<?php

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$source_root = getenv( 'RAN_WP_RELEASE_UPDATER_SOURCE_ROOT' );
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$marker_file = getenv( 'RAN_WP_RELEASE_UPDATER_MARKER_FILE' );
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$marker_root = $marker_file ? realpath( dirname( $marker_file ) ) : false;
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$expected_source_root = is_string( $marker_root ) ? $marker_root . '/site/wp-content/plugins/ran-wp-release-updater' : '';
if ( ! is_string( $source_root ) || realpath( $source_root ) !== $expected_source_root || ! is_file( $expected_source_root . '/bootstrap.php' ) || ! is_file( $expected_source_root . '/runtime.php' ) ) {
	throw new RuntimeException( 'Harness source must be the copied disposable updater source.' );
}

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
if ( ! class_exists( 'WP_Automatic_Updater' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-automatic-updater.php';
}

final class RAN_WP_RELEASE_UPDATER_Test_Phase24AutomaticUpdater extends WP_Automatic_Updater {
	public function update_one( string $type, object $item ): mixed {
		return $this->update( $type, $item );
	}
}

add_filter( 'filesystem_method', static fn () => 'direct' );
remove_action( 'upgrader_process_complete', 'wp_version_check' );
remove_action( 'upgrader_process_complete', 'wp_update_plugins' );
remove_action( 'upgrader_process_complete', 'wp_update_themes' );

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$phase24_vcs_checkout = array(
	'calls'    => 0,
	'contexts' => array(),
);
add_filter(
	'automatic_updates_is_vcs_checkout',
	static function ( bool $checkout, string $context ) use ( &$phase24_vcs_checkout ): bool {
		unset( $checkout );
		++$phase24_vcs_checkout['calls'];
		$phase24_vcs_checkout['contexts'][] = $context;
		return false;
	},
	PHP_INT_MAX,
	2
);

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$mail_attempts = 0;
add_filter(
	'pre_wp_mail',
	static function ( mixed $mail_return, array $attributes ) use ( &$mail_attempts ): bool {
		unset( $mail_return, $attributes );
		++$mail_attempts;
		return true;
	},
	PHP_INT_MIN,
	2
);

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$source_root = getenv( 'RAN_WP_RELEASE_UPDATER_SOURCE_ROOT' );
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$output_path = getenv( 'RAN_WP_RELEASE_UPDATER_OUTPUT' );
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$plugin_id = getenv( 'RAN_WP_RELEASE_UPDATER_PLUGIN_ID' );
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$theme_id = getenv( 'RAN_WP_RELEASE_UPDATER_THEME_ID' );
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$manager_theme_id = getenv( 'RAN_WP_RELEASE_UPDATER_MANAGER_THEME_ID' );
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$plugin_uri = getenv( 'RAN_WP_RELEASE_UPDATER_PLUGIN_URI' );
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$theme_uri = getenv( 'RAN_WP_RELEASE_UPDATER_THEME_URI' );
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$archive = getenv( 'RAN_WP_RELEASE_UPDATER_ARCHIVE' );
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$marker = getenv( 'RAN_WP_RELEASE_UPDATER_PHASE24' );
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$marker_file = getenv( 'RAN_WP_RELEASE_UPDATER_MARKER_FILE' );
// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- This CLI/eval-file fixture variable is local scenario/process state, not a WordPress request global override.
$mode = getenv( 'RAN_WP_RELEASE_UPDATER_MODE' );
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$failure_stage = getenv( 'RAN_WP_RELEASE_UPDATER_FAILURE_STAGE' );
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$target_type = getenv( 'RAN_WP_RELEASE_UPDATER_TARGET_TYPE' );

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$marker_root = $marker_file ? realpath( dirname( $marker_file ) ) : false;
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$workspace_root = $marker_root ? realpath( dirname( $marker_root ) ) : false;
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read exact local bytes for the disposable integration fixture without requiring WordPress filesystem initialization.
if ( 'RAN_WP_RELEASE_UPDATER_PHASE24' !== $marker || ! $marker_file || ! is_file( $marker_file ) || is_link( $marker_file ) || file_get_contents( $marker_file ) !== $marker . "\n" || false === $marker_root || false === $workspace_root || ! str_ends_with( str_replace( '\\', '/', $workspace_root ), '/.workspaces/p0.4' ) || ! in_array( $mode, array( 'success', 'download', 'validation', 'install' ), true ) || ! in_array( $failure_stage, array( 'success', 'download', 'validation', 'install' ), true ) || ! in_array( $target_type, array( 'plugin', 'theme' ), true ) || ! is_string( $archive ) || ! is_file( $archive ) ) {
	throw new RuntimeException( 'Guarded phase-2.4 harness missing required marker/env settings.' );
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$http_requests = array(
	'allowed'           => 0,
	'blocked'           => 0,
	'blocked_urls'      => array(),
	'guard'             => 0,
	'asset_writes'      => 0,
	'core_denied'       => 0,
	'credentialed'      => 0,
	'credential_leaks'  => 0,
	'injected_download' => 0,
	'loopback'          => 0,
);
add_filter(
	'pre_http_request',
	static function ( mixed $preempt, array $args, string $url ) use ( &$http_requests, $target_type, $archive ): mixed {
		unset( $preempt );
		if ( 'https://phase24-network-guard.invalid/probe' === $url ) {
			if ( ran_wp_release_updater_test_request_contains_fixture_credential( $args ) ) {
				++$http_requests['credential_leaks'];
			}
			++$http_requests['guard'];
			return new WP_Error( 'phase24_network_forbidden', 'Network access is forbidden in the disposable proof.' );
		}
		$response = ran_wp_release_updater_test_fixture_http_response( $url, $args, $target_type, $archive, $http_requests );
		if ( $response instanceof WP_Error ) {
			return $response;
		}
		if ( is_array( $response ) ) {
			++$http_requests['allowed'];
			return $response;
		}
		++$http_requests['blocked'];
		if ( count( $http_requests['blocked_urls'] ) < 16 ) {
			$http_requests['blocked_urls'][] = $url;
		}
		return new WP_Error( 'phase24_network_forbidden', 'Network access is forbidden in the disposable proof.' );
	},
	PHP_INT_MIN,
	3
);
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$network_probe = wp_remote_get( 'https://phase24-network-guard.invalid/probe', array( 'timeout' => 1 ) );
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$network_guard_proved = is_wp_error( $network_probe ) && 'phase24_network_forbidden' === $network_probe->get_error_code();
if ( ! $network_guard_proved ) {
	throw new RuntimeException( 'Disposable network guard did not fail closed.' );
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$identity = 'plugin' === $target_type ? $plugin_id : $theme_id;
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$uri = 'plugin' === $target_type ? $plugin_uri : $theme_uri;
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$target = ran_wp_release_updater_test_build_target( $target_type, $identity, $uri, $archive, 'success' === $mode ? '1.0.0' : '2.0.0' );

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$evidence = array(
	'marker'                          => $marker,
	'sourceRoot'                      => $source_root,
	'core_upgrade'                    => 'success' === $mode ? ran_wp_release_updater_test_run_core_upgrade_scenario( $target ) : ( in_array( $failure_stage, array( 'download', 'validation' ), true ) ? ran_wp_release_updater_test_run_preoffer_failure_scenario( $target, $failure_stage ) : ran_wp_release_updater_test_run_core_upgrade_failure_scenario( $target, $failure_stage ) ),
	'automatic_vcs_checkout_override' => $phase24_vcs_checkout,
	'activation_readback'             => array(
		'plugin_active'        => is_plugin_active( $plugin_id ),
		'theme_active'         => wp_get_theme()->get_stylesheet() === $theme_id,
		'manager_theme_active' => wp_get_theme()->get_stylesheet() === $manager_theme_id,
	),
	'registration'                    => $target['registration'],
	'sanity'                          => $target['sanity'],
	'database_readback'               => array(
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
		$identity => ran_wp_release_updater_test_readback_options( $target ),
	),
);

add_action(
	'shutdown',
	static function () use ( &$evidence, $output_path, $target_type, $identity, $target, &$http_requests, $network_guard_proved, &$mail_attempts ): void {
		$slug                      = 'theme' === $target_type ? $identity : dirname( $identity );
		$evidence['post_shutdown'] = array(
			'version'                 => ran_wp_release_updater_test_file_version( $target_type, $identity ),
			'bytes'                   => ran_wp_release_updater_test_fixture_bytes( $target_type, $identity ),
			'digest'                  => ran_wp_release_updater_test_fixture_digest( $target_type, $identity ),
			'manifest'                => ran_wp_release_updater_test_fixture_manifest( $target_type, $identity ),
			'backup_absent'           => ! is_dir( ran_wp_release_updater_test_backup_dir( $target_type, $slug ) ),
			'maintenance_absent'      => ! is_file( ABSPATH . '.maintenance' ),
			'database'                => ran_wp_release_updater_test_readback_options( $target ),
			'network_guard_installed' => true,
			'network_guard_proved'    => $network_guard_proved,
			'mail_attempts'           => $mail_attempts,
			'mail_short_circuited'    => true,
			'http'                    => $http_requests,
		);
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Native JSON preserves protocol or fixture bytes without requiring WordPress helpers or their fallback behavior.
		$encoded = json_encode( $evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR );
		$evidence['post_shutdown']['credential_absent_from_evidence'] = ! str_contains( $encoded, 'phase24-token' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Write exact bytes for the disposable integration fixture; WordPress helpers would alter the boundary under test. Native JSON preserves protocol or fixture bytes without requiring WordPress helpers or their fallback behavior.
		file_put_contents( $output_path, json_encode( $evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) );
	},
	PHP_INT_MAX
);

/** @return array<string,mixed> */
// phpcs:ignore Universal.Files.SeparateFunctionsFromOO.Mixed -- The self-contained fixture combines foreign functions/classes with the test harness that exercises them.
function ran_wp_release_updater_test_build_target( string $type, string $identity, string $uri, string $archive, string $installed_version ): array {
	$policy        = getenv( 'RAN_WP_RELEASE_UPDATER_POLICY' );
	$policy        = $policy ? $policy : 'manual';
	$failure_stage = getenv( 'RAN_WP_RELEASE_UPDATER_FAILURE_STAGE' );
	$handles       = $GLOBALS['phase24_handles'] ?? null;
	$target        = is_array( $handles ) ? ( $handles[ $type ] ?? null ) : null;
	$manager       = is_array( $handles ) ? ( $handles['manager'] ?? null ) : null;
	if ( ! is_object( $target ) || ! is_object( $manager ) || ! method_exists( $target, 'status' ) || ! method_exists( $manager, 'status' ) ) {
		throw new RuntimeException( 'Fixture-owned concise registrations are unavailable.' );
	}
	$target_status  = $target->status();
	$manager_status = $manager->status();
	if ( 'active' !== ( $target_status['state'] ?? null ) || 'active' !== ( $manager_status['state'] ?? null ) ) {
		throw new RuntimeException( 'Fixture-owned concise registrations were not active after normal WordPress hooks.' );
	}

	$package_observation = (object) array(
		'calls'   => 0,
		'package' => null,
	);
	add_filter(
		'upgrader_pre_download',
		static function ( mixed $reply, string $package, mixed $upgrader, array $hook_extra ) use ( $type, $identity, $package_observation ): mixed {
			unset( $upgrader );
			$key = 'plugin' === $type ? 'plugin' : 'theme';
			if ( ( $hook_extra[ $key ] ?? null ) === $identity ) {
				++$package_observation->calls;
				$package_observation->package = $package;
			}
			return $reply;
		},
		PHP_INT_MIN,
		4
	);

	$offer = apply_filters(
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Exercise the native WordPress hostname-specific update hook; its foreign identifier must stay exact. Native URL parsing preserves provider and bootstrap validation semantics independently of WordPress helpers.
		'update_' . ( 'plugin' === $type ? 'plugins_' : 'themes_' ) . parse_url( $uri, PHP_URL_HOST ),
		false,
		array(
			'Version'   => $installed_version,
			'UpdateURI' => $uri,
		),
		$identity,
		array()
	);
	$neutral_offer = is_array( $offer ) && is_string( $offer['package'] ?? null ) && str_starts_with( $offer['package'], 'ran-wp-release-updater:v1:' );
	if ( in_array( $failure_stage, array( 'download', 'validation' ), true ) && ! $neutral_offer ) {
		$status = $target->status();
		return array(
			'type'               => $type,
			'identity'           => $identity,
			'uri'                => $uri,
			'offer'              => false,
			'packageObservation' => $package_observation,
			'policy'             => $policy,
			'failure_code'       => $status['native']['failure_code'] ?? null,
			'validation_code'    => $status['native']['candidate_validation_code'] ?? null,
			'registration'       => array(
				'target'  => $target_status,
				'manager' => $manager_status,
			),
			'targetName'         => 'ran_wp_release_updater_target_v1_' . \RAN\WPReleaseUpdater\V1\Contract\BindingRecord::target_fence_key(
				array(
					'network_id'                 => 1,
					'target_type'                => $type,
					'installed_package_identity' => $identity,
				)
			),
			'sanity'             => array( 'offer_hook_fired' => false ),
		);
	}
	if ( ! $neutral_offer ) {
		throw new RuntimeException( 'Core offer did not carry a neutral release token.' );
	}

	return array(
		'type'               => $type,
		'identity'           => $identity,
		'uri'                => $uri,
		'offer'              => $offer,
		'packageObservation' => $package_observation,
		'policy'             => $policy,
		'registration'       => array(
			'target'  => $target_status,
			'manager' => $manager_status,
		),
		'targetName'         => 'ran_wp_release_updater_target_v1_' . \RAN\WPReleaseUpdater\V1\Contract\BindingRecord::target_fence_key(
			array(
				'network_id'                 => 1,
				'target_type'                => $type,
				'installed_package_identity' => $identity,
			)
		),
		'sanity'             => array(
			'offer_hook_fired' => is_array( $offer ),
		),
	);
}

/**
 * @param array<string,mixed> $target
 * @return array<string,mixed>
 */
function ran_wp_release_updater_test_run_preoffer_failure_scenario( array $target, string $stage ): array {
	$before   = ran_wp_release_updater_test_file_version( $target['type'], $target['identity'] );
	$manifest = ran_wp_release_updater_test_fixture_manifest( $target['type'], $target['identity'] );
	$code     = 'validation' === $stage ? ( $target['validation_code'] ?? null ) : ( $target['failure_code'] ?? null );
	return array(
		'failure_stage'                 => $stage,
		'failed'                        => is_string( $code ),
		'result_code'                   => $code,
		'version_before'                => $before,
		'version_after'                 => ran_wp_release_updater_test_file_version( $target['type'], $target['identity'] ),
		'manifest_before'               => $manifest,
		'injected_post_copy'            => array(),
		'rollback_backup_path_exists'   => false,
		'maintenance_file_exists'       => is_file( ABSPATH . '.maintenance' ),
		'offer_token_used'              => false,
		'package_handoff_calls'         => 0,
		'cron_context'                  => wp_doing_cron(),
		'automatic_result_observed'     => false,
		'automatic_plugin_was_active'   => 'plugin' === $target['type'] && is_plugin_active( $target['identity'] ),
		'manual_plugin_was_deactivated' => null,
	);
}

/**
 * @return array<string,mixed>
 * @param array<string,mixed> $target
 */
function ran_wp_release_updater_test_run_core_upgrade_scenario( array $target ): array {
	$type     = $target['type'];
	$identity = $target['identity'];
	$offer    = $target['offer'];
	$slug     = 'theme' === $type ? basename( $identity ) : dirname( $identity );
	$backup   = ran_wp_release_updater_test_backup_dir( $type, $slug );
	if ( is_dir( $backup ) ) {
		ran_wp_release_updater_test_rrmdir_recursive( $backup );
	}

	$before          = ran_wp_release_updater_test_file_version( $type, $identity );
	$manifest_before = ran_wp_release_updater_test_fixture_manifest( $type, $identity );
	$execution       = ran_wp_release_updater_test_execute_core_upgrade( $target );
	$result          = $execution['result'];

	return array(
		'upgraded'                      => true === $result,
		'result_code'                   => is_wp_error( $result ) ? $result->get_error_code() : null,
		'version_before'                => $before,
		'version_after'                 => ran_wp_release_updater_test_file_version( $type, $identity ),
		'manifest_before'               => $manifest_before,
		'bytes_after'                   => ran_wp_release_updater_test_fixture_bytes( $type, $identity ),
		'backup_cleaned'                => ! is_dir( $backup ),
		'maintenance_file_absent'       => ! is_file( ABSPATH . '.maintenance' ),
		'offer_token_used'              => 1 === $target['packageObservation']->calls && is_string( $target['packageObservation']->package ) && hash_equals( $offer['package'], $target['packageObservation']->package ),
		'package_handoff_calls'         => $target['packageObservation']->calls,
		'cron_context'                  => $execution['cron_context'],
		'automatic_result_observed'     => $execution['automatic_result_observed'],
		'automatic_plugin_was_active'   => $execution['automatic_plugin_was_active'],
		'manual_plugin_was_deactivated' => $execution['manual_plugin_was_deactivated'],
	);
}

/**
 * @param array<string,mixed> $target
 * @return array<string,mixed>
 */
function ran_wp_release_updater_test_run_core_upgrade_failure_scenario( array $target, string $failure_stage ): array {
	$type           = $target['type'];
	$identity       = $target['identity'];
	$offer          = $target['offer'];
	$slug           = 'theme' === $type ? basename( $identity ) : dirname( $identity );
	$before         = ran_wp_release_updater_test_file_version( $type, $identity );
	$injected       = array(
		'post_copy_seen'      => false,
		'destination_version' => null,
		'backup_present'      => false,
		'destination_bytes'   => null,
		'destination_digest'  => null,
	);
	$inject_failure = static function ( mixed $response, array $hook_extra, array $install_result ) use ( $type, $identity, &$injected ): mixed {
		$key = 'plugin' === $type ? 'plugin' : 'theme';
		if ( ( $hook_extra[ $key ] ?? null ) !== $identity || ! is_array( $install_result ) ) { // @phpstan-ignore function.alreadyNarrowedType (Retain runtime evidence validation at the external WordPress or native-process boundary.)
			return $response;
		}
		$injected['post_copy_seen']      = true;
		$injected['destination_version'] = ran_wp_release_updater_test_file_version( $type, $identity );
		$injected['destination_bytes']   = ran_wp_release_updater_test_fixture_bytes( $type, $identity );
		$injected['destination_digest']  = ran_wp_release_updater_test_fixture_digest( $type, $identity );
		$injected['backup_present']      = is_dir( ran_wp_release_updater_test_backup_dir( $type, 'theme' === $type ? $identity : dirname( $identity ) ) );
		return new WP_Error( 'phase24_injected_post_copy_failure', 'Injected after Core moved the valid archive into the destination.' );
	};
	if ( 'install' === $failure_stage ) {
		add_filter( 'upgrader_post_install', $inject_failure, PHP_INT_MAX, 3 );
	}
	$execution = ran_wp_release_updater_test_execute_core_upgrade( $target );
	$result    = $execution['result'];
	if ( 'install' === $failure_stage ) {
		remove_filter( 'upgrader_post_install', $inject_failure, PHP_INT_MAX );
	}
	$backup         = ran_wp_release_updater_test_backup_dir( $type, $slug );
	$install_result = $result;
	if ( ! is_wp_error( $install_result ) ) {
		$install_result = new WP_Error( 'failure_not_reported', 'Failure scenario did not expose the injected Core error.' );
	}

	return array(
		'failure_stage'                 => $failure_stage,
		'failed'                        => is_wp_error( $install_result ), // @phpstan-ignore function.alreadyNarrowedType (Retain runtime evidence validation at the external WordPress or native-process boundary.)
		'result_code'                   => is_wp_error( $install_result ) ? $install_result->get_error_code() : null, // @phpstan-ignore function.alreadyNarrowedType (Retain runtime evidence validation at the external WordPress or native-process boundary.)
		'version_before'                => $before,
		'version_after'                 => ran_wp_release_updater_test_file_version( $type, $identity ),
		'bytes_after'                   => ran_wp_release_updater_test_fixture_bytes( $type, $identity ),
		'injected_post_copy'            => $injected,
		'rollback_backup_path_exists'   => is_dir( $backup ),
		'maintenance_file_exists'       => is_file( ABSPATH . '.maintenance' ),
		'rollback_path'                 => $backup,
		'offer_token_used'              => 1 === $target['packageObservation']->calls && is_string( $target['packageObservation']->package ) && hash_equals( $offer['package'], $target['packageObservation']->package ),
		'package_handoff_calls'         => $target['packageObservation']->calls,
		'cron_context'                  => $execution['cron_context'],
		'automatic_result_observed'     => $execution['automatic_result_observed'],
		'automatic_plugin_was_active'   => $execution['automatic_plugin_was_active'],
		'manual_plugin_was_deactivated' => $execution['manual_plugin_was_deactivated'],
	);
}

/**
 * @param array<string,mixed> $target
 * @return array<string,mixed>
 */
function ran_wp_release_updater_test_execute_core_upgrade( array $target ): array {
	$type                          = $target['type'];
	$identity                      = $target['identity'];
	$item                          = ran_wp_release_updater_test_prime_core_offer( $target );
	$cron_context                  = wp_doing_cron();
	$automatic_plugin_was_active   = 'plugin' === $type && is_plugin_active( $identity );
	$manual_plugin_was_deactivated = null;
	$automatic_result_observed     = false;
	if ( 'automatic' === $target['policy'] ) {
		$updater                   = new RAN_WP_RELEASE_UPDATER_Test_Phase24AutomaticUpdater();
		$result                    = $updater->update_one( $type, $item );
		$automatic_result_observed = true;
	} elseif ( 'plugin' === $type ) {
		$result                        = ( new Plugin_Upgrader( new Automatic_Upgrader_Skin() ) )->upgrade( $identity, array( 'clear_update_cache' => false ) );
		$manual_plugin_was_deactivated = ! is_plugin_active( $identity );
		if ( $manual_plugin_was_deactivated ) {
			$activation = activate_plugin( $identity, '', false, true );
			if ( is_wp_error( $activation ) ) {
				throw new RuntimeException( 'Manual plugin reactivation failed.' );
			}
		}
	} else {
		$result = ( new Theme_Upgrader( new Automatic_Upgrader_Skin() ) )->upgrade( $identity, array( 'clear_update_cache' => false ) );
	}
	return array(
		'result'                        => $result,
		'cron_context'                  => $cron_context,
		'automatic_result_observed'     => $automatic_result_observed,
		'automatic_plugin_was_active'   => $automatic_plugin_was_active,
		'manual_plugin_was_deactivated' => $manual_plugin_was_deactivated,
	);
}

/** @param array<string,mixed> $target */
function ran_wp_release_updater_test_prime_core_offer( array $target ): object {
	$identity             = $target['identity'];
	$offer                = $target['offer'];
	$offer['new_version'] = $offer['version'];
	$transient            = (object) array(
		'last_checked' => time(),
		'response'     => array(),
		'checked'      => array(),
	);
	if ( 'plugin' === $target['type'] ) {
		foreach ( get_plugins() as $file => $plugin ) {
			$transient->checked[ $file ] = $plugin['Version'];
		}
		$offer['plugin']                  = $identity;
		$transient->response[ $identity ] = (object) $offer;
		set_site_transient( 'update_plugins', $transient, 60 );
		return $transient->response[ $identity ];
	}
	foreach ( wp_get_themes() as $slug => $theme ) {
		$transient->checked[ $slug ] = $theme->get( 'Version' );
	}
	$offer['theme']                   = $identity;
	$offer['slug']                    = $identity;
	$transient->response[ $identity ] = $offer;
	set_site_transient( 'update_themes', $transient, 60 );
	return (object) $offer;
}

/**
 * @param array<string,mixed> $args
 * @param array<string,mixed> $counts
 * @return array<string,mixed>|WP_Error|null
 */
function ran_wp_release_updater_test_fixture_http_response( string $url, array $args, string $type, string $archive, array &$counts ): array|WP_Error|null {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Native URL parsing preserves provider and bootstrap validation semantics independently of WordPress helpers.
	$parts = parse_url( $url );
	if ( is_array( $parts ) && in_array( $parts['scheme'] ?? null, array( 'http', 'https' ), true ) && 'api.wordpress.org' === ( $parts['host'] ?? null ) && in_array( $parts['path'] ?? null, array( '/core/version-check/1.7/', '/plugins/update-check/1.1/', '/themes/update-check/1.1/' ), true ) ) {
		if ( ran_wp_release_updater_test_request_contains_fixture_credential( $args ) ) {
			++$counts['credential_leaks'];
		}
		++$counts['core_denied'];
		return new WP_Error( 'phase24_core_network_denied', 'WordPress.org refresh is denied in the disposable proof.' );
	}
	if ( is_array( $parts ) && 'http' === ( $parts['scheme'] ?? null ) && '127.0.0.1' === ( $parts['host'] ?? null ) && '/' === ( $parts['path'] ?? null ) && is_string( $parts['query'] ?? null ) ) {
		if ( ran_wp_release_updater_test_request_contains_fixture_credential( $args ) ) {
			++$counts['credential_leaks'];
		}
		parse_str( $parts['query'], $query );
		$key   = $query['wp_scrape_key'] ?? null;
		$nonce = $query['wp_scrape_nonce'] ?? null;
		if ( is_string( $key ) && is_string( $nonce ) && hash_equals( md5( $nonce ), $key ) && array( 'wp_scrape_key', 'wp_scrape_nonce' ) === array_keys( $query ) ) {
			++$counts['loopback'];
			return array(
				'body'     => '###### wp_scraping_result_start:' . $key . ' ######null###### wp_scraping_result_end:' . $key . ' ######',
				'headers'  => array(),
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
			);
		}
	}
	$locator    = 'phase24-owner/phase24-' . $type;
	$repository = 'https://api.github.com/repos/' . $locator;
	$release    = 'https://api.github.com/repos/' . $locator . '/releases/201';
	$commit     = 'https://api.github.com/repos/' . $locator . '/commits/' . rawurlencode( 'success' === getenv( 'RAN_WP_RELEASE_UPDATER_MODE' ) ? 'v2.0.0' : 'v3.0.0' );
	$asset      = 'https://api.github.com/repos/' . $locator . '/releases/assets/301';
	$known_urls = array( 'https://api.github.com/repos/' . $locator . '/releases?per_page=20&page=1', $repository, $release, $commit, $asset );
	if ( ! in_array( $url, $known_urls, true ) || ! ran_wp_release_updater_test_github_request_contract( $args, $asset === $url ) ) {
		return null;
	}
	++$counts['credentialed'];
	$tag          = 'success' === getenv( 'RAN_WP_RELEASE_UPDATER_MODE' ) ? 'v2.0.0' : 'v3.0.0';
	$release_body = array(
		'id'           => 201,
		'draft'        => false,
		'prerelease'   => false,
		'immutable'    => true,
		'html_url'     => 'https://github.com/' . $locator . '/releases/tag/' . $tag,
		'published_at' => '2026-08-22T10:00:00Z',
		'tag_name'     => $tag,
		'assets'       => array(
			array(
				'id'     => 301,
				'name'   => basename( $archive ),
				'size'   => filesize( $archive ),
				'state'  => 'uploaded',
				'digest' => 'sha256:' . hash_file( 'sha256', $archive ),
			),
		),
	);
	if ( 'https://api.github.com/repos/' . $locator . '/releases?per_page=20&page=1' === $url ) {
		return ran_wp_release_updater_test_github_response( 200, array( $release_body ) );
	}
	if ( $repository === $url ) {
		return ran_wp_release_updater_test_github_response( 200, array( 'id' => 101 ) );
	}
	if ( $release === $url ) {
		return ran_wp_release_updater_test_github_response( 200, $release_body );
	}
	if ( $commit === $url ) {
		return ran_wp_release_updater_test_github_response( 200, array( 'sha' => str_repeat( 'a', 40 ) ) );
	}
	if ( $asset === $url && true === ( $args['stream'] ?? false ) && is_string( $args['filename'] ?? null ) && '' !== $args['filename'] ) {
		if ( 'download' === getenv( 'RAN_WP_RELEASE_UPDATER_FAILURE_STAGE' ) ) {
			++$counts['injected_download'];
			return new WP_Error( 'phase24_injected_download_failure', 'Injected fixture download failure.' );
		}
		if ( false === copy( $archive, $args['filename'] ) ) {
			return null;
		}
		++$counts['asset_writes'];
		return ran_wp_release_updater_test_github_response( 200, null, $args['filename'] );
	}
	return null;
}

/** @param array<string,mixed> $args */
function ran_wp_release_updater_test_github_request_contract( array $args, bool $asset ): bool {
	$headers = $args['headers'] ?? null;
	return is_array( $headers )
		&& 'GET' === ( $args['method'] ?? null )
		&& 0 === ( $args['redirection'] ?? null )
		&& 10 === ( $args['timeout'] ?? null )
		&& 'Bearer phase24-token' === ( $headers['Authorization'] ?? null )
		&& 'ran-wp-release-updater' === ( $headers['User-Agent'] ?? null )
		&& '2022-11-28' === ( $headers['X-GitHub-Api-Version'] ?? null )
		&& ( $asset ? 'application/octet-stream' : 'application/vnd.github+json' ) === ( $headers['Accept'] ?? null );
}

/** @param array<string,mixed> $args */
function ran_wp_release_updater_test_request_contains_fixture_credential( array $args ): bool {
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Inspect controlled fixture values or prove the artifact explicitly rejects serialization; no external payload is serialized.
	return str_contains( serialize( $args ), 'phase24-token' );
}

/** @return array<string,mixed> */
function ran_wp_release_updater_test_github_response( int $status, mixed $body, ?string $file = null ): array {
	$response = array(
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Native JSON preserves protocol or fixture bytes without requiring WordPress helpers or their fallback behavior.
		'body'     => null === $body ? '' : json_encode( $body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES ),
		'headers'  => array(),
		'response' => array(
			'code'    => $status,
			'message' => 'OK',
		),
	);
	if ( null !== $file ) {
		$response['filename'] = $file;
	}
	return $response;
}

function ran_wp_release_updater_test_file_version( string $type, string $identity ): ?string {
	if ( 'plugin' === $type ) {
		$plugin = WP_PLUGIN_DIR . '/' . $identity;
		if ( ! is_file( $plugin ) ) {
			return null;
		}
		$data = get_plugin_data( $plugin, false, false );
		return is_array( $data ) && isset( $data['Version'] ) ? (string) $data['Version'] : null;
	}

	$file = get_theme_root( $identity ) . '/' . $identity . '/style.css';
	if ( ! is_file( $file ) ) {
		return null;
	}
	$data = get_file_data( $file, array( 'Version' => 'Version' ), 'theme' );
	return is_array( $data ) && isset( $data['Version'] ) ? (string) $data['Version'] : null;
}

function ran_wp_release_updater_test_fixture_bytes( string $type, string $identity ): ?int {
	$path = 'plugin' === $type ? WP_PLUGIN_DIR . '/' . $identity : get_theme_root( $identity ) . '/' . $identity . '/style.css';
	return is_file( $path ) ? filesize( $path ) : null;
}

function ran_wp_release_updater_test_fixture_digest( string $type, string $identity ): ?string {
	$manifest = ran_wp_release_updater_test_fixture_manifest( $type, $identity );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Native JSON preserves protocol or fixture bytes without requiring WordPress helpers or their fallback behavior.
	return is_array( $manifest ) ? hash( 'sha256', json_encode( $manifest, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) ) : null;
}

/** @return array<string,array{size:int,sha256:string}>|null */
function ran_wp_release_updater_test_fixture_manifest( string $type, string $identity ): ?array {
	$root = 'plugin' === $type ? dirname( WP_PLUGIN_DIR . '/' . $identity ) : get_theme_root( $identity ) . '/' . $identity;
	if ( ! is_dir( $root ) || is_link( $root ) ) {
		return null;
	}
	$files    = array();
	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $iterator as $entry ) {
		if ( $entry->isLink() || ! $entry->isFile() ) {
			return null;
		}
		$relative = substr( $entry->getPathname(), strlen( $root ) + 1 );
		$sha256   = hash_file( 'sha256', $entry->getPathname() );
		if ( false === $sha256 ) {
			return null;
		}
		$files[ $relative ] = array(
			'size'   => $entry->getSize(),
			'sha256' => $sha256,
		);
	}
	ksort( $files, SORT_STRING );
	return $files;
}

function ran_wp_release_updater_test_backup_dir( string $type, string $slug ): string {
	$base   = WP_CONTENT_DIR . '/upgrade-temp-backup';
	$bucket = 'plugin' === $type ? 'plugins' : 'themes';
	return $base . '/' . $bucket . '/' . $slug;
}

function ran_wp_release_updater_test_rrmdir_recursive( string $path ): void {
	if ( ! is_dir( $path ) ) {
		if ( is_link( $path ) || is_file( $path ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- Best-effort fixture teardown tolerates paths already removed by the scenario. Remove native fixture entries directly, preserving the surrounding ownership and link-handling checks.
			@unlink( $path );
		}
		return;
	}
	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $iterator as $entry ) {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.WP.AlternativeFunctions.unlink_unlink -- Best-effort fixture teardown tolerates paths already removed by the scenario. Remove native fixture entries directly, preserving the surrounding ownership and link-handling checks.
		is_dir( $entry->getPathname() ) ? @rmdir( $entry->getPathname() ) : @unlink( $entry->getPathname() );
	}
	// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Best-effort fixture teardown tolerates paths already removed by the scenario. Remove native fixture entries directly, preserving the surrounding ownership and link-handling checks.
	@rmdir( $path );
}

/**
 * @param array<string,mixed> $target
 * @return array<string,mixed>
 */
function ran_wp_release_updater_test_readback_options( array $target ): array {
	$target_name    = $target['targetName'] ?? null;
	$target_row     = is_string( $target_name ) ? ran_wp_release_updater_test_option_row( $target_name ) : null;
	$target_value   = is_array( $target_row ) ? $target_row['option_value'] : null;
	$target_decoded = is_string( $target_value ) ? json_decode( $target_value, true, 32, JSON_THROW_ON_ERROR ) : null;
	return array(
		'target_name'     => is_string( $target_name ) ? $target_name : null,
		'target_exists'   => is_array( $target_row ),
		'target_autoload' => is_array( $target_row ) ? $target_row['autoload'] : null,
		'target_schema'   => is_array( $target_decoded ) ? ( $target_decoded['state_schema'] ?? null ) : null,
		'target_value'    => $target_value,
		'state_row_count' => ran_wp_release_updater_test_option_prefix_count( 'ran_wp_release_updater_state_v1_' ),
	);
}

function ran_wp_release_updater_test_option_prefix_count( string $prefix ): int {
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
function ran_wp_release_updater_test_option_row( string $option_name ): ?array {
	if ( ! isset( $GLOBALS['wpdb'] ) ) {
		return null;
	}
	$row = $GLOBALS['wpdb']->get_row( $GLOBALS['wpdb']->prepare( 'SELECT option_value, autoload FROM ' . $GLOBALS['wpdb']->options . ' WHERE option_name=%s LIMIT 1', $option_name ), ARRAY_A );
	return is_array( $row ) && is_string( $row['option_value'] ?? null ) && is_string( $row['autoload'] ?? null ) ? $row : null;
}
