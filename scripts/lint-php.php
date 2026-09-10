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
		fwrite( STDERR, "Could not start PHP lint for {$file}.\n" );
		exit( 1 );
	}
	fclose( $pipes[0] );
	$stdout = stream_get_contents( $pipes[1] );
	$stderr = stream_get_contents( $pipes[2] );
	fclose( $pipes[1] );
	fclose( $pipes[2] );
	$exit = proc_close( $process );
	if ( 0 !== $exit ) {
		fwrite( STDERR, $stdout . $stderr );
		exit( $exit );
	}
}

fwrite( STDOUT, sprintf( "PASS PHP syntax lint (%d files)\n", count( $files ) ) );
