<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- CLI fixture state is process-local or shared with its controlled callbacks; preserve observed globals and external fixture keys, not plugin runtime globals.

declare(strict_types=1);

// Run against pristine WordPress source and a new, socket-only MySQL instance.
const RAN_WP_RELEASE_UPDATER_TEST_INTEGRATION_MARKER = 'ran-wp-release-updater-integration-v1';
$processes         = array();
$owned_directories = array();
$redactions        = array();
$environment       = array();
$report            = null;
$result            = array(
	'status'      => 'failed',
	'php_version' => PHP_VERSION,
	'scenarios'   => array(),
);

try {
	$scenario = 'all';
	foreach ( array_slice( $argv, 1 ) as $argument ) {
		if ( ! preg_match( '/\A--scenario=(all|distribution|multisite)\z/', $argument, $match ) ) {
			throw new RuntimeException( 'Use --scenario=all|distribution|multisite.' );
		}
		$scenario = $match[1];
	}
	if ( PHP_VERSION_ID < 80200 || ! extension_loaded( 'mysqli' ) || ! extension_loaded( 'zip' ) ) {
		throw new RuntimeException( 'PHP 8.2 with mysqli and zip is required.' );
	}
	$source                            = dirname( __DIR__, 2 );
	$runtime                           = ran_wp_release_updater_test_verify_runtime( $source );
	$result['candidate_revision']      = $runtime['package_revision'];
	$result['candidate_manifest_hash'] = hash_file( 'sha256', $source . '/runtime-copy.json' );
	$suite_bytes                       = '';
	foreach ( array( __FILE__, __DIR__ . '/wordpress-integration/distribution.php', __DIR__ . '/wordpress-integration/multisite.php' ) as $file ) {
		$suite_bytes .= basename( $file ) . "\0" . hash_file( 'sha256', $file ) . "\n";
	}
	$result['suite_revision'] = hash( 'sha256', $suite_bytes );
	$wordpress                = ran_wp_release_updater_test_input_directory( 'RAN_UPDATER_WP_ROOT', false );
	if ( ! is_file( $wordpress . '/wp-load.php' ) ) {
		throw new RuntimeException( 'RAN_UPDATER_WP_ROOT must contain pristine WordPress source.' );
	}
	$workspace        = ran_wp_release_updater_test_input_directory( 'RAN_UPDATER_INTEGRATION_ROOT' );
	$socket_parent    = getenv( 'RAN_UPDATER_SOCKET_ROOT' ) ? ran_wp_release_updater_test_input_directory( 'RAN_UPDATER_SOCKET_ROOT' ) : $workspace;
	$wp_cli           = ran_wp_release_updater_test_input_executable( 'RAN_UPDATER_WP_CLI' );
	$mysqld           = ran_wp_release_updater_test_input_executable( 'RAN_UPDATER_MYSQLD_BIN' );
	$report           = $workspace . '/ran-wp-release-updater-result-' . bin2hex( random_bytes( 6 ) ) . '.json';
	$run              = ran_wp_release_updater_test_own_directory( $workspace, 'ran-wp-release-updater-run-' );
	$socket_directory = ran_wp_release_updater_test_own_directory( $socket_parent, 's-' );
	$socket           = $socket_directory . '/mysql.sock';
	$socket_limit     = 'Darwin' === PHP_OS_FAMILY ? 103 : 107;
	if ( strlen( $socket ) > $socket_limit || ! str_starts_with( $socket, '/' ) ) {
		throw new RuntimeException( 'Set RAN_UPDATER_SOCKET_ROOT to a shorter durable absolute directory.' );
	}
	$scratch = ran_wp_release_updater_test_make_directory( $run . '/scratch' );
	$config  = $run . '/wp-cli.yml';
	ran_wp_release_updater_test_write_file( $config, "{}\n" );
	$environment = array_merge(
		getenv(),
		array(
			'TMPDIR'             => $scratch,
			'TMP'                => $scratch,
			'TEMP'               => $scratch,
			'WP_CLI_CONFIG_PATH' => $config,
			'WP_CLI_CACHE_DIR'   => ran_wp_release_updater_test_make_directory( $run . '/wp-cache' ),
		)
	);
	$mysql       = ran_wp_release_updater_test_make_directory( $run . '/mysql' );
	$data        = ran_wp_release_updater_test_make_directory( $mysql . '/data' );
	$pid_file    = $mysql . '/mysqld.pid';
	ran_wp_release_updater_test_command(
		array(
			$mysqld,
			'--no-defaults',
			'--initialize-insecure',
			'--datadir=' . $data,
			'--socket=' . $socket,
			'--tmpdir=' . $scratch,
			'--skip-mysqlx',
		),
		$mysql,
		'mysql-initialize',
		timeout: 120
	);
	$server                      = ran_wp_release_updater_test_start_process(
		array(
			$mysqld,
			'--no-defaults',
			'--datadir=' . $data,
			'--socket=' . $socket,
			'--skip-networking',
			'--pid-file=' . $pid_file,
			'--tmpdir=' . $scratch,
			'--skip-mysqlx',
			'--log-error=' . $mysql . '/error.log',
		),
		$mysql,
		'mysql-server'
	);
	$database                    = ran_wp_release_updater_test_attest_database( $server, $socket, $data, $pid_file );
	$wp_command                  = array(
		PHP_BINARY,
		'-d',
		'sys_temp_dir=' . $scratch,
		'-d',
		'zend.exception_ignore_args=1',
		$wp_cli,
		'--allow-root',
		'--skip-packages',
	);
	$password                    = bin2hex( random_bytes( 24 ) );
	$redactions[]                = $password;
	$result['wordpress_version'] = ran_wp_release_updater_test_wordpress_version( $wordpress );
	$result['runtime_protocol']  = $runtime['runtime_protocol'];

	if ( 'all' === $scenario || 'distribution' === $scenario ) {
		$started = hrtime( true );
		$site    = ran_wp_release_updater_test_create_site( $wordpress, $run . '/single', $database, $socket );
		ran_wp_release_updater_test_install_wordpress( $wp_command, $site, $password, false );
		$archives  = ran_wp_release_updater_test_consumer_archives( $source, $run );
		$arguments = array_merge( $wp_command, array( '--path=' . $site ) );
		$inputs    = array(
			'RAN_UPDATER_PLUGIN_ZIP' => $archives['plugin'],
			'RAN_UPDATER_THEME_ZIP'  => $archives['theme'],
			'RAN_UPDATER_OUTPUT'     => $run . '/distribution.json',
		);
		$observer  = __DIR__ . '/wordpress-integration/distribution.php';
		ran_wp_release_updater_test_command(
			array_merge( $arguments, array( 'eval-file', $observer ) ),
			$site,
			'distribution-install',
			array_merge( $inputs, array( 'RAN_UPDATER_DISTRIBUTION_MODE' => 'install' ) )
		);
		ran_wp_release_updater_test_command( array_merge( $arguments, array( 'plugin', 'activate', 'ran-neutral-plugin' ) ), $site, 'activate-plugin' );
		ran_wp_release_updater_test_command( array_merge( $arguments, array( 'theme', 'activate', 'ran-neutral-theme' ) ), $site, 'activate-theme' );
		ran_wp_release_updater_test_command(
			array_merge( $arguments, array( 'eval-file', $observer ) ),
			$site,
			'distribution-observe',
			array_merge( $inputs, array( 'RAN_UPDATER_DISTRIBUTION_MODE' => 'observe' ) )
		);
		$proof                               = ran_wp_release_updater_test_read_json( $run . '/distribution.json' );
		$result['scenarios']['distribution'] = $proof;
		ran_wp_release_updater_test_require_fact( true === ( $proof['pass'] ?? null ), 'Installed distribution assertions failed.' );
		$result['scenarios']['distribution']['duration_ms'] = (int) ( ( hrtime( true ) - $started ) / 1000000 );
	}
	if ( 'all' === $scenario || 'multisite' === $scenario ) {
		$started = hrtime( true );
		$site    = ran_wp_release_updater_test_create_site( $wordpress, $run . '/network', $database, $socket );
		ran_wp_release_updater_test_install_wordpress( $wp_command, $site, $password, true );
		$arguments = array_merge( $wp_command, array( '--path=' . $site ) );
		$subsite   = ran_wp_release_updater_test_command(
			array_merge(
				$arguments,
				array(
					'site',
					'create',
					'--slug=subsite',
					'--title=Subsite',
					'--porcelain',
				)
			),
			$site,
			'create-subsite'
		);
		ran_wp_release_updater_test_require_fact( ctype_digit( $subsite ), 'WordPress did not return a subsite ID.' );
		ran_wp_release_updater_test_network_consumer( $source, $site );
		ran_wp_release_updater_test_command( array_merge( $arguments, array( 'plugin', 'activate', 'ran-network-target', '--network' ) ), $site, 'activate-network-plugin' );
		$observer     = __DIR__ . '/wordpress-integration/multisite.php';
		$main_output  = $run . '/main.json';
		$child_output = $run . '/subsite.json';
		$release      = $run . '/release-main';
		$winner       = ran_wp_release_updater_test_start_process(
			array_merge( $arguments, array( '--url=http://example.test', 'eval-file', $observer ) ),
			$site,
			'main-site-discovery',
			array(
				'RAN_UPDATER_NETWORK_OUTPUT'  => $main_output,
				'RAN_UPDATER_NETWORK_RELEASE' => $release,
			)
		);
		ran_wp_release_updater_test_wait_for_output( $winner, $main_output );
		ran_wp_release_updater_test_command(
			array_merge( $arguments, array( '--url=http://example.test/subsite/', 'eval-file', $observer ) ),
			$site,
			'subsite-discovery',
			array( 'RAN_UPDATER_NETWORK_OUTPUT' => $child_output )
		);
		$main                             = ran_wp_release_updater_test_read_json( $main_output );
		$child                            = ran_wp_release_updater_test_read_json( $child_output );
		$result['scenarios']['multisite'] = array(
			'main'    => $main,
			'subsite' => $child,
		);
		ran_wp_release_updater_test_assert_network( $main, $child );
		ran_wp_release_updater_test_write_file( $release, "release\n" );
		ran_wp_release_updater_test_finish_process( $winner );
		// Positive control: the subsite must discover once the competing owner exits.
		$after_output = $run . '/subsite-after-release.json';
		ran_wp_release_updater_test_command(
			array_merge( $arguments, array( '--url=http://example.test/subsite/', 'eval-file', $observer ) ),
			$site,
			'subsite-after-release',
			array( 'RAN_UPDATER_NETWORK_OUTPUT' => $after_output )
		);
		$after = ran_wp_release_updater_test_read_json( $after_output );
		$result['scenarios']['multisite']['after_release'] = $after;
		ran_wp_release_updater_test_require_fact(
			1 === ( $after['provider_callback_delta'] ?? null ) && false === ( $after['suppressed_provider'] ?? null )
			&& $after['blog_id'] === $child['blog_id'] && $after['option']['name'] === $main['option']['name']
			&& true === $after['option']['owner_token_present']
			&& $after['option']['owner_token_sha256'] !== $main['option']['owner_token_sha256']
			&& $after['option']['fence_epoch'] > $main['option']['fence_epoch'],
			'Subsite did not acquire the released fence.'
		);
		$result['scenarios']['multisite']['pass']        = true;
		$result['scenarios']['multisite']['duration_ms'] = (int) ( ( hrtime( true ) - $started ) / 1000000 );
	}
	$database->close();
	$result['status'] = 'passed';
} catch ( Throwable $error ) {
	$result['error'] = ran_wp_release_updater_test_redact( $error->getMessage() );
} finally {
	$cleanup_errors = array();
	// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- This CLI/eval-file fixture variable is local scenario/process state, not a WordPress request global override.
	foreach ( array_reverse( array_keys( $processes ) ) as $id ) {
		try {
			ran_wp_release_updater_test_stop_process( $id );
		} catch ( Throwable $error ) {
			$cleanup_errors[] = ran_wp_release_updater_test_redact( $error->getMessage() ); }
	}
	// Never remove a server's files unless all owned processes have stopped.
	if ( array() === $processes ) {
		foreach ( array_reverse( $owned_directories ) as $directory ) {
			try {
				ran_wp_release_updater_test_remove_owned_directory( $directory );
			} catch ( Throwable $error ) {
				$cleanup_errors[] = ran_wp_release_updater_test_redact( $error->getMessage() ); }
		}
	}
	$result['cleanup'] = array() === $cleanup_errors && array() === $processes ? 'complete' : 'failed';
	if ( 'complete' !== $result['cleanup'] ) {
		$result['status']         = 'failed';
		$result['cleanup_errors'] = $cleanup_errors;
	}
	$json = json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . "\n";
	if ( is_string( $report ) ) {
		ran_wp_release_updater_test_write_file( $report, $json );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Keep the redacted result file private using native permission bits.
		chmod( $report, 0600 );
	}
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Emit redacted JSON to CLI stdout for the parent process; HTML escaping would corrupt the machine-readable proof.
	echo $json;
}
exit( 'passed' === $result['status'] ? 0 : 1 );

function ran_wp_release_updater_test_require_fact( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function ran_wp_release_updater_test_input_directory( string $name, bool $writable = true ): string {
	$value = getenv( $name );
	$path  = is_string( $value ) ? realpath( $value ) : false;
	ran_wp_release_updater_test_require_fact(
		is_string( $path ) && ! is_link( (string) $value ) && is_dir( $path )
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Check real permissions before admitting the configured disposable filesystem root.
		&& ( ! $writable || is_writable( $path ) ),
		$name . ' must name an existing non-symlink directory.'
	);
	return $path;
}

function ran_wp_release_updater_test_input_executable( string $name ): string {
	$value = getenv( $name );
	$path  = is_string( $value ) ? realpath( $value ) : false;
	ran_wp_release_updater_test_require_fact( is_string( $path ) && is_file( $path ) && is_executable( $path ), $name . ' must name an executable file.' );
	return $path;
}

function ran_wp_release_updater_test_make_directory( string $path ): string {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create real directories for the disposable integration fixture with the specified permissions.
	ran_wp_release_updater_test_require_fact( mkdir( $path, 0700, true ), 'Could not create a fixture directory.' );
	return $path;
}

function ran_wp_release_updater_test_write_file( string $path, string $bytes ): void {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write exact bytes for the disposable integration fixture; WordPress helpers would alter the boundary under test.
	ran_wp_release_updater_test_require_fact( strlen( $bytes ) === file_put_contents( $path, $bytes, LOCK_EX ), 'Could not write a fixture file.' );
}

function ran_wp_release_updater_test_own_directory( string $parent_directory, string $prefix ): string {
	global $owned_directories;
	$path = ran_wp_release_updater_test_make_directory( $parent_directory . '/' . $prefix . bin2hex( random_bytes( 6 ) ) );
	try {
		ran_wp_release_updater_test_write_file( $path . '/.integration-owner', RAN_WP_RELEASE_UPDATER_TEST_INTEGRATION_MARKER ); } catch ( Throwable $error ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove native fixture entries directly, preserving the surrounding ownership and link-handling checks.
		rmdir( $path );
		throw $error; }
		$owned_directories[] = $path;
		return $path;
}

function ran_wp_release_updater_test_remove_owned_directory( string $path ): void {
	ran_wp_release_updater_test_require_fact(
		! is_link( $path ) && is_dir( $path )
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read exact local bytes for the disposable integration fixture without requiring WordPress filesystem initialization.
		&& RAN_WP_RELEASE_UPDATER_TEST_INTEGRATION_MARKER === file_get_contents( $path . '/.integration-owner' ),
		'Refusing cleanup without the run ownership marker.'
	);
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $iterator as $file ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove native fixture entries directly, preserving the surrounding ownership and link-handling checks.
		$removed = $file->isLink() || ! $file->isDir() ? unlink( $file->getPathname() ) : rmdir( $file->getPathname() );
		ran_wp_release_updater_test_require_fact( $removed, 'Owned fixture cleanup failed.' );
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove native fixture entries directly, preserving the surrounding ownership and link-handling checks.
	ran_wp_release_updater_test_require_fact( rmdir( $path ), 'Owned run directory cleanup failed.' );
}

function ran_wp_release_updater_test_redact( string $value ): string {
	global $redactions;
	return str_replace( $redactions, '[redacted]', $value );
}

function ran_wp_release_updater_test_start_process( array $command, string $cwd, string $label, array $extra = array(), string $stdin = '' ): int {
	global $processes, $environment;
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Run the isolated proof command with explicit argv, pipe capture and exit-status observation.
	$process = proc_open(
		$command,
		array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		),
		$pipes,
		$cwd,
		array_merge( $environment, $extra )
	);
	ran_wp_release_updater_test_require_fact( is_resource( $process ), $label . ' could not start.' );
	$id               = (int) $process;
	$processes[ $id ] = array(
		'process' => $process,
		'pipes'   => $pipes,
		'label'   => $label,
		'out'     => '',
		'err'     => '',
		'exit'    => null,
	);
	if ( '' !== $stdin ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Write fixture bytes to the native stream while preserving its existing partial-write or subprocess protocol.
		fwrite( $pipes[0], $stdin );
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the native stream owned by this fixture; WordPress filesystem abstractions do not own process or file handles.
	fclose( $pipes[0] );
	stream_set_blocking( $pipes[1], false );
	stream_set_blocking( $pipes[2], false );
	return $id;
}

function ran_wp_release_updater_test_poll_process( int $id ): array {
	global $processes;
	$entry = &$processes[ $id ];
	foreach ( array(
		1 => 'out',
		2 => 'err',
	) as $number => $key ) {
		$chunk = stream_get_contents( $entry['pipes'][ $number ], 65536 );
		if ( is_string( $chunk ) ) {
			$entry[ $key ] = substr( $entry[ $key ] . $chunk, -65536 );
		}
	}
	$status = proc_get_status( $entry['process'] );
	if ( ! $status['running'] && null === $entry['exit'] ) {
		$entry['exit'] = $status['exitcode'];
	}
	return $status;
}

function ran_wp_release_updater_test_finish_process( int $id, int $timeout = 60 ): string {
	global $processes;
	$deadline = microtime( true ) + $timeout;
	while ( ran_wp_release_updater_test_poll_process( $id )['running'] ) {
		ran_wp_release_updater_test_require_fact( microtime( true ) < $deadline, $processes[ $id ]['label'] . ' timed out.' );
		usleep( 25000 );
	}
	ran_wp_release_updater_test_poll_process( $id );
	$entry = $processes[ $id ];
	foreach ( array( 1, 2 ) as $number ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the native stream owned by this fixture; WordPress filesystem abstractions do not own process or file handles.
		fclose( $entry['pipes'][ $number ] );
	}
	$closed = proc_close( $entry['process'] );
	unset( $processes[ $id ] );
	$exit = $entry['exit'] >= 0 ? $entry['exit'] : $closed;
	ran_wp_release_updater_test_require_fact( 0 === $exit, $entry['label'] . ' failed (exit ' . $exit . '): ' . ran_wp_release_updater_test_redact( substr( $entry['err'], -2000 ) ) );
	return trim( $entry['out'] );
}

function ran_wp_release_updater_test_command( array $command, string $cwd, string $label, array $extra = array(), string $stdin = '', int $timeout = 60 ): string {
	return ran_wp_release_updater_test_finish_process( ran_wp_release_updater_test_start_process( $command, $cwd, $label, $extra, $stdin ), $timeout );
}

function ran_wp_release_updater_test_stop_process( int $id ): void {
	global $processes;
	if ( ! isset( $processes[ $id ] ) ) {
		return;
	}
	$process = $processes[ $id ]['process'];
	if ( ran_wp_release_updater_test_poll_process( $id )['running'] ) {
		proc_terminate( $process );
	}
	$deadline = microtime( true ) + 5;
	while ( ran_wp_release_updater_test_poll_process( $id )['running'] && microtime( true ) < $deadline ) {
		usleep( 25000 );
	}
	if ( ran_wp_release_updater_test_poll_process( $id )['running'] ) {
		proc_terminate( $process, 9 );
	}
	$deadline = microtime( true ) + 5;
	while ( ran_wp_release_updater_test_poll_process( $id )['running'] && microtime( true ) < $deadline ) {
		usleep( 25000 );
	}
	ran_wp_release_updater_test_require_fact( ! ran_wp_release_updater_test_poll_process( $id )['running'], 'Owned process did not stop: ' . $processes[ $id ]['label'] );
	foreach ( array( 1, 2 ) as $number ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the native stream owned by this fixture; WordPress filesystem abstractions do not own process or file handles.
		fclose( $processes[ $id ]['pipes'][ $number ] );
	}
	proc_close( $process );
	unset( $processes[ $id ] );
}

function ran_wp_release_updater_test_attest_database( int $server, string $socket, string $data, string $pid_file ): mysqli {
	// phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_report -- Use native strict MySQL reporting for the isolated proof connection and its failure handling.
	mysqli_report( MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT );
	$deadline = microtime( true ) + 30;
	do {
		$status = ran_wp_release_updater_test_poll_process( $server );
		ran_wp_release_updater_test_require_fact( $status['running'], 'Owned MySQL exited before socket attestation.' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read exact local bytes for the disposable integration fixture without requiring WordPress filesystem initialization.
		if ( is_file( $pid_file ) && trim( (string) file_get_contents( $pid_file ) ) === (string) $status['pid'] ) {
			try {
				// phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_init -- The isolated proof owns a dedicated native MySQL connection before or outside WordPress database initialization.
				$db = mysqli_init();
				$db->real_connect( 'localhost', 'root', '', null, 0, $socket );
			} catch ( mysqli_sql_exception ) {
				if ( isset( $db ) ) {
					$db->close();
				}
				usleep( 50000 );
				continue;
			}
			$row = $db->query( 'SELECT @@datadir AS d, @@pid_file AS p, @@skip_networking AS n' )->fetch_assoc();
			ran_wp_release_updater_test_require_fact(
				realpath( (string) $row['d'] ) === realpath( $data )
				&& realpath( (string) $row['p'] ) === realpath( $pid_file ) && 1 === (int) $row['n'],
				'Owned MySQL attestation failed.'
			);
			return $db;
		}
		usleep( 50000 );
	} while ( microtime( true ) < $deadline );
	throw new RuntimeException( 'Timed out attesting the owned MySQL socket.' );
}

function ran_wp_release_updater_test_runtime_files( string $source ): array {
	$files    = array( 'bootstrap.php', 'runtime.php' );
	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $source . '/src', FilesystemIterator::SKIP_DOTS ) );
	foreach ( $iterator as $file ) {
		ran_wp_release_updater_test_require_fact( ! $file->isLink(), 'Runtime source must not contain symlinks.' );
		if ( $file->isFile() && 'php' === $file->getExtension() ) {
			$files[] = substr( $file->getPathname(), strlen( $source ) + 1 );
		}
	}
	sort( $files, SORT_STRING );
	return $files;
}

function ran_wp_release_updater_test_verify_runtime( string $source ): array {
	$runtime = ran_wp_release_updater_test_read_json( $source . '/runtime-copy.json' );
	$payload = '';
	foreach ( ran_wp_release_updater_test_runtime_files( $source ) as $file ) {
		$payload .= $file . "\0" . hash_file( 'sha256', $source . '/' . $file ) . "\n";
	}
	ran_wp_release_updater_test_require_fact(
		5 === ( $runtime['runtime_protocol'] ?? null )
		&& hash( 'sha256', $payload ) === ( $runtime['package_revision'] ?? null ),
		'Runtime manifest does not match the Protocol 4 source bytes.'
	);
	return $runtime;
}

function ran_wp_release_updater_test_copy_runtime( string $source, string $destination ): void {
	global $runtime;
	ran_wp_release_updater_test_make_directory( $destination );
	foreach ( array_merge( ran_wp_release_updater_test_runtime_files( $source ), array( 'LICENSE', 'composer.json', 'runtime-copy.json' ) ) as $file ) {
		$target = $destination . '/' . $file;
		if ( ! is_dir( dirname( $target ) ) ) {
			ran_wp_release_updater_test_make_directory( dirname( $target ) );
		}
		ran_wp_release_updater_test_require_fact( ! is_link( $source . '/' . $file ) && copy( $source . '/' . $file, $target ), 'Runtime distribution copy failed.' );
	}
	ran_wp_release_updater_test_require_fact( ran_wp_release_updater_test_verify_runtime( $destination ) === $runtime, 'Copied runtime changed after candidate verification.' );
}

function ran_wp_release_updater_test_wordpress_version( string $source ): string {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read exact local bytes for the disposable integration fixture without requiring WordPress filesystem initialization.
	$bytes = (string) file_get_contents( $source . '/wp-includes/version.php' );
	ran_wp_release_updater_test_require_fact( 1 === preg_match( '/\$wp_version\s*=\s*[\'"]([^\'"]+)[\'"]/', $bytes, $matches ), 'Could not read the WordPress fixture version.' );
	return $matches[1];
}

function ran_wp_release_updater_test_create_site( string $wordpress, string $site, mysqli $db, string $socket ): string {
	ran_wp_release_updater_test_make_directory( $site );
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $wordpress, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::SELF_FIRST
	);
	foreach ( $iterator as $file ) {
		$relative = substr( $file->getPathname(), strlen( $wordpress ) + 1 );
		$first    = explode( '/', $relative )[0];
		if ( in_array( $first, array( 'wp-content', 'wp-config.php', '.git', '.well-known' ), true ) ) {
			continue;
		}
		ran_wp_release_updater_test_require_fact( ! $file->isLink(), 'Pristine WordPress fixture contains a symlink.' );
		$target = $site . '/' . $relative;
		if ( $file->isDir() ) {
			ran_wp_release_updater_test_make_directory( $target );
		} else {
			ran_wp_release_updater_test_require_fact( copy( $file->getPathname(), $target ), 'WordPress fixture copy failed.' );
		}
	}
	foreach ( array( 'plugins', 'themes', 'uploads', 'mu-plugins' ) as $directory ) {
		ran_wp_release_updater_test_make_directory( $site . '/wp-content/' . $directory );
	}
	$name = 'ran_integration_' . bin2hex( random_bytes( 6 ) );
	$db->query( 'CREATE DATABASE ' . $name );
	$config = "<?php\n";
	foreach ( array(
		'DB_NAME'            => $name,
		'DB_USER'            => 'root',
		'DB_PASSWORD'        => '',
		'DB_HOST'            => 'localhost:' . $socket,
		'DB_CHARSET'         => 'utf8mb4',
		'DB_COLLATE'         => '',
		'FS_METHOD'          => 'direct',
		'DISABLE_WP_CRON'    => true,
		'WP_ALLOW_MULTISITE' => true,
	) as $key => $value ) {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Encode controlled fixture values as PHP literals for the isolated child script; this is not debug output.
		$config .= 'define(' . var_export( $key, true ) . ', ' . var_export( $value, true ) . ");\n";
	}
	$config .= "\$table_prefix = 'wp_';\nif (!defined('ABSPATH')) define('ABSPATH', __DIR__ . '/');\nrequire_once ABSPATH . 'wp-settings.php';\n";
	ran_wp_release_updater_test_write_file( $site . '/wp-config.php', $config );
	ran_wp_release_updater_test_write_file(
		$site . '/wp-content/mu-plugins/integration-http.php',
		<<<'PHP'
<?php
$GLOBALS['ran_updater_http_calls'] = 0;
add_filter('pre_http_request', static function ($pre, $args, $url) {
	if ('api.github.com' === parse_url($url, PHP_URL_HOST)) {
		++$GLOBALS['ran_updater_http_calls'];
	}
	return new WP_Error('integration_http_blocked', 'Integration fixture: outbound HTTP disabled.');
}, PHP_INT_MAX, 3);
PHP
	);
	return $site;
}

function ran_wp_release_updater_test_install_wordpress( array $base, string $site, string $password, bool $network ): void {
	$arguments = array(
		'--path=' . $site,
		'core',
		$network ? 'multisite-install' : 'install',
		'--url=http://example.test',
		'--title=Integration',
		'--admin_user=admin',
		'--prompt=admin_password',
		'--admin_email=admin@example.test',
		'--skip-email',
	);
	if ( $network ) {
		$arguments[] = '--subdomains=0';
	}
	ran_wp_release_updater_test_command( array_merge( $base, $arguments ), $site, $network ? 'install-multisite' : 'install-wordpress', stdin: $password . "\n" );
	if ( $network ) {
		// WP-CLI installs the network tables; subsequent requests also need its constants.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read exact local bytes for the disposable integration fixture without requiring WordPress filesystem initialization.
		$config      = (string) file_get_contents( $site . '/wp-config.php' );
		$definitions = '';
		foreach ( array(
			'MULTISITE'            => true,
			'SUBDOMAIN_INSTALL'    => false,
			'DOMAIN_CURRENT_SITE'  => 'example.test',
			'PATH_CURRENT_SITE'    => '/',
			'SITE_ID_CURRENT_SITE' => 1,
			'BLOG_ID_CURRENT_SITE' => 1,
		) as $key => $value ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Encode controlled fixture values as PHP literals for the isolated child script; this is not debug output.
			$definitions .= 'define(' . var_export( $key, true ) . ', ' . var_export( $value, true ) . ");\n";
		}
		ran_wp_release_updater_test_write_file( $site . '/wp-config.php', str_replace( "require_once ABSPATH . 'wp-settings.php';", $definitions . "require_once ABSPATH . 'wp-settings.php';", $config ) );
	}
}

function ran_wp_release_updater_test_consumer_archives( string $source, string $run ): array {
	$archives = array();
	foreach ( array( 'plugin', 'theme' ) as $type ) {
		$slug = 'ran-neutral-' . $type;
		$root = ran_wp_release_updater_test_make_directory( $run . '/' . $slug );
		ran_wp_release_updater_test_copy_runtime( $source, $root . '/vendor/ran/wp-release-updater' );
		$header = "/*\n" . ( 'plugin' === $type ? 'Plugin' : 'Theme' ) . " Name: Integration consumer\nVersion: 1.0.0\n"
			. 'Update URI: https://github.com/fixture/neutral-' . $type . "\nRequires PHP: 8.2\nRequires at least: 6.5\n*/\n";
		$entry  = 'plugin' === $type ? '__FILE__' : "__DIR__ . '/style.css'";
		$php    = "<?php\n" . ( 'plugin' === $type ? $header : '' );
		$php   .= "\$GLOBALS['ran_updater_credential_calls']['" . $type . "'] = 0;\n"
			. "\$registrar = require __DIR__ . '/vendor/ran/wp-release-updater/bootstrap.php';\n"
			. "\$credentials = static function (): ?string { ++\$GLOBALS['ran_updater_credential_calls']['" . $type . "']; return null; };\n"
			. "\$GLOBALS['ran_updater_" . $type . "_handle'] = \$registrar->" . $type . "('github', " . $entry
			. ", 'fixture/neutral-" . $type . "', '" . ( 'plugin' === $type ? '123456789' : '123456790' ) . "', 'stable', 'manual', \$credentials);\n"
			. "\$GLOBALS['ran_updater_" . $type . "_handle']->register();\n";
		if ( 'plugin' === $type ) {
			ran_wp_release_updater_test_write_file( $root . '/' . $slug . '.php', $php );
		} else {
			ran_wp_release_updater_test_write_file( $root . '/style.css', $header );
			ran_wp_release_updater_test_write_file( $root . '/functions.php', $php );
			ran_wp_release_updater_test_write_file( $root . '/index.php', "<?php\n" );
		}
		$archive = $run . '/' . $slug . '.zip';
		$zip     = new ZipArchive();
		ran_wp_release_updater_test_require_fact( true === $zip->open( $archive, ZipArchive::CREATE | ZipArchive::EXCL ), 'Could not create consumer ZIP.' );
		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $file ) {
			ran_wp_release_updater_test_require_fact( $zip->addFile( $file->getPathname(), $slug . '/' . substr( $file->getPathname(), strlen( $root ) + 1 ) ), 'Could not add consumer ZIP entry.' );
		}
		ran_wp_release_updater_test_require_fact( $zip->close(), 'Could not finish consumer ZIP.' );
		$archives[ $type ] = $archive;
	}
	return $archives;
}

function ran_wp_release_updater_test_network_consumer( string $source, string $site ): void {
	$root = ran_wp_release_updater_test_make_directory( $site . '/wp-content/plugins/ran-network-target' );
	ran_wp_release_updater_test_copy_runtime( $source, $root . '/vendor/ran/wp-release-updater' );
	ran_wp_release_updater_test_write_file(
		$root . '/main.php',
		<<<'PHP'
<?php
/*
Plugin Name: Integration network consumer
Version: 1.0.0
Update URI: https://github.com/fixture/network-target
*/
$registrar = require __DIR__ . '/vendor/ran/wp-release-updater/bootstrap.php';
$GLOBALS['ran_network_handle'] = $registrar->plugin('github', __FILE__, 'fixture/network-target', '123456791');
$GLOBALS['ran_network_duplicate_handle'] = $registrar->plugin('github', __FILE__, 'fixture/network-target', '123456791');
$GLOBALS['ran_network_handle']->register();
$GLOBALS['ran_network_duplicate_handle']->register();
PHP
	);
}

function ran_wp_release_updater_test_wait_for_output( int $process, string $path ): void {
	$deadline = microtime( true ) + 15;
	while ( ! is_file( $path ) ) {
		ran_wp_release_updater_test_require_fact( ran_wp_release_updater_test_poll_process( $process )['running'], 'Main-site discovery exited before producing evidence.' );
		ran_wp_release_updater_test_require_fact( microtime( true ) < $deadline, 'Main-site discovery did not reach the synchronization barrier.' );
		usleep( 25000 );
	}
	ran_wp_release_updater_test_require_fact( ran_wp_release_updater_test_poll_process( $process )['running'], 'Main-site discovery must remain alive while the subsite runs.' );
}

function ran_wp_release_updater_test_assert_network( array $main, array $child ): void {
	ran_wp_release_updater_test_require_fact(
		is_int( $main['blog_id'] ?? null ) && is_int( $child['blog_id'] ?? null )
		&& $main['blog_id'] !== $child['blog_id'] && $main['network_id'] === $child['network_id'],
		'Expected two sites in the same network.'
	);
	foreach ( array( $main, $child ) as $proof ) {
		ran_wp_release_updater_test_require_fact(
			true === ( $proof['duplicate_registration_accepted'] ?? null )
			&& 1 === ( $proof['logical_target_count'] ?? null ) && 1 === ( $proof['native_callback_count'] ?? null )
			&& 'target_active' === ( $proof['status']['code'] ?? null )
			&& true === ( $proof['option']['present'] ?? null ) && true === ( $proof['option']['owner_token_present'] ?? null )
			&& $proof['network_id'] === $proof['option']['network_id']
			&& ( $proof['option']['lease_deadline'] ?? 0 ) > time(),
			'Network target or live persisted fence is missing.'
		);
	}
	ran_wp_release_updater_test_require_fact(
		1 === $main['provider_callback_delta'] && false === $main['suppressed_provider']
		&& 0 === $child['provider_callback_delta'] && true === $child['suppressed_provider'],
		'Competing site was not fenced before provider access.'
	);
	ran_wp_release_updater_test_require_fact(
		$main['option'] === $child['option']
		&& 1 === preg_match( '/\A[0-9a-f]{64}\z/', $main['option']['owner_token_sha256'] ?? '' ),
		'Sites did not observe the same unchanged fence owner.'
	);
}

function ran_wp_release_updater_test_read_json( string $path ): array {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read exact local bytes for the disposable integration fixture without requiring WordPress filesystem initialization.
	$record = json_decode( (string) file_get_contents( $path ), true, 128, JSON_THROW_ON_ERROR );
	ran_wp_release_updater_test_require_fact( is_array( $record ), 'Expected a JSON evidence object.' );
	return $record;
}

// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
