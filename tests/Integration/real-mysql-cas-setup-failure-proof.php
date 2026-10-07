<?php

declare(strict_types=1);

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$configured = getenv( 'RAN_UPDATER_MYSQL_ROOT' );
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Check real permissions before admitting the configured disposable filesystem root.
if ( ! is_string( $configured ) || '' === $configured || is_link( $configured ) || ! is_dir( $configured ) || ! is_writable( $configured ) ) {
	throw new RuntimeException( 'MySQL setup-failure proof requires RAN_UPDATER_MYSQL_ROOT to name an existing, writable, non-symlink durable directory.' );
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$parent = realpath( $configured );
if ( false === $parent ) {
	throw new RuntimeException( 'Could not resolve RAN_UPDATER_MYSQL_ROOT.' );
}
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$root = $parent . '/ran-wp-release-updater-mysql-setup-failure-' . bin2hex( random_bytes( 8 ) );
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create real directories for the disposable integration fixture with the specified permissions.
if ( ! mkdir( $root, 0700 ) ) {
	throw new RuntimeException( 'Could not create setup-failure proof root.' );
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$original_root = getenv( 'RAN_UPDATER_MYSQL_ROOT' );
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$original_mysqld = getenv( 'RAN_UPDATER_MYSQLD_BIN' );
try {
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Configure the setup-failure child; finally restores both original environment values.
	putenv( 'RAN_UPDATER_MYSQL_ROOT=' . $root );
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Configure the setup-failure child; finally restores both original environment values.
	putenv( 'RAN_UPDATER_MYSQLD_BIN=' . $root . '/not-mysqld' );
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Run the isolated proof command with explicit argv, pipe capture and exit-status observation. Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
	$process = proc_open(
		array( PHP_BINARY, __DIR__ . '/real-mysql-cas-proof.php' ),
		array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		),
		$pipes
	);
	if ( ! is_resource( $process ) ) {
		throw new RuntimeException( 'Could not start setup-failure proof.' );
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the native stream owned by this fixture; WordPress filesystem abstractions do not own process or file handles.
	fclose( $pipes[0] );
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
	$stdout = stream_get_contents( $pipes[1] );
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
	$stderr = stream_get_contents( $pipes[2] );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the native stream owned by this fixture; WordPress filesystem abstractions do not own process or file handles.
	fclose( $pipes[1] );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the native stream owned by this fixture; WordPress filesystem abstractions do not own process or file handles.
	fclose( $pipes[2] );
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
	$exit = proc_close( $process );
	if ( 0 === $exit || ! str_contains( $stdout . $stderr, 'requires RAN_UPDATER_MYSQLD_BIN' ) ) {
		throw new RuntimeException( 'Setup-failure proof did not fail while resolving mysqld.' );
	}
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
	$fixture_entries = scandir( $root );
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
	$entries = array_values( array_diff( $fixture_entries ? $fixture_entries : array(), array( '.', '..' ) ) );
	if ( array() !== $entries ) {
		throw new RuntimeException( 'Setup-failure proof leaked an isolated MySQL child directory.' );
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Native JSON preserves protocol or fixture bytes without requiring WordPress helpers or their fallback behavior.
	echo json_encode(
		array(
			'result' => 'setup_failure_cleaned',
			'root'   => $root,
		),
		JSON_THROW_ON_ERROR
	) . PHP_EOL;
} finally {
	if ( false === $original_root ) {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Restore the caller environment after the setup-failure subprocess.
		putenv( 'RAN_UPDATER_MYSQL_ROOT' );
	} else {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Restore the caller environment after the setup-failure subprocess.
		putenv( 'RAN_UPDATER_MYSQL_ROOT=' . $original_root );
	}
	if ( false === $original_mysqld ) {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Restore the caller environment after the setup-failure subprocess.
		putenv( 'RAN_UPDATER_MYSQLD_BIN' );
	} else {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Restore the caller environment after the setup-failure subprocess.
		putenv( 'RAN_UPDATER_MYSQLD_BIN=' . $original_mysqld );
	}
	if ( is_dir( $root ) ) {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
		$fixture_entries = scandir( $root );
		if ( array() === array_values( array_diff( $fixture_entries ? $fixture_entries : array(), array( '.', '..' ) ) ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove native fixture entries directly, preserving the surrounding ownership and link-handling checks.
			rmdir( $root );
		}
	}
}
