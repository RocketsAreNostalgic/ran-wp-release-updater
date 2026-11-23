<?php
/**
 * Installed proof for ran_wp_release_updater_offer_release_v1: an authorised same-version release,
 * or an older release the host says is intended, installs through WordPress's own upgrader on the
 * active target, and the stored update check row never carries the authorised offer, after success
 * or after a failed install that WordPress restores.
 */

use RAN\WPReleaseUpdater\V1\Contract\BindingRecord;
use RAN\WPReleaseUpdater\V1\Contract\IdentityDescriptor;
use RAN\WPReleaseUpdater\V1\Tests\Support\ControllableReleaseAdapter;
use RAN\WPReleaseUpdater\V1\WordPress\NativePackageUpdater;

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$marker_file = (string) getenv( 'RAN_WP_RELEASE_UPDATER_MARKER_FILE' );
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$source_root = dirname( $marker_file ) . '/site/wp-content/plugins/ran-wp-release-updater';
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read exact local bytes for the disposable integration fixture without requiring WordPress filesystem initialization.
if ( ! is_file( $marker_file ) || is_link( $marker_file ) || "RAN_WP_RELEASE_UPDATER_OFFER_RELEASE\n" !== file_get_contents( $marker_file ) || realpath( ABSPATH ) !== realpath( dirname( $marker_file ) . '/site' ) || ! is_file( $source_root . '/bootstrap.php' ) ) {
	throw new RuntimeException( 'The offer-release harness is not inside its owned disposable site.' );
}
// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- This CLI/eval-file fixture variable is local scenario/process state, not a WordPress request global override.
$type = (string) getenv( 'RAN_WP_RELEASE_UPDATER_OFFER_TYPE' );
// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- This CLI/eval-file fixture variable is local scenario/process state, not a WordPress request global override.
$mode                                  = (string) getenv( 'RAN_WP_RELEASE_UPDATER_OFFER_MODE' );
$ran_wp_release_updater_test_direction = (string) getenv( 'RAN_WP_RELEASE_UPDATER_OFFER_DIRECTION' );
$ran_wp_release_updater_test_exact     = str_starts_with( $mode, 'exact-' );
if ( ! in_array( $type, array( 'plugin', 'theme' ), true ) || ( ! $ran_wp_release_updater_test_exact && ! in_array( $mode, array( 'success', 'failure' ), true ) ) || ! in_array( $ran_wp_release_updater_test_direction, array( 'same', 'older', 'newer' ), true ) ) {
	throw new RuntimeException( 'Invalid offer-release scenario.' );
}
require_once $source_root . '/bootstrap.php';
foreach ( array( 'file.php', 'misc.php', 'plugin.php', 'theme.php', 'template.php', 'class-wp-upgrader.php', 'class-plugin-upgrader.php', 'class-theme-upgrader.php', 'class-bulk-upgrader-skin.php', 'class-bulk-plugin-upgrader-skin.php' ) as $ran_wp_release_updater_test_file ) {
	require_once ABSPATH . 'wp-admin/includes/' . $ran_wp_release_updater_test_file;
}
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$activation = $GLOBALS['ran_wp_release_updater_v1_broker']->activate(
	array(
		'php_version'       => PHP_VERSION,
		'runtime_protocol'  => 11,
		'wordpress_version' => $GLOBALS['wp_version'],
	)
);
if ( 'runtime_active' !== ( $activation['code'] ?? null ) ) {
	throw new RuntimeException( 'The copied updater runtime could not be activated.' );
}
// The test adapter implements the runtime's contract, so it loads once the runtime is active.
require_once dirname( __DIR__, 2 ) . '/Support/ControllableReleaseAdapter.php';
add_filter( 'filesystem_method', static fn (): string => 'direct' );

$ran_wp_release_updater_test_slug     = 'exact-copy-zero-slug' === $mode ? '0' : 'offer-target';
$ran_wp_release_updater_test_uri      = $ran_wp_release_updater_test_exact ? 'https://github.com/exact-fixture/offer-target' : 'https://offer-release.invalid/offer-target/repository';
$ran_wp_release_updater_test_identity = 'plugin' === $type ? $ran_wp_release_updater_test_slug . '/offer-target.php' : $ran_wp_release_updater_test_slug;
$ran_wp_release_updater_test_option   = 'plugin' === $type ? 'update_plugins' : 'update_themes';
// The release is 2.0.0. The installed copy has different bytes in every case.
$ran_wp_release_updater_test_installed = match ( $ran_wp_release_updater_test_direction ) {
	'older' => '3.0.0',
	'newer' => '1.0.0',
	default => '2.0.0',
};
$ran_wp_release_updater_test_bytes     = static function ( string $name, string $version ) use ( $ran_wp_release_updater_test_uri ): string {
	$header = "Version: {$version}\nUpdate URI: {$ran_wp_release_updater_test_uri}\nRequires PHP: 8.2\nRequires at least: 6.5\n*/\n";
	return match ( $name ) {
		'offer-target.php' => "<?php\n/*\nPlugin Name: Offer Target\n{$header}",
		'style.css'        => "/*\nTheme Name: Offer Target\n{$header}",
		default            => "<?php\n",
	};
};
$ran_wp_release_updater_test_directory = ( 'plugin' === $type ? WP_PLUGIN_DIR : get_theme_root() ) . '/' . $ran_wp_release_updater_test_slug;
$ran_wp_release_updater_test_archive   = WP_CONTENT_DIR . '/uploads/offer-target-2.0.0.zip';
$ran_wp_release_updater_test_zip       = new ZipArchive();
if ( ! wp_mkdir_p( $ran_wp_release_updater_test_directory ) || true !== $ran_wp_release_updater_test_zip->open( $ran_wp_release_updater_test_archive, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
	throw new RuntimeException( 'The offer-release fixture could not be created.' );
}
foreach ( 'plugin' === $type ? array( 'offer-target.php' ) : array( 'style.css', 'index.php' ) as $ran_wp_release_updater_test_name ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write exact bytes for the disposable integration fixture; WordPress helpers would alter the boundary under test.
	file_put_contents( $ran_wp_release_updater_test_directory . '/' . $ran_wp_release_updater_test_name, $ran_wp_release_updater_test_bytes( $ran_wp_release_updater_test_name, $ran_wp_release_updater_test_installed ) . "// installed\n" );
	$ran_wp_release_updater_test_zip->addFromString( $ran_wp_release_updater_test_slug . '/' . $ran_wp_release_updater_test_name, $ran_wp_release_updater_test_bytes( $ran_wp_release_updater_test_name, '2.0.0' ) . "// release\n" );
}
if ( 'exact-recovery-verified-directory' === $mode ) {
	$ran_wp_release_updater_test_zip->addFromString( 'offer-target/verified/nested/readme.txt', 'Verified incoming file' );
}
if ( 'exact-copy-incoming-directory' === $mode || 'exact-copy-incoming-file' === $mode ) {
	$ran_wp_release_updater_test_zip->addFromString( 'offer-target/offer-target' . ( 'exact-copy-incoming-directory' === $mode ? '/readme.txt' : '' ), 'Incoming payload for copy fallback' );
}
$ran_wp_release_updater_test_zip->close();
if ( 'plugin' === $type ) {
	if ( null !== activate_plugin( $ran_wp_release_updater_test_identity ) ) {
		throw new RuntimeException( 'The offer-release plugin could not be activated.' );
	}
} else {
	switch_theme( $ran_wp_release_updater_test_slug );
}
$ran_wp_release_updater_test_path = $ran_wp_release_updater_test_directory . '/' . ( 'plugin' === $type ? 'offer-target.php' : 'index.php' );
if ( $ran_wp_release_updater_test_exact ) {
	require_once __DIR__ . '/exact-install.php';
	if ( ! function_exists( 'ran_wp_release_updater_test_install_exact' ) ) {
		throw new RuntimeException( 'The exact-install proof is unavailable.' );
	}
	ran_wp_release_updater_test_install_exact(
		$type,
		$mode,
		$ran_wp_release_updater_test_direction,
		$ran_wp_release_updater_test_identity,
		$ran_wp_release_updater_test_archive,
		$ran_wp_release_updater_test_path,
		$ran_wp_release_updater_test_installed,
		$source_root
	);
	return;
}
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read exact local bytes for the disposable integration fixture.
$ran_wp_release_updater_test_before = (string) file_get_contents( $ran_wp_release_updater_test_path );

$ran_wp_release_updater_test_facts      = array(
	'repository_identity' => 'repo-offer-' . $type,
	'repository_locator'  => 'fixture/offer/' . $type,
);
$ran_wp_release_updater_test_descriptor = IdentityDescriptor::create(
	array(
		'artifact_filename'          => basename( $ran_wp_release_updater_test_archive ),
		'artifact_identity'          => 'asset-offer-' . $type,
		'artifact_sha256'            => hash_file( 'sha256', $ran_wp_release_updater_test_archive ),
		'artifact_size'              => filesize( $ran_wp_release_updater_test_archive ),
		'assurance_facts'            => array_fill_keys( array( 'exact_artifact_identity', 'exact_commit_identity', 'exact_reacquisition_supported', 'exact_release_identity', 'provenance_verified', 'publication_immutable', 'repository_identity_stable', 'trusted_digest_source' ), true ),
		'canonical_update_uri'       => $ran_wp_release_updater_test_uri,
		'channel'                    => 'stable',
		'commit_identity'            => 'commit-offer-' . $type,
		'installed_package_identity' => $ran_wp_release_updater_test_identity,
		'prerelease'                 => false,
		'provider_code'              => 'fixture',
		'release_identity'           => 'release-offer-' . $type,
		'repository_identity'        => $ran_wp_release_updater_test_facts['repository_identity'],
		'repository_locator'         => $ran_wp_release_updater_test_facts['repository_locator'],
		'tag'                        => 'v2.0.0',
		'target_type'                => $type,
		'version'                    => '2.0.0',
	)
);
$ran_wp_release_updater_test_adapter    = new ControllableReleaseAdapter( $ran_wp_release_updater_test_descriptor, $ran_wp_release_updater_test_archive, sys_get_temp_dir() );
$ran_wp_release_updater_test_updater    = NativePackageUpdater::from_configuration(
	array(
		'installed_directory'        => dirname( $ran_wp_release_updater_test_path ),
		'headers'                    => array(
			'Author'      => 'Fixture',
			'Description' => 'Offer release fixture',
			'Name'        => 'Offer Target',
			'PluginURI'   => $ran_wp_release_updater_test_uri,
			'RequiresPHP' => '8.2',
			'RequiresWP'  => '6.5',
			'UpdateURI'   => $ran_wp_release_updater_test_uri,
			'Version'     => $ran_wp_release_updater_test_installed,
		),
		'installed_package_identity' => $ran_wp_release_updater_test_identity,
		'policy'                     => 'manual',
		'target_type'                => $type,
		'update_uri'                 => $ran_wp_release_updater_test_uri,
	),
	BindingRecord::create(
		array(
			'canonical_repository_locator' => $ran_wp_release_updater_test_facts['repository_locator'],
			'canonical_update_uri'         => $ran_wp_release_updater_test_uri,
			'installed_package_identity'   => $ran_wp_release_updater_test_identity,
			'maximum_artifact_bytes'       => 52_428_800,
			'network_id'                   => 1,
			'php_runtime_version'          => PHP_VERSION,
			'provider_code'                => 'fixture',
			'release_channel'              => 'stable',
			'stable_repository_identity'   => $ran_wp_release_updater_test_facts['repository_identity'],
			'target_type'                  => $type,
			'theme_template'               => '',
			'update_policy'                => 'manual',
			'wordpress_runtime_version'    => $GLOBALS['wp_version'],
		)
	),
	$ran_wp_release_updater_test_adapter,
	$GLOBALS['wpdb'],
	array(
		'archive_root'               => $ran_wp_release_updater_test_slug,
		'configuration_update_uri'   => $ran_wp_release_updater_test_uri,
		'header_file'                => 'plugin' === $type ? 'offer-target.php' : 'style.css',
		'installed_package_identity' => $ran_wp_release_updater_test_identity,
		'maximum_artifact_bytes'     => 52_428_800,
		'metadata_name'              => 'Offer Target',
		'offer_update_uri'           => $ran_wp_release_updater_test_uri,
		'php_runtime_version'        => PHP_VERSION,
		'provider_code'              => 'fixture',
		'repository_identity'        => $ran_wp_release_updater_test_facts['repository_identity'],
		'repository_locator'         => $ran_wp_release_updater_test_facts['repository_locator'],
		'staged_package_update_uri'  => $ran_wp_release_updater_test_uri,
		'target_type'                => $type,
		'theme_template'             => '',
		'wordpress_runtime_version'  => $GLOBALS['wp_version'],
	),
	// A host such as Core supplies these; the theme failure path relies on them to survive WordPress's own error handling.
	operation_guard: static fn (): bool => true,
	native_mutation_begin: static fn (): string => 'offer-release-proof',
	native_mutation_complete: static function (): void {}
);
if ( ! $ran_wp_release_updater_test_updater instanceof NativePackageUpdater ) {
	throw new RuntimeException( 'The offer-release target could not be constructed.' );
}
$ran_wp_release_updater_test_updater->register();

/** Reads the stored update check row directly, bypassing every transient filter. */
function ran_wp_release_updater_test_offer_raw_row( string $option ): ?string {
	global $wpdb;
	$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", '_site_transient_' . $option ) );
	return is_string( $value ) ? $value : null;
}
function ran_wp_release_updater_test_offer_carried( ?string $bytes ): bool {
	return is_string( $bytes ) && str_contains( $bytes, 'ran-wp-release-updater:v1:' );
}

set_site_transient(
	$ran_wp_release_updater_test_option,
	(object) array(
		'last_checked' => time(),
		'checked'      => array( $ran_wp_release_updater_test_identity => $ran_wp_release_updater_test_installed ),
		'response'     => array(),
	)
);
// Without the host's intent an older release is refused and nothing is supplied; the intent changes nothing for the same version.
$ran_wp_release_updater_test_unintended = apply_filters( 'ran_wp_release_updater_offer_release_v1', null, $type, $ran_wp_release_updater_test_identity, 'release-offer-' . $type, 'v2.0.0' );
$ran_wp_release_updater_test_unheld     = null === ( get_site_transient( $ran_wp_release_updater_test_option )->response[ $ran_wp_release_updater_test_identity ] ?? null );
$ran_wp_release_updater_test_report     = apply_filters( 'ran_wp_release_updater_offer_release_v1', null, $type, $ran_wp_release_updater_test_identity, 'release-offer-' . $type, 'v2.0.0', true );
// Each answered call inspects; each authorisation also validates one acquisition. The install then downloads once more.
$ran_wp_release_updater_test_calls = array( $ran_wp_release_updater_test_adapter->list_calls, $ran_wp_release_updater_test_adapter->inspect_calls, $ran_wp_release_updater_test_adapter->acquire_calls );
// While held, a read carries the offer and a save of that same read never stores it.
$ran_wp_release_updater_test_read  = get_site_transient( $ran_wp_release_updater_test_option );
$ran_wp_release_updater_test_entry = $ran_wp_release_updater_test_read->response[ $ran_wp_release_updater_test_identity ] ?? null;
$ran_wp_release_updater_test_held  = array( 'read_carries' => ran_wp_release_updater_test_offer_carried( (string) ( ( is_object( $ran_wp_release_updater_test_entry ) ? get_object_vars( $ran_wp_release_updater_test_entry ) : (array) $ran_wp_release_updater_test_entry )['package'] ?? '' ) ) );
// The save strips the entry from this same object before it is stored.
set_site_transient( $ran_wp_release_updater_test_option, $ran_wp_release_updater_test_read );
$ran_wp_release_updater_test_held['raw_row'] = ran_wp_release_updater_test_offer_raw_row( $ran_wp_release_updater_test_option );
if ( 'failure' === $mode ) {
	add_filter(
		'upgrader_post_install',
		static fn ( mixed $response, array $extra ): mixed => ( $extra[ $type ] ?? null ) === $ran_wp_release_updater_test_identity ? new WP_Error( 'offer_release_injected_post_copy_failure', 'Injected after Core copied the release.' ) : $response,
		PHP_INT_MAX,
		2
	);
}
ob_start();
// Native routes for an active target: the plugin count-one bulk route and the theme upgrade route.
$ran_wp_release_updater_test_result = 'plugin' === $type
	? ( new Plugin_Upgrader( new Bulk_Plugin_Upgrader_Skin() ) )->bulk_upgrade( array( $ran_wp_release_updater_test_identity ) )
	: ( new Theme_Upgrader( new Theme_Upgrader_Skin() ) )->upgrade( $ran_wp_release_updater_test_identity );
ob_end_clean();
$ran_wp_release_updater_test_result = is_array( $ran_wp_release_updater_test_result ) ? ( $ran_wp_release_updater_test_result[ $ran_wp_release_updater_test_identity ] ?? false ) : $ran_wp_release_updater_test_result;
// A later read and save in the same request must not bring the consumed offer back.
set_site_transient( $ran_wp_release_updater_test_option, get_site_transient( $ran_wp_release_updater_test_option ) );

// Registered after the upgrade so it runs after WordPress's restore (10), backup deletion (100) and the updater's finalization.
add_action(
	'shutdown',
	static function () use ( $type, $mode, $ran_wp_release_updater_test_direction, $ran_wp_release_updater_test_installed, $ran_wp_release_updater_test_unintended, $ran_wp_release_updater_test_unheld, $ran_wp_release_updater_test_calls, $ran_wp_release_updater_test_report, $ran_wp_release_updater_test_held, $ran_wp_release_updater_test_result, $ran_wp_release_updater_test_option, $ran_wp_release_updater_test_path, $ran_wp_release_updater_test_before, $ran_wp_release_updater_test_archive, $ran_wp_release_updater_test_adapter, $ran_wp_release_updater_test_updater, $ran_wp_release_updater_test_identity, $source_root ): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read exact local bytes for the disposable integration fixture.
		$after = (string) file_get_contents( $ran_wp_release_updater_test_path );
		$zip   = new ZipArchive();
		$zip->open( $ran_wp_release_updater_test_archive );
		$released = (string) $zip->getFromName( 'offer-target/' . basename( $ran_wp_release_updater_test_path ) );
		$zip->close();
		$origin   = ( new ReflectionClass( NativePackageUpdater::class ) )->getFileName();
		$outcome  = $ran_wp_release_updater_test_updater->status()['last_native_outcome'];
		$raw      = ran_wp_release_updater_test_offer_raw_row( $ran_wp_release_updater_test_option );
		$active   = 'plugin' === $type ? is_plugin_active( $ran_wp_release_updater_test_identity ) : get_stylesheet() === $ran_wp_release_updater_test_identity;
		$success  = 'success' === $mode;
		$older    = 'older' === $ran_wp_release_updater_test_direction;
		$header   = get_file_data( 'plugin' === $type ? $ran_wp_release_updater_test_path : dirname( $ran_wp_release_updater_test_path ) . '/style.css', array( 'Version' => 'Version' ) )['Version'];
		$checks   = array(
			'unintended'         => is_array( $ran_wp_release_updater_test_unintended ) && ( $older ? array( 'refused', 'release_older' ) : array( 'authorized', 'release_authorized' ) ) === array( $ran_wp_release_updater_test_unintended['status'], $ran_wp_release_updater_test_unintended['code'] ) && ( ! $older || $ran_wp_release_updater_test_unheld ),
			'authorized'         => is_array( $ran_wp_release_updater_test_report ) && 'authorized' === $ran_wp_release_updater_test_report['status'] && $ran_wp_release_updater_test_direction === $ran_wp_release_updater_test_report['relationship'],
			'held_read_carries'  => $ran_wp_release_updater_test_held['read_carries'],
			'held_save_clean'    => is_string( $ran_wp_release_updater_test_held['raw_row'] ) && ! ran_wp_release_updater_test_offer_carried( $ran_wp_release_updater_test_held['raw_row'] ),
			'final_row_clean'    => ! ran_wp_release_updater_test_offer_carried( $raw ),
			'authorising_calls'  => array( 0, 2, $older ? 1 : 2 ) === $ran_wp_release_updater_test_calls,
			'one_fresh_download' => array( 0, 3, $older ? 2 : 3 ) === array( $ran_wp_release_updater_test_adapter->list_calls, $ran_wp_release_updater_test_adapter->inspect_calls, $ran_wp_release_updater_test_adapter->acquire_calls ),
			'active_preserved'   => $active,
			'native_origin'      => $source_root . '/src/WordPress/NativePackageUpdater.php' === $origin,
			'result'             => $success ? false !== $ran_wp_release_updater_test_result && ! is_wp_error( $ran_wp_release_updater_test_result ) : is_wp_error( $ran_wp_release_updater_test_result ),
			'files'              => $success ? $released === $after && $after !== $ran_wp_release_updater_test_before : $ran_wp_release_updater_test_before === $after,
			'header_version'     => ( $success ? '2.0.0' : $ran_wp_release_updater_test_installed ) === $header,
			'outcome'            => is_array( $outcome ) && 'release-offer-' . $type === $outcome['release_identity'] && ( $success ? 'verified_success' === $outcome['outcome'] : 'verified_success' !== $outcome['outcome'] ),
			'offer_not_supplied' => null === ( get_site_transient( $ran_wp_release_updater_test_option )->response[ $ran_wp_release_updater_test_identity ] ?? null ),
		);
		$evidence = array(
			'pass'         => ! in_array( false, $checks, true ),
			'type'         => $type,
			'mode'         => $mode,
			'direction'    => $ran_wp_release_updater_test_direction,
			'checks'       => $checks,
			'report'       => $ran_wp_release_updater_test_report,
			'result_code'  => is_wp_error( $ran_wp_release_updater_test_result ) ? $ran_wp_release_updater_test_result->get_error_code() : null,
			'outcome'      => $outcome,
			'raw_row_kept' => is_string( $raw ),
		);
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Write exact evidence bytes for the disposable integration fixture. Native JSON preserves fixture bytes without WordPress helpers.
		file_put_contents( (string) getenv( 'RAN_WP_RELEASE_UPDATER_OFFER_OUTPUT' ), json_encode( $evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) );
	},
	PHP_INT_MAX
);
