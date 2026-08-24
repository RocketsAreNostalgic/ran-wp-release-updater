<?php

declare( strict_types = 1 );

$root = realpath( __DIR__ . '/../../' ) ?: dirname( __DIR__, 2 );
$wpRoot = getenv( 'RAN_WP_RELEASE_UPDATER_LOCAL_WP_ROOT' ) ?: dirname( $root, 3 );
$marker = 'RAN_WP_RELEASE_UPDATER_MIXED_BULK';
$base = '/private/tmp/' . strtolower( $marker ) . '-' . bin2hex( random_bytes( 16 ) );
$markerFile = $base . '/' . $marker . '.marker';
$site = $base . '/site';
$dbName = 'ran_updater_mixed_bulk_' . random_int( 100000, 999999 );
$socket = $base . '/mysql/mysql.sock';
$mysqld = getenv( 'RAN_UPDATER_MYSQLD_BIN' ) ?: '/Applications/Local.app/Contents/Resources/extraResources/lightning-services/mysql-8.4.0/bin/darwin-arm64/bin/mysqld';
$phpCandidates = glob( '/Applications/Local.app/Contents/Resources/extraResources/lightning-services/php-8.2*/bin/darwin-arm64/bin/php' );
$php = getenv( 'RAN_WP_RELEASE_UPDATER_PHP82' ) ?: ( $phpCandidates[0] ?? '' );
$wp = '/usr/local/bin/wp';
$server = null;
$result = array( 'marker' => $marker, 'status' => 'errored' );

if ( '/private/tmp' !== realpath( dirname( $base ) ) || file_exists( $base ) || is_link( $base ) || ! is_dir( $wpRoot ) ) throw new RuntimeException( 'Refusing an unsafe disposable mixed-bulk proof root.' );
if ( ! mkdir( $base, 0700 ) || $base !== realpath( $base ) || ! file_put_contents( $markerFile, $marker . "\n" ) ) throw new RuntimeException( 'Could not establish the owned disposable proof root.' );

try {
	if ( ! is_executable( $mysqld ) || ! is_executable( $php ) || ! is_file( $wp ) ) throw new RuntimeException( 'Required Local PHP 8.2, mysqld, or WP-CLI is unavailable.' );
	mkdir( dirname( $socket ), 0700, true );
	run( array( $mysqld, '--no-defaults', '--initialize-insecure', '--datadir=' . $base . '/mysql/data' ), $base . '/mysql' );
	$server = proc_open( array( $mysqld, '--no-defaults', '--datadir=' . $base . '/mysql/data', '--socket=' . $socket, '--pid-file=' . $base . '/mysql/mysqld.pid', '--skip-networking', '--log-error=' . $base . '/mysql/mysqld.err' ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'file', $base . '/mysql/mysqld.out', 'a' ), 2 => array( 'file', $base . '/mysql/mysqld.err', 'a' ) ), $pipes, $base . '/mysql' );
	if ( ! is_resource( $server ) ) throw new RuntimeException( 'Could not launch isolated mysqld.' );
	fclose( $pipes[0] );
	$db = connect( $socket ); $db->query( 'CREATE DATABASE `' . $db->real_escape_string( $dbName ) . '`' ); $db->close();
	copyTree( $wpRoot, $site, array( '.git', 'wp-content', 'wp-config.php', '.well-known' ) );
	foreach ( array( 'plugins', 'themes', 'uploads' ) as $dir ) mkdir( $site . '/wp-content/' . $dir, 0700, true );
	copyTree( $root, $site . '/wp-content/plugins/ran-wp-release-updater', array( 'tests', '.git', '.github', 'vendor', 'node_modules' ) );
	$targets = array(
		'managed-a' => array( 'name' => 'Managed A', 'uri' => 'https://mixed-bulk.invalid/managed-a/repository' ),
		'ordinary' => array( 'name' => 'Ordinary', 'uri' => 'https://mixed-bulk.invalid/ordinary/repository' ),
		'managed-b' => array( 'name' => 'Managed B', 'uri' => 'https://mixed-bulk.invalid/managed-b/repository' ),
	);
	foreach ( $targets as $slug => $target ) { fixturePlugin( $site, $slug, $target['name'], $target['uri'], '1.0.0' ); $targets[ $slug ]['archive'] = fixtureArchive( $site, $slug, $target['name'], $target['uri'] ); }
	file_put_contents( $site . '/wp-config.php', config( $dbName, $socket ) );
	$env = array( 'DB_HOST' => 'localhost:' . $socket, 'DB_USER' => 'root', 'DB_PASSWORD' => '' );
	run( array( $php, $wp, '--path=' . $site, 'core', 'install', '--skip-email', '--url=http://127.0.0.1', '--title=mixed-bulk', '--admin_user=admin', '--admin_password=password123!', '--admin_email=admin@example.test' ), $site, $env );
	run( array( $php, $wp, '--path=' . $site, 'plugin', 'activate', 'managed-a', 'ordinary', 'managed-b' ), $site, $env );
	$output = $base . '/evidence.json';
	run( array( $php, $wp, '--path=' . $site, 'eval-file', $root . '/tests/Integration/wordpress-native-mixed-bulk-proof-harness.php' ), $site, $env + array( 'RAN_WP_RELEASE_UPDATER_MIXED_BULK' => $marker, 'RAN_WP_RELEASE_UPDATER_MARKER_FILE' => $markerFile, 'RAN_WP_RELEASE_UPDATER_SOURCE_ROOT' => $site . '/wp-content/plugins/ran-wp-release-updater', 'RAN_WP_RELEASE_UPDATER_MIXED_BULK_OUTPUT' => $output, 'RAN_WP_RELEASE_UPDATER_MANAGED_A_ARCHIVE' => $targets['managed-a']['archive'], 'RAN_WP_RELEASE_UPDATER_ORDINARY_ARCHIVE' => $targets['ordinary']['archive'], 'RAN_WP_RELEASE_UPDATER_MANAGED_B_ARCHIVE' => $targets['managed-b']['archive'] ) );
	$proof = json_decode( (string) file_get_contents( $output ), true, 64, JSON_THROW_ON_ERROR );
	$expect = array( 'managed-a/managed-a.php', 'ordinary/ordinary.php', 'managed-b/managed-b.php' );
	if ( ! is_array( $proof ) || $expect !== array_keys( $proof['results'] ?? array() ) || array( true, true, true ) !== array_values( $proof['results'] ?? array() ) || ! ( $proof['pass'] ?? false ) ) throw new RuntimeException( 'Mixed-bulk assertion failed: ' . substr( json_encode( $proof, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ), 0, 12000 ) );
	$result = array( 'marker' => $marker, 'status' => 'pass', 'evidence' => $proof );
} finally {
	if ( is_resource( $server ) ) { proc_terminate( $server, 15 ); for ( $i = 0; $i < 100 && proc_get_status( $server )['running']; ++$i ) usleep( 10000 ); proc_close( $server ); }
	if ( file_exists( $base ) ) { if ( is_link( $base ) || $base !== realpath( $base ) || ! is_file( $markerFile ) || $marker . "\n" !== file_get_contents( $markerFile ) ) throw new RuntimeException( 'Refusing unvalidated disposable proof cleanup.' ); removeTree( $base ); }
}
echo json_encode( $result, JSON_UNESCAPED_SLASHES ) . PHP_EOL;

function run( array $command, string $cwd, array $env = array() ): void { $p = proc_open( $command, array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes, $cwd, $env ); if ( ! is_resource( $p ) ) throw new RuntimeException( 'Could not run command.' ); fclose( $pipes[0] ); $out = stream_get_contents( $pipes[1] ) . stream_get_contents( $pipes[2] ); fclose( $pipes[1] ); fclose( $pipes[2] ); if ( 0 !== proc_close( $p ) ) throw new RuntimeException( substr( $out, 0, 8000 ) ); }
function connect( string $socket ): mysqli { for ( $i = 0; $i < 120; ++$i ) { $db = mysqli_init(); try { if ( mysqli_real_connect( $db, null, 'root', '', null, 0, $socket ) ) return $db; } catch ( mysqli_sql_exception ) {} usleep( 25000 ); } throw new RuntimeException( 'Could not connect to isolated MySQL.' ); }
function copyTree( string $source, string $destination, array $exclude ): void { mkdir( $destination, 0700, true ); $skip = array_flip( $exclude ); $it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $source, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::SELF_FIRST ); foreach ( $it as $f ) { $relative = substr( $f->getPathname(), strlen( $source ) + 1 ); if ( $f->isLink() || array_intersect( explode( '/', $relative ), array_keys( $skip ) ) ) continue; $to = $destination . '/' . $relative; if ( $f->isDir() ) mkdir( $to, 0700, true ); elseif ( ! is_dir( dirname( $to ) ) ) mkdir( dirname( $to ), 0700, true ); if ( $f->isFile() && ! copy( $f->getPathname(), $to ) ) throw new RuntimeException( 'Copy failed.' ); } }
function fixturePlugin( string $site, string $slug, string $name, string $uri, string $version ): void { $dir = $site . '/wp-content/plugins/' . $slug; mkdir( $dir, 0700, true ); file_put_contents( $dir . '/' . $slug . '.php', "<?php\n/*\nPlugin Name: {$name}\nVersion: {$version}\nUpdate URI: {$uri}\nRequires PHP: 8.2\nRequires at least: 6.8\n*/\n" ); }
function fixtureArchive( string $site, string $slug, string $name, string $uri ): string { $path = $site . '/wp-content/uploads/' . $slug . '-2.0.0.zip'; $zip = new ZipArchive(); if ( true !== $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) throw new RuntimeException( 'Archive create failed.' ); $zip->addFromString( $slug . '/' . $slug . '.php', "<?php\n/*\nPlugin Name: {$name}\nVersion: 2.0.0\nUpdate URI: {$uri}\nRequires PHP: 8.2\nRequires at least: 6.8\n*/\n" ); $zip->close(); return $path; }
function config( string $db, string $socket ): string { return "<?php\ndefine('DB_NAME', '{$db}'); define('DB_USER', 'root'); define('DB_PASSWORD', ''); define('DB_HOST', 'localhost:{$socket}'); define('DB_CHARSET','utf8'); define('DB_COLLATE','');\n" . "define('AUTH_KEY','x'); define('SECURE_AUTH_KEY','x'); define('LOGGED_IN_KEY','x'); define('NONCE_KEY','x'); define('AUTH_SALT','x'); define('SECURE_AUTH_SALT','x'); define('LOGGED_IN_SALT','x'); define('NONCE_SALT','x');\n\$table_prefix='wp_'; define('FS_METHOD','direct'); define('WP_DEBUG',false); require_once __DIR__ . '/wp-settings.php';\n"; }
function removeTree( string $path ): void { $it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST ); foreach ( $it as $f ) $f->isDir() ? rmdir( $f->getPathname() ) : unlink( $f->getPathname() ); rmdir( $path ); }
