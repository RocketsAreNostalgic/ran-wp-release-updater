<?php

declare( strict_types = 1 );
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$root = realpath( __DIR__ . '/../../' );
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$root = $root ? $root : dirname( __DIR__, 2 );
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$wp_root_input = getenv( 'RAN_WP_RELEASE_UPDATER_LOCAL_WP_ROOT' );
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$wp_root = is_string( $wp_root_input ) && '' !== $wp_root_input ? realpath( $wp_root_input ) : false;
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$marker = 'RAN_WP_RELEASE_UPDATER_MIXED_BULK';
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$base = '/private/tmp/' . strtolower( $marker ) . '-' . bin2hex( random_bytes( 16 ) );
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$marker_file = $base . '/' . $marker . '.marker';
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$site = $base . '/site';
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$db_name = 'ran_updater_mixed_bulk_' . random_int( 100000, 999999 );
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$socket = $base . '/mysql/mysql.sock';
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$mysqld = getenv( 'RAN_UPDATER_MYSQLD_BIN' );
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$mysqld = $mysqld ? $mysqld : '/Applications/Local.app/Contents/Resources/extraResources/lightning-services/mysql-8.4.0/bin/darwin-arm64/bin/mysqld';
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$php_candidates = glob( '/Applications/Local.app/Contents/Resources/extraResources/lightning-services/php-8.2*/bin/darwin-arm64/bin/php' );
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$php = getenv( 'RAN_WP_RELEASE_UPDATER_PHP82' );
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$php = $php ? $php : ( $php_candidates[0] ?? '' );
// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- This CLI/eval-file fixture variable is local scenario/process state, not a WordPress request global override.
$wp = '/usr/local/bin/wp';
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$server = null;
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$result = array(
	'marker' => $marker,
	'status' => 'errored',
);
if (
	'/private/tmp' !== realpath( dirname( $base ) )
	|| file_exists( $base )
	|| is_link( $base )
	|| false === $wp_root
	|| ! is_file( $wp_root . '/wp-load.php' )
	|| ! is_file( $wp_root . '/wp-settings.php' )
	|| ! is_file( $wp_root . '/wp-includes/version.php' )
) {
	throw new RuntimeException( 'Refusing an unsafe disposable mixed-bulk proof root.' );
}
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write exact bytes for the disposable integration fixture; WordPress helpers would alter the boundary under test.
if ( ! mkdir( $base, 0700 ) || realpath( $base ) !== $base || ! file_put_contents( $marker_file, $marker . "\n" ) ) {
	throw new RuntimeException( 'Could not establish the owned disposable proof root.' );
}
try {
	if ( ! is_executable( $mysqld ) || ! is_executable( $php ) || ! is_file( $wp ) ) {
		throw new RuntimeException( 'Required Local PHP 8.2, mysqld, or WP-CLI is unavailable.' );
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create real directories for the disposable integration fixture with the specified permissions.
	mkdir( dirname( $socket ), 0700, true );
	ran_wp_release_updater_test_run( array( $mysqld, '--no-defaults', '--initialize-insecure', '--datadir=' . $base . '/mysql/data' ), $base . '/mysql' );
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Start the dedicated mysqld child with explicit argv and fixture paths; the harness owns its shutdown. Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
	$server = proc_open(
		array( $mysqld, '--no-defaults', '--datadir=' . $base . '/mysql/data', '--socket=' . $socket, '--pid-file=' . $base . '/mysql/mysqld.pid', '--skip-networking', '--log-error=' . $base . '/mysql/mysqld.err' ),
		array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'file', $base . '/mysql/mysqld.out', 'a' ),
			2 => array( 'file', $base . '/mysql/mysqld.err', 'a' ),
		),
		$pipes,
		$base . '/mysql'
	);
	if ( ! is_resource( $server ) ) {
		throw new RuntimeException( 'Could not launch isolated mysqld.' );
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the native stream owned by this fixture; WordPress filesystem abstractions do not own process or file handles.
	fclose( $pipes[0] );
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
	$db = ran_wp_release_updater_test_connect( $socket );
	$db->query( 'CREATE DATABASE `' . $db->real_escape_string( $db_name ) . '`' );
	$db->close();
	ran_wp_release_updater_test_copy_tree( $wp_root, $site, array( '.git', 'wp-content', 'wp-config.php', '.well-known' ) );
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
	foreach ( array( 'plugins', 'themes', 'uploads' ) as $dir ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create real directories for the disposable integration fixture with the specified permissions.
		mkdir( $site . '/wp-content/' . $dir, 0700, true );
	}
	ran_wp_release_updater_test_copy_tree( $root, $site . '/wp-content/plugins/ran-wp-release-updater', array( 'tests', '.git', '.github', 'vendor', 'node_modules' ) );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write exact bytes for the disposable integration fixture; WordPress helpers would alter the boundary under test.
	file_put_contents( $site . '/wp-config.php', ran_wp_release_updater_test_config( $db_name, $socket ) );
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
	$env = array(
		'DB_HOST'     => 'localhost:' . $socket,
		'DB_USER'     => 'root',
		'DB_PASSWORD' => '',
	);
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
	$admin_password = bin2hex( random_bytes( 32 ) );
	ran_wp_release_updater_test_run( array( $php, $wp, '--path=' . $site, 'core', 'install', '--skip-email', '--url=http://127.0.0.1', '--title=mixed-bulk', '--admin_user=admin', '--prompt=admin_password', '--admin_email=admin@example.test' ), $site, $env, $admin_password . "\n" );
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
	$scenarios = array( array( 'plugin', 'success' ), array( 'theme', 'success' ), array( 'plugin', 'failure' ), array( 'theme', 'failure' ) );
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
	$proofs = array();
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
	foreach ( $scenarios as $scenario ) {
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- This CLI/eval-file fixture variable is local scenario/process state, not a WordPress request global override.
		[ $type, $mode ] = $scenario;
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- This CLI/eval-file fixture variable is local scenario/process state, not a WordPress request global override.
		$targets = ran_wp_release_updater_test_create_fixtures( $site, $type );
		ran_wp_release_updater_test_run( array( $php, $wp, '--path=' . $site, 'eval', "global \$wpdb; \$wpdb->query( \"DELETE FROM {\$wpdb->options} WHERE option_name LIKE 'ran\\\\_wp\\\\_release\\\\_updater\\\\_target\\\\_v1\\\\_%'\" );" ), $site, $env );
		if ( 'plugin' === $type ) {
			ran_wp_release_updater_test_run( array( $php, $wp, '--path=' . $site, 'plugin', 'activate', 'managed-a' ), $site, $env );
		} else {
			ran_wp_release_updater_test_run( array( $php, $wp, '--path=' . $site, 'theme', 'activate', 'managed-a' ), $site, $env );
		}
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
		$output = $base . '/evidence-' . $type . '-' . $mode . '.json';
		ran_wp_release_updater_test_run(
			array( $php, $wp, '--path=' . $site, 'eval-file', $root . '/tests/Integration/wordpress-native-mixed-bulk-proof-harness.php' ),
			$site,
			$env + array(
				'RAN_WP_RELEASE_UPDATER_MIXED_BULK'        => $marker,
				'RAN_WP_RELEASE_UPDATER_MARKER_FILE'       => $marker_file,
				'RAN_WP_RELEASE_UPDATER_SOURCE_ROOT'       => $site . '/wp-content/plugins/ran-wp-release-updater',
				'RAN_WP_RELEASE_UPDATER_MIXED_BULK_OUTPUT' => $output,
				'RAN_WP_RELEASE_UPDATER_BULK_TYPE'         => $type,
				'RAN_WP_RELEASE_UPDATER_BULK_MODE'         => $mode,
				'RAN_WP_RELEASE_UPDATER_MANAGED_A_ARCHIVE' => $targets['managed-a'],
				'RAN_WP_RELEASE_UPDATER_ORDINARY_ARCHIVE'  => $targets['ordinary'],
				'RAN_WP_RELEASE_UPDATER_MANAGED_B_ARCHIVE' => $targets['managed-b'],
			)
		);
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Read exact local bytes for the disposable integration fixture without requiring WordPress filesystem initialization. Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
		$proof = json_decode( (string) file_get_contents( $output ), true, 64, JSON_THROW_ON_ERROR );
		if ( ! is_array( $proof ) || ! ( $proof['pass'] ?? false ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Native JSON preserves protocol or fixture bytes without requiring WordPress helpers or their fallback behavior.
			throw new RuntimeException( 'Mixed-bulk ' . $type . ' ' . $mode . ' assertion failed: ' . substr( json_encode( $proof, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ), 0, 12000 ) );
		}
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
		$proofs[ $type . '-' . $mode ] = $proof;
	}
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
	$result = array(
		'marker'    => $marker,
		'status'    => 'pass',
		'scenarios' => array_keys( $proofs ),
		'evidence'  => $proofs,
	);
} finally {
	if ( is_resource( $server ) ) {
		proc_terminate( $server, 15 );
		// phpcs:ignore Generic.CodeAnalysis.ForLoopWithTestFunctionCall.NotAllowed, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Re-observe child liveness or the release file on each bounded poll; caching the condition would break synchronization. Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
		for ( $i = 0; $i < 100 && proc_get_status( $server )['running']; ++$i ) {
			usleep( 10000 );
		}
		proc_close( $server );
	}
	if ( file_exists( $base ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read exact local bytes for the disposable integration fixture without requiring WordPress filesystem initialization.
		if ( is_link( $base ) || realpath( $base ) !== $base || ! is_file( $marker_file ) || file_get_contents( $marker_file ) !== $marker . "\n" ) { // @phpstan-ignore booleanOr.leftAlwaysFalse (Retain the independent filesystem ownership recheck before destructive fixture cleanup.)
			throw new RuntimeException( 'Refusing unvalidated disposable proof cleanup.' );
		}
		ran_wp_release_updater_test_remove_tree( $base );
	}
}
// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Native JSON preserves protocol or fixture bytes without requiring WordPress helpers or their fallback behavior.
echo json_encode( $result, JSON_UNESCAPED_SLASHES ) . PHP_EOL;
function ran_wp_release_updater_test_run( array $command, string $cwd, array $env = array(), string $stdin = '' ): void {
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Run the isolated proof command with explicit argv, pipe capture and exit-status observation.
	$p = proc_open(
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
	if ( ! is_resource( $p ) ) {
		throw new RuntimeException( 'Could not run command.' );
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Write fixture bytes to the native stream while preserving its existing partial-write or subprocess protocol.
	if ( '' !== $stdin && false === fwrite( $pipes[0], $stdin ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the native stream owned by this fixture; WordPress filesystem abstractions do not own process or file handles.
		fclose( $pipes[0] );
		proc_terminate( $p );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the native stream owned by this fixture; WordPress filesystem abstractions do not own process or file handles.
		fclose( $pipes[1] );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the native stream owned by this fixture; WordPress filesystem abstractions do not own process or file handles.
		fclose( $pipes[2] );
		proc_close( $p );
		throw new RuntimeException( 'Could not provide command input.' );
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the native stream owned by this fixture; WordPress filesystem abstractions do not own process or file handles.
	fclose( $pipes[0] );
	$out = stream_get_contents( $pipes[1] ) . stream_get_contents( $pipes[2] );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the native stream owned by this fixture; WordPress filesystem abstractions do not own process or file handles.
	fclose( $pipes[1] );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the native stream owned by this fixture; WordPress filesystem abstractions do not own process or file handles.
	fclose( $pipes[2] );
	if ( 0 !== proc_close( $p ) ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- This exception carries internal failure data rather than an HTML response; escaping would alter the failure contract.
		throw new RuntimeException( substr( $out, 0, 8000 ) );
	}
}
function ran_wp_release_updater_test_connect( string $socket ): mysqli {
	for ( $i = 0; $i < 120; ++$i ) {
		// phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_init -- The isolated proof owns a dedicated native MySQL connection before or outside WordPress database initialization.
		$db = mysqli_init();
		try {
			// phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_real_connect -- Connect only to the isolated fixture database; preserve its socket/port and server-attestation boundary.
			if ( mysqli_real_connect( $db, null, 'root', '', null, 0, $socket ) ) {
				return $db;
			}
		// phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- An unavailable isolated server is expected during startup/teardown; preserve the bounded retry or cleanup path.
		} catch ( mysqli_sql_exception ) {
		}
		usleep( 25000 );
	}
	throw new RuntimeException( 'Could not connect to isolated MySQL.' );
}
function ran_wp_release_updater_test_copy_tree( string $source, string $destination, array $exclude ): void {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create real directories for the disposable integration fixture with the specified permissions.
	mkdir( $destination, 0700, true );
	$skip = array_flip( $exclude );
	$it   = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $source, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::SELF_FIRST );
	foreach ( $it as $f ) {
		$relative = substr( $f->getPathname(), strlen( $source ) + 1 );
		if ( $f->isLink() || array_intersect( explode( '/', $relative ), array_keys( $skip ) ) ) {
			continue;
		}
		$to = $destination . '/' . $relative;
		if ( $f->isDir() ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create real directories for the disposable integration fixture with the specified permissions.
			mkdir( $to, 0700, true );
		} elseif ( ! is_dir( dirname( $to ) ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create real directories for the disposable integration fixture with the specified permissions.
			mkdir( dirname( $to ), 0700, true );
		}
		if ( $f->isFile() && ! copy( $f->getPathname(), $to ) ) {
			throw new RuntimeException( 'Copy failed.' );
		}
	}
}
/** @return array<string,string> */
function ran_wp_release_updater_test_create_fixtures( string $site, string $type ): array {
	$targets  = array(
		'managed-a' => array( 'Managed A', 'https://mixed-bulk.invalid/managed-a/repository' ),
		'ordinary'  => array( 'Ordinary', 'https://mixed-bulk.invalid/ordinary/repository' ),
		'managed-b' => array( 'Managed B', 'https://mixed-bulk.invalid/managed-b/repository' ),
	);
	$archives = array();
	foreach ( $targets as $slug => $target ) {
		[ $name, $uri ] = $target;
		if ( 'plugin' === $type ) {
			ran_wp_release_updater_test_fixture_plugin( $site, $slug, $name, $uri, '1.0.0' );
		} else {
			ran_wp_release_updater_test_fixture_theme( $site, $slug, $name, $uri, '1.0.0' );
		}
		$archives[ $slug ] = ran_wp_release_updater_test_fixture_archive( $site, $slug, $name, $uri, $type );
	}
	return $archives;
}
function ran_wp_release_updater_test_fixture_plugin( string $site, string $slug, string $name, string $uri, string $version ): void {
	$dir = $site . '/wp-content/plugins/' . $slug;
	if ( ! is_dir( $dir ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create real directories for the disposable integration fixture with the specified permissions.
		mkdir( $dir, 0700, true );
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write exact bytes for the disposable integration fixture; WordPress helpers would alter the boundary under test.
	file_put_contents(
		$dir . '/' . $slug . '.php',
		"<?php\n/*\nPlugin Name: {$name}\nVersion: {$version}\nUpdate URI: {$uri}\nRequires PHP: 8.2\nRequires at least: 6.8\n*/\n// {$slug}-v{$version}\n"
	);
}
function ran_wp_release_updater_test_fixture_theme( string $site, string $slug, string $name, string $uri, string $version ): void {
	$dir = $site . '/wp-content/themes/' . $slug;
	if ( ! is_dir( $dir ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create real directories for the disposable integration fixture with the specified permissions.
		mkdir( $dir, 0700, true );
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write exact bytes for the disposable integration fixture; WordPress helpers would alter the boundary under test.
	file_put_contents(
		$dir . '/style.css',
		"/*\nTheme Name: {$name}\nVersion: {$version}\nUpdate URI: {$uri}\nRequires PHP: 8.2\nRequires at least: 6.8\n*/\n"
	);
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write exact bytes for the disposable integration fixture; WordPress helpers would alter the boundary under test.
	file_put_contents(
		$dir . '/index.php',
		"<?php // {$slug}-v{$version}\n"
	);
}
function ran_wp_release_updater_test_fixture_archive( string $site, string $slug, string $name, string $uri, string $type ): string {
	$path = $site . '/wp-content/uploads/' . $type . '-' . $slug . '-2.0.0.zip';
	$zip  = new ZipArchive();
	if ( true !== $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
		throw new RuntimeException( 'Archive create failed.' );
	}
	if ( 'plugin' === $type ) {
		$zip->addFromString(
			$slug . '/' . $slug . '.php',
			"<?php\n/*\nPlugin Name: {$name}\nVersion: 2.0.0\nUpdate URI: {$uri}\nRequires PHP: 8.2\nRequires at least: 6.8\n*/\n// {$slug}-v2\n"
		);
	} else {
		$zip->addFromString(
			$slug . '/style.css',
			"/*\nTheme Name: {$name}\nVersion: 2.0.0\nUpdate URI: {$uri}\nRequires PHP: 8.2\nRequires at least: 6.8\n*/\n"
		);
		$zip->addFromString(
			$slug . '/index.php',
			"<?php // {$slug}-v2\n"
		);
	}
	$zip->close();
	return $path;
}
function ran_wp_release_updater_test_config( string $db, string $socket ): string {
	return "<?php\ndefine('DB_NAME', '{$db}'); define('DB_USER', 'root'); define('DB_PASSWORD', ''); define('DB_HOST', 'localhost:{$socket}'); define('DB_CHARSET','utf8'); define('DB_COLLATE','');\ndefine('AUTH_KEY','x'); define('SECURE_AUTH_KEY','x'); define('LOGGED_IN_KEY','x'); define('NONCE_KEY','x'); define('AUTH_SALT','x'); define('SECURE_AUTH_SALT','x'); define('LOGGED_IN_SALT','x'); define('NONCE_SALT','x');\n\$table_prefix='wp_'; define('FS_METHOD','direct'); define('WP_DEBUG',false); require_once __DIR__ . '/wp-settings.php';\n";
}
function ran_wp_release_updater_test_remove_tree( string $path ): void {
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $it as $f ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove native fixture entries directly, preserving the surrounding ownership and link-handling checks.
		$f->isDir() ? rmdir( $f->getPathname() ) : unlink( $f->getPathname() );
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove native fixture entries directly, preserving the surrounding ownership and link-handling checks.
	rmdir( $path );
}
