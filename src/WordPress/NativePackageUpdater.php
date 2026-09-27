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
	private bool $lease_held                            = false;
	private bool $queued_multi_run                      = false;
	private bool $shutdown_scheduled                    = false;
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
	private ?array $discovery_snapshot = null;
	private int $discovery_epoch       = 0;
	private OwnedArchiveStore $archive_store;
	private StagedPackageManifest $manifest_builder;
	private PendingInstallState $pending_install;

	/**
	 * @param array<string,string> $headers
	 * @param array<string,mixed> $archive_policy
	 */
	private function __construct(
		private string $target_type,
		private string $installed_identity,
		private string $update_uri,
		private string $policy,
		private array $headers,
		private BindingRecord $binding,
		private ReleaseAdapter $adapter,
		private object $wpdb,
		private array $archive_policy,
		private PackageIdentityValidator $validator,
		private ?SelectedRuntimeState $selected_runtime_state = null,
		private bool $native_discovery_reuse = false
	) {
		$this->archive_store    = new OwnedArchiveStore();
		$this->manifest_builder = new StagedPackageManifest();
		$this->pending_install  = new PendingInstallState();
	}

	/**
	 * @param array<string,mixed> $configuration
	 * @param array<string,mixed> $archive_policy
	 */
	public static function from_configuration(
		array $configuration,
		BindingRecord $binding,
		ReleaseAdapter $adapter,
		object $wpdb,
		array $archive_policy,
		?PackageIdentityValidator $validator = null,
		?SelectedRuntimeState $selected_runtime_state = null,
		bool $native_discovery_reuse = false
	): ?self {
		$binding_facts = $binding->to_array();
		if (
			! self::valid_configuration( $configuration, $binding )
			|| $configuration['policy'] !== $binding_facts['update_policy']
			|| ! array_key_exists( 'theme_template', $archive_policy )
			|| ! is_string( $archive_policy['theme_template'] )
			|| ! hash_equals( $binding_facts['theme_template'], $archive_policy['theme_template'] )
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
			$archive_policy,
			$validator ?? new PackageIdentityValidator(),
			$selected_runtime_state,
			$native_discovery_reuse
		);
	}

	public function register(): void {
		if ( $this->registered ) {
			return;
		}

		$host = parse_url( $this->update_uri, PHP_URL_HOST );
		if ( ! is_string( $host ) || '' === $host ) {
			return;
		}

		add_filter( ( 'plugin' === $this->target_type ? 'update_plugins_' : 'update_themes_' ) . $host, array( $this, 'filter_update' ), 10, 4 );
		if ( 'plugin' === $this->target_type ) {
			add_filter( 'plugins_api', array( $this, 'filter_plugin_information' ), 10, 3 );
		}
		add_filter( 'plugin' === $this->target_type ? 'auto_update_plugin' : 'auto_update_theme', array( $this, 'filter_auto_update' ), 10, 2 );
		add_filter( 'upgrader_package_options', array( $this, 'capture_package_options' ), PHP_INT_MAX, 1 );
		add_filter( 'upgrader_pre_download', array( $this, 'filter_pre_download' ), PHP_INT_MAX, 4 );
		add_filter( 'upgrader_pre_install', array( $this, 'filter_pre_install' ), 1, 2 );
		add_filter( 'pre_unzip_file', array( $this, 'filter_pre_unzip_file' ), PHP_INT_MAX, 5 );
		add_filter( 'upgrader_source_selection', array( $this, 'filter_source_selection' ), PHP_INT_MAX, 4 );
		add_filter( 'upgrader_install_package_result', array( $this, 'capture_install_package_result' ), PHP_INT_MAX, 2 );
		add_action( 'upgrader_process_complete', array( $this, 'observe_completion' ), 10, 2 );
		$this->registered = true;
	}

	/**
	 * @param array<string,mixed>|false $update
	 * @param array<string,mixed> $package_data
	 * @param list<string> $locales
	 */
	public function filter_update( mixed $update, array $package_data, string $package_identity, array $locales ): mixed {
		unset( $locales );
		if ( ! hash_equals( $this->installed_identity, $package_identity ) || ! $this->live() ) {
			return $update;
		}
		$this->begin_operation();
		$installed                         = is_string( $package_data['Version'] ?? null ) ? $package_data['Version'] : '';
		$this->status                      = self::empty_status();
		$this->status['installed_version'] = $installed;
		$this->status['last_check']        = time();
		$runtime_uri                       = is_string( $package_data['UpdateURI'] ?? null ) ? $package_data['UpdateURI'] : '';
		if ( null === ReleaseVersion::normalize_header( $installed ) || ! $this->matches_runtime_uri( $runtime_uri ) ) {
			return $this->diagnose( 'runtime_package_identity_invalid', $update );
		}
		if ( in_array( $this->policy, array( 'disabled', 'forced-off' ), true ) ) {
			return false;
		}
		if ( ! $this->direct_filesystem() || ! $this->claim_discovery() ) {
			return $update;
		}
		$descriptor = $this->discover( $installed );
		if ( ! $descriptor instanceof IdentityDescriptor || ! $this->manual_eligible( $descriptor ) ) {
			return $update;
		}
		$token = $this->token( $descriptor );
		if ( null === $token ) {
			return $update;
		}
		$facts                                    = $descriptor->to_array();
		$this->status['offered_version']          = $facts['version'];
		$this->status['offered_release_identity'] = $descriptor->release_identity();
		$offer                                    = array(
			'id'                                  => $this->update_uri,
			'slug'                                => $this->information_slug(),
			'url'                                 => $this->update_uri,
			'package'                             => $token,
			'requires'                            => $this->headers['RequiresWP'],
			'requires_php'                        => $this->headers['RequiresPHP'],
			'version'                             => $facts['version'],
			'autoupdate'                          => $this->automatic_eligible( $descriptor ),
			'ran_wp_release_updater_binding_hash' => $this->binding->binding_hash(),
		);
		if ( 'plugin' === $this->target_type ) {
			$offer['plugin'] = $this->installed_identity;
		} else {
			$offer['theme'] = $this->installed_identity;
		}
		return $offer;
	}

	public function filter_plugin_information( mixed $result, string $action, mixed $arguments ): mixed {
		if ( 'plugin_information' !== $action || ! is_object( $arguments ) || $this->information_slug() !== ( $arguments->slug ?? null ) || ! $this->live() ) {
			return $result;
		}
		$this->begin_operation();
		if ( ! $this->direct_filesystem() || ! $this->claim_discovery() ) {
			return $result;
		}
		$descriptor = $this->discover( $this->headers['Version'] );
		if ( ! $descriptor instanceof IdentityDescriptor || ! $this->manual_eligible( $descriptor ) ) {
			return $result;
		}
		$facts               = $descriptor->to_array();
		$info                = new \stdClass();
		$info->name          = $this->headers['Name'];
		$info->slug          = $this->information_slug();
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

	public function filter_auto_update( ?bool $update, mixed $item ): ?bool {
		if ( ! is_object( $item ) || ! $this->matches_item_identity( $item ) || ! $this->live() ) {
			return $update;
		}
		$this->begin_operation();
		$descriptor = $this->parse_token( is_string( $item->package ?? null ) ? $item->package : '' );
		return $this->direct_filesystem() && $descriptor instanceof IdentityDescriptor && $this->newer_than_installed( $descriptor ) && $this->automatic_eligible( $descriptor );
	}

	public function capture_package_options( mixed $options ): mixed {
		if ( ! $this->live() ) {
			return $options;
		}
		$extra = is_array( $options ) && is_array( $options['hook_extra'] ?? null )
			? $options['hook_extra']
			: null;
		if ( is_array( $extra ) && $this->matches_operation( $extra ) ) {
			$this->begin_operation();
			$this->queued_multi_run = true === ( $options['is_multi'] ?? false );
		}
		return $options;
	}
	/** @param array<string,mixed> $hook_extra */
	public function filter_pre_download( mixed $reply, string $package, mixed $upgrader, array $hook_extra ): mixed {
		unset( $upgrader );
		if ( ! $this->live() || ! $this->matches_target_identity( $hook_extra ) ) {
			return $reply;
		}
		$this->clear_discovery_snapshot();
		if ( ! $this->matches_operation( $hook_extra ) ) {
			return false !== $reply && ! $reply instanceof \WP_Error ? $this->failure( 'unverified_pre_download_result' ) : $reply;
		}
		$queued_multi_run       = $this->queued_multi_run;
		$this->queued_multi_run = false;
		if ( $reply instanceof \WP_Error ) {
			return $reply;
		}
		if ( false !== $reply ) {
			return $this->failure( 'unverified_pre_download_result' );
		}
		$this->clear_pending( false );
		$token = $this->parse_token( $package );
		if ( ! $this->direct_filesystem() || ! $token instanceof IdentityDescriptor || ! $this->newer_than_installed( $token ) || ! $this->manual_eligible( $token ) ) {
			return $this->failure( 'unverified_pre_download' );
		}
		if ( ! $this->claim_discovery() ) {
			return $this->failure( 'binding_fence_lost' );
		}
		try {
			$fresh = $this->adapter->inspect( $token->release_identity(), $token->to_array()['tag'] );
			BindingRecord::assert_descriptor_binding( $fresh, $this->binding );
		} catch ( \Throwable ) {
			return $this->failure( 'acquisition_failed' );
		}
		if ( ! $this->newer_than_installed( $fresh ) ) {
			return $this->failure( 'unverified_pre_download' );
		}
		if ( ! hash_equals( $token->fingerprint_value(), $fresh->fingerprint_value() ) ) {
			return $this->failure( 'remote_release_changed' );
		}
		if ( ! $this->state instanceof BindingState ) {
			return $this->failure( 'binding_fence_lost' );
		}
		$this->descriptor = $fresh;
		$renewed          = BindingFenceCoordinator::renew_persistent_binding_state( $this->wpdb, $this->state, $this->claim, 3600 );
		if ( 'renewed' !== $renewed['result'] || ! $renewed['current'] instanceof BindingState ) {
			return $this->failure( 'binding_fence_lost' );
		}
		$this->state = $renewed['current'];
		$this->claim = $this->claim( $this->state );
		try {
			$artifact = $this->adapter->acquire( $fresh );
			$owned    = $artifact->inspect( fn( string $path ): ?array => $this->validated_copy( $path, $fresh ) );
		} catch ( \Throwable ) {
			return $this->failure( 'acquisition_failed' );
		}
		if ( ! is_array( $owned ) ) {
			return $this->failure( 'acquisition_identity_invalid' );
		}
		$identity = $this->archive_store->identity( $owned['path'], $fresh );
		if ( null === $identity ) {
			$this->archive_store->remove( $owned['path'], $owned['directory'] );
			return $this->failure( 'acquisition_identity_invalid' );
		}
		$verified = $this->verify_current();
		if ( null === $verified ) {
			$this->archive_store->remove( $owned['path'], $owned['directory'] );
			return $this->failure( 'binding_fence_lost' );
		}
		try {
			$proof   = $this->validator->validate( $fresh, $this->archive_policy, $owned['path'] );
			$receipt = AcquisitionReceipt::issue( $verified['current'], $fresh, $this->validator, $proof, $verified['now'] );
		} catch ( \Throwable ) {
			$this->archive_store->remove( $owned['path'], $owned['directory'] );
			return $this->failure( 'acquisition_identity_invalid' );
		}
		$this->state = $verified['current'];
		$this->pending_install->begin( $owned['path'], $owned['directory'], $identity, $receipt, multi_run: $queued_multi_run );
		$this->schedule_finalization();
		return $owned['path'];
	}

	/** @param list<string> $needed_dirs */
	public function filter_pre_unzip_file( mixed $pre, string $file, string $destination, array $needed_dirs, float $required_space ): mixed {
		unset( $destination, $needed_dirs, $required_space );
		if ( ! $this->pending_install->active() || ! is_string( $this->pending_install->archive() ) || ! hash_equals( $this->pending_install->archive(), $file ) ) {
			return $pre;
		}
		if ( ! $this->live() ) {
			$this->clear_pending();
			return $this->failure( 'runtime_liveness_lost' );
		}
		$verified = $this->verify_current();
		if (
			! $this->direct_filesystem()
			|| null === $verified
			|| ! $this->descriptor instanceof IdentityDescriptor
			|| ! is_array( $this->pending_install->archive_identity() )
			|| ! $this->archive_store->same_identity( $file, $this->descriptor, $this->pending_install->archive_identity() )
			|| ! $this->pending_install->receipt() instanceof AcquisitionReceipt
		) {
			$this->clear_pending();
			return $this->failure( 'archive_changed_before_extraction' );
		}
		try {
			AcquisitionReceipt::assert_fresh( $this->pending_install->receipt(), $verified['current'], $this->descriptor, $verified['now'] );
		} catch ( InvalidArgumentException ) {
			$this->clear_pending();
			return $this->failure( 'archive_changed_before_extraction' );
		}
		$this->state = $verified['current'];
		if ( ! $this->pending_install->admit_extraction() ) {
			$this->clear_pending();
			return $this->failure( 'archive_changed_before_extraction' );
		}
		return $pre;
	}

	/** @param array<string,mixed> $hook_extra */
	public function filter_source_selection( mixed $source, string $remote_source, mixed $upgrader, array $hook_extra ): mixed {
		unset( $remote_source, $upgrader );
		if ( ! $this->matches_operation( $hook_extra ) ) {
			return $source;
		}
		if ( ! $this->live() ) {
			if ( ! $this->pending_install->active() ) {
				return $source;
			}
			$this->clear_pending();
			return $this->failure( 'runtime_liveness_lost' );
		}

		$manifest = is_string( $source ) && $this->matches_staged_metadata( $source )
			? $this->manifest_builder->build( $source )
			: null;
		$verified = $this->verify_current();
		if (
			! $this->direct_filesystem()
			|| ! $this->pending_install->active()
			|| ! $this->pending_install->extraction_admitted()
			|| null === $verified
			|| ! $this->descriptor instanceof IdentityDescriptor
			|| ! $this->pending_install->receipt() instanceof AcquisitionReceipt
			|| ! is_string( $source )
			|| ! is_array( $manifest )
		) {
			$this->clear_pending();
			return $this->failure( 'staged_package_identity_invalid' );
		}

		try {
			AcquisitionReceipt::assert_archive_manifest(
				$this->pending_install->receipt(),
				$verified['current'],
				$this->descriptor,
				$verified['now'],
				$this->manifest_builder->hash( $manifest ),
				count( $manifest ),
				$this->manifest_builder->expanded_bytes( $manifest )
			);
		} catch ( InvalidArgumentException ) {
			$this->clear_pending();
			return $this->failure( 'staged_package_identity_invalid' );
		}

		$this->state = $verified['current'];
		if ( ! $this->pending_install->stage_manifest( $manifest ) ) {
			$this->clear_pending();
			return $this->failure( 'staged_package_identity_invalid' );
		}
		return $source;
	}
	/** @param array<string,mixed> $hook_extra */
	public function filter_pre_install( mixed $response, array $hook_extra ): mixed {
		if ( ! $this->matches_operation( $hook_extra ) ) {
			return $response;
		}
		if ( ! $this->live() ) {
			if ( ! $this->pending_install->active() ) {
				return $response;
			}
			$this->clear_pending();
			return $this->failure( 'runtime_liveness_lost' );
		}
		$verified = $this->verify_current();
		if (
			! $this->direct_filesystem()
			|| ! $this->pending_install->active()
			|| ! $this->pending_install->extraction_admitted()
			|| null === $verified
			|| ! $this->descriptor instanceof IdentityDescriptor
			|| ! $this->pending_install->receipt() instanceof AcquisitionReceipt
			|| $response instanceof \WP_Error
		) {
			$this->clear_pending();
			return $this->failure( 'unverified_pre_install' );
		}
		try {
			AcquisitionReceipt::assert_fresh( $this->pending_install->receipt(), $verified['current'], $this->descriptor, $verified['now'] );
		} catch ( InvalidArgumentException ) {
			$this->clear_pending();
			return $this->failure( 'unverified_pre_install' );
		}
		$this->state = $verified['current'];
		return $response;
	}
	/** @param array<string,mixed> $hook_extra */
	public function capture_install_package_result( mixed $result, array $hook_extra ): mixed {
		if ( ! $this->matches_operation( $hook_extra ) ) {
			return $result;
		}
		if ( ! $this->live() ) {
			if ( ! $this->pending_install->active() ) {
				return $result;
			}
			$this->clear_pending();
			return $this->failure( 'runtime_liveness_lost' );
		}
		if ( ! $this->pending_install->active() || $result instanceof \WP_Error || false === $result ) {
			$this->clear_pending();
			return $this->failure( 'unverified_install_result' );
		}
		if ( ! $this->pending_install->capture_install_result( $result ) ) {
			$this->clear_pending();
			return $this->failure( 'unverified_install_result' );
		}
		return $result;
	}

	/** @param array<string,mixed> $hook_extra */
	public function observe_completion( mixed $upgrader, array $hook_extra ): void {
		unset( $upgrader );
		if ( $this->live() && $this->matches_completion( $hook_extra ) ) {
			$this->clear_discovery_snapshot();
			$this->pending_install->observe_completion();
		}
	}
	/** Finalization is deliberately after Core rollback and backup cleanup. */
	public function finalize_pending_install(): void {
		if ( ! $this->live() ) {
			if ( $this->pending_install->active() ) {
				$this->diagnose( 'runtime_liveness_lost', null );
			}
			$this->clear_pending();
			return;
		}
		if ( ! $this->pending_install->active() ) {
			$this->clear_pending();
			return;
		}

		try {
			if ( ! $this->descriptor instanceof IdentityDescriptor ) {
				$this->diagnose( 'outcome_uncertain', null );
				return;
			}
			$destination               = is_array( $this->pending_install->install_result() ) && is_string( $this->pending_install->install_result()['destination'] ?? null )
				? $this->pending_install->install_result()['destination']
				: null;
			$manifest                  = is_string( $destination ) ? $this->manifest_builder->build( $destination ) : null;
			$verified                  = $this->verify_current();
			$archive_manifest_verified = false;
			if (
				null !== $verified
				&& $this->pending_install->receipt() instanceof AcquisitionReceipt
				&& is_array( $manifest )
			) {
				try {
					AcquisitionReceipt::assert_archive_manifest(
						$this->pending_install->receipt(),
						$verified['current'],
						$this->descriptor,
						$verified['now'],
						$this->manifest_builder->hash( $manifest ),
						count( $manifest ),
						$this->manifest_builder->expanded_bytes( $manifest )
					);
					$archive_manifest_verified = true;
					$this->state               = $verified['current'];
				} catch ( InvalidArgumentException ) {
					$archive_manifest_verified = false;
				}
			}
			if (
				! $this->pending_install->install_result_captured()
				|| ( ! $this->pending_install->completion_observed() && ! $this->pending_install->multi_run() )
				|| ! $archive_manifest_verified
				|| ! is_string( $destination )
				|| ! is_array( $this->pending_install->staged_manifest() )
				|| ! is_array( $manifest )
				|| ! hash_equals( $this->manifest_builder->hash( $this->pending_install->staged_manifest() ), $this->manifest_builder->hash( $manifest ) )
				|| ! $this->matches_staged_metadata( $destination )
			) {
				$this->diagnose( 'outcome_uncertain', null );
				return;
			}
			$completed = BindingFenceCoordinator::complete_persistent_install( $this->wpdb, $this->state, $this->claim, $this->pending_install->receipt(), $this->descriptor );
			$this->diagnose( 'completed' === $completed['result'] ? 'update_completed' : 'outcome_uncertain', null );
		} finally {
			$this->clear_pending();
		}
	}
	/** @return list<string> */ public function diagnostics(): array {
		return $this->diagnostics; }
	/** @return array{candidate_header_version:string|null,candidate_tag:string|null,candidate_validation_code:string|null,candidate_version:string|null,failure_code:string|null,installed_version:string|null,last_check:int|null,offered_release_identity:string|null,offered_version:string|null,relationship:string|null} */ public function status(): array {
		return $this->status; }
	public function refresh(): bool {
		if ( ! $this->live() ) {
			$this->clear_pending();
			return false;
		}
		$this->diagnostics = array();
		$this->status      = self::empty_status();
		$this->clear_discovery_snapshot();
		$this->queued_multi_run = false;
		$this->clear_pending();
		return true;
	}

	private function live(): bool {
		$live = ! $this->selected_runtime_state instanceof SelectedRuntimeState || $this->selected_runtime_state->broker_is_live();
		if ( ! $live ) {
			$this->clear_discovery_snapshot();
		}
		return $live;
	}

	private function begin_operation(): void {
		if ( $this->selected_runtime_state instanceof SelectedRuntimeState ) {
			$this->selected_runtime_state->begin_operation( $this->target_type );
		}
	}

	/** @param array<string,mixed> $configuration */
	private static function valid_configuration( array $configuration, BindingRecord $binding ): bool {
		if ( ! self::exact_keys( $configuration, self::CONFIGURATION_KEYS ) || ! is_array( $configuration['headers'] ) || ! self::exact_keys( $configuration['headers'], self::HEADER_KEYS ) || ! is_string( $configuration['update_uri'] ) || ! in_array( $configuration['policy'], array( 'disabled', 'forced-off', 'manual', 'automatic' ), true ) || ! self::valid_native_identity( $configuration['target_type'], $configuration['installed_package_identity'] ) ) {
			return false;
		}
		foreach ( $configuration['headers'] as $header ) {
			if ( ! is_string( $header ) || strlen( $header ) > 8192 ) {
				return false;
			}
		}
		$facts = $binding->to_array();
		if ( $configuration['target_type'] !== $facts['target_type'] || ! hash_equals( $configuration['installed_package_identity'], $facts['installed_package_identity'] ) || null === ReleaseVersion::normalize_header( $configuration['headers']['Version'] ) ) {
			return false;
		}
		return CanonicalUpdateUri::canonicalize_boundaries(
			array(
				'archive_preflight' => $facts['canonical_update_uri'],
				'configuration'     => $configuration['update_uri'],
				'offer'             => $configuration['headers']['UpdateURI'],
				'staged_package'    => $facts['canonical_update_uri'],
			)
		) === $facts['canonical_update_uri'];
	}

	private function matches_runtime_uri( string $runtime_uri ): bool {
		return CanonicalUpdateUri::canonicalize_boundaries(
			array(
				'archive_preflight' => $this->binding->to_array()['canonical_update_uri'],
				'configuration'     => $this->update_uri,
				'offer'             => $runtime_uri,
				'staged_package'    => $this->headers['UpdateURI'],
			)
		) === $this->update_uri; }
	private function manual_eligible( IdentityDescriptor $descriptor ): bool {
		$facts = $descriptor->to_array()['assurance_facts'];
		foreach ( array( 'exact_artifact_identity', 'exact_commit_identity', 'exact_reacquisition_supported', 'exact_release_identity', 'repository_identity_stable', 'trusted_digest_source' ) as $fact ) {
			if ( true !== $facts[ $fact ] ) {
				return false;
			}
		} return true; }
	private function automatic_eligible( IdentityDescriptor $descriptor ): bool {
		$facts = $descriptor->to_array()['assurance_facts'];
		return 'automatic' === $this->policy && $this->manual_eligible( $descriptor ) && true === $facts['publication_immutable'] && true === $facts['provenance_verified']; }
	private function newer_than_installed( IdentityDescriptor $descriptor ): bool {
		return ReleaseVersion::RELATIONSHIP_NEWER === ReleaseVersion::relationship( $descriptor->to_array()['version'], $this->headers['Version'] ); }
	private function information_slug(): string {
		return 'ran-wp-release-updater-' . substr( hash( 'sha256', $this->target_type . "\0" . $this->installed_identity ), 0, 24 ); }
	private function matches_item_identity( object $item ): bool {
		$identity = 'plugin' === $this->target_type ? ( $item->plugin ?? null ) : ( $item->theme ?? null );
		return is_string( $identity ) && hash_equals( $this->installed_identity, $identity ); }
	/** @param array<string,mixed> $extra */ private function matches_operation( array $extra ): bool {
		$identity = 'plugin' === $this->target_type ? ( $extra['plugin'] ?? null ) : ( $extra['theme'] ?? null );
		if ( ! is_string( $identity ) || ! hash_equals( $this->installed_identity, $identity ) ) {
			return false;
		} return ( ! array_key_exists( 'action', $extra ) || 'update' === $extra['action'] ) && ( ! array_key_exists( 'type', $extra ) || $this->target_type === $extra['type'] ); }
	/** @param array<string,mixed> $extra */ private function matches_target_identity( array $extra ): bool {
		$identity = 'plugin' === $this->target_type ? ( $extra['plugin'] ?? null ) : ( $extra['theme'] ?? null );
		return is_string( $identity ) && hash_equals( $this->installed_identity, $identity ); }
	/** @param array<string,mixed> $extra */ private function matches_completion( array $extra ): bool {
		if ( 'update' !== ( $extra['action'] ?? null ) || ( $extra['type'] ?? null ) !== $this->target_type ) {
			return false;
		} $key   = 'plugin' === $this->target_type ? 'plugins' : 'themes';
		$single  = 'plugin' === $this->target_type ? 'plugin' : 'theme';
		$targets = $extra[ $key ] ?? array( $extra[ $single ] ?? null );
		return is_array( $targets ) && in_array( $this->installed_identity, $targets, true ); }
	private function matches_staged_metadata( string $source, ?string $expected_version = null ): bool {
		$source    = rtrim( $source, '/\\' );
		$directory = @lstat( $source );
		if (
			! is_array( $directory )
			|| 0040000 !== ( $directory['mode'] & 0170000 )
			|| ! hash_equals( $this->package_root(), basename( $source ) )
		) {
			return false;
		}

		$header = 'plugin' === $this->target_type ? basename( $this->installed_identity ) : 'style.css';
		$path   = $source . DIRECTORY_SEPARATOR . $header;
		$file   = @lstat( $path );
		if ( ! is_array( $file ) || 0100000 !== ( $file['mode'] & 0170000 ) ) {
			return false;
		}

		$contents = @file_get_contents( $path, false, null, 0, 8192 );
		if ( ! is_string( $contents ) ) {
			return false;
		}

		$parsed = PackageIdentityValidator::parse_header( $contents, $this->target_type );
		if ( 'installed_header_verified' !== $parsed['code'] || ! isset( $parsed['headers'] ) ) {
			return false;
		}

		$headers            = $parsed['headers'];
		$expected_version ??= $this->descriptor instanceof IdentityDescriptor
			? $this->descriptor->to_array()['version']
			: null;

		return hash_equals( $this->headers['Name'], $headers['Name'] )
			&& hash_equals( $this->binding->to_array()['theme_template'], $headers['Template'] )
			&& is_string( $expected_version )
			&& 0 === ReleaseVersion::compare( $headers['Version'], $expected_version )
			&& $this->matches_runtime_uri( $headers['UpdateURI'] );
	}
	private function package_root(): string {
		return 'plugin' === $this->target_type ? dirname( $this->installed_identity ) : $this->installed_identity; }
	private function schedule_finalization(): void {
		if ( $this->shutdown_scheduled ) {
			return;
		}

		add_action( 'shutdown', array( $this, 'finalize_pending_install' ), PHP_INT_MAX, 0 );
		$this->shutdown_scheduled = true;
	}
	private function claim_discovery(): bool {
		if ( $this->lease_held && null !== $this->verify_current() ) {
			return true;
		}
		$this->clear_discovery_snapshot();
		$this->lease_held = false;
		$this->state      = null;
		$this->claim      = null;
		try {
			$owner = bin2hex( random_bytes( 32 ) );
		} catch ( \Throwable ) {
			return false; }
		$claimed = BindingFenceCoordinator::claim_persistent_binding_state( $this->wpdb, $this->binding, $owner, 600 );
		if ( 'claimed' !== $claimed['result'] || ! $claimed['current'] instanceof BindingState ) {
			return false;
		}
		$this->state      = $claimed['current'];
		$this->claim      = $this->claim( $this->state );
		$this->lease_held = true;
		$this->schedule_finalization();
		return true;
	}
	/** claimDiscovery() has established the current binding fence before discovery. */
	private function discover( string $installed ): ?IdentityDescriptor {
		$normalized_installed = ReleaseVersion::normalize_header( $installed );
		if ( ! is_string( $normalized_installed ) ) {
			return null;
		}
		$reused = $this->reusable_discovery( $normalized_installed );
		if ( $reused instanceof IdentityDescriptor ) {
			return $reused;
		}
		$this->clear_discovery_snapshot();
		$discovery_claim = $this->claim;
		$discovery_epoch = $this->discovery_epoch;
		try {
			$listed     = $this->adapter->list_releases();
			$candidates = $listed['candidates'] ?? null;
		} catch ( \Throwable ) {
			$this->status['candidate_validation_code'] = 'release_list_failed';
			return null;
		}
		$rate_limit = $listed['rate_limit'] ?? null;
		if ( is_array( $rate_limit ) && true === ( $rate_limit['limited'] ?? null ) ) {
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
				BindingRecord::assert_descriptor_binding( $descriptor, $this->binding );
			} catch ( ReleaseFailure $failure ) {
				$this->status['candidate_validation_code'] = 'candidate_inspection_failed';
				if ( self::can_reject_candidate( $failure ) ) {
					continue;
				}
				return null;
			} catch ( \Throwable ) {
				$this->status['candidate_validation_code'] = 'candidate_inspection_failed';
				return null;
			}
			$facts = $descriptor->to_array();
			if ( ! hash_equals( $candidate['release_identity'], $facts['release_identity'] ) || ! hash_equals( $candidate['tag'], $facts['tag'] ) || 0 !== ReleaseVersion::compare( $candidate['version'], $facts['version'] ) ) {
				$this->status['candidate_validation_code'] = 'candidate_descriptor_mismatch';
				return null; }
			$artifact = null;
			try {
				$artifact = $this->adapter->acquire( $descriptor );
				$valid    = $artifact->inspect( fn( string $path ) => $this->validator->validate( $descriptor, $this->archive_policy, $path ) );
			} catch ( ReleaseFailure $failure ) {
				$this->status['candidate_validation_code'] = 'candidate_validation_failed';
				if ( $artifact instanceof \RAN\WPReleaseUpdater\V1\Archive\TemporaryArtifact && ! $artifact->discard() ) {
					return null;
				}
				if ( self::can_reject_candidate( $failure ) ) {
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
			if ( ! $valid->is_valid() ) {
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
			if ( $this->native_discovery_reuse && is_array( $discovery_claim ) && null !== $this->verify_current() && $this->live() && $this->discovery_epoch === $discovery_epoch && $this->claim === $discovery_claim ) {
				$this->discovery_snapshot = array(
					'claim'      => $discovery_claim,
					'descriptor' => $descriptor,
					'installed'  => $normalized_installed,
				);
			}
			return $descriptor;
		}
		return null;
	}
	private static function can_reject_candidate( ReleaseFailure $failure ): bool {
		return in_array( $failure->release_code, array( 'release_unavailable', 'package_incompatible' ), true )
			&& in_array( $failure->cleanup_status, array( 'not_applicable', 'complete' ), true );
	}
	private function reusable_discovery( string $installed ): ?IdentityDescriptor {
		if ( ! $this->native_discovery_reuse || ! is_array( $this->discovery_snapshot ) || ! is_array( $this->claim ) || $this->discovery_snapshot['installed'] !== $installed || $this->discovery_snapshot['claim'] !== $this->claim ) {
			return null;
		}
		$descriptor                                = $this->discovery_snapshot['descriptor'];
		$facts                                     = $descriptor->to_array();
		$this->status['candidate_tag']             = $facts['tag'];
		$this->status['candidate_version']         = $facts['version'];
		$this->status['candidate_header_version']  = $facts['version'];
		$this->status['candidate_validation_code'] = 'archive_identity_verified';
		$this->status['relationship']              = ReleaseVersion::relationship( $facts['version'], $installed );
		$this->descriptor                          = $descriptor;
		return $descriptor;
	}
	private function clear_discovery_snapshot(): void {
		++$this->discovery_epoch;
		$this->discovery_snapshot                 = null;
		$this->status['offered_release_identity'] = null;
		$this->status['offered_version']          = null; }
	private function token( IdentityDescriptor $descriptor ): ?string {
		$value = array(
			'binding_hash' => $this->binding->binding_hash(),
			'descriptor'   => $descriptor->to_array(),
			'schema'       => 1,
		);
		try {
			$json = json_encode( $value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES );
		} catch ( \JsonException ) {
			return null; }
		$token = 'ran-wp-release-updater:v1:' . rtrim( strtr( base64_encode( $json ), '+/', '-_' ), '=' );
		return strlen( $token ) <= 8192 ? $token : null;
	}
	private function parse_token( string $token ): ?IdentityDescriptor {
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
			if ( ! self::exact_keys( $value, array( 'binding_hash', 'descriptor', 'schema' ) ) || 1 !== $value['schema'] ) {
				return null;
			}
			if ( ! is_string( $value['binding_hash'] ) || ! hash_equals( $this->binding->binding_hash(), $value['binding_hash'] ) ) {
				return null;
			}
			$descriptor = IdentityDescriptor::rehydrate( $value['descriptor'] );
			BindingRecord::assert_descriptor_binding( $descriptor, $this->binding );
			return $descriptor;
		} catch ( \Throwable ) {
			return null; }
	}
	/** @return array{directory:string,path:string}|null */ private function validated_copy( string $path, IdentityDescriptor $descriptor ): ?array {
		try {
			$proof = $this->validator->validate( $descriptor, $this->archive_policy, $path );
		} catch ( \Throwable ) {
			return null;
		} return $proof->is_valid() ? $this->archive_store->copy( $path, $descriptor ) : null; }
	/** @return array<string,mixed> */ private function claim( BindingState $state ): array {
		return array(
			'binding_generation' => $state->binding_generation(),
			'binding_hash'       => $state->binding()->binding_hash(),
			'lease_deadline'     => $state->lease_deadline(),
			'owner_token'        => $state->owner_token(),
		); }
	/** @return array{current:BindingState,now:int}|null */ private function verify_current(): ?array {
		if ( ! $this->state instanceof BindingState ) {
			return null;
		} $verified = BindingFenceCoordinator::verify_persistent_binding_state( $this->wpdb, $this->state, $this->claim );
		return 'verified' === $verified['result'] && $verified['current'] instanceof BindingState && is_int( $verified['now'] ?? null ) ? array(
			'current' => $verified['current'],
			'now'     => $verified['now'],
		) : null; }
	private function clear_pending( bool $release = true ): void {
		$this->clear_discovery_snapshot();
		$this->archive_store->remove( $this->pending_install->archive(), $this->pending_install->archive_directory() );
		$this->pending_install->clear();
		if ( $release && $this->lease_held && $this->state instanceof BindingState ) {
			BindingFenceCoordinator::release_persistent_binding_state( $this->wpdb, $this->state, $this->claim );
		}
		if ( $release ) {
			$this->state      = null;
			$this->claim      = null;
			$this->lease_held = false;
		}
	}
	private function diagnose( string $code, mixed $return_value ): mixed {
		if ( count( $this->diagnostics ) === self::MAX_DIAGNOSTICS ) {
			array_shift( $this->diagnostics );
		} $this->diagnostics[]        = $code;
		$this->status['failure_code'] = 'update_completed' === $code ? null : $code;
		return $return_value; }
	/** @return array{candidate_header_version:null,candidate_tag:null,candidate_validation_code:null,candidate_version:null,failure_code:null,installed_version:null,last_check:null,offered_release_identity:null,offered_version:null,relationship:null} */ private static function empty_status(): array {
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
	private function direct_filesystem(): bool {
		return function_exists( 'get_filesystem_method' ) && 'direct' === get_filesystem_method(); }
	private static function valid_native_identity( mixed $type, mixed $identity ): bool {
		if ( ! is_string( $identity ) ) {
			return false;
		} if ( 'theme' === $type ) {
			return 1 === preg_match( '/\A[A-Za-z0-9][A-Za-z0-9._-]{0,99}\z/D', $identity );
		} return 'plugin' === $type && 1 === preg_match( '/\A[A-Za-z0-9][A-Za-z0-9._-]{0,99}\/[A-Za-z0-9][A-Za-z0-9._-]{0,99}\.php\z/D', $identity ); }
	/** @param list<string> $keys */ private static function exact_keys( mixed $value, array $keys ): bool {
		if ( ! is_array( $value ) || count( $value ) !== count( $keys ) ) {
			return false;
		} foreach ( $keys as $key ) {
			if ( ! array_key_exists( $key, $value ) ) {
				return false;
			}
		} return true; }
}
