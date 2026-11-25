<?php

declare(strict_types=1);

namespace RAN\WPReleaseUpdater\V1\Tests\WordPress;

use PHPUnit\Framework\TestCase;
use RAN\WPReleaseUpdater\V1\Contract\IdentityDescriptor;
use RAN\WPReleaseUpdater\V1\WordPress\OwnedArchiveStore;

final class OwnedArchiveStoreTest extends TestCase {

	public function test_identity_rejects_symlink_before_hashing(): void {
		$target     = tempnam( sys_get_temp_dir(), 'ran-owned-target-' );
		$link_path  = sys_get_temp_dir() . '/ran-owned-link-' . bin2hex( random_bytes( 8 ) );
		$script     = tempnam( sys_get_temp_dir(), 'ran-owned-symlink-probe-' );
		$facts_file = tempnam( sys_get_temp_dir(), 'ran-owned-symlink-facts-' );
		self::assertIsString( $target );
		self::assertIsString( $script );
		self::assertIsString( $facts_file );
		try {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write exact bytes for archive custody and identity fixtures; WordPress helpers would alter the boundary under test.
			file_put_contents( $target, 'owned-archive-payload' );
			self::assertTrue( symlink( $target, $link_path ) );
			$sha256 = hash_file( 'sha256', $target );
			$size   = filesize( $target );
			self::assertIsString( $sha256 );
			self::assertIsInt( $size );
			$descriptor = IdentityDescriptor::create(
				array(
					'artifact_filename'          => 'package.zip',
					'artifact_identity'          => 'asset:1',
					'artifact_sha256'            => $sha256,
					'artifact_size'              => $size,
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
					'canonical_update_uri'       => 'https://updates.example.test/owner/package',
					'channel'                    => 'stable',
					'commit_identity'            => 'commit:1',
					'installed_package_identity' => 'example-plugin/example-plugin.php',
					'prerelease'                 => false,
					'provider_code'              => 'neutral',
					'release_identity'           => 'release:1',
					'repository_identity'        => 'repo:1',
					'repository_locator'         => 'owner/package',
					'tag'                        => 'v1.0.0',
					'target_type'                => 'plugin',
					'version'                    => '1.0.0',
				)
			);
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Encode exact fixture facts for an isolated process; WordPress JSON filters must not alter fixture bytes.
			$encoded = json_encode(
				array(
					'bootstrap'  => dirname( __DIR__ ) . '/bootstrap.php',
					'descriptor' => $descriptor->to_array(),
					'path'       => $link_path,
				),
				JSON_THROW_ON_ERROR
			);
			$fixture = <<<'PHP'
<?php
namespace RAN\WPReleaseUpdater\V1\WordPress {
	function mkdir( string $directory, int $permissions = 0777, bool $recursive = false, mixed $context = null ): bool {
		++$GLOBALS['ran_owned_store_mkdir_calls'];
		return null === $context ? \mkdir( $directory, $permissions, $recursive ) : \mkdir( $directory, $permissions, $recursive, $context );
	}
	function fopen( string $filename, string $mode, bool $use_include_path = false, mixed $context = null ): mixed {
		++$GLOBALS['ran_owned_store_fopen_calls'];
		return null === $context ? \fopen( $filename, $mode, $use_include_path ) : \fopen( $filename, $mode, $use_include_path, $context );
	}
	function hash_init( string $algo, int $flags = 0, string $key = '', array $options = array() ): \HashContext {
		++$GLOBALS['ran_owned_store_hash_calls'];
		return \hash_init( $algo, $flags, $key, $options );
	}
	function hash_file( string $algorithm, string $filename, bool $binary = false ): string|false {
		++$GLOBALS['ran_owned_store_hash_calls'];
		return \hash_file( $algorithm, $filename, $binary );
	}
}
namespace {
	$ran_facts = json_decode( (string) file_get_contents( $argv[1] ), true, 64, JSON_THROW_ON_ERROR );
	require $ran_facts['bootstrap'];
	$GLOBALS['ran_owned_store_mkdir_calls'] = 0;
	$GLOBALS['ran_owned_store_fopen_calls'] = 0;
	$GLOBALS['ran_owned_store_hash_calls']  = 0;
	$ran_allocate_calls                     = 0;
	$ran_store                              = new \RAN\WPReleaseUpdater\V1\WordPress\OwnedArchiveStore();
	$ran_descriptor                         = \RAN\WPReleaseUpdater\V1\Contract\IdentityDescriptor::rehydrate( $ran_facts['descriptor'] );
	$ran_identity                           = $ran_store->stat_identity( $ran_facts['path'], $ran_descriptor );
	$ran_copy                               = $ran_store->copy(
		$ran_facts['path'],
		$ran_descriptor,
		static function ( string $path, string $directory ) use ( &$ran_allocate_calls ): void {
			unset( $path, $directory );
			++$ran_allocate_calls;
		}
	);
	echo json_encode(
		array(
			'allocate_calls' => $ran_allocate_calls,
			'copy'           => $ran_copy,
			'fopen_calls'    => $GLOBALS['ran_owned_store_fopen_calls'],
			'hash_calls'     => $GLOBALS['ran_owned_store_hash_calls'],
			'identity'       => $ran_identity,
			'mkdir_calls'    => $GLOBALS['ran_owned_store_mkdir_calls'],
		),
		JSON_THROW_ON_ERROR
	);
}
PHP;
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write isolated subprocess script for native hash interception verification.
			file_put_contents( $script, $fixture );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write exact fixture input bytes for the native subprocess.
			file_put_contents( $facts_file, $encoded );
			$output = array();
			$status = 0;
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- A separate native PHP process isolates the namespaced hash_file test seam from production and other test symbol worlds.
			exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $script ) . ' ' . escapeshellarg( $facts_file ), $output, $status );
			self::assertSame( 0, $status, implode( "\n", $output ) );
			$result = json_decode( implode( "\n", $output ), true, 64, JSON_THROW_ON_ERROR );
			self::assertIsArray( $result );
			self::assertNull( $result['identity'] );
			self::assertNull( $result['copy'] );
			self::assertSame( 0, $result['allocate_calls'] );
			self::assertSame( 0, $result['mkdir_calls'] );
			self::assertSame( 0, $result['fopen_calls'] );
			self::assertSame( 0, $result['hash_calls'] );
		} finally {
			foreach ( array( $link_path, $target, $script, $facts_file ) as $cleanup_path ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- Best-effort fixture teardown tolerates paths already removed by the scenario.
				@unlink( $cleanup_path );
			}
		}
	}

	public function test_copy_and_remove_cleans_up_read_only_archive_without_chmodding_symlinks(): void {
		$source      = tempnam( sys_get_temp_dir(), 'ran-owned-src-' );
		$target      = tempnam( sys_get_temp_dir(), 'ran-owned-symlink-target-' );
		$symlink_dir = sys_get_temp_dir() . '/ran-owned-link-dir-' . bin2hex( random_bytes( 8 ) );
		$link        = $symlink_dir . DIRECTORY_SEPARATOR . 'package.zip';
		self::assertIsString( $source );
		self::assertIsString( $target );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create an owned-shape directory for symlink cleanup testing.
		mkdir( $symlink_dir, 0700, true );
		try {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write exact fixture bytes for read-only cleanup verification.
			file_put_contents( $source, 'owned-archive-payload' );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write exact fixture bytes for symlink target preservation verification.
			file_put_contents( $target, 'keep-permissions' );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Set exact read-only permissions on the symlink target to prove remove() does not alter it.
			chmod( $target, 0400 );
			self::assertTrue( symlink( $target, $link ) );

			$sha256 = hash_file( 'sha256', $source );
			$size   = filesize( $source );
			self::assertIsString( $sha256 );
			self::assertIsInt( $size );
			$descriptor = IdentityDescriptor::create(
				array(
					'artifact_filename'          => 'package.zip',
					'artifact_identity'          => 'asset:1',
					'artifact_sha256'            => $sha256,
					'artifact_size'              => $size,
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
					'canonical_update_uri'       => 'https://updates.example.test/owner/package',
					'channel'                    => 'stable',
					'commit_identity'            => 'commit:1',
					'installed_package_identity' => 'example-plugin/example-plugin.php',
					'prerelease'                 => false,
					'provider_code'              => 'neutral',
					'release_identity'           => 'release:1',
					'repository_identity'        => 'repo:1',
					'repository_locator'         => 'owner/package',
					'tag'                        => 'v1.0.0',
					'target_type'                => 'plugin',
					'version'                    => '1.0.0',
				)
			);

			$store = new OwnedArchiveStore();
			$copy  = $store->copy( $source, $descriptor );
			self::assertIsArray( $copy );
			$identity = $store->stat_identity( $copy['path'], $descriptor );
			self::assertIsArray( $identity );
			self::assertTrue( $store->same_stat_identity( $copy['path'], $descriptor, $identity ) );

			$store->remove( $copy['path'], $copy['directory'] );
			self::assertFileDoesNotExist( $copy['path'] );
			self::assertDirectoryDoesNotExist( $copy['directory'] );

			$store->remove( $link, $symlink_dir );
			self::assertFalse( is_link( $link ) );
			self::assertDirectoryDoesNotExist( $symlink_dir );
			clearstatcache( true, $target );
			$target_stat = lstat( $target );
			self::assertIsArray( $target_stat );
			self::assertSame( 0400, (int) $target_stat['mode'] & 0777 );
		} finally {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Restore writable mode before best-effort fixture teardown.
			@chmod( $target, 0600 );
			foreach ( array( $link, $source, $target ) as $cleanup_path ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- Best-effort fixture teardown tolerates paths already removed by the scenario.
				@unlink( $cleanup_path );
			}
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Best-effort fixture teardown.
			@rmdir( $symlink_dir );
		}
	}
}
