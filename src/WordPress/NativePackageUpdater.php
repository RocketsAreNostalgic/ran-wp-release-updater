<?php

declare(strict_types=1);

namespace RAN\WPReleaseUpdater\V1\WordPress;

use InvalidArgumentException;
use RAN\WPReleaseUpdater\V1\Archive\PackageIdentityValidator;
use RAN\WPReleaseUpdater\V1\Archive\ValidatedPackage;
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
	private ?\Closure $operation_guard;
	private ?\Closure $native_mutation_begin;
	private ?\Closure $native_mutation_complete;
	private ?\Closure $descriptor_admission;
	private ?string $native_owner                  = null;
	private bool $native_beginning                 = false;
	private bool $finalizing                       = false;
	private string $native_outcome                 = 'uncertain';
	private ?\Theme_Upgrader $theme_error_upgrader = null;
	private ?\WP_Error $theme_error_original       = null;
	private ?\WP_Error $theme_error_outgoing       = null;


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
		private bool $native_discovery_reuse = false,
		?callable $operation_guard = null,
		?callable $native_mutation_begin = null,
		?callable $native_mutation_complete = null,
		?callable $descriptor_admission = null
	) {
		$this->archive_store            = new OwnedArchiveStore();
		$this->manifest_builder         = new StagedPackageManifest();
		$this->pending_install          = new PendingInstallState();
		$this->operation_guard          = null === $operation_guard ? null : \Closure::fromCallable( $operation_guard );
		$this->native_mutation_begin    = null === $native_mutation_begin ? null : \Closure::fromCallable( $native_mutation_begin );
		$this->native_mutation_complete = null === $native_mutation_complete ? null : \Closure::fromCallable( $native_mutation_complete );
		$this->descriptor_admission     = null === $descriptor_admission ? null : \Closure::fromCallable( $descriptor_admission );
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
		bool $native_discovery_reuse = false,
		?callable $operation_guard = null,
		?callable $native_mutation_begin = null,
		?callable $native_mutation_complete = null,
		?callable $descriptor_admission = null
	): ?self {
		if ( ( null === $native_mutation_begin ) !== ( null === $native_mutation_complete ) || ( null === $operation_guard && null !== $native_mutation_begin ) ) {
			return null;
		}
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
			$native_discovery_reuse,
			$operation_guard,
			$native_mutation_begin,
			$native_mutation_complete,
			$descriptor_admission
		);
	}

	public function register(): void {
		if ( $this->registered ) {
			return;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Native URL parsing preserves provider and bootstrap validation semantics independently of WordPress helpers.
		$host = parse_url( $this->update_uri, PHP_URL_HOST );
		if ( ! is_string( $host ) || '' === $host ) {
			return;
		}

		add_filter( ( 'plugin' === $this->target_type ? 'update_plugins_' : 'update_themes_' ) . $host, array( $this, 'filter_update' ), 10, 4 );
		if ( null !== $this->descriptor_admission ) {
			add_filter( 'site_transient_update_' . ( 'plugin' === $this->target_type ? 'plugins' : 'themes' ), array( $this, 'filter_cached_update' ), 10, 1 );
		}
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

	public function filter_cached_update( mixed $transient ): mixed {
		if ( null === $this->descriptor_admission || ! is_object( $transient ) || ! is_array( $transient->response ?? null ) || ! array_key_exists( $this->installed_identity, $transient->response ) ) {
			return $transient;
		}
		$entry      = $transient->response[ $this->installed_identity ];
		$package    = is_object( $entry ) ? ( $entry->package ?? null ) : ( is_array( $entry ) ? ( $entry['package'] ?? null ) : null );
		$descriptor = is_string( $package ) && str_starts_with( $package, 'ran-wp-release-updater:v1:' ) ? $this->parse_token( $package ) : null;
		if ( ! $descriptor instanceof IdentityDescriptor || ! $this->live() || ! $this->descriptor_admitted( $descriptor ) || ! $this->newer_than_installed( $descriptor ) ) {
			unset( $transient->response[ $this->installed_identity ] );
		}
		return $transient;
	}

	/**
	 * @param array<string,mixed>|false $update
	 * @param array<string,mixed> $package_data
	 * @param list<string> $locales
	 */
	public function filter_update( mixed $update, array $package_data, string $package_identity, array $locales ): mixed {
		unset( $locales );
		if ( ! hash_equals( $this->installed_identity, $package_identity ) ) {
			return $update;
		}
		if ( ! $this->live() ) {
			return null === $this->operation_guard ? $update : false;
		}
		$check_id = null;
		$reason   = 'unexpected_failure';
		$result   = $this->checked_update( $update, $package_data, $reason, $check_id );
		$this->complete_check_observation( $check_id, $reason );
		return null !== $this->operation_guard && ! $this->live() ? false : $result;
	}

	/** @param array<string,mixed> $package_data */
	private function checked_update( mixed $update, array $package_data, string &$reason, ?string &$check_id ): mixed {
		$this->begin_operation();
		$installed                         = is_string( $package_data['Version'] ?? null ) ? $package_data['Version'] : '';
		$this->status                      = self::empty_status();
		$this->status['installed_version'] = $installed;
		$this->status['last_check']        = time();
		$runtime_uri                       = is_string( $package_data['UpdateURI'] ?? null ) ? $package_data['UpdateURI'] : '';
		if ( null === ReleaseVersion::normalize_header( $installed ) || ! $this->matches_runtime_uri( $runtime_uri ) ) {
			$reason   = 'runtime_identity_invalid';
			$check_id = $this->start_check_observation();
			return $this->diagnose( 'runtime_package_identity_invalid', $update );
		}
		if ( in_array( $this->policy, array( 'disabled', 'forced-off' ), true ) ) {
			$reason   = 'disabled' === $this->policy ? 'policy_disabled' : 'policy_forced_off';
			$check_id = $this->start_check_observation();
			return false;
		}
		if ( ! $this->direct_filesystem() ) {
			$reason   = 'filesystem_unavailable';
			$check_id = $this->start_check_observation();
			return $update;
		}
		if ( ! $this->claim_discovery() ) {
			$reason   = 'discovery_not_admitted';
			$check_id = $this->start_check_observation();
			return $update;
		}
		$descriptor = $this->discover(
			$installed,
			$reason,
			function () use ( &$check_id ): void {
				$check_id = $this->start_check_observation();
			}
		);
		if ( ! $descriptor instanceof IdentityDescriptor ) {
			return 'candidate_admission_rejected' === $this->status['candidate_validation_code'] ? false : $update;
		}
		if ( ! $this->manual_eligible( $descriptor ) ) {
			$reason = 'candidate_not_eligible';
			return $update;
		}
		$token = $this->token( $descriptor );
		if ( null === $token ) {
			$reason = 'offer_token_unavailable';
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
		$reason = 'update_available';
		return $offer;
	}

	private function start_check_observation(): ?string {
		$facts = $this->binding->to_array();
		if ( ! function_exists( 'do_action' ) || ! function_exists( 'get_current_blog_id' ) ) {
			return null;
		}
		foreach ( array(
			'provider_code'              => 32,
			'stable_repository_identity' => 191,
			'installed_package_identity' => 255,
		) as $key => $limit ) {
			if ( ! is_string( $facts[ $key ] ) || strlen( $facts[ $key ] ) > $limit ) {
				return null;
			}
		}
		try {
			$blog_id = get_current_blog_id();
			if ( $blog_id < 1 ) {
				return null;
			}
			$check_id = bin2hex( random_bytes( 16 ) );
		} catch ( \Throwable ) {
			return null;
		}
		self::observe_check(
			'started',
			array(
				'check_id'            => $check_id,
				'network_id'          => $facts['network_id'],
				'blog_id'             => $blog_id,
				'target_type'         => $this->target_type,
				'installed_identity'  => $this->installed_identity,
				'provider_code'       => $facts['provider_code'],
				'repository_identity' => $facts['stable_repository_identity'],
				'channel'             => $facts['release_channel'],
				'binding_hash'        => $this->binding->binding_hash(),
			)
		);
		return $check_id;
	}

	private function complete_check_observation( ?string $check_id, string $reason ): void {
		if ( null === $check_id ) {
			return;
		}
		$payload = array(
			'check_id' => $check_id,
			'status'   => 'failed',
			'reason'   => $reason,
		);
		if ( in_array( $reason, array( 'update_available', 'no_candidates', 'no_newer_candidate' ), true ) ) {
			$payload['status'] = 'succeeded';
		} elseif ( in_array( $reason, array( 'candidate_rejected', 'candidate_not_eligible' ), true ) ) {
			$payload['status'] = 'rejected';
		} elseif ( in_array( $reason, array( 'policy_disabled', 'policy_forced_off', 'discovery_not_admitted' ), true ) ) {
			$payload['status'] = 'skipped';
		}
		foreach ( array( 'relationship', 'installed_version', 'candidate_version', 'offered_version', 'candidate_validation_code' ) as $key ) {
			$value           = $this->status[ $key ];
			$payload[ $key ] = is_string( $value ) && strlen( $value ) <= 64 ? $value : null;
		}
		foreach ( array( 'installed_version', 'candidate_version', 'offered_version' ) as $key ) {
			if ( is_string( $payload[ $key ] ) && null === ReleaseVersion::normalize_header( $payload[ $key ] ) ) {
				$payload[ $key ] = null;
			}
		}
		$validation_codes = array( 'release_list_failed', 'candidate_list_invalid', 'candidate_invalid', 'candidate_not_newer', 'candidate_inspection_failed', 'candidate_admission_rejected', 'candidate_descriptor_mismatch', 'candidate_validation_failed', 'archive_identity_verified' );
		if ( ! in_array( $payload['candidate_validation_code'], $validation_codes, true ) ) {
			$payload['candidate_validation_code'] = null;
		}
		self::observe_check( 'completed', $payload );
	}

	/**
	 * @param 'started'|'completed' $stage
	 * @param array<string,mixed> $payload
	 */
	private static function observe_check( string $stage, array $payload ): void {
		do_action( 'ran_wp_release_updater_check_' . $stage . '_v1', $payload );
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
		return $this->live() ? $info : false;
	}

	public function filter_auto_update( ?bool $update, mixed $item ): ?bool {
		if ( ! is_object( $item ) || ! $this->matches_item_identity( $item ) ) {
			return $update;
		}
		if ( ! $this->live() ) {
			return null === $this->operation_guard ? $update : false;
		}
		$this->begin_operation();
		$descriptor = $this->parse_token( is_string( $item->package ?? null ) ? $item->package : '' );
		return $this->direct_filesystem() && $descriptor instanceof IdentityDescriptor && $this->descriptor_admitted( $descriptor ) && $this->newer_than_installed( $descriptor ) && $this->automatic_eligible( $descriptor ) && $this->live();
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
		if ( ! $this->matches_target_identity( $hook_extra ) ) {
			return $reply;
		}
		if ( $this->native_beginning || null !== $this->native_owner || ! $this->live() ) {
			return null === $this->operation_guard ? $reply : $this->failure( 'runtime_liveness_lost' );
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
		if ( ! $this->direct_filesystem() || ! $token instanceof IdentityDescriptor || ! $this->descriptor_admitted( $token ) || ! $this->newer_than_installed( $token ) || ! $this->manual_eligible( $token ) ) {
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
		if ( ! $this->live() || ! $this->descriptor_admitted( $fresh ) || ! $this->newer_than_installed( $fresh ) ) {
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
		$owned       = null;
		$artifact    = null;
		try {
			$artifact = $this->adapter->acquire( $fresh );
			$artifact->inspect(
				function ( string $path ) use ( $fresh, &$owned ): void {
					$owned = $this->validated_copy( $path, $fresh );
				}
			);
			if ( ! $artifact->discard() ) {
				throw new \RuntimeException( 'Acquisition cleanup failed.' );
			}
		} catch ( \Throwable ) {
			if ( is_array( $owned ) ) {
				$this->archive_store->remove( $owned['path'], $owned['directory'] );
			}
			$artifact?->discard();
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
		if ( null === $verified || ! $this->live() ) {
			$this->archive_store->remove( $owned['path'], $owned['directory'] );
			return $this->failure( 'binding_fence_lost' );
		}
		try {
			if ( ! $this->validator->verify_archive_identity( $fresh, $owned['path'] ) ) {
				throw new InvalidArgumentException( 'The owned archive identity changed.' );
			}
			$receipt = AcquisitionReceipt::issue( $verified['current'], $fresh, $this->validator, $owned['proof'], $verified['now'] );
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
			|| ! $this->descriptor_admitted( $this->descriptor )
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
		if ( ! $this->live() || ! $this->pending_install->admit_extraction() ) {
			$this->clear_pending();
			return $this->failure( 'archive_changed_before_extraction' );
		}
		return $pre;
	}

	/** @param array<string,mixed> $hook_extra */
	public function filter_source_selection( mixed $source, string $remote_source, mixed $upgrader, array $hook_extra ): mixed {
		unset( $remote_source );
		if ( ! $this->matches_operation( $hook_extra ) ) {
			return $source;
		}
		if ( ! $this->live() ) {
			if ( null === $this->operation_guard && ! $this->pending_install->active() ) {
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
			|| ! $this->descriptor_admitted( $this->descriptor )
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
		if ( ! $this->pending_install->verify_staging() ) {
			$this->clear_pending();
			return $this->failure( 'staged_package_identity_invalid' );
		}
		if ( ! $this->live() || ! $this->descriptor_admitted( $this->descriptor ) || ! $this->begin_native_mutation() ) {
			$this->clear_pending();
			return $this->failure( 'runtime_liveness_lost' );
		}
		$this->admit_theme_error_bridge( $upgrader, $hook_extra );
		return $source;
	}
	/** @param array<string,mixed> $hook_extra */
	public function filter_pre_install( mixed $response, array $hook_extra ): mixed {
		if ( ! $this->matches_operation( $hook_extra ) ) {
			return $response;
		}
		if ( ! $this->live() ) {
			if ( null === $this->operation_guard && ! $this->pending_install->active() ) {
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
			|| ! $this->descriptor_admitted( $this->descriptor )
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
		if ( ! $this->live() ) {
			$this->clear_pending();
			return $this->failure( 'runtime_liveness_lost' );
		}
		return $response;
	}
	/** @param array<string,mixed> $hook_extra */
	public function capture_install_package_result( mixed $result, array $hook_extra ): mixed {
		if ( ! $this->matches_operation( $hook_extra ) ) {
			return $result;
		}
		if ( null === $this->native_owner && ! $this->live() ) {
			if ( ! $this->pending_install->active() ) {
				return $result;
			}
			$this->clear_pending();
			return $this->failure( 'runtime_liveness_lost' );
		}
		if ( null !== $this->native_owner && ( $result instanceof \WP_Error || false === $result ) ) {
			$this->pending_install->capture_install_result( $result );
		}
		if ( ! $this->pending_install->active() || $result instanceof \WP_Error || false === $result ) {
			if ( $result instanceof \WP_Error ) {
				$outgoing = $this->failure( 'unverified_install_result' );
				$upgrader = $this->theme_error_upgrader;
				if ( $outgoing instanceof \WP_Error && $upgrader instanceof \Theme_Upgrader && $this->can_bridge_theme_error( $result ) ) {
					$this->theme_error_original = $result;
					$this->theme_error_outgoing = $outgoing;
					add_action( 'upgrader_process_complete', array( $this, 'restore_theme_error_result' ), PHP_INT_MIN, 2 );
					$upgrader->result = array();
					return $outgoing;
				}
				$this->clear_pending();
				return $outgoing;
			}
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
	public function restore_theme_error_result( mixed $upgrader, array $hook_extra ): void {
		if ( ! $upgrader instanceof \Theme_Upgrader || $upgrader !== $this->theme_error_upgrader || ! $this->matches_completion( $hook_extra ) ) {
			return;
		}
		if (
			$this->theme_error_original instanceof \WP_Error
			&& $this->theme_error_outgoing instanceof \WP_Error
			&& $upgrader->skin instanceof \Theme_Upgrader_Skin
			&& \Theme_Upgrader_Skin::class === get_class( $upgrader->skin )
			&& array() === $upgrader->result
			&& $upgrader->skin->result === $this->theme_error_outgoing
		) {
			$upgrader->result = $this->theme_error_original;
		}
		$this->disarm_theme_error_bridge();
	}
	/** @param array<string,mixed> $hook_extra */
	public function observe_completion( mixed $upgrader, array $hook_extra ): void {
		unset( $upgrader );
		if ( ( null !== $this->native_owner || $this->live() ) && $this->matches_completion( $hook_extra ) ) {
			$this->clear_discovery_snapshot();
			$this->pending_install->observe_completion();
		}
	}
	/** Finalization is deliberately after Core rollback and backup cleanup. */
	public function finalize_pending_install(): void {
		if ( $this->finalizing ) {
			return;
		}
		$this->finalizing = true;
		if ( null === $this->native_owner && ! $this->live() ) {
			if ( $this->pending_install->active() ) {
				$this->diagnose( 'runtime_liveness_lost', null );
			}
			$this->clear_pending();
			$this->finalizing = false;
			return;
		}
		if ( ! $this->pending_install->active() ) {
			$this->clear_pending();
			$this->finalizing = false;
			return;
		}

		try {
			if ( ! $this->descriptor instanceof IdentityDescriptor ) {
				$this->diagnose( 'outcome_uncertain', null );
				return;
			}
			if ( ! $this->descriptor_admitted( $this->descriptor ) ) {
				$this->diagnose( 'descriptor_admission_rejected', null );
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
				|| ! $this->pending_install->staging_verified()
				|| ! is_array( $manifest )
				|| ! $this->matches_staged_metadata( $destination )
			) {
				$this->diagnose( 'outcome_uncertain', null );
				return;
			}
			$completed = BindingFenceCoordinator::complete_persistent_install( $this->wpdb, $this->state, $this->claim, $this->pending_install->receipt(), $this->descriptor );
			if ( 'completed' === $completed['result'] ) {
				$this->lease_held     = false;
				$this->native_outcome = 'verified_success';
			}
			if ( null === $this->native_owner ) {
				$this->diagnose( 'completed' === $completed['result'] ? 'update_completed' : 'outcome_uncertain', null );
			}
		} finally {
			$this->clear_pending();
			$this->finalizing = false;
		}
	}
	/** @return list<string> */ public function diagnostics(): array {
		return $this->diagnostics; }
	/** @return array{candidate_header_version:string|null,candidate_tag:string|null,candidate_validation_code:string|null,candidate_version:string|null,failure_code:string|null,installed_version:string|null,last_check:int|null,offered_release_identity:string|null,offered_version:string|null,relationship:string|null} */ public function status(): array {
		return $this->status; }
	public function refresh(): bool {
		if ( $this->native_beginning || null !== $this->native_owner ) {
			return false;
		}
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
		$live = ( ! $this->selected_runtime_state instanceof SelectedRuntimeState || $this->selected_runtime_state->broker_is_live() ) && $this->operation_allowed();
		if ( ! $live ) {
			$this->clear_discovery_snapshot();
		}
		return $live;
	}

	/** @phpstan-impure Host authority must be read afresh. */
	private function operation_allowed(): bool {
		try {
			return null === $this->operation_guard || true === ( $this->operation_guard )();
		} catch ( \Throwable ) {
			return false;
		}
	}

	private function begin_native_mutation(): bool {
		if ( null === $this->native_mutation_begin ) {
			return true;
		}
		if ( $this->native_beginning || null !== $this->native_owner ) {
			return false;
		}
		$this->native_beginning = true;
		$receipt                = $this->pending_install->receipt();
		try {
			$owner = ( $this->native_mutation_begin )( $this->target_type, $this->installed_identity );
			if ( ! is_string( $owner ) || 1 !== preg_match( '/\A[^\x00-\x20\x7f]{1,191}\z/D', $owner ) ) {
				return false;
			}
			$this->native_owner = $owner;
			return $this->pending_install->staging_verified() && $receipt === $this->pending_install->receipt() && null !== $this->verify_current();
		} catch ( \Throwable ) {
			return false;
		} finally {
			$this->native_beginning = false;
		}
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
	private function descriptor_admitted( IdentityDescriptor $descriptor ): bool {
		try {
			return null === $this->descriptor_admission || true === ( $this->descriptor_admission )( $descriptor );
		} catch ( \Throwable ) {
			return false;
		}
	}
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
		$source = rtrim( $source, '/\\' );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Observe native filesystem identity without following a replacement abstraction; missing or changed facts fail existing validation.
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
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Observe native filesystem identity without following a replacement abstraction; missing or changed facts fail existing validation.
		$file = @lstat( $path );
		if ( ! is_array( $file ) || 0100000 !== ( $file['mode'] & 0170000 ) ) {
			return false;
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read the bounded staged header locally; unreadable content returns the existing metadata rejection.
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
	/**
	 * claim_discovery() has established the current binding fence before discovery.
	 * @param (\Closure(): void)|null $on_discovery
	 */
	private function discover( string $installed, string &$reason = '', ?\Closure $on_discovery = null ): ?IdentityDescriptor {
		$reason               = 'release_list_failed';
		$rejected             = false;
		$normalized_installed = ReleaseVersion::normalize_header( $installed );
		if ( ! is_string( $normalized_installed ) ) {
			return null;
		}
		$reused = $this->reusable_discovery( $normalized_installed );
		if ( $reused instanceof IdentityDescriptor ) {
			return $reused;
		}
		if ( null !== $on_discovery ) {
			$on_discovery();
		}
		$this->clear_discovery_snapshot();
		$discovery_claim = $this->claim;
		$discovery_epoch = $this->discovery_epoch;
		try {
			$listed     = $this->adapter->list_releases();
			$candidates = $listed['candidates'] ?? null;
		} catch ( \Throwable ) {
			$reason                                    = 'release_list_failed';
			$this->status['candidate_validation_code'] = 'release_list_failed';
			return null;
		}
		$rate_limit = $listed['rate_limit'] ?? null;
		if ( is_array( $rate_limit ) && true === ( $rate_limit['limited'] ?? null ) ) {
			$reason                                    = 'release_list_failed';
			$this->status['candidate_validation_code'] = 'release_list_failed';
			return null;
		}
		if ( ! is_array( $candidates ) || count( $candidates ) > 8 ) {
			$reason                                    = 'candidate_invalid';
			$this->status['candidate_validation_code'] = 'candidate_list_invalid';
			return null; }
		foreach ( $candidates as $candidate ) {
			if ( ! is_array( $candidate ) || ! is_string( $candidate['release_identity'] ?? null ) || ! is_string( $candidate['tag'] ?? null ) || ! is_string( $candidate['version'] ?? null ) ) {
				$reason                                    = 'candidate_invalid';
				$this->status['candidate_validation_code'] = 'candidate_invalid';
				return null; }
			$relationship = ReleaseVersion::relationship( $candidate['version'], $installed );
			if ( $rejected && in_array( $relationship, array( ReleaseVersion::RELATIONSHIP_SAME, ReleaseVersion::RELATIONSHIP_OLDER ), true ) ) {
				// A later non-candidate cannot replace the facts for the retained rejection.
				continue;
			}
			$this->status['candidate_tag']     = $candidate['tag'];
			$this->status['candidate_version'] = $candidate['version'];
			$this->status['relationship']      = $relationship;
			if ( ReleaseVersion::RELATIONSHIP_NEWER !== $this->status['relationship'] ) {
				if ( ReleaseVersion::RELATIONSHIP_INVALID === $this->status['relationship'] ) {
					$rejected = true;
					$reason   = 'candidate_invalid';
				}
				$this->status['candidate_validation_code'] = 'candidate_not_newer';
				continue; }
			$rejected = true;
			$reason   = 'candidate_rejected';
			try {
				$descriptor = $this->adapter->inspect( $candidate['release_identity'], $candidate['tag'] );
				BindingRecord::assert_descriptor_binding( $descriptor, $this->binding );
			} catch ( ReleaseFailure $failure ) {
				$reason                                    = 'candidate_inspection_failed';
				$this->status['candidate_validation_code'] = 'candidate_inspection_failed';
				if ( self::can_reject_candidate( $failure ) ) {
					$reason = 'candidate_rejected';
					continue;
				}
				return null;
			} catch ( \Throwable ) {
				$reason                                    = 'candidate_inspection_failed';
				$this->status['candidate_validation_code'] = 'candidate_inspection_failed';
				return null;
			}
			if ( ! $this->descriptor_admitted( $descriptor ) ) {
				$reason                                    = 'candidate_rejected';
				$this->status['candidate_validation_code'] = 'candidate_admission_rejected';
				continue;
			}
			$facts = $descriptor->to_array();
			if ( ! hash_equals( $candidate['release_identity'], $facts['release_identity'] ) || ! hash_equals( $candidate['tag'], $facts['tag'] ) || 0 !== ReleaseVersion::compare( $candidate['version'], $facts['version'] ) ) {
				$reason                                    = 'candidate_descriptor_mismatch';
				$this->status['candidate_validation_code'] = 'candidate_descriptor_mismatch';
				return null; }
			$artifact = null;
			try {
				$artifact = $this->adapter->acquire( $descriptor );
				$valid    = $artifact->inspect( fn( string $path ) => $this->validator->validate( $descriptor, $this->archive_policy, $path ) );
			} catch ( ReleaseFailure $failure ) {
				$reason                                    = 'candidate_validation_failed';
				$this->status['candidate_validation_code'] = 'candidate_validation_failed';
				if ( $artifact instanceof \RAN\WPReleaseUpdater\V1\Archive\TemporaryArtifact && ! $artifact->discard() ) {
					return null;
				}
				if ( self::can_reject_candidate( $failure ) ) {
					$reason = 'candidate_rejected';
					continue;
				}
				return null;
			} catch ( \Throwable ) {
				$reason                                    = 'candidate_validation_failed';
				$this->status['candidate_validation_code'] = 'candidate_validation_failed';
				if ( $artifact instanceof \RAN\WPReleaseUpdater\V1\Archive\TemporaryArtifact ) {
					$artifact->discard();
				}
				return null;
			}
			$this->status['candidate_validation_code'] = $valid->code();
			if ( ! $valid->is_valid() ) {
				$reason = 'candidate_rejected';
				if ( ! $artifact->discard() ) {
					$reason                                    = 'candidate_validation_failed';
					$this->status['candidate_validation_code'] = 'candidate_validation_failed';
					return null;
				}
				continue;
			}
			if ( ! $artifact->discard() ) {
				$reason                                    = 'candidate_validation_failed';
				$this->status['candidate_validation_code'] = 'candidate_validation_failed';
				return null;
			}
			if ( ! $this->descriptor_admitted( $descriptor ) ) {
				$reason                                    = 'candidate_rejected';
				$this->status['candidate_validation_code'] = 'candidate_admission_rejected';
				continue;
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
			return $this->live() ? $descriptor : null;
		}
		if ( ! $rejected && false === ( $listed['search_exhausted'] ?? null ) && false === ( $listed['not_modified'] ?? null ) ) {
			$reason = array() === $candidates ? 'no_candidates' : 'no_newer_candidate';
		}
		return null;
	}
	private static function can_reject_candidate( ReleaseFailure $failure ): bool {
		return in_array( $failure->release_code, array( 'release_unavailable', 'package_incompatible' ), true )
			&& in_array( $failure->cleanup_status, array( 'not_applicable', 'complete' ), true );
	}
	private function reusable_discovery( string $installed ): ?IdentityDescriptor {
		if ( ! $this->live() || ! $this->native_discovery_reuse || ! is_array( $this->discovery_snapshot ) || ! is_array( $this->claim ) || $this->discovery_snapshot['installed'] !== $installed || $this->discovery_snapshot['claim'] !== $this->claim ) {
			return null;
		}
		$descriptor = $this->discovery_snapshot['descriptor'];
		if ( ! $this->descriptor_admitted( $descriptor ) ) {
			$this->clear_discovery_snapshot();
			$this->descriptor                          = null;
			$this->status['candidate_validation_code'] = 'candidate_admission_rejected';
			return null;
		}
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
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Native JSON preserves protocol or fixture bytes without requiring WordPress helpers or their fallback behavior.
			$json = json_encode( $value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES );
		} catch ( \JsonException ) {
			return null; }
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- URL-safe base64 represents the bounded opaque operation token or its fixture; it does not encode executable code.
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
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decode the bounded opaque operation token for structural validation; never execute decoded bytes.
		$raw = base64_decode( strtr( $encoded, '-_', '+/' ) . str_repeat( '=', ( 4 - strlen( $encoded ) % 4 ) % 4 ), true );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- URL-safe base64 represents the bounded opaque operation token or its fixture; it does not encode executable code.
		if ( ! is_string( $raw ) || strlen( $raw ) > 6144 || ! hash_equals( $encoded, rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' ) ) ) {
			return null;
		}
		try {
			$value = json_decode( $raw, true, 32, JSON_THROW_ON_ERROR );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Native JSON preserves protocol or fixture bytes without requiring WordPress helpers or their fallback behavior.
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
	/** @return array{directory:string,path:string,proof:ValidatedPackage}|null */ private function validated_copy( string $path, IdentityDescriptor $descriptor ): ?array {
		try {
			$proof = $this->validator->validate( $descriptor, $this->archive_policy, $path );
		} catch ( \Throwable ) {
			return null;
		}
		$copy = $proof->is_valid() ? $this->archive_store->copy( $path, $descriptor ) : null;
		return null === $copy ? null : array_merge( $copy, array( 'proof' => $proof ) ); }
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
	/**
	 * Related to https://core.trac.wordpress.org/ticket/27365, which concerns
	 * updater error returns rather than this Theme_Upgrader result-array read.
	 * Before editing this area again, check the supported Core versions for an
	 * upstream fix and remove this guard once that fix is qualified.
	 *
	 * @param array<string,mixed> $hook_extra
	 */
	private function admit_theme_error_bridge( mixed $upgrader, array $hook_extra ): void {
		if (
			! $upgrader instanceof \Theme_Upgrader
			|| \Theme_Upgrader::class !== get_class( $upgrader )
			|| ! $upgrader->skin instanceof \Theme_Upgrader_Skin
			|| \Theme_Upgrader_Skin::class !== get_class( $upgrader->skin )
			|| null === $this->native_owner
			|| 'theme' !== $this->target_type
			|| $this->pending_install->multi_run()
			|| 'update' !== ( $hook_extra['action'] ?? null )
			|| ! function_exists( 'doing_action' )
			|| doing_action( 'wp_maybe_auto_update' )
			|| null !== $this->theme_error_upgrader
		) {
			return;
		}
		$this->theme_error_upgrader = $upgrader;
	}
	private function can_bridge_theme_error( \WP_Error $result ): bool {
		$upgrader = $this->theme_error_upgrader;
		return $upgrader instanceof \Theme_Upgrader
			&& null !== $this->native_owner
			&& $this->pending_install->active()
			&& ! $this->pending_install->multi_run()
			&& $upgrader->skin instanceof \Theme_Upgrader_Skin
			&& \Theme_Upgrader_Skin::class === get_class( $upgrader->skin )
			&& function_exists( 'doing_action' )
			&& ! doing_action( 'wp_maybe_auto_update' )
			&& null === $this->theme_error_original
			&& null === $this->theme_error_outgoing
			&& $upgrader->result === $result;
	}
	private function disarm_theme_error_bridge(): void {
		$this->theme_error_upgrader = null;
		$this->theme_error_original = null;
		$this->theme_error_outgoing = null;
	}
	private function clear_pending( bool $release = true ): void {
		if ( $this->native_beginning || ( null !== $this->native_owner && ! $this->finalizing ) ) {
			return;
		}
		$owner     = $this->native_owner;
		$archive   = $this->pending_install->archive();
		$directory = $this->pending_install->archive_directory();
		$this->clear_discovery_snapshot();
		$this->disarm_theme_error_bridge();
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
		if ( null !== $owner && null !== $this->native_mutation_complete ) {
			$this->native_owner = null;
			clearstatcache();
			$clean                = is_string( $archive ) && is_string( $directory ) && ! file_exists( $archive ) && ! is_link( $archive ) && ! file_exists( $directory ) && ! is_link( $directory );
			$outcome              = $clean ? $this->native_outcome : 'uncertain';
			$this->native_outcome = 'uncertain';
			try {
				( $this->native_mutation_complete )( $owner, $this->target_type, $this->installed_identity, $outcome );
			} catch ( \Throwable ) {
				$outcome = 'uncertain';
			}
			$this->diagnose( 'verified_success' === $outcome ? 'update_completed' : 'outcome_uncertain', null );
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
