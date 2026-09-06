<?php

declare( strict_types = 1 );

$pluginRoot = realpath( __DIR__ . '/../../' ) ?: __DIR__ . '/../../';
$wpRootInput = getenv( 'RAN_WP_RELEASE_UPDATER_LOCAL_WP_ROOT' );
$wpRoot     = is_string( $wpRootInput ) && '' !== $wpRootInput ? realpath( $wpRootInput ) : false;

$marker    = 'RAN_WP_RELEASE_UPDATER_PHASE24';
$workspaceInput = getenv( 'RAN_WP_RELEASE_UPDATER_PHASE24_WORKSPACE' );
$workspace = is_string( $workspaceInput ) && '' !== $workspaceInput
	? realpath( $workspaceInput )
	: $pluginRoot . '/.workspaces/p0.4';
$base      = rtrim( (string) $workspace, '/\\' ) . '/' . strtolower( $marker ) . '-' . bin2hex( random_bytes( 16 ) );
$dbName    = 'ran_updater_phase24_' . random_int( 100000, 999999 );
$sitePath  = $base . '/site';
$hookFile  = $base . '/phase24-harness-output-' . $dbName . '.json';
$markerFile = $base . '/RAN_WP_RELEASE_UPDATER_PHASE24.marker';
$wpCliCmd  = '/usr/local/bin/wp';
$php82Candidates = glob( '/Applications/Local.app/Contents/Resources/extraResources/lightning-services/php-8.2*/bin/darwin-arm64/bin/php' );
$wpCliPhp  = getenv( 'RAN_WP_RELEASE_UPDATER_PHP82' ) ?: ( is_array( $php82Candidates ) && isset( $php82Candidates[0] ) ? $php82Candidates[0] : '' );
$server    = null;
$mode      = 'isolated_mysql_server';
$dbUser    = getenv( 'RAN_WP_RELEASE_UPDATER_DB_USER' ) ?: 'root';
$dbPassRaw = getenv( 'RAN_WP_RELEASE_UPDATER_DB_PASSWORD' );
$dbPassword = false === $dbPassRaw ? '' : $dbPassRaw;

if ( false === $workspace || ! is_dir( $workspace ) || file_exists( $base ) || is_link( $base ) || dirname( $base ) !== $workspace ) {
	throw new RuntimeException( 'Refusing to run harness outside its durable workspace.' );
}
if (
	false === $wpRoot
	|| ! is_file( $wpRoot . '/wp-load.php' )
	|| ! is_file( $wpRoot . '/wp-settings.php' )
	|| ! is_file( $wpRoot . '/wp-includes/version.php' )
) {
	throw new RuntimeException( 'Could not resolve a safe local WordPress root.' );
}
$phpTempDir = $workspace . '/php-tmp';
if ( ! is_dir( $phpTempDir ) && ! mkdir( $phpTempDir, 0700, true ) ) {
	throw new RuntimeException( 'Could not prepare the PHP temporary directory.' );
}
putenv( 'TMPDIR=' . $phpTempDir );
putenv( 'TMP=' . $phpTempDir );
putenv( 'TEMP=' . $phpTempDir );

$mysqld = getenv( 'RAN_UPDATER_MYSQLD_BIN' ) ?: '/Applications/Local.app/Contents/Resources/extraResources/lightning-services/mysql-8.4.0/bin/darwin-arm64/bin/mysqld';
$mysqlDir = $base . '/mysql';
$socket = 'mysql.sock';
$pidFile = 'mysqld.pid';
$port = reserveLoopbackPort();

$result = array(
	'marker'          => $marker,
	'status'          => 'errored',
	'disposable_root' => $base,
	'database_mode'   => null,
	'source_root'     => $pluginRoot,
);

putenv( 'RAN_WP_RELEASE_UPDATER_PHASE24=' . $marker );
if ( ! mkdir( $base, 0700, false ) ) {
	throw new RuntimeException( 'Could not prepare isolated proof root.' );
}
if ( is_link( $base ) || $base !== realpath( $base ) || dirname( $base ) !== $workspace || false === file_put_contents( $markerFile, $marker . "\n" ) ) {
	throw new RuntimeException( 'Could not establish a real disposable proof root and marker.' );
}

if ( ! is_dir( $base . '/mysql' ) && ! mkdir( $base . '/mysql', 0700, true ) ) {
	throw new RuntimeException( 'Could not prepare isolated MySQL working directory.' );
}

try {
	$mysqlDataDir = $base . '/mysql/data';
	if ( ! is_file( $mysqld ) || ! is_executable( $mysqld ) ) {
		throw new RuntimeException( 'The isolated Local mysqld binary is unavailable.' );
	}
	run_command( array( $mysqld, '--no-defaults', '--initialize-insecure', '--datadir=' . $mysqlDataDir, '--socket=' . $socket, '--tmpdir=' . $mysqlDir, '--skip-mysqlx' ), $mysqlDir, null, true );
	$server = proc_open(
		array( $mysqld, '--no-defaults', '--datadir=' . $mysqlDataDir, '--bind-address=127.0.0.1', '--port=' . $port, '--socket=' . $socket, '--pid-file=' . $pidFile, '--tmpdir=' . $mysqlDir, '--skip-mysqlx', '--log-error=' . $mysqlDir . '/mysqld.err' ),
		array( 0 => array( 'pipe', 'r' ), 1 => array( 'file', $mysqlDir . '/mysqld.out', 'a' ), 2 => array( 'file', $mysqlDir . '/mysqld.err', 'a' ) ),
		$serverPipes,
		$mysqlDir
	);
	if ( ! is_resource( $server ) ) {
		throw new RuntimeException( 'Could not launch isolated mysqld.' );
	}
	fclose( $serverPipes[0] );
	$mode = 'isolated_mysql_server';

	$result['database_mode'] = $mode;
	$mysqli = attest_server( $server, $port, $mysqlDataDir, $dbUser, $dbPassword );
	$mysqli->query( 'CREATE DATABASE IF NOT EXISTS `' . $mysqli->real_escape_string( $dbName ) . '`' );
	$mysqli->close();

	copy_tree( $wpRoot, $sitePath, array( '.git', 'wp-content', 'wp-config.php', '.well-known' ) );
	foreach ( array( $sitePath . '/wp-content/plugins', $sitePath . '/wp-content/themes', $sitePath . '/wp-content/uploads' ) as $directory ) {
		if ( ! is_dir( $directory ) && ! mkdir( $directory, 0700, true ) ) {
			throw new RuntimeException( 'Could not create disposable wp-content directory.' );
		}
	}
	$copiedUpdater = $sitePath . '/wp-content/plugins/ran-wp-release-updater';
	copy_tree( $pluginRoot, $copiedUpdater, array( 'tests', '.git', '.github', '.phpunit.cache', '.workspaces', 'node_modules', 'vendor' ) );
	if ( is_dir( $copiedUpdater . '/.phpunit.cache' ) || is_dir( $copiedUpdater . '/.workspaces' ) ) {
		throw new RuntimeException( 'Disposable updater copy includes a private work or cache root.' );
	}

	$pluginUri = 'https://github.com/phase24-owner/phase24-plugin';
	$themeUri  = 'https://github.com/phase24-owner/phase24-theme';
	$managerThemeUri = 'https://github.com/phase24-owner/phase24-manager-theme';
	$pluginArchive = make_fixture_archive( $sitePath, 'phase24-plugin', $pluginUri, 'plugin', '2.0.0', false );
	$themeArchive  = make_fixture_archive( $sitePath, 'phase24-theme', $themeUri, 'theme', '2.0.0', true );

	create_fixture_plugin( $sitePath, 'phase24-plugin', $pluginUri );
	create_fixture_theme( $sitePath, 'phase24-theme', $themeUri );
	create_fixture_theme( $sitePath, 'phase24-manager-theme', $managerThemeUri );
	create_manager_plugin( $sitePath, 'phase24-manager-theme', $managerThemeUri );

	file_put_contents(
		$sitePath . '/wp-config.php',
		"<?php\n" .
		"define( 'DB_NAME', '{$dbName}' );\n" .
		"define( 'DB_USER', '{$dbUser}' );\n" .
		"define( 'DB_PASSWORD', '{$dbPassword}' );\n" .
		"define( 'DB_HOST', '127.0.0.1:{$port}' );\n" .
		"define( 'DB_CHARSET', 'utf8' );\n" .
		"define( 'DB_COLLATE', '' );\n" .
		"define( 'AUTH_KEY', 'phase24-disposable' );\n" .
		"define( 'SECURE_AUTH_KEY', 'phase24-disposable' );\n" .
		"define( 'LOGGED_IN_KEY', 'phase24-disposable' );\n" .
		"define( 'NONCE_KEY', 'phase24-disposable' );\n" .
		"define( 'AUTH_SALT', 'phase24-disposable' );\n" .
		"define( 'SECURE_AUTH_SALT', 'phase24-disposable' );\n" .
		"define( 'LOGGED_IN_SALT', 'phase24-disposable' );\n" .
		"define( 'NONCE_SALT', 'phase24-disposable' );\n\n" .
		"\$table_prefix = 'wp_';\n" .
		"define( 'WP_DEBUG', false );\n" .
		"define( 'FS_METHOD', 'direct' );\n" .
		"define( 'AUTOMATIC_UPDATER_DISABLED', false );\n" .
		"define( 'DISABLE_WP_CRON', true );\n" .
		"define( 'DOING_CRON', '1' === getenv( 'RAN_WP_RELEASE_UPDATER_DOING_CRON' ) );\n" .
		"if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', dirname( __FILE__ ) . '/' );\n" .
		"require_once ABSPATH . 'wp-settings.php';\n"
	);

	$cliEnv = array( 'DB_HOST' => '127.0.0.1:' . $port, 'DB_USER' => $dbUser, 'DB_PASSWORD' => $dbPassword, 'TMPDIR' => $phpTempDir, 'TMP' => $phpTempDir, 'TEMP' => $phpTempDir );

	if ( ! is_file( $wpCliCmd ) || ! is_file( $wpCliPhp ) || ! is_executable( $wpCliPhp ) ) {
		throw new RuntimeException( 'Required local WP-CLI or PHP 8.2 runtime is unavailable.' );
	}
	$adminPassword = bin2hex( random_bytes( 32 ) );
	run_command( array( $wpCliPhp, '-n', '-d', 'sys_temp_dir=' . $phpTempDir, $wpCliCmd, '--path=' . $sitePath, 'core', 'install', '--skip-email', '--url=http://127.0.0.1', '--title=phase24', '--admin_user=admin', '--prompt=admin_password', '--admin_email=admin@example.test' ), $sitePath, $cliEnv, true, $adminPassword . "\n" );
	run_command( array( $wpCliPhp, '-n', '-d', 'sys_temp_dir=' . $phpTempDir, $wpCliCmd, '--path=' . $sitePath, 'plugin', 'activate', 'phase24-plugin' ), $sitePath, $cliEnv, true );
	run_command( array( $wpCliPhp, '-n', '-d', 'sys_temp_dir=' . $phpTempDir, $wpCliCmd, '--path=' . $sitePath, 'plugin', 'activate', 'phase24-manager' ), $sitePath, $cliEnv, true );
	run_command( array( $wpCliPhp, '-n', '-d', 'sys_temp_dir=' . $phpTempDir, $wpCliCmd, '--path=' . $sitePath, 'theme', 'activate', 'phase24-theme' ), $sitePath, $cliEnv, true );

	$probeEnv = array(
		'RAN_WP_RELEASE_UPDATER_PHASE24' => $marker,
		'RAN_WP_RELEASE_UPDATER_SOURCE_ROOT' => $sitePath . '/wp-content/plugins/ran-wp-release-updater',
		'RAN_WP_RELEASE_UPDATER_PLUGIN_ID' => 'phase24-plugin/phase24-plugin.php',
		'RAN_WP_RELEASE_UPDATER_THEME_ID' => 'phase24-theme',
		'RAN_WP_RELEASE_UPDATER_MANAGER_THEME_ID' => 'phase24-manager-theme',
		'RAN_WP_RELEASE_UPDATER_PLUGIN_URI' => $pluginUri,
		'RAN_WP_RELEASE_UPDATER_THEME_URI' => $themeUri,
		'RAN_WP_RELEASE_UPDATER_MANAGER_THEME_URI' => $managerThemeUri,
		'RAN_WP_RELEASE_UPDATER_PLUGIN_ARCHIVE' => $pluginArchive,
		'RAN_WP_RELEASE_UPDATER_THEME_ARCHIVE' => $themeArchive,
		'RAN_WP_RELEASE_UPDATER_PLUGIN_FAILURE_ARCHIVE' => make_fixture_archive( $sitePath, 'phase24-plugin', $pluginUri, 'plugin-failure', '3.0.0', false ),
		'RAN_WP_RELEASE_UPDATER_THEME_FAILURE_ARCHIVE' => make_fixture_archive( $sitePath, 'phase24-theme', $themeUri, 'theme-failure', '3.0.0', true ),
		'RAN_WP_RELEASE_UPDATER_PLUGIN_VALIDATION_ARCHIVE' => make_fixture_archive( $sitePath, 'phase24-plugin', $pluginUri . '-wrong', 'plugin-validation', '3.0.0', false ),
		'RAN_WP_RELEASE_UPDATER_THEME_VALIDATION_ARCHIVE' => make_fixture_archive( $sitePath, 'phase24-theme', $themeUri . '-wrong', 'theme-validation', '3.0.0', true ),
		'RAN_WP_RELEASE_UPDATER_OUTPUT' => $hookFile,
		'RAN_WP_RELEASE_UPDATER_MARKER_FILE' => $markerFile,
	);

	$phaseOutput = array();
	$successfulDigests = array();
	foreach ( array( 'manual', 'automatic' ) as $policy ) foreach ( array( 'plugin', 'theme' ) as $type ) foreach ( array( 'success', 'download', 'validation', 'install' ) as $phaseMode ) {
		$phase = array( $policy, $phaseMode, $type );
		if ( 'success' === $phaseMode ) {
			if ( 'plugin' === $type ) create_fixture_plugin( $sitePath, 'phase24-plugin', $pluginUri ); else create_fixture_theme( $sitePath, 'phase24-theme', $themeUri );
		}
		$phaseFile = $base . '/phase24-' . implode( '-', $phase ) . '.json';
		$phaseEnv = $probeEnv;
		$phaseEnv['RAN_WP_RELEASE_UPDATER_MODE'] = $phaseMode;
		$phaseEnv['RAN_WP_RELEASE_UPDATER_TARGET_TYPE'] = $type;
		$phaseEnv['RAN_WP_RELEASE_UPDATER_POLICY'] = $policy;
		$phaseEnv['RAN_WP_RELEASE_UPDATER_ARCHIVE'] = 'plugin' === $type ? ( 'success' === $phaseMode ? $pluginArchive : ( 'validation' === $phaseMode ? $phaseEnv['RAN_WP_RELEASE_UPDATER_PLUGIN_VALIDATION_ARCHIVE'] : $phaseEnv['RAN_WP_RELEASE_UPDATER_PLUGIN_FAILURE_ARCHIVE'] ) ) : ( 'success' === $phaseMode ? $themeArchive : ( 'validation' === $phaseMode ? $phaseEnv['RAN_WP_RELEASE_UPDATER_THEME_VALIDATION_ARCHIVE'] : $phaseEnv['RAN_WP_RELEASE_UPDATER_THEME_FAILURE_ARCHIVE'] ) );
		$phaseEnv['RAN_WP_RELEASE_UPDATER_DOING_CRON'] = 'automatic' === $policy ? '1' : '0';
		$phaseEnv['RAN_WP_RELEASE_UPDATER_FAILURE_STAGE'] = $phaseMode;
		$phaseEnv['RAN_WP_RELEASE_UPDATER_OUTPUT'] = $phaseFile;
		$phaseEnv['TMPDIR'] = $phpTempDir;
		$phaseEnv['TMP'] = $phpTempDir;
		$phaseEnv['TEMP'] = $phpTempDir;
		run_command( array( $wpCliPhp, '-n', '-d', 'sys_temp_dir=' . $phpTempDir, $wpCliCmd, '--path=' . $sitePath, 'eval-file', $pluginRoot . '/tests/Integration/phase-2.4-wordpress-core-proof-harness.php' ), $sitePath, $phaseEnv, true );
		$one = json_decode( (string) file_get_contents( $phaseFile ), true, 64, JSON_THROW_ON_ERROR );
		$database = is_array( $one ) ? ( $one['post_shutdown']['database'] ?? null ) : null;
		$preofferFailure = in_array( $phaseMode, array( 'download', 'validation' ), true );
		$http = is_array( $one ) ? ( $one['post_shutdown']['http'] ?? null ) : null;
		$commonProof = is_array( $one )
			&& $sitePath . '/wp-content/plugins/ran-wp-release-updater' === ( $one['sourceRoot'] ?? null )
			&& true === ( $one['activation_readback']['plugin_active'] ?? null )
			&& true === ( $one['activation_readback']['theme_active'] ?? null )
			&& false === ( $one['activation_readback']['manager_theme_active'] ?? null )
			&& 'active' === ( $one['registration']['manager']['state'] ?? null )
			&& true === ( $one['post_shutdown']['network_guard_installed'] ?? null )
			&& is_array( $http )
			&& true === ( $one['post_shutdown']['network_guard_proved'] ?? null )
			&& 0 === ( $one['post_shutdown']['mail_attempts'] ?? null )
			&& true === ( $one['post_shutdown']['mail_short_circuited'] ?? null )
			&& 1 === ( $http['guard'] ?? null )
			&& 0 === ( $http['blocked'] ?? null )
			&& array() === ( $http['blocked_urls'] ?? null )
			&& 0 === ( $http['core_denied'] ?? null )
			&& 0 === ( $http['credential_leaks'] ?? null )
			&& true === ( $one['post_shutdown']['credential_absent_from_evidence'] ?? null )
			&& true === ( $one['post_shutdown']['backup_absent'] ?? null )
			&& true === ( $one['post_shutdown']['maintenance_absent'] ?? null )
			&& is_array( $database )
			&& true === ( $database['target_exists'] ?? null )
			&& 'no' === ( $database['target_autoload'] ?? null )
			&& 1 === ( $database['target_schema'] ?? null )
			&& 0 === ( $database['state_row_count'] ?? null );
		$successProof = 'success' === $phaseMode
			&& true === ( $one['sanity']['offer_hook_fired'] ?? null )
			&& ( 'automatic' !== $policy || 0 < ( $one['automatic_vcs_checkout_override']['calls'] ?? 0 ) )
			&& true === ( $one['core_upgrade']['upgraded'] ?? null )
			&& null === ( $one['core_upgrade']['result_code'] ?? null )
			&& '1.0.0' === ( $one['core_upgrade']['version_before'] ?? null )
			&& '2.0.0' === ( $one['core_upgrade']['version_after'] ?? null )
			&& false === ( $one['core_upgrade']['backup_cleaned'] ?? null )
			&& true === ( $one['core_upgrade']['maintenance_file_absent'] ?? null )
			&& true === ( $one['core_upgrade']['offer_token_used'] ?? null )
			&& 1 === ( $one['core_upgrade']['package_handoff_calls'] ?? null )
			&& ( 'automatic' === $policy ) === ( $one['core_upgrade']['cron_context'] ?? null )
			&& ( 'automatic' === $policy ) === ( $one['core_upgrade']['automatic_result_observed'] ?? null )
			&& ( 'manual' !== $policy || 'plugin' !== $type || true === ( $one['core_upgrade']['manual_plugin_was_deactivated'] ?? null ) )
			&& ( 'plugin' !== $type || 'automatic' !== $policy || true === ( $one['core_upgrade']['automatic_plugin_was_active'] ?? null ) )
			&& '2.0.0' === ( $one['post_shutdown']['version'] ?? null )
			&& 13 + ( 'automatic' === $policy && 'plugin' === $type ? 1 : 0 ) === ( $http['allowed'] ?? null )
			&& 13 === ( $http['credentialed'] ?? null )
			&& 2 === ( $http['asset_writes'] ?? null )
			&& ( 'automatic' === $policy && 'plugin' === $type ? 1 : 0 ) === ( $http['loopback'] ?? null );
		$preofferFailureProof = $preofferFailure
			&& false === ( $one['sanity']['offer_hook_fired'] ?? null )
			&& 0 === ( $one['automatic_vcs_checkout_override']['calls'] ?? null )
			&& $phaseMode === ( $one['core_upgrade']['failure_stage'] ?? null )
			&& '2.0.0' === ( $one['core_upgrade']['version_before'] ?? null )
			&& '2.0.0' === ( $one['core_upgrade']['version_after'] ?? null )
			&& false === ( $one['core_upgrade']['offer_token_used'] ?? null )
			&& 0 === ( $one['core_upgrade']['package_handoff_calls'] ?? null )
			&& false === ( $one['core_upgrade']['automatic_result_observed'] ?? null )
			&& ( $one['core_upgrade']['manifest_before'] ?? null ) === ( $one['post_shutdown']['manifest'] ?? null )
			&& '2.0.0' === ( $one['post_shutdown']['version'] ?? null )
			&& ( 'download' !== $phaseMode || ( 1 === ( $http['injected_download'] ?? null ) && 5 === ( $http['allowed'] ?? null ) && 6 === ( $http['credentialed'] ?? null ) && 0 === ( $http['asset_writes'] ?? null ) ) )
			&& ( 'validation' !== $phaseMode || ( 'archive_update_uri_mismatch' === ( $one['core_upgrade']['result_code'] ?? null ) && 7 === ( $http['allowed'] ?? null ) && 7 === ( $http['credentialed'] ?? null ) && 1 === ( $http['asset_writes'] ?? null ) ) );
		$coreFailureProof = 'install' === $phaseMode
			&& true === ( $one['sanity']['offer_hook_fired'] ?? null )
			&& ( 'automatic' !== $policy || 0 < ( $one['automatic_vcs_checkout_override']['calls'] ?? 0 ) )
			&& true === ( $one['core_upgrade']['failed'] ?? null )
			&& $phaseMode === ( $one['core_upgrade']['failure_stage'] ?? null )
			&& '2.0.0' === ( $one['core_upgrade']['version_before'] ?? null )
			&& '3.0.0' === ( $one['core_upgrade']['version_after'] ?? null )
			&& ( 'install' !== $phaseMode || 'phase24_injected_post_copy_failure' === ( $one['core_upgrade']['result_code'] ?? null ) )
			&& ( 'install' !== $phaseMode || true === ( $one['core_upgrade']['injected_post_copy']['post_copy_seen'] ?? null ) )
			&& ( 'install' !== $phaseMode || '3.0.0' === ( $one['core_upgrade']['injected_post_copy']['destination_version'] ?? null ) )
			&& ( 'install' !== $phaseMode || true === ( $one['core_upgrade']['injected_post_copy']['backup_present'] ?? null ) )
			&& ( 'install' !== $phaseMode || true === ( $one['core_upgrade']['rollback_backup_path_exists'] ?? null ) )
			&& false === ( $one['core_upgrade']['maintenance_file_exists'] ?? null )
			&& true === ( $one['core_upgrade']['offer_token_used'] ?? null )
			&& 1 === ( $one['core_upgrade']['package_handoff_calls'] ?? null )
			&& ( 'automatic' === $policy ) === ( $one['core_upgrade']['cron_context'] ?? null )
			&& ( 'automatic' === $policy ) === ( $one['core_upgrade']['automatic_result_observed'] ?? null )
			&& ( 'manual' !== $policy || 'plugin' !== $type || true === ( $one['core_upgrade']['manual_plugin_was_deactivated'] ?? null ) )
			&& ( 'plugin' !== $type || 'automatic' !== $policy || true === ( $one['core_upgrade']['automatic_plugin_was_active'] ?? null ) )
			&& '2.0.0' === ( $one['post_shutdown']['version'] ?? null )
			&& ( $successfulDigests[ $policy . ':' . $type ] ?? null ) === ( $one['post_shutdown']['digest'] ?? null )
			&& ( $successfulDigests[ $policy . ':' . $type ] ?? null ) !== ( $one['core_upgrade']['injected_post_copy']['destination_digest'] ?? null )
			&& 13 === ( $http['allowed'] ?? null )
			&& 13 === ( $http['credentialed'] ?? null )
			&& 2 === ( $http['asset_writes'] ?? null )
			&& 0 === ( $http['loopback'] ?? null );
		if ( ! $commonProof || ( ! $successProof && ! $preofferFailureProof && ! $coreFailureProof ) ) {
			throw new RuntimeException( 'Core proof assertion failed for ' . implode( ':', $phase ) . ': ' . substr( json_encode( $one, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ), 0, 12000 ) );
		}
		if ( 'success' === $phaseMode ) {
			$successfulDigests[ $policy . ':' . $type ] = $one['post_shutdown']['digest'] ?? null;
		}
		$phaseOutput[ implode( ':', $phase ) ] = $one;
		unlink( $phaseFile );
	}
	$readback = run_command(
		array(
			$wpCliPhp,
			'-n',
			'-d',
			'sys_temp_dir=' . $phpTempDir,
			$wpCliCmd,
			'--path=' . $sitePath,
			'eval',
			'echo wp_json_encode( array( "plugin_active" => is_plugin_active( "phase24-plugin/phase24-plugin.php" ), "theme_active" => wp_get_theme()->get_stylesheet() === "phase24-theme" ), JSON_PRETTY_PRINT );',
		),
		$sitePath,
		$cliEnv,
		false
	);

	$readbackBody = json_decode( trim( $readback['stdout'] ), true, 32, JSON_THROW_ON_ERROR );
	if ( 0 !== $readback['code'] || true !== ( $readbackBody['plugin_active'] ?? null ) || true !== ( $readbackBody['theme_active'] ?? null ) ) {
		throw new RuntimeException( 'Separate WP-CLI activation readback failed: ' . substr( json_encode( array( 'code' => $readback['code'], 'body' => $readbackBody, 'stderr' => trim( $readback['stderr'] ) ), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ), 0, 4000 ) );
	}
	$result['status'] = 'pass';
	$result['hook_probe'] = $phaseOutput;
	$result['cli_readback'] = array(
		'code' => $readback['code'],
		'body' => trim( $readback['stdout'] ),
	);
	$result['cleanup'] = array(
		'output_file_exists' => is_file( $hookFile ),
		'mySql_server_pid' => is_resource( $server ),
		'mySql_mode' => $mode,
	);
} finally {
	if ( is_resource( $server ) ) {
		proc_terminate( $server, 15 );
		for ( $attempt = 0; $attempt < 100; ++$attempt ) {
			$status = proc_get_status( $server );
			if ( ! $status['running'] ) {
				proc_close( $server );
				$server = null;
				break;
			}
			usleep( 10000 );
		}
		if ( is_resource( $server ) ) {
			proc_terminate( $server, 9 );
			proc_close( $server );
		}
	}

	if ( isset( $port, $dbName ) ) {
		try {
			$cleanup = mysqli_init();
			if ( @mysqli_real_connect( $cleanup, '127.0.0.1', $dbUser, $dbPassword, null, $port ) ) {
				$cleanup->query( 'DROP DATABASE IF EXISTS `' . $cleanup->real_escape_string( $dbName ) . '`' );
				$cleanup->close();
			}
		} catch ( mysqli_sql_exception ) {
			// The isolated server may fail before it has created its socket.
		}
	}

	if ( is_file( $hookFile ) ) {
		unlink( $hookFile );
	}
	if ( is_link( $base ) || $base !== realpath( $base ) || dirname( $base ) !== $workspace || ! is_file( $markerFile ) || is_link( $markerFile ) || $marker . "\n" !== file_get_contents( $markerFile ) ) {
		throw new RuntimeException( 'Refusing cleanup because disposable-root ownership cannot be revalidated.' );
	}
	removeTree( $base );
	if ( file_exists( $base ) || is_link( $base ) ) {
		throw new RuntimeException( 'Disposable proof root was not fully removed.' );
	}
}

echo json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL;

function run_command( array $command, string $cwd, ?array $env = null, bool $requireZero = true, string $stdin = '' ): array {
	$process = proc_open(
		$command,
		array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		),
		$pipes,
		$cwd,
		$env
	);
	if ( ! is_resource( $process ) ) {
		throw new RuntimeException( 'Could not run command: ' . implode( ' ', $command ) );
	}
	if ( '' !== $stdin && false === fwrite( $pipes[0], $stdin ) ) {
		fclose( $pipes[0] );
		proc_terminate( $process );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		proc_close( $process );
		throw new RuntimeException( 'Could not provide command input.' );
	}
	fclose( $pipes[0] );
	$stdout = stream_get_contents( $pipes[1] );
	$stderr = stream_get_contents( $pipes[2] );
	fclose( $pipes[1] );
	fclose( $pipes[2] );
	$exit = proc_close( $process );
	if ( $requireZero && 0 !== $exit ) {
		throw new RuntimeException( 'Command failed: ' . implode( ' ', $command ) . ' (' . $exit . ')' . substr( trim( $stdout . "\n" . $stderr ), 0, 8000 ) );
	}
	return array( 'code' => $exit, 'stdout' => $stdout, 'stderr' => $stderr );
}

function reserveLoopbackPort(): int {
	$listener = stream_socket_server( 'tcp://127.0.0.1:0', $errorNumber, $errorMessage );
	if ( false === $listener ) {
		throw new RuntimeException( 'Could not reserve an isolated loopback port: ' . $errorMessage . ' (' . $errorNumber . ')' );
	}
	$address = stream_socket_get_name( $listener, false );
	fclose( $listener );
	$separator = is_string( $address ) ? strrpos( $address, ':' ) : false;
	$port = false === $separator ? 0 : (int) substr( $address, $separator + 1 );
	if ( $port < 1 ) {
		throw new RuntimeException( 'Could not determine an isolated loopback port.' );
	}
	return $port;
}

function attest_server( mixed $server, int $port, string $dataDirectory, string $user, string $password ): mysqli {
	$status = is_resource( $server ) ? proc_get_status( $server ) : false;
	if ( ! is_array( $status ) || ! $status['running'] ) {
		throw new RuntimeException( 'Isolated MySQL stopped before attestation.' );
	}
	$mysqli = connect( $port, $user, $password );
	try {
		$result = $mysqli->query( 'SELECT @@datadir AS datadir' );
		$row = $result->fetch_assoc();
		$expected = realpath( $dataDirectory );
		$actual = is_array( $row ) && isset( $row['datadir'] ) ? realpath( (string) $row['datadir'] ) : false;
		if ( false === $expected || false === $actual || rtrim( $expected, '/\\' ) !== rtrim( $actual, '/\\' ) ) {
			throw new RuntimeException( 'Loopback MySQL datadir did not match the isolated fixture.' );
		}
		return $mysqli;
	} catch ( Throwable $error ) {
		$mysqli->close();
		throw $error;
	}
}

function connect( int $port, string $user, string $password ): mysqli {
	$attempts = 0;
	while ( $attempts < 120 ) {
		$mysqli = mysqli_init();
		try {
			$connected = is_object( $mysqli ) && mysqli_real_connect( $mysqli, '127.0.0.1', $user, $password, null, $port );
			if ( $connected ) {
				return $mysqli;
			}
		} catch ( mysqli_sql_exception ) {
		}
		if ( is_object( $mysqli ) ) {
			$mysqli->close();
		}
		$attempts++;
		usleep( 25000 );
	}
	throw new RuntimeException( 'Could not connect to MySQL socket.' );
}

function copy_tree( string $source, string $destination, array $exclude = array() ): void {
	$source = rtrim( $source, '/\\' );
	$destination = rtrim( $destination, '/\\' );
	if ( ! is_dir( $source ) ) {
		throw new RuntimeException( 'Source path does not exist for copy_tree: ' . $source );
	}
	if ( ! mkdir( $destination, 0700, true ) && ! is_dir( $destination ) ) {
		throw new RuntimeException( 'Could not create tree copy destination.' );
	}
	$forbidden = array_flip( array_merge( $exclude, array( 'node_modules', 'vendor', '.git' ) ) );
	$iterator = new RecursiveIteratorIterator(
		new RecursiveCallbackFilterIterator(
			new RecursiveDirectoryIterator( $source, FilesystemIterator::SKIP_DOTS ),
			static function ( SplFileInfo $current ) use ( $source, $forbidden ): bool {
				$path = ltrim( str_replace( $source . DIRECTORY_SEPARATOR, '', $current->getPathname() ), '/\\' );
				if ( '' === $path ) {
					return true;
				}
				$segments = explode( DIRECTORY_SEPARATOR, $path );
				return 0 === count( array_intersect( $segments, array_keys( $forbidden ) ) );
			}
		),
		RecursiveIteratorIterator::SELF_FIRST
	);
	foreach ( $iterator as $entry ) {
		$relative = substr( $entry->getPathname(), strlen( $source ) + 1 );
		$segments = explode( DIRECTORY_SEPARATOR, $relative );
		if ( $entry->isLink() ) {
			continue;
		}
		$target = $destination . '/' . $relative;
		if ( $entry->isDir() ) {
			mkdir( $target, 0700, true );
		} else {
			if ( ! is_readable( $entry->getPathname() ) ) {
				continue;
			}
			if ( ! is_dir( dirname( $target ) ) ) {
				mkdir( dirname( $target ), 0700, true );
			}
			if ( false === @copy( $entry->getPathname(), $target ) ) {
				throw new RuntimeException( 'Could not copy file: ' . $entry->getPathname() );
			}
		}
	}
}

function create_fixture_plugin( string $site, string $identity, string $uri ): void {
	$root = $site . '/wp-content/plugins/' . $identity;
	if ( ! is_dir( $root ) && ! mkdir( $root, 0700, true ) ) {
		throw new RuntimeException( 'Could not create fixture plugin directory.' );
	}
	file_put_contents( $root . '/' . $identity . '.php', fixture_plugin_source( $uri, '1.0.0' ) );
}

function create_fixture_theme( string $site, string $identity, string $uri ): void {
	$root = $site . '/wp-content/themes/' . $identity;
	if ( ! is_dir( $root ) && ! mkdir( $root, 0700, true ) ) {
		throw new RuntimeException( 'Could not create fixture theme directory.' );
	}
	file_put_contents( $root . '/style.css', fixture_theme_stylesheet( $uri, '1.0.0' ) );
	file_put_contents( $root . '/index.php', "<?php\n// Phase24 disposable theme fixture.\n" );
	if ( 'phase24-theme' === $identity ) {
		file_put_contents( $root . '/functions.php', fixture_theme_registration_source() );
	}
}

function create_manager_plugin( string $site, string $theme, string $uri ): void {
	$root = $site . '/wp-content/plugins/phase24-manager';
	if ( ! is_dir( $root ) && ! mkdir( $root, 0700, true ) ) {
		throw new RuntimeException( 'Could not create manager plugin directory.' );
	}
	file_put_contents( $root . '/phase24-manager.php', "<?php\n/*\nPlugin Name: Phase24 Manager\nVersion: 1.0.0\n*/\n\$phase24 = require dirname( __DIR__ ) . '/ran-wp-release-updater/bootstrap.php';\n\$phase24Handle = \$phase24->theme( 'github', WP_CONTENT_DIR . '/themes/{$theme}/style.css', 'phase24-owner/phase24-manager-theme', '102', 'stable', 'disabled' );\n\$phase24Handle->register();\n\$GLOBALS['phase24_handles']['manager'] = \$phase24Handle;\n" );
}

function make_fixture_archive( string $site, string $identity, string $uri, string $archiveTag, string $version, bool $isTheme ): string {
	$zipPath = $site . '/wp-content/uploads/' . $archiveTag . '-' . $identity . '.zip';
	$zip = new ZipArchive();
	if ( true !== $zip->open( $zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
		throw new RuntimeException( 'Could not create fixture archive.' );
	}
	$archiveRoot = $identity . '/';
	$zip->addFromString( $archiveRoot . ( $isTheme ? 'style.css' : $identity . '.php' ), $isTheme ? fixture_theme_stylesheet( $uri, $version ) : fixture_plugin_source( $uri, $version ) );
	if ( $isTheme ) {
		$zip->addFromString( $archiveRoot . 'index.php', "<?php\n// Phase24 disposable theme fixture.\n" );
		$zip->addFromString( $archiveRoot . 'functions.php', fixture_theme_registration_source() );
	}
	$zip->addFromString( $archiveRoot . 'readme.txt', "Phase24 fixture archive {$archiveTag}\n" );
	$zip->close();
	return $zipPath;
}

function fixture_plugin_source( string $uri, string $version ): string {
	return "<?php\n/*\nPlugin Name: Phase24 Plugin\nVersion: {$version}\nUpdate URI: {$uri}\nRequires PHP: 8.2\nRequires at least: 6.8\n*/\n// phase24-plugin-v{$version}\n\$phase24 = require dirname( __DIR__ ) . '/ran-wp-release-updater/bootstrap.php';\n\$phase24Handle = \$phase24->plugin( 'github', __FILE__, 'phase24-owner/phase24-plugin', '101', 'stable', getenv( 'RAN_WP_RELEASE_UPDATER_POLICY' ) ?: 'manual', static fn (): string => 'phase24-token' );\n\$phase24Handle->register();\n\$GLOBALS['phase24_handles']['plugin'] = \$phase24Handle;\n";
}

function fixture_theme_stylesheet( string $uri, string $version ): string {
	return "/*\nTheme Name: Phase24 Theme\nVersion: {$version}\nUpdate URI: {$uri}\nRequires PHP: 8.2\nRequires at least: 6.8\n*/\n";
}

function fixture_theme_registration_source(): string {
	return "<?php\n\$phase24 = require dirname( __DIR__, 2 ) . '/plugins/ran-wp-release-updater/bootstrap.php';\n\$phase24Handle = \$phase24->theme( 'github', __DIR__ . '/style.css', 'phase24-owner/phase24-theme', '101', 'stable', getenv( 'RAN_WP_RELEASE_UPDATER_POLICY' ) ?: 'manual', static fn (): string => 'phase24-token' );\n\$phase24Handle->register();\n\$GLOBALS['phase24_handles']['theme'] = \$phase24Handle;\n";
}

function removeTree( string $path ): void {
	if ( ! is_dir( $path ) ) {
		return;
	}
	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $iterator as $entry ) {
		is_dir( $entry->getPathname() ) ? rmdir( $entry->getPathname() ) : unlink( $entry->getPathname() );
	}
	rmdir( $path );
}
