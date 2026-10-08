<?php

declare(strict_types=1);

use PHPStan\DependencyInjection\ContainerFactory;

require dirname( __DIR__ ) . '/vendor/autoload.php';

$ran_wp_release_updater_analysis_status = ( static function (): int {
	$started   = hrtime( true );
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
	$workers = getenv( 'PHPSTAN_TEST_PROCESSES' );
	$workers = false === $workers || '' === $workers ? ( 'true' === getenv( 'GITHUB_ACTIONS' ) ? '4' : '1' ) : $workers;
	if ( ! in_array( $workers, array( '1', '2', '4' ), true ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Report invalid CLI configuration to standard error.
		fwrite( STDERR, "PHPSTAN_TEST_PROCESSES must be 1, 2 or 4.\n" );
		return 2;
	}
	$status = 0;
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Report numeric CLI coverage and process counts, not HTML.
	printf( "Test analysis: %d isolated files; %s processes.\n", count( $files ), $workers );
	foreach ( array_chunk( $files, (int) $workers ) as $wave ) {
		$running = array();
		foreach ( $wave as $file ) {
			// Each child retains its own PHP process and file selection. Temporary files avoid pipe deadlocks and interleaved diagnostics.
			$output = tmpfile();
			if ( false === $output ) {
				$status = 2;
				break;
			}
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Concurrent analyzers retain isolated symbol worlds and never execute fixture source.
			$process = proc_open(
				array( PHP_BINARY, $root . '/vendor/bin/phpstan', 'analyse', '--configuration=' . $config, '--no-progress', '--memory-limit=1G', $file ),
				array( STDIN, $output, $output ),
				$pipes,
				$root
			);
			if ( ! is_resource( $process ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the private temporary stream also removes it.
				fclose( $output );
				$status = 2;
				break;
			}
			$running[] = array( $process, $output );
		}
		foreach ( $running as list( $process, $output ) ) {
			if ( 0 !== proc_close( $process ) ) {
				$status = max( 1, $status );
			}
			rewind( $output );
			stream_copy_to_stream( $output, STDOUT );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Release the completed child output and remove its private temporary file.
			fclose( $output );
		}
		if ( 2 === $status ) {
			return $status;
		}
	}

	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Report numeric CLI timing for the complete test analysis stage.
	printf( "Test analysis completed in %.2f seconds.\n", ( hrtime( true ) - $started ) / 1000000000 );
	return $status;
} )();
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The closure returns only an integer CLI status; exit emits no response body.
exit( $ran_wp_release_updater_analysis_status );
