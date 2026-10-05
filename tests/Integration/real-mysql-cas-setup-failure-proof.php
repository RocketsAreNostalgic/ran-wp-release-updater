<?php

declare(strict_types=1);

$configured = getenv( 'RAN_UPDATER_MYSQL_ROOT' );
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Check real permissions before admitting the configured disposable filesystem root.
if ( ! is_string( $configured ) || '' === $configured || is_link( $configured ) || ! is_dir( $configured ) || ! is_writable( $configured ) ) {
	throw new RuntimeException( 'MySQL setup-failure proof requires RAN_UPDATER_MYSQL_ROOT to name an existing, writable, non-symlink durable directory.' );
}

$parent = realpath( $configured );
if ( false === $parent ) {
	throw new RuntimeException( 'Could not resolve RAN_UPDATER_MYSQL_ROOT.' );
}
$root = $parent . '/ran-wp-release-updater-mysql-setup-failure-' . bin2hex( random_bytes( 8 ) );
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create real directories for the disposable integration fixture with the specified permissions.
if ( ! mkdir( $root, 0700 ) ) {
	throw new RuntimeException( 'Could not create setup-failure proof root.' );
}

$original_root   = getenv( 'RAN_UPDATER_MYSQL_ROOT' );
$original_mysqld = getenv( 'RAN_UPDATER_MYSQLD_BIN' );
try {
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Configure the setup-failure child; finally restores both original environment values.
	putenv( 'RAN_UPDATER_MYSQL_ROOT=' . $root );
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- Configure the setup-failure child; finally restores both original environment values.
	putenv( 'RAN_UPDATER_MYSQLD_BIN=' . $root . '/not-mysqld' );
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Run the isolated proof command with explicit argv, pipe capture and exit-status observation.
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
	$stdout = stream_get_contents( $pipes[1] );
	$stderr = stream_get_contents( $pipes[2] );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the native stream owned by this fixture; WordPress filesystem abstractions do not own process or file handles.
	fclose( $pipes[1] );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the native stream owned by this fixture; WordPress filesystem abstractions do not own process or file handles.
	fclose( $pipes[2] );
	$exit = proc_close( $process );
	if ( 0 === $exit || ! str_contains( $stdout . $stderr, 'requires RAN_UPDATER_MYSQLD_BIN' ) ) {
		throw new RuntimeException( 'Setup-failure proof did not fail while resolving mysqld.' );
	}
	$entries = array_values( array_diff( scandir( $root ) ?: array(), array( '.', '..' ) ) );
	if ( array() !== $entries ) {
		throw new RuntimeException( 'Setup-failure proof leaked an isolated MySQL child directory.' );
	}
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
	if ( is_dir( $root ) && array() === array_values( array_diff( scandir( $root ) ?: array(), array( '.', '..' ) ) ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove native fixture entries directly, preserving the surrounding ownership and link-handling checks.
		rmdir( $root );
	}
}
