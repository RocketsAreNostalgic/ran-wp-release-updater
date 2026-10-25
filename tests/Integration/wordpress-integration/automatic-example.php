<?php

// wp eval-file wraps this fixture before execution, so strict_types cannot lead.

require_once ABSPATH . 'wp-admin/includes/update.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

$ran_wp_release_updater_test_automatic_archive = ran_wp_release_updater_test_automatic_example_input( 'RAN_UPDATER_AUTOMATIC_EXAMPLE_ARCHIVE' );
$ran_wp_release_updater_test_automatic_output  = ran_wp_release_updater_test_automatic_example_input( 'RAN_UPDATER_AUTOMATIC_EXAMPLE_OUTPUT' );
$ran_wp_release_updater_test_automatic_version = ran_wp_release_updater_test_automatic_example_input( 'RAN_UPDATER_AUTOMATIC_EXAMPLE_VERSION' );
$ran_wp_release_updater_test_automatic_source  = ran_wp_release_updater_test_automatic_example_input( 'RAN_UPDATER_AUTOMATIC_EXAMPLE_SOURCE' );
$ran_wp_release_updater_test_automatic_mode    = getenv( 'RAN_UPDATER_AUTOMATIC_EXAMPLE_MODE' );
$ran_wp_release_updater_test_automatic_mode    = false === $ran_wp_release_updater_test_automatic_mode ? 'update' : $ran_wp_release_updater_test_automatic_mode;
$ran_wp_release_updater_test_automatic_slug    = 'ran-wp-release-updater-example';
$ran_wp_release_updater_test_automatic_plugin  = $ran_wp_release_updater_test_automatic_slug . '/ran-wp-release-updater-example.php';

if ( ! is_file( $ran_wp_release_updater_test_automatic_archive ) || ! preg_match( '/\A0\.[123]\.0\z/', $ran_wp_release_updater_test_automatic_version ) || ! is_dir( $ran_wp_release_updater_test_automatic_source ) || ! in_array( $ran_wp_release_updater_test_automatic_mode, array( 'observe', 'schedule' ), true ) ) {
	throw new RuntimeException( 'Automatic example fixture inputs are invalid.' );
}

$ran_wp_release_updater_test_automatic_before           = get_plugin_data( WP_PLUGIN_DIR . '/' . $ran_wp_release_updater_test_automatic_plugin, false, false );
$ran_wp_release_updater_test_automatic_before_version   = $ran_wp_release_updater_test_automatic_before['Version'] ?? null;
$ran_wp_release_updater_test_automatic_before_transient = get_site_transient( 'update_plugins' );
if ( 'schedule' === $ran_wp_release_updater_test_automatic_mode ) {
	// Core owns discovery. Clear only its stale cache so the fresh cron request must discover again.
	delete_site_transient( 'update_plugins' );
	wp_schedule_single_event( time() - 1, 'wp_maybe_auto_update' );
}
$ran_wp_release_updater_test_automatic_after              = get_plugin_data( WP_PLUGIN_DIR . '/' . $ran_wp_release_updater_test_automatic_plugin, false, false );
$ran_wp_release_updater_test_automatic_after_version      = $ran_wp_release_updater_test_automatic_after['Version'] ?? null;
$ran_wp_release_updater_test_automatic_runtime            = WP_PLUGIN_DIR . '/' . $ran_wp_release_updater_test_automatic_slug . '/vendor/ran/wp-release-updater';
$ran_wp_release_updater_test_automatic_installed_manifest = ran_wp_release_updater_test_automatic_example_directory_manifest( WP_PLUGIN_DIR . '/' . $ran_wp_release_updater_test_automatic_slug );
$ran_wp_release_updater_test_automatic_archive_manifest   = ran_wp_release_updater_test_automatic_example_archive_manifest( $ran_wp_release_updater_test_automatic_archive, $ran_wp_release_updater_test_automatic_slug );
$ran_wp_release_updater_test_automatic_fence_key          = 'ran_wp_release_updater_target_v1_' . \RAN\WPReleaseUpdater\V1\Contract\BindingRecord::target_fence_key(
	array(
		'network_id'                 => get_current_network_id(),
		'target_type'                => 'plugin',
		'installed_package_identity' => $ran_wp_release_updater_test_automatic_plugin,
	)
);
global $wpdb;
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WordPress exposes its database connection through this named global; only the exact fixture-owned fence option is read.
$ran_wp_release_updater_test_automatic_fence_raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->base_prefix}options WHERE option_name = %s LIMIT 1", $ran_wp_release_updater_test_automatic_fence_key ) );
$ran_wp_release_updater_test_automatic_fence     = is_string( $ran_wp_release_updater_test_automatic_fence_raw ) ? json_decode( $ran_wp_release_updater_test_automatic_fence_raw, true ) : null;
$ran_wp_release_updater_test_automatic_cleanup   = array(
	'auto_updater_lock_absent' => false === get_option( 'auto_updater.lock', false ),
	'maintenance_absent'       => ! file_exists( ABSPATH . '.maintenance' ),
	'lease_released'           => is_array( $ran_wp_release_updater_test_automatic_fence ) && 1 === ( $ran_wp_release_updater_test_automatic_fence['lease_deadline'] ?? null ),
	'backup_absent'            => ! is_dir( WP_CONTENT_DIR . '/upgrade-temp-backup/plugins/' . $ran_wp_release_updater_test_automatic_slug ),
	'owned_downloads_absent'   => array() === glob( rtrim( sys_get_temp_dir(), '/\\' ) . '/ran-wp-release-updater-*', GLOB_NOSORT ),
);
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- The installed runtime copy is exact fixture evidence, not a WordPress-managed file.
$ran_wp_release_updater_test_automatic_runtime_copy = json_decode( (string) file_get_contents( $ran_wp_release_updater_test_automatic_runtime . '/runtime-copy.json' ), true, 512, JSON_THROW_ON_ERROR );
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- The candidate runtime copy is exact fixture evidence, not a WordPress-managed file.
$ran_wp_release_updater_test_automatic_source_copy = json_decode( (string) file_get_contents( $ran_wp_release_updater_test_automatic_source . '/runtime-copy.json' ), true, 512, JSON_THROW_ON_ERROR );
$ran_wp_release_updater_test_automatic_origin      = ( new ReflectionClass( 'RAN\\WPReleaseUpdater\\V1\\WordPress\\NativePackageUpdater' ) )->getFileName();
$ran_wp_release_updater_test_automatic_installed   = $ran_wp_release_updater_test_automatic_before_version !== $ran_wp_release_updater_test_automatic_after_version;
$ran_wp_release_updater_test_automatic_proof       = array(
	'before_version'       => $ran_wp_release_updater_test_automatic_before_version,
	'after_version'        => $ran_wp_release_updater_test_automatic_after_version,
	'installed'            => $ran_wp_release_updater_test_automatic_installed,
	'mode'                 => $ran_wp_release_updater_test_automatic_mode,
	'active'               => is_plugin_active( $ran_wp_release_updater_test_automatic_plugin ),
	// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Exact manifest bytes provide stable process-boundary evidence.
	'archive_manifest'     => hash( 'sha256', json_encode( $ran_wp_release_updater_test_automatic_archive_manifest, JSON_THROW_ON_ERROR ) ),
	// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Exact manifest bytes provide stable process-boundary evidence.
	'installed_manifest'   => hash( 'sha256', json_encode( $ran_wp_release_updater_test_automatic_installed_manifest, JSON_THROW_ON_ERROR ) ),
	'runtime_revision'     => $ran_wp_release_updater_test_automatic_runtime_copy['package_revision'] ?? null,
	'runtime_origin'       => $ran_wp_release_updater_test_automatic_origin,
	'cleanup'              => $ran_wp_release_updater_test_automatic_cleanup,
	'update_transient_was' => false !== $ran_wp_release_updater_test_automatic_before_transient,
	'pass'                 => 'schedule' === $ran_wp_release_updater_test_automatic_mode || ( $ran_wp_release_updater_test_automatic_after_version === $ran_wp_release_updater_test_automatic_version
		&& is_plugin_active( $ran_wp_release_updater_test_automatic_plugin )
		&& $ran_wp_release_updater_test_automatic_archive_manifest === $ran_wp_release_updater_test_automatic_installed_manifest
		&& is_array( $ran_wp_release_updater_test_automatic_runtime_copy ) && is_array( $ran_wp_release_updater_test_automatic_source_copy )
		&& ( $ran_wp_release_updater_test_automatic_source_copy['package_revision'] ?? null ) === ( $ran_wp_release_updater_test_automatic_runtime_copy['package_revision'] ?? null )
		&& is_string( $ran_wp_release_updater_test_automatic_origin ) && str_starts_with( $ran_wp_release_updater_test_automatic_origin, $ran_wp_release_updater_test_automatic_runtime . '/' )
		&& ! in_array( false, $ran_wp_release_updater_test_automatic_cleanup, true )
		),
);

// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Emit exact isolated-process evidence for the parent harness without WordPress filesystem mediation.
file_put_contents( $ran_wp_release_updater_test_automatic_output, json_encode( $ran_wp_release_updater_test_automatic_proof, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . "\n" );

function ran_wp_release_updater_test_automatic_example_input( string $ran_wp_release_updater_test_automatic_name ): string {
	$ran_wp_release_updater_test_automatic_value = getenv( $ran_wp_release_updater_test_automatic_name );
	if ( ! is_string( $ran_wp_release_updater_test_automatic_value ) || '' === $ran_wp_release_updater_test_automatic_value ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- This exception carries fixture input identity, not HTML output.
		throw new RuntimeException( $ran_wp_release_updater_test_automatic_name . ' is required.' );
	}
	return $ran_wp_release_updater_test_automatic_value;
}

/** @return array<string,mixed> */
function ran_wp_release_updater_test_automatic_example_response( mixed $ran_wp_release_updater_test_automatic_body, ?string $ran_wp_release_updater_test_automatic_filename = null ): array {
	return array(
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- The fixture returns provider JSON bytes at the WordPress HTTP boundary.
		'body'     => null === $ran_wp_release_updater_test_automatic_body ? '' : json_encode( $ran_wp_release_updater_test_automatic_body, JSON_THROW_ON_ERROR ),
		'headers'  => array(),
		'response' => array(
			'code'    => 200,
			'message' => 'OK',
		),
		'filename' => $ran_wp_release_updater_test_automatic_filename,
	);
}

/** @return array<string,array{sha256:string,size:int}> */
function ran_wp_release_updater_test_automatic_example_directory_manifest( string $root ): array {
	$ran_wp_release_updater_test_automatic_manifest = array();
	$ran_wp_release_updater_test_automatic_iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $ran_wp_release_updater_test_automatic_iterator as $file ) {
		if ( $file->isLink() || ! $file->isFile() ) {
			throw new RuntimeException( 'Installed automatic example contains a non-regular entry.' );
		}
		$ran_wp_release_updater_test_automatic_relative = substr( $file->getPathname(), strlen( $root ) + 1 );
		$ran_wp_release_updater_test_automatic_digest   = hash_file( 'sha256', $file->getPathname() );
		$ran_wp_release_updater_test_automatic_size     = $file->getSize();
		if ( ! is_string( $ran_wp_release_updater_test_automatic_digest ) || ! is_int( $ran_wp_release_updater_test_automatic_size ) ) {
			throw new RuntimeException( 'Installed automatic example manifest cannot be read.' );
		}
		$ran_wp_release_updater_test_automatic_manifest[ $ran_wp_release_updater_test_automatic_relative ] = array(
			'sha256' => $ran_wp_release_updater_test_automatic_digest,
			'size'   => $ran_wp_release_updater_test_automatic_size,
		);
	}
	ksort( $ran_wp_release_updater_test_automatic_manifest );
	return $ran_wp_release_updater_test_automatic_manifest;
}

/** @return array<string,array{sha256:string,size:int}> */
function ran_wp_release_updater_test_automatic_example_archive_manifest( string $path, string $root ): array {
	$ran_wp_release_updater_test_automatic_zip = new ZipArchive();
	if ( true !== $ran_wp_release_updater_test_automatic_zip->open( $path ) ) {
		throw new RuntimeException( 'Automatic example archive cannot be opened.' );
	}
	$ran_wp_release_updater_test_automatic_manifest = array();
	// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- ZipArchive exposes this native property name.
	for ( $ran_wp_release_updater_test_automatic_index = 0; $ran_wp_release_updater_test_automatic_index < $ran_wp_release_updater_test_automatic_zip->numFiles; ++$ran_wp_release_updater_test_automatic_index ) {
		$ran_wp_release_updater_test_automatic_name = $ran_wp_release_updater_test_automatic_zip->getNameIndex( $ran_wp_release_updater_test_automatic_index );
		if ( ! is_string( $ran_wp_release_updater_test_automatic_name ) || ! str_starts_with( $ran_wp_release_updater_test_automatic_name, $root . '/' ) || str_ends_with( $ran_wp_release_updater_test_automatic_name, '/' ) ) {
			continue;
		}
		$ran_wp_release_updater_test_automatic_bytes = $ran_wp_release_updater_test_automatic_zip->getFromIndex( $ran_wp_release_updater_test_automatic_index );
		if ( ! is_string( $ran_wp_release_updater_test_automatic_bytes ) ) {
			throw new RuntimeException( 'Automatic example archive entry cannot be read.' );
		}
		$ran_wp_release_updater_test_automatic_manifest[ substr( $ran_wp_release_updater_test_automatic_name, strlen( $root ) + 1 ) ] = array(
			'sha256' => hash( 'sha256', $ran_wp_release_updater_test_automatic_bytes ),
			'size'   => strlen( $ran_wp_release_updater_test_automatic_bytes ),
		);
	}
	$ran_wp_release_updater_test_automatic_zip->close();
	ksort( $ran_wp_release_updater_test_automatic_manifest );
	return $ran_wp_release_updater_test_automatic_manifest;
}
