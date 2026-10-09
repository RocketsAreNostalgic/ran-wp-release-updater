<?php

declare( strict_types = 1 );

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$plugin_root = realpath( __DIR__ . '/../../' );
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$plugin_root = $plugin_root ? $plugin_root : __DIR__ . '/../../';
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$wp_root_input = getenv( 'RAN_WP_RELEASE_UPDATER_LOCAL_WP_ROOT' );
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$wp_root = is_string( $wp_root_input ) && '' !== $wp_root_input ? realpath( $wp_root_input ) : false;

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$marker = 'RAN_WP_RELEASE_UPDATER_PHASE24';
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$workspace_input = getenv( 'RAN_WP_RELEASE_UPDATER_PHASE24_WORKSPACE' );
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$workspace = is_string( $workspace_input ) && '' !== $workspace_input
	? realpath( $workspace_input )
	: $plugin_root . '/.workspaces/p0.4';
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$base = rtrim( (string) $workspace, '/\\' ) . '/' . strtolower( $marker ) . '-' . bin2hex( random_bytes( 16 ) );
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$db_name = 'ran_updater_phase24_' . random_int( 100000, 999999 );
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$site_path = $base . '/site';
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$hook_file = $base . '/phase24-harness-output-' . $db_name . '.json';
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$marker_file = $base . '/RAN_WP_RELEASE_UPDATER_PHASE24.marker';
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$wp_cli_cmd = '/usr/local/bin/wp';
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$php82_candidates = glob( '/Applications/Local.app/Contents/Resources/extraResources/lightning-services/php-8.2*/bin/darwin-arm64/bin/php' );
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$wp_cli_php = getenv( 'RAN_WP_RELEASE_UPDATER_PHP82' );
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$wp_cli_php = $wp_cli_php ? $wp_cli_php : ( is_array( $php82_candidates ) && isset( $php82_candidates[0] ) ? $php82_candidates[0] : '' );
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$server = null;
// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- This CLI/eval-file fixture variable is local scenario/process state, not a WordPress request global override.
$mode = 'isolated_mysql_server';
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$db_user = getenv( 'RAN_WP_RELEASE_UPDATER_DB_USER' );
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$db_user = $db_user ? $db_user : 'root';
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$db_pass_raw = getenv( 'RAN_WP_RELEASE_UPDATER_DB_PASSWORD' );
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$db_password = false === $db_pass_raw ? '' : $db_pass_raw;

if ( false === $workspace || ! is_dir( $workspace ) || file_exists( $base ) || is_link( $base ) || dirname( $base ) !== $workspace ) {
	throw new RuntimeException( 'Refusing to run harness outside its durable workspace.' );
}
if (
	false === $wp_root
	|| ! is_file( $wp_root . '/wp-load.php' )
	|| ! is_file( $wp_root . '/wp-settings.php' )
	|| ! is_file( $wp_root . '/wp-includes/version.php' )
) {
	throw new RuntimeException( 'Could not resolve a safe local WordPress root.' );
}
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$php_temp_dir = $workspace . '/php-tmp';
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create real directories for the disposable integration fixture with the specified permissions.
if ( ! is_dir( $php_temp_dir ) && ! mkdir( $php_temp_dir, 0700, true ) ) {
	throw new RuntimeException( 'Could not prepare the PHP temporary directory.' );
}
// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- This standalone proof configures temporary paths and its marker for descendant processes.
putenv( 'TMPDIR=' . $php_temp_dir );
// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- This standalone proof configures temporary paths and its marker for descendant processes.
putenv( 'TMP=' . $php_temp_dir );
// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- This standalone proof configures temporary paths and its marker for descendant processes.
putenv( 'TEMP=' . $php_temp_dir );

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$mysqld = getenv( 'RAN_UPDATER_MYSQLD_BIN' );
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$mysqld = $mysqld ? $mysqld : '/Applications/Local.app/Contents/Resources/extraResources/lightning-services/mysql-8.4.0/bin/darwin-arm64/bin/mysqld';
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$mysql_dir = $base . '/mysql';
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$socket = 'mysql.sock';
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$pid_file = 'mysqld.pid';
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$port = ran_wp_release_updater_test_reserve_loopback_port();

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$result = array(
	'marker'          => $marker,
	'status'          => 'errored',
	'disposable_root' => $base,
	'database_mode'   => null,
	'source_root'     => $plugin_root,
);

// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- This standalone proof configures temporary paths and its marker for descendant processes.
putenv( 'RAN_WP_RELEASE_UPDATER_PHASE24=' . $marker );
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create real directories for the disposable integration fixture with the specified permissions.
if ( ! mkdir( $base, 0700, false ) ) {
	throw new RuntimeException( 'Could not prepare isolated proof root.' );
}
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write exact bytes for the disposable integration fixture; WordPress helpers would alter the boundary under test.
if ( is_link( $base ) || realpath( $base ) !== $base || dirname( $base ) !== $workspace || false === file_put_contents( $marker_file, $marker . "\n" ) ) { // @phpstan-ignore booleanOr.leftAlwaysFalse (Retain the independent filesystem ownership recheck before destructive fixture cleanup.)
	throw new RuntimeException( 'Could not establish a real disposable proof root and marker.' );
}

// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create real directories for the disposable integration fixture with the specified permissions.
if ( ! is_dir( $base . '/mysql' ) && ! mkdir( $base . '/mysql', 0700, true ) ) {
	throw new RuntimeException( 'Could not prepare isolated MySQL working directory.' );
}

try {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
	$mysql_data_dir = $base . '/mysql/data';
	if ( ! is_file( $mysqld ) || ! is_executable( $mysqld ) ) {
		throw new RuntimeException( 'The isolated Local mysqld binary is unavailable.' );
	}
	ran_wp_release_updater_test_run_command( array( $mysqld, '--no-defaults', '--initialize-insecure', '--datadir=' . $mysql_data_dir, '--socket=' . $socket, '--tmpdir=' . $mysql_dir, '--skip-mysqlx' ), $mysql_dir, null, true );
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Start the dedicated mysqld child with explicit argv and fixture paths; the harness owns its shutdown. Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
	$server = proc_open(
		array( $mysqld, '--no-defaults', '--datadir=' . $mysql_data_dir, '--bind-address=127.0.0.1', '--port=' . $port, '--socket=' . $socket, '--pid-file=' . $pid_file, '--tmpdir=' . $mysql_dir, '--skip-mysqlx', '--log-error=' . $mysql_dir . '/mysqld.err' ),
		array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'file', $mysql_dir . '/mysqld.out', 'a' ),
			2 => array( 'file', $mysql_dir . '/mysqld.err', 'a' ),
		),
		$server_pipes,
		$mysql_dir
	);
	if ( ! is_resource( $server ) ) {
		throw new RuntimeException( 'Could not launch isolated mysqld.' );
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the native stream owned by this fixture; WordPress filesystem abstractions do not own process or file handles.
	fclose( $server_pipes[0] );
	// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- This CLI/eval-file fixture variable is local scenario/process state, not a WordPress request global override.
	$mode = 'isolated_mysql_server';

	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
	$result['database_mode'] = $mode;
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
	$mysqli = ran_wp_release_updater_test_attest_server( $server, $port, $mysql_data_dir, $db_user, $db_password );
	$mysqli->query( 'CREATE DATABASE IF NOT EXISTS `' . $mysqli->real_escape_string( $db_name ) . '`' );
	$mysqli->close();

	ran_wp_release_updater_test_copy_tree( $wp_root, $site_path, array( '.git', 'wp-content', 'wp-config.php', '.well-known' ) );
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
	foreach ( array( $site_path . '/wp-content/plugins', $site_path . '/wp-content/themes', $site_path . '/wp-content/uploads' ) as $directory ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create real directories for the disposable integration fixture with the specified permissions.
		if ( ! is_dir( $directory ) && ! mkdir( $directory, 0700, true ) ) {
			throw new RuntimeException( 'Could not create disposable wp-content directory.' );
		}
	}
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
	$copied_updater = $site_path . '/wp-content/plugins/ran-wp-release-updater';
	ran_wp_release_updater_test_copy_tree( $plugin_root, $copied_updater, array( 'tests', '.git', '.github', '.phpunit.cache', '.workspaces', 'node_modules', 'vendor' ) );
	if ( is_dir( $copied_updater . '/.phpunit.cache' ) || is_dir( $copied_updater . '/.workspaces' ) ) {
		throw new RuntimeException( 'Disposable updater copy includes a private work or cache root.' );
	}

	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
	$plugin_uri = 'https://github.com/phase24-owner/phase24-plugin';
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
	$theme_uri = 'https://github.com/phase24-owner/phase24-theme';
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
	$manager_theme_uri = 'https://github.com/phase24-owner/phase24-manager-theme';
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
	$plugin_archive = ran_wp_release_updater_test_make_fixture_archive( $site_path, 'phase24-plugin', $plugin_uri, 'plugin', '2.0.0', false );
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
	$theme_archive = ran_wp_release_updater_test_make_fixture_archive( $site_path, 'phase24-theme', $theme_uri, 'theme', '2.0.0', true );

	ran_wp_release_updater_test_create_fixture_plugin( $site_path, 'phase24-plugin', $plugin_uri );
	ran_wp_release_updater_test_create_fixture_theme( $site_path, 'phase24-theme', $theme_uri );
	ran_wp_release_updater_test_create_fixture_theme( $site_path, 'phase24-manager-theme', $manager_theme_uri );
	ran_wp_release_updater_test_create_manager_plugin( $site_path, 'phase24-manager-theme' );

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write exact bytes for the disposable integration fixture; WordPress helpers would alter the boundary under test.
	file_put_contents(
		$site_path . '/wp-config.php',
		"<?php\n" .
		"define( 'DB_NAME', '{$db_name}' );\n" .
		"define( 'DB_USER', '{$db_user}' );\n" .
		"define( 'DB_PASSWORD', '{$db_password}' );\n" .
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

	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
	$cli_env = array(
		'DB_HOST'     => '127.0.0.1:' . $port,
		'DB_USER'     => $db_user,
		'DB_PASSWORD' => $db_password,
		'TMPDIR'      => $php_temp_dir,
		'TMP'         => $php_temp_dir,
		'TEMP'        => $php_temp_dir,
	);

	if ( ! is_file( $wp_cli_cmd ) || ! is_file( $wp_cli_php ) || ! is_executable( $wp_cli_php ) ) {
		throw new RuntimeException( 'Required local WP-CLI or PHP 8.2 runtime is unavailable.' );
	}
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
	$admin_password = bin2hex( random_bytes( 32 ) );
	ran_wp_release_updater_test_run_command( array( $wp_cli_php, '-n', '-d', 'sys_temp_dir=' . $php_temp_dir, $wp_cli_cmd, '--path=' . $site_path, 'core', 'install', '--skip-email', '--url=http://127.0.0.1', '--title=phase24', '--admin_user=admin', '--prompt=admin_password', '--admin_email=admin@example.test' ), $site_path, $cli_env, true, $admin_password . "\n" );
	ran_wp_release_updater_test_run_command( array( $wp_cli_php, '-n', '-d', 'sys_temp_dir=' . $php_temp_dir, $wp_cli_cmd, '--path=' . $site_path, 'plugin', 'activate', 'phase24-plugin' ), $site_path, $cli_env, true );
	ran_wp_release_updater_test_run_command( array( $wp_cli_php, '-n', '-d', 'sys_temp_dir=' . $php_temp_dir, $wp_cli_cmd, '--path=' . $site_path, 'plugin', 'activate', 'phase24-manager' ), $site_path, $cli_env, true );
	ran_wp_release_updater_test_run_command( array( $wp_cli_php, '-n', '-d', 'sys_temp_dir=' . $php_temp_dir, $wp_cli_cmd, '--path=' . $site_path, 'theme', 'activate', 'phase24-theme' ), $site_path, $cli_env, true );

	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
	$probe_env = array(
		'RAN_WP_RELEASE_UPDATER_PHASE24'                   => $marker,
		'RAN_WP_RELEASE_UPDATER_SOURCE_ROOT'               => $site_path . '/wp-content/plugins/ran-wp-release-updater',
		'RAN_WP_RELEASE_UPDATER_PLUGIN_ID'                 => 'phase24-plugin/phase24-plugin.php',
		'RAN_WP_RELEASE_UPDATER_THEME_ID'                  => 'phase24-theme',
		'RAN_WP_RELEASE_UPDATER_MANAGER_THEME_ID'          => 'phase24-manager-theme',
		'RAN_WP_RELEASE_UPDATER_PLUGIN_URI'                => $plugin_uri,
		'RAN_WP_RELEASE_UPDATER_THEME_URI'                 => $theme_uri,
		'RAN_WP_RELEASE_UPDATER_MANAGER_THEME_URI'         => $manager_theme_uri,
		'RAN_WP_RELEASE_UPDATER_PLUGIN_ARCHIVE'            => $plugin_archive,
		'RAN_WP_RELEASE_UPDATER_THEME_ARCHIVE'             => $theme_archive,
		'RAN_WP_RELEASE_UPDATER_PLUGIN_FAILURE_ARCHIVE'    => ran_wp_release_updater_test_make_fixture_archive( $site_path, 'phase24-plugin', $plugin_uri, 'plugin-failure', '3.0.0', false ),
		'RAN_WP_RELEASE_UPDATER_THEME_FAILURE_ARCHIVE'     => ran_wp_release_updater_test_make_fixture_archive( $site_path, 'phase24-theme', $theme_uri, 'theme-failure', '3.0.0', true ),
		'RAN_WP_RELEASE_UPDATER_PLUGIN_VALIDATION_ARCHIVE' => ran_wp_release_updater_test_make_fixture_archive( $site_path, 'phase24-plugin', $plugin_uri . '-wrong', 'plugin-validation', '3.0.0', false ),
		'RAN_WP_RELEASE_UPDATER_THEME_VALIDATION_ARCHIVE'  => ran_wp_release_updater_test_make_fixture_archive( $site_path, 'phase24-theme', $theme_uri . '-wrong', 'theme-validation', '3.0.0', true ),
		'RAN_WP_RELEASE_UPDATER_OUTPUT'                    => $hook_file,
		'RAN_WP_RELEASE_UPDATER_MARKER_FILE'               => $marker_file,
	);

	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
	$phase_output = array();
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
	$successful_digests = array();
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
	foreach ( array( 'manual', 'automatic' ) as $policy ) {
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- This CLI/eval-file fixture variable is local scenario/process state, not a WordPress request global override.
		foreach ( array( 'plugin', 'theme' ) as $type ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
			foreach ( array( 'success', 'download', 'validation', 'install' ) as $phase_mode ) {
						// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
						$phase = array( $policy, $phase_mode, $type );
				if ( 'success' === $phase_mode ) {
					if ( 'plugin' === $type ) {
						ran_wp_release_updater_test_create_fixture_plugin( $site_path, 'phase24-plugin', $plugin_uri );
					} else {
						ran_wp_release_updater_test_create_fixture_theme( $site_path, 'phase24-theme', $theme_uri );
					}
				}
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
				$phase_file = $base . '/phase24-' . implode( '-', $phase ) . '.json';
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
				$phase_env = $probe_env;
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
				$phase_env['RAN_WP_RELEASE_UPDATER_MODE'] = $phase_mode;
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
				$phase_env['RAN_WP_RELEASE_UPDATER_TARGET_TYPE'] = $type;
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
				$phase_env['RAN_WP_RELEASE_UPDATER_POLICY'] = $policy;
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
				$phase_env['RAN_WP_RELEASE_UPDATER_ARCHIVE'] = 'plugin' === $type ? ( 'success' === $phase_mode ? $plugin_archive : ( 'validation' === $phase_mode ? $phase_env['RAN_WP_RELEASE_UPDATER_PLUGIN_VALIDATION_ARCHIVE'] : $phase_env['RAN_WP_RELEASE_UPDATER_PLUGIN_FAILURE_ARCHIVE'] ) ) : ( 'success' === $phase_mode ? $theme_archive : ( 'validation' === $phase_mode ? $phase_env['RAN_WP_RELEASE_UPDATER_THEME_VALIDATION_ARCHIVE'] : $phase_env['RAN_WP_RELEASE_UPDATER_THEME_FAILURE_ARCHIVE'] ) );
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
				$phase_env['RAN_WP_RELEASE_UPDATER_DOING_CRON'] = 'automatic' === $policy ? '1' : '0';
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
				$phase_env['RAN_WP_RELEASE_UPDATER_FAILURE_STAGE'] = $phase_mode;
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
				$phase_env['RAN_WP_RELEASE_UPDATER_OUTPUT'] = $phase_file;
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
				$phase_env['TMPDIR'] = $php_temp_dir;
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
				$phase_env['TMP'] = $php_temp_dir;
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
				$phase_env['TEMP'] = $php_temp_dir;
				ran_wp_release_updater_test_run_command( array( $wp_cli_php, '-n', '-d', 'sys_temp_dir=' . $php_temp_dir, $wp_cli_cmd, '--path=' . $site_path, 'eval-file', $plugin_root . '/tests/Integration/phase-2.4-wordpress-core-proof-harness.php' ), $site_path, $phase_env, true );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Read exact local bytes for the disposable integration fixture without requiring WordPress filesystem initialization. Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
				$one = json_decode( (string) file_get_contents( $phase_file ), true, 64, JSON_THROW_ON_ERROR );
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
				$database = is_array( $one ) ? ( $one['post_shutdown']['database'] ?? null ) : null;
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
				$preoffer_failure = in_array( $phase_mode, array( 'download', 'validation' ), true );
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
				$http = is_array( $one ) ? ( $one['post_shutdown']['http'] ?? null ) : null;
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
				$common_proof = is_array( $one )
				&& ( $one['sourceRoot'] ?? null ) === $site_path . '/wp-content/plugins/ran-wp-release-updater'
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
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
				$success_proof = 'success' === $phase_mode
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
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
				$preoffer_failure_proof = $preoffer_failure
				&& false === ( $one['sanity']['offer_hook_fired'] ?? null )
				&& 0 === ( $one['automatic_vcs_checkout_override']['calls'] ?? null )
				&& ( $one['core_upgrade']['failure_stage'] ?? null ) === $phase_mode
				&& '2.0.0' === ( $one['core_upgrade']['version_before'] ?? null )
				&& '2.0.0' === ( $one['core_upgrade']['version_after'] ?? null )
				&& false === ( $one['core_upgrade']['offer_token_used'] ?? null )
				&& 0 === ( $one['core_upgrade']['package_handoff_calls'] ?? null )
				&& false === ( $one['core_upgrade']['automatic_result_observed'] ?? null )
				&& ( $one['core_upgrade']['manifest_before'] ?? null ) === ( $one['post_shutdown']['manifest'] ?? null )
				&& '2.0.0' === ( $one['post_shutdown']['version'] ?? null )
				&& ( 'download' !== $phase_mode || ( 1 === ( $http['injected_download'] ?? null ) && 5 === ( $http['allowed'] ?? null ) && 6 === ( $http['credentialed'] ?? null ) && 0 === ( $http['asset_writes'] ?? null ) ) )
				&& ( 'validation' !== $phase_mode || ( 'archive_update_uri_mismatch' === ( $one['core_upgrade']['result_code'] ?? null ) && 7 === ( $http['allowed'] ?? null ) && 7 === ( $http['credentialed'] ?? null ) && 1 === ( $http['asset_writes'] ?? null ) ) );
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
				$core_failure_proof = 'install' === $phase_mode
				&& true === ( $one['sanity']['offer_hook_fired'] ?? null )
				&& ( 'automatic' !== $policy || 0 < ( $one['automatic_vcs_checkout_override']['calls'] ?? 0 ) )
				&& true === ( $one['core_upgrade']['failed'] ?? null )
				&& ( $one['core_upgrade']['failure_stage'] ?? null ) === $phase_mode
				&& '2.0.0' === ( $one['core_upgrade']['version_before'] ?? null )
				&& '3.0.0' === ( $one['core_upgrade']['version_after'] ?? null )
				&& 'phase24_injected_post_copy_failure' === ( $one['core_upgrade']['result_code'] ?? null )
				&& true === ( $one['core_upgrade']['injected_post_copy']['post_copy_seen'] ?? null )
				&& '3.0.0' === ( $one['core_upgrade']['injected_post_copy']['destination_version'] ?? null )
				&& true === ( $one['core_upgrade']['injected_post_copy']['backup_present'] ?? null )
				&& true === ( $one['core_upgrade']['rollback_backup_path_exists'] ?? null )
				&& false === ( $one['core_upgrade']['maintenance_file_exists'] ?? null )
				&& true === ( $one['core_upgrade']['offer_token_used'] ?? null )
				&& 1 === ( $one['core_upgrade']['package_handoff_calls'] ?? null )
				&& ( 'automatic' === $policy ) === ( $one['core_upgrade']['cron_context'] ?? null )
				&& ( 'automatic' === $policy ) === ( $one['core_upgrade']['automatic_result_observed'] ?? null )
				&& ( 'manual' !== $policy || 'plugin' !== $type || true === ( $one['core_upgrade']['manual_plugin_was_deactivated'] ?? null ) )
				&& ( 'plugin' !== $type || 'automatic' !== $policy || true === ( $one['core_upgrade']['automatic_plugin_was_active'] ?? null ) )
				&& '2.0.0' === ( $one['post_shutdown']['version'] ?? null )
				&& ( $successful_digests[ $policy . ':' . $type ] ?? null ) === ( $one['post_shutdown']['digest'] ?? null )
				&& ( $successful_digests[ $policy . ':' . $type ] ?? null ) !== ( $one['core_upgrade']['injected_post_copy']['destination_digest'] ?? null )
				&& 13 === ( $http['allowed'] ?? null )
				&& 13 === ( $http['credentialed'] ?? null )
				&& 2 === ( $http['asset_writes'] ?? null )
				&& 0 === ( $http['loopback'] ?? null );
				if ( ! $common_proof || ( ! $success_proof && ! $preoffer_failure_proof && ! $core_failure_proof ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Native JSON preserves protocol or fixture bytes without requiring WordPress helpers or their fallback behavior.
					throw new RuntimeException( 'Core proof assertion failed for ' . implode( ':', $phase ) . ': ' . substr( json_encode( $one, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ), 0, 12000 ) );
				}
				if ( 'success' === $phase_mode ) {
					// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
					$successful_digests[ $policy . ':' . $type ] = $one['post_shutdown']['digest'] ?? null;
				}
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
				$phase_output[ implode( ':', $phase ) ] = $one;
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove native fixture entries directly, preserving the surrounding ownership and link-handling checks.
				unlink( $phase_file );
			}
		}
	}
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
	$readback = ran_wp_release_updater_test_run_command(
		array(
			$wp_cli_php,
			'-n',
			'-d',
			'sys_temp_dir=' . $php_temp_dir,
			$wp_cli_cmd,
			'--path=' . $site_path,
			'eval',
			'echo wp_json_encode( array( "plugin_active" => is_plugin_active( "phase24-plugin/phase24-plugin.php" ), "theme_active" => wp_get_theme()->get_stylesheet() === "phase24-theme" ), JSON_PRETTY_PRINT );',
		),
		$site_path,
		$cli_env,
		false
	);

	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
	$readback_body = json_decode( trim( $readback['stdout'] ), true, 32, JSON_THROW_ON_ERROR );
	if ( 0 !== $readback['code'] || true !== ( $readback_body['plugin_active'] ?? null ) || true !== ( $readback_body['theme_active'] ?? null ) ) {
		throw new RuntimeException(
			'Separate WP-CLI activation readback failed: ' . substr(
				// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Native JSON preserves protocol or fixture bytes without requiring WordPress helpers or their fallback behavior.
				json_encode(
					array(
						'code'   => $readback['code'],
						'body'   => $readback_body,
						'stderr' => trim( $readback['stderr'] ),
					),
					JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
				),
				0,
				4000
			)
		);
	}
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
	$result['status'] = 'pass';
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
	$result['hook_probe'] = $phase_output;
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
	$result['cli_readback'] = array(
		'code' => $readback['code'],
		'body' => trim( $readback['stdout'] ),
	);
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
	$result['cleanup'] = array(
		'output_file_exists' => is_file( $hook_file ),
		'mySql_server_pid'   => is_resource( $server ),
		'mySql_mode'         => $mode,
	);
} finally {
	if ( is_resource( $server ) ) {
		proc_terminate( $server, 15 );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
		for ( $attempt = 0; $attempt < 100; ++$attempt ) {
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- This CLI/eval-file fixture variable is local scenario/process state, not a WordPress request global override.
			$status = proc_get_status( $server );
			if ( ! $status['running'] ) {
				proc_close( $server );
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
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

	if ( isset( $port, $db_name ) ) { // @phpstan-ignore isset.variable, isset.variable (Finally cleanup must also handle an earlier exception before resource initialization.)
		try {
			// phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_init, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- The isolated proof owns a dedicated native MySQL connection before or outside WordPress database initialization. Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
			$cleanup = mysqli_init();
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.DB.RestrictedFunctions.mysql_mysqli_real_connect -- The isolated server may already be unavailable; connection success gates best-effort database teardown. Connect only to the isolated fixture database; preserve its socket/port and server-attestation boundary.
			if ( $cleanup instanceof mysqli && @mysqli_real_connect( $cleanup, '127.0.0.1', $db_user, $db_password, null, $port ) ) {
				$cleanup->query( 'DROP DATABASE IF EXISTS `' . $cleanup->real_escape_string( $db_name ) . '`' );
				$cleanup->close();
			}
		// phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- An unavailable isolated server is expected during startup/teardown; preserve the bounded retry or cleanup path.
		} catch ( mysqli_sql_exception ) {
			// The isolated server may fail before it has created its socket.
		}
	}

	if ( is_file( $hook_file ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove native fixture entries directly, preserving the surrounding ownership and link-handling checks.
		unlink( $hook_file );
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read exact local bytes for the disposable integration fixture without requiring WordPress filesystem initialization.
	if ( is_link( $base ) || realpath( $base ) !== $base || dirname( $base ) !== $workspace || ! is_file( $marker_file ) || is_link( $marker_file ) || file_get_contents( $marker_file ) !== $marker . "\n" ) {
		throw new RuntimeException( 'Refusing cleanup because disposable-root ownership cannot be revalidated.' );
	}
	ran_wp_release_updater_test_remove_tree( $base );
	if ( file_exists( $base ) || is_link( $base ) ) { // @phpstan-ignore booleanOr.rightAlwaysFalse (Retain the independent filesystem ownership recheck after destructive fixture cleanup.)
		throw new RuntimeException( 'Disposable proof root was not fully removed.' );
	}
}

// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Native JSON preserves protocol or fixture bytes without requiring WordPress helpers or their fallback behavior.
echo json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL;

/**
 * @param list<string> $command
 * @param array<string,string>|null $env
 * @return array{code:int,stdout:string,stderr:string}
 */
function ran_wp_release_updater_test_run_command( array $command, string $cwd, ?array $env = null, bool $require_zero = true, string $stdin = '' ): array {
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
		$env
	);
	if ( ! is_resource( $process ) ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- This exception carries internal failure data rather than an HTML response; escaping would alter the failure contract.
		throw new RuntimeException( 'Could not run command: ' . implode( ' ', $command ) );
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Write fixture bytes to the native stream while preserving its existing partial-write or subprocess protocol.
	if ( '' !== $stdin && false === fwrite( $pipes[0], $stdin ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the native stream owned by this fixture; WordPress filesystem abstractions do not own process or file handles.
		fclose( $pipes[0] );
		proc_terminate( $process );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the native stream owned by this fixture; WordPress filesystem abstractions do not own process or file handles.
		fclose( $pipes[1] );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the native stream owned by this fixture; WordPress filesystem abstractions do not own process or file handles.
		fclose( $pipes[2] );
		proc_close( $process );
		throw new RuntimeException( 'Could not provide command input.' );
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
	if ( ! is_string( $stdout ) || ! is_string( $stderr ) ) {
		throw new RuntimeException( 'Could not read command output.' );
	}
	if ( $require_zero && 0 !== $exit ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- This exception carries internal failure data rather than an HTML response; escaping would alter the failure contract.
		throw new RuntimeException( 'Command failed: ' . implode( ' ', $command ) . ' (' . $exit . ')' . substr( trim( $stdout . "\n" . $stderr ), 0, 8000 ) );
	}
	return array(
		'code'   => $exit,
		'stdout' => $stdout,
		'stderr' => $stderr,
	);
}

function ran_wp_release_updater_test_reserve_loopback_port(): int {
	$listener = stream_socket_server( 'tcp://127.0.0.1:0', $error_number, $error_message );
	if ( false === $listener ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- This exception carries internal failure data rather than an HTML response; escaping would alter the failure contract.
		throw new RuntimeException( 'Could not reserve an isolated loopback port: ' . $error_message . ' (' . $error_number . ')' );
	}
	$address = stream_socket_get_name( $listener, false );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the native stream owned by this fixture; WordPress filesystem abstractions do not own process or file handles.
	fclose( $listener );
	$separator = is_string( $address ) ? strrpos( $address, ':' ) : false;
	$port      = false === $separator ? 0 : (int) substr( $address, $separator + 1 );
	if ( $port < 1 ) {
		throw new RuntimeException( 'Could not determine an isolated loopback port.' );
	}
	return $port;
}

function ran_wp_release_updater_test_attest_server( mixed $server, int $port, string $data_directory, string $user, string $password ): mysqli {
	$status = is_resource( $server ) ? proc_get_status( $server ) : false;
	if ( ! is_array( $status ) || ! $status['running'] ) {
		throw new RuntimeException( 'Isolated MySQL stopped before attestation.' );
	}
	$mysqli = ran_wp_release_updater_test_connect( $port, $user, $password );
	try {
		$result = $mysqli->query( 'SELECT @@datadir AS datadir' );
		if ( ! $result instanceof mysqli_result ) {
			throw new RuntimeException( 'MySQL attestation query did not return rows.' );
		}
		$row      = $result->fetch_assoc();
		$expected = realpath( $data_directory );
		$actual   = is_array( $row ) && isset( $row['datadir'] ) ? realpath( (string) $row['datadir'] ) : false;
		if ( false === $expected || false === $actual || rtrim( $expected, '/\\' ) !== rtrim( $actual, '/\\' ) ) {
			throw new RuntimeException( 'Loopback MySQL datadir did not match the isolated fixture.' );
		}
		return $mysqli;
	} catch ( Throwable $error ) {
		$mysqli->close();
		throw $error;
	}
}

function ran_wp_release_updater_test_connect( int $port, string $user, string $password ): mysqli {
	$attempts = 0;
	while ( $attempts < 120 ) {
		// phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_init -- The isolated proof owns a dedicated native MySQL connection before or outside WordPress database initialization.
		$mysqli = mysqli_init();
		try {
			// phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_real_connect -- Connect only to the isolated fixture database; preserve its socket/port and server-attestation boundary.
			$connected = is_object( $mysqli ) && mysqli_real_connect( $mysqli, '127.0.0.1', $user, $password, null, $port );
			if ( $connected ) {
				return $mysqli;
			}
		// phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- An unavailable isolated server is expected during startup/teardown; preserve the bounded retry or cleanup path.
		} catch ( mysqli_sql_exception ) {
		}
		if ( is_object( $mysqli ) ) {
			$mysqli->close();
		}
		++$attempts;
		usleep( 25000 );
	}
	throw new RuntimeException( 'Could not connect to MySQL socket.' );
}

/**
 * @param list<string> $exclude
 */
function ran_wp_release_updater_test_copy_tree( string $source, string $destination, array $exclude = array() ): void {
	$source      = rtrim( $source, '/\\' );
	$destination = rtrim( $destination, '/\\' );
	if ( ! is_dir( $source ) ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- This exception carries internal failure data rather than an HTML response; escaping would alter the failure contract.
		throw new RuntimeException( 'Source path does not exist for copy_tree: ' . $source );
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create real directories for the disposable integration fixture with the specified permissions.
	if ( ! mkdir( $destination, 0700, true ) && ! is_dir( $destination ) ) {
		throw new RuntimeException( 'Could not create tree copy destination.' );
	}
	$forbidden = array_flip( array_merge( $exclude, array( 'node_modules', 'vendor', '.git' ) ) );
	$iterator  = new RecursiveIteratorIterator(
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
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create real directories for the disposable integration fixture with the specified permissions.
			mkdir( $target, 0700, true );
		} else {
			if ( ! is_readable( $entry->getPathname() ) ) {
				continue;
			}
			if ( ! is_dir( dirname( $target ) ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create real directories for the disposable integration fixture with the specified permissions.
				mkdir( dirname( $target ), 0700, true );
			}
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Copy failure is checked and translated to the harness exception; suppress only the native warning.
			if ( false === @copy( $entry->getPathname(), $target ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- This exception carries internal failure data rather than an HTML response; escaping would alter the failure contract.
				throw new RuntimeException( 'Could not copy file: ' . $entry->getPathname() );
			}
		}
	}
}

function ran_wp_release_updater_test_create_fixture_plugin( string $site, string $identity, string $uri ): void {
	$root = $site . '/wp-content/plugins/' . $identity;
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create real directories for the disposable integration fixture with the specified permissions.
	if ( ! is_dir( $root ) && ! mkdir( $root, 0700, true ) ) {
		throw new RuntimeException( 'Could not create fixture plugin directory.' );
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write exact bytes for the disposable integration fixture; WordPress helpers would alter the boundary under test.
	file_put_contents( $root . '/' . $identity . '.php', ran_wp_release_updater_test_fixture_plugin_source( $uri, '1.0.0' ) );
}

function ran_wp_release_updater_test_create_fixture_theme( string $site, string $identity, string $uri ): void {
	$root = $site . '/wp-content/themes/' . $identity;
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create real directories for the disposable integration fixture with the specified permissions.
	if ( ! is_dir( $root ) && ! mkdir( $root, 0700, true ) ) {
		throw new RuntimeException( 'Could not create fixture theme directory.' );
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write exact bytes for the disposable integration fixture; WordPress helpers would alter the boundary under test.
	file_put_contents( $root . '/style.css', ran_wp_release_updater_test_fixture_theme_stylesheet( $uri, '1.0.0' ) );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write exact bytes for the disposable integration fixture; WordPress helpers would alter the boundary under test.
	file_put_contents( $root . '/index.php', "<?php\n// Phase24 disposable theme fixture.\n" );
	if ( 'phase24-theme' === $identity ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write exact bytes for the disposable integration fixture; WordPress helpers would alter the boundary under test.
		file_put_contents( $root . '/functions.php', ran_wp_release_updater_test_fixture_theme_registration_source() );
	}
}

function ran_wp_release_updater_test_create_manager_plugin( string $site, string $theme ): void {
	$root = $site . '/wp-content/plugins/phase24-manager';
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create real directories for the disposable integration fixture with the specified permissions.
	if ( ! is_dir( $root ) && ! mkdir( $root, 0700, true ) ) {
		throw new RuntimeException( 'Could not create manager plugin directory.' );
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write exact bytes for the disposable integration fixture; WordPress helpers would alter the boundary under test.
	file_put_contents( $root . '/phase24-manager.php', "<?php\n/*\nPlugin Name: Phase24 Manager\nVersion: 1.0.0\n*/\n\$phase24 = require dirname( __DIR__ ) . '/ran-wp-release-updater/bootstrap.php';\n\$phase24Handle = \$phase24->theme( 'github', WP_CONTENT_DIR . '/themes/{$theme}/style.css', 'phase24-owner/phase24-manager-theme', '102', 'stable', 'disabled' );\n\$phase24Handle->register();\n\$GLOBALS['phase24_handles']['manager'] = \$phase24Handle;\n" );
}

function ran_wp_release_updater_test_make_fixture_archive( string $site, string $identity, string $uri, string $archive_tag, string $version, bool $is_theme ): string {
	$zip_path = $site . '/wp-content/uploads/' . $archive_tag . '-' . $identity . '.zip';
	$zip      = new ZipArchive();
	if ( true !== $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
		throw new RuntimeException( 'Could not create fixture archive.' );
	}
	$archive_root = $identity . '/';
	$zip->addFromString( $archive_root . ( $is_theme ? 'style.css' : $identity . '.php' ), $is_theme ? ran_wp_release_updater_test_fixture_theme_stylesheet( $uri, $version ) : ran_wp_release_updater_test_fixture_plugin_source( $uri, $version ) );
	if ( $is_theme ) {
		$zip->addFromString( $archive_root . 'index.php', "<?php\n// Phase24 disposable theme fixture.\n" );
		$zip->addFromString( $archive_root . 'functions.php', ran_wp_release_updater_test_fixture_theme_registration_source() );
	}
	$zip->addFromString( $archive_root . 'readme.txt', "Phase24 fixture archive {$archive_tag}\n" );
	$zip->close();
	return $zip_path;
}

function ran_wp_release_updater_test_fixture_plugin_source( string $uri, string $version ): string {
	return "<?php\n/*\nPlugin Name: Phase24 Plugin\nVersion: {$version}\nUpdate URI: {$uri}\nRequires PHP: 8.2\nRequires at least: 6.8\n*/\n// phase24-plugin-v{$version}\n\$phase24 = require dirname( __DIR__ ) . '/ran-wp-release-updater/bootstrap.php';\n\$phase24Handle = \$phase24->plugin( 'github', __FILE__, 'phase24-owner/phase24-plugin', '101', 'stable', getenv( 'RAN_WP_RELEASE_UPDATER_POLICY' ) ?: 'manual', static fn (): string => 'phase24-token' );\n\$phase24Handle->register();\n\$GLOBALS['phase24_handles']['plugin'] = \$phase24Handle;\n";
}

function ran_wp_release_updater_test_fixture_theme_stylesheet( string $uri, string $version ): string {
	return "/*\nTheme Name: Phase24 Theme\nVersion: {$version}\nUpdate URI: {$uri}\nRequires PHP: 8.2\nRequires at least: 6.8\n*/\n";
}

function ran_wp_release_updater_test_fixture_theme_registration_source(): string {
	return "<?php\n\$phase24 = require dirname( __DIR__, 2 ) . '/plugins/ran-wp-release-updater/bootstrap.php';\n\$phase24Handle = \$phase24->theme( 'github', __DIR__ . '/style.css', 'phase24-owner/phase24-theme', '101', 'stable', getenv( 'RAN_WP_RELEASE_UPDATER_POLICY' ) ?: 'manual', static fn (): string => 'phase24-token' );\n\$phase24Handle->register();\n\$GLOBALS['phase24_handles']['theme'] = \$phase24Handle;\n";
}

function ran_wp_release_updater_test_remove_tree( string $path ): void {
	if ( ! is_dir( $path ) ) {
		return;
	}
	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $iterator as $entry ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove native fixture entries directly, preserving the surrounding ownership and link-handling checks.
		is_dir( $entry->getPathname() ) ? rmdir( $entry->getPathname() ) : unlink( $entry->getPathname() );
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove native fixture entries directly, preserving the surrounding ownership and link-handling checks.
	rmdir( $path );
}
