<?php

use RAN\WPReleaseUpdater\V1\Archive\TemporaryArtifact;
use RAN\WPReleaseUpdater\V1\Contract\BindingRecord;
use RAN\WPReleaseUpdater\V1\Contract\IdentityDescriptor;
use RAN\WPReleaseUpdater\V1\Contract\ReleaseAdapter;
use RAN\WPReleaseUpdater\V1\WordPress\NativePackageUpdater;
$source_root          = getenv( 'RAN_WP_RELEASE_UPDATER_SOURCE_ROOT' );
$marker_file          = getenv( 'RAN_WP_RELEASE_UPDATER_MARKER_FILE' );
$marker_root          = is_string( $marker_file ) ? realpath( dirname( $marker_file ) ) : false;
$expected_source_root = is_string( $marker_root ) ? $marker_root . '/site/wp-content/plugins/ran-wp-release-updater' : '';
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read exact local bytes for the disposable integration fixture without requiring WordPress filesystem initialization.
if ( 'RAN_WP_RELEASE_UPDATER_MIXED_BULK' !== getenv( 'RAN_WP_RELEASE_UPDATER_MIXED_BULK' ) || ! is_string( $source_root ) || realpath( $source_root ) !== $expected_source_root || ! is_file( (string) $marker_file ) || is_link( (string) $marker_file ) || "RAN_WP_RELEASE_UPDATER_MIXED_BULK\n" !== file_get_contents( (string) $marker_file ) ) {
	throw new RuntimeException( 'The mixed-bulk harness is not inside its owned disposable site.' );
}
$type = (string) getenv( 'RAN_WP_RELEASE_UPDATER_BULK_TYPE' );
$mode = (string) getenv( 'RAN_WP_RELEASE_UPDATER_BULK_MODE' );
if ( ! in_array( $type, array( 'plugin', 'theme' ), true ) || ! in_array( $mode, array( 'success', 'failure' ), true ) ) {
	throw new RuntimeException( 'Invalid mixed-bulk scenario.' );
}
require_once $expected_source_root . '/bootstrap.php';
$activation = $GLOBALS['ran_wp_release_updater_v1_broker']->activate(
	array(
		'php_version'       => PHP_VERSION,
		'runtime_protocol'  => 1,
		'wordpress_version' => $GLOBALS['wp_version'],
	)
);
if ( true !== ( $activation['loaded'] ?? null ) ) {
	throw new RuntimeException( 'The copied updater runtime could not be activated.' );
}
foreach ( array( 'file.php', 'misc.php', 'plugin.php', 'template.php', 'class-wp-upgrader.php', 'class-plugin-upgrader.php', 'class-theme-upgrader.php', 'class-bulk-upgrader-skin.php', 'class-bulk-plugin-upgrader-skin.php', 'class-bulk-theme-upgrader-skin.php' ) as $file ) {
	require_once ABSPATH . 'wp-admin/includes/' . $file;
}
final class MixedBulkFixtureAdapter implements ReleaseAdapter {
	public int $list_calls    = 0;
	public int $inspect_calls = 0;
	public int $acquire_calls = 0;
	/** @var list<string> */
	public array $acquired_paths = array();
	public function __construct( private IdentityDescriptor $descriptor, private string $archive ) {
	}
	public function list_releases( array $conditional = array() ): array {
		unset( $conditional );
		++$this->list_calls;
		$facts = $this->descriptor->to_array();
		return array(
			'candidates' => array(
				array(
					'release_identity' => $facts['release_identity'],
					'tag'              => $facts['tag'],
					'version'          => $facts['version'],
				),
			),
		);
	}
	public function inspect( string $release_identity, ?string $expected_tag = null ): IdentityDescriptor {
		++$this->inspect_calls;
		$facts = $this->descriptor->to_array();
		if ( $facts['release_identity'] !== $release_identity || $facts['tag'] !== $expected_tag ) {
			throw new RuntimeException( 'The fixture release identity changed.' );
		}
		return $this->descriptor;
	}
	public function acquire( IdentityDescriptor $descriptor ): TemporaryArtifact {
		++$this->acquire_calls;
		if ( $descriptor->fingerprint_value() !== $this->descriptor->fingerprint_value() ) {
			throw new RuntimeException( 'The fixture descriptor crossed target custody.' );
		}
		$path = tempnam( sys_get_temp_dir(), 'ran-mixed-bulk-artifact-' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Set real fixture permission bits for archive custody and permission-boundary checks.
		if ( ! is_string( $path ) || ! copy( $this->archive, $path ) || ! chmod( $path, 0600 ) ) {
			throw new RuntimeException( 'The fixture artifact could not be acquired.' );
		}
		$this->acquired_paths[] = $path;
		$stat                   = lstat( $path );
		if ( ! is_array( $stat ) ) {
			throw new RuntimeException( 'The fixture artifact identity is unavailable.' );
		}
		return new TemporaryArtifact(
			$path,
			hash_file( 'sha256', $path ),
			array(
				'dev'   => $stat['dev'],
				'ino'   => $stat['ino'],
				'mode'  => $stat['mode'],
				'nlink' => $stat['nlink'],
				'uid'   => $stat['uid'],
				'gid'   => $stat['gid'],
				'size'  => $stat['size'],
				'mtime' => $stat['mtime'],
				'ctime' => $stat['ctime'],
			)
		);
	}
}
add_filter( 'filesystem_method', static fn (): string => 'direct' );
remove_action( 'upgrader_process_complete', 'wp_version_check' );
remove_action( 'upgrader_process_complete', 'wp_update_plugins' );
remove_action( 'upgrader_process_complete', 'wp_update_themes' );
$network_calls = 0;
add_filter(
	'pre_http_request',
	static function () use ( &$network_calls ): WP_Error {
		++$network_calls;
		return new WP_Error( 'mixed_bulk_network_forbidden', 'Network is forbidden in the mixed-bulk proof.' );
	},
	PHP_INT_MIN,
	3
);
$ids                        = 'plugin' === $type ? array( 'managed-a/managed-a.php', 'ordinary/ordinary.php', 'managed-b/managed-b.php' ) : array( 'managed-a', 'ordinary', 'managed-b' );
$archives                   = array(
	'managed-a' => (string) getenv( 'RAN_WP_RELEASE_UPDATER_MANAGED_A_ARCHIVE' ),
	'ordinary'  => (string) getenv( 'RAN_WP_RELEASE_UPDATER_ORDINARY_ARCHIVE' ),
	'managed-b' => (string) getenv( 'RAN_WP_RELEASE_UPDATER_MANAGED_B_ARCHIVE' ),
);
$expected_archive_manifests = array(
	'managed-a' => archive_file_manifest( $archives['managed-a'], 'managed-a' ),
	'managed-b' => archive_file_manifest( $archives['managed-b'], 'managed-b' ),
);
$before_owned_directories   = glob( rtrim( sys_get_temp_dir(), '/\\' ) . '/ran-wp-release-updater-*', GLOB_ONLYDIR ) ?: array();
$managed_a                  = build_mixed_bulk_target( $type, 'managed-a', 'Managed A', $archives['managed-a'] );
$managed_b                  = build_mixed_bulk_target( $type, 'managed-b', 'Managed B', $archives['managed-b'] );
$observations               = array_fill_keys( $ids, array() );
add_filter(
	'upgrader_pre_download',
	static function ( mixed $reply, string $package, mixed $upgrader, array $extra ) use ( $type, &$observations ): mixed {
		unset( $upgrader );
		$identity = $extra[ 'plugin' === $type ? 'plugin' : 'theme' ] ?? null;
		if ( is_string( $identity ) && array_key_exists( $identity, $observations ) ) {
			$observations[ $identity ][] = $package;
		}

		return $reply;
	},
	PHP_INT_MIN,
	4
);
prime_mixed_bulk_transient( $type, $ids, $managed_a, $managed_b, $archives['ordinary'] );
$before_bytes = array();
foreach ( $ids as $identity ) {
	$before_bytes[ $identity ] = target_bytes( $type, $identity );
}
$active_before = active_states( $type, $ids );
$injected      = array(
	'post_copy_seen'    => false,
	'destination_bytes' => null,
	'backup_present'    => false,
);
if ( 'failure' === $mode ) {
	add_filter(
		'upgrader_post_install',
		static function ( mixed $response, array $extra, array $install ) use ( $type, $ids, &$injected ): mixed {
			$key = 'plugin' === $type ? 'plugin' : 'theme';
			if ( ( $extra[ $key ] ?? null ) !== $ids[0] || ! is_array( $install ) ) {
				return $response;
			}

			$injected['post_copy_seen']    = true;
			$injected['destination_bytes'] = target_bytes( $type, $ids[0] );
			$injected['backup_present']    = is_dir( WP_CONTENT_DIR . '/upgrade-temp-backup/' . ( 'plugin' === $type ? 'plugins/' : 'themes/' ) . ( 'plugin' === $type ? dirname( $ids[0] ) : $ids[0] ) );
			return new WP_Error( 'mixed_bulk_injected_post_copy_failure', 'Injected after Core copied the failing target.' );
		},
		PHP_INT_MAX,
		3
	);
}
$upgrader     = 'plugin' === $type ? new Plugin_Upgrader( new Bulk_Plugin_Upgrader_Skin() ) : new Theme_Upgrader( new Bulk_Theme_Upgrader_Skin() );
$raw_results  = $upgrader->bulk_upgrade( $ids, array( 'clear_update_cache' => false ) );
$results      = array();
$result_codes = array();
foreach ( $ids as $identity ) {
	$one                       = is_array( $raw_results ) ? ( $raw_results[ $identity ] ?? false ) : false;
	$results[ $identity ]      = false !== $one && ! is_wp_error( $one );
	$result_codes[ $identity ] = is_wp_error( $one ) ? $one->get_error_code() : null;
}
$output_path = (string) getenv( 'RAN_WP_RELEASE_UPDATER_MIXED_BULK_OUTPUT' );
add_action(
	'shutdown',
	static function () use ( $output_path, $type, $mode, $ids, $results, $result_codes, $active_before, $before_bytes, $injected, $observations, $archives, $expected_archive_manifests, $managed_a, $managed_b, $before_owned_directories, &$network_calls ): void {
		global $wpdb;
		$after_owned_directories = glob( rtrim( sys_get_temp_dir(), '/\\' ) . '/ran-wp-release-updater-*', GLOB_ONLYDIR ) ?: array();
		$new_owned_directories   = array_values( array_diff( $after_owned_directories, $before_owned_directories ) );
		$versions                = array();
		foreach ( $ids as $identity ) {
			$versions[ $identity ] = target_version( $type, $identity );
		}
		$active            = active_states( $type, $ids );
		$adapter_evidence  = array();
		$updater_evidence  = array();
		$manifest_evidence = array();
		foreach ( array(
			'managed-a' => $managed_a,
			'managed-b' => $managed_b,
		) as $slug => $target ) {
			$adapter                    = $target['adapter'];
			$adapter_evidence[ $slug ]  = array(
				'calls'                 => array( $adapter->list_calls, $adapter->inspect_calls, $adapter->acquire_calls ),
				'acquired_paths_absent' => array_reduce( $adapter->acquired_paths, static fn ( bool $ok, string $path ): bool => $ok && ! file_exists( $path ) && ! is_link( $path ), true ),
			);
			$updater_evidence[ $slug ]  = array(
				'diagnostics'  => $target['updater']->diagnostics(),
				'failure_code' => $target['updater']->status()['failure_code'],
			);
			$installed_manifest         = target_file_manifest( $type, $target['identity'] );
			$expected_manifest          = $expected_archive_manifests[ $slug ] ?? array();
			$manifest_evidence[ $slug ] = array(
				'expected'    => $expected_manifest,
				'installed'   => $installed_manifest,
				'exact_match' => $expected_manifest === $installed_manifest,
			);
		}
		$tokens               = array( $managed_a['offer']['package'], $managed_b['offer']['package'] );
		$observation_evidence = array(
			'managed_a_exact_token'   => 1 === count( $observations[ $ids[0] ] ) && ( $observations[ $ids[0] ][0] ?? null ) === $tokens[0],
			'ordinary_direct_archive' => 1 === count( $observations[ $ids[1] ] ) && ( $observations[ $ids[1] ][0] ?? null ) === $archives['ordinary'],
			'managed_b_exact_token'   => 1 === count( $observations[ $ids[2] ] ) && ( $observations[ $ids[2] ][0] ?? null ) === $tokens[1],
			'managed_tokens_distinct' => $tokens[0] !== $tokens[1],
		);
		$values               = $wpdb->get_col( "SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE 'ran\\_wp\\_release\\_updater\\_target\\_v1\\_%' ORDER BY option_name" );
		$leases_released      = 2 === count( $values );
		foreach ( $values as $value ) {
			$decoded         = is_string( $value ) ? json_decode( $value, true ) : null;
			$leases_released = $leases_released && is_array( $decoded ) && 1 === ( $decoded['lease_deadline'] ?? null );
		}
		$bytes_after = array();
		foreach ( $ids as $identity ) {
			$bytes_after[ $identity ] = target_bytes( $type, $identity );
		}
		$backup_root                  = WP_CONTENT_DIR . '/upgrade-temp-backup/' . ( 'plugin' === $type ? 'plugins' : 'themes' );
		$backups_absent               = ! is_dir( $backup_root . '/managed-a' ) && ! is_dir( $backup_root . '/ordinary' ) && ! is_dir( $backup_root . '/managed-b' );
		$success                      = 'success' === $mode;
		$expected_results             = $success ? array( true, true, true ) : array( false, true, true );
		$failure_exact                = ! $success && 'ran_wp_release_updater_unverified_install_result' === $result_codes[ $ids[0] ] && null === $result_codes[ $ids[1] ] && null === $result_codes[ $ids[2] ];
		$manifest_exact               = $success ? ( $manifest_evidence['managed-a']['exact_match'] && $manifest_evidence['managed-b']['exact_match'] ) : $manifest_evidence['managed-b']['exact_match'];
		$pass                         = array_keys( $results ) === $ids
			&& array_values( $results ) === $expected_results
			&& ! in_array( false, $observation_evidence, true )
			&& $manifest_exact
			&& ! str_starts_with( $archives['ordinary'], 'ran-wp-release-updater:v1:' )
			&& array( 1, 2, 2 ) === $adapter_evidence['managed-a']['calls']
			&& array( 1, 2, 2 ) === $adapter_evidence['managed-b']['calls']
			&& $adapter_evidence['managed-a']['acquired_paths_absent']
			&& $adapter_evidence['managed-b']['acquired_paths_absent']
			&& $leases_released
			&& 0 === $network_calls
			&& array() === $new_owned_directories
			&& ! is_file( ABSPATH . '.maintenance' )
			&& $backups_absent
			&& $active_before === $active
			&& ( $success
				? array_fill_keys( $ids, '2.0.0' ) === $versions
					&& in_array( 'update_completed', $updater_evidence['managed-a']['diagnostics'], true )
					&& in_array( 'update_completed', $updater_evidence['managed-b']['diagnostics'], true )
					&& null === $updater_evidence['managed-a']['failure_code']
					&& null === $updater_evidence['managed-b']['failure_code']
				: $failure_exact
					&& $injected['post_copy_seen']
					&& '2.0.0' === target_header_version_from_bytes( $type, (string) $injected['destination_bytes'] )
					&& $injected['backup_present']
					&& $before_bytes[ $ids[0] ] === $bytes_after[ $ids[0] ]
					&& array( '1.0.0', '2.0.0', '2.0.0' ) === array_values( $versions )
					&& in_array( 'unverified_install_result', $updater_evidence['managed-a']['diagnostics'], true )
					&& 'unverified_install_result' === $updater_evidence['managed-a']['failure_code']
					&& in_array( 'update_completed', $updater_evidence['managed-b']['diagnostics'], true )
			);
		$evidence                     = array(
			'pass'                => $pass,
			'type'                => $type,
			'mode'                => $mode,
			'results'             => $results,
			'resultCodes'         => $result_codes,
			'versions'            => $versions,
			'activeBefore'        => $active_before,
			'active'              => $active,
			'injected'            => $injected,
			'observationEvidence' => $observation_evidence,
			'adapterEvidence'     => $adapter_evidence,
			'updaterEvidence'     => $updater_evidence,
			'leasesReleased'      => $leases_released,
			'networkCalls'        => $network_calls,
			'newOwnedDirectories' => $new_owned_directories,
			'bytesAfter'          => $bytes_after,
		);
		$evidence['manifestEvidence'] = $manifest_evidence;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write exact bytes for the disposable integration fixture; WordPress helpers would alter the boundary under test.
		file_put_contents( $output_path, json_encode( $evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) );
	},
	PHP_INT_MAX
);
/** @return array{identity:string,offer:array<string,mixed>,adapter:MixedBulkFixtureAdapter,updater:NativePackageUpdater} */
function build_mixed_bulk_target( string $type, string $slug, string $name, string $archive ): array {
	global $wpdb;
	$identity            = 'plugin' === $type ? $slug . '/' . $slug . '.php' : $slug;
	$uri                 = 'https://mixed-bulk.invalid/' . $slug . '/repository';
	$repository_identity = 'repo-' . $type . '-' . $slug;
	$locator             = 'fixture/' . $type . '/' . $slug;
	$descriptor          = IdentityDescriptor::create(
		array(
			'artifact_filename'          => basename( $archive ),
			'artifact_identity'          => 'asset-' . $type . '-' . $slug,
			'artifact_sha256'            => hash_file( 'sha256', $archive ),
			'artifact_size'              => filesize( $archive ),
			'assurance_facts'            => array(
				'exact_artifact_identity'       => true,
				'exact_commit_identity'         => true,
				'exact_reacquisition_supported' => true,
				'exact_release_identity'        => true,
				'provenance_verified'           => true,
				'publication_immutable'         => true,
				'repository_identity_stable'    => true,
				'trusted_digest_source'         => true,
			),
			'canonical_update_uri'       => $uri,
			'channel'                    => 'stable',
			'commit_identity'            => 'commit-' . $type . '-' . $slug,
			'installed_package_identity' => $identity,
			'prerelease'                 => false,
			'provider_code'              => 'fixture',
			'release_identity'           => 'release-' . $type . '-' . $slug,
			'repository_identity'        => $repository_identity,
			'repository_locator'         => $locator,
			'tag'                        => 'v2.0.0',
			'target_type'                => $type,
			'version'                    => '2.0.0',
		)
	);
	$binding             = BindingRecord::create(
		array(
			'canonical_repository_locator' => $locator,
			'canonical_update_uri'         => $uri,
			'installed_package_identity'   => $identity,
			'maximum_artifact_bytes'       => 52_428_800,
			'network_id'                   => 1,
			'php_runtime_version'          => '8.2',
			'provider_code'                => 'fixture',
			'release_channel'              => 'stable',
			'stable_repository_identity'   => $repository_identity,
			'target_type'                  => $type,
			'theme_template'               => '',
			'update_policy'                => 'manual',
			'wordpress_runtime_version'    => '6.8',
		)
	);
	$adapter             = new MixedBulkFixtureAdapter( $descriptor, $archive );
	$headers             = array(
		'Author'      => 'Fixture',
		'Description' => 'Mixed bulk fixture',
		'Name'        => $name,
		'PluginURI'   => $uri,
		'RequiresPHP' => '8.2',
		'RequiresWP'  => '6.8',
		'UpdateURI'   => $uri,
		'Version'     => '1.0.0',
	);
	$updater             = NativePackageUpdater::from_configuration(
		array(
			'headers'                    => $headers,
			'installed_package_identity' => $identity,
			'policy'                     => 'manual',
			'target_type'                => $type,
			'update_uri'                 => $uri,
		),
		$binding,
		$adapter,
		$wpdb,
		array(
			'archive_root'               => $slug,
			'configuration_update_uri'   => $uri,
			'header_file'                => 'plugin' === $type ? $slug . '.php' : 'style.css',
			'installed_package_identity' => $identity,
			'maximum_artifact_bytes'     => 52_428_800,
			'metadata_name'              => $name,
			'offer_update_uri'           => $uri,
			'php_runtime_version'        => '8.2',
			'provider_code'              => 'fixture',
			'repository_identity'        => $repository_identity,
			'repository_locator'         => $locator,
			'staged_package_update_uri'  => $uri,
			'target_type'                => $type,
			'theme_template'             => '',
			'wordpress_runtime_version'  => '6.8',
		)
	);
	if ( ! $updater instanceof NativePackageUpdater ) {
		throw new RuntimeException( 'The managed mixed-bulk target could not be constructed.' );
	}
	$updater->register();
	$offer = apply_filters(
		( 'plugin' === $type ? 'update_plugins_' : 'update_themes_' ) . parse_url( $uri, PHP_URL_HOST ),
		false,
		array(
			'Version'   => '1.0.0',
			'UpdateURI' => $uri,
		),
		$identity,
		array()
	);
	if ( ! is_array( $offer ) || ! is_string( $offer ['package'] ?? null ) || ! str_starts_with( $offer ['package'], 'ran-wp-release-updater:v1:' ) ) {
		throw new RuntimeException( 'The managed mixed-bulk offer is invalid.' );
	}
	return array(
		'identity' => $identity,
		'offer'    => $offer,
		'adapter'  => $adapter,
		'updater'  => $updater,
	);
}
function target_install_path( string $type, string $identity ): string {
	return 'plugin' === $type ? WP_PLUGIN_DIR . '/' . dirname( $identity ) : WP_CONTENT_DIR . '/themes/' . $identity;
}
function prime_mixed_bulk_transient( string $type, array $ids, array $managed_a, array $managed_b, string $ordinary_archive ): void {
	$transient = (object) array(
		'last_checked' => time(),
		'checked'      => array(),
		'response'     => array(),
	);
	if ( 'plugin' === $type ) {
		foreach ( get_plugins() as $file => $plugin ) {
			$transient->checked [ $file ] = $plugin ['Version'];
		}
	} else {
		foreach ( wp_get_themes() as $slug => $theme ) {
			$transient->checked [ $slug ] = $theme->get( 'Version' );
		}
	}
	foreach ( array( $managed_a, $managed_b ) as $target ) {
		$offer                                        = $target ['offer'];
		$offer ['new_version']                        = $offer ['version'];
		$transient->response [ $target ['identity'] ] = 'plugin' === $type ? (object) $offer : $offer;
	}
	$transient->response [ $ids [1] ] = 'plugin' === $type ? (object) array(
		'id'           => 'https://mixed-bulk.invalid/ordinary/repository',
		'slug'         => 'ordinary',
		'plugin'       => $ids [1],
		'new_version'  => '2.0.0',
		'version'      => '2.0.0',
		'package'      => $ordinary_archive,
		'url'          => 'https://mixed-bulk.invalid/ordinary/repository',
		'requires'     => '6.8',
		'requires_php' => '8.2',
	) : array(
		'theme'        => $ids [1],
		'new_version'  => '2.0.0',
		'version'      => '2.0.0',
		'package'      => $ordinary_archive,
		'url'          => 'https://mixed-bulk.invalid/ordinary/repository',
		'requires'     => '6.8',
		'requires_php' => '8.2',
	);
	set_site_transient( 'plugin' === $type ? 'update_plugins' : 'update_themes', $transient, 60 );
}
function target_path( string $type, string $identity ): string {
	return 'plugin' === $type ? target_install_path( $type, $identity ) . '/' . basename( $identity ) : target_install_path( $type, $identity ) . '/style.css';
}
function archive_file_manifest( string $archive, string $root ): array {
	$zip = new \ZipArchive();
	if ( true !== $zip->open( $archive ) ) {
		throw new RuntimeException( 'The fixture archive could not be inspected for manifest.' );
	}
	$manifest = array();
	// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- ZipArchive exposes this native property name.
	$entry_count = $zip->numFiles;
	if ( ! is_int( $entry_count ) ) {
		throw new RuntimeException( 'The fixture archive manifest could not be enumerated.' );
	}
	$root_prefix = $root . '/';
	for ( $i = 0; $i < $entry_count; ++$i ) {
		$name = $zip->getNameIndex( $i );
		if ( ! is_string( $name ) || '/' === substr( $name, -1 ) ) {
			continue;
		}
		if ( ! str_starts_with( $name, $root_prefix ) ) {
			continue;
		}
		$normalized = substr( $name, strlen( $root_prefix ) );
		if ( '' === $normalized ) {
			continue;
		}
		$bytes = $zip->getFromIndex( $i );
		if ( ! is_string( $bytes ) ) {
			throw new RuntimeException( 'The fixture archive manifest contains unreadable content.' );
		}
		$manifest[ $normalized ] = hash( 'sha256', $bytes );
	}
	$zip->close();
	ksort( $manifest );
	return $manifest;
}
function target_file_manifest( string $type, string $identity ): array {
	$base = target_install_path( $type, $identity );
	if ( ! is_dir( $base ) ) {
		return array();
	}
	$manifest   = array();
	$iterator   = new \RecursiveIteratorIterator(
		new \RecursiveDirectoryIterator( $base, \FilesystemIterator::SKIP_DOTS )
	);
	$base_posix = str_replace( '\\', '/', $base . '/' );
	foreach ( $iterator as $file ) {
		if ( ! $file->isFile() ) {
			continue;
		}
		$full_path = str_replace( '\\', '/', $file->getPathname() );
		$relative  = str_starts_with( $full_path, $base_posix ) ? substr( $full_path, strlen( $base_posix ) ) : null;
		if ( ! is_string( $relative ) || '' === $relative ) {
			continue;
		}
		$manifest[ $relative ] = hash_file( 'sha256', $full_path );
		if ( ! is_string( $manifest[ $relative ] ) ) {
			throw new RuntimeException( 'The fixture installed-manifest could not read every file.' );
		}
	}
	ksort( $manifest );
	return $manifest;
}
function target_bytes( string $type, string $identity ): string {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read exact local bytes for the disposable integration fixture without requiring WordPress filesystem initialization.
	return (string) file_get_contents( target_path( $type, $identity ) );
}
function target_version( string $type, string $identity ): ?string {
	return target_header_version_from_bytes( $type, target_bytes( $type, $identity ) );
}
function active_states( string $type, array $ids ): array {
	$active = array();
	foreach ( $ids as $identity ) {
		$active [ $identity ] = 'plugin' === $type ? is_plugin_active( $identity ) : get_stylesheet() === $identity;
	}
	return $active;
}
function target_header_version_from_bytes( string $type, string $bytes ): ?string {
	return 1 === preg_match( '/^Version:\s*(.+)$/mi', $bytes, $matches ) ? trim( $matches [1] ) : null;
}
