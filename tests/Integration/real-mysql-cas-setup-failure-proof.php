<?php

declare(strict_types=1);

$configured = getenv( 'RAN_UPDATER_MYSQL_ROOT' );
if ( ! is_string( $configured ) || '' === $configured || is_link( $configured ) || ! is_dir( $configured ) || ! is_writable( $configured ) ) {
	throw new RuntimeException( 'MySQL setup-failure proof requires RAN_UPDATER_MYSQL_ROOT to name an existing, writable, non-symlink durable directory.' );
}

$parent = realpath( $configured );
if ( false === $parent ) {
	throw new RuntimeException( 'Could not resolve RAN_UPDATER_MYSQL_ROOT.' );
}
$root = $parent . '/ran-wp-release-updater-mysql-setup-failure-' . bin2hex( random_bytes( 8 ) );
if ( ! mkdir( $root, 0700 ) ) {
	throw new RuntimeException( 'Could not create setup-failure proof root.' );
}

$originalRoot   = getenv( 'RAN_UPDATER_MYSQL_ROOT' );
$originalMysqld = getenv( 'RAN_UPDATER_MYSQLD_BIN' );
try {
	putenv( 'RAN_UPDATER_MYSQL_ROOT=' . $root );
	putenv( 'RAN_UPDATER_MYSQLD_BIN=' . $root . '/not-mysqld' );
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
	fclose( $pipes[0] );
	$stdout = stream_get_contents( $pipes[1] );
	$stderr = stream_get_contents( $pipes[2] );
	fclose( $pipes[1] );
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
	if ( false === $originalRoot ) {
		putenv( 'RAN_UPDATER_MYSQL_ROOT' );
	} else {
		putenv( 'RAN_UPDATER_MYSQL_ROOT=' . $originalRoot );
	}
	if ( false === $originalMysqld ) {
		putenv( 'RAN_UPDATER_MYSQLD_BIN' );
	} else {
		putenv( 'RAN_UPDATER_MYSQLD_BIN=' . $originalMysqld );
	}
	if ( is_dir( $root ) && array() === array_values( array_diff( scandir( $root ) ?: array(), array( '.', '..' ) ) ) ) {
		rmdir( $root );
	}
}
