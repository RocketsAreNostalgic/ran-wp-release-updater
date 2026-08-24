<?php

use RAN\WPReleaseUpdater\V1\Archive\TemporaryArtifact;
use RAN\WPReleaseUpdater\V1\Contract\BindingRecord;
use RAN\WPReleaseUpdater\V1\Contract\IdentityDescriptor;
use RAN\WPReleaseUpdater\V1\Contract\ReleaseAdapter;
use RAN\WPReleaseUpdater\V1\WordPress\NativePluginUpdater;

$sourceRoot = getenv( 'RAN_WP_RELEASE_UPDATER_SOURCE_ROOT' );
$markerFile = getenv( 'RAN_WP_RELEASE_UPDATER_MARKER_FILE' );
$markerRoot = is_string( $markerFile ) ? realpath( dirname( $markerFile ) ) : false;
$expectedSourceRoot = is_string( $markerRoot ) ? $markerRoot . '/site/wp-content/plugins/ran-wp-release-updater' : '';
if ( 'RAN_WP_RELEASE_UPDATER_MIXED_BULK' !== getenv( 'RAN_WP_RELEASE_UPDATER_MIXED_BULK' )
	|| ! is_string( $sourceRoot ) || $expectedSourceRoot !== realpath( $sourceRoot )
	|| ! is_file( (string) $markerFile ) || is_link( (string) $markerFile )
	|| "RAN_WP_RELEASE_UPDATER_MIXED_BULK\n" !== file_get_contents( (string) $markerFile ) ) {
	throw new RuntimeException( 'The mixed-bulk harness is not inside its owned disposable site.' );
}

require_once $expectedSourceRoot . '/bootstrap.php';
$activation = $GLOBALS['ran_wp_release_updater_v1_broker']->activate(
	array( 'php_version' => PHP_VERSION, 'runtime_protocol' => 1, 'wordpress_version' => $GLOBALS['wp_version'] )
);
if ( true !== ( $activation['loaded'] ?? null ) ) {
	throw new RuntimeException( 'The copied updater runtime could not be activated.' );
}

require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/misc.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/template.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
require_once ABSPATH . 'wp-admin/includes/class-plugin-upgrader.php';
require_once ABSPATH . 'wp-admin/includes/class-bulk-upgrader-skin.php';
require_once ABSPATH . 'wp-admin/includes/class-bulk-plugin-upgrader-skin.php';

final class MixedBulkFixtureAdapter implements ReleaseAdapter {
	public int $listCalls = 0;
	public int $inspectCalls = 0;
	public int $acquireCalls = 0;
	/** @var list<string> */
	public array $acquiredPaths = array();

	public function __construct( private IdentityDescriptor $descriptor, private string $archive ) {}

	public function listReleases( array $conditional = array() ): array {
		unset( $conditional );
		++$this->listCalls;
		$facts = $this->descriptor->toArray();
		return array( 'candidates' => array( array( 'release_identity' => $facts['release_identity'], 'tag' => $facts['tag'], 'version' => $facts['version'] ) ) );
	}

	public function inspect( string $releaseIdentity, ?string $expectedTag = null ): IdentityDescriptor {
		++$this->inspectCalls;
		$facts = $this->descriptor->toArray();
		if ( $facts['release_identity'] !== $releaseIdentity || $facts['tag'] !== $expectedTag ) {
			throw new RuntimeException( 'The fixture release identity changed.' );
		}
		return $this->descriptor;
	}

	public function acquire( IdentityDescriptor $descriptor ): TemporaryArtifact {
		++$this->acquireCalls;
		if ( $descriptor->fingerprintValue() !== $this->descriptor->fingerprintValue() ) {
			throw new RuntimeException( 'The fixture descriptor crossed target custody.' );
		}
		$path = tempnam( sys_get_temp_dir(), 'ran-mixed-bulk-artifact-' );
		if ( ! is_string( $path ) || ! copy( $this->archive, $path ) || ! chmod( $path, 0600 ) ) {
			throw new RuntimeException( 'The fixture artifact could not be acquired.' );
		}
		$this->acquiredPaths[] = $path;
		$stat = lstat( $path );
		if ( ! is_array( $stat ) ) {
			throw new RuntimeException( 'The fixture artifact identity is unavailable.' );
		}
		return new TemporaryArtifact(
			$path,
			hash_file( 'sha256', $path ),
			array( 'dev' => $stat['dev'], 'ino' => $stat['ino'], 'mode' => $stat['mode'], 'nlink' => $stat['nlink'], 'uid' => $stat['uid'], 'gid' => $stat['gid'], 'size' => $stat['size'], 'mtime' => $stat['mtime'], 'ctime' => $stat['ctime'] )
		);
	}
}

add_filter( 'filesystem_method', static fn (): string => 'direct' );
remove_action( 'upgrader_process_complete', 'wp_version_check' );
remove_action( 'upgrader_process_complete', 'wp_update_plugins' );
remove_action( 'upgrader_process_complete', 'wp_update_themes' );
$networkCalls = 0;
add_filter( 'pre_http_request', static function () use ( &$networkCalls ): WP_Error {
	++$networkCalls;
	return new WP_Error( 'mixed_bulk_network_forbidden', 'Network is forbidden in the mixed-bulk proof.' );
}, PHP_INT_MIN, 3 );

$identities = array( 'managed-a/managed-a.php', 'ordinary/ordinary.php', 'managed-b/managed-b.php' );
$beforeOwnedDirectories = glob( rtrim( sys_get_temp_dir(), '/\\' ) . '/ran-wp-release-updater-*', GLOB_ONLYDIR ) ?: array();
$managedA = build_mixed_bulk_target( 'managed-a', 'Managed A', (string) getenv( 'RAN_WP_RELEASE_UPDATER_MANAGED_A_ARCHIVE' ) );
$managedB = build_mixed_bulk_target( 'managed-b', 'Managed B', (string) getenv( 'RAN_WP_RELEASE_UPDATER_MANAGED_B_ARCHIVE' ) );
$observations = array_fill_keys( $identities, array() );
add_filter( 'upgrader_pre_download', static function ( mixed $reply, string $package, mixed $upgrader, array $extra ) use ( &$observations ): mixed {
	unset( $upgrader );
	$identity = $extra['plugin'] ?? null;
	if ( is_string( $identity ) && array_key_exists( $identity, $observations ) ) {
		$observations[ $identity ][] = $package;
	}
	return $reply;
}, PHP_INT_MIN, 4 );

$ordinaryArchive = (string) getenv( 'RAN_WP_RELEASE_UPDATER_ORDINARY_ARCHIVE' );
$transient = (object) array( 'last_checked' => time(), 'checked' => array(), 'response' => array() );
foreach ( get_plugins() as $file => $plugin ) {
	$transient->checked[ $file ] = $plugin['Version'];
}
foreach ( array( $managedA, $managedB ) as $target ) {
	$offer = $target['offer'];
	$offer['new_version'] = $offer['version'];
	$transient->response[ $target['identity'] ] = (object) $offer;
}
$transient->response['ordinary/ordinary.php'] = (object) array(
	'id' => 'https://mixed-bulk.invalid/ordinary/repository', 'slug' => 'ordinary', 'plugin' => 'ordinary/ordinary.php',
	'new_version' => '2.0.0', 'version' => '2.0.0', 'package' => $ordinaryArchive,
	'url' => 'https://mixed-bulk.invalid/ordinary/repository', 'requires' => '6.8', 'requires_php' => '8.2',
);
set_site_transient( 'update_plugins', $transient, 60 );

$upgrader = new Plugin_Upgrader( new Bulk_Plugin_Upgrader_Skin() );
$rawResults = $upgrader->bulk_upgrade( $identities, array( 'clear_update_cache' => false ) );
$results = array();
foreach ( is_array( $rawResults ) ? $rawResults : array() as $identity => $oneResult ) {
	$results[ $identity ] = false !== $oneResult && ! is_wp_error( $oneResult );
}
$versions = array();
$active = array();
foreach ( $identities as $identity ) {
	$data = get_plugin_data( WP_PLUGIN_DIR . '/' . $identity, false, false );
	$versions[ $identity ] = $data['Version'] ?? null;
	$active[ $identity ] = is_plugin_active( $identity );
}

$outputPath = (string) getenv( 'RAN_WP_RELEASE_UPDATER_MIXED_BULK_OUTPUT' );
add_action( 'shutdown', static function () use ( $outputPath, $identities, $results, $versions, $active, $observations, $ordinaryArchive, $managedA, $managedB, $beforeOwnedDirectories, &$networkCalls ): void {
	global $wpdb;
	$afterOwnedDirectories = glob( rtrim( sys_get_temp_dir(), '/\\' ) . '/ran-wp-release-updater-*', GLOB_ONLYDIR ) ?: array();
	$newOwnedDirectories = array_values( array_diff( $afterOwnedDirectories, $beforeOwnedDirectories ) );
	$adapterEvidence = array();
	$updaterEvidence = array();
	foreach ( array( 'managed-a' => $managedA, 'managed-b' => $managedB ) as $slug => $target ) {
		$adapter = $target['adapter'];
		$adapterEvidence[ $slug ] = array(
			'calls' => array( $adapter->listCalls, $adapter->inspectCalls, $adapter->acquireCalls ),
			'acquired_paths_absent' => array_reduce( $adapter->acquiredPaths, static fn ( bool $ok, string $path ): bool => $ok && ! file_exists( $path ) && ! is_link( $path ), true ),
		);
		$updaterEvidence[ $slug ] = array( 'diagnostics' => $target['updater']->diagnostics(), 'failure_code' => $target['updater']->status()['failure_code'] );
	}
	$tokens = array( $managedA['offer']['package'], $managedB['offer']['package'] );
	$observationEvidence = array(
		'managed_a_exact_token' => 1 === count( $observations['managed-a/managed-a.php'] ) && $tokens[0] === ( $observations['managed-a/managed-a.php'][0] ?? null ),
		'ordinary_direct_archive' => 1 === count( $observations['ordinary/ordinary.php'] ) && $ordinaryArchive === ( $observations['ordinary/ordinary.php'][0] ?? null ),
		'managed_b_exact_token' => 1 === count( $observations['managed-b/managed-b.php'] ) && $tokens[1] === ( $observations['managed-b/managed-b.php'][0] ?? null ),
		'managed_tokens_distinct' => $tokens[0] !== $tokens[1],
	);
	$coordinatorValues = $wpdb->get_col( "SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE 'ran\\_wp\\_release\\_updater\\_target\\_v1\\_%' ORDER BY option_name" );
	$coordinatorRows = is_array( $coordinatorValues ) ? count( $coordinatorValues ) : -1;
	$leasesReleased = 2 === $coordinatorRows;
	foreach ( is_array( $coordinatorValues ) ? $coordinatorValues : array() as $value ) {
		$decoded = is_string( $value ) ? json_decode( $value, true ) : null;
		$leasesReleased = $leasesReleased && is_array( $decoded ) && 1 === ( $decoded['lease_deadline'] ?? null );
	}
	$pass = $identities === array_keys( $results )
		&& array( true, true, true ) === array_values( $results )
		&& array_fill_keys( $identities, '2.0.0' ) === $versions
		&& array_fill_keys( $identities, true ) === $active
		&& ! in_array( false, $observationEvidence, true )
		&& ! str_starts_with( $ordinaryArchive, 'ran-wp-release-updater:v1:' )
		&& array( 1, 2, 2 ) === $adapterEvidence['managed-a']['calls']
		&& array( 1, 2, 2 ) === $adapterEvidence['managed-b']['calls']
		&& $adapterEvidence['managed-a']['acquired_paths_absent']
		&& $adapterEvidence['managed-b']['acquired_paths_absent']
		&& in_array( 'update_completed', $updaterEvidence['managed-a']['diagnostics'], true )
		&& in_array( 'update_completed', $updaterEvidence['managed-b']['diagnostics'], true )
		&& null === $updaterEvidence['managed-a']['failure_code']
		&& null === $updaterEvidence['managed-b']['failure_code']
		&& $leasesReleased && 0 === $networkCalls && array() === $newOwnedDirectories
		&& ! is_file( ABSPATH . '.maintenance' )
		&& ! is_dir( WP_CONTENT_DIR . '/upgrade-temp-backup/plugins/managed-a' )
		&& ! is_dir( WP_CONTENT_DIR . '/upgrade-temp-backup/plugins/ordinary' )
		&& ! is_dir( WP_CONTENT_DIR . '/upgrade-temp-backup/plugins/managed-b' );
	$evidence = compact( 'pass', 'results', 'versions', 'active', 'observationEvidence', 'adapterEvidence', 'updaterEvidence', 'coordinatorRows', 'leasesReleased', 'networkCalls', 'newOwnedDirectories' );
	file_put_contents( $outputPath, json_encode( $evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) );
}, PHP_INT_MAX );

/** @return array{identity:string,offer:array<string,mixed>,adapter:MixedBulkFixtureAdapter,updater:NativePluginUpdater} */
function build_mixed_bulk_target( string $slug, string $name, string $archive ): array {
	global $wpdb;
	$identity = $slug . '/' . $slug . '.php';
	$uri = 'https://mixed-bulk.invalid/' . $slug . '/repository';
	$repositoryIdentity = 'repo-' . $slug;
	$locator = 'fixture/' . $slug;
	$descriptor = IdentityDescriptor::create( array(
		'artifact_filename' => basename( $archive ), 'artifact_identity' => 'asset-' . $slug,
		'artifact_sha256' => hash_file( 'sha256', $archive ), 'artifact_size' => filesize( $archive ),
		'assurance_facts' => array( 'exact_artifact_identity' => true, 'exact_commit_identity' => true, 'exact_reacquisition_supported' => true, 'exact_release_identity' => true, 'provenance_verified' => true, 'publication_immutable' => true, 'repository_identity_stable' => true, 'trusted_digest_source' => true ),
		'canonical_update_uri' => $uri, 'channel' => 'stable', 'commit_identity' => 'commit-' . $slug,
		'installed_package_identity' => $identity, 'prerelease' => false, 'provider_code' => 'fixture',
		'release_identity' => 'release-' . $slug, 'repository_identity' => $repositoryIdentity,
		'repository_locator' => $locator, 'tag' => 'v2.0.0', 'target_type' => 'plugin', 'version' => '2.0.0',
	) );
	$binding = BindingRecord::create( array(
		'canonical_repository_locator' => $locator, 'canonical_update_uri' => $uri,
		'installed_package_identity' => $identity, 'php_runtime_version' => '8.2', 'provider_code' => 'fixture',
		'release_channel' => 'stable', 'stable_repository_identity' => $repositoryIdentity,
		'target_type' => 'plugin', 'update_policy' => 'manual', 'wordpress_runtime_version' => '6.8',
	) );
	$adapter = new MixedBulkFixtureAdapter( $descriptor, $archive );
	$headers = array( 'Author' => 'Fixture', 'Description' => 'Mixed bulk fixture', 'Name' => $name, 'PluginURI' => $uri, 'RequiresPHP' => '8.2', 'RequiresWP' => '6.8', 'UpdateURI' => $uri, 'Version' => '1.0.0' );
	$updater = NativePluginUpdater::fromConfiguration(
		array( 'headers' => $headers, 'installed_package_identity' => $identity, 'policy' => 'manual', 'target_type' => 'plugin', 'update_uri' => $uri ),
		$binding,
		$adapter,
		$wpdb,
		array( 'archive_root' => $slug, 'configuration_update_uri' => $uri, 'header_file' => $slug . '.php', 'installed_package_identity' => $identity, 'metadata_name' => $name, 'offer_update_uri' => $uri, 'php_runtime_version' => '8.2', 'provider_code' => 'fixture', 'repository_identity' => $repositoryIdentity, 'repository_locator' => $locator, 'staged_package_update_uri' => $uri, 'target_type' => 'plugin', 'wordpress_runtime_version' => '6.8' )
	);
	if ( ! $updater instanceof NativePluginUpdater ) {
		throw new RuntimeException( 'The managed mixed-bulk target could not be constructed.' );
	}
	$updater->register();
	$offer = apply_filters( 'update_plugins_' . parse_url( $uri, PHP_URL_HOST ), false, array( 'Version' => '1.0.0', 'UpdateURI' => $uri ), $identity, array() );
	if ( ! is_array( $offer ) || ! is_string( $offer['package'] ?? null ) || ! str_starts_with( $offer['package'], 'ran-wp-release-updater:v1:' ) ) {
		throw new RuntimeException( 'The managed mixed-bulk offer is invalid.' );
	}
	return array( 'identity' => $identity, 'offer' => $offer, 'adapter' => $adapter, 'updater' => $updater );
}
