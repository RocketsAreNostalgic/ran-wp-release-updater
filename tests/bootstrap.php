<?php

declare(strict_types=1);

if ( ! function_exists( 'apply_filters' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress calls this global stub by its exact foreign function name.
	function apply_filters( string $hook, mixed $value, mixed ...$arguments ): mixed {
		$callback = $GLOBALS['ran_wp_release_updater_test_filter_callbacks'][ $hook ] ?? null;
		return is_callable( $callback ) ? $callback( $value, ...$arguments ) : $value;
	}
}

if ( ! function_exists( 'wp_normalize_path' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Match the global WordPress helper for local filesystem paths in lifecycle fixtures.
	function wp_normalize_path( string $path ): string {
		$path = preg_replace( '|(?<=.)/+|', '/', str_replace( '\\', '/', $path ) ) ?? $path;
		return ':' === substr( $path, 1, 1 ) ? ucfirst( $path ) : $path;
	}
}

spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = 'RAN\\WPReleaseUpdater\\V1\\';
		if ( ! str_starts_with( $class_name, $prefix ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( $prefix ) );
		$file     = dirname( __DIR__ ) . '/src/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_file( $file ) ) {
			require_once $file;
		}
	}
);
