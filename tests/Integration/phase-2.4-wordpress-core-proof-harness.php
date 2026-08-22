<?php

$sourceRoot = getenv( 'RAN_WP_RELEASE_UPDATER_SOURCE_ROOT' );
$markerFile = getenv( 'RAN_WP_RELEASE_UPDATER_MARKER_FILE' );
$markerRoot = $markerFile ? realpath( dirname( $markerFile ) ) : false;
$expectedSourceRoot = is_string( $markerRoot ) ? $markerRoot . '/site/wp-content/plugins/ran-wp-release-updater' : '';
if ( ! is_string( $sourceRoot ) || $expectedSourceRoot !== realpath( $sourceRoot ) || ! is_file( $expectedSourceRoot . '/bootstrap.php' ) || ! is_file( $expectedSourceRoot . '/runtime.php' ) ) throw new RuntimeException( 'Harness source must be the copied disposable updater source.' );
require_once $expectedSourceRoot . '/bootstrap.php';
require_once $expectedSourceRoot . '/runtime.php';

use RAN\WPReleaseUpdater\V1\Archive\PackageIdentityValidator;
use RAN\WPReleaseUpdater\V1\Contract\AcquisitionReceipt;
use RAN\WPReleaseUpdater\V1\Contract\BindingRecord;
use RAN\WPReleaseUpdater\V1\Contract\CanonicalUpdateUri;
use RAN\WPReleaseUpdater\V1\Contract\IdentityDescriptor;
use RAN\WPReleaseUpdater\V1\WordPress\BindingState;
use RAN\WPReleaseUpdater\V1\WordPress\NativePluginUpdater;
use RAN\WPReleaseUpdater\V1\WordPress\ReleaseOperationCoordinator;

if ( ! function_exists( 'is_plugin_active' ) ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
}
if ( ! class_exists( 'Plugin_Upgrader' ) ) {
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/misc.php';
	require_once ABSPATH . 'wp-admin/includes/template.php';
	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
	require_once ABSPATH . 'wp-admin/includes/class-plugin-upgrader.php';
	require_once ABSPATH . 'wp-admin/includes/class-theme-upgrader.php';
}

add_filter( 'filesystem_method', static fn () => 'direct' );

$sourceRoot  = getenv( 'RAN_WP_RELEASE_UPDATER_SOURCE_ROOT' );
$outputPath  = getenv( 'RAN_WP_RELEASE_UPDATER_OUTPUT' );
$pluginId    = getenv( 'RAN_WP_RELEASE_UPDATER_PLUGIN_ID' );
$themeId     = getenv( 'RAN_WP_RELEASE_UPDATER_THEME_ID' );
$pluginUri   = getenv( 'RAN_WP_RELEASE_UPDATER_PLUGIN_URI' );
$themeUri    = getenv( 'RAN_WP_RELEASE_UPDATER_THEME_URI' );
$pluginArchive = getenv( 'RAN_WP_RELEASE_UPDATER_PLUGIN_ARCHIVE' );
$themeArchive  = getenv( 'RAN_WP_RELEASE_UPDATER_THEME_ARCHIVE' );
$pluginFailureArchive = getenv( 'RAN_WP_RELEASE_UPDATER_PLUGIN_FAILURE_ARCHIVE' );
$themeFailureArchive  = getenv( 'RAN_WP_RELEASE_UPDATER_THEME_FAILURE_ARCHIVE' );
$marker      = getenv( 'RAN_WP_RELEASE_UPDATER_PHASE24' );
$markerFile  = getenv( 'RAN_WP_RELEASE_UPDATER_MARKER_FILE' );
$mode        = getenv( 'RAN_WP_RELEASE_UPDATER_MODE' );
$targetType  = getenv( 'RAN_WP_RELEASE_UPDATER_TARGET_TYPE' );

$markerRoot = $markerFile ? realpath( dirname( $markerFile ) ) : false;
if ( 'RAN_WP_RELEASE_UPDATER_PHASE24' !== $marker || ! $markerFile || ! is_file( $markerFile ) || is_link( $markerFile ) || $marker . "\n" !== file_get_contents( $markerFile ) || false === $markerRoot || '/private/tmp' !== realpath( dirname( $markerRoot ) ) || ! in_array( $mode, array( 'success', 'failure' ), true ) || ! in_array( $targetType, array( 'plugin', 'theme' ), true ) ) {
	throw new RuntimeException( 'Guarded phase-2.4 harness missing required marker/env settings.' );
}

$httpRequests = 0;
add_filter(
	'pre_http_request',
	static function () use ( &$httpRequests ): WP_Error {
		++$httpRequests;
		return new WP_Error( 'phase24_network_forbidden', 'Network access is forbidden in the disposable proof.' );
	},
	PHP_INT_MIN
);
$networkProbe = wp_remote_get( 'https://phase24-network-guard.invalid/probe', array( 'timeout' => 1 ) );
$networkGuardProved = is_wp_error( $networkProbe ) && 'phase24_network_forbidden' === $networkProbe->get_error_code();
if ( ! $networkGuardProved ) throw new RuntimeException( 'Disposable network guard did not fail closed.' );

$identity = 'plugin' === $targetType ? $pluginId : $themeId;
$uri = 'plugin' === $targetType ? $pluginUri : $themeUri;
$archive = 'plugin' === $targetType ? ( 'success' === $mode ? $pluginArchive : $pluginFailureArchive ) : ( 'success' === $mode ? $themeArchive : $themeFailureArchive );
$target = build_target( $targetType, $identity, $uri, $archive, 'success' === $mode ? '1.0.0' : '2.0.0', 'success' === $mode ? '2.0.0' : '3.0.0' );

$evidence = array(
	'marker'     => $marker,
	'sourceRoot' => $sourceRoot,
	'core_upgrade' => 'success' === $mode ? run_core_upgrade_scenario( $targetType, $identity, $uri, $archive, $target['targetName'] ) : run_core_upgrade_failure_scenario( $targetType, $identity, $uri, $archive, $target['targetName'] ),
	'activation_readback' => array(
		'plugin_active' => is_plugin_active( $pluginId ), 'theme_active' => wp_get_theme()->get_stylesheet() === $themeId,
	),
	'sanity' => $target['sanity'],
	'database_readback' => array(
		$identity => readback_options( $target ),
	),
);

add_action( 'shutdown', static function () use ( &$evidence, $outputPath, $targetType, $identity, $target, &$httpRequests, $networkGuardProved ): void { $slug = 'theme' === $targetType ? $identity : dirname( $identity ); $evidence['post_shutdown'] = array( 'version' => file_version( $targetType, $identity ), 'bytes' => fixture_bytes( $targetType, $identity ), 'digest' => fixture_digest( $targetType, $identity ), 'backup_absent' => ! is_dir( backup_dir( $targetType, $slug ) ), 'maintenance_absent' => ! is_file( ABSPATH . '.maintenance' ), 'database' => readback_options( $target ), 'network_guard_installed' => true, 'network_guard_proved' => $networkGuardProved, 'blocked_http_requests' => $httpRequests ); file_put_contents( $outputPath, json_encode( $evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) ); }, PHP_INT_MAX );

/** @return array<string,mixed> */
function build_target( string $type, string $identity, string $uri, string $archive, string $installedVersion, string $releaseVersion ): array {
	$cleanUri = CanonicalUpdateUri::canonicalize( $uri );
	if ( null === $cleanUri ) {
		throw new RuntimeException( 'Invalid update URI in fixture target.' );
	}

	$binding = BindingRecord::create(
		array(
			'canonical_repository_locator'   => 'phase24/' . $type,
			'canonical_update_uri'          => $cleanUri,
			'installed_package_identity'    => $identity,
			'php_runtime_version'           => '8.2',
			'provider_code'                 => 'neutral',
			'release_channel'               => 'stable',
			'stable_repository_identity'    => 'repo:phase24',
			'target_type'                   => $type,
			'update_policy'                 => 'manual',
			'wordpress_runtime_version'      => '6.8',
		)
	);

	$descriptor = IdentityDescriptor::create(
		array(
			'artifact_filename'              => basename( $archive ),
			'artifact_identity'              => 'phase24-asset:' . $type,
			'artifact_sha256'                => hash_file( 'sha256', $archive ),
			'artifact_size'                  => filesize( $archive ),
			'assurance_facts'                => array(
				'exact_artifact_identity'       => true,
				'exact_commit_identity'         => true,
				'exact_reacquisition_supported' => true,
				'exact_release_identity'        => true,
				'provenance_verified'           => true,
				'publication_immutable'         => true,
				'repository_identity_stable'    => true,
				'trusted_digest_source'         => true,
			),
			'canonical_update_uri'           => $cleanUri,
			'channel'                       => 'stable',
			'commit_identity'               => 'phase24-commit:' . $type,
			'installed_package_identity'     => $identity,
			'prerelease'                    => false,
			'provider_code'                 => 'neutral',
			'release_identity'              => 'release-' . $type . ':2',
			'repository_identity'           => 'repo:phase24',
			'repository_locator'            => 'phase24/' . $type,
			'tag'                           => 'v' . $releaseVersion,
			'target_type'                   => $type,
			'version'                       => $releaseVersion,
		)
	);

	$policy = array(
		'archive_root'              => ( 'theme' === $type ? 'phase24-theme' : 'phase24-plugin' ),
		'configuration_update_uri'   => $cleanUri,
		'header_file'               => ( 'theme' === $type ? 'style.css' : 'phase24-plugin.php' ),
		'installed_package_identity' => $identity,
		'metadata_name'             => 'Phase24 ' . ucfirst( $type ),
		'offer_update_uri'           => $cleanUri,
		'php_runtime_version'       => '8.2',
		'provider_code'             => 'neutral',
		'repository_identity'       => 'repo:phase24',
		'repository_locator'        => 'phase24/' . $type,
		'staged_package_update_uri' => $cleanUri,
		'target_type'               => $type,
		'wordpress_runtime_version'  => '6.8',
	);

	$validator = new PackageIdentityValidator();
	$package = $validator->validate( $descriptor, $policy, $archive );
	if ( ! $package->isValid() ) {
		throw new RuntimeException( 'Could not validate fixture package for ' . $type );
	}

	$claim = ReleaseOperationCoordinator::claimPersistentBindingState( $GLOBALS['wpdb'], $binding, str_repeat( 'a', 64 ), 600 );
	if ( 'claimed' !== $claim['result'] ) {
		$claim = load_existing_claim( $binding );
	}
	if ( ! $claim['current'] instanceof BindingState ) {
		throw new RuntimeException( 'Could not claim binding for ' . $type );
	}

	$inspection = AcquisitionReceipt::issue( $claim['current'], $descriptor, $validator, $package, time() );
	$acquire = static function ( BindingState $state, int $now ) use ( $validator, $descriptor, $archive, $policy ): array {
		$cached = $validator->validate( $descriptor, $policy, $archive );
		if ( ! $cached->isValid() ) {
			throw new RuntimeException( 'Cached package validation failed.' );
		}
		return array(
			'path'    => $archive,
			'receipt' => AcquisitionReceipt::issue( $state, $descriptor, $validator, $cached, $now ),
		);
	};

	$configuration = array(
		'headers' => array(
			'Author'      => 'Phase24',
			'Description' => 'Phase24 disposable fixture',
			'Name'        => 'Phase24 ' . ucfirst( $type ),
			'PluginURI'   => $cleanUri,
			'RequiresPHP' => '8.2',
			'RequiresWP'  => '6.8',
			'UpdateURI'   => $cleanUri,
			'Version'     => $installedVersion,
		),
		'installed_package_identity' => $identity,
		'policy'                    => 'manual',
		'target_type'               => $type,
		'update_uri'                => $cleanUri,
	);

	$updater = NativePluginUpdater::fromConfiguration(
		$configuration,
		$descriptor,
		$binding,
		$GLOBALS['wpdb'],
		$claim['current'],
		claim_identity( $claim['current'] ),
		$inspection,
		$acquire
	);
	if ( ! $updater instanceof NativePluginUpdater ) {
		throw new RuntimeException( 'Could not construct updater for ' . $type );
	}

	$updater->register();

	$offer = apply_filters( 'update_' . ( 'plugin' === $type ? 'plugins_' : 'themes_' ) . parse_url( $uri, PHP_URL_HOST ), false, array( 'Version' => '1.0.0', 'UpdateURI' => $uri ), $identity, array() );

	return array(
		'type'  => $type,
		'identity' => $identity,
		'uri' => $cleanUri,
		'archive' => $archive,
		'targetName' => 'ran_wp_release_updater_target_v1_' . BindingRecord::targetFenceKey( array( 'installed_package_identity' => $identity ) ),
		'sanity' => array(
			'offer_hook_fired' => is_array( $offer ),
		),
	);
}

function claim_identity( BindingState $state ): array {
	return array(
		'binding_generation' => $state->bindingGeneration(),
		'binding_hash'      => $state->binding()->bindingHash(),
		'lease_deadline'    => $state->leaseDeadline(),
		'owner_token'       => $state->ownerToken(),
	);
}

function run_target_checks( array $target, string $failureArchive ): array {
	$type = $target['type'];
	$identity = $target['identity'];
	$uri = (string) $target['uri'];
	$archive = (string) $target['archive'];

	$good = run_core_upgrade_scenario( $type, $identity, $uri, $archive, $target['targetName'] );
	$bad = run_core_upgrade_failure_scenario( $type, $identity, $uri, $failureArchive, $target['targetName'] );

	$target['core_upgrade_ok'] = $good;
	$target['core_upgrade_failed'] = $bad;
	return $target;
}

/** @return array<string,mixed> */
function run_core_upgrade_scenario( string $type, string $identity, string $uri, string $archive, string $label ) : array {
	$slug = 'theme' === $type ? basename( $identity ) : dirname( $identity );
	$backup = backup_dir( $type, $slug );
	if ( is_dir( $backup ) ) {
		rrmdir_recursive( $backup );
	}

	$before = file_version( $type, $identity );
	$transient = (object) array( 'last_checked' => time(), 'response' => array() );
	if ( 'plugin' === $type ) {
		$transient->response[ $identity ] = (object) array(
			'slug'        => $slug,
			'plugin'      => $identity,
			'new_version' => '2.0.0',
			'package'     => $archive,
			'url'         => $uri,
			'tested'      => '6.8',
			'requires_php'=> '8.2',
		);
		set_site_transient( 'update_plugins', $transient, 60 );
		$upgrader = new Plugin_Upgrader( new Automatic_Upgrader_Skin() );
		$result = $upgrader->upgrade( $identity, array( 'clear_update_cache' => false ) );
	} else {
		$transient->response[ $slug ] = array(
			'theme'       => $slug,
			'slug'        => $slug,
			'new_version' => '2.0.0',
			'package'     => $archive,
			'url'         => $uri,
			'tested'      => '6.8',
			'requires_php'=> '8.2',
		);
		set_site_transient( 'update_themes', $transient, 60 );
		$upgrader = new Theme_Upgrader( new Automatic_Upgrader_Skin() );
		$result = $upgrader->upgrade( $slug, array( 'clear_update_cache' => false ) );
	}

	return array(
		'upgraded'    => true === $result,
		'result_code' => is_wp_error( $result ) ? $result->get_error_code() : null,
		'version_before' => $before,
		'version_after'  => file_version( $type, $identity ),
		'bytes_after' => fixture_bytes( $type, $identity ),
		'backup_cleaned' => ! is_dir( $backup ),
		'maintenance_file_absent' => ! is_file( ABSPATH . '.maintenance' ),
		'state_name' => $label,
	);
}

function run_core_upgrade_failure_scenario( string $type, string $identity, string $uri, string $archive, string $label ): array {
	$slug = 'theme' === $type ? basename( $identity ) : dirname( $identity );
	$before = file_version( $type, $identity );
	$injected = array( 'post_copy_seen' => false, 'destination_version' => null, 'backup_present' => false, 'destination_bytes' => null, 'destination_digest' => null );
	$injectFailure = static function ( mixed $response, array $hookExtra, array $installResult ) use ( $type, $identity, &$injected ): mixed {
		$key = 'plugin' === $type ? 'plugin' : 'theme';
		if ( $identity !== ( $hookExtra[ $key ] ?? null ) || ! is_array( $installResult ) ) {
			return $response;
		}
		$injected['post_copy_seen'] = true;
		$injected['destination_version'] = file_version( $type, $identity );
		$injected['destination_bytes'] = fixture_bytes( $type, $identity );
		$injected['destination_digest'] = fixture_digest( $type, $identity );
		$injected['backup_present'] = is_dir( backup_dir( $type, 'theme' === $type ? $identity : dirname( $identity ) ) );
		return new WP_Error( 'phase24_injected_post_copy_failure', 'Injected after Core moved the valid archive into the destination.' );
	};
	add_filter( 'upgrader_post_install', $injectFailure, PHP_INT_MAX, 3 );
	$transient = (object) array( 'last_checked' => time(), 'response' => array() );
	if ( 'plugin' === $type ) {
		$transient->response[ $identity ] = (object) array( 'slug' => $slug, 'plugin' => $identity, 'new_version' => '3.0.0', 'package' => $archive, 'url' => $uri );
		set_site_transient( 'update_plugins', $transient, 60 );
		$upgrader = new Plugin_Upgrader( new Automatic_Upgrader_Skin() );
		$result = $upgrader->upgrade( $identity, array( 'clear_update_cache' => false ) );
	} else {
		$transient->response[ $slug ] = array( 'theme' => $slug, 'slug' => $slug, 'new_version' => '3.0.0', 'package' => $archive, 'url' => $uri );
		set_site_transient( 'update_themes', $transient, 60 );
		$upgrader = new Theme_Upgrader( new Automatic_Upgrader_Skin() );
		$result = $upgrader->upgrade( $slug, array( 'clear_update_cache' => false ) );
	}
	remove_filter( 'upgrader_post_install', $injectFailure, PHP_INT_MAX );
	$backup = backup_dir( $type, $slug );
	$installResult = $upgrader->result;
	if ( ! is_wp_error( $installResult ) ) $installResult = new WP_Error( 'failure_not_reported', 'Failure scenario did not expose the injected Core error.' );

	return array(
		'failed' => is_wp_error( $installResult ),
		'result_code' => is_wp_error( $installResult ) ? $installResult->get_error_code() : null,
		'version_before' => $before,
		'version_after'  => file_version( $type, $identity ),
		'bytes_after' => fixture_bytes( $type, $identity ),
		'injected_post_copy' => $injected,
		'rollback_backup_path_exists' => is_dir( $backup ),
		'maintenance_file_exists' => is_file( ABSPATH . '.maintenance' ),
		'rollback_path' => $backup,
		'state_name' => $label,
	);
}

function file_version( string $type, string $identity ): ?string {
	if ( 'plugin' === $type ) {
		$plugin = WP_PLUGIN_DIR . '/' . $identity;
		if ( ! is_file( $plugin ) ) {
			return null;
		}
		$data = get_plugin_data( $plugin, false, false );
		return is_array( $data ) && isset( $data['Version'] ) ? (string) $data['Version'] : null;
	}

	$file = get_theme_root( $identity ) . '/' . $identity . '/style.css';
	if ( ! is_file( $file ) ) return null;
	$data = get_file_data( $file, array( 'Version' => 'Version' ), 'theme' );
	return is_array( $data ) && isset( $data['Version'] ) ? (string) $data['Version'] : null;
}

function fixture_bytes( string $type, string $identity ): ?int {
	$path = 'plugin' === $type ? WP_PLUGIN_DIR . '/' . $identity : get_theme_root( $identity ) . '/' . $identity . '/style.css';
	return is_file( $path ) ? filesize( $path ) : null;
}

function fixture_digest( string $type, string $identity ): ?string {
	$root = 'plugin' === $type ? dirname( WP_PLUGIN_DIR . '/' . $identity ) : get_theme_root( $identity ) . '/' . $identity;
	if ( ! is_dir( $root ) || is_link( $root ) ) return null;
	$files = array();
	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $iterator as $entry ) {
		if ( $entry->isLink() || ! $entry->isFile() ) return null;
		$relative = substr( $entry->getPathname(), strlen( $root ) + 1 );
		$files[ $relative ] = hash_file( 'sha256', $entry->getPathname() );
	}
	ksort( $files, SORT_STRING );
	return hash( 'sha256', json_encode( $files, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) );
}

/** @return array{current:BindingState|null,result:string} */
function load_existing_claim( BindingRecord $binding ): array {
	$name = 'ran_wp_release_updater_target_v1_' . BindingRecord::targetFenceKey( array( 'installed_package_identity' => $binding->toArray()['installed_package_identity'] ) );
	$raw = get_option( $name, null );
	if ( ! is_string( $raw ) ) return array( 'current' => null, 'result' => 'binding_fence_lost' );
	$current = BindingState::rehydrate( json_decode( $raw, true, 64, JSON_THROW_ON_ERROR ) );
	$verified = ReleaseOperationCoordinator::verifyPersistentBindingState( $GLOBALS['wpdb'], $current, claim_identity( $current ) );
	return array( 'current' => 'verified' === $verified['result'] ? $verified['current'] : null, 'result' => $verified['result'] );
}

function backup_dir( string $type, string $slug ): string {
	$base = WP_CONTENT_DIR . '/upgrade-temp-backup';
	$bucket = 'plugin' === $type ? 'plugins' : 'themes';
	return $base . '/' . $bucket . '/' . $slug;
}

function rrmdir_recursive( string $path ): void {
	if ( ! is_dir( $path ) ) {
		if ( is_link( $path ) || is_file( $path ) ) {
			@unlink( $path );
		}
		return;
	}
	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $iterator as $entry ) {
		is_dir( $entry->getPathname() ) ? @rmdir( $entry->getPathname() ) : @unlink( $entry->getPathname() );
	}
	@rmdir( $path );
}

/** @param array<string,mixed> $target @return array<string,mixed> */
function readback_options( array $target ): array {
	$targetName = $target['targetName'] ?? null;
	$targetRow = is_string( $targetName ) ? option_row( $targetName ) : null;
	$targetValue = is_array( $targetRow ) ? $targetRow['option_value'] : null;
	$targetDecoded = is_string( $targetValue ) ? json_decode( $targetValue, true, 32, JSON_THROW_ON_ERROR ) : null;
	return array(
		'target_name' => is_string( $targetName ) ? $targetName : null,
		'target_exists' => is_array( $targetRow ),
		'target_autoload' => is_array( $targetRow ) ? $targetRow['autoload'] : null,
		'target_schema' => is_array( $targetDecoded ) ? ( $targetDecoded['state_schema'] ?? null ) : null,
		'target_value' => $targetValue,
		'state_row_count' => option_prefix_count( 'ran_wp_release_updater_state_v1_' ),
	);
}

function option_prefix_count( string $prefix ): int {
	if ( ! isset( $GLOBALS['wpdb'] ) ) {
		return -1;
	}
	$count = $GLOBALS['wpdb']->get_var(
		$GLOBALS['wpdb']->prepare(
			'SELECT COUNT(*) FROM ' . $GLOBALS['wpdb']->options . ' WHERE option_name LIKE %s',
			$GLOBALS['wpdb']->esc_like( $prefix ) . '%'
		)
	);
	return is_string( $count ) && ctype_digit( $count ) ? (int) $count : -1;
}

/** @return array{option_value:string,autoload:string}|null */
function option_row( string $optionName ): ?array {
	if ( ! isset( $GLOBALS['wpdb'] ) ) {
		return null;
	}
	$row = $GLOBALS['wpdb']->get_row( $GLOBALS['wpdb']->prepare( 'SELECT option_value, autoload FROM ' . $GLOBALS['wpdb']->options . ' WHERE option_name=%s LIMIT 1', $optionName ), ARRAY_A );
	return is_array( $row ) && is_string( $row['option_value'] ?? null ) && is_string( $row['autoload'] ?? null ) ? $row : null;
}
