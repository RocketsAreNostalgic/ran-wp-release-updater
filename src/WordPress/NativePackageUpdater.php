<?php

declare(strict_types=1);

namespace RAN\WPReleaseUpdater\V1\WordPress;

use RAN\WPReleaseUpdater\V1\Dependency\PackageDirectory;
use InvalidArgumentException;
use RAN\WPReleaseUpdater\V1\Archive\PackageIdentityValidator;
use RAN\WPReleaseUpdater\V1\Archive\ValidatedPackage;
use RAN\WPReleaseUpdater\V1\Contract\CanonicalUpdateUri;
use RAN\WPReleaseUpdater\V1\Contract\IdentityDescriptor;
use RAN\WPReleaseUpdater\V1\Contract\ReleaseAdapter;
use RAN\WPReleaseUpdater\V1\Contract\ReleaseVersion;
use RAN\WPReleaseUpdater\V1\Contract\ReleaseRejection;
use RAN\WPReleaseUpdater\V1\Dependency\TargetWorkingCopy;
use RAN\WPReleaseUpdater\V1\Runtime\ReleaseFailure;
use RAN\WPReleaseUpdater\V1\Runtime\RequestProtocolValidator;
use RAN\WPReleaseUpdater\V1\Runtime\SelectedRuntimeState;

/**
 * The single native WordPress lifecycle owner for a sealed neutral release.
 *
 * @phpstan-import-type OwnedCopy from ExactInstallInvocation
 * @phpstan-import-type Finalization from ExactInstallInvocation
 * @phpstan-type TargetBinding array{canonical_repository_locator:string,canonical_update_uri:string,installed_package_identity:string,maximum_artifact_bytes:int,network_id:int,php_runtime_version:string,provider_code:string,release_channel:string,stable_repository_identity:string,target_type:string,theme_template:string,update_policy:string,wordpress_runtime_version:string}
 */
final class NativePackageUpdater {
	private const MAX_DIAGNOSTICS     = 16;
	private const CONFIGURATION_KEYS  = array( 'headers', 'installed_directory', 'installed_package_identity', 'policy', 'target_type', 'update_uri' );
	private const HEADER_KEYS         = array( 'Author', 'Description', 'Name', 'PluginURI', 'RequiresPHP', 'RequiresWP', 'UpdateURI', 'Version' );
	private const TARGET_BINDING_KEYS = array(
		'canonical_repository_locator',
		'canonical_update_uri',
		'installed_package_identity',
		'maximum_artifact_bytes',
		'network_id',
		'php_runtime_version',
		'provider_code',
		'release_channel',
		'stable_repository_identity',
		'target_type',
		'theme_template',
		'update_policy',
		'wordpress_runtime_version',
	);
	private const DESCRIPTOR_PAIRS    = array(
		'installed_package_identity' => 'installed_package_identity',
		'target_type'                => 'target_type',
		'provider_code'              => 'provider_code',
		'channel'                    => 'release_channel',
		'canonical_update_uri'       => 'canonical_update_uri',
		'repository_locator'         => 'canonical_repository_locator',
		'repository_identity'        => 'stable_repository_identity',
	);
	/** @var array<string,mixed>|null */
	private ?array $listing_diagnostics = null;
	private ?bool $search_exhausted     = null;
	/** @var list<array{release_identity:?string,tag:?string,reason:string}> */
	private array $candidate_rejections = array();

	private bool $registered                            = false;
	private ?IdentityDescriptor $descriptor             = null;
	private ?string $lock_token                         = null;
	private bool $lock_owned                            = false;
	private bool $lock_multi_run                        = false;
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
	/** @var array{outcome:string,source:string,wordpress_code:string|null,release_identity:string,version:string,last_observed_step:string|null,recorded_at:int}|null */
	private ?array $last_native_outcome = null;
	/** @var array{descriptor:IdentityDescriptor,installed:string}|null */
	private ?array $discovery_snapshot = null;
	private int $discovery_epoch       = 0;
	private OwnedArchiveStore $archive_store;
	private StagedPackageManifest $manifest_builder;
	private PendingInstallState $pending_install;
	private WordPressUpdaterLock $updater_lock;
	private ?\Closure $operation_guard;
	private ?\Closure $native_mutation_begin;
	private ?\Closure $native_mutation_complete;
	private ?\Closure $descriptor_admission;
	private ?string $native_owner                   = null;
	private bool $native_beginning                  = false;
	private bool $finalizing                        = false;
	private string $native_outcome                  = 'uncertain';
	private ?\Theme_Upgrader $theme_error_upgrader  = null;
	private ?\WP_Error $theme_error_original        = null;
	private ?\WP_Error $theme_error_outgoing        = null;
	private ?IdentityDescriptor $attempt_descriptor = null;
	/** @var array{outcome:string,source:string,wordpress_code:string|null}|null */
	private ?array $attempt_outcome = null;
	/** @var array<string,mixed>|null Offer for the one release authorised for this request, held until an attempt consumes it. */
	private ?array $authorized_offer = null;
	/** That release's fingerprint, the only one a download may use. */
	private ?string $authorized_fingerprint = null;
	/** @var array<string,true> Every fingerprint authorised since refresh(); entries carrying one are stripped whenever WordPress saves the update check. */
	private array $authorized_fingerprints = array();
	/** @var array<string,array{Author:string,Description:string,Name:string,PluginURI:string,RequiresPHP:string,RequiresWP:string}> Validated candidate headers keyed by descriptor fingerprint. */
	private array $candidate_headers               = array();
	private const EXACT_TOKEN_PREFIX               = 'ran-wp-release-updater:exact:v1:';
	private ?ExactInstallInvocation $exact_install = null;


	/**
	 * @param array<string,string> $headers
	 * @param TargetBinding        $target_binding
	 * @param array<string,mixed>  $archive_policy
	 */
	private function __construct(
		private string $target_type,
		private string $installed_directory,
		private string $installed_identity,
		private string $update_uri,
		private string $policy,
		private array $headers,
		private array $target_binding,
		private string $binding_hash,
		private ReleaseAdapter $adapter,
		object $wpdb,
		private array $archive_policy,
		private PackageIdentityValidator $validator,
		private ?SelectedRuntimeState $selected_runtime_state = null,
		private bool $native_discovery_reuse = false,
		?callable $operation_guard = null,
		?callable $native_mutation_begin = null,
		?callable $native_mutation_complete = null,
		?callable $descriptor_admission = null,
		private bool $allow_working_copy = false
	) {
		$this->archive_store            = new OwnedArchiveStore();
		$this->manifest_builder         = new StagedPackageManifest();
		$this->pending_install          = new PendingInstallState();
		$this->updater_lock             = new WordPressUpdaterLock( $wpdb );
		$this->operation_guard          = null === $operation_guard ? null : \Closure::fromCallable( $operation_guard );
		$this->native_mutation_begin    = null === $native_mutation_begin ? null : \Closure::fromCallable( $native_mutation_begin );
		$this->native_mutation_complete = null === $native_mutation_complete ? null : \Closure::fromCallable( $native_mutation_complete );
		$this->descriptor_admission     = null === $descriptor_admission ? null : \Closure::fromCallable( $descriptor_admission );
	}

	/**
	 * @param array<string,mixed> $configuration
	 * @param array<string,mixed> $target_binding
	 * @param array<string,mixed> $archive_policy
	 */
	public static function from_configuration(
		array $configuration,
		array $target_binding,
		ReleaseAdapter $adapter,
		object $wpdb,
		array $archive_policy,
		?PackageIdentityValidator $validator = null,
		?SelectedRuntimeState $selected_runtime_state = null,
		bool $native_discovery_reuse = false,
		?callable $operation_guard = null,
		?callable $native_mutation_begin = null,
		?callable $native_mutation_complete = null,
		?callable $descriptor_admission = null,
		bool $allow_working_copy = false
	): ?self {
		if ( ( null === $native_mutation_begin ) !== ( null === $native_mutation_complete ) || ( null === $operation_guard && null !== $native_mutation_begin ) ) {
			return null;
		}
		$binding_facts = self::normalize_target_binding( $target_binding );
		if ( null === $binding_facts ) {
			return null;
		}
		if (
			! self::valid_configuration( $configuration, $binding_facts )
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
		$binding_hash = self::compute_binding_hash( $binding_facts );
		if ( null === $binding_hash ) {
			return null;
		}
		return new self(
			$configuration['target_type'],
			$configuration['installed_directory'],
			$configuration['installed_package_identity'],
			$uri,
			$configuration['policy'],
			$configuration['headers'],
			$binding_facts,
			$binding_hash,
			$adapter,
			$wpdb,
			$archive_policy,
			$validator ?? new PackageIdentityValidator(),
			$selected_runtime_state,
			$native_discovery_reuse,
			$operation_guard,
			$native_mutation_begin,
			$native_mutation_complete,
			$descriptor_admission,
			$allow_working_copy
		);
	}

	/**
	 * @internal Validates and orders target binding facts.
	 * @return TargetBinding|null
	 */
	public static function normalize_target_binding( mixed $value ): ?array {
		if ( ! self::exact_keys( $value, self::TARGET_BINDING_KEYS ) || ! is_array( $value ) ) {
			return null;
		}
		if (
			! in_array( $value['target_type'], array( 'plugin', 'theme' ), true )
			|| ! IdentityDescriptor::is_bounded_opaque_identity( $value['installed_package_identity'], 255 )
			|| ! is_int( $value['maximum_artifact_bytes'] )
			|| $value['maximum_artifact_bytes'] < 1
			|| ! is_int( $value['network_id'] )
			|| $value['network_id'] < 1
			|| ! IdentityDescriptor::is_provider_code( $value['provider_code'] )
			|| ! IdentityDescriptor::is_bounded_opaque_identity( $value['canonical_repository_locator'], 255 )
			|| ! IdentityDescriptor::is_bounded_opaque_identity( $value['stable_repository_identity'] )
			|| ! is_string( $value['canonical_update_uri'] )
			|| CanonicalUpdateUri::canonicalize( $value['canonical_update_uri'] ) !== $value['canonical_update_uri']
			|| ! in_array( $value['release_channel'], array( 'stable', 'prerelease' ), true )
			|| ! self::valid_theme_template( $value['target_type'], $value['theme_template'] )
			|| ! in_array( $value['update_policy'], array( 'disabled', 'forced-off', 'manual', 'automatic' ), true )
			|| ! IdentityDescriptor::is_bounded_opaque_identity( $value['php_runtime_version'], 64 )
			|| ! IdentityDescriptor::is_bounded_opaque_identity( $value['wordpress_runtime_version'], 64 )
		) {
			return null;
		}
		return array(
			'canonical_repository_locator' => $value['canonical_repository_locator'],
			'canonical_update_uri'         => $value['canonical_update_uri'],
			'installed_package_identity'   => $value['installed_package_identity'],
			'maximum_artifact_bytes'       => $value['maximum_artifact_bytes'],
			'network_id'                   => $value['network_id'],
			'php_runtime_version'          => $value['php_runtime_version'],
			'provider_code'                => $value['provider_code'],
			'release_channel'              => $value['release_channel'],
			'stable_repository_identity'   => $value['stable_repository_identity'],
			'target_type'                  => $value['target_type'],
			'theme_template'               => $value['theme_template'],
			'update_policy'                => $value['update_policy'],
			'wordpress_runtime_version'    => $value['wordpress_runtime_version'],
		);
	}

	/**
	 * @internal Asserts a release descriptor matches the bound target facts.
	 * @param TargetBinding $binding
	 */
	public static function assert_descriptor_binding( IdentityDescriptor $descriptor, array $binding ): IdentityDescriptor {
		$facts = $descriptor->to_array();
		foreach ( self::DESCRIPTOR_PAIRS as $descriptor_key => $binding_key ) {
			if ( ! hash_equals( (string) $facts[ $descriptor_key ], (string) $binding[ $binding_key ] ) ) {
				throw new InvalidArgumentException( 'The descriptor binding is invalid.' );
			}
		}
		if ( $facts['artifact_size'] > $binding['maximum_artifact_bytes'] ) {
			throw new InvalidArgumentException( 'The descriptor exceeds the target artifact limit.' );
		}
		return $descriptor;
	}

	/** @param TargetBinding $facts */
	private static function compute_binding_hash( array $facts ): ?string {
		try {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Native JSON preserves protocol or fixture bytes without requiring WordPress helpers or their fallback behavior.
			return hash( 'sha256', json_encode( $facts, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		} catch ( \JsonException ) {
			return null;
		}
	}

	private static function valid_theme_template( string $target_type, mixed $value ): bool {
		return is_string( $value )
			&& (
				( 'plugin' === $target_type && '' === $value )
				|| (
					'theme' === $target_type
					&& ( '' === $value || PackageDirectory::is_valid_name( $value ) )
				)
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

		$prefix        = 'plugin' === $this->target_type ? 'update_plugins_' : 'update_themes_';
		$canonical_key = strtolower( $host );
		add_filter( $prefix . $canonical_key, array( $this, 'filter_update' ), 10, 4 );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Native URL parsing preserves provider and bootstrap validation semantics independently of WordPress helpers.
		$raw_host = parse_url( $this->headers['UpdateURI'], PHP_URL_HOST );
		if ( is_string( $raw_host ) && '' !== $raw_host && $raw_host !== $canonical_key && 0 === strcasecmp( $raw_host, $canonical_key ) ) {
			add_filter( $prefix . $raw_host, array( $this, 'filter_update' ), 10, 4 );
		}
		if ( null !== $this->descriptor_admission || $this->policy_blocked() ) {
			add_filter( 'site_transient_update_' . ( 'plugin' === $this->target_type ? 'plugins' : 'themes' ), array( $this, 'filter_cached_update' ), 10, 1 );
		}
		if ( 'plugin' === $this->target_type ) {
			add_filter( 'plugins_api', array( $this, 'filter_plugin_information' ), 10, 3 );
		}
		add_filter( 'ran_wp_release_updater_check_releases_v1', array( $this, 'filter_check_releases' ), 10, 3 );
		add_filter( 'ran_wp_release_updater_offer_release_v1', array( $this, 'filter_offer_release' ), 10, 6 );
		add_filter( 'ran_wp_release_updater_install_exact_release_v1', array( $this, 'filter_install_exact_release' ), 10, 7 );
		add_filter( 'plugin' === $this->target_type ? 'auto_update_plugin' : 'auto_update_theme', array( $this, 'filter_auto_update' ), 10, 2 );
		add_filter( 'upgrader_package_options', array( $this, 'capture_package_options' ), PHP_INT_MAX, 1 );
		add_filter( 'upgrader_pre_download', array( $this, 'filter_pre_download' ), PHP_INT_MAX, 4 );
		add_filter( 'upgrader_pre_install', array( $this, 'filter_pre_install' ), 1, 2 );
		add_filter( 'pre_unzip_file', array( $this, 'filter_pre_unzip_file' ), PHP_INT_MAX, 5 );
		add_filter( 'upgrader_source_selection', array( $this, 'filter_source_selection' ), PHP_INT_MAX, 4 );
		add_filter( 'upgrader_install_package_result', array( $this, 'capture_install_package_result' ), PHP_INT_MAX, 2 );
		add_action( 'upgrader_process_complete', array( $this, 'observe_completion' ), 10, 2 );
		add_action( 'automatic_updates_complete', array( $this, 'observe_automatic_results' ), 10, 1 );
		$this->registered = true;
	}

	public function filter_cached_update( mixed $transient ): mixed {
		if ( null !== $this->authorized_offer && $this->live() ) {
			// Supply the authorised release so WordPress's native upgrader finds it in this request.
			if ( ! is_object( $transient ) ) {
				$transient = (object) array( 'response' => array() );
			} elseif ( ! isset( $transient->response ) && ( $transient instanceof \stdClass || property_exists( $transient, 'response' ) ) ) {
				$transient->response = array();
			}
			if ( is_array( $transient->response ?? null ) ) {
				$transient->response[ $this->installed_identity ] = 'plugin' === $this->target_type ? (object) $this->authorized_offer : $this->authorized_offer;
				return $transient;
			}
		}
		$blocked = $this->policy_blocked();
		if ( ( null === $this->descriptor_admission && ! $blocked ) || ! is_object( $transient ) || ! is_array( $transient->response ?? null ) || ! array_key_exists( $this->installed_identity, $transient->response ) ) {
			return $transient;
		}
		if ( $blocked ) {
			unset( $transient->response[ $this->installed_identity ] );
			return $transient;
		}
		$descriptor = $this->cached_descriptor( $transient );
		if ( ! $descriptor instanceof IdentityDescriptor || ! $this->live() || ! $this->descriptor_admitted( $descriptor ) || ! $this->newer_than_installed( $descriptor ) ) {
			unset( $transient->response[ $this->installed_identity ] );
		}
		return $transient;
	}

	/** Removes any release authorised in this request from the update check before WordPress saves it, so no offer outlives the request. */
	public function filter_store_update( mixed $transient ): mixed {
		$package = $this->cached_package( $transient );
		if ( is_string( $package ) && str_starts_with( $package, self::EXACT_TOKEN_PREFIX ) ) {
			unset( $transient->response[ $this->installed_identity ] );
			return $transient;
		}
		$descriptor = array() === $this->authorized_fingerprints ? null : $this->cached_descriptor( $transient );
		if ( is_object( $transient ) && is_array( $transient->response ?? null ) && null !== $descriptor && isset( $this->authorized_fingerprints[ $descriptor->fingerprint_value() ] ) ) {
			unset( $transient->response[ $this->installed_identity ] );
		}
		return $transient;
	}

	private function cached_descriptor( mixed $transient ): ?IdentityDescriptor {
		$package = $this->cached_package( $transient );
		return is_string( $package ) ? $this->parse_token( $package ) : null;
	}

	private function cached_package( mixed $transient ): mixed {
		$entry = is_object( $transient ) && is_array( $transient->response ?? null ) ? ( $transient->response[ $this->installed_identity ] ?? null ) : null;
		return is_object( $entry ) ? ( $entry->package ?? null ) : ( is_array( $entry ) ? ( $entry['package'] ?? null ) : null );
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
		if ( null !== $this->exact_install ) {
			return false;
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
		if ( $this->policy_blocked() ) {
			$reason   = 'disabled' === $this->policy ? 'policy_disabled' : 'policy_forced_off';
			$check_id = $this->start_check_observation();
			return false;
		}
		if ( ! $this->direct_filesystem() ) {
			$reason   = 'filesystem_unavailable';
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
		$this->status['offered_version']          = $descriptor->to_array()['version'];
		$this->status['offered_release_identity'] = $descriptor->release_identity();
		$reason                                   = 'update_available';
		return $this->offer_entry( $descriptor, $token, $this->automatic_eligible( $descriptor ) );
	}

	/** @return array<string,mixed> */
	private function offer_entry( IdentityDescriptor $descriptor, string $token, bool $autoupdate ): array {
		$headers = $this->candidate_headers_for( $descriptor );
		return array(
			'id'                                  => $this->update_uri,
			'slug'                                => $this->information_slug(),
			'url'                                 => $this->update_uri,
			'package'                             => $token,
			'requires'                            => $headers['RequiresWP'],
			'requires_php'                        => $headers['RequiresPHP'],
			'version'                             => $descriptor->to_array()['version'],
			'autoupdate'                          => $autoupdate,
			'ran_wp_release_updater_binding_hash' => $this->binding_hash,
			'plugin' === $this->target_type ? 'plugin' : 'theme' => $this->installed_identity,
		);
	}

	/**
	 * Host-requested authorisation of one exact release for this request: the same version as installed or newer,
	 * or older only when $older_intended is exactly true, which the host passes after the operator confirmed it.
	 *
	 * The release is freshly inspected, bound and validated as discovery validates a candidate. Until a download
	 * attempt consumes it, reads of the update check supply its offer; saves of the update check never keep it.
	 * Another target's request, or a runtime that is not live, is returned unchanged.
	 *
	 * @return mixed Unchanged $result, or the offer report array for this target.
	 */
	public function filter_offer_release( mixed $result, mixed $target_type, mixed $installed_identity, mixed $release_identity, mixed $tag, mixed $older_intended = false ): mixed {
		if ( $target_type !== $this->target_type || ! is_string( $installed_identity ) || ! hash_equals( $this->installed_identity, $installed_identity ) ) {
			return $result;
		}
		if ( null !== $this->exact_install ) {
			return $this->offer_report( 'runtime_busy' );
		}
		$this->authorized_offer = null;
		if ( ! $this->live() ) {
			return $result;
		}
		$previous            = $this->status;
		$previous_rejections = $this->candidate_rejections;
		try {
			return $this->authorize_release( is_string( $release_identity ) ? $release_identity : '', is_string( $tag ) ? $tag : '', true === $older_intended );
		} finally {
			// Authorising is not a check: native status and rejection telemetry stay as the last native check left them.
			$this->status               = $previous;
			$this->candidate_rejections = $previous_rejections;
		}
	}

	/** @return array{status:string,code:string,version:string|null,relationship:string|null} */
	private function authorize_release( string $release_identity, string $tag, bool $older_intended ): array {
		if ( $this->policy_blocked() ) {
			return $this->offer_report( 'disabled' === $this->policy ? 'policy_disabled' : 'policy_forced_off' );
		}
		if ( $this->native_beginning || null !== $this->native_owner || $this->pending_install->active() ) {
			return $this->offer_report( 'runtime_busy' );
		}
		$this->begin_operation();
		if ( ! $this->direct_filesystem() ) {
			return $this->offer_report( 'filesystem_unavailable' );
		}
		try {
			$descriptor = $this->adapter->inspect( $release_identity, $tag );
			self::assert_descriptor_binding( $descriptor, $this->target_binding );
		} catch ( \Throwable ) {
			return $this->offer_report( 'release_unavailable' );
		}
		$version      = $descriptor->to_array()['version'];
		$relationship = ReleaseVersion::relationship( $version, $this->installed_version() );
		$older        = ReleaseVersion::RELATIONSHIP_OLDER === $relationship;
		if ( ReleaseVersion::RELATIONSHIP_INVALID === $relationship || ( $older && ! $older_intended ) ) {
			return $this->offer_report( $older ? 'release_older' : 'release_invalid', $version, $relationship );
		}
		if ( ! $this->manual_eligible( $descriptor ) ) {
			return $this->offer_report( 'candidate_not_eligible', $version, $relationship );
		}
		$candidate = array(
			'release_identity' => $release_identity,
			'tag'              => $tag,
			'version'          => $version,
		);
		$reason    = '';
		if ( true !== $this->admit_candidate( $descriptor, $candidate, $reason ) ) {
			return $this->offer_report( $this->status['candidate_validation_code'] ?? $reason, $version, $relationship );
		}
		$token = $this->token( $descriptor );
		if ( null === $token || ! $this->live() ) {
			return $this->offer_report( null === $token ? 'offer_token_unavailable' : 'runtime_liveness_lost', $version, $relationship );
		}
		// WordPress adds new_version when it saves a check; a supplied offer is never saved, so it carries its own.
		$this->authorized_offer       = $this->offer_entry( $descriptor, $token, false ) + array( 'new_version' => $version );
		$this->authorized_fingerprint = $descriptor->fingerprint_value();
		$this->authorized_fingerprints[ $this->authorized_fingerprint ] = true;
		$transient = 'plugin' === $this->target_type ? 'update_plugins' : 'update_themes';
		add_filter( 'site_transient_' . $transient, array( $this, 'filter_cached_update' ), 10, 1 );
		add_filter( 'pre_set_site_transient_' . $transient, array( $this, 'filter_store_update' ), PHP_INT_MAX, 1 );
		return $this->offer_report( 'release_authorized', $version, $relationship );
	}

	/** @return array{status:string,code:string,version:string|null,relationship:string|null} */
	private function offer_report( string $code, ?string $version = null, ?string $relationship = null ): array {
		return array(
			'status'       => 'release_authorized' === $code ? 'authorized' : 'refused',
			'code'         => $code,
			'version'      => $version,
			'relationship' => $relationship,
		);
	}

	/** Runs one explicitly selected release through the registered target's native lifecycle. */
	public function filter_install_exact_release( mixed $result, mixed $target_type, mixed $installed_identity, mixed $release_identity, mixed $tag, mixed $expected_version, mixed $older_intended = false ): mixed {
		if ( null !== $result || $target_type !== $this->target_type || $installed_identity !== $this->installed_identity || ! $this->live() ) {
			return $result;
		}
		if ( $this->policy_blocked() ) {
			return $this->exact_report( 'disabled' === $this->policy ? 'policy_disabled' : 'policy_forced_off' );
		}
		if ( null !== $this->exact_install || $this->native_beginning || null !== $this->native_owner || $this->pending_install->active() || doing_action( 'shutdown' ) ) {
			return $this->exact_report( 'runtime_busy' );
		}
		if ( ! is_string( $release_identity ) || '' === $release_identity || ! is_string( $tag ) || '' === $tag || ! is_string( $expected_version ) || null === ReleaseVersion::normalize( $expected_version ) ) {
			return $this->exact_report( 'release_invalid' );
		}
		if ( ! $this->direct_filesystem() ) {
			return $this->exact_report( 'filesystem_unavailable' );
		}
		if ( $this->working_copy_blocked() ) {
			return $this->exact_report( 'target_is_working_copy' );
		}
		$installed = $this->bound_package_version( $this->installed_directory, true );
		if ( null === $installed || null === ReleaseVersion::normalize_header( $installed ) ) {
			return $this->exact_report( 'runtime_package_identity_invalid' );
		}
		$is_plugin = 'plugin' === $this->target_type;
		if ( $is_plugin ? ! class_exists( '\\Plugin_Upgrader' ) || ! class_exists( '\\Bulk_Plugin_Upgrader_Skin' ) : ! class_exists( '\\Theme_Upgrader' ) || ! class_exists( '\\Theme_Upgrader_Skin' ) ) {
			return $this->exact_report( 'wordpress_unavailable' );
		}

		try {
			$exact = ExactInstallInvocation::create( $this->target_type, $this->installed_directory, self::EXACT_TOKEN_PREFIX . bin2hex( random_bytes( 32 ) ), $installed );
		} catch ( \Throwable ) {
			return $this->exact_report( 'wordpress_unavailable' );
		}
		$this->exact_install = $exact;
		try {
			$code = $this->install_exact_release( $exact, $release_identity, $tag, $expected_version, true === $older_intended );
		} catch ( \Throwable ) {
			$code = 'unexpected_failure';
		} finally {
			if ( ! $this->pending_install->active() && ! $exact->maintenance_pending() ) {
				if ( $exact->lease_claimed && null === $exact->finalization ) {
					$this->clear_pending();
				}
				$this->disarm_exact_install();
			}
		}
		return $this->exact_report( $code, $exact );
	}

	private function install_exact_release( ExactInstallInvocation $exact, string $release_identity, string $tag, string $expected_version, bool $older_intended ): string {
		$this->begin_operation();
		if ( ! $this->acquire_mutation_lock( false ) ) {
			return 'binding_fence_lost';
		}
		$exact->lease_claimed = true;
		$this->schedule_finalization();
		try {
			$descriptor = $this->adapter->inspect( $release_identity, $tag );
			self::assert_descriptor_binding( $descriptor, $this->target_binding );
		} catch ( \Throwable ) {
			return 'release_unavailable';
		}
		$facts             = $descriptor->to_array();
		$exact->descriptor = $descriptor;
		if ( $release_identity !== $facts['release_identity'] || $tag !== $facts['tag'] || 0 !== ReleaseVersion::compare( $expected_version, $facts['version'] ) ) {
			return 'candidate_descriptor_mismatch';
		}
		$relationship = ReleaseVersion::relationship( $facts['version'], $exact->installed_version );
		if ( ! $older_intended && ReleaseVersion::RELATIONSHIP_OLDER === $relationship ) {
			return 'release_older';
		}
		if ( ! $this->manual_eligible( $descriptor ) || ! $this->descriptor_admitted( $descriptor ) ) {
			return 'candidate_not_eligible';
		}
		if ( ! $this->live() || ! $this->verify_mutation_lock() ) {
			return 'binding_fence_lost';
		}
		$failure = $this->acquire_owned_archive( $descriptor, $exact->archive, $exact );
		if ( null !== $failure || null === $exact->archive ) {
			return $failure ?? 'acquisition_identity_invalid';
		}
		$exact->archive_identity = $this->archive_store->identity( $exact->archive['path'], $descriptor );
		if ( null === $exact->archive_identity ) {
			return 'acquisition_identity_invalid';
		}
		if ( ! $this->live() || ! $this->descriptor_admitted( $descriptor ) || ! $this->verify_mutation_lock() ) {
			return 'binding_fence_lost';
		}

		$result = $this->run_exact_upgrade( $exact, $this->offer_entry( $descriptor, $exact->token, false ) + array( 'new_version' => $facts['version'] ) );
		if ( null === $exact->finalization && $exact->restore_pending() ) {
			return 'backup_restore_pending';
		}
		if ( $exact->maintenance_pending() ) {
			return 'maintenance_cleanup_failed';
		}
		if (
			! $exact->download_consumed
			|| ! $this->pending_install->completion_observed()
			|| ! is_array( $result )
			|| $result !== $exact->upgrader->result
			|| $result !== $this->pending_install->install_result()
		) {
			return $exact->download_failure ?? 'unverified_install_result';
		}
		try {
			$installed_verified = $this->verify_installed_manifest( $descriptor );
		} catch ( \Throwable ) {
			$installed_verified = false;
		}
		if ( ! $installed_verified ) {
			$exact->preserve_backup();
			return 'unverified_install_result';
		}
		$exact->authorize_backup_deletion();
		$failure = $exact->finish_backup();
		if ( null !== $failure ) {
			return $failure;
		}
		$this->finalize_pending_install();
		return 'update_completed';
	}

	/** @param array<string,mixed> $offer */
	private function run_exact_upgrade( ExactInstallInvocation $exact, array $offer ): mixed {
		$transient              = 'plugin' === $this->target_type ? 'update_plugins' : 'update_themes';
		$hook                   = 'site_transient_' . $transient;
		$overlay                = function ( mixed $current ) use ( $exact, $offer ): mixed {
			if ( $exact->download_consumed || doing_action( 'shutdown' ) || ! $this->live() ) {
				return $current;
			}
			$copy             = is_object( $current ) ? get_object_vars( $current ) : array();
			$copy['response'] = is_array( $copy['response'] ?? null ) ? $copy['response'] : array();
			$copy['response'][ $this->installed_identity ] = 'plugin' === $this->target_type ? (object) $offer : $offer;
			return (object) $copy;
		};
		$this->authorized_offer = null;
		add_filter( $hook, $overlay, PHP_INT_MAX, 1 );
		add_filter( 'pre_set_site_transient_' . $transient, array( $this, 'filter_store_update' ), PHP_INT_MAX, 1 );
		try {
			return $exact->run( $this->installed_identity );
		} finally {
			remove_filter( $hook, $overlay, PHP_INT_MAX );
		}
	}

	/** @return array{status:string,code:string,version:?string,relationship:?string,finalization_pending:bool,finalization:?Finalization} */
	private function exact_report( string $code, ?ExactInstallInvocation $exact = null ): array {
		$version      = $exact?->descriptor?->to_array()['version'] ?? null;
		$finalization = $exact?->finalization;
		$pending      = null !== $exact && $exact === $this->exact_install;
		$code         = match ( true ) {
			'failed' === ( $finalization['archive_cleanup'] ?? null ) => 'archive_cleanup_failed',
			'failed' === ( $finalization['host_completion'] ?? null ) => 'host_completion_failed',
			'failed' === ( $finalization['lease'] ?? null )            => 'lease_release_failed',
			'update_completed' === $code && 'completed' !== ( $finalization['commit'] ?? null ) => 'install_commit_failed',
			default => $code,
		};
		return array(
			'status'               => 'update_completed' === $code ? 'installed' : ( $pending ? 'pending' : ( $exact?->wordpress_started ? 'failed' : 'refused' ) ),
			'code'                 => $code,
			'version'              => $version,
			'relationship'         => null === $version || null === $exact ? null : ReleaseVersion::relationship( $version, $exact->installed_version ),
			'finalization_pending' => $pending,
			'finalization'         => $finalization,
		);
	}

	private function disarm_exact_install(): void {
		if ( $this->exact_install?->lease_claimed && $this->shutdown_scheduled ) {
			remove_action( 'shutdown', array( $this, 'finalize_pending_install' ), PHP_INT_MAX );
			$this->shutdown_scheduled = false;
		}
		$this->exact_install = null;
	}

	/**
	 * Host-requested release check that reports facts and never offers a release.
	 *
	 * Unlike filter_update(), `disabled` still discovers here. Nothing is written to
	 * the WordPress update transient, no offer token is built and no install state is
	 * touched. `forced-off` is blocked before any filesystem, lease or network work.
	 * Another target's request, or a runtime that is not live, is returned unchanged.
	 *
	 * @return mixed Unchanged $result, or the check report array for this target.
	 */
	public function filter_check_releases( mixed $result, mixed $target_type, mixed $installed_identity ): mixed {
		if ( $target_type !== $this->target_type || ! is_string( $installed_identity ) || ! hash_equals( $this->installed_identity, $installed_identity ) ) {
			return $result;
		}
		$previous   = $this->status;
		$descriptor = $this->descriptor;
		$snapshot   = $this->discovery_snapshot;
		if ( ! $this->live() ) {
			return $result;
		}
		try {
			return $this->read_only_check();
		} finally {
			// The check leaves native status, descriptor and reusable discovery as the last native check left them.
			$this->status = $previous;
			if ( $this->live() ) {
				$this->descriptor         = $descriptor;
				$this->discovery_snapshot = $snapshot;
			} else {
				// A displaced runtime keeps no discovery state, exactly as a native call that finds it displaced.
				$this->descriptor                         = null;
				$this->status['offered_release_identity'] = null;
				$this->status['offered_version']          = null;
			}
		}
	}

	/** @return array{status:string,reason:string,policy:string,relationship:string|null,installed_version:string|null,candidate_version:string|null,candidate_validation_code:string|null,offered:false} */
	private function read_only_check(): array {
		$reason       = 'unexpected_failure';
		$check_id     = null;
		$this->status = self::empty_status();
		if ( null !== $this->exact_install || $this->native_beginning || null !== $this->native_owner || $this->pending_install->active() ) {
			return $this->check_report( 'runtime_busy' );
		}
		$installed                         = $this->installed_version();
		$this->status['installed_version'] = $installed;
		$this->status['last_check']        = time();
		if ( null === ReleaseVersion::normalize_header( $installed ) ) {
			$reason   = 'runtime_identity_invalid';
			$check_id = $this->start_check_observation();
		} elseif ( 'forced-off' === $this->policy ) {
			$reason   = 'policy_forced_off';
			$check_id = $this->start_check_observation();
		} else {
			$this->begin_operation();
			if ( ! $this->direct_filesystem() ) {
				$reason   = 'filesystem_unavailable';
				$check_id = $this->start_check_observation();
			} else {
				// Always discover afresh: a native snapshot must not answer, so every check is observed.
				$this->discovery_snapshot = null;
				$descriptor               = $this->discover(
					$installed,
					$reason,
					function () use ( &$check_id ): void {
						$check_id = $this->start_check_observation();
					}
				);
				if ( $descriptor instanceof IdentityDescriptor ) {
					$reason = $this->manual_eligible( $descriptor ) ? 'newer_release_observed' : 'candidate_not_eligible';
				}
			}
		}
		$report = $this->check_report( $reason );
		$this->complete_check_observation( $check_id, $reason );
		if ( ! $this->live() ) {
			$this->status = self::empty_status();
			return $this->check_report( 'runtime_liveness_lost' );
		}
		return $report;
	}

	/**
	 * @return array{status:string,reason:string,policy:string,relationship:string|null,installed_version:string|null,candidate_version:string|null,candidate_validation_code:string|null,offered:false}
	 */
	private function check_report( string $reason ): array {
		$payload = $this->check_facts( $reason );
		return array(
			'status'                    => $payload['status'],
			'reason'                    => $reason,
			'policy'                    => $this->policy,
			'relationship'              => $payload['relationship'],
			'installed_version'         => $payload['installed_version'],
			'candidate_version'         => $payload['candidate_version'],
			'candidate_validation_code' => $payload['candidate_validation_code'],
			'offered'                   => false,
		);
	}

	/**
	 * @return array{status:string,reason:string,relationship:string|null,installed_version:string|null,candidate_version:string|null,offered_version:string|null,candidate_validation_code:string|null}
	 */
	private function check_facts( string $reason ): array {
		$payload = array(
			'status' => 'failed',
			'reason' => $reason,
		);
		if ( in_array( $reason, array( 'update_available', 'newer_release_observed', 'no_candidates', 'no_newer_candidate' ), true ) ) {
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
		return $payload;
	}

	private function start_check_observation(): ?string {
		$this->listing_diagnostics  = null;
		$this->search_exhausted     = null;
		$this->candidate_rejections = array();
		$facts                      = $this->target_binding;
		if ( ! function_exists( 'do_action' ) || ! function_exists( 'get_current_blog_id' ) ) {
			return null;
		}
		foreach ( array(
			'provider_code'              => 32,
			'stable_repository_identity' => 191,
			'installed_package_identity' => 255,
		) as $key => $limit ) {
			if ( strlen( $facts[ $key ] ) > $limit ) {
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
				'binding_hash'        => $this->binding_hash,
			)
		);
		return $check_id;
	}

	private function complete_check_observation( ?string $check_id, string $reason ): void {
		if ( null === $check_id ) {
			return;
		}
		$payload  = array( 'check_id' => $check_id ) + $this->check_facts( $reason );
		$extended = $payload + array(
			'search_exhausted'     => $this->search_exhausted,
			'listing_diagnostics'  => $this->listing_diagnostics,
			'candidate_rejections' => $this->candidate_rejections,
		);
		self::observe_check( 'completed', $payload );
		do_action( 'ran_wp_release_updater_check_completed_v2', $extended );
	}

	/**
	 * @param 'started'|'completed' $stage
	 * @param array<string,mixed> $payload
	 */
	private static function observe_check( string $stage, array $payload ): void {
		do_action( 'ran_wp_release_updater_check_' . $stage . '_v1', $payload );
	}

	public function filter_plugin_information( mixed $result, string $action, mixed $arguments ): mixed {
		if ( 'plugin_information' !== $action || ! is_object( $arguments ) || $this->information_slug() !== ( $arguments->slug ?? null ) || null !== $this->exact_install || $this->policy_blocked() || ! $this->live() ) {
			return $result;
		}
		$previous_status              = $this->status;
		$previous_descriptor          = $this->descriptor;
		$previous_listing_diagnostics = $this->listing_diagnostics;
		$previous_search_exhausted    = $this->search_exhausted;
		$previous_rejections          = $this->candidate_rejections;
		try {
			$this->begin_operation();
			if ( ! $this->direct_filesystem() ) {
				return $result;
			}
			$descriptor = $this->discover( $this->installed_version() );
			if ( ! $descriptor instanceof IdentityDescriptor || ! $this->manual_eligible( $descriptor ) ) {
				return $result;
			}
			$facts               = $descriptor->to_array();
			$headers             = $this->candidate_headers_for( $descriptor );
			$info                = new \stdClass();
			$info->name          = $headers['Name'];
			$info->slug          = $this->information_slug();
			$info->version       = $facts['version'];
			$info->author        = $headers['Author'];
			$info->homepage      = $headers['PluginURI'];
			$info->requires      = $headers['RequiresWP'];
			$info->requires_php  = $headers['RequiresPHP'];
			$info->download_link = $this->token( $descriptor ) ?? '';
			$info->external      = true;
			$info->sections      = array(
				'description' => $headers['Description'],
				'changelog'   => '',
			);
			return $this->live() ? $info : false;
		} finally {
			// Modal rendering is read-only: native check status stays as the last native check left it.
			$this->status               = $previous_status;
			$this->listing_diagnostics  = $previous_listing_diagnostics;
			$this->search_exhausted     = $previous_search_exhausted;
			$this->candidate_rejections = $previous_rejections;
			if ( $this->live() ) {
				$this->descriptor = $previous_descriptor;
			} else {
				$this->descriptor                         = null;
				$this->status['offered_release_identity'] = null;
				$this->status['offered_version']          = null;
			}
		}
	}

	public function filter_auto_update( ?bool $update, mixed $item ): ?bool {
		if ( ! is_object( $item ) || ! $this->matches_item_identity( $item ) ) {
			return $update;
		}
		if ( null !== $this->exact_install ) {
			return false;
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
		if ( ! $this->matches_target_identity( $hook_extra ) ) {
			return $reply;
		}
		if ( null !== $this->exact_install ) {
			return $this->exact_pre_download( $this->exact_install, $reply, $package, $upgrader, $hook_extra );
		}
		if ( str_starts_with( $package, self::EXACT_TOKEN_PREFIX ) ) {
			return $this->failure( 'unverified_pre_download', false );
		}
		// Every download attempt for this target consumes the authorisation; a later attempt needs a new one.
		$held                   = null === $this->authorized_offer ? null : $this->authorized_fingerprint;
		$this->authorized_offer = null;
		if ( $this->policy_blocked() ) {
			return $reply instanceof \WP_Error ? $reply : $this->failure( 'policy_blocked', false );
		}
		if ( $this->native_beginning || null !== $this->native_owner || ! $this->live() ) {
			$fail_closed = null !== $this->operation_guard
				|| $this->native_beginning
				|| null !== $this->native_owner
				|| ( false === $reply && str_starts_with( $package, 'ran-wp-release-updater:' ) );
			return $fail_closed ? $this->failure( 'runtime_liveness_lost', false ) : $reply;
		}
		$this->clear_discovery_snapshot();
		if ( ! $this->matches_operation( $hook_extra ) ) {
			return false !== $reply && ! $reply instanceof \WP_Error ? $this->failure( 'unverified_pre_download_result', false ) : $reply;
		}
		$queued_multi_run       = $this->queued_multi_run;
		$this->queued_multi_run = false;
		if ( false !== $reply && ! $reply instanceof \WP_Error ) {
			return $this->failure( 'unverified_pre_download_result', false );
		}
		$this->clear_pending();
		$working_copy = $this->working_copy_blocked();
		$token        = $this->parse_token( $package );
		$authorized   = $token instanceof IdentityDescriptor && null !== $held && hash_equals( $held, $token->fingerprint_value() );
		if ( ! $this->direct_filesystem() || ! $token instanceof IdentityDescriptor || ! $this->descriptor_admitted( $token ) || ! $this->download_version_admitted( $token, $authorized ) || ! $this->manual_eligible( $token ) ) {
			return $reply instanceof \WP_Error ? $reply : $this->failure( $working_copy ? 'target_is_working_copy' : 'unverified_pre_download', false );
		}
		if ( ! $this->acquire_mutation_lock( $queued_multi_run ) ) {
			return $this->failure( 'binding_fence_lost', false );
		}
		$this->start_native_attempt( $token );
		if ( $reply instanceof \WP_Error ) {
			$this->observe_wordpress_error( $reply );
			$this->clear_pending();
			return $reply;
		}
		if ( $working_copy ) {
			return $this->fail_pending( 'target_is_working_copy' );
		}
		$fresh = $this->refresh_download_binding( $token, $authorized );
		if ( is_string( $fresh ) ) {
			return $this->fail_pending( $fresh );
		}
		$owned   = null;
		$failure = $this->acquire_owned_archive( $fresh, $owned );
		if ( null !== $failure || null === $owned ) {
			if ( is_array( $owned ) ) {
				$this->archive_store->remove( $owned['path'], $owned['directory'] );
			}
			return $this->fail_pending( $failure ?? 'acquisition_identity_invalid' );
		}
		$identity = $this->archive_store->identity( $owned['path'], $fresh );
		if ( null === $identity ) {
			$this->archive_store->remove( $owned['path'], $owned['directory'] );
			return $this->fail_pending( 'acquisition_identity_invalid' );
		}
		if ( ! $this->verify_mutation_lock() || ! $this->live() ) {
			$this->archive_store->remove( $owned['path'], $owned['directory'] );
			return $this->fail_pending( 'binding_fence_lost' );
		}
		try {
			if ( ! $this->validator->verify_archive_identity( $fresh, $owned['path'] ) ) {
				throw new InvalidArgumentException( 'The owned archive identity changed.' );
			}
			$proof = $this->validator->consume_receipt_proof( $owned['proof'], $fresh );
		} catch ( \Throwable ) {
			$this->archive_store->remove( $owned['path'], $owned['directory'] );
			return $this->fail_pending( 'acquisition_identity_invalid' );
		}
		$this->pending_install->begin( $owned['path'], $owned['directory'], $identity, $proof, multi_run: $queued_multi_run );
		$this->schedule_finalization();
		return $owned['path'];
	}

	/** @param array<string,mixed> $hook_extra */
	private function exact_pre_download( ExactInstallInvocation $exact, mixed $reply, string $package, mixed $upgrader, array $hook_extra ): mixed {
		if ( $exact->upgrader !== $upgrader || $exact->download_consumed ) {
			return $this->failure( 'unverified_pre_download', false );
		}
		$exact->download_consumed = true;
		$token                    = $exact->descriptor;
		$owned                    = $exact->archive;
		$identity                 = $exact->archive_identity;
		if ( null === $token || null === $owned || null === $identity || ! hash_equals( $exact->token, $package ) || ! $this->matches_operation( $hook_extra ) ) {
			return $this->exact_download_failure( $exact, 'unverified_pre_download' );
		}
		$this->start_native_attempt( $token );
		if ( $reply instanceof \WP_Error ) {
			$this->observe_wordpress_error( $reply );
			return $reply;
		}
		if ( false !== $reply || ! $this->direct_filesystem() ) {
			return $this->exact_download_failure( $exact, 'unverified_pre_download' );
		}
		if ( $this->working_copy_blocked() ) {
			return $this->exact_download_failure( $exact, 'target_is_working_copy' );
		}
		if ( ! $this->live() || ! $this->verify_mutation_lock() ) {
			return $this->exact_download_failure( $exact, 'binding_fence_lost' );
		}
		$fresh = $this->refresh_download_binding( $token, true );
		if ( is_string( $fresh ) ) {
			return $this->exact_download_failure( $exact, $fresh );
		}
		if ( $this->bound_package_version( $this->installed_directory, true ) !== $exact->installed_version ) {
			return $this->exact_download_failure( $exact, 'runtime_package_identity_invalid' );
		}
		if ( ! $this->verify_mutation_lock() || ! $this->live() ) {
			return $this->exact_download_failure( $exact, 'binding_fence_lost' );
		}
		if ( ! $this->archive_store->same_identity( $owned['path'], $fresh, $identity ) ) {
			return $this->exact_download_failure( $exact, 'acquisition_identity_invalid' );
		}
		try {
			$proof = $this->validator->consume_receipt_proof( $owned['proof'], $fresh );
		} catch ( \Throwable ) {
			return $this->exact_download_failure( $exact, 'acquisition_identity_invalid' );
		}
		$this->pending_install->begin( $owned['path'], $owned['directory'], $identity, $proof, multi_run: $upgrader instanceof \Plugin_Upgrader );
		$exact->archive          = null;
		$exact->archive_location = null;
		$exact->archive_identity = null;
		$this->queued_multi_run  = false;
		return $owned['path'];
	}

	private function exact_download_failure( ExactInstallInvocation $exact, string $code ): mixed {
		$exact->download_failure = $code;
		return $this->failure( $code );
	}

	private function start_native_attempt( IdentityDescriptor $descriptor ): void {
		$this->attempt_descriptor = $descriptor;
		$this->attempt_outcome    = array(
			'outcome'        => 'uncertain',
			'source'         => 'release_updater',
			'wordpress_code' => null,
		);
	}

	private function refresh_download_binding( IdentityDescriptor $token, bool $authorized ): IdentityDescriptor|string {
		try {
			$fresh = $this->adapter->inspect( $token->release_identity(), $token->to_array()['tag'] );
			self::assert_descriptor_binding( $fresh, $this->target_binding );
		} catch ( \Throwable ) {
			return 'acquisition_failed';
		}
		if ( $this->attempt_descriptor !== $token || ! $this->verify_mutation_lock() ) {
			return 'binding_fence_lost';
		}
		if ( ! $this->live() || ! $this->descriptor_admitted( $fresh ) || ! $this->download_version_admitted( $fresh, $authorized ) ) {
			return 'unverified_pre_download';
		}
		if ( ! hash_equals( $token->fingerprint_value(), $fresh->fingerprint_value() ) ) {
			return 'remote_release_changed';
		}
		$this->descriptor = $fresh;
		return $fresh;
	}

	/** @param OwnedCopy|null $owned */
	private function acquire_owned_archive( IdentityDescriptor $descriptor, ?array &$owned, ?ExactInstallInvocation $exact = null ): ?string {
		$artifact    = null;
		$on_allocate = null === $exact ? null : static function ( string $path, string $directory ) use ( $exact ): void {
			$exact->archive_location = array(
				'path'      => $path,
				'directory' => $directory,
			);
		};
		try {
			$artifact = $this->adapter->acquire( $descriptor );
			$artifact->inspect(
				function ( string $path ) use ( $descriptor, &$owned, $on_allocate ): void {
					$owned = $this->validated_copy( $path, $descriptor, $on_allocate );
				}
			);
			if ( $artifact->discard() ) {
				return null === $owned ? 'acquisition_identity_invalid' : null;
			}
		} catch ( \Throwable $error ) {
			if ( null !== $exact && $error instanceof ReleaseFailure && 'failed' === $error->cleanup_status ) {
				$exact->artifact_cleanup_failed = true;
			}
		}
		if ( null !== $artifact && ! $artifact->discard() && null !== $exact ) {
			$exact->artifact_cleanup_failed = true;
		}
		return 'acquisition_failed';
	}

	/** @param list<string> $needed_dirs */
	public function filter_pre_unzip_file( mixed $pre, string $file, string $destination, array $needed_dirs, float $required_space ): mixed {
		unset( $destination, $needed_dirs, $required_space );
		if ( ! $this->pending_install->active() || ! is_string( $this->pending_install->archive() ) || ! hash_equals( $this->pending_install->archive(), $file ) ) {
			return $pre;
		}
		if ( ! $this->live() ) {
			return $this->fail_pending( 'runtime_liveness_lost' );
		}
		$descriptor = $this->descriptor;
		if (
			! $this->direct_filesystem()
			|| ! $this->verify_mutation_lock()
			|| ! $descriptor instanceof IdentityDescriptor
			|| ! $this->descriptor_admitted( $descriptor )
			|| ! is_array( $this->pending_install->archive_identity() )
			|| ! $this->archive_store->same_identity( $file, $descriptor, $this->pending_install->archive_identity() )
			|| ! $this->pending_install->matches_descriptor( $descriptor )
		) {
			return $this->fail_pending( 'archive_changed_before_extraction' );
		}
		if ( ! $this->live() || ! $this->pending_install->admit_extraction() ) {
			return $this->fail_pending( 'archive_changed_before_extraction' );
		}
		return $pre;
	}

	/** @param array<string,mixed> $hook_extra */
	public function filter_source_selection( mixed $source, string $remote_source, mixed $upgrader, array $hook_extra ): mixed {
		unset( $remote_source );
		if ( ! $this->matches_operation( $hook_extra ) ) {
			return $source;
		}
		$this->observe_wordpress_error( $source );
		if ( ! $this->live() ) {
			if ( null === $this->operation_guard && ! $this->pending_install->active() ) {
				return $source;
			}
			return $this->fail_pending( 'runtime_liveness_lost' );
		}

		$manifest   = is_string( $source ) && $this->matches_staged_metadata( $source )
			? $this->manifest_builder->build( $source )
			: null;
		$descriptor = $this->descriptor;
		if (
			! $this->direct_filesystem()
			|| ! $this->pending_install->active()
			|| ! $this->pending_install->extraction_admitted()
			|| ! $this->verify_mutation_lock()
			|| ! $descriptor instanceof IdentityDescriptor
			|| ! $this->descriptor_admitted( $descriptor )
			|| ! is_string( $source )
			|| null === $manifest
			|| ! $this->pending_install->matches_archive_manifest(
				$descriptor,
				$this->manifest_builder->hash( $manifest ),
				count( $manifest ),
				$this->manifest_builder->expanded_bytes( $manifest )
			)
		) {
			return $this->fail_pending( 'staged_package_identity_invalid' );
		}

		if ( ! $this->pending_install->verify_staging() ) {
			return $this->fail_pending( 'staged_package_identity_invalid' );
		}
		if ( ! $this->live() || ! $this->descriptor_admitted( $descriptor ) ) {
			return $this->fail_pending( 'runtime_liveness_lost' );
		}
		if ( $this->working_copy_blocked() ) {
			return $this->fail_pending( 'target_is_working_copy' );
		}
		$had_native_owner = null !== $this->native_owner;
		if ( ! $this->begin_native_mutation() ) {
			return $this->fail_pending( 'runtime_liveness_lost', ! $had_native_owner && null !== $this->native_owner );
		}
		if ( $this->working_copy_blocked() ) {
			$this->diagnose( 'target_is_working_copy', null, true );
			$this->finalize_pending_install();
			return $this->failure( 'target_is_working_copy' );
		}
		$this->admit_theme_error_bridge( $upgrader, $hook_extra );
		$exact = $this->exact_install;
		if ( null !== $exact && $exact->upgrader === $upgrader ) {
			$exact->expect_incoming( $manifest );
		}
		return $source;
	}
	/** @param array<string,mixed> $hook_extra */
	public function filter_pre_install( mixed $response, array $hook_extra ): mixed {
		if ( ! $this->matches_operation( $hook_extra ) ) {
			return $response;
		}
		$this->observe_wordpress_error( $response );
		if ( ! $this->live() ) {
			if ( null === $this->operation_guard && ! $this->pending_install->active() ) {
				return $response;
			}
			return $this->fail_pending( 'runtime_liveness_lost' );
		}
		if ( $this->working_copy_blocked() ) {
			return $this->fail_pending( 'target_is_working_copy' );
		}
		$descriptor = $this->descriptor;
		if (
			! $this->direct_filesystem()
			|| ! $this->pending_install->active()
			|| ! $this->pending_install->extraction_admitted()
			|| ! $this->verify_mutation_lock()
			|| ! $descriptor instanceof IdentityDescriptor
			|| ! $this->descriptor_admitted( $descriptor )
			|| ! $this->pending_install->matches_descriptor( $descriptor )
			|| $response instanceof \WP_Error
		) {
			return $this->fail_pending( 'unverified_pre_install' );
		}
		if ( ! $this->live() ) {
			return $this->fail_pending( 'runtime_liveness_lost' );
		}
		return $response;
	}
	/** @param array<string,mixed> $hook_extra */
	public function capture_install_package_result( mixed $result, array $hook_extra ): mixed {
		if ( ! $this->matches_operation( $hook_extra ) || null !== $this->exact_install?->finalization ) {
			return $result;
		}
		$this->observe_wordpress_error( $result );
		if ( $result instanceof \WP_Error && 'ran_wp_release_updater_target_is_working_copy' === $result->get_error_code() ) {
			$this->clear_pending();
			return $result;
		}
		if ( null === $this->native_owner && ! $this->live() ) {
			if ( ! $this->pending_install->active() ) {
				return $result;
			}
			return $this->fail_pending( 'runtime_liveness_lost' );
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
			return $this->fail_pending( 'unverified_install_result' );
		}
		if ( ! $this->pending_install->capture_install_result( $result ) ) {
			return $this->fail_pending( 'unverified_install_result' );
		}
		if ( $this->pending_install->multi_run() && $this->lock_owned && $this->lock_multi_run && null !== $this->lock_token ) {
			$this->updater_lock->mark_multi_run_settled( $this->lock_token );
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
			&& $this->matches_theme_skin( $upgrader )
			&& array() === $upgrader->result
			&& $upgrader->skin->result === $this->theme_error_outgoing
		) {
			$upgrader->result = $this->theme_error_original;
		}
		$this->disarm_theme_error_bridge();
	}
	/** @param array<string,mixed> $hook_extra */
	public function observe_completion( mixed $upgrader, array $hook_extra ): void {
		if ( null !== $this->exact_install ) {
			$field = 'plugin' === $this->target_type ? 'plugins' : 'themes';
			if ( $this->exact_install->upgrader !== $upgrader || array( $this->installed_identity ) !== ( $hook_extra[ $field ] ?? array( $hook_extra[ $this->target_type ] ?? null ) ) ) {
				return;
			}
		}
		if ( ( null !== $this->native_owner || $this->live() ) && $this->matches_completion( $hook_extra ) ) {
			$this->clear_discovery_snapshot();
			$this->pending_install->observe_completion();
		}
	}
	/** @param array<string,mixed> $results */
	public function observe_automatic_results( array $results ): void {
		if ( null !== $this->attempt_outcome && is_array( $this->pending_install->install_result() ) && ( null !== $this->native_owner || $this->live() ) ) {
			$field   = 'plugin' === $this->target_type ? 'plugin' : 'theme';
			$entries = $results[ $this->target_type ] ?? array();
			foreach ( is_array( $entries ) ? $entries : array() as $entry ) {
				if ( is_object( $entry ) && is_object( $entry->item ?? null ) && ( $entry->item->$field ?? null ) === $this->installed_identity ) {
					$this->observe_wordpress_error( $entry->result ?? null );
				}
			}
		}
		if (
			null === $this->exact_install
			&& null === $this->native_mutation_begin
			&& ! $this->lock_owned
			&& null !== $this->lock_token
			&& $this->shutdown_scheduled
			&& $this->pending_install->active()
		) {
			$this->finalize_pending_install();
		}
	}
	private function observe_wordpress_error( mixed $result ): void {
		if ( null === $this->attempt_outcome || ! $result instanceof \WP_Error ) {
			return;
		}
		$code   = (string) $result->get_error_code();
		$prefix = 'ran_wp_release_updater_';
		if ( str_starts_with( $code, $prefix ) && RequestProtocolValidator::is_native_failure_code( substr( $code, strlen( $prefix ) ) ) ) {
			return;
		}
		$this->attempt_outcome = array(
			'outcome'        => 'unverified_install_result',
			'source'         => 'wordpress',
			'wordpress_code' => RequestProtocolValidator::screen_wordpress_code( $code ),
		);
	}
	/** Finalization is deliberately after Core rollback and backup cleanup. */
	public function finalize_pending_install(): void {
		$exact    = $this->exact_install;
		$shutdown = null !== $exact && doing_action( 'shutdown' );
		if ( ! $this->shutdown_scheduled || ( null !== $exact && ! $exact->backup_deleted && ! $shutdown ) ) {
			return;
		}
		if ( $shutdown ) {
			try {
				if ( ! $exact->recover_at_shutdown() ) {
					$this->diagnose( 'outcome_uncertain', null );
				}
			} catch ( \Throwable ) {
				$this->diagnose( 'outcome_uncertain', null );
			}
		}
		if ( $this->finalizing ) {
			if ( $shutdown ) {
				// The synchronous finalizer was interrupted. There is no second commit or host acknowledgement.
				$this->native_outcome = 'uncertain';
				$this->clear_pending();
				$this->disarm_exact_install();
			}
			return;
		}
		$this->finalizing = true;
		try {
			if ( null === $this->native_owner && ! $this->live() ) {
				if ( $this->pending_install->active() ) {
					$this->diagnose( 'runtime_liveness_lost', null, true );
				}
				return;
			}
			if ( ! $this->pending_install->active() ) {
				return;
			}
			// An interrupted exact install can still clean up, but only a proved Core return and backup deletion admit a commit.
			if ( null !== $exact && ! $exact->backup_deleted ) {
				$this->diagnose( 'outcome_uncertain', null );
				return;
			}
			$descriptor = $this->descriptor;
			if ( ! $descriptor instanceof IdentityDescriptor ) {
				$this->diagnose( 'outcome_uncertain', null );
				return;
			}
			if ( ! $this->descriptor_admitted( $descriptor ) ) {
				$this->diagnose( 'descriptor_admission_rejected', null, true );
				return;
			}
			if (
				! $this->pending_install->install_result_captured()
				|| ( ! $this->pending_install->completion_observed() && ! $this->pending_install->multi_run() )
				|| ! $this->verify_installed_manifest( $descriptor )
			) {
				$this->diagnose( 'outcome_uncertain', null );
				return;
			}
			// phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText -- Preserve the exact lowercase source wire value.
			if ( 'wordpress' !== ( $this->attempt_outcome['source'] ?? null ) ) {
				$this->native_outcome = 'verified_success';
				$this->sync_installed_headers( $descriptor );
			}
		} catch ( \Throwable $error ) {
			if ( null === $exact ) {
				throw $error;
			}
			$this->diagnose( 'outcome_uncertain', null );
		} finally {
			$this->clear_pending();
			$this->finalizing = false;
			if ( null === $exact && $this->shutdown_scheduled ) {
				if ( function_exists( 'remove_action' ) ) {
					remove_action( 'shutdown', array( $this, 'finalize_pending_install' ), PHP_INT_MAX );
				}
				$this->shutdown_scheduled = false;
			}
			if ( $shutdown ) {
				$this->disarm_exact_install();
			}
		}
	}

	private function verify_installed_manifest( IdentityDescriptor $descriptor ): bool {
		$result      = $this->pending_install->install_result();
		$destination = is_array( $result ) ? ( $result['destination'] ?? null ) : null;
		if ( ! is_string( $destination ) || ! $this->installed_destination_matches( $destination ) ) {
			return false;
		}
		$manifest_root = $this->registered_plugin_symlink_target( $destination ) ?? $destination;
		$manifest      = $this->manifest_builder->build( $manifest_root );
		if ( ! $this->verify_mutation_lock() || null === $manifest ) {
			return false;
		}
		if (
			! $this->pending_install->matches_archive_manifest(
				$descriptor,
				$this->manifest_builder->hash( $manifest ),
				count( $manifest ),
				$this->manifest_builder->expanded_bytes( $manifest )
			)
		) {
			return false;
		}
		return $this->pending_install->staging_verified() && $this->matches_staged_metadata( $destination, null, true ) && $this->installed_destination_matches( $destination );
	}

	private function installed_destination_matches( string $destination ): bool {
		$destination = rtrim( $destination, '/\\' );
		$registered  = rtrim( $this->installed_directory, '/\\' );
		if ( null !== $this->exact_install && wp_normalize_path( $registered ) !== wp_normalize_path( $destination ) ) {
			return false;
		}
		clearstatcache( true );
		$resolved = realpath( $destination );
		if ( false === $resolved || realpath( $registered ) !== $resolved ) {
			return false;
		}
		if ( null !== $this->exact_install ) {
			return $this->exact_install->destination_has_original_parent( $destination );
		}
		// Hosts register the PHP-resolved package path while Core reports it beneath the unresolved plugin or theme
		// root, so ordinary installs compare resolved directories and only admit a linked leaf registered in $wp_plugin_paths.
		return is_dir( $destination ) && ( ! is_link( $destination ) || null !== $this->registered_plugin_symlink_target( $destination ) );
	}

	/** @return list<string> */ public function diagnostics(): array {
		return $this->diagnostics; }
	/** @return array{candidate_header_version:string|null,candidate_tag:string|null,candidate_validation_code:string|null,candidate_version:string|null,failure_code:string|null,installed_version:string|null,last_check:int|null,offered_release_identity:string|null,offered_version:string|null,relationship:string|null,last_native_outcome:array<string,mixed>|null} */ public function status(): array {
		$outcome = ! $this->selected_runtime_state instanceof SelectedRuntimeState || $this->selected_runtime_state->broker_is_live()
			? $this->last_native_outcome : null;
		return array_merge( $this->status, array( 'last_native_outcome' => $outcome ) );
	}
	public function refresh(): bool {
		if ( null !== $this->exact_install || $this->native_beginning || null !== $this->native_owner ) {
			return false;
		}
		$this->authorized_offer        = null;
		$this->authorized_fingerprint  = null;
		$this->authorized_fingerprints = array();
		$this->candidate_headers       = array();
		if ( ! $this->live() ) {
			$this->clear_pending();
			return false;
		}
		$this->diagnostics = array();
		$this->status      = self::empty_status();
		$this->clear_discovery_snapshot();
		$this->queued_multi_run = false;
		$this->clear_pending();
		// WordPress caches the whole update check in this site transient and answers from it
		// for up to twelve hours, so without this the next check would never reach the provider.
		if ( function_exists( 'delete_site_transient' ) ) {
			delete_site_transient( 'plugin' === $this->target_type ? 'update_plugins' : 'update_themes' );
		}
		return true;
	}

	/** @phpstan-impure Host callbacks can change target markers between checks. */
	private function working_copy_blocked(): bool {
		return ! $this->allow_working_copy && TargetWorkingCopy::is_working_copy( $this->installed_directory );
	}

	/** `disabled` and `forced-off` admit no discovery, lease, provider work or download token on any path. */
	private function policy_blocked(): bool {
		return in_array( $this->policy, array( 'disabled', 'forced-off' ), true );
	}

	/** @phpstan-impure The runtime and host authority can change during callbacks. */
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
		$proof                  = $this->pending_install->archive_proof();
		try {
			$owner = ( $this->native_mutation_begin )( $this->target_type, $this->installed_identity );
			if ( ! is_string( $owner ) || 1 !== preg_match( '/\A[^\x00-\x20\x7f]{1,191}\z/D', $owner ) ) {
				return false;
			}
			$this->native_owner = $owner;
			return $this->live() && $this->pending_install->staging_verified() && $proof === $this->pending_install->archive_proof() && $this->verify_mutation_lock();
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

	/**
	 * @param array<string,mixed> $configuration
	 * @param TargetBinding       $facts
	 */
	private static function valid_configuration( array $configuration, array $facts ): bool {
		if ( ! self::exact_keys( $configuration, self::CONFIGURATION_KEYS ) || ! is_string( $configuration['installed_directory'] ) || ! is_array( $configuration['headers'] ) || ! self::exact_keys( $configuration['headers'], self::HEADER_KEYS ) || ! is_string( $configuration['update_uri'] ) || ! in_array( $configuration['policy'], array( 'disabled', 'forced-off', 'manual', 'automatic' ), true ) || ! self::valid_native_identity( $configuration['target_type'], $configuration['installed_package_identity'] ) ) {
			return false;
		}
		$directory = str_replace( '\\', '/', $configuration['installed_directory'] );
		if ( ! str_starts_with( $directory, '/' ) && 1 !== preg_match( '/\A[A-Za-z]:\//D', $directory ) ) {
			return false;
		}
		foreach ( $configuration['headers'] as $header ) {
			if ( ! is_string( $header ) || strlen( $header ) > 8192 ) {
				return false;
			}
		}
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
				'archive_preflight' => $this->target_binding['canonical_update_uri'],
				'configuration'     => $this->update_uri,
				'offer'             => $runtime_uri,
				'staged_package'    => $this->headers['UpdateURI'],
			)
		) === $this->update_uri;
	}
	private function manual_eligible( IdentityDescriptor $descriptor ): bool {
		$facts = $descriptor->to_array()['assurance_facts'];
		foreach ( array( 'exact_artifact_identity', 'exact_commit_identity', 'exact_reacquisition_supported', 'exact_release_identity', 'repository_identity_stable', 'trusted_digest_source' ) as $fact ) {
			if ( true !== $facts[ $fact ] ) {
				return false;
			}
		} return true; }
	/** @phpstan-impure Admission is a live host decision. */
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
	private function installed_version(): string {
		return $this->headers['Version'];
	}
	private function remember_candidate_headers( IdentityDescriptor $descriptor, ValidatedPackage $package ): void {
		$snapshot     = $package->to_array();
		$author       = is_string( $snapshot['author'] ?? null ) && '' !== $snapshot['author'] ? $snapshot['author'] : $this->headers['Author'];
		$description  = is_string( $snapshot['description'] ?? null ) && '' !== $snapshot['description'] ? $snapshot['description'] : $this->headers['Description'];
		$name         = is_string( $snapshot['metadata_name'] ?? null ) && '' !== $snapshot['metadata_name'] ? $snapshot['metadata_name'] : $this->headers['Name'];
		$plugin_uri   = is_string( $snapshot['package_uri'] ?? null ) && '' !== $snapshot['package_uri'] ? $snapshot['package_uri'] : $this->headers['PluginURI'];
		$requires_php = is_string( $snapshot['requires_php'] ?? null ) ? $snapshot['requires_php'] : $this->headers['RequiresPHP'];
		$requires_wp  = is_string( $snapshot['requires_wp'] ?? null ) ? $snapshot['requires_wp'] : $this->headers['RequiresWP'];
		$this->candidate_headers[ $descriptor->fingerprint_value() ] = array(
			'Author'      => $author,
			'Description' => $description,
			'Name'        => $name,
			'PluginURI'   => $plugin_uri,
			'RequiresPHP' => $requires_php,
			'RequiresWP'  => $requires_wp,
		);
	}
	/** @return array{Author:string,Description:string,Name:string,PluginURI:string,RequiresPHP:string,RequiresWP:string} */
	private function candidate_headers_for( IdentityDescriptor $descriptor ): array {
		$fingerprint = $descriptor->fingerprint_value();
		if ( isset( $this->candidate_headers[ $fingerprint ] ) ) {
			return $this->candidate_headers[ $fingerprint ];
		}
		return array(
			'Author'      => $this->headers['Author'],
			'Description' => $this->headers['Description'],
			'Name'        => $this->headers['Name'],
			'PluginURI'   => $this->headers['PluginURI'],
			'RequiresPHP' => $this->headers['RequiresPHP'],
			'RequiresWP'  => $this->headers['RequiresWP'],
		);
	}
	private function sync_installed_headers( IdentityDescriptor $descriptor ): void {
		$headers                      = $this->candidate_headers_for( $descriptor );
		$this->headers['Version']     = $descriptor->to_array()['version'];
		$this->headers['Author']      = $headers['Author'];
		$this->headers['Description'] = $headers['Description'];
		$this->headers['Name']        = $headers['Name'];
		$this->headers['PluginURI']   = $headers['PluginURI'];
		$this->headers['RequiresPHP'] = $headers['RequiresPHP'];
		$this->headers['RequiresWP']  = $headers['RequiresWP'];
	}
	private function newer_than_installed( IdentityDescriptor $descriptor ): bool {
		return ReleaseVersion::RELATIONSHIP_NEWER === ReleaseVersion::relationship( $descriptor->to_array()['version'], $this->installed_version() ); }
	/** Newer, or any valid version when the attempt's token is the release authorised for this request. */
	private function download_version_admitted( IdentityDescriptor $descriptor, bool $authorized ): bool {
		return $this->newer_than_installed( $descriptor ) || ( $authorized && ReleaseVersion::RELATIONSHIP_INVALID !== ReleaseVersion::relationship( $descriptor->to_array()['version'], $this->installed_version() ) ); }
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
	private function matches_staged_metadata( string $source, ?string $expected_version = null, bool $allow_registered_plugin_path = false ): bool {
		$version            = $this->bound_package_version( $source, $allow_registered_plugin_path );
		$expected_version ??= $this->descriptor instanceof IdentityDescriptor
			? $this->descriptor->to_array()['version']
			: null;
		return null !== $version && null !== $expected_version && 0 === ReleaseVersion::compare( $version, $expected_version );
	}

	private function registered_plugin_symlink_target( string $path ): ?string {
		if ( 'plugin' !== $this->target_type || ! is_array( $GLOBALS['wp_plugin_paths'] ?? null ) ) {
			return null;
		}
		$trimmed  = rtrim( $path, '/\\' );
		$resolved = realpath( $trimmed );
		if ( false === $resolved || ! is_dir( $resolved ) ) {
			return null;
		}
		$normalized_path = str_replace( '\\', '/', $trimmed );
		$parent_resolved = realpath( dirname( $trimmed ) );
		$is_link         = is_link( $trimmed );
		foreach ( $GLOBALS['wp_plugin_paths'] as $logical => $actual ) {
			if ( ! is_string( $logical ) || ! is_string( $actual ) || '' === $logical || '' === $actual ) {
				continue;
			}
			$logical_trimmed = rtrim( $logical, '/\\' );
			$logical_norm    = str_replace( '\\', '/', $logical_trimmed );
			if ( ! hash_equals( $this->package_root(), basename( $logical_norm ) ) ) {
				continue;
			}
			$actual_resolved = realpath( $actual );
			if ( false === $actual_resolved || $actual_resolved !== $resolved ) {
				continue;
			}
			if ( ! $is_link ) {
				return $actual_resolved;
			}
			$logical_parent = realpath( dirname( $logical_trimmed ) );
			if (
				$normalized_path === $logical_norm
				|| ( false !== $parent_resolved && $parent_resolved === $logical_parent && hash_equals( basename( $logical_norm ), basename( $normalized_path ) ) )
			) {
				return $actual_resolved;
			}
		}
		return null;
	}

	private function bound_package_version( string $source, bool $allow_registered_plugin_path = false ): ?string {
		$source            = rtrim( $source, '/\\' );
		$registered_target = $allow_registered_plugin_path ? $this->registered_plugin_symlink_target( $source ) : null;
		$inspect_directory = $registered_target ?? $source;
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Observe native filesystem identity without following a replacement abstraction; missing or changed facts fail existing validation.
		$directory = @lstat( $inspect_directory );
		if (
			! is_array( $directory )
			|| 0040000 !== ( $directory['mode'] & 0170000 )
			|| ( null === $registered_target && ! hash_equals( $this->package_root(), basename( $inspect_directory ) ) )
		) {
			return null;
		}

		$header = 'plugin' === $this->target_type ? basename( $this->installed_identity ) : 'style.css';
		$path   = $inspect_directory . DIRECTORY_SEPARATOR . $header;
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Observe native filesystem identity without following a replacement abstraction; missing or changed facts fail existing validation.
		$file = @lstat( $path );
		if ( ! is_array( $file ) || 0100000 !== ( $file['mode'] & 0170000 ) ) {
			return null;
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read the bounded staged header locally; unreadable content returns the existing metadata rejection.
		$contents = @file_get_contents( $path, false, null, 0, 8192 );
		if ( ! is_string( $contents ) ) {
			return null;
		}

		$parsed = PackageIdentityValidator::parse_header( $contents, $this->target_type );
		if ( 'installed_header_verified' !== $parsed['code'] || ! isset( $parsed['headers'] ) ) {
			return null;
		}

		$headers = $parsed['headers'];
		return hash_equals( $this->headers['Name'], $headers['Name'] )
			&& hash_equals( $this->target_binding['theme_template'], $headers['Template'] )
			&& $this->matches_runtime_uri( $headers['UpdateURI'] )
			? $headers['Version'] : null;
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
	private function acquire_mutation_lock( bool $multi_run ): bool {
		if ( null !== $this->native_mutation_begin ) {
			return true;
		}
		if ( function_exists( 'doing_action' ) && doing_action( 'wp_maybe_auto_update' ) ) {
			$token = $this->updater_lock->current_token();
			if ( null === $token ) {
				return false;
			}
			$this->lock_token     = $token;
			$this->lock_owned     = false;
			$this->lock_multi_run = false;
			return true;
		}
		$token = $multi_run ? $this->updater_lock->acquire_multi_run() : $this->updater_lock->acquire();
		if ( null === $token ) {
			return false;
		}
		$this->lock_token     = $token;
		$this->lock_owned     = true;
		$this->lock_multi_run = $multi_run;
		return true;
	}
	/** @phpstan-impure The updater lock row in wp_options may change between reads. */
	private function verify_mutation_lock(): bool {
		if ( null !== $this->native_mutation_begin ) {
			return true;
		}
		return null !== $this->lock_token && $this->updater_lock->verify( $this->lock_token );
	}
	/**
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
		$discovery_epoch = $this->discovery_epoch;
		try {
			$listed     = $this->adapter->list_releases();
			$candidates = $listed['candidates'] ?? null;
		} catch ( \Throwable ) {
			$reason                                    = 'release_list_failed';
			$this->status['candidate_validation_code'] = 'release_list_failed';
			return null;
		}
		$diagnostics               = $listed['diagnostics'] ?? null;
		$this->listing_diagnostics = ReleaseRejection::valid_diagnostics( $diagnostics ) && is_array( $diagnostics ) ? $diagnostics : null;
		$this->search_exhausted    = is_bool( $listed['search_exhausted'] ?? null ) ? $listed['search_exhausted'] : null;
		$rate_limit                = $listed['rate_limit'] ?? null;
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
					$rejected                                  = true;
					$reason                                    = 'candidate_invalid';
					$this->status['candidate_validation_code'] = 'candidate_invalid';
				} else {
					$this->status['candidate_validation_code'] = 'candidate_not_newer';
				}
				continue; }
			$rejected = true;
			$reason   = 'candidate_rejected';
			try {
				$descriptor = $this->adapter->inspect( $candidate['release_identity'], $candidate['tag'] );
				self::assert_descriptor_binding( $descriptor, $this->target_binding );
			} catch ( ReleaseFailure $failure ) {
				$this->retain_candidate_rejection( $candidate, $failure->rejection_reason );
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
			$admitted = $this->admit_candidate( $descriptor, $candidate, $reason );
			if ( true !== $admitted ) {
				if ( false === $admitted ) {
					continue;
				}
				return null;
			}
			$facts                                    = $descriptor->to_array();
			$this->status['candidate_header_version'] = $facts['version'];
			$this->descriptor                         = $descriptor;
			if ( $this->native_discovery_reuse && $this->live() && $this->discovery_epoch === $discovery_epoch ) {
				$this->discovery_snapshot = array(
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
	/**
	 * Admits an inspected, bound release as discovery admits a candidate: host admission, exact descriptor
	 * match, acquisition, archive validation and discard, then host admission again.
	 *
	 * @param array{release_identity:string,tag:string,version:string} $candidate
	 * @return bool|null True when admitted, false when another candidate may be tried, null when discovery must stop.
	 */
	private function admit_candidate( IdentityDescriptor $descriptor, array $candidate, string &$reason ): ?bool {
		if ( ! $this->descriptor_admitted( $descriptor ) ) {
			$reason                                    = 'candidate_rejected';
			$this->status['candidate_validation_code'] = 'candidate_admission_rejected';
			return false;
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
			$this->retain_candidate_rejection( $candidate, $failure->rejection_reason );
			$reason                                    = 'candidate_validation_failed';
			$this->status['candidate_validation_code'] = 'candidate_validation_failed';
			if ( $artifact instanceof \RAN\WPReleaseUpdater\V1\Archive\TemporaryArtifact && ! $artifact->discard() ) {
				return null;
			}
			if ( self::can_reject_candidate( $failure ) ) {
				$reason = 'candidate_rejected';
				return false;
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
			return false;
		}
		if ( ! $artifact->discard() ) {
			$reason                                    = 'candidate_validation_failed';
			$this->status['candidate_validation_code'] = 'candidate_validation_failed';
			return null;
		}
		if ( ! $this->descriptor_admitted( $descriptor ) ) {
			$reason                                    = 'candidate_rejected';
			$this->status['candidate_validation_code'] = 'candidate_admission_rejected';
			return false;
		}
		$this->remember_candidate_headers( $descriptor, $valid );
		return true;
	}
	/** @param array<string,mixed> $candidate */
	private function retain_candidate_rejection( array $candidate, ?string $reason ): void {
		if ( null !== $reason && ReleaseRejection::valid( $reason ) && count( $this->candidate_rejections ) < 8 ) {
			$this->candidate_rejections[] = ReleaseRejection::sample( $candidate['release_identity'], $candidate['tag'], $reason );
		}
	}

	private static function can_reject_candidate( ReleaseFailure $failure ): bool {
		return in_array( $failure->release_code, array( 'release_unavailable', 'package_incompatible' ), true )
			&& in_array( $failure->cleanup_status, array( 'not_applicable', 'complete' ), true );
	}
	private function reusable_discovery( string $installed ): ?IdentityDescriptor {
		if ( ! $this->live() || ! $this->native_discovery_reuse || ! is_array( $this->discovery_snapshot ) || $this->discovery_snapshot['installed'] !== $installed ) {
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
			'binding_hash' => $this->binding_hash,
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
			if ( ! is_string( $value['binding_hash'] ) || ! hash_equals( $this->binding_hash, $value['binding_hash'] ) ) {
				return null;
			}
			$descriptor = IdentityDescriptor::rehydrate( $value['descriptor'] );
			self::assert_descriptor_binding( $descriptor, $this->target_binding );
			return $descriptor;
		} catch ( \Throwable ) {
			return null; }
	}
	/**
	 * @param (\Closure(string,string): void)|null $on_allocate
	 * @return OwnedCopy|null
	 */
	private function validated_copy( string $path, IdentityDescriptor $descriptor, ?\Closure $on_allocate = null ): ?array {
		try {
			$proof = $this->validator->validate( $descriptor, $this->archive_policy, $path );
		} catch ( \Throwable ) {
			return null;
		}
		if ( ! $proof->is_valid() ) {
			return null;
		}
		$this->remember_candidate_headers( $descriptor, $proof );
		$copy = $this->archive_store->copy( $path, $descriptor, $on_allocate );
		return null === $copy ? null : array_merge( $copy, array( 'proof' => $proof ) );
	}
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
			|| ! $this->matches_theme_skin( $upgrader )
			|| ( null === $this->native_owner && $this->exact_install?->upgrader !== $upgrader )
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
			&& ( null !== $this->native_owner || $this->exact_install?->upgrader === $upgrader )
			&& $this->pending_install->active()
			&& ! $this->pending_install->multi_run()
			&& $this->matches_theme_skin( $upgrader )
			&& function_exists( 'doing_action' )
			&& ! doing_action( 'wp_maybe_auto_update' )
			&& null === $this->theme_error_original
			&& null === $this->theme_error_outgoing
			&& $upgrader->result === $result;
	}
	private function matches_theme_skin( \Theme_Upgrader $upgrader ): bool {
		return $upgrader->skin instanceof \Theme_Upgrader_Skin
			&& ( ( \Theme_Upgrader::class === get_class( $upgrader ) && \Theme_Upgrader_Skin::class === get_class( $upgrader->skin ) )
			|| ( $this->exact_install?->upgrader === $upgrader && $this->exact_install->uses_original_skin() ) );
	}
	private function disarm_theme_error_bridge(): void {
		$this->theme_error_upgrader = null;
		$this->theme_error_original = null;
		$this->theme_error_outgoing = null;
	}
	private function clear_pending( bool $release = true, bool $force = false ): void {
		$exact    = $this->exact_install;
		$shutdown = null !== $exact && doing_action( 'shutdown' );
		if ( null !== $exact?->finalization
			|| ( $this->native_beginning && ( ! $shutdown || ! $this->finalizing ) )
			|| ( ! $force && ! $this->finalizing && ( null !== $this->native_owner || ( null !== $exact && $this->pending_install->staging_verified() ) ) )
		) {
			return;
		}
		$owner      = $this->native_owner;
		$archive    = $this->pending_install->archive() ?? $exact?->archive_location['path'] ?? null;
		$directory  = $this->pending_install->archive_directory() ?? $exact?->archive_location['directory'] ?? null;
		$descriptor = $this->attempt_descriptor;
		$record     = null === $descriptor || null === $this->attempt_outcome ? null : array_merge(
			$this->attempt_outcome,
			array(
				'release_identity'   => $descriptor->release_identity(),
				'version'            => $descriptor->to_array()['version'],
				'last_observed_step' => $this->pending_install->last_observed_step(),
				'recorded_at'        => time(),
			)
		);
		$release    = $release || null !== $record;
		$lock_token = $this->lock_token;
		$lock_owned = $this->lock_owned;
		$lock_multi = $this->lock_multi_run;
		$this->clear_discovery_snapshot();
		$this->disarm_theme_error_bridge();
		$this->archive_store->remove( $archive, $directory );
		clearstatcache();
		$clean = ( null === $archive || ( ! file_exists( $archive ) && ! is_link( $archive ) ) )
			&& ( null === $directory || ( ! file_exists( $directory ) && ! is_link( $directory ) ) );
		if ( null !== $exact ) {
			if ( ! $clean && ! $shutdown ) {
				$store = $this->archive_store;
				add_action(
					'shutdown',
					static function () use ( $store, $archive, $directory ): void {
						$store->remove( $archive, $directory );
					},
					PHP_INT_MAX,
					0
				);
			}
			$clean                   = $clean && ! $exact->artifact_cleanup_failed;
			$exact->archive          = null;
			$exact->archive_location = null;
			$exact->archive_identity = null;
		}
		$candidate        = $this->native_outcome;
		$outcome          = $clean ? $candidate : 'uncertain';
		$host             = 'not_applicable';
		$lease            = 'not_held';
		$release_fallback = null;
		if ( null !== $exact && ! $shutdown && $release && $lock_owned && null !== $lock_token ) {
			// Keep the exact lock token available if a host callback interrupts this request.
			$release_fallback = function () use ( $lock_token, $lock_multi ): void {
				try {
					if ( $lock_multi ) {
						$this->updater_lock->release_multi_run( $lock_token );
					} else {
						$this->updater_lock->release( $lock_token );
					}
				} catch ( \Throwable ) {
					$this->diagnose( 'outcome_uncertain', null );
				}
			};
			add_action( 'shutdown', $release_fallback, PHP_INT_MAX, 0 );
		}
		$this->pending_install->clear();
		$this->attempt_descriptor = null;
		$this->attempt_outcome    = null;
		$this->native_owner       = null;
		$this->native_outcome     = 'uncertain';
		if ( $release ) {
			$this->lock_token     = null;
			$this->lock_owned     = false;
			$this->lock_multi_run = false;
		}
		// Finish private cleanup and the consumer callback before releasing the updater lock.
		if ( null !== $owner && null !== $this->native_mutation_complete ) {
			try {
				( $this->native_mutation_complete )( $owner, $this->target_type, $this->installed_identity, $outcome );
				$host = 'completed';
			} catch ( \Throwable ) {
				$outcome = 'uncertain';
				$host    = 'failed';
			}
		}
		if ( $release && $lock_owned && null !== $lock_token ) {
			try {
				$released = $lock_multi
					? $this->updater_lock->release_multi_run( $lock_token )
					: $this->updater_lock->release( $lock_token );
				$lease    = $released ? 'released' : 'failed';
			} catch ( \Throwable $error ) {
				if ( null === $exact ) {
					throw $error;
				}
				$lease = 'failed';
			}
			$outcome = 'released' === $lease ? $outcome : 'uncertain';
		}
		if ( null !== $release_fallback && 'released' === $lease ) {
			remove_action( 'shutdown', $release_fallback, PHP_INT_MAX );
		}
		if ( null !== $record ) {
			if ( 'verified_success' === $outcome ) {
				$record['outcome'] = 'verified_success';
			} elseif ( 'verified_success' === $candidate ) {
				$record['outcome'] = 'uncertain';
			}
			$this->last_native_outcome = $record;
		}
		if ( null !== $exact ) {
			$exact->finalization = array(
				'commit'          => 'verified_success' === $candidate ? 'completed' : ( $exact->backup_deleted ? 'failed' : 'not_attempted' ),
				'host_completion' => $host,
				'lease'           => $lease,
				'archive_cleanup' => $clean ? 'clean' : 'failed',
			);
		}
		if ( null !== $owner || 'verified_success' === $candidate ) {
			$this->diagnose( 'verified_success' === $outcome ? 'update_completed' : 'outcome_uncertain', null );
		}
	}
	private function fail_pending( string $code, bool $force = false ): mixed {
		$error = $this->failure( $code );
		$this->clear_pending( true, $force );
		return $error;
	}
	private function diagnose( string $code, mixed $return_value, bool $record_attempt = false ): mixed {
		// Final uncertainty must not erase a refusal whose cause was already observed.
		if ( $record_attempt && null !== $this->attempt_outcome && 'release_updater' === $this->attempt_outcome['source'] && RequestProtocolValidator::is_native_failure_code( $code )
			&& ( 'outcome_uncertain' !== $code || 'uncertain' === $this->attempt_outcome['outcome'] ) ) {
			$this->attempt_outcome['outcome'] = 'outcome_uncertain' === $code ? 'uncertain' : $code;
		}
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
	private function failure( string $code, bool $record_attempt = true ): mixed {
		$this->diagnose( $code, null, $record_attempt );
		return class_exists( '\\WP_Error' ) ? new \WP_Error( 'ran_wp_release_updater_' . $code, 'The update operation was not admitted.' ) : false; }
	private function direct_filesystem(): bool {
		return SelectedRuntimeState::direct_filesystem_available(); }
	private static function valid_native_identity( mixed $type, mixed $identity ): bool {
		if ( ! is_string( $identity ) ) {
			return false;
		} if ( 'theme' === $type ) {
			return PackageDirectory::is_valid_name( $identity );
		}
		$parts = explode( '/', $identity );
		return 'plugin' === $type && 2 === count( $parts ) && PackageDirectory::is_valid_name( $parts[0] )
			&& 1 === preg_match( '/\A[A-Za-z0-9][A-Za-z0-9._-]{0,99}\.php\z/D', $parts[1] );
	}
	/** @param list<string> $keys */ private static function exact_keys( mixed $value, array $keys ): bool {
		if ( ! is_array( $value ) || count( $value ) !== count( $keys ) ) {
			return false;
		} foreach ( $keys as $key ) {
			if ( ! array_key_exists( $key, $value ) ) {
				return false;
			}
		} return true; }
}
