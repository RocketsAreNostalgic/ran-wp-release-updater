<?php

declare(strict_types=1);


// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$root = dirname( __DIR__ );
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$paths = array(
	$root . '/bootstrap.php',
	$root . '/runtime.php',
	$root . '/src',
	$root . '/scripts',
	$root . '/tests',
);
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$files = array();
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
foreach ( $paths as $source_path ) {
	if ( is_file( $source_path ) && str_ends_with( $source_path, '.php' ) ) {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
		$files[] = $source_path;
		continue;
	}
	if ( ! is_dir( $source_path ) ) {
		continue;
	}
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $source_path, FilesystemIterator::SKIP_DOTS )
	);
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
	foreach ( $iterator as $file ) {
		if ( $file->isFile() && 'php' === strtolower( $file->getExtension() ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
			$files[] = $file->getPathname();
		}
	}
}
sort( $files, SORT_STRING );
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
foreach ( $files as $file ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals. Run the native PHP parser in a separate process and preserve its blocking exit status.
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
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
	$stdout = stream_get_contents( $pipes[1] );
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
	$stderr = stream_get_contents( $pipes[2] );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the exact native stream owned by this operation; no WordPress filesystem abstraction applies.
	fclose( $pipes[1] );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the exact native stream owned by this operation; no WordPress filesystem abstraction applies.
	fclose( $pipes[2] );
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
	$exit = proc_close( $process );
	if ( 0 !== $exit ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Write directly to the CLI descriptor without requiring a WordPress runtime.
		fwrite( STDERR, $stdout . $stderr );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Standalone CLI parser output is diagnostic text, not an HTML response.
		exit( $exit );
	}
}

// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Write directly to the CLI descriptor without requiring a WordPress runtime.
fwrite( STDOUT, sprintf( "PASS PHP syntax lint (%d files)\n", count( $files ) ) );
