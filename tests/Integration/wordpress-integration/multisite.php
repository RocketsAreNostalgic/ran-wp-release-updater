<?php

// wp eval-file wraps this fixture before execution, so strict_types cannot lead.

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$output = getenv( 'RAN_UPDATER_NETWORK_OUTPUT' );
if ( ! is_string( $output ) || '' === $output ) {
	throw new RuntimeException( 'Network output is missing.' );
}
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$handle = $GLOBALS['ran_network_handle'] ?? null;
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$duplicate_handle = $GLOBALS['ran_network_duplicate_handle'] ?? null;
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$broker = $GLOBALS['ran_wp_release_updater_v1_broker'] ?? null;
if ( ! is_object( $handle ) || ! is_callable( array( $handle, 'status' ) ) || ! is_object( $duplicate_handle ) || ! is_callable( array( $duplicate_handle, 'register' ) ) || ! is_object( $broker ) || ! is_callable( array( $broker, 'diagnostics' ) ) ) {
	throw new RuntimeException( 'Network target did not boot with its duplicate declaration.' );
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$duplicate_accepted = $duplicate_handle->register();
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$provider_before = (int) ( $GLOBALS['ran_updater_http_calls'] ?? 0 );
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$plugin_data = get_plugin_data( WP_PLUGIN_DIR . '/ran-network-target/main.php', false, false );
// phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The native WordPress update hook includes the hostname github.com. Exercise the native WordPress hostname-specific update hook; its foreign identifier must stay exact.
apply_filters( 'update_plugins_github.com', false, $plugin_data, 'ran-network-target/main.php', array() );
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$provider_after = (int) ( $GLOBALS['ran_updater_http_calls'] ?? 0 );

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$network_id = get_current_network_id();
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$key = 'ran_wp_release_updater_target_v1_' . \RAN\WPReleaseUpdater\V1\Contract\BindingRecord::target_fence_key(
	array(
		'network_id'                 => $network_id,
		'target_type'                => 'plugin',
		'installed_package_identity' => 'ran-network-target/main.php',
	)
);
global $wpdb;
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->base_prefix}options WHERE option_name = %s LIMIT 1", $key ) );
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$state = is_string( $raw ) ? json_decode( $raw, true ) : null;
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$diagnostics = $broker->diagnostics();
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$provider_suppressed = 0 === ( $provider_after - $provider_before );
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$native_callbacks = ran_wp_release_updater_test_native_callbacks( 'update_plugins_github.com' );
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$proof = array(
	'blog_id'                         => get_current_blog_id(),
	'network_id'                      => $network_id,
	'duplicate_registration_accepted' => $duplicate_accepted,
	'logical_target_count'            => $diagnostics['logical_target_count'] ?? null,
	'native_callback_count'           => count( $native_callbacks ),
	'provider_callback_delta'         => $provider_after - $provider_before,
	'suppressed_provider'             => $provider_suppressed,
	'option'                          => array(
		'name'                => $key,
		'present'             => is_array( $state ),
		'network_id'          => is_array( $state ) ? ( $state['binding']['network_id'] ?? null ) : null,
		'owner_token_present' => is_array( $state ) && is_string( $state['owner_token'] ?? null ) && '' !== $state['owner_token'],
		'owner_token_sha256'  => is_array( $state ) && is_string( $state['owner_token'] ?? null ) ? hash( 'sha256', $state['owner_token'] ) : null,
		'lease_deadline'      => is_array( $state ) ? ( $state['lease_deadline'] ?? null ) : null,
		'fence_epoch'         => is_array( $state ) ? ( $state['fence_epoch'] ?? null ) : null,
	),
	'status'                          => $handle->status(),
);
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Write the multisite proof result for the parent harness to validate. Native JSON preserves protocol or fixture bytes without requiring WordPress helpers or their fallback behavior.
file_put_contents( $output, json_encode( $proof, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . "\n" );

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$release = getenv( 'RAN_UPDATER_NETWORK_RELEASE' );
if ( is_string( $release ) && '' !== $release ) {
	// phpcs:ignore Generic.CodeAnalysis.ForLoopWithTestFunctionCall.NotAllowed, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Re-observe child liveness or the release file on each bounded poll; caching the condition would break synchronization. Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
	for ( $attempt = 0; $attempt < 400 && ! is_file( $release ); ++$attempt ) {
		usleep( 50000 );
	}
	if ( ! is_file( $release ) ) {
		throw new RuntimeException( 'Timed out waiting for deterministic network release.' );
	}
}

/** @return list<object> */
function ran_wp_release_updater_test_native_callbacks( string $hook ): array {
	$registered = $GLOBALS['wp_filter'][ $hook ] ?? null;
	if ( ! $registered instanceof WP_Hook ) {
		return array();
	}
	$found = array();
	foreach ( $registered->callbacks as $priority ) {
		foreach ( $priority as $entry ) {
			$callback = $entry['function'] ?? null;
			if ( is_array( $callback ) && is_object( $callback[0] ) && 'filter_update' === ( $callback[1] ?? null ) ) {
				$found[] = $callback[0];
			}
		}
	}
	return $found;
}
