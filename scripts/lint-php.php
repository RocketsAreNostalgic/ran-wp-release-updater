<?php

declare(strict_types=1);

$root  = dirname( __DIR__ );
$paths = array(
	$root . '/bootstrap.php',
	$root . '/runtime.php',
	$root . '/src',
	$root . '/scripts',
	$root . '/tests',
);
$files = array();
foreach ( $paths as $path ) {
	if ( is_file( $path ) && str_ends_with( $path, '.php' ) ) {
		$files[] = $path;
		continue;
	}
	if ( ! is_dir( $path ) ) {
		continue;
	}
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS )
	);
	foreach ( $iterator as $file ) {
		if ( $file->isFile() && 'php' === strtolower( $file->getExtension() ) ) {
			$files[] = $file->getPathname();
		}
	}
}
sort( $files, SORT_STRING );
foreach ( $files as $file ) {
	$process = proc_open(
		array( PHP_BINARY, '-l', $file ),
		array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		),
		$pipes,
		$root
	);
	if ( ! is_resource( $process ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Write directly to the CLI descriptor without requiring a WordPress runtime.
		fwrite( STDERR, "Could not start PHP lint for {$file}.\n" );
		exit( 1 );
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the exact native stream owned by this operation; no WordPress filesystem abstraction applies.
	fclose( $pipes[0] );
	$stdout = stream_get_contents( $pipes[1] );
	$stderr = stream_get_contents( $pipes[2] );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the exact native stream owned by this operation; no WordPress filesystem abstraction applies.
	fclose( $pipes[1] );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the exact native stream owned by this operation; no WordPress filesystem abstraction applies.
	fclose( $pipes[2] );
	$exit = proc_close( $process );
	if ( 0 !== $exit ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Write directly to the CLI descriptor without requiring a WordPress runtime.
		fwrite( STDERR, $stdout . $stderr );
		exit( $exit );
	}
}

// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Write directly to the CLI descriptor without requiring a WordPress runtime.
fwrite( STDOUT, sprintf( "PASS PHP syntax lint (%d files)\n", count( $files ) ) );
