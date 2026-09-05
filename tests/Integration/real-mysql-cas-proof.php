<?php

declare(strict_types=1);

use RAN\WPReleaseUpdater\V1\Contract\BindingRecord;
use RAN\WPReleaseUpdater\V1\Contract\IdentityDescriptor;
use RAN\WPReleaseUpdater\V1\Contract\AcquisitionReceipt;
use RAN\WPReleaseUpdater\V1\Archive\PackageIdentityValidator;
use RAN\WPReleaseUpdater\V1\WordPress\ReleaseOperationCoordinator;
use RAN\WPReleaseUpdater\V1\WordPress\BindingState;
use Tests\Support\MysqliOptionDatabase;

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/Support/MysqliOptionDatabase.php';

mysqli_report( MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT );

if ( '--worker' === ( $argv[1] ?? null ) ) {
	worker( $argv );
	exit( 0 );
}

$root = createIsolatedRoot();
$data = $root . '/data';
$port = reserveLoopbackPort();
$pid = $root . '/mysqld.pid';
$mysqld = resolveMysqld();
if ( ! mkdir( $data, 0700, true ) ) throw new RuntimeException( 'Could not create isolated MySQL directory.' );
$server = null;
try {
	run( array( $mysqld, '--no-defaults', '--initialize-insecure', '--datadir=' . $data, '--socket=mysql.sock', '--tmpdir=' . $root, '--skip-mysqlx' ), $root );
	$server = proc_open(
		array(
			$mysqld,
			'--no-defaults',
			'--datadir=' . $data,
			'--bind-address=127.0.0.1',
			'--port=' . $port,
			'--pid-file=' . $pid,
			'--socket=mysql.sock',
			'--tmpdir=' . $root,
			'--skip-mysqlx',
			'--log-error=' . $root . '/mysqld.err',
		),
		array( 0 => array( 'pipe', 'r' ), 1 => array( 'file', $root . '/mysqld.out', 'a' ), 2 => array( 'file', $root . '/mysqld.err', 'a' ) ),
		$pipes,
		$root
	);
	if ( ! is_resource( $server ) ) throw new RuntimeException( 'Could not start isolated MySQL.' );
	$mysqli = attestServer( $server, $port, $data );
	$mysqli->query( 'CREATE DATABASE proof' );
	$mysqli->select_db( 'proof' );
	$mysqli->query( 'CREATE TABLE options (option_name varchar(191) NOT NULL PRIMARY KEY, option_value longtext NOT NULL, autoload varchar(20) NOT NULL) ENGINE=InnoDB' );
	$binding = BindingRecord::create( bindingFacts() );
	$cold = workers( $port, 'cold' );
	assertOneClaim( $cold, 'cold claim' );
	$winner = claimed( $cold );
	$winnerState = BindingState::rehydrate( $winner['state'] );
	list( $descriptor, $receipt ) = mintReceipt( $root . '/receipt.zip', $winnerState );
	$target = targetName( $binding );
	$rows = $mysqli->query( "SELECT option_name, autoload FROM options ORDER BY option_name" )->fetch_all( MYSQLI_ASSOC );
	if ( 1 !== count( $rows ) || 'no' !== $rows[0]['autoload'] ) throw new RuntimeException( 'Option was not one non-autoload row.' );
	$mysqli->query( "UPDATE options SET option_value = JSON_SET(option_value, '$.lease_deadline', 1) WHERE option_name = '" . $mysqli->real_escape_string( $target ) . "'" );
	$takeover = workers( $port, 'takeover' );
	assertOneClaim( $takeover, 'expired takeover' );
	$new = claimed( $takeover );
	if ( $winner['owner'] === $new['owner'] || $new['epoch'] <= $winner['epoch'] ) throw new RuntimeException( 'Takeover did not install a new owner and target fence epoch.' );
	$database = new MysqliOptionDatabase( connectProof( $port ), 'options' );
	$stale = ReleaseOperationCoordinator::verifyPersistentBindingState( $database, $winnerState, claim( $winner['state'] ) );
	$completion = ReleaseOperationCoordinator::completePersistentInstall( $database, $winnerState, claim( $winner['state'] ), $receipt, $descriptor );
	if ( 'binding_fence_lost' !== $stale['result'] || 'binding_fence_lost' !== $completion['result'] ) throw new RuntimeException( 'Stale writer or completion was not fenced.' );
	assertFinalRows( $database, $binding, $new );
	echo json_encode( array( 'mysqld_binary' => $mysqld, 'cold_claims' => $cold, 'expired_takeover' => $takeover, 'non_autoload_rows' => $rows, 'stale_writer' => $stale['result'], 'stale_completion' => $completion['result'] ), JSON_THROW_ON_ERROR ) . PHP_EOL;
} finally {
	if ( is_resource( $server ) ) stopServer( $server );
	removeTree( $root );
}

/** @param list<string> $argv */
function worker( array $argv ): void {
	$startAt = (int) $argv[5];
	while ( hrtime( true ) < $startAt ) usleep( 1000 );
	$database = new MysqliOptionDatabase( connectProof( (int) $argv[2] ), 'options' );
	$binding = BindingRecord::create( bindingFacts() );
	$owner = $argv[3];
	$result = ReleaseOperationCoordinator::claimPersistentBindingState( $database, $binding, $owner, 30 );
	$epoch = 0; $target = $database->get_var( $database->prepare( "SELECT option_value FROM {$database->options} WHERE option_name = %s LIMIT 1", targetName( $binding ) ) );
	if ( is_string( $target ) ) $epoch = json_decode( $target, true, 16, JSON_THROW_ON_ERROR )['fence_epoch'];
	echo json_encode( array( 'owner' => $owner, 'result' => $result['result'], 'state' => $result['current']?->toArray(), 'epoch' => $epoch ), JSON_THROW_ON_ERROR ) . PHP_EOL;
}

/** @return list<array{owner:string,result:string,state:array<string,mixed>|null,epoch:int}> */
function workers( int $port, string $scenario ): array {
	$processes = array();
	$startAt = hrtime( true ) + 500000000;
	$owners = 'takeover' === $scenario
		? array( str_repeat( 'c', 64 ), str_repeat( 'd', 64 ) )
		: array( str_repeat( 'a', 64 ), str_repeat( 'b', 64 ) );
	foreach ( $owners as $owner ) {
		$processes[] = proc_open(
			array( PHP_BINARY, __FILE__, '--worker', (string) $port, $owner, $scenario, (string) $startAt ),
			array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ),
			$pipes
		);
		$processes[array_key_last( $processes )] = array( 'process' => $processes[array_key_last( $processes )], 'pipes' => $pipes );
	}
	$results = array();
	foreach ( $processes as $entry ) { fclose( $entry['pipes'][0] ); $out = stream_get_contents( $entry['pipes'][1] ); $err = stream_get_contents( $entry['pipes'][2] ); fclose( $entry['pipes'][1] ); fclose( $entry['pipes'][2] ); $exit = proc_close( $entry['process'] ); if ( 0 !== $exit ) throw new RuntimeException( 'Worker failed with exit ' . $exit . '; stdout: ' . substr( $out, 0, 4096 ) . '; stderr: ' . substr( $err, 0, 4096 ) ); $results[] = json_decode( trim( $out ), true, 32, JSON_THROW_ON_ERROR ); }
	return $results;
}

function createIsolatedRoot(): string {
	$configured = getenv( 'RAN_UPDATER_MYSQL_ROOT' );
	if ( ! is_string( $configured ) || '' === $configured ) {
		throw new RuntimeException( 'Real MySQL CAS proof requires RAN_UPDATER_MYSQL_ROOT to name a durable directory.' );
	}
	if ( is_link( $configured ) || ! is_dir( $configured ) || ! is_writable( $configured ) ) {
		throw new RuntimeException( 'RAN_UPDATER_MYSQL_ROOT must be an existing, writable, non-symlink directory.' );
	}
	$parent = realpath( $configured );
	if ( false === $parent ) throw new RuntimeException( 'Could not resolve RAN_UPDATER_MYSQL_ROOT.' );
	$root = rtrim( $parent, '/\\' ) . '/ran-wp-release-updater-mysql-' . bin2hex( random_bytes( 8 ) );
	if ( ! mkdir( $root, 0700 ) ) throw new RuntimeException( 'Could not create isolated MySQL root.' );
	return $root;
}

function reserveLoopbackPort(): int {
	$listener = stream_socket_server( 'tcp://127.0.0.1:0', $errorNumber, $errorMessage );
	if ( false === $listener ) throw new RuntimeException( 'Could not reserve an isolated loopback port: ' . $errorMessage . ' (' . $errorNumber . ')' );
	$address = stream_socket_get_name( $listener, false );
	fclose( $listener );
	$separator = is_string( $address ) ? strrpos( $address, ':' ) : false;
	$port = false === $separator ? 0 : (int) substr( $address, $separator + 1 );
	if ( $port < 1 ) throw new RuntimeException( 'Could not determine an isolated loopback port.' );
	return $port;
}

function attestServer( $server, int $port, string $data ): mysqli {
	$status = proc_get_status( $server );
	if ( ! is_array( $status ) || ! $status['running'] ) throw new RuntimeException( 'Isolated MySQL stopped before attestation.' );
	$mysqli = connect( $port );
	try {
		$result = $mysqli->query( 'SELECT @@datadir AS datadir' );
		$row = $result->fetch_assoc();
		$expected = realpath( $data );
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

function connect( int $port ): mysqli {
	for ( $attempt = 0; $attempt < 100; ++$attempt ) {
		$mysqli = mysqli_init();
		$connected = false;
		try {
			$connected = $mysqli instanceof mysqli && mysqli_real_connect( $mysqli, '127.0.0.1', 'root', '', null, $port );
		} catch ( mysqli_sql_exception ) {}
		if ( $connected ) return $mysqli;
		if ( $mysqli instanceof mysqli ) $mysqli->close();
		usleep( 50000 );
	}
	throw new RuntimeException( 'Timed out connecting to isolated MySQL.' );
}

function connectProof( int $port ): mysqli {
	$mysqli = connect( $port );
	if ( ! $mysqli->select_db( 'proof' ) ) {
		$mysqli->close();
		throw new RuntimeException( 'Could not select isolated proof database.' );
	}
	return $mysqli;
}
function resolveMysqld(): string { $configured = getenv( 'RAN_UPDATER_MYSQLD_BIN' ); $candidate = is_string( $configured ) && '' !== $configured ? $configured : trim( (string) shell_exec( 'command -v mysqld 2>/dev/null' ) ); if ( ! is_file( $candidate ) || ! is_executable( $candidate ) ) throw new RuntimeException( 'Real MySQL CAS proof requires RAN_UPDATER_MYSQLD_BIN to name an executable mysqld binary, or mysqld on PATH; it will not use a running server.' ); return $candidate; }
/** @param list<array{owner:string,result:string,state:array<string,mixed>|null,epoch:int}> $results */
function assertOneClaim( array $results, string $label ): void { $winners = array_values( array_filter( $results, static fn ( array $result ): bool => 'claimed' === $result['result'] ) ); $losers = array_values( array_filter( $results, static fn ( array $result ): bool => 'binding_fence_lost' === $result['result'] ) ); if ( 2 !== count( $results ) || 1 !== count( $winners ) || 1 !== count( $losers ) || null === $winners[0]['state'] || $winners[0]['owner'] === $losers[0]['owner'] ) throw new RuntimeException( $label . ' did not have one owner and one binding_fence_lost non-owner.' ); }
/** @param list<array{owner:string,result:string,state:array<string,mixed>|null,epoch:int}> $results @return array{owner:string,result:string,state:array<string,mixed>|null,epoch:int} */
function claimed( array $results ): array { foreach ( $results as $result ) if ( 'claimed' === $result['result'] ) return $result; throw new RuntimeException( 'Missing claim winner.' ); }
/** @param array{owner:string,result:string,state:array<string,mixed>|null,epoch:int} $winner */
function assertFinalRows( MysqliOptionDatabase $database, BindingRecord $binding, array $winner ): void { $target = $database->get_var( $database->prepare( "SELECT option_value FROM {$database->options} WHERE option_name = %s LIMIT 1", targetName( $binding ) ) ); if ( ! is_string( $target ) || null === $winner['state'] ) throw new RuntimeException( 'Final coordinator row is missing.' ); if ( $winner['state'] !== json_decode( $target, true, 64, JSON_THROW_ON_ERROR ) ) throw new RuntimeException( 'Final self-contained state does not match the selected winner.' ); }
/** @param array<string,mixed> $facts */
function targetName( BindingRecord $binding ): string { $facts = $binding->toArray(); return 'ran_wp_release_updater_target_v1_' . BindingRecord::targetFenceKey( array( 'network_id' => $facts['network_id'], 'target_type' => $facts['target_type'], 'installed_package_identity' => $facts['installed_package_identity'] ) ); }
/** @param array<string,mixed> $state @return array<string,mixed> */
function claim( array $state ): array { return array( 'binding_generation' => $state['binding_generation'], 'binding_hash' => $state['binding']['binding_hash'], 'lease_deadline' => $state['lease_deadline'], 'owner_token' => $state['owner_token'] ); }
/** @return array<string,mixed> */
function bindingFacts(): array { return array( 'canonical_repository_locator' => 'fixture/repository', 'canonical_update_uri' => 'https://example.invalid/fixture/repository', 'installed_package_identity' => 'x/x.php', 'maximum_artifact_bytes' => 52_428_800, 'network_id' => 1, 'php_runtime_version' => '8.2', 'provider_code' => 'fixture', 'release_channel' => 'stable', 'stable_repository_identity' => 'fixture-repository:1', 'target_type' => 'plugin', 'theme_template' => '', 'update_policy' => 'manual', 'wordpress_runtime_version' => '6.8' ); }
/** @return array<string,mixed> */
function descriptorFacts(): array { return array( 'artifact_filename' => 'x.zip', 'artifact_identity' => 'fixture-asset:1', 'artifact_sha256' => str_repeat( 'a', 64 ), 'artifact_size' => 1, 'assurance_facts' => array( 'exact_artifact_identity' => true, 'exact_commit_identity' => true, 'exact_reacquisition_supported' => true, 'exact_release_identity' => true, 'provenance_verified' => true, 'publication_immutable' => true, 'repository_identity_stable' => true, 'trusted_digest_source' => true ), 'canonical_update_uri' => 'https://example.invalid/fixture/repository', 'channel' => 'stable', 'commit_identity' => 'fixture-commit:1', 'installed_package_identity' => 'x/x.php', 'prerelease' => false, 'provider_code' => 'fixture', 'release_identity' => 'fixture-release:42', 'repository_identity' => 'fixture-repository:1', 'repository_locator' => 'fixture/repository', 'tag' => 'v1', 'target_type' => 'plugin', 'version' => '1.0.0' ); }
/** @return array{IdentityDescriptor,AcquisitionReceipt} */
function mintReceipt( string $path, \RAN\WPReleaseUpdater\V1\WordPress\BindingState $state ): array { $zip = new ZipArchive(); if ( true !== $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) || ! $zip->addFromString( 'x/x.php', "<?php\n/*\nPlugin Name: Fixture\nVersion: 1.0.0\nUpdate URI: https://example.invalid/fixture/repository\n*/" ) ) throw new RuntimeException( 'Could not create proof receipt fixture.' ); $zip->close(); $facts = descriptorFacts(); $facts['artifact_sha256'] = hash_file( 'sha256', $path ); $facts['artifact_size'] = filesize( $path ); $descriptor = IdentityDescriptor::create( $facts ); $validator = new PackageIdentityValidator(); $package = $validator->validate( $descriptor, array( 'archive_root' => 'x', 'configuration_update_uri' => 'https://example.invalid/fixture/repository', 'header_file' => 'x.php', 'installed_package_identity' => 'x/x.php', 'maximum_artifact_bytes' => 52_428_800, 'metadata_name' => 'Fixture', 'offer_update_uri' => 'https://example.invalid/fixture/repository', 'php_runtime_version' => '8.2', 'provider_code' => 'fixture', 'repository_identity' => 'fixture-repository:1', 'repository_locator' => 'fixture/repository', 'staged_package_update_uri' => 'https://example.invalid/fixture/repository', 'target_type' => 'plugin', 'theme_template' => '', 'wordpress_runtime_version' => '6.8' ), $path ); if ( ! $package->isValid() ) throw new RuntimeException( 'Could not validate proof receipt fixture.' ); return array( $descriptor, AcquisitionReceipt::issue( $state, $descriptor, $validator, $package, time() ) ); }
/** @param list<string> $command */
function run( array $command, string $cwd ): void { $process = proc_open( $command, array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes, $cwd ); if ( ! is_resource( $process ) ) throw new RuntimeException( 'Could not initialize isolated MySQL.' ); fclose( $pipes[0] ); $stdout = stream_get_contents( $pipes[1] ); $stderr = stream_get_contents( $pipes[2] ); fclose( $pipes[1] ); fclose( $pipes[2] ); if ( 0 !== proc_close( $process ) ) throw new RuntimeException( 'MySQL initialization failed: ' . $stdout . $stderr ); }
function stopServer( $server ): void { proc_terminate( $server, 15 ); for ( $attempt = 0; $attempt < 100; ++$attempt ) { $status = proc_get_status( $server ); if ( ! $status['running'] ) { proc_close( $server ); return; } usleep( 50000 ); } proc_terminate( $server, 9 ); proc_close( $server ); }
function removeTree( string $path ): void { if ( ! is_dir( $path ) ) return; $iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST ); foreach ( $iterator as $entry ) { $entry->isDir() ? rmdir( $entry->getPathname() ) : unlink( $entry->getPathname() ); } rmdir( $path ); }
