<?php

$sourceRoot = getenv( 'RAN_WP_RELEASE_UPDATER_SOURCE_ROOT' );
$markerFile = getenv( 'RAN_WP_RELEASE_UPDATER_MARKER_FILE' );
$markerRoot = $markerFile ? realpath( dirname( $markerFile ) ) : false;
$expectedSourceRoot = is_string( $markerRoot ) ? $markerRoot . '/site/wp-content/plugins/ran-wp-release-updater' : '';
if ( ! is_string( $sourceRoot ) || $expectedSourceRoot !== realpath( $sourceRoot ) || ! is_file( $expectedSourceRoot . '/bootstrap.php' ) || ! is_file( $expectedSourceRoot . '/runtime.php' ) ) throw new RuntimeException( 'Harness source must be the copied disposable updater source.' );
require_once $expectedSourceRoot . '/bootstrap.php';
$activation = $GLOBALS['ran_wp_release_updater_v1_broker']->activate( array( 'php_version' => PHP_VERSION, 'runtime_protocol' => 1, 'wordpress_version' => $GLOBALS['wp_version'] ) );
if ( true !== ( $activation['loaded'] ?? null ) ) throw new RuntimeException( 'Harness could not activate the selected updater runtime.' );

use RAN\WPReleaseUpdater\V1\Contract\BindingRecord;
use RAN\WPReleaseUpdater\V1\Provider\GitHub\GitHubCredentialResolver;
use RAN\WPReleaseUpdater\V1\Provider\GitHub\GitHubReleaseAdapter;

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
if ( ! class_exists( 'WP_Automatic_Updater' ) ) require_once ABSPATH . 'wp-admin/includes/class-wp-automatic-updater.php';

final class Phase24AutomaticUpdater extends WP_Automatic_Updater {
	public function resultFor( string $type, string $identity ): mixed {
		foreach ( $this->update_results[ $type ] ?? array() as $entry ) {
			$itemIdentity = 'plugin' === $type ? ( $entry->item->plugin ?? null ) : ( $entry->item->theme ?? null );
			if ( $identity === $itemIdentity ) return $entry->result;
		}
		return null;
	}
}

add_filter( 'filesystem_method', static fn () => 'direct' );
remove_action( 'upgrader_process_complete', 'wp_version_check' );
remove_action( 'upgrader_process_complete', 'wp_update_plugins' );
remove_action( 'upgrader_process_complete', 'wp_update_themes' );

$mailAttempts = 0;
add_filter(
	'pre_wp_mail',
	static function ( mixed $return, array $attributes ) use ( &$mailAttempts ): bool {
		unset( $return, $attributes );
		++$mailAttempts;
		return true;
	},
	PHP_INT_MIN,
	2
);

$sourceRoot  = getenv( 'RAN_WP_RELEASE_UPDATER_SOURCE_ROOT' );
$outputPath  = getenv( 'RAN_WP_RELEASE_UPDATER_OUTPUT' );
$pluginId    = getenv( 'RAN_WP_RELEASE_UPDATER_PLUGIN_ID' );
$themeId     = getenv( 'RAN_WP_RELEASE_UPDATER_THEME_ID' );
$pluginUri   = getenv( 'RAN_WP_RELEASE_UPDATER_PLUGIN_URI' );
$themeUri    = getenv( 'RAN_WP_RELEASE_UPDATER_THEME_URI' );
$archive     = getenv( 'RAN_WP_RELEASE_UPDATER_ARCHIVE' );
$marker      = getenv( 'RAN_WP_RELEASE_UPDATER_PHASE24' );
$markerFile  = getenv( 'RAN_WP_RELEASE_UPDATER_MARKER_FILE' );
$mode        = getenv( 'RAN_WP_RELEASE_UPDATER_MODE' );
$targetType  = getenv( 'RAN_WP_RELEASE_UPDATER_TARGET_TYPE' );

$markerRoot = $markerFile ? realpath( dirname( $markerFile ) ) : false;
if ( 'RAN_WP_RELEASE_UPDATER_PHASE24' !== $marker || ! $markerFile || ! is_file( $markerFile ) || is_link( $markerFile ) || $marker . "\n" !== file_get_contents( $markerFile ) || false === $markerRoot || '/private/tmp' !== realpath( dirname( $markerRoot ) ) || ! in_array( $mode, array( 'success', 'failure' ), true ) || ! in_array( $targetType, array( 'plugin', 'theme' ), true ) || ! is_string( $archive ) || ! is_file( $archive ) ) {
	throw new RuntimeException( 'Guarded phase-2.4 harness missing required marker/env settings.' );
}

$httpRequests = array( 'allowed' => 0, 'blocked' => 0, 'blocked_urls' => array(), 'guard' => 0, 'asset_writes' => 0, 'core_denied' => 0, 'credentialed' => 0, 'credential_leaks' => 0, 'loopback' => 0 );
add_filter(
	'pre_http_request',
	static function ( mixed $preempt, array $args, string $url ) use ( &$httpRequests, $targetType, $archive ): mixed {
		unset( $preempt );
		if ( 'https://phase24-network-guard.invalid/probe' === $url ) {
			if ( request_contains_fixture_credential( $args ) ) ++$httpRequests['credential_leaks'];
			++$httpRequests['guard'];
			return new WP_Error( 'phase24_network_forbidden', 'Network access is forbidden in the disposable proof.' );
		}
		$response = fixture_http_response( $url, $args, $targetType, $archive, $httpRequests );
		if ( $response instanceof WP_Error ) return $response;
		if ( is_array( $response ) ) {
			++$httpRequests['allowed'];
			return $response;
		}
		++$httpRequests['blocked'];
		if ( count( $httpRequests['blocked_urls'] ) < 16 ) $httpRequests['blocked_urls'][] = $url;
		return new WP_Error( 'phase24_network_forbidden', 'Network access is forbidden in the disposable proof.' );
	},
	PHP_INT_MIN,
	3
);
$networkProbe = wp_remote_get( 'https://phase24-network-guard.invalid/probe', array( 'timeout' => 1 ) );
$networkGuardProved = is_wp_error( $networkProbe ) && 'phase24_network_forbidden' === $networkProbe->get_error_code();
if ( ! $networkGuardProved ) throw new RuntimeException( 'Disposable network guard did not fail closed.' );

$identity = 'plugin' === $targetType ? $pluginId : $themeId;
$uri = 'plugin' === $targetType ? $pluginUri : $themeUri;
$target = build_target( $targetType, $identity, $uri, $archive, 'success' === $mode ? '1.0.0' : '2.0.0', 'success' === $mode ? '2.0.0' : '3.0.0' );

$evidence = array(
	'marker'     => $marker,
	'sourceRoot' => $sourceRoot,
	'core_upgrade' => 'success' === $mode ? run_core_upgrade_scenario( $target ) : run_core_upgrade_failure_scenario( $target ),
	'activation_readback' => array(
		'plugin_active' => is_plugin_active( $pluginId ), 'theme_active' => wp_get_theme()->get_stylesheet() === $themeId,
	),
	'sanity' => $target['sanity'],
	'database_readback' => array(
		$identity => readback_options( $target ),
	),
);

add_action( 'shutdown', static function () use ( &$evidence, $outputPath, $targetType, $identity, $target, &$httpRequests, $networkGuardProved, &$mailAttempts ): void {
	$slug = 'theme' === $targetType ? $identity : dirname( $identity );
	$evidence['post_shutdown'] = array(
		'version' => file_version( $targetType, $identity ),
		'bytes' => fixture_bytes( $targetType, $identity ),
		'digest' => fixture_digest( $targetType, $identity ),
		'backup_absent' => ! is_dir( backup_dir( $targetType, $slug ) ),
		'maintenance_absent' => ! is_file( ABSPATH . '.maintenance' ),
		'database' => readback_options( $target ),
		'network_guard_installed' => true,
		'network_guard_proved' => $networkGuardProved,
		'mail_attempts' => $mailAttempts,
		'mail_short_circuited' => true,
		'http' => $httpRequests,
	);
	$encoded = json_encode( $evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR );
	$evidence['post_shutdown']['credential_absent_from_evidence'] = ! str_contains( $encoded, 'phase24-token' );
	file_put_contents( $outputPath, json_encode( $evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) );
}, PHP_INT_MAX );

/** @return array<string,mixed> */
function build_target( string $type, string $identity, string $uri, string $archive, string $installedVersion, string $releaseVersion ): array {
	$binding = BindingRecord::create(
		array(
			'canonical_repository_locator'   => 'phase24-owner/phase24-' . $type,
			'canonical_update_uri'          => $uri,
			'installed_package_identity'    => $identity,
			'php_runtime_version'           => '8.2',
			'provider_code'                 => 'github',
			'release_channel'               => 'stable',
			'stable_repository_identity'    => '101',
			'target_type'                   => $type,
			'update_policy'                 => getenv( 'RAN_WP_RELEASE_UPDATER_POLICY' ) ?: 'manual',
			'wordpress_runtime_version'      => '6.8',
		)
	);
	$archivePolicy = array(
		'archive_root'              => ( 'theme' === $type ? 'phase24-theme' : 'phase24-plugin' ),
		'configuration_update_uri'   => $uri,
		'header_file'               => ( 'theme' === $type ? 'style.css' : 'phase24-plugin.php' ),
		'installed_package_identity' => $identity,
		'metadata_name'             => 'Phase24 ' . ucfirst( $type ),
		'offer_update_uri'           => $uri,
		'php_runtime_version'       => '8.2',
		'provider_code'             => 'github',
		'repository_identity'       => '101',
		'repository_locator'        => 'phase24-owner/phase24-' . $type,
		'staged_package_update_uri' => $uri,
		'target_type'               => $type,
		'wordpress_runtime_version'  => '6.8',
	);
	$configuration = array(
		'headers' => array(
			'Author'      => 'Phase24',
			'Description' => 'Phase24 disposable fixture',
			'Name'        => 'Phase24 ' . ucfirst( $type ),
			'PluginURI'   => $uri,
			'RequiresPHP' => '8.2',
			'RequiresWP'  => '6.8',
			'UpdateURI'   => $uri,
			'Version'     => $installedVersion,
		),
		'installed_package_identity' => $identity,
		'policy'                    => $binding->toArray()['update_policy'],
		'target_type'               => $type,
		'update_uri'                => $uri,
	);
	$updater = GitHubReleaseAdapter::registerFromConfiguration(
		$configuration,
		$binding,
		new GitHubCredentialResolver( static fn (): string => 'phase24-token' ),
		$GLOBALS['wpdb'],
		$archivePolicy
	);
	if ( ! is_object( $updater ) ) {
		throw new RuntimeException( 'Could not construct updater for ' . $type );
	}

	$packageObservation = (object) array( 'calls' => 0, 'package' => null );
	add_filter(
		'upgrader_pre_download',
		static function ( mixed $reply, string $package, mixed $upgrader, array $hookExtra ) use ( $type, $identity, $packageObservation ): mixed {
			unset( $upgrader );
			$key = 'plugin' === $type ? 'plugin' : 'theme';
			if ( $identity === ( $hookExtra[ $key ] ?? null ) ) {
				++$packageObservation->calls;
				$packageObservation->package = $package;
			}
			return $reply;
		},
		PHP_INT_MIN,
		4
	);

	$offer = apply_filters( 'update_' . ( 'plugin' === $type ? 'plugins_' : 'themes_' ) . parse_url( $uri, PHP_URL_HOST ), false, array( 'Version' => $installedVersion, 'UpdateURI' => $uri ), $identity, array() );
	if ( ! is_array( $offer ) || ! is_string( $offer['package'] ?? null ) || ! str_starts_with( $offer['package'], 'ran-wp-release-updater:v1:' ) ) {
		throw new RuntimeException( 'Core offer did not carry a neutral release token.' );
	}

	return array(
		'type'  => $type,
		'identity' => $identity,
		'uri' => $uri,
		'offer' => $offer,
		'packageObservation' => $packageObservation,
		'policy' => $binding->toArray()['update_policy'],
		'targetName' => 'ran_wp_release_updater_target_v1_' . BindingRecord::targetFenceKey( array( 'installed_package_identity' => $identity ) ),
		'sanity' => array(
			'offer_hook_fired' => is_array( $offer ),
		),
	);
}

/** @return array<string,mixed> */
function run_core_upgrade_scenario( array $target ) : array {
	$type = $target['type'];
	$identity = $target['identity'];
	$offer = $target['offer'];
	$slug = 'theme' === $type ? basename( $identity ) : dirname( $identity );
	$backup = backup_dir( $type, $slug );
	if ( is_dir( $backup ) ) {
		rrmdir_recursive( $backup );
	}

	$before = file_version( $type, $identity );
	$execution = execute_core_upgrade( $target );
	$result = $execution['result'];

	return array(
		'upgraded'    => true === $result,
		'result_code' => is_wp_error( $result ) ? $result->get_error_code() : null,
		'version_before' => $before,
		'version_after'  => file_version( $type, $identity ),
		'bytes_after' => fixture_bytes( $type, $identity ),
		'backup_cleaned' => ! is_dir( $backup ),
		'maintenance_file_absent' => ! is_file( ABSPATH . '.maintenance' ),
		'offer_token_used' => 1 === $target['packageObservation']->calls && is_string( $target['packageObservation']->package ) && hash_equals( $offer['package'], $target['packageObservation']->package ),
		'package_handoff_calls' => $target['packageObservation']->calls,
		'cron_context' => $execution['cron_context'],
		'automatic_result_observed' => $execution['automatic_result_observed'],
		'automatic_plugin_was_active' => $execution['automatic_plugin_was_active'],
		'manual_plugin_was_deactivated' => $execution['manual_plugin_was_deactivated'],
	);
}

function run_core_upgrade_failure_scenario( array $target ): array {
	$type = $target['type'];
	$identity = $target['identity'];
	$offer = $target['offer'];
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
	$execution = execute_core_upgrade( $target );
	$result = $execution['result'];
	remove_filter( 'upgrader_post_install', $injectFailure, PHP_INT_MAX );
	$backup = backup_dir( $type, $slug );
	$installResult = $result;
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
		'offer_token_used' => 1 === $target['packageObservation']->calls && is_string( $target['packageObservation']->package ) && hash_equals( $offer['package'], $target['packageObservation']->package ),
		'package_handoff_calls' => $target['packageObservation']->calls,
		'cron_context' => $execution['cron_context'],
		'automatic_result_observed' => $execution['automatic_result_observed'],
		'automatic_plugin_was_active' => $execution['automatic_plugin_was_active'],
		'manual_plugin_was_deactivated' => $execution['manual_plugin_was_deactivated'],
	);
}

/** @param array<string,mixed> $target @return array<string,mixed> */
function execute_core_upgrade( array $target ): array {
	$type = $target['type'];
	$identity = $target['identity'];
	$item = prime_core_offer( $target );
	$cronContext = wp_doing_cron();
	$automaticPluginWasActive = 'plugin' === $type && is_plugin_active( $identity );
	$manualPluginWasDeactivated = null;
	$automaticResultObserved = false;
	if ( 'automatic' === $target['policy'] ) {
		prime_automatic_background_state( $type );
		$updater = new Phase24AutomaticUpdater();
		$updater->run();
		$result = $updater->resultFor( $type, $identity );
		$automaticResultObserved = null !== $result;
	} elseif ( 'plugin' === $type ) {
		$result = ( new Plugin_Upgrader( new Automatic_Upgrader_Skin() ) )->upgrade( $identity, array( 'clear_update_cache' => false ) );
		$manualPluginWasDeactivated = ! is_plugin_active( $identity );
		if ( $manualPluginWasDeactivated ) {
			$activation = activate_plugin( $identity, '', false, true );
			if ( is_wp_error( $activation ) ) throw new RuntimeException( 'Manual plugin reactivation failed.' );
		}
	} else {
		$result = ( new Theme_Upgrader( new Automatic_Upgrader_Skin() ) )->upgrade( $identity, array( 'clear_update_cache' => false ) );
	}
	return array(
		'result' => $result,
		'cron_context' => $cronContext,
		'automatic_result_observed' => $automaticResultObserved,
		'automatic_plugin_was_active' => $automaticPluginWasActive,
		'manual_plugin_was_deactivated' => $manualPluginWasDeactivated,
	);
}

/** @param array<string,mixed> $target */
function prime_core_offer( array $target ): object {
	$identity = $target['identity'];
	$offer = $target['offer'];
	$offer['new_version'] = $offer['version'];
	$transient = (object) array( 'last_checked' => time(), 'response' => array(), 'checked' => array() );
	if ( 'plugin' === $target['type'] ) {
		foreach ( get_plugins() as $file => $plugin ) $transient->checked[ $file ] = $plugin['Version'];
		$offer['plugin'] = $identity;
		$transient->response[ $identity ] = (object) $offer;
		set_site_transient( 'update_plugins', $transient, 60 );
		return $transient->response[ $identity ];
	}
	foreach ( wp_get_themes() as $slug => $theme ) $transient->checked[ $slug ] = $theme->get( 'Version' );
	$offer['theme'] = $identity;
	$offer['slug'] = $identity;
	$transient->response[ $identity ] = $offer;
	set_site_transient( 'update_themes', $transient, 60 );
	return (object) $offer;
}

function prime_automatic_background_state( string $targetType ): void {
	if ( 'plugin' === $targetType ) {
		$themes = (object) array( 'last_checked' => time(), 'response' => array(), 'checked' => array() );
		foreach ( wp_get_themes() as $slug => $theme ) $themes->checked[ $slug ] = $theme->get( 'Version' );
		set_site_transient( 'update_themes', $themes, 60 );
	} else {
		$plugins = (object) array( 'last_checked' => time(), 'response' => array(), 'checked' => array() );
		foreach ( get_plugins() as $file => $plugin ) $plugins->checked[ $file ] = $plugin['Version'];
		set_site_transient( 'update_plugins', $plugins, 60 );
	}
	set_site_transient( 'update_core', (object) array( 'last_checked' => time(), 'updates' => array(), 'version_checked' => wp_get_wp_version() ), 60 );
}

/** @param array<string,mixed> $args @param array<string,mixed> $counts @return array<string,mixed>|WP_Error|null */
function fixture_http_response( string $url, array $args, string $type, string $archive, array &$counts ): array|WP_Error|null {
	$parts = parse_url( $url );
	if ( is_array( $parts ) && in_array( $parts['scheme'] ?? null, array( 'http', 'https' ), true ) && 'api.wordpress.org' === ( $parts['host'] ?? null ) && in_array( $parts['path'] ?? null, array( '/core/version-check/1.7/', '/plugins/update-check/1.1/', '/themes/update-check/1.1/' ), true ) ) {
		if ( request_contains_fixture_credential( $args ) ) ++$counts['credential_leaks'];
		++$counts['core_denied'];
		return new WP_Error( 'phase24_core_network_denied', 'WordPress.org refresh is denied in the disposable proof.' );
	}
	if ( is_array( $parts ) && 'http' === ( $parts['scheme'] ?? null ) && '127.0.0.1' === ( $parts['host'] ?? null ) && '/' === ( $parts['path'] ?? null ) && is_string( $parts['query'] ?? null ) ) {
		if ( request_contains_fixture_credential( $args ) ) ++$counts['credential_leaks'];
		parse_str( $parts['query'], $query );
		$key = $query['wp_scrape_key'] ?? null;
		$nonce = $query['wp_scrape_nonce'] ?? null;
		if ( is_string( $key ) && is_string( $nonce ) && hash_equals( md5( $nonce ), $key ) && array( 'wp_scrape_key', 'wp_scrape_nonce' ) === array_keys( $query ) ) {
			++$counts['loopback'];
			return array( 'body' => '###### wp_scraping_result_start:' . $key . ' ######null###### wp_scraping_result_end:' . $key . ' ######', 'headers' => array(), 'response' => array( 'code' => 200, 'message' => 'OK' ) );
		}
	}
	$locator = 'phase24-owner/phase24-' . $type;
	$repository = 'https://api.github.com/repos/' . $locator;
	$release = 'https://api.github.com/repos/' . $locator . '/releases/201';
	$commit = 'https://api.github.com/repos/' . $locator . '/commits/' . rawurlencode( 'success' === getenv( 'RAN_WP_RELEASE_UPDATER_MODE' ) ? 'v2.0.0' : 'v3.0.0' );
	$asset = 'https://api.github.com/repos/' . $locator . '/releases/assets/301';
	$knownUrls = array( 'https://api.github.com/repos/' . $locator . '/releases?per_page=20&page=1', $repository, $release, $commit, $asset );
	if ( ! in_array( $url, $knownUrls, true ) || ! github_request_contract( $args, $asset === $url ) ) return null;
	++$counts['credentialed'];
	$tag = 'success' === getenv( 'RAN_WP_RELEASE_UPDATER_MODE' ) ? 'v2.0.0' : 'v3.0.0';
	$releaseBody = array(
		'id' => 201,
		'draft' => false,
		'prerelease' => false,
		'immutable' => true,
		'html_url' => 'https://github.com/' . $locator . '/releases/tag/' . $tag,
		'published_at' => '2026-08-22T10:00:00Z',
		'tag_name' => $tag,
		'assets' => array( array( 'id' => 301, 'name' => basename( $archive ), 'size' => filesize( $archive ), 'state' => 'uploaded', 'digest' => 'sha256:' . hash_file( 'sha256', $archive ) ) ),
	);
	if ( 'https://api.github.com/repos/' . $locator . '/releases?per_page=20&page=1' === $url ) {
		return github_response( 200, array( $releaseBody ) );
	}
	if ( $repository === $url ) {
		return github_response( 200, array( 'id' => 101 ) );
	}
	if ( $release === $url ) {
		return github_response( 200, $releaseBody );
	}
	if ( $commit === $url ) {
		return github_response( 200, array( 'sha' => str_repeat( 'a', 40 ) ) );
	}
	if ( $asset === $url && true === ( $args['stream'] ?? false ) && is_string( $args['filename'] ?? null ) && '' !== $args['filename'] ) {
		if ( false === copy( $archive, $args['filename'] ) ) {
			return null;
		}
		++$counts['asset_writes'];
		return github_response( 200, null, $args['filename'] );
	}
	return null;
}

/** @param array<string,mixed> $args */
function github_request_contract( array $args, bool $asset ): bool {
	$headers = $args['headers'] ?? null;
	return is_array( $headers )
		&& 'GET' === ( $args['method'] ?? null )
		&& 0 === ( $args['redirection'] ?? null )
		&& 10 === ( $args['timeout'] ?? null )
		&& 'Bearer phase24-token' === ( $headers['Authorization'] ?? null )
		&& 'ran-wp-release-updater/0.1.0-beta.1' === ( $headers['User-Agent'] ?? null )
		&& '2022-11-28' === ( $headers['X-GitHub-Api-Version'] ?? null )
		&& ( $asset ? 'application/octet-stream' : 'application/vnd.github+json' ) === ( $headers['Accept'] ?? null );
}

/** @param array<string,mixed> $args */
function request_contains_fixture_credential( array $args ): bool {
	return str_contains( serialize( $args ), 'phase24-token' );
}

/** @return array<string,mixed> */
function github_response( int $status, mixed $body, ?string $file = null ): array {
	$response = array( 'body' => null === $body ? '' : json_encode( $body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES ), 'headers' => array(), 'response' => array( 'code' => $status, 'message' => 'OK' ) );
	if ( null !== $file ) {
		$response['filename'] = $file;
	}
	return $response;
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
