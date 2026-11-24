<?php

declare(strict_types=1);

use RAN\WPReleaseUpdater\V1\WordPress\WordPressUpdaterLock;
use RAN\WPReleaseUpdater\V1\Tests\Support\MysqliOptionDatabase;

require_once dirname( __DIR__ ) . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/Support/MysqliOptionDatabase.php';

// phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_report -- Use native strict MySQL reporting for the isolated proof connection and its failure handling.
mysqli_report( MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT );

if ( '--worker' === ( $argv[1] ?? null ) ) {
	ran_wp_release_updater_test_worker( $argv );
	exit( 0 );
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$root = null;
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$server = null;
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$original_cwd = getcwd();
try {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
	$root = ran_wp_release_updater_test_create_isolated_root();
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
	$data = $root . '/data';
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
	$pid = $root . '/mysqld.pid';
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
	$mysqld = ran_wp_release_updater_test_resolve_mysqld();
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create real directories for the disposable integration fixture with the specified permissions.
	if ( ! mkdir( $data, 0700, true ) ) {
		throw new RuntimeException( 'Could not create isolated MySQL directory.' );
	}
	if ( false === chdir( $data ) ) {
		throw new RuntimeException( 'Could not enter isolated MySQL data directory.' );
	}
	ran_wp_release_updater_test_run( array( $mysqld, '--no-defaults', '--initialize-insecure', '--datadir=' . $data, '--socket=mysql.sock', '--tmpdir=' . $root, '--skip-mysqlx' ), $data );
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Start the dedicated mysqld child with explicit argv and fixture paths; the harness owns its shutdown. Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
	$server = proc_open(
		array(
			$mysqld,
			'--no-defaults',
			'--datadir=' . $data,
			'--skip-networking',
			'--pid-file=' . $pid,
			'--socket=mysql.sock',
			'--tmpdir=' . $root,
			'--skip-mysqlx',
			'--log-error=' . $root . '/mysqld.err',
		),
		array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'file', $root . '/mysqld.out', 'a' ),
			2 => array( 'file', $root . '/mysqld.err', 'a' ),
		),
		$pipes,
		$data
	);
	if ( ! is_resource( $server ) ) {
		throw new RuntimeException( 'Could not start isolated MySQL.' );
	}
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
	$mysqli = ran_wp_release_updater_test_attest_server( $server, $data );
	$mysqli->query( 'CREATE DATABASE proof' );
	$mysqli->select_db( 'proof' );
	$mysqli->query( 'CREATE TABLE options (option_name varchar(191) NOT NULL PRIMARY KEY, option_value longtext NOT NULL, autoload varchar(20) NOT NULL) ENGINE=InnoDB' );

	// 1. Cold concurrent acquire race: exactly one winner, zero 1062 errors, 'off' autoload, fractional token.
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
	$cold = ran_wp_release_updater_test_workers( 'cold', $data );
	ran_wp_release_updater_test_assert_one_winner( $cold, 'cold acquire' );
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
	$winner                             = ran_wp_release_updater_test_winner( $cold );
	$ran_wp_release_updater_rows_result = $mysqli->query( 'SELECT option_name, option_value, autoload FROM options ORDER BY option_name' );
	if ( ! $ran_wp_release_updater_rows_result instanceof mysqli_result ) {
		throw new RuntimeException( 'MySQL option observation did not return rows.' );
	}
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
	$rows = $ran_wp_release_updater_rows_result->fetch_all( MYSQLI_ASSOC );
	if (
		1 !== count( $rows )
		|| WordPressUpdaterLock::OPTION_NAME !== $rows[0]['option_name']
		|| 'off' !== $rows[0]['autoload']
		|| $winner['token'] !== $rows[0]['option_value']
		|| 1 !== preg_match( '/\A[0-9]+\.[0-9]{27}\z/D', (string) $rows[0]['option_value'] )
	) {
		throw new RuntimeException( 'Lock option was not one non-autoloaded fractional auto_updater.lock row.' );
	}

	// 2. Corrupt lock row must fail closed without overwriting.
	$mysqli->query( "UPDATE options SET option_value = 'corrupt-lock-value' WHERE option_name = 'auto_updater.lock'" );
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
	$database = new MysqliOptionDatabase( ran_wp_release_updater_test_connect_proof(), 'options' );
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
	$lock = new WordPressUpdaterLock( $database );
	if ( null !== $lock->acquire() || null !== $lock->current_token() ) {
		throw new RuntimeException( 'Corrupt lock row did not fail closed.' );
	}

	// 3. Stale expiry takeover race (>3600s): exactly one winner, zero deprecated BINARY warnings.
	$ran_wp_release_updater_stale_timestamp = (string) ( time() - WordPressUpdaterLock::STALE_SECONDS - 10 );
	$mysqli->query( "UPDATE options SET option_value = '" . $mysqli->real_escape_string( $ran_wp_release_updater_stale_timestamp ) . "' WHERE option_name = 'auto_updater.lock'" );
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
	$takeover = ran_wp_release_updater_test_workers( 'takeover', $data );
	ran_wp_release_updater_test_assert_one_winner( $takeover, 'expired takeover' );
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
	$new = ran_wp_release_updater_test_winner( $takeover );
	if ( $winner['token'] === $new['token'] ) {
		throw new RuntimeException( 'Takeover did not install a distinct lock token.' );
	}

	// 4. Stale writer cannot verify or release the successor's lock.
	if ( $lock->verify( $winner['token'] ) || $lock->release( $winner['token'] ) ) {
		throw new RuntimeException( 'Stale writer was not fenced from successor lock.' );
	}
	if ( ! $lock->verify( $new['token'] ) ) {
		throw new RuntimeException( 'Successor lock did not remain intact after stale writer attempt.' );
	}

	// 5. Exact-token release deletes the row cleanly with zero MySQL warnings.
	if ( ! $lock->release( $new['token'] ) ) {
		throw new RuntimeException( 'Exact-token release failed.' );
	}
	$ran_wp_release_updater_after_result = $mysqli->query( 'SELECT option_name FROM options' );
	if ( ! $ran_wp_release_updater_after_result instanceof mysqli_result || array() !== $ran_wp_release_updater_after_result->fetch_all( MYSQLI_ASSOC ) ) {
		throw new RuntimeException( 'Lock row was not removed after exact-token release.' );
	}
	ran_wp_release_updater_test_assert_no_deprecated_warnings( $database->warnings(), 'parent' );

	// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Native JSON preserves protocol or fixture bytes without requiring WordPress helpers or their fallback behavior.
	echo json_encode(
		array(
			'mysqld_binary'    => $mysqld,
			'transport'        => 'unix_socket_skip_networking',
			'lock_option'      => WordPressUpdaterLock::OPTION_NAME,
			'autoload'         => $rows[0]['autoload'],
			'cold_acquire'     => $cold,
			'expired_takeover' => $takeover,
			'corrupt_blocked'  => true,
			'stale_fenced'     => true,
			'released_clean'   => true,
			'parent_warnings'  => $database->warnings(),
		),
		JSON_THROW_ON_ERROR
	) . PHP_EOL;
} finally {
	if ( is_resource( $server ) ) {
		ran_wp_release_updater_test_stop_server( $server );
	}
	if ( is_string( $original_cwd ) ) {
		chdir( $original_cwd );
	}
	if ( is_string( $root ) ) {
		ran_wp_release_updater_test_remove_tree( $root );
	}
}

/** @param list<string> $argv */
function ran_wp_release_updater_test_worker( array $argv ): void {
	$start_at = (int) $argv[3];
	while ( hrtime( true ) < $start_at ) {
		usleep( 1000 );
	}
	$database = new MysqliOptionDatabase( ran_wp_release_updater_test_connect_proof(), 'options' );
	$lock     = new WordPressUpdaterLock( $database );
	$token    = $lock->acquire();
	ran_wp_release_updater_test_assert_no_deprecated_warnings( $database->warnings(), $argv[2] );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Native JSON preserves protocol or fixture bytes without requiring WordPress helpers or their fallback behavior.
	echo json_encode(
		array(
			'worker'   => $argv[2],
			'acquired' => is_string( $token ),
			'token'    => $token,
			'warnings' => $database->warnings(),
		),
		JSON_THROW_ON_ERROR
	) . PHP_EOL;
}

/** @return list<array{worker:string,acquired:bool,token:?string,warnings:list<array{level:string,code:int,message:string}>}> */
function ran_wp_release_updater_test_workers( string $scenario, string $data ): array {
	$processes = array();
	$start_at  = hrtime( true ) + 500000000;
	$workers   = array( $scenario . '-1', $scenario . '-2' );
	foreach ( $workers as $worker ) {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Independent PHP workers must race against the same isolated MySQL server to prove lock mutual exclusion.
		$process     = proc_open(
			array( PHP_BINARY, __FILE__, '--worker', $worker, (string) $start_at ),
			array(
				0 => array( 'pipe', 'r' ),
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes,
			$data
		);
		$processes[] = array(
			'process' => $process,
			'pipes'   => $pipes,
		);
	}
	$results = array();
	foreach ( $processes as $entry ) {
		if ( ! is_resource( $entry['process'] ) ) {
			throw new RuntimeException( 'Could not start MySQL proof worker.' );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the native stream owned by this fixture; WordPress filesystem abstractions do not own process or file handles.
		fclose( $entry['pipes'][0] );
		$out = stream_get_contents( $entry['pipes'][1] );
		$err = stream_get_contents( $entry['pipes'][2] );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the native stream owned by this fixture; WordPress filesystem abstractions do not own process or file handles.
		fclose( $entry['pipes'][1] );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the native stream owned by this fixture; WordPress filesystem abstractions do not own process or file handles.
		fclose( $entry['pipes'][2] );
		$exit = proc_close( $entry['process'] );
		if ( 0 !== $exit ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- This exception carries internal failure data rather than an HTML response; escaping would alter the failure contract.
			throw new RuntimeException( 'Worker failed with exit ' . $exit . '; stdout: ' . substr( $out, 0, 4096 ) . '; stderr: ' . substr( $err, 0, 4096 ) );
		}
		$results[] = json_decode( trim( $out ), true, 32, JSON_THROW_ON_ERROR );
	}
	return $results;
}

/** @param list<array{worker:string,acquired:bool,token:?string,warnings:list<array{level:string,code:int,message:string}>}> $results */
function ran_wp_release_updater_test_assert_one_winner( array $results, string $label ): void {
	$winners = array_values( array_filter( $results, static fn ( array $item ): bool => true === $item['acquired'] && is_string( $item['token'] ) ) );
	$losers  = array_values( array_filter( $results, static fn ( array $item ): bool => false === $item['acquired'] && null === $item['token'] ) );
	if ( 1 !== count( $winners ) || 1 !== count( $losers ) ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- This exception carries internal failure data rather than an HTML response; escaping would alter the failure contract.
		throw new RuntimeException( 'Expected one winner and one loser for ' . $label . '.' );
	}
}

/**
 * @param list<array{worker:string,acquired:bool,token:?string,warnings:list<array{level:string,code:int,message:string}>}> $results
 * @return array{worker:string,acquired:bool,token:string,warnings:list<array{level:string,code:int,message:string}>}
 */
function ran_wp_release_updater_test_winner( array $results ): array {
	foreach ( $results as $item ) {
		if ( true === $item['acquired'] && is_string( $item['token'] ) ) {
			return $item;
		}
	}
	throw new RuntimeException( 'No winning worker found.' );
}

/** @param list<array{level:string,code:int,message:string}> $warnings */
function ran_wp_release_updater_test_assert_no_deprecated_warnings( array $warnings, string $label ): void {
	foreach ( $warnings as $warning ) {
		// Ignore duplicate-key notes from INSERT IGNORE (1062); reject any SQL syntax or deprecation warning (e.g. 1287).
		if ( 1062 === $warning['code'] ) {
			continue;
		}
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- This exception carries internal failure data rather than an HTML response; escaping would alter the failure contract.
		throw new RuntimeException( 'Unexpected MySQL warning in ' . $label . ': [' . $warning['code'] . '] ' . $warning['message'] );
	}
}

function ran_wp_release_updater_test_create_isolated_root(): string {
	$configured = getenv( 'RAN_UPDATER_MYSQL_ROOT' );
	if ( ! is_string( $configured ) || '' === $configured ) {
		throw new RuntimeException( 'Real MySQL CAS proof requires RAN_UPDATER_MYSQL_ROOT to name a durable directory.' );
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Check real permissions before admitting the configured disposable filesystem root.
	if ( is_link( $configured ) || ! is_dir( $configured ) || ! is_writable( $configured ) ) {
		throw new RuntimeException( 'RAN_UPDATER_MYSQL_ROOT must be an existing, writable, non-symlink directory.' );
	}
	$parent = realpath( $configured );
	if ( false === $parent ) {
		throw new RuntimeException( 'Could not resolve RAN_UPDATER_MYSQL_ROOT.' );
	}
	$root = rtrim( $parent, '/\\' ) . '/ran-wp-release-updater-mysql-' . bin2hex( random_bytes( 8 ) );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create real directories for the disposable integration fixture with the specified permissions.
	if ( ! mkdir( $root, 0700 ) ) {
		throw new RuntimeException( 'Could not create isolated MySQL root.' );
	}
	return $root;
}

/**
 * @param resource $server
 */
function ran_wp_release_updater_test_attest_server( $server, string $data ): mysqli {
	$status = proc_get_status( $server );
	if ( ! is_array( $status ) || ! $status['running'] ) { // @phpstan-ignore function.alreadyNarrowedType (Retain runtime evidence validation at the external WordPress or native-process boundary.)
		throw new RuntimeException( 'Isolated MySQL stopped before attestation.' );
	}
	$mysqli = ran_wp_release_updater_test_connect();
	try {
		$result = $mysqli->query( 'SELECT @@datadir AS datadir, @@skip_networking AS skip_networking' );
		if ( ! $result instanceof mysqli_result ) {
			throw new RuntimeException( 'MySQL attestation query did not return rows.' );
		}
		$row      = $result->fetch_assoc();
		$expected = realpath( $data );
		$actual   = is_array( $row ) && isset( $row['datadir'] ) ? realpath( (string) $row['datadir'] ) : false;
		if ( false === $expected || false === $actual || rtrim( $expected, '/\\' ) !== rtrim( $actual, '/\\' ) || ! is_array( $row ) || 1 !== (int) ( $row['skip_networking'] ?? 0 ) ) {
			throw new RuntimeException( 'Unix-socket MySQL attestation did not match the isolated fixture or disable networking.' );
		}
		return $mysqli;
	} catch ( Throwable $error ) {
		$mysqli->close();
		throw $error;
	}
}

function ran_wp_release_updater_test_connect(): mysqli {
	for ( $attempt = 0; $attempt < 100; ++$attempt ) {
		// phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_init -- The isolated proof owns a dedicated native MySQL connection before or outside WordPress database initialization.
		$mysqli    = mysqli_init();
		$connected = false;
		try {
			// phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_real_connect -- Connect only to the isolated fixture database; preserve its socket/port and server-attestation boundary.
			$connected = $mysqli instanceof mysqli && mysqli_real_connect( $mysqli, 'localhost', 'root', '', null, null, 'mysql.sock' );
		// phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- An unavailable isolated server is expected during startup/teardown; preserve the bounded retry or cleanup path.
		} catch ( mysqli_sql_exception ) {
		}
		if ( $connected && $mysqli instanceof mysqli ) {
			return $mysqli;
		}
		if ( $mysqli instanceof mysqli ) {
			$mysqli->close();
		}
		usleep( 50000 );
	}
	throw new RuntimeException( 'Timed out connecting to isolated Unix-socket MySQL.' );
}

function ran_wp_release_updater_test_connect_proof(): mysqli {
	$mysqli = ran_wp_release_updater_test_connect();
	if ( ! $mysqli->select_db( 'proof' ) ) {
		$mysqli->close();
		throw new RuntimeException( 'Could not select isolated proof database.' );
	}
	return $mysqli;
}

function ran_wp_release_updater_test_resolve_mysqld(): string {
	$configured = getenv( 'RAN_UPDATER_MYSQLD_BIN' );
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec -- Fixed command discovers mysqld on PATH; the result must name an executable file before the isolated server starts.
	$candidate = is_string( $configured ) && '' !== $configured ? $configured : trim( (string) shell_exec( 'command -v mysqld 2>/dev/null' ) );
	if ( ! is_file( $candidate ) || ! is_executable( $candidate ) ) {
		throw new RuntimeException( 'Real MySQL CAS proof requires RAN_UPDATER_MYSQLD_BIN to name an executable mysqld binary, or mysqld on PATH; it will not use a running server.' );
	}
	return $candidate;
}

/** @param list<string> $command */
function ran_wp_release_updater_test_run( array $command, string $cwd ): void {
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Initialize the dedicated isolated mysqld data directory with explicit argv.
	$process = proc_open(
		$command,
		array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		),
		$pipes,
		$cwd
	);
	if ( ! is_resource( $process ) ) {
		throw new RuntimeException( 'Could not start isolated MySQL command.' );
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the native stream owned by this fixture; WordPress filesystem abstractions do not own process or file handles.
	fclose( $pipes[0] );
	$out = stream_get_contents( $pipes[1] );
	$err = stream_get_contents( $pipes[2] );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the native stream owned by this fixture; WordPress filesystem abstractions do not own process or file handles.
	fclose( $pipes[1] );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the native stream owned by this fixture; WordPress filesystem abstractions do not own process or file handles.
	fclose( $pipes[2] );
	$exit = proc_close( $process );
	if ( 0 !== $exit ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- This exception carries internal failure data rather than an HTML response; escaping would alter the failure contract.
		throw new RuntimeException( 'Command failed with exit ' . $exit . '; stdout: ' . substr( (string) $out, 0, 2048 ) . '; stderr: ' . substr( (string) $err, 0, 2048 ) );
	}
}

/** @param resource $server */
function ran_wp_release_updater_test_stop_server( $server ): void {
	$status = proc_get_status( $server );
	if ( $status['running'] ) {
		proc_terminate( $server );
	}
	$deadline = microtime( true ) + 10;
	while ( proc_get_status( $server )['running'] && microtime( true ) < $deadline ) {
		usleep( 50000 );
	}
	if ( proc_get_status( $server )['running'] ) {
		proc_terminate( $server, 9 );
	}
	proc_close( $server );
}

function ran_wp_release_updater_test_remove_tree( string $path ): void {
	if ( ! is_dir( $path ) ) {
		return;
	}
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $iterator as $entry ) {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.WP.AlternativeFunctions.unlink_unlink -- Best-effort fixture teardown removes disposable isolated MySQL files.
		$entry->isLink() || ! $entry->isDir() ? @unlink( $entry->getPathname() ) : @rmdir( $entry->getPathname() );
	}
	// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Best-effort fixture teardown removes disposable isolated MySQL root.
	@rmdir( $path );
}
