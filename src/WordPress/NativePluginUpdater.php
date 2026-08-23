<?php

declare(strict_types=1);

namespace RAN\WPReleaseUpdater\V1\WordPress;

use InvalidArgumentException;
use RAN\WPReleaseUpdater\V1\Archive\PackageIdentityValidator;
use RAN\WPReleaseUpdater\V1\Archive\TemporaryArtifact;
use RAN\WPReleaseUpdater\V1\Contract\AcquisitionReceipt;
use RAN\WPReleaseUpdater\V1\Contract\BindingRecord;
use RAN\WPReleaseUpdater\V1\Contract\CanonicalUpdateUri;
use RAN\WPReleaseUpdater\V1\Contract\IdentityDescriptor;
use RAN\WPReleaseUpdater\V1\Contract\ReleaseAdapter;
use RAN\WPReleaseUpdater\V1\Contract\ReleaseVersion;

/** The single native WordPress lifecycle owner for a sealed neutral release. */
final class NativePluginUpdater {
	private const MAX_DIAGNOSTICS = 16;
	private const MAX_MANIFEST_ENTRIES = 10000;
	private const CORE_ARTIFACT_HANDOFF_FILTER = 'ran_wp_release_updater_v1_core_artifact_handoff';
	private const CONFIGURATION_KEYS = array( 'headers', 'installed_package_identity', 'policy', 'target_type', 'update_uri' );
	private const HEADER_KEYS = array( 'Author', 'Description', 'Name', 'PluginURI', 'RequiresPHP', 'RequiresWP', 'UpdateURI', 'Version' );
	private bool $registered = false;
	private ?IdentityDescriptor $descriptor = null;
	private ?BindingState $state = null;
	private mixed $claim = null;
	private bool $leaseHeld = false;
	private bool $pending = false;
	private bool $extractionAdmitted = false;
	private ?string $stagedSource = null;
	/** @var array<string,array{sha256:string,size:int}>|null */ private ?array $stagedManifest = null;
	private ?string $pendingArchive = null;
	private ?string $ownedArchiveDirectory = null;
	/** @var array<string,int>|null */ private ?array $pendingArchiveIdentity = null;
	private bool $installResultCaptured = false;
	private mixed $installResult = null;
	private bool $completionObserved = false;
	private bool $multiRun = false;
	private bool $queuedMultiRun = false;
	private bool $shutdownScheduled = false;
	/** @var list<string> */ private array $diagnostics = array();
	/** @var array{candidate_header_version:string|null,candidate_tag:string|null,candidate_validation_code:string|null,candidate_version:string|null,failure_code:string|null,installed_version:string|null,last_check:int|null,offered_version:string|null,relationship:string|null} */
	private array $status = array( 'candidate_tag' => null, 'candidate_validation_code' => null, 'candidate_version' => null, 'candidate_header_version' => null, 'failure_code' => null, 'installed_version' => null, 'last_check' => null, 'offered_version' => null, 'relationship' => null );
	private ?AcquisitionReceipt $pendingReceipt = null;
	private ?TemporaryArtifact $coreArtifact = null;
	private ?string $coreExpectedVersion = null;

	/** @param array<string,string> $headers @param array<string,mixed> $archivePolicy */
	private function __construct(
		private string $targetType, private string $installedIdentity, private string $updateUri, private string $policy,
		private array $headers, private BindingRecord $binding, private ReleaseAdapter $adapter, private object $wpdb,
		private array $archivePolicy, private PackageIdentityValidator $validator
	) {}

	/** @param array<string,mixed> $configuration @param array<string,mixed> $archivePolicy */
	public static function fromConfiguration(
		array $configuration, BindingRecord $binding, ReleaseAdapter $adapter, object $wpdb, array $archivePolicy,
		?PackageIdentityValidator $validator = null
	): ?self {
		if ( ! self::validConfiguration( $configuration, $binding ) || $configuration['policy'] !== $binding->toArray()['update_policy'] ) return null;
		$uri = CanonicalUpdateUri::canonicalize( $configuration['update_uri'] );
		if ( ! is_string( $uri ) ) return null;
		return new self(
			$configuration['target_type'], $configuration['installed_package_identity'], $uri, $configuration['policy'],
			$configuration['headers'], $binding, $adapter, $wpdb, $archivePolicy, $validator ?? new PackageIdentityValidator()
		);
	}

	public function register(): void {
		if ( $this->registered ) return;
		$host = parse_url( $this->updateUri, PHP_URL_HOST ); if ( ! is_string( $host ) || '' === $host ) return;
		add_filter( ( 'plugin' === $this->targetType ? 'update_plugins_' : 'update_themes_' ) . $host, array( $this, 'filterUpdate' ), 10, 4 );
		if ( 'plugin' === $this->targetType ) add_filter( 'plugins_api', array( $this, 'filterPluginInformation' ), 10, 3 );
		add_filter( 'plugin' === $this->targetType ? 'auto_update_plugin' : 'auto_update_theme', array( $this, 'filterAutoUpdate' ), 10, 2 );
		add_filter( 'upgrader_package_options', array( $this, 'capturePackageOptions' ), PHP_INT_MAX, 1 ); add_filter( 'upgrader_pre_download', array( $this, 'filterPreDownload' ), PHP_INT_MAX, 4 ); add_filter( 'upgrader_pre_install', array( $this, 'filterPreInstall' ), 1, 2 ); add_filter( 'pre_unzip_file', array( $this, 'filterPreUnzipFile' ), PHP_INT_MAX, 5 ); add_filter( 'upgrader_source_selection', array( $this, 'filterSourceSelection' ), PHP_INT_MAX, 4 ); add_filter( 'upgrader_install_package_result', array( $this, 'captureInstallPackageResult' ), PHP_INT_MAX, 2 ); add_action( 'upgrader_process_complete', array( $this, 'observeCompletion' ), 10, 2 ); $this->registered = true;
	}

	/** @param array<string,mixed>|false $update @param array<string,mixed> $packageData @param list<string> $locales */
	public function filterUpdate( mixed $update, array $packageData, string $packageIdentity, array $locales ): mixed {
		unset( $locales ); if ( ! hash_equals( $this->installedIdentity, $packageIdentity ) ) return $update;
		$installed = is_string( $packageData['Version'] ?? null ) ? $packageData['Version'] : '';
		$this->status = self::emptyStatus(); $this->status['installed_version'] = $installed; $this->status['last_check'] = time();
		$runtimeUri = is_string( $packageData['UpdateURI'] ?? null ) ? $packageData['UpdateURI'] : '';
		if ( null === ReleaseVersion::normalizeHeader( $installed ) || ! $this->matchesRuntimeUri( $runtimeUri ) ) {
			return $this->diagnose( 'runtime_package_identity_invalid', $update );
		}
		if ( in_array( $this->policy, array( 'disabled', 'forced-off' ), true ) ) return false;
		if ( ! $this->directFilesystem() || ! $this->claimDiscovery() ) return $update;
		$descriptor = $this->discover( $installed );
		if ( ! $descriptor instanceof IdentityDescriptor || ! $this->manualEligible( $descriptor ) ) return $update;
		$token = $this->token( $descriptor ); if ( null === $token ) return $update;
		$facts = $descriptor->toArray();
		$this->status['offered_version'] = $facts['version'];
		$offer = array(
			'id' => $this->updateUri, 'slug' => $this->informationSlug(), 'url' => $this->updateUri, 'package' => $token,
			'requires' => $this->headers['RequiresWP'], 'requires_php' => $this->headers['RequiresPHP'],
			'version' => $facts['version'], 'autoupdate' => $this->automaticEligible( $descriptor ),
			'ran_wp_release_updater_binding_hash' => $this->binding->bindingHash(),
		);
		if ( 'plugin' === $this->targetType ) $offer['plugin'] = $this->installedIdentity; else $offer['theme'] = $this->installedIdentity;
		return $offer;
	}

	public function filterPluginInformation( mixed $result, string $action, mixed $arguments ): mixed {
		if ( 'plugin_information' !== $action || ! is_object( $arguments ) || $this->informationSlug() !== ( $arguments->slug ?? null ) ) return $result;
		if ( ! $this->directFilesystem() || ! $this->claimDiscovery() ) return $result;
		$descriptor = $this->discover( $this->headers['Version'] );
		if ( ! $descriptor instanceof IdentityDescriptor || ! $this->manualEligible( $descriptor ) ) return $result;
		$facts = $descriptor->toArray(); $info = new \stdClass(); $info->name = $this->headers['Name'];
		$info->slug = $this->informationSlug(); $info->version = $facts['version']; $info->author = $this->headers['Author'];
		$info->homepage = $this->headers['PluginURI']; $info->requires = $this->headers['RequiresWP'];
		$info->requires_php = $this->headers['RequiresPHP']; $info->download_link = $this->token( $descriptor ) ?? '';
		$info->external = true; $info->sections = array( 'description' => $this->headers['Description'], 'changelog' => '' );
		return $info;
	}

	public function filterAutoUpdate( ?bool $update, mixed $item ): ?bool {
		if ( ! is_object( $item ) || ! $this->matchesItemIdentity( $item ) ) return $update;
		$descriptor = $this->parseToken( is_string( $item->package ?? null ) ? $item->package : '' );
		return $this->directFilesystem() && $descriptor instanceof IdentityDescriptor && $this->newerThanInstalled( $descriptor ) && $this->automaticEligible( $descriptor );
	}

	/** @param array<string,mixed> $hookExtra */
	public function capturePackageOptions( mixed $options ): mixed { $extra = is_array( $options ) && is_array( $options['hook_extra'] ?? null ) ? $options['hook_extra'] : null; if ( is_array( $extra ) && $this->matchesCompletion( $extra ) ) $this->queuedMultiRun = true === ( $options['is_multi'] ?? false ); return $options; }
	/** @param array<string,mixed> $hookExtra */
	public function filterPreDownload( mixed $reply, string $package, mixed $upgrader, array $hookExtra ): mixed {
		unset( $upgrader ); if ( ! $this->matchesTargetIdentity( $hookExtra ) ) return $reply;
		if ( ! $this->matchesOperation( $hookExtra ) ) return false !== $reply && ! $reply instanceof \WP_Error ? $this->failure( 'unverified_pre_download_result' ) : $reply;
		$queuedMultiRun = $this->queuedMultiRun; $this->queuedMultiRun = false;
		if ( $reply instanceof \WP_Error ) return $reply;
		if ( false !== $reply ) {
			if ( $this->pending && ! ( $this->coreArtifact instanceof TemporaryArtifact ) ) {
				return $this->failure( 'unverified_pre_download_result' );
			}
			$handoff = $this->acceptCoreArtifactHandoff( $reply, $package, $hookExtra );
			if ( ! is_array( $handoff ) ) return $this->failure( 'unverified_pre_download_result' );
			if ( ! $this->releaseDiscoveryLeaseForCoreHandoff() ) {
				$handoff['artifact']->discard();
				return $this->failure( 'binding_fence_lost' );
			}
			$this->clearPending( false );
			$this->pending = true; $this->multiRun = $queuedMultiRun; $this->pendingArchive = $reply;
			$this->coreArtifact = $handoff['artifact']; $this->coreExpectedVersion = $handoff['expected_version'];
			$this->scheduleFinalization(); return $reply;
		}
		$this->clearPending( false );
		$token = $this->parseToken( $package );
		if ( ! $this->directFilesystem() || ! $token instanceof IdentityDescriptor || ! $this->newerThanInstalled( $token ) || ! $this->manualEligible( $token ) ) {
			return $this->failure( 'unverified_pre_download' );
		}
		if ( ! $this->claimDiscovery() ) return $this->failure( 'binding_fence_lost' );
		try {
			$fresh = $this->adapter->inspect( $token->releaseIdentity(), $token->toArray()['tag'] );
			BindingRecord::assertDescriptorBinding( $fresh, $this->binding );
		} catch ( \Throwable ) { return $this->failure( 'acquisition_failed' ); }
		if ( ! $this->newerThanInstalled( $fresh ) ) return $this->failure( 'unverified_pre_download' );
		if ( ! hash_equals( $token->fingerprintValue(), $fresh->fingerprintValue() ) ) {
			return $this->failure( 'remote_release_changed' );
		}
		$this->descriptor = $fresh;
		$renewed = ReleaseOperationCoordinator::renewPersistentBindingState( $this->wpdb, $this->state, $this->claim, 3600 );
		if ( 'renewed' !== $renewed['result'] || ! $renewed['current'] instanceof BindingState ) {
			return $this->failure( 'binding_fence_lost' );
		}
		$this->state = $renewed['current']; $this->claim = $this->claim( $this->state );
		try {
			$artifact = $this->adapter->acquire( $fresh );
			$owned = $artifact->inspect( fn( string $path ): ?array => $this->validatedCopy( $path, $fresh ) );
		} catch ( \Throwable ) { return $this->failure( 'acquisition_failed' ); }
		if ( ! is_array( $owned ) ) return $this->failure( 'acquisition_identity_invalid' );
		$identity = self::archiveIdentity( $owned['path'], $fresh );
		if ( null === $identity ) {
			$this->removeOwnedArchive( $owned['path'], $owned['directory'] );
			return $this->failure( 'acquisition_identity_invalid' );
		}
		$verified = $this->verifyCurrent();
		if ( null === $verified ) {
			$this->removeOwnedArchive( $owned['path'], $owned['directory'] );
			return $this->failure( 'binding_fence_lost' );
		}
		try {
			$proof = $this->validator->validate( $fresh, $this->archivePolicy, $owned['path'] );
			$receipt = AcquisitionReceipt::issue( $verified['current'], $fresh, $this->validator, $proof, $verified['now'] );
		} catch ( \Throwable ) {
			$this->removeOwnedArchive( $owned['path'], $owned['directory'] );
			return $this->failure( 'acquisition_identity_invalid' );
		}
		$this->state = $verified['current']; $this->pendingReceipt = $receipt; $this->pending = true;
		$this->multiRun = $queuedMultiRun; $this->pendingArchive = $owned['path']; $this->ownedArchiveDirectory = $owned['directory'];
		$this->pendingArchiveIdentity = $identity; $this->scheduleFinalization(); return $owned['path'];
	}

	/** @param list<string> $neededDirs */
	public function filterPreUnzipFile( mixed $pre, string $file, string $destination, array $neededDirs, float $requiredSpace ): mixed { unset( $destination, $neededDirs, $requiredSpace ); if ( ! $this->pending || ! is_string( $this->pendingArchive ) || ! hash_equals( $this->pendingArchive, $file ) ) return $pre; if ( $this->coreArtifact instanceof TemporaryArtifact ) { try { $this->coreArtifact->inspect( static fn( string $path ): string => $path ); } catch ( \Throwable ) { $this->clearPending(); return $this->failure( 'archive_changed_before_extraction' ); } $this->extractionAdmitted = true; return $pre; } $verified = $this->verifyCurrent(); if ( ! $this->directFilesystem() || null === $verified || ! is_array( $this->pendingArchiveIdentity ) || ! self::sameArchiveIdentity( $file, $this->descriptor, $this->pendingArchiveIdentity ) || ! $this->pendingReceipt instanceof AcquisitionReceipt ) { $this->clearPending(); return $this->failure( 'archive_changed_before_extraction' ); } try { AcquisitionReceipt::assertFresh( $this->pendingReceipt, $verified['current'], $this->descriptor, $verified['now'] ); } catch ( InvalidArgumentException ) { $this->clearPending(); return $this->failure( 'archive_changed_before_extraction' ); } $this->state = $verified['current']; $this->extractionAdmitted = true; return $pre; }

	/** @param array<string,mixed> $hookExtra */
	public function filterSourceSelection( mixed $source, string $remoteSource, mixed $upgrader, array $hookExtra ): mixed { unset( $remoteSource, $upgrader ); if ( ! $this->matchesOperation( $hookExtra ) ) return $source; if ( $this->coreArtifact instanceof TemporaryArtifact ) { $manifest = is_string( $source ) && is_string( $this->coreExpectedVersion ) && $this->matchesStagedMetadata( $source, $this->coreExpectedVersion ) ? self::regularFileManifest( $source ) : null; if ( ! $this->directFilesystem() || ! $this->pending || ! $this->extractionAdmitted || ! is_string( $source ) || ! is_array( $manifest ) ) { $this->clearPending(); return $this->failure( 'staged_package_identity_invalid' ); } $this->stagedSource = $source; $this->stagedManifest = $manifest; return $source; } $manifest = is_string( $source ) && $this->matchesStagedMetadata( $source ) ? self::regularFileManifest( $source ) : null; $verified = $this->verifyCurrent(); if ( ! $this->directFilesystem() || ! $this->pending || ! $this->extractionAdmitted || null === $verified || ! $this->pendingReceipt instanceof AcquisitionReceipt || ! is_string( $source ) || ! is_array( $manifest ) ) { $this->clearPending(); return $this->failure( 'staged_package_identity_invalid' ); } try { AcquisitionReceipt::assertArchiveManifest( $this->pendingReceipt, $verified['current'], $this->descriptor, $verified['now'], self::manifestHash( $manifest ), count( $manifest ), self::manifestExpandedBytes( $manifest ) ); } catch ( InvalidArgumentException ) { $this->clearPending(); return $this->failure( 'staged_package_identity_invalid' ); } $this->state = $verified['current']; $this->stagedSource = $source; $this->stagedManifest = $manifest; return $source; }
	/** @param array<string,mixed> $hookExtra */
	public function filterPreInstall( mixed $response, array $hookExtra ): mixed { if ( ! $this->matchesOperation( $hookExtra ) ) return $response; if ( $this->coreArtifact instanceof TemporaryArtifact ) { if ( ! $this->directFilesystem() || ! $this->pending || ! $this->extractionAdmitted || $response instanceof \WP_Error ) { $this->clearPending(); return $this->failure( 'unverified_pre_install' ); } return $response; } $verified = $this->verifyCurrent(); if ( ! $this->directFilesystem() || ! $this->pending || ! $this->extractionAdmitted || null === $verified || ! $this->pendingReceipt instanceof AcquisitionReceipt || $response instanceof \WP_Error ) { $this->clearPending(); return $this->failure( 'unverified_pre_install' ); } try { AcquisitionReceipt::assertFresh( $this->pendingReceipt, $verified['current'], $this->descriptor, $verified['now'] ); } catch ( InvalidArgumentException ) { $this->clearPending(); return $this->failure( 'unverified_pre_install' ); } $this->state = $verified['current']; return $response; }
	/** @param array<string,mixed> $hookExtra */
	public function captureInstallPackageResult( mixed $result, array $hookExtra ): mixed { if ( ! $this->matchesOperation( $hookExtra ) ) return $result; if ( ! $this->pending || $result instanceof \WP_Error || false === $result ) { $this->clearPending(); return $this->failure( 'unverified_install_result' ); } $this->installResultCaptured = true; $this->installResult = $result; return $result; }
	/** @param array<string,mixed> $hookExtra */
	public function observeCompletion( mixed $upgrader, array $hookExtra ): void { unset( $upgrader ); if ( $this->matchesCompletion( $hookExtra ) ) $this->completionObserved = true; }
	/** Finalization is deliberately after Core rollback and backup cleanup. */
	public function finalizePendingInstall(): void { if ( ! $this->pending ) { $this->clearPending(); return; } try { $destination = is_array( $this->installResult ) && is_string( $this->installResult['destination'] ?? null ) ? $this->installResult['destination'] : null; $manifest = is_string( $destination ) ? self::regularFileManifest( $destination ) : null; if ( $this->coreArtifact instanceof TemporaryArtifact ) { if ( ! $this->installResultCaptured || ( ! $this->completionObserved && ! $this->multiRun ) || ! is_string( $destination ) || ! is_array( $this->stagedManifest ) || ! is_array( $manifest ) || ! hash_equals( self::manifestHash( $this->stagedManifest ), self::manifestHash( $manifest ) ) || ! is_string( $this->coreExpectedVersion ) || ! $this->matchesStagedMetadata( $destination, $this->coreExpectedVersion ) ) $this->diagnose( 'outcome_uncertain', null ); return; } $verified = $this->verifyCurrent(); $archiveManifestVerified = false; if ( null !== $verified && $this->pendingReceipt instanceof AcquisitionReceipt && is_array( $manifest ) ) { try { AcquisitionReceipt::assertArchiveManifest( $this->pendingReceipt, $verified['current'], $this->descriptor, $verified['now'], self::manifestHash( $manifest ), count( $manifest ), self::manifestExpandedBytes( $manifest ) ); $archiveManifestVerified = true; $this->state = $verified['current']; } catch ( InvalidArgumentException ) { $archiveManifestVerified = false; } } if ( ! $this->installResultCaptured || ( ! $this->completionObserved && ! $this->multiRun ) || ! $archiveManifestVerified || ! is_string( $destination ) || ! is_array( $this->stagedManifest ) || ! is_array( $manifest ) || ! hash_equals( self::manifestHash( $this->stagedManifest ), self::manifestHash( $manifest ) ) || ! $this->matchesStagedMetadata( $destination ) ) { $this->diagnose( 'outcome_uncertain', null ); return; } $completed = ReleaseOperationCoordinator::completePersistentInstall( $this->wpdb, $this->state, $this->claim, $this->pendingReceipt, $this->descriptor ); $this->diagnose( 'completed' === $completed['result'] ? 'update_completed' : 'outcome_uncertain', null ); } finally { $this->clearPending(); } }
	/** @return list<string> */ public function diagnostics(): array { return $this->diagnostics; }
	/** @return array{candidate_header_version:string|null,candidate_tag:string|null,candidate_validation_code:string|null,candidate_version:string|null,failure_code:string|null,installed_version:string|null,last_check:int|null,offered_version:string|null,relationship:string|null} */ public function status(): array { return $this->status; }
	public function refresh(): void { $this->diagnostics = array(); $this->status = self::emptyStatus(); $this->queuedMultiRun = false; $this->clearPending(); }

	/** @param array<string,mixed> $configuration */
	private static function validConfiguration( array $configuration, BindingRecord $binding ): bool {
		if ( ! self::exactKeys( $configuration, self::CONFIGURATION_KEYS ) || ! is_array( $configuration['headers'] ) || ! self::exactKeys( $configuration['headers'], self::HEADER_KEYS ) || ! is_string( $configuration['update_uri'] ) || ! in_array( $configuration['policy'], array( 'disabled', 'forced-off', 'manual', 'automatic' ), true ) || ! self::validNativeIdentity( $configuration['target_type'], $configuration['installed_package_identity'] ) ) return false;
		foreach ( $configuration['headers'] as $header ) if ( ! is_string( $header ) || strlen( $header ) > 8192 ) return false;
		$facts = $binding->toArray(); if ( $configuration['target_type'] !== $facts['target_type'] || ! hash_equals( $configuration['installed_package_identity'], $facts['installed_package_identity'] ) || null === ReleaseVersion::normalizeHeader( $configuration['headers']['Version'] ) ) return false;
		return $facts['canonical_update_uri'] === CanonicalUpdateUri::canonicalizeBoundaries( array( 'archive_preflight' => $facts['canonical_update_uri'], 'configuration' => $configuration['update_uri'], 'offer' => $configuration['headers']['UpdateURI'], 'staged_package' => $facts['canonical_update_uri'] ) );
	}

	/** @return array{artifact:TemporaryArtifact,expected_version:string}|null */ private function acceptCoreArtifactHandoff( mixed $reply, string $package, array $hookExtra ): ?array { if ( ! is_string( $reply ) || '' === $reply || ! hash_equals( $package, $reply ) || 'update' !== ( $hookExtra['action'] ?? null ) ) return null; $artifact = null; try { $artifact = apply_filters( self::CORE_ARTIFACT_HANDOFF_FILTER, null, $reply, $package, $hookExtra, $this->targetType, $this->installedIdentity ); if ( ! $artifact instanceof TemporaryArtifact ) return null; return array( 'artifact' => $artifact, 'expected_version' => $artifact->acceptCoreUpdate( $this->targetType, $this->installedIdentity, 'update', $reply ) ); } catch ( \Throwable ) { if ( $artifact instanceof TemporaryArtifact ) $artifact->discard(); return null; } }
	private function releaseDiscoveryLeaseForCoreHandoff(): bool {
		if ( ! $this->leaseHeld ) {
			$this->state = null;
			$this->claim = null;
			return true;
		}
		if ( ! $this->state instanceof BindingState ) return false;
		$released = ReleaseOperationCoordinator::releasePersistentBindingState(
			$this->wpdb,
			$this->state,
			$this->claim
		);
		if ( 'released' !== $released['result'] ) return false;
		$this->state = null;
		$this->claim = null;
		$this->leaseHeld = false;
		return true;
	}
	private function matchesRuntimeUri( string $runtimeUri ): bool { return $this->updateUri === CanonicalUpdateUri::canonicalizeBoundaries( array( 'archive_preflight' => $this->binding->toArray()['canonical_update_uri'], 'configuration' => $this->updateUri, 'offer' => $runtimeUri, 'staged_package' => $this->headers['UpdateURI'] ) ); }
	private function manualEligible( IdentityDescriptor $descriptor ): bool { $facts = $descriptor->toArray()['assurance_facts']; foreach ( array( 'exact_artifact_identity', 'exact_commit_identity', 'exact_reacquisition_supported', 'exact_release_identity', 'repository_identity_stable', 'trusted_digest_source' ) as $fact ) if ( true !== $facts[ $fact ] ) return false; return true; }
	private function automaticEligible( IdentityDescriptor $descriptor ): bool { $facts = $descriptor->toArray()['assurance_facts']; return 'automatic' === $this->policy && $this->manualEligible( $descriptor ) && true === $facts['publication_immutable'] && true === $facts['provenance_verified']; }
	private function newerThanInstalled( IdentityDescriptor $descriptor ): bool { return ReleaseVersion::RELATIONSHIP_NEWER === ReleaseVersion::relationship( $descriptor->toArray()['version'], $this->headers['Version'] ); }
	private function informationSlug(): string { return 'ran-wp-release-updater-' . substr( hash( 'sha256', $this->targetType . "\0" . $this->installedIdentity ), 0, 24 ); }
	private function matchesItemIdentity( object $item ): bool { $identity = 'plugin' === $this->targetType ? ( $item->plugin ?? null ) : ( $item->theme ?? null ); return is_string( $identity ) && hash_equals( $this->installedIdentity, $identity ); }
	/** @param array<string,mixed> $extra */ private function matchesOperation( array $extra ): bool { $identity = 'plugin' === $this->targetType ? ( $extra['plugin'] ?? null ) : ( $extra['theme'] ?? null ); if ( ! is_string( $identity ) || ! hash_equals( $this->installedIdentity, $identity ) ) return false; return ( ! array_key_exists( 'action', $extra ) || 'update' === $extra['action'] ) && ( ! array_key_exists( 'type', $extra ) || $this->targetType === $extra['type'] ); }
	/** @param array<string,mixed> $extra */ private function matchesTargetIdentity( array $extra ): bool { $identity = 'plugin' === $this->targetType ? ( $extra['plugin'] ?? null ) : ( $extra['theme'] ?? null ); return is_string( $identity ) && hash_equals( $this->installedIdentity, $identity ); }
	/** @param array<string,mixed> $extra */ private function matchesCompletion( array $extra ): bool { if ( 'update' !== ( $extra['action'] ?? null ) || $this->targetType !== ( $extra['type'] ?? null ) ) return false; $key = 'plugin' === $this->targetType ? 'plugins' : 'themes'; $single = 'plugin' === $this->targetType ? 'plugin' : 'theme'; $targets = $extra[ $key ] ?? array( $extra[ $single ] ?? null ); return is_array( $targets ) && in_array( $this->installedIdentity, $targets, true ); }
	private function matchesStagedMetadata( string $source, ?string $expectedVersion = null ): bool { $source = rtrim( $source, '/\\' ); $directory = @lstat( $source ); if ( ! is_array( $directory ) || 0040000 !== ( $directory['mode'] & 0170000 ) || ! hash_equals( $this->packageRoot(), basename( $source ) ) ) return false; $header = 'plugin' === $this->targetType ? basename( $this->installedIdentity ) : 'style.css'; $path = $source . DIRECTORY_SEPARATOR . $header; $file = @lstat( $path ); if ( ! is_array( $file ) || 0100000 !== ( $file['mode'] & 0170000 ) ) return false; $contents = @file_get_contents( $path, false, null, 0, 8192 ); if ( ! is_string( $contents ) ) return false; $name = self::headerValue( $contents, 'theme' === $this->targetType ? 'Theme Name' : 'Plugin Name' ); $version = self::headerValue( $contents, 'Version' ); $uri = self::headerValue( $contents, 'Update URI' ); $expectedVersion ??= $this->descriptor instanceof IdentityDescriptor ? $this->descriptor->toArray()['version'] : null; return is_string( $name ) && hash_equals( $this->headers['Name'], $name ) && is_string( $version ) && is_string( $expectedVersion ) && is_string( $uri ) && 0 === ReleaseVersion::compare( $version, $expectedVersion ) && $this->matchesRuntimeUri( $uri ); }
	private function packageRoot(): string { return 'plugin' === $this->targetType ? dirname( $this->installedIdentity ) : $this->installedIdentity; }
	private function scheduleFinalization(): void { if ( $this->shutdownScheduled ) return; add_action( 'shutdown', array( $this, 'finalizePendingInstall' ), PHP_INT_MAX, 0 ); $this->shutdownScheduled = true; }
	private function claimDiscovery(): bool {
		if ( $this->leaseHeld && null !== $this->verifyCurrent() ) return true;
		$this->leaseHeld = false; $this->state = null; $this->claim = null;
		try { $owner = bin2hex( random_bytes( 32 ) ); } catch ( \Throwable ) { return false; }
		$claimed = ReleaseOperationCoordinator::claimPersistentBindingState( $this->wpdb, $this->binding, $owner, 600 );
		if ( 'claimed' !== $claimed['result'] || ! $claimed['current'] instanceof BindingState ) return false;
		$this->state = $claimed['current']; $this->claim = $this->claim( $this->state ); $this->leaseHeld = true;
		$this->scheduleFinalization(); return true;
	}
	private function discover( string $installed ): ?IdentityDescriptor {
		try { $listed = $this->adapter->listReleases(); $candidates = $listed['candidates'] ?? null; } catch ( \Throwable ) { $this->status['candidate_validation_code'] = 'release_list_failed'; return null; }
		if ( ! is_array( $candidates ) || count( $candidates ) > 8 ) { $this->status['candidate_validation_code'] = 'candidate_list_invalid'; return null; }
		foreach ( $candidates as $candidate ) {
			if ( ! is_array( $candidate ) || ! is_string( $candidate['release_identity'] ?? null ) || ! is_string( $candidate['tag'] ?? null ) || ! is_string( $candidate['version'] ?? null ) ) { $this->status['candidate_validation_code'] = 'candidate_invalid'; continue; }
			$this->status['candidate_tag'] = $candidate['tag']; $this->status['candidate_version'] = $candidate['version']; $this->status['relationship'] = ReleaseVersion::relationship( $candidate['version'], $installed );
			if ( ReleaseVersion::RELATIONSHIP_NEWER !== $this->status['relationship'] ) { $this->status['candidate_validation_code'] = 'candidate_not_newer'; continue; }
			try { $descriptor = $this->adapter->inspect( $candidate['release_identity'], $candidate['tag'] ); BindingRecord::assertDescriptorBinding( $descriptor, $this->binding ); } catch ( \Throwable ) { $this->status['candidate_validation_code'] = 'candidate_inspection_failed'; continue; }
			$facts = $descriptor->toArray();
			if ( ! hash_equals( $candidate['release_identity'], $facts['release_identity'] ) || ! hash_equals( $candidate['tag'], $facts['tag'] ) || 0 !== ReleaseVersion::compare( $candidate['version'], $facts['version'] ) ) { $this->status['candidate_validation_code'] = 'candidate_descriptor_mismatch'; continue; }
			try { $artifact = $this->adapter->acquire( $descriptor ); $valid = $artifact->inspect( fn( string $path ) => $this->validator->validate( $descriptor, $this->archivePolicy, $path ) ); } catch ( \Throwable ) { $this->status['candidate_validation_code'] = 'candidate_validation_failed'; continue; }
			$this->status['candidate_validation_code'] = $valid->code();
			if ( ! $valid->isValid() ) continue;
			$this->status['candidate_header_version'] = $facts['version'];
			$this->descriptor = $descriptor; return $descriptor;
		}
		return null;
	}
	private function token( IdentityDescriptor $descriptor ): ?string {
		$value = array( 'binding_hash' => $this->binding->bindingHash(), 'descriptor' => $descriptor->toArray(), 'schema' => 1 );
		try { $json = json_encode( $value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES ); } catch ( \JsonException ) { return null; }
		$token = 'ran-wp-release-updater:v1:' . rtrim( strtr( base64_encode( $json ), '+/', '-_' ), '=' );
		return strlen( $token ) <= 8192 ? $token : null;
	}
	private function parseToken( string $token ): ?IdentityDescriptor {
		$prefix = 'ran-wp-release-updater:v1:';
		if ( ! str_starts_with( $token, $prefix ) || strlen( $token ) > 8192 ) return null;
		$encoded = substr( $token, strlen( $prefix ) ); if ( '' === $encoded || 1 === preg_match( '/[^A-Za-z0-9_-]/', $encoded ) ) return null;
		$raw = base64_decode( strtr( $encoded, '-_', '+/' ) . str_repeat( '=', ( 4 - strlen( $encoded ) % 4 ) % 4 ), true );
		if ( ! is_string( $raw ) || strlen( $raw ) > 6144 || ! hash_equals( $encoded, rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' ) ) ) return null;
		try {
			$value = json_decode( $raw, true, 32, JSON_THROW_ON_ERROR );
			$canonical = json_encode( $value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES );
			if ( ! is_string( $canonical ) || ! hash_equals( $raw, $canonical ) ) return null;
			if ( ! self::exactKeys( $value, array( 'binding_hash', 'descriptor', 'schema' ) ) || 1 !== $value['schema'] ) return null;
			if ( ! is_string( $value['binding_hash'] ) || ! hash_equals( $this->binding->bindingHash(), $value['binding_hash'] ) ) return null;
			$descriptor = IdentityDescriptor::rehydrate( $value['descriptor'] ); BindingRecord::assertDescriptorBinding( $descriptor, $this->binding ); return $descriptor;
		} catch ( \Throwable ) { return null; }
	}
	/** @return array{directory:string,path:string}|null */ private function validatedCopy( string $path, IdentityDescriptor $descriptor ): ?array { try { $proof = $this->validator->validate( $descriptor, $this->archivePolicy, $path ); } catch ( \Throwable ) { return null; } return $proof->isValid() ? $this->copyOwnedArchive( $path ) : null; }
	/** @return array<string,mixed> */ private function claim( BindingState $state ): array { return array( 'binding_generation' => $state->bindingGeneration(), 'binding_hash' => $state->binding()->bindingHash(), 'lease_deadline' => $state->leaseDeadline(), 'owner_token' => $state->ownerToken() ); }
	/** @return array{current:BindingState,now:int}|null */ private function verifyCurrent(): ?array { if ( ! $this->state instanceof BindingState ) return null; $verified = ReleaseOperationCoordinator::verifyPersistentBindingState( $this->wpdb, $this->state, $this->claim ); return 'verified' === $verified['result'] && $verified['current'] instanceof BindingState && is_int( $verified['now'] ?? null ) ? array( 'current' => $verified['current'], 'now' => $verified['now'] ) : null; }
	private function clearPending( bool $release = true ): void { $coreArtifact = $this->coreArtifact; if ( ! ( $coreArtifact instanceof TemporaryArtifact ) ) $this->removeOwnedArchive( $this->pendingArchive, $this->ownedArchiveDirectory ); $this->pending = false; $this->extractionAdmitted = false; $this->stagedSource = null; $this->stagedManifest = null; $this->pendingArchive = null; $this->ownedArchiveDirectory = null; $this->pendingArchiveIdentity = null; $this->pendingReceipt = null; $this->coreArtifact = null; $this->coreExpectedVersion = null; $this->installResultCaptured = false; $this->installResult = null; $this->completionObserved = false; $this->multiRun = false; if ( $coreArtifact instanceof TemporaryArtifact ) $coreArtifact->discard(); if ( $release && $this->leaseHeld && $this->state instanceof BindingState ) ReleaseOperationCoordinator::releasePersistentBindingState( $this->wpdb, $this->state, $this->claim ); if ( $release ) { $this->state = null; $this->claim = null; $this->leaseHeld = false; } }
	private function diagnose( string $code, mixed $return ): mixed { if ( count( $this->diagnostics ) === self::MAX_DIAGNOSTICS ) array_shift( $this->diagnostics ); $this->diagnostics[] = $code; $this->status['failure_code'] = 'update_completed' === $code ? null : $code; return $return; }
	/** @return array{candidate_header_version:null,candidate_tag:null,candidate_validation_code:null,candidate_version:null,failure_code:null,installed_version:null,last_check:null,offered_version:null,relationship:null} */ private static function emptyStatus(): array { return array( 'candidate_tag' => null, 'candidate_validation_code' => null, 'candidate_version' => null, 'candidate_header_version' => null, 'failure_code' => null, 'installed_version' => null, 'last_check' => null, 'offered_version' => null, 'relationship' => null ); }
	private function failure( string $code ): mixed { $this->diagnose( $code, null ); return class_exists( '\\WP_Error' ) ? new \WP_Error( 'ran_wp_release_updater_' . $code, 'The update operation was not admitted.' ) : false; }
	private function directFilesystem(): bool { return function_exists( 'get_filesystem_method' ) && 'direct' === get_filesystem_method(); }
	/** @return array{directory:string,path:string}|null */ private function copyOwnedArchive( string $source ): ?array {
		$facts = $this->descriptor->toArray(); $before = self::archiveIdentity( $source, $this->descriptor ); if ( null === $before ) return null;
		try { $suffix = bin2hex( random_bytes( 16 ) ); } catch ( \Throwable ) { return null; } $directory = rtrim( sys_get_temp_dir(), '/\\' ) . DIRECTORY_SEPARATOR . 'ran-wp-release-updater-' . $suffix; if ( ! @mkdir( $directory, 0700 ) || ! @chmod( $directory, 0700 ) ) return null;
		$path = $directory . DIRECTORY_SEPARATOR . 'package.zip'; $input = @fopen( $source, 'rb' ); $output = @fopen( $path, 'x+b' ); if ( ! is_resource( $input ) || ! is_resource( $output ) || ! @chmod( $path, 0600 ) ) { if ( is_resource( $input ) ) fclose( $input ); if ( is_resource( $output ) ) fclose( $output ); $this->removeOwnedArchive( $path, $directory ); return null; }
		$context = hash_init( 'sha256' ); $size = 0; $ok = true; while ( ! feof( $input ) ) { $chunk = fread( $input, 65536 ); if ( ! is_string( $chunk ) || ( '' === $chunk && ! feof( $input ) ) || strlen( $chunk ) > $facts['artifact_size'] - $size ) { $ok = false; break; } for ( $written = 0, $length = strlen( $chunk ); $written < $length; ) { $result = fwrite( $output, substr( $chunk, $written ) ); if ( ! is_int( $result ) || 0 === $result ) { $ok = false; break 2; } $written += $result; } $size += $length; hash_update( $context, $chunk ); } fflush( $output ); fclose( $input ); fclose( $output ); clearstatcache( true, $source ); $after = self::archiveIdentity( $source, $this->descriptor ); if ( ! @chmod( $path, 0400 ) ) $ok = false; $copy = self::archiveIdentity( $path, $this->descriptor ); if ( ! $ok || $size !== $facts['artifact_size'] || ! hash_equals( $facts['artifact_sha256'], hash_final( $context ) ) || ! is_array( $after ) || $after !== $before || null === $copy ) { $this->removeOwnedArchive( $path, $directory ); return null; } return array( 'directory' => $directory, 'path' => $path );
	}
	private function removeOwnedArchive( ?string $path, ?string $directory ): void { if ( is_string( $path ) && is_string( $directory ) && hash_equals( $directory . DIRECTORY_SEPARATOR . 'package.zip', $path ) ) { $entry = @lstat( $path ); if ( is_array( $entry ) ) { if ( 0040000 === ( $entry['mode'] & 0170000 ) ) @rmdir( $path ); else @unlink( $path ); } } if ( is_string( $directory ) && is_dir( $directory ) ) @rmdir( $directory ); }
	private static function validNativeIdentity( mixed $type, mixed $identity ): bool { if ( ! is_string( $identity ) ) return false; if ( 'theme' === $type ) return 1 === preg_match( '/\A[A-Za-z0-9][A-Za-z0-9._-]{0,99}\z/D', $identity ); return 'plugin' === $type && 1 === preg_match( '/\A[A-Za-z0-9][A-Za-z0-9._-]{0,99}\/[A-Za-z0-9][A-Za-z0-9._-]{0,99}\.php\z/D', $identity ); }
	/** @return array<string,int>|null */ private static function archiveIdentity( string $path, IdentityDescriptor $descriptor ): ?array { $facts = $descriptor->toArray(); clearstatcache( true, $path ); $stat = @lstat( $path ); $hash = is_file( $path ) ? hash_file( 'sha256', $path ) : false; if ( ! is_array( $stat ) || 0100000 !== ( $stat['mode'] & 0170000 ) || $stat['size'] !== $facts['artifact_size'] || ! is_string( $hash ) || ! hash_equals( $facts['artifact_sha256'], $hash ) ) return null; return array( 'dev' => $stat['dev'], 'ino' => $stat['ino'], 'mode' => $stat['mode'], 'mtime' => $stat['mtime'], 'ctime' => $stat['ctime'], 'size' => $stat['size'] ); }
	/** @param array<string,int> $identity */ private static function sameArchiveIdentity( string $path, IdentityDescriptor $descriptor, array $identity ): bool { $current = self::archiveIdentity( $path, $descriptor ); if ( ! is_array( $current ) ) return false; foreach ( $identity as $key => $value ) if ( ! array_key_exists( $key, $current ) || $current[ $key ] !== $value ) return false; return true; }
	/** @return array<string,array{sha256:string,size:int}>|null */ private static function regularFileManifest( string $root ): ?array { $root = rtrim( $root, '/\\' ); $rootStat = @lstat( $root ); if ( ! is_array( $rootStat ) || 0040000 !== ( $rootStat['mode'] & 0170000 ) ) return null; $queue = array( array( 'path' => $root, 'relative' => '' ) ); $manifest = array(); $total = 0; $entriesSeen = 0; while ( array() !== $queue ) { $next = array_pop( $queue ); $entries = @scandir( $next['path'] ); if ( ! is_array( $entries ) ) return null; foreach ( $entries as $entry ) { if ( '.' === $entry || '..' === $entry ) continue; if ( ++$entriesSeen > self::MAX_MANIFEST_ENTRIES ) return null; $path = $next['path'] . DIRECTORY_SEPARATOR . $entry; $relative = '' === $next['relative'] ? $entry : $next['relative'] . '/' . $entry; if ( strlen( $relative ) > PackageIdentityValidator::MAX_ARCHIVE_PATH_BYTES || 1 === preg_match( '/[^\x20-\x7E]|[\\\\:]/', $relative ) ) return null; $stat = @lstat( $path ); if ( ! is_array( $stat ) ) return null; $type = $stat['mode'] & 0170000; if ( 0040000 === $type ) { $queue[] = array( 'path' => $path, 'relative' => $relative ); continue; } if ( 0100000 !== $type || $stat['size'] < 0 || $stat['size'] > PackageIdentityValidator::MAX_EXPANDED_ARCHIVE_BYTES - $total ) return null; $hash = @hash_file( 'sha256', $path ); clearstatcache( true, $path ); $after = @lstat( $path ); if ( ! is_string( $hash ) || ! is_array( $after ) || $after['dev'] !== $stat['dev'] || $after['ino'] !== $stat['ino'] || $after['mode'] !== $stat['mode'] || $after['mtime'] !== $stat['mtime'] || $after['ctime'] !== $stat['ctime'] || $after['size'] !== $stat['size'] ) return null; $total += $stat['size']; $manifest[ $relative ] = array( 'sha256' => $hash, 'size' => $stat['size'] ); } } ksort( $manifest, SORT_STRING ); return array() === $manifest ? null : $manifest; }
	/** @param array<string,array{sha256:string,size:int}> $manifest */ private static function manifestHash( array $manifest ): string { return hash( 'sha256', json_encode( $manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES ) ); }
	/** @param array<string,array{sha256:string,size:int}> $manifest */ private static function manifestExpandedBytes( array $manifest ): int { $total = 0; foreach ( $manifest as $entry ) $total += $entry['size']; return $total; }
	private static function headerValue( string $contents, string $name ): ?string { return 1 === preg_match_all( '/^[ \\t\\/*#@]*' . preg_quote( $name, '/' ) . ':(.*)$/mi', $contents, $matches ) ? trim( $matches[1][0] ) : null; }
	/** @param array<string,mixed> $value @param list<string> $keys */ private static function exactKeys( mixed $value, array $keys ): bool { if ( ! is_array( $value ) || count( $value ) !== count( $keys ) ) return false; foreach ( $keys as $key ) if ( ! array_key_exists( $key, $value ) ) return false; return true; }
}
