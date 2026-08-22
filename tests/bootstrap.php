<?php

declare(strict_types=1);

$testAutoloadMap = array(
	'RAN\\WPReleaseUpdater\\V1\\WordPress\\BindingState' => dirname( __DIR__ ) . '/src/WordPress/ReleaseOperationCoordinator.php',
);

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, mixed $value, mixed ...$arguments ): mixed {
		$callback = $GLOBALS['ran_wp_release_updater_test_filter_callbacks'][ $hook ] ?? null;
		return is_callable( $callback ) ? $callback( $value, ...$arguments ) : $value;
	}
}

spl_autoload_register(
	static function ( string $class ) use ( $testAutoloadMap ): void {
		if ( isset( $testAutoloadMap[ $class ] ) ) {
			require_once $testAutoloadMap[ $class ];
			return;
		}

		$prefix = 'RAN\\WPReleaseUpdater\\V1\\';
		if ( ! str_starts_with( $class, $prefix ) ) {
			return;
		}

		$relative = substr( $class, strlen( $prefix ) );
		$file     = dirname( __DIR__ ) . '/src/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_file( $file ) ) {
			require_once $file;
		}
	}
);
