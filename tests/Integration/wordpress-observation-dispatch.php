<?php

declare(strict_types=1);

use RAN\WPReleaseUpdater\V1\WordPress\NativePackageUpdater;

$ran_wp_release_updater_root  = getenv( 'RAN_UPDATER_WP_ROOT' );
$ran_wp_release_updater_stage = $argv[1] ?? '';
if ( ! is_string( $ran_wp_release_updater_root ) || ! is_file( $ran_wp_release_updater_root . '/wp-includes/plugin.php' ) || ! in_array( $ran_wp_release_updater_stage, array( 'started', 'completed' ), true ) ) {
	throw new RuntimeException( 'Require pristine RAN_UPDATER_WP_ROOT and started or completed stage.' );
}
require $ran_wp_release_updater_root . '/wp-includes/plugin.php';
require dirname( __DIR__, 2 ) . '/src/WordPress/NativePackageUpdater.php';

$ran_wp_release_updater_hook   = 'ran_wp_release_updater_check_' . $ran_wp_release_updater_stage . '_v1';
$ran_wp_release_updater_method = new ReflectionMethod( NativePackageUpdater::class, 'observe_check' );
add_action( $ran_wp_release_updater_hook, static function (): void {} );
$ran_wp_release_updater_method->invoke( null, $ran_wp_release_updater_stage, array() );
if ( false !== current_filter() || doing_action() ) {
	throw new RuntimeException( 'Ordinary observation did not leave the WordPress dispatcher clean.' );
}

$ran_wp_release_updater_failure = new RuntimeException( 'Expected observer failure.' );
add_action(
	$ran_wp_release_updater_hook,
	static function () use ( $ran_wp_release_updater_failure ): void {
		throw $ran_wp_release_updater_failure;
	}
);
try {
	$ran_wp_release_updater_method->invoke( null, $ran_wp_release_updater_stage, array() );
} catch ( Throwable $ran_wp_release_updater_caught ) {
	if ( $ran_wp_release_updater_failure !== $ran_wp_release_updater_caught ) {
		throw new RuntimeException( 'The observer exception identity changed.' );
	}
	// Exit this isolated process immediately; an interrupted WordPress dispatcher cannot resume.
	exit( 0 );
}
throw new RuntimeException( 'The updater swallowed a real WordPress dispatcher exception.' );
