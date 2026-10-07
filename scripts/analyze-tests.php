<?php

declare(strict_types=1);

use PHPStan\DependencyInjection\ContainerFactory;

require dirname( __DIR__ ) . '/vendor/autoload.php';

$ran_wp_release_updater_analysis_status = ( static function (): int {
	$root      = dirname( __DIR__ );
	$config    = $root . '/phpstan-tests.neon';
	$container = ( new ContainerFactory( $root ) )->create( $root . '/.phpunit.cache/test-analysis', array( $config ), array() );
	$files     = $container->getService( 'fileFinderAnalyse' )->findFiles( array( $root . '/tests' ) )->getFiles();
	sort( $files );
	$arguments = array_slice( $GLOBALS['argv'] ?? array(), 1 );
	if ( array( '--list' ) === $arguments ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Emit machine-readable CLI selection evidence without HTML escaping. Native JSON preserves protocol or fixture bytes without requiring WordPress helpers or their fallback behavior.
		echo json_encode( $files, JSON_THROW_ON_ERROR ) . "\n";
		return 0;
	}
	if ( array() !== $arguments || array() === $files ) {
		return 2;
	}
	$status = 0;
	foreach ( $files as $file ) {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Invoke the locked analyzer separately for each isolated executable fixture world; never execute fixture source.
		$process = proc_open(
			array( PHP_BINARY, $root . '/vendor/bin/phpstan', 'analyse', '--configuration=' . $config, '--no-progress', '--memory-limit=1G', $file ),
			array( STDIN, STDOUT, STDERR ),
			$pipes,
			$root
		);
		if ( ! is_resource( $process ) ) {
			return 2;
		}
		if ( 0 !== proc_close( $process ) ) {
			$status = 1;
		}
	}
	return $status;
} )();
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The closure returns only an integer CLI status; exit emits no response body.
exit( $ran_wp_release_updater_analysis_status );
