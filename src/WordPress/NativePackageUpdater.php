<?php

declare(strict_types=1);

namespace RAN\WPReleaseUpdater\V1\WordPress;

use InvalidArgumentException;
use RAN\WPReleaseUpdater\V1\Archive\PackageIdentityValidator;
use RAN\WPReleaseUpdater\V1\Contract\AcquisitionReceipt;
use RAN\WPReleaseUpdater\V1\Contract\BindingRecord;
use RAN\WPReleaseUpdater\V1\Contract\CanonicalUpdateUri;
use RAN\WPReleaseUpdater\V1\Contract\IdentityDescriptor;
use RAN\WPReleaseUpdater\V1\Contract\ReleaseAdapter;
use RAN\WPReleaseUpdater\V1\Contract\ReleaseVersion;
use RAN\WPReleaseUpdater\V1\Runtime\ReleaseFailure;
use RAN\WPReleaseUpdater\V1\Runtime\SelectedRuntimeState;

/** The single native WordPress lifecycle owner for a sealed neutral release. */
final class NativePackageUpdater {
	private const MAX_DIAGNOSTICS                       = 16;
	private const CONFIGURATION_KEYS                    = array( 'headers', 'installed_package_identity', 'policy', 'target_type', 'update_uri' );
	private const HEADER_KEYS                           = array( 'Author', 'Description', 'Name', 'PluginURI', 'RequiresPHP', 'RequiresWP', 'UpdateURI', 'Version' );
	private bool $registered                            = false;
	private ?IdentityDescriptor $descriptor             = null;
	private ?BindingState $state                        = null;
	private mixed $claim                                = null;
	private bool $leaseHeld                             = false;
	private bool $queuedMultiRun                        = false;
	private bool $shutdownScheduled                     = false;
	/** @var list<string> */ private array $diagnostics = array();
	/** @var array{candidate_header_version:string|null,candidate_tag:string|null,candidate_validation_code:string|null,candidate_version:string|null,failure_code:string|null,installed_version:string|null,last_check:int|null,offered_release_identity:string|null,offered_version:string|null,relationship:string|null} */
	private array $status = array(
		'candidate_header_version'  => null,
		'candidate_tag'             => null,
		'candidate_validation_code' => null,
		'candidate_version'         => null,
		'failure_code'              => null,
		'installed_version'         => null,
		'last_check'                => null,
		'offered_release_identity'  => null,
		'offered_version'           => null,
		'relationship'              => null,
	);
	/** @var array{claim:array<string,mixed>,descriptor:IdentityDescriptor,installed:string}|null */
	private ?array $discoverySnapshot = null;
	private int $discoveryEpoch       = 0;
	private OwnedArchiveStore $archiveStore;
	private StagedPackageManifest $manifestBuilder;
	private PendingInstallState $pendingInstall;

	/** @param array<string,string> $headers @param array<string,mixed> $archivePolicy */
	private function __construct(
		private string $targetType,
		private string $installedIdentity,
		private string $updateUri,
		private string $policy,
		private array $headers,
		private BindingRecord $binding,
		private ReleaseAdapter $adapter,
		private object $wpdb,
		private array $archivePolicy,
		private PackageIdentityValidator $validator,
		private ?SelectedRuntimeState $selectedRuntimeState = null,
		private bool $nativeDiscoveryReuse = false
	) {
		$this->archiveStore    = new OwnedArchiveStore();
		$this->manifestBuilder = new StagedPackageManifest();
		$this->pendingInstall  = new PendingInstallState();
	}

	/** @param array<string,mixed> $configuration @param array<string,mixed> $archivePolicy */
	public static function fromConfiguration(
		array $configuration,
		BindingRecord $binding,
		ReleaseAdapter $adapter,
		object $wpdb,
		array $archivePolicy,
		?PackageIdentityValidator $validator = null,
		?SelectedRuntimeState $selectedRuntimeState = null,
		bool $nativeDiscoveryReuse = false
	): ?self {
		$bindingFacts = $binding->toArray();
		if (
			! self::validConfiguration( $configuration, $binding )
			|| $configuration['policy'] !== $bindingFacts['update_policy']
			|| ! array_key_exists( 'theme_template', $archivePolicy )
			|| ! is_string( $archivePolicy['theme_template'] )
			|| ! hash_equals( $bindingFacts['theme_template'], $archivePolicy['theme_template'] )
		) {
			return null;
		}
		$uri = CanonicalUpdateUri::canonicalize( $configuration['update_uri'] );
		if ( ! is_string( $uri ) ) {
			return null;
		}
		return new self(
			$configuration['target_type'],
			$configuration['installed_package_identity'],
			$uri,
			$configuration['policy'],
			$configuration['headers'],
			$binding,
			$adapter,
			$wpdb,
			$archivePolicy,
			$validator ?? new PackageIdentityValidator(),
			$selectedRuntimeState,
			$nativeDiscoveryReuse
		);
	}

	public function register(): void {
		if ( $this->registered ) {
			return;
		}

		$host = parse_url( $this->updateUri, PHP_URL_HOST );
		if ( ! is_string( $host ) || '' === $host ) {
			return;
		}

		add_filter( ( 'plugin' === $this->targetType ? 'update_plugins_' : 'update_themes_' ) . $host, array( $this, 'filterUpdate' ), 10, 4 );
		if ( 'plugin' === $this->targetType ) {
			add_filter( 'plugins_api', array( $this, 'filterPluginInformation' ), 10, 3 );
		}
		add_filter( 'plugin' === $this->targetType ? 'auto_update_plugin' : 'auto_update_theme', array( $this, 'filterAutoUpdate' ), 10, 2 );
		add_filter( 'upgrader_package_options', array( $this, 'capturePackageOptions' ), PHP_INT_MAX, 1 );
		add_filter( 'upgrader_pre_download', array( $this, 'filterPreDownload' ), PHP_INT_MAX, 4 );
		add_filter( 'upgrader_pre_install', array( $this, 'filterPreInstall' ), 1, 2 );
		add_filter( 'pre_unzip_file', array( $this, 'filterPreUnzipFile' ), PHP_INT_MAX, 5 );
		add_filter( 'upgrader_source_selection', array( $this, 'filterSourceSelection' ), PHP_INT_MAX, 4 );
		add_filter( 'upgrader_install_package_result', array( $this, 'captureInstallPackageResult' ), PHP_INT_MAX, 2 );
		add_action( 'upgrader_process_complete', array( $this, 'observeCompletion' ), 10, 2 );
		$this->registered = true;
	}

	/** @param array<string,mixed>|false $update @param array<string,mixed> $packageData @param list<string> $locales */
	public function filterUpdate( mixed $update, array $packageData, string $packageIdentity, array $locales ): mixed {
		unset( $locales );
		if ( ! hash_equals( $this->installedIdentity, $packageIdentity ) || ! $this->live() ) {
			return $update;
		}
		$this->beginOperation();
		$installed                         = is_string( $packageData['Version'] ?? null ) ? $packageData['Version'] : '';
		$this->status                      = self::emptyStatus();
		$this->status['installed_version'] = $installed;
		$this->status['last_check']        = time();
		$runtimeUri                        = is_string( $packageData['UpdateURI'] ?? null ) ? $packageData['UpdateURI'] : '';
		if ( null === ReleaseVersion::normalizeHeader( $installed ) || ! $this->matchesRuntimeUri( $runtimeUri ) ) {
			return $this->diagnose( 'runtime_package_identity_invalid', $update );
		}
		if ( in_array( $this->policy, array( 'disabled', 'forced-off' ), true ) ) {
			return false;
		}
		if ( ! $this->directFilesystem() || ! $this->claimDiscovery() ) {
			return $update;
		}
		$descriptor = $this->discover( $installed );
		if ( ! $descriptor instanceof IdentityDescriptor || ! $this->manualEligible( $descriptor ) ) {
			return $update;
		}
		$token = $this->token( $descriptor );
		if ( null === $token ) {
			return $update;
		}
		$facts                                    = $descriptor->toArray();
		$this->status['offered_version']          = $facts['version'];
		$this->status['offered_release_identity'] = $descriptor->releaseIdentity();
		$offer                                    = array(
			'id'                                  => $this->updateUri,
			'slug'                                => $this->informationSlug(),
			'url'                                 => $this->updateUri,
			'package'                             => $token,
			'requires'                            => $this->headers['RequiresWP'],
			'requires_php'                        => $this->headers['RequiresPHP'],
			'version'                             => $facts['version'],
			'autoupdate'                          => $this->automaticEligible( $descriptor ),
			'ran_wp_release_updater_binding_hash' => $this->binding->bindingHash(),
		);
		if ( 'plugin' === $this->targetType ) {
			$offer['plugin'] = $this->installedIdentity;
		} else {
			$offer['theme'] = $this->installedIdentity;
		}
		return $offer;
	}

	public function filterPluginInformation( mixed $result, string $action, mixed $arguments ): mixed {
		if ( 'plugin_information' !== $action || ! is_object( $arguments ) || $this->informationSlug() !== ( $arguments->slug ?? null ) || ! $this->live() ) {
			return $result;
		}
		$this->beginOperation();
		if ( ! $this->directFilesystem() || ! $this->claimDiscovery() ) {
			return $result;
		}
		$descriptor = $this->discover( $this->headers['Version'] );
		if ( ! $descriptor instanceof IdentityDescriptor || ! $this->manualEligible( $descriptor ) ) {
			return $result;
		}
		$facts               = $descriptor->toArray();
		$info                = new \stdClass();
		$info->name          = $this->headers['Name'];
		$info->slug          = $this->informationSlug();
		$info->version       = $facts['version'];
		$info->author        = $this->headers['Author'];
		$info->homepage      = $this->headers['PluginURI'];
		$info->requires      = $this->headers['RequiresWP'];
		$info->requires_php  = $this->headers['RequiresPHP'];
		$info->download_link = $this->token( $descriptor ) ?? '';
		$info->external      = true;
		$info->sections      = array(
			'description' => $this->headers['Description'],
			'changelog'   => '',
		);
		return $info;
	}

	public function filterAutoUpdate( ?bool $update, mixed $item ): ?bool {
		if ( ! is_object( $item ) || ! $this->matchesItemIdentity( $item ) || ! $this->live() ) {
			return $update;
		}
		$this->beginOperation();
		$descriptor = $this->parseToken( is_string( $item->package ?? null ) ? $item->package : '' );
		return $this->directFilesystem() && $descriptor instanceof IdentityDescriptor && $this->newerThanInstalled( $descriptor ) && $this->automaticEligible( $descriptor );
	}

	/** @param array<string,mixed> $hookExtra */
	public function capturePackageOptions( mixed $options ): mixed {
		if ( ! $this->live() ) {
			return $options;
		}
		$extra = is_array( $options ) && is_array( $options['hook_extra'] ?? null )
			? $options['hook_extra']
			: null;
		if ( is_array( $extra ) && $this->matchesOperation( $extra ) ) {
			$this->beginOperation();
			$this->queuedMultiRun = true === ( $options['is_multi'] ?? false );
		}
		return $options;
	}
	/** @param array<string,mixed> $hookExtra */
	public function filterPreDownload( mixed $reply, string $package, mixed $upgrader, array $hookExtra ): mixed {
		unset( $upgrader );
		if ( ! $this->live() || ! $this->matchesTargetIdentity( $hookExtra ) ) {
			return $reply;
		}
		$this->clearDiscoverySnapshot();
		if ( ! $this->matchesOperation( $hookExtra ) ) {
			return false !== $reply && ! $reply instanceof \WP_Error ? $this->failure( 'unverified_pre_download_result' ) : $reply;
		}
		$queuedMultiRun       = $this->queuedMultiRun;
		$this->queuedMultiRun = false;
		if ( $reply instanceof \WP_Error ) {
			return $reply;
		}
		if ( false !== $reply ) {
			return $this->failure( 'unverified_pre_download_result' );
		}
		$this->clearPending( false );
		$token = $this->parseToken( $package );
		if ( ! $this->directFilesystem() || ! $token instanceof IdentityDescriptor || ! $this->newerThanInstalled( $token ) || ! $this->manualEligible( $token ) ) {
			return $this->failure( 'unverified_pre_download' );
		}
		if ( ! $this->claimDiscovery() ) {
			return $this->failure( 'binding_fence_lost' );
		}
		try {
			$fresh = $this->adapter->inspect( $token->releaseIdentity(), $token->toArray()['tag'] );
			BindingRecord::assertDescriptorBinding( $fresh, $this->binding );
		} catch ( \Throwable ) {
			return $this->failure( 'acquisition_failed' );
		}
		if ( ! $this->newerThanInstalled( $fresh ) ) {
			return $this->failure( 'unverified_pre_download' );
		}
		if ( ! hash_equals( $token->fingerprintValue(), $fresh->fingerprintValue() ) ) {
			return $this->failure( 'remote_release_changed' );
		}
		$this->descriptor = $fresh;
		$renewed          = BindingFenceCoordinator::renewPersistentBindingState( $this->wpdb, $this->state, $this->claim, 3600 );
		if ( 'renewed' !== $renewed['result'] || ! $renewed['current'] instanceof BindingState ) {
			return $this->failure( 'binding_fence_lost' );
		}
		$this->state = $renewed['current'];
		$this->claim = $this->claim( $this->state );
		try {
			$artifact = $this->adapter->acquire( $fresh );
			$owned    = $artifact->inspect( fn( string $path ): ?array => $this->validatedCopy( $path, $fresh ) );
		} catch ( \Throwable ) {
			return $this->failure( 'acquisition_failed' );
		}
		if ( ! is_array( $owned ) ) {
			return $this->failure( 'acquisition_identity_invalid' );
		}
		$identity = $this->archiveStore->identity( $owned['path'], $fresh );
		if ( null === $identity ) {
			$this->archiveStore->remove( $owned['path'], $owned['directory'] );
			return $this->failure( 'acquisition_identity_invalid' );
		}
		$verified = $this->verifyCurrent();
		if ( null === $verified ) {
			$this->archiveStore->remove( $owned['path'], $owned['directory'] );
			return $this->failure( 'binding_fence_lost' );
		}
		try {
			$proof   = $this->validator->validate( $fresh, $this->archivePolicy, $owned['path'] );
			$receipt = AcquisitionReceipt::issue( $verified['current'], $fresh, $this->validator, $proof, $verified['now'] );
		} catch ( \Throwable ) {
			$this->archiveStore->remove( $owned['path'], $owned['directory'] );
			return $this->failure( 'acquisition_identity_invalid' );
		}
		$this->state = $verified['current'];
		$this->pendingInstall->begin( $owned['path'], $owned['directory'], $identity, $receipt, $queuedMultiRun );
		$this->scheduleFinalization();
		return $owned['path'];
	}

	/** @param list<string> $neededDirs */
	public function filterPreUnzipFile( mixed $pre, string $file, string $destination, array $neededDirs, float $requiredSpace ): mixed {
		unset( $destination, $neededDirs, $requiredSpace );
		if ( ! $this->pendingInstall->active() || ! is_string( $this->pendingInstall->archive() ) || ! hash_equals( $this->pendingInstall->archive(), $file ) ) {
			return $pre;
		}
		if ( ! $this->live() ) {
			$this->clearPending();
			return $this->failure( 'runtime_liveness_lost' );
		}
		$verified = $this->verifyCurrent();
		if (
			! $this->directFilesystem()
			|| null === $verified
			|| ! is_array( $this->pendingInstall->archiveIdentity() )
			|| ! $this->archiveStore->sameIdentity( $file, $this->descriptor, $this->pendingInstall->archiveIdentity() )
			|| ! $this->pendingInstall->receipt() instanceof AcquisitionReceipt
		) {
			$this->clearPending();
			return $this->failure( 'archive_changed_before_extraction' );
		}
		try {
			AcquisitionReceipt::assertFresh( $this->pendingInstall->receipt(), $verified['current'], $this->descriptor, $verified['now'] );
		} catch ( InvalidArgumentException ) {
			$this->clearPending();
			return $this->failure( 'archive_changed_before_extraction' );
		}
		$this->state = $verified['current'];
		if ( ! $this->pendingInstall->admitExtraction() ) {
			$this->clearPending();
			return $this->failure( 'archive_changed_before_extraction' );
		}
		return $pre;
	}

	/** @param array<string,mixed> $hookExtra */
	public function filterSourceSelection( mixed $source, string $remoteSource, mixed $upgrader, array $hookExtra ): mixed {
		unset( $remoteSource, $upgrader );
		if ( ! $this->matchesOperation( $hookExtra ) ) {
			return $source;
		}
		if ( ! $this->live() ) {
			if ( ! $this->pendingInstall->active() ) {
				return $source;
			}
			$this->clearPending();
			return $this->failure( 'runtime_liveness_lost' );
		}

		$manifest = is_string( $source ) && $this->matchesStagedMetadata( $source )
			? $this->manifestBuilder->build( $source )
			: null;
		$verified = $this->verifyCurrent();
		if (
			! $this->directFilesystem()
			|| ! $this->pendingInstall->active()
			|| ! $this->pendingInstall->extractionAdmitted()
			|| null === $verified
			|| ! $this->pendingInstall->receipt() instanceof AcquisitionReceipt
			|| ! is_string( $source )
			|| ! is_array( $manifest )
		) {
			$this->clearPending();
			return $this->failure( 'staged_package_identity_invalid' );
		}

		try {
			AcquisitionReceipt::assertArchiveManifest(
				$this->pendingInstall->receipt(),
				$verified['current'],
				$this->descriptor,
				$verified['now'],
				$this->manifestBuilder->hash( $manifest ),
				count( $manifest ),
				$this->manifestBuilder->expandedBytes( $manifest )
			);
		} catch ( InvalidArgumentException ) {
			$this->clearPending();
			return $this->failure( 'staged_package_identity_invalid' );
		}

		$this->state = $verified['current'];
		if ( ! $this->pendingInstall->stageManifest( $manifest ) ) {
			$this->clearPending();
			return $this->failure( 'staged_package_identity_invalid' );
		}
		return $source;
	}
	/** @param array<string,mixed> $hookExtra */
	public function filterPreInstall( mixed $response, array $hookExtra ): mixed {
		if ( ! $this->matchesOperation( $hookExtra ) ) {
			return $response;
		}
		if ( ! $this->live() ) {
			if ( ! $this->pendingInstall->active() ) {
				return $response;
			}
			$this->clearPending();
			return $this->failure( 'runtime_liveness_lost' );
		}
		$verified = $this->verifyCurrent();
		if (
			! $this->directFilesystem()
			|| ! $this->pendingInstall->active()
			|| ! $this->pendingInstall->extractionAdmitted()
			|| null === $verified
			|| ! $this->pendingInstall->receipt() instanceof AcquisitionReceipt
			|| $response instanceof \WP_Error
		) {
			$this->clearPending();
			return $this->failure( 'unverified_pre_install' );
		}
		try {
			AcquisitionReceipt::assertFresh( $this->pendingInstall->receipt(), $verified['current'], $this->descriptor, $verified['now'] );
		} catch ( InvalidArgumentException ) {
			$this->clearPending();
			return $this->failure( 'unverified_pre_install' );
		}
		$this->state = $verified['current'];
		return $response;
	}
	/** @param array<string,mixed> $hookExtra */
	public function captureInstallPackageResult( mixed $result, array $hookExtra ): mixed {
		if ( ! $this->matchesOperation( $hookExtra ) ) {
			return $result;
		}
		if ( ! $this->live() ) {
			if ( ! $this->pendingInstall->active() ) {
				return $result;
			}
			$this->clearPending();
			return $this->failure( 'runtime_liveness_lost' );
		}
		if ( ! $this->pendingInstall->active() || $result instanceof \WP_Error || false === $result ) {
			$this->clearPending();
			return $this->failure( 'unverified_install_result' );
		}
		if ( ! $this->pendingInstall->captureInstallResult( $result ) ) {
			$this->clearPending();
			return $this->failure( 'unverified_install_result' );
		}
		return $result;
	}

	/** @param array<string,mixed> $hookExtra */
	public function observeCompletion( mixed $upgrader, array $hookExtra ): void {
		unset( $upgrader );
		if ( $this->live() && $this->matchesCompletion( $hookExtra ) ) {
			$this->clearDiscoverySnapshot();
			$this->pendingInstall->observeCompletion();
		}
	}
	/** Finalization is deliberately after Core rollback and backup cleanup. */
	public function finalizePendingInstall(): void {
		if ( ! $this->live() ) {
			if ( $this->pendingInstall->active() ) {
				$this->diagnose( 'runtime_liveness_lost', null );
			}
			$this->clearPending();
			return;
		}
		if ( ! $this->pendingInstall->active() ) {
			$this->clearPending();
			return;
		}

		try {
			$destination             = is_array( $this->pendingInstall->installResult() ) && is_string( $this->pendingInstall->installResult()['destination'] ?? null )
				? $this->pendingInstall->installResult()['destination']
				: null;
			$manifest                = is_string( $destination ) ? $this->manifestBuilder->build( $destination ) : null;
			$verified                = $this->verifyCurrent();
			$archiveManifestVerified = false;
			if (
				null !== $verified
				&& $this->pendingInstall->receipt() instanceof AcquisitionReceipt
				&& is_array( $manifest )
			) {
				try {
					AcquisitionReceipt::assertArchiveManifest(
						$this->pendingInstall->receipt(),
						$verified['current'],
						$this->descriptor,
						$verified['now'],
						$this->manifestBuilder->hash( $manifest ),
						count( $manifest ),
						$this->manifestBuilder->expandedBytes( $manifest )
					);
					$archiveManifestVerified = true;
					$this->state             = $verified['current'];
				} catch ( InvalidArgumentException ) {
					$archiveManifestVerified = false;
				}
			}
			if (
				! $this->pendingInstall->installResultCaptured()
				|| ( ! $this->pendingInstall->completionObserved() && ! $this->pendingInstall->multiRun() )
				|| ! $archiveManifestVerified
				|| ! is_string( $destination )
				|| ! is_array( $this->pendingInstall->stagedManifest() )
				|| ! is_array( $manifest )
				|| ! hash_equals( $this->manifestBuilder->hash( $this->pendingInstall->stagedManifest() ), $this->manifestBuilder->hash( $manifest ) )
				|| ! $this->matchesStagedMetadata( $destination )
			) {
				$this->diagnose( 'outcome_uncertain', null );
				return;
			}
			$completed = BindingFenceCoordinator::completePersistentInstall( $this->wpdb, $this->state, $this->claim, $this->pendingInstall->receipt(), $this->descriptor );
			$this->diagnose( 'completed' === $completed['result'] ? 'update_completed' : 'outcome_uncertain', null );
		} finally {
			$this->clearPending();
		}
	}
	/** @return list<string> */ public function diagnostics(): array {
		return $this->diagnostics; }
	/** @return array{candidate_header_version:string|null,candidate_tag:string|null,candidate_validation_code:string|null,candidate_version:string|null,failure_code:string|null,installed_version:string|null,last_check:int|null,offered_release_identity:string|null,offered_version:string|null,relationship:string|null} */ public function status(): array {
		return $this->status; }
	public function refresh(): bool {
		if ( ! $this->live() ) {
			$this->clearPending();
			return false;
		}
		$this->diagnostics = array();
		$this->status      = self::emptyStatus();
		$this->clearDiscoverySnapshot();
		$this->queuedMultiRun = false;
		$this->clearPending();
		return true;
	}

	private function live(): bool {
		$live = ! $this->selectedRuntimeState instanceof SelectedRuntimeState || $this->selectedRuntimeState->brokerIsLive();
		if ( ! $live ) {
			$this->clearDiscoverySnapshot();
		}
		return $live;
	}

	private function beginOperation(): void {
		if ( $this->selectedRuntimeState instanceof SelectedRuntimeState ) {
			$this->selectedRuntimeState->beginOperation( $this->targetType );
		}
	}

	/** @param array<string,mixed> $configuration */
	private static function validConfiguration( array $configuration, BindingRecord $binding ): bool {
		if ( ! self::exactKeys( $configuration, self::CONFIGURATION_KEYS ) || ! is_array( $configuration['headers'] ) || ! self::exactKeys( $configuration['headers'], self::HEADER_KEYS ) || ! is_string( $configuration['update_uri'] ) || ! in_array( $configuration['policy'], array( 'disabled', 'forced-off', 'manual', 'automatic' ), true ) || ! self::validNativeIdentity( $configuration['target_type'], $configuration['installed_package_identity'] ) ) {
			return false;
		}
		foreach ( $configuration['headers'] as $header ) {
			if ( ! is_string( $header ) || strlen( $header ) > 8192 ) {
				return false;
			}
		}
		$facts = $binding->toArray();
		if ( $configuration['target_type'] !== $facts['target_type'] || ! hash_equals( $configuration['installed_package_identity'], $facts['installed_package_identity'] ) || null === ReleaseVersion::normalizeHeader( $configuration['headers']['Version'] ) ) {
			return false;
		}
		return $facts['canonical_update_uri'] === CanonicalUpdateUri::canonicalizeBoundaries(
			array(
				'archive_preflight' => $facts['canonical_update_uri'],
				'configuration'     => $configuration['update_uri'],
				'offer'             => $configuration['headers']['UpdateURI'],
				'staged_package'    => $facts['canonical_update_uri'],
			)
		);
	}

	private function matchesRuntimeUri( string $runtimeUri ): bool {
		return $this->updateUri === CanonicalUpdateUri::canonicalizeBoundaries(
			array(
				'archive_preflight' => $this->binding->toArray()['canonical_update_uri'],
				'configuration'     => $this->updateUri,
				'offer'             => $runtimeUri,
				'staged_package'    => $this->headers['UpdateURI'],
			)
		); }
	private function manualEligible( IdentityDescriptor $descriptor ): bool {
		$facts = $descriptor->toArray()['assurance_facts'];
		foreach ( array( 'exact_artifact_identity', 'exact_commit_identity', 'exact_reacquisition_supported', 'exact_release_identity', 'repository_identity_stable', 'trusted_digest_source' ) as $fact ) {
			if ( true !== $facts[ $fact ] ) {
				return false;
			}
		} return true; }
	private function automaticEligible( IdentityDescriptor $descriptor ): bool {
		$facts = $descriptor->toArray()['assurance_facts'];
		return 'automatic' === $this->policy && $this->manualEligible( $descriptor ) && true === $facts['publication_immutable'] && true === $facts['provenance_verified']; }
	private function newerThanInstalled( IdentityDescriptor $descriptor ): bool {
		return ReleaseVersion::RELATIONSHIP_NEWER === ReleaseVersion::relationship( $descriptor->toArray()['version'], $this->headers['Version'] ); }
	private function informationSlug(): string {
		return 'ran-wp-release-updater-' . substr( hash( 'sha256', $this->targetType . "\0" . $this->installedIdentity ), 0, 24 ); }
	private function matchesItemIdentity( object $item ): bool {
		$identity = 'plugin' === $this->targetType ? ( $item->plugin ?? null ) : ( $item->theme ?? null );
		return is_string( $identity ) && hash_equals( $this->installedIdentity, $identity ); }
	/** @param array<string,mixed> $extra */ private function matchesOperation( array $extra ): bool {
		$identity = 'plugin' === $this->targetType ? ( $extra['plugin'] ?? null ) : ( $extra['theme'] ?? null );
		if ( ! is_string( $identity ) || ! hash_equals( $this->installedIdentity, $identity ) ) {
			return false;
		} return ( ! array_key_exists( 'action', $extra ) || 'update' === $extra['action'] ) && ( ! array_key_exists( 'type', $extra ) || $this->targetType === $extra['type'] ); }
	/** @param array<string,mixed> $extra */ private function matchesTargetIdentity( array $extra ): bool {
		$identity = 'plugin' === $this->targetType ? ( $extra['plugin'] ?? null ) : ( $extra['theme'] ?? null );
		return is_string( $identity ) && hash_equals( $this->installedIdentity, $identity ); }
	/** @param array<string,mixed> $extra */ private function matchesCompletion( array $extra ): bool {
		if ( 'update' !== ( $extra['action'] ?? null ) || $this->targetType !== ( $extra['type'] ?? null ) ) {
			return false;
		} $key   = 'plugin' === $this->targetType ? 'plugins' : 'themes';
		$single  = 'plugin' === $this->targetType ? 'plugin' : 'theme';
		$targets = $extra[ $key ] ?? array( $extra[ $single ] ?? null );
		return is_array( $targets ) && in_array( $this->installedIdentity, $targets, true ); }
	private function matchesStagedMetadata( string $source, ?string $expectedVersion = null ): bool {
		$source    = rtrim( $source, '/\\' );
		$directory = @lstat( $source );
		if (
			! is_array( $directory )
			|| 0040000 !== ( $directory['mode'] & 0170000 )
			|| ! hash_equals( $this->packageRoot(), basename( $source ) )
		) {
			return false;
		}

		$header = 'plugin' === $this->targetType ? basename( $this->installedIdentity ) : 'style.css';
		$path   = $source . DIRECTORY_SEPARATOR . $header;
		$file   = @lstat( $path );
		if ( ! is_array( $file ) || 0100000 !== ( $file['mode'] & 0170000 ) ) {
			return false;
		}

		$contents = @file_get_contents( $path, false, null, 0, 8192 );
		if ( ! is_string( $contents ) ) {
			return false;
		}

		$parsed = PackageIdentityValidator::parseHeader( $contents, $this->targetType );
		if ( 'installed_header_verified' !== $parsed['code'] ) {
			return false;
		}

		$headers           = $parsed['headers'];
		$expectedVersion ??= $this->descriptor instanceof IdentityDescriptor
			? $this->descriptor->toArray()['version']
			: null;

		return hash_equals( $this->headers['Name'], $headers['Name'] )
			&& hash_equals( $this->binding->toArray()['theme_template'], $headers['Template'] )
			&& is_string( $expectedVersion )
			&& 0 === ReleaseVersion::compare( $headers['Version'], $expectedVersion )
			&& $this->matchesRuntimeUri( $headers['UpdateURI'] );
	}
	private function packageRoot(): string {
		return 'plugin' === $this->targetType ? dirname( $this->installedIdentity ) : $this->installedIdentity; }
	private function scheduleFinalization(): void {
		if ( $this->shutdownScheduled ) {
			return;
		}

		add_action( 'shutdown', array( $this, 'finalizePendingInstall' ), PHP_INT_MAX, 0 );
		$this->shutdownScheduled = true;
	}
	private function claimDiscovery(): bool {
		if ( $this->leaseHeld && null !== $this->verifyCurrent() ) {
			return true;
		}
		$this->clearDiscoverySnapshot();
		$this->leaseHeld = false;
		$this->state     = null;
		$this->claim     = null;
		try {
			$owner = bin2hex( random_bytes( 32 ) );
		} catch ( \Throwable ) {
			return false; }
		$claimed = BindingFenceCoordinator::claimPersistentBindingState( $this->wpdb, $this->binding, $owner, 600 );
		if ( 'claimed' !== $claimed['result'] || ! $claimed['current'] instanceof BindingState ) {
			return false;
		}
		$this->state     = $claimed['current'];
		$this->claim     = $this->claim( $this->state );
		$this->leaseHeld = true;
		$this->scheduleFinalization();
		return true;
	}
	/** claimDiscovery() has established the current binding fence before discovery. */
	private function discover( string $installed ): ?IdentityDescriptor {
		$normalizedInstalled = ReleaseVersion::normalizeHeader( $installed );
		if ( ! is_string( $normalizedInstalled ) ) {
			return null;
		}
		$reused = $this->reusableDiscovery( $normalizedInstalled );
		if ( $reused instanceof IdentityDescriptor ) {
			return $reused;
		}
		$this->clearDiscoverySnapshot();
		$discoveryClaim = $this->claim;
		$discoveryEpoch = $this->discoveryEpoch;
		try {
			$listed     = $this->adapter->listReleases();
			$candidates = $listed['candidates'] ?? null;
		} catch ( \Throwable ) {
			$this->status['candidate_validation_code'] = 'release_list_failed';
			return null;
		}
		$rateLimit = $listed['rate_limit'] ?? null;
		if ( is_array( $rateLimit ) && true === ( $rateLimit['limited'] ?? null ) ) {
			$this->status['candidate_validation_code'] = 'release_list_failed';
			return null;
		}
		if ( ! is_array( $candidates ) || count( $candidates ) > 8 ) {
			$this->status['candidate_validation_code'] = 'candidate_list_invalid';
			return null; }
		foreach ( $candidates as $candidate ) {
			if ( ! is_array( $candidate ) || ! is_string( $candidate['release_identity'] ?? null ) || ! is_string( $candidate['tag'] ?? null ) || ! is_string( $candidate['version'] ?? null ) ) {
				$this->status['candidate_validation_code'] = 'candidate_invalid';
				return null; }
			$this->status['candidate_tag']     = $candidate['tag'];
			$this->status['candidate_version'] = $candidate['version'];
			$this->status['relationship']      = ReleaseVersion::relationship( $candidate['version'], $installed );
			if ( ReleaseVersion::RELATIONSHIP_NEWER !== $this->status['relationship'] ) {
				$this->status['candidate_validation_code'] = 'candidate_not_newer';
				continue; }
			try {
				$descriptor = $this->adapter->inspect( $candidate['release_identity'], $candidate['tag'] );
				BindingRecord::assertDescriptorBinding( $descriptor, $this->binding );
			} catch ( ReleaseFailure $failure ) {
				$this->status['candidate_validation_code'] = 'candidate_inspection_failed';
				if ( self::canRejectCandidate( $failure ) ) {
					continue;
				}
				return null;
			} catch ( \Throwable ) {
				$this->status['candidate_validation_code'] = 'candidate_inspection_failed';
				return null;
			}
			$facts = $descriptor->toArray();
			if ( ! hash_equals( $candidate['release_identity'], $facts['release_identity'] ) || ! hash_equals( $candidate['tag'], $facts['tag'] ) || 0 !== ReleaseVersion::compare( $candidate['version'], $facts['version'] ) ) {
				$this->status['candidate_validation_code'] = 'candidate_descriptor_mismatch';
				return null; }
			$artifact = null;
			try {
				$artifact = $this->adapter->acquire( $descriptor );
				$valid    = $artifact->inspect( fn( string $path ) => $this->validator->validate( $descriptor, $this->archivePolicy, $path ) );
			} catch ( ReleaseFailure $failure ) {
				$this->status['candidate_validation_code'] = 'candidate_validation_failed';
				if ( $artifact instanceof \RAN\WPReleaseUpdater\V1\Archive\TemporaryArtifact && ! $artifact->discard() ) {
					return null;
				}
				if ( self::canRejectCandidate( $failure ) ) {
					continue;
				}
				return null;
			} catch ( \Throwable ) {
				$this->status['candidate_validation_code'] = 'candidate_validation_failed';
				if ( $artifact instanceof \RAN\WPReleaseUpdater\V1\Archive\TemporaryArtifact ) {
					$artifact->discard();
				}
				return null;
			}
			$this->status['candidate_validation_code'] = $valid->code();
			if ( ! $valid->isValid() ) {
				if ( ! $artifact->discard() ) {
					$this->status['candidate_validation_code'] = 'candidate_validation_failed';
					return null;
				}
				continue;
			}
			if ( ! $artifact->discard() ) {
				$this->status['candidate_validation_code'] = 'candidate_validation_failed';
				return null;
			}
			$this->status['candidate_header_version'] = $facts['version'];
			$this->descriptor                         = $descriptor;
			if ( $this->nativeDiscoveryReuse && is_array( $discoveryClaim ) && null !== $this->verifyCurrent() && $this->live() && $this->discoveryEpoch === $discoveryEpoch && $this->claim === $discoveryClaim ) {
				$this->discoverySnapshot = array(
					'claim'      => $discoveryClaim,
					'descriptor' => $descriptor,
					'installed'  => $normalizedInstalled,
				);
			}
			return $descriptor;
		}
		return null;
	}
	private static function canRejectCandidate( ReleaseFailure $failure ): bool {
		return in_array( $failure->releaseCode, array( 'release_unavailable', 'package_incompatible' ), true )
			&& in_array( $failure->cleanupStatus, array( 'not_applicable', 'complete' ), true );
	}
	private function reusableDiscovery( string $installed ): ?IdentityDescriptor {
		if ( ! $this->nativeDiscoveryReuse || ! is_array( $this->discoverySnapshot ) || ! is_array( $this->claim ) || $this->discoverySnapshot['installed'] !== $installed || $this->discoverySnapshot['claim'] !== $this->claim ) {
			return null;
		}
		$descriptor                                = $this->discoverySnapshot['descriptor'];
		$facts                                     = $descriptor->toArray();
		$this->status['candidate_tag']             = $facts['tag'];
		$this->status['candidate_version']         = $facts['version'];
		$this->status['candidate_header_version']  = $facts['version'];
		$this->status['candidate_validation_code'] = 'archive_identity_verified';
		$this->status['relationship']              = ReleaseVersion::relationship( $facts['version'], $installed );
		$this->descriptor                          = $descriptor;
		return $descriptor;
	}
	private function clearDiscoverySnapshot(): void {
		++$this->discoveryEpoch;
		$this->discoverySnapshot                  = null;
		$this->status['offered_release_identity'] = null;
		$this->status['offered_version']          = null; }
	private function token( IdentityDescriptor $descriptor ): ?string {
		$value = array(
			'binding_hash' => $this->binding->bindingHash(),
			'descriptor'   => $descriptor->toArray(),
			'schema'       => 1,
		);
		try {
			$json = json_encode( $value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES );
		} catch ( \JsonException ) {
			return null; }
		$token = 'ran-wp-release-updater:v1:' . rtrim( strtr( base64_encode( $json ), '+/', '-_' ), '=' );
		return strlen( $token ) <= 8192 ? $token : null;
	}
	private function parseToken( string $token ): ?IdentityDescriptor {
		$prefix = 'ran-wp-release-updater:v1:';
		if ( ! str_starts_with( $token, $prefix ) || strlen( $token ) > 8192 ) {
			return null;
		}
		$encoded = substr( $token, strlen( $prefix ) );
		if ( '' === $encoded || 1 === preg_match( '/[^A-Za-z0-9_-]/', $encoded ) ) {
			return null;
		}
		$raw = base64_decode( strtr( $encoded, '-_', '+/' ) . str_repeat( '=', ( 4 - strlen( $encoded ) % 4 ) % 4 ), true );
		if ( ! is_string( $raw ) || strlen( $raw ) > 6144 || ! hash_equals( $encoded, rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' ) ) ) {
			return null;
		}
		try {
			$value     = json_decode( $raw, true, 32, JSON_THROW_ON_ERROR );
			$canonical = json_encode( $value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES );
			if ( ! is_string( $canonical ) || ! hash_equals( $raw, $canonical ) ) {
				return null;
			}
			if ( ! self::exactKeys( $value, array( 'binding_hash', 'descriptor', 'schema' ) ) || 1 !== $value['schema'] ) {
				return null;
			}
			if ( ! is_string( $value['binding_hash'] ) || ! hash_equals( $this->binding->bindingHash(), $value['binding_hash'] ) ) {
				return null;
			}
			$descriptor = IdentityDescriptor::rehydrate( $value['descriptor'] );
			BindingRecord::assertDescriptorBinding( $descriptor, $this->binding );
			return $descriptor;
		} catch ( \Throwable ) {
			return null; }
	}
	/** @return array{directory:string,path:string}|null */ private function validatedCopy( string $path, IdentityDescriptor $descriptor ): ?array {
		try {
			$proof = $this->validator->validate( $descriptor, $this->archivePolicy, $path );
		} catch ( \Throwable ) {
			return null;
		} return $proof->isValid() ? $this->archiveStore->copy( $path, $descriptor ) : null; }
	/** @return array<string,mixed> */ private function claim( BindingState $state ): array {
		return array(
			'binding_generation' => $state->bindingGeneration(),
			'binding_hash'       => $state->binding()->bindingHash(),
			'lease_deadline'     => $state->leaseDeadline(),
			'owner_token'        => $state->ownerToken(),
		); }
	/** @return array{current:BindingState,now:int}|null */ private function verifyCurrent(): ?array {
		if ( ! $this->state instanceof BindingState ) {
			return null;
		} $verified = BindingFenceCoordinator::verifyPersistentBindingState( $this->wpdb, $this->state, $this->claim );
		return 'verified' === $verified['result'] && $verified['current'] instanceof BindingState && is_int( $verified['now'] ?? null ) ? array(
			'current' => $verified['current'],
			'now'     => $verified['now'],
		) : null; }
	private function clearPending( bool $release = true ): void {
		$this->clearDiscoverySnapshot();
		$this->archiveStore->remove( $this->pendingInstall->archive(), $this->pendingInstall->archiveDirectory() );
		$this->pendingInstall->clear();
		if ( $release && $this->leaseHeld && $this->state instanceof BindingState ) {
			BindingFenceCoordinator::releasePersistentBindingState( $this->wpdb, $this->state, $this->claim );
		}
		if ( $release ) {
			$this->state     = null;
			$this->claim     = null;
			$this->leaseHeld = false;
		}
	}
	private function diagnose( string $code, mixed $return ): mixed {
		if ( count( $this->diagnostics ) === self::MAX_DIAGNOSTICS ) {
			array_shift( $this->diagnostics );
		} $this->diagnostics[]        = $code;
		$this->status['failure_code'] = 'update_completed' === $code ? null : $code;
		return $return; }
	/** @return array{candidate_header_version:null,candidate_tag:null,candidate_validation_code:null,candidate_version:null,failure_code:null,installed_version:null,last_check:null,offered_release_identity:null,offered_version:null,relationship:null} */ private static function emptyStatus(): array {
		return array(
			'candidate_header_version'  => null,
			'candidate_tag'             => null,
			'candidate_validation_code' => null,
			'candidate_version'         => null,
			'failure_code'              => null,
			'installed_version'         => null,
			'last_check'                => null,
			'offered_release_identity'  => null,
			'offered_version'           => null,
			'relationship'              => null,
		); }
	private function failure( string $code ): mixed {
		$this->diagnose( $code, null );
		return class_exists( '\\WP_Error' ) ? new \WP_Error( 'ran_wp_release_updater_' . $code, 'The update operation was not admitted.' ) : false; }
	private function directFilesystem(): bool {
		return function_exists( 'get_filesystem_method' ) && 'direct' === get_filesystem_method(); }
	private static function validNativeIdentity( mixed $type, mixed $identity ): bool {
		if ( ! is_string( $identity ) ) {
			return false;
		} if ( 'theme' === $type ) {
			return 1 === preg_match( '/\A[A-Za-z0-9][A-Za-z0-9._-]{0,99}\z/D', $identity );
		} return 'plugin' === $type && 1 === preg_match( '/\A[A-Za-z0-9][A-Za-z0-9._-]{0,99}\/[A-Za-z0-9][A-Za-z0-9._-]{0,99}\.php\z/D', $identity ); }
	/** @param array<string,mixed> $value @param list<string> $keys */ private static function exactKeys( mixed $value, array $keys ): bool {
		if ( ! is_array( $value ) || count( $value ) !== count( $keys ) ) {
			return false;
		} foreach ( $keys as $key ) {
			if ( ! array_key_exists( $key, $value ) ) {
				return false;
			}
		} return true; }
}
