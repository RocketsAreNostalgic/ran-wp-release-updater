<?php

declare( strict_types = 1 );
$root          = realpath( __DIR__ . '/../../' ) ?: dirname( __DIR__, 2 );
$wpRoot        = getenv( 'RAN_WP_RELEASE_UPDATER_LOCAL_WP_ROOT' ) ?: dirname( $root, 3 );
$marker        = 'RAN_WP_RELEASE_UPDATER_MIXED_BULK';
$base          = '/private/tmp/' . strtolower( $marker ) . '-' . bin2hex( random_bytes( 16 ) );
$markerFile    = $base . '/' . $marker . '.marker';
$site          = $base . '/site';
$dbName        = 'ran_updater_mixed_bulk_' . random_int( 100000, 999999 );
$socket        = $base . '/mysql/mysql.sock';
$mysqld        = getenv( 'RAN_UPDATER_MYSQLD_BIN' ) ?: '/Applications/Local.app/Contents/Resources/extraResources/lightning-services/mysql-8.4.0/bin/darwin-arm64/bin/mysqld';
$phpCandidates = glob( '/Applications/Local.app/Contents/Resources/extraResources/lightning-services/php-8.2*/bin/darwin-arm64/bin/php' );
$php           = getenv( 'RAN_WP_RELEASE_UPDATER_PHP82' ) ?: ( $phpCandidates[0] ?? '' );
$wp            = '/usr/local/bin/wp';
$server        = null;
$result        = array(
	'marker' => $marker,
	'status' => 'errored',
);
if ( '/private/tmp' !== realpath( dirname( $base ) ) || file_exists( $base ) || is_link( $base ) || ! is_dir( $wpRoot ) ) {
	throw new RuntimeException( 'Refusing an unsafe disposable mixed-bulk proof root.' );
}
if ( ! mkdir( $base, 0700 ) || $base !== realpath( $base ) || ! file_put_contents( $markerFile, $marker . "\n" ) ) {
	throw new RuntimeException( 'Could not establish the owned disposable proof root.' );
}
try {
	if ( ! is_executable( $mysqld ) || ! is_executable( $php ) || ! is_file( $wp ) ) {
		throw new RuntimeException( 'Required Local PHP 8.2, mysqld, or WP-CLI is unavailable.' );
	}
	mkdir( dirname( $socket ), 0700, true );
	run( array( $mysqld, '--no-defaults', '--initialize-insecure', '--datadir=' . $base . '/mysql/data' ), $base . '/mysql' );
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
	fclose( $pipes[0] );
	$db = connect( $socket );
	$db->query( 'CREATE DATABASE `' . $db->real_escape_string( $dbName ) . '`' );
	$db->close();
	copyTree( $wpRoot, $site, array( '.git', 'wp-content', 'wp-config.php', '.well-known' ) );
	foreach ( array( 'plugins', 'themes', 'uploads' ) as $dir ) {
		mkdir( $site . '/wp-content/' . $dir, 0700, true );
	}
	copyTree( $root, $site . '/wp-content/plugins/ran-wp-release-updater', array( 'tests', '.git', '.github', 'vendor', 'node_modules' ) );
	file_put_contents( $site . '/wp-config.php', config( $dbName, $socket ) );
	$env = array(
		'DB_HOST'     => 'localhost:' . $socket,
		'DB_USER'     => 'root',
		'DB_PASSWORD' => '',
	);
	run( array( $php, $wp, '--path=' . $site, 'core', 'install', '--skip-email', '--url=http://127.0.0.1', '--title=mixed-bulk', '--admin_user=admin', '--admin_password=password123!', '--admin_email=admin@example.test' ), $site, $env );
	$scenarios = array( array( 'plugin', 'success' ), array( 'theme', 'success' ), array( 'plugin', 'failure' ), array( 'theme', 'failure' ) );
	$proofs    = array();
	foreach ( $scenarios as $scenario ) {
		[ $type, $mode ] = $scenario;
		$targets        = createFixtures( $site, $type );
		run( array( $php, $wp, '--path=' . $site, 'eval', "global \$wpdb; \$wpdb->query( \"DELETE FROM {\$wpdb->options} WHERE option_name LIKE 'ran\\\\_wp\\\\_release\\\\_updater\\\\_target\\\\_v1\\\\_%'\" );" ), $site, $env );
		if ( 'plugin' === $type ) {
			run( array( $php, $wp, '--path=' . $site, 'plugin', 'activate', 'managed-a' ), $site, $env );
		} else {
			run( array( $php, $wp, '--path=' . $site, 'theme', 'activate', 'managed-a' ), $site, $env );
		}
		$output = $base . '/evidence-' . $type . '-' . $mode . '.json';
		run(
			array( $php, $wp, '--path=' . $site, 'eval-file', $root . '/tests/Integration/wordpress-native-mixed-bulk-proof-harness.php' ),
			$site,
			$env + array(
				'RAN_WP_RELEASE_UPDATER_MIXED_BULK'        => $marker,
				'RAN_WP_RELEASE_UPDATER_MARKER_FILE'       => $markerFile,
				'RAN_WP_RELEASE_UPDATER_SOURCE_ROOT'       => $site . '/wp-content/plugins/ran-wp-release-updater',
				'RAN_WP_RELEASE_UPDATER_MIXED_BULK_OUTPUT' => $output,
				'RAN_WP_RELEASE_UPDATER_BULK_TYPE'         => $type,
				'RAN_WP_RELEASE_UPDATER_BULK_MODE'         => $mode,
				'RAN_WP_RELEASE_UPDATER_MANAGED_A_ARCHIVE' => $targets['managed-a'],
				'RAN_WP_RELEASE_UPDATER_ORDINARY_ARCHIVE'  => $targets['ordinary'],
				'RAN_WP_RELEASE_UPDATER_MANAGED_B_ARCHIVE' => $targets['managed-b'],
			)
		);
		$proof = json_decode( (string) file_get_contents( $output ), true, 64, JSON_THROW_ON_ERROR );
		if ( ! is_array( $proof ) || ! ( $proof['pass'] ?? false ) ) {
			throw new RuntimeException( 'Mixed-bulk ' . $type . ' ' . $mode . ' assertion failed: ' . substr( json_encode( $proof, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ), 0, 12000 ) );
		}
		$proofs[ $type . '-' . $mode ] = $proof;
	}
	$result = array(
		'marker'    => $marker,
		'status'    => 'pass',
		'scenarios' => array_keys( $proofs ),
		'evidence'  => $proofs,
	);
} finally {
	if ( is_resource( $server ) ) {
		proc_terminate( $server, 15 );
		for ( $i = 0; $i < 100 && proc_get_status( $server )['running']; ++$i ) {
			usleep( 10000 );
		}
		proc_close( $server );
	}
	if ( file_exists( $base ) ) {
		if ( is_link( $base ) || $base !== realpath( $base ) || ! is_file( $markerFile ) || $marker . "\n" !== file_get_contents( $markerFile ) ) {
			throw new RuntimeException( 'Refusing unvalidated disposable proof cleanup.' );
		}
		removeTree( $base );
	}
}
echo json_encode( $result, JSON_UNESCAPED_SLASHES ) . PHP_EOL;
function run( array $command, string $cwd, array $env = array() ): void {
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
	fclose( $pipes[0] );
	$out = stream_get_contents( $pipes[1] ) . stream_get_contents( $pipes[2] );
	fclose( $pipes[1] );
	fclose( $pipes[2] );
	if ( 0 !== proc_close( $p ) ) {
		throw new RuntimeException( substr( $out, 0, 8000 ) );
	}
}
function connect( string $socket ): mysqli {
	for ( $i = 0; $i < 120; ++$i ) {
		$db = mysqli_init();
		try {
			if ( mysqli_real_connect( $db, null, 'root', '', null, 0, $socket ) ) {
				return $db;
			}
		} catch ( mysqli_sql_exception ) {
		}
		usleep( 25000 );
	}
	throw new RuntimeException( 'Could not connect to isolated MySQL.' );
}
function copyTree( string $source, string $destination, array $exclude ): void {
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
			mkdir( $to, 0700, true );
		} elseif ( ! is_dir( dirname( $to ) ) ) {
			mkdir( dirname( $to ), 0700, true );
		}
		if ( $f->isFile() && ! copy( $f->getPathname(), $to ) ) {
			throw new RuntimeException( 'Copy failed.' );
		}
	}
}
/** @return array<string,string> */
function createFixtures( string $site, string $type ): array {
	$targets  = array(
		'managed-a' => array( 'Managed A', 'https://mixed-bulk.invalid/managed-a/repository' ),
		'ordinary'  => array( 'Ordinary', 'https://mixed-bulk.invalid/ordinary/repository' ),
		'managed-b' => array( 'Managed B', 'https://mixed-bulk.invalid/managed-b/repository' ),
	);
	$archives = array();
	foreach ( $targets as $slug => $target ) {
		[ $name, $uri ] = $target;
		if ( 'plugin' === $type ) {
			fixturePlugin( $site, $slug, $name, $uri, '1.0.0' );
		} else {
			fixtureTheme( $site, $slug, $name, $uri, '1.0.0' );
		}
		$archives[ $slug ] = fixtureArchive( $site, $slug, $name, $uri, $type );
	}
	return $archives;
}
function fixturePlugin( string $site, string $slug, string $name, string $uri, string $version ): void {
	$dir = $site . '/wp-content/plugins/' . $slug;
	if ( ! is_dir( $dir ) ) {
		mkdir( $dir, 0700, true );
	}
	file_put_contents(
		$dir . '/' . $slug . '.php',
		"<?php\n/*\nPlugin Name: {$name}\nVersion: {$version}\nUpdate URI: {$uri}\nRequires PHP: 8.2\nRequires at least: 6.8\n*/\n// {$slug}-v{$version}\n"
	);
}
function fixtureTheme( string $site, string $slug, string $name, string $uri, string $version ): void {
	$dir = $site . '/wp-content/themes/' . $slug;
	if ( ! is_dir( $dir ) ) {
		mkdir( $dir, 0700, true );
	}
	file_put_contents(
		$dir . '/style.css',
		"/*\nTheme Name: {$name}\nVersion: {$version}\nUpdate URI: {$uri}\nRequires PHP: 8.2\nRequires at least: 6.8\n*/\n"
	);
	file_put_contents(
		$dir . '/index.php',
		"<?php // {$slug}-v{$version}\n"
	);
}
function fixtureArchive( string $site, string $slug, string $name, string $uri, string $type ): string {
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
function config( string $db, string $socket ): string {
	return "<?php\ndefine('DB_NAME', '{$db}'); define('DB_USER', 'root'); define('DB_PASSWORD', ''); define('DB_HOST', 'localhost:{$socket}'); define('DB_CHARSET','utf8'); define('DB_COLLATE','');\n" . "define('AUTH_KEY','x'); define('SECURE_AUTH_KEY','x'); define('LOGGED_IN_KEY','x'); define('NONCE_KEY','x'); define('AUTH_SALT','x'); define('SECURE_AUTH_SALT','x'); define('LOGGED_IN_SALT','x'); define('NONCE_SALT','x');\n\$table_prefix='wp_'; define('FS_METHOD','direct'); define('WP_DEBUG',false); require_once __DIR__ . '/wp-settings.php';\n";
}
function removeTree( string $path ): void {
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $it as $f ) {
		$f->isDir() ? rmdir( $f->getPathname() ) : unlink( $f->getPathname() );
	}
	rmdir( $path );
}
