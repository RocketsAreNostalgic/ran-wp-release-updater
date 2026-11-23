<?php

declare(strict_types=1);

namespace RAN\WPReleaseUpdater\V1\Tests\WordPress;

use PHPUnit\Framework\TestCase;
use RAN\WPReleaseUpdater\V1\Contract\IdentityDescriptor;

final class OwnedArchiveStoreTest extends TestCase {

	public function test_identity_rejects_symlink_before_hashing(): void {
		$target    = tempnam( sys_get_temp_dir(), 'ran-owned-target-' );
		$link_path = sys_get_temp_dir() . '/ran-owned-link-' . bin2hex( random_bytes( 8 ) );
		$script    = tempnam( sys_get_temp_dir(), 'ran-owned-symlink-probe-' );
		self::assertIsString( $target );
		self::assertIsString( $script );
		$facts_file = $script . '.json';
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
	function hash_file( string $algorithm, string $filename, bool $binary = false ): string|false {
		++$GLOBALS['ran_owned_store_hash_calls'];
		return \hash_file( $algorithm, $filename, $binary );
	}
}
namespace {
	$ran_facts = json_decode( (string) file_get_contents( $argv[1] ), true, 64, JSON_THROW_ON_ERROR );
	require $ran_facts['bootstrap'];
	$GLOBALS['ran_owned_store_hash_calls'] = 0;
	$ran_store                             = new \RAN\WPReleaseUpdater\V1\WordPress\OwnedArchiveStore();
	$ran_identity                          = $ran_store->identity(
		$ran_facts['path'],
		\RAN\WPReleaseUpdater\V1\Contract\IdentityDescriptor::rehydrate( $ran_facts['descriptor'] )
	);
	echo json_encode(
		array(
			'hash_calls' => $GLOBALS['ran_owned_store_hash_calls'],
			'identity'   => $ran_identity,
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
			self::assertSame( 0, $result['hash_calls'] );
		} finally {
			foreach ( array( $link_path, $target, $script, $facts_file ) as $cleanup_path ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- Best-effort fixture teardown tolerates paths already removed by the scenario.
				@unlink( $cleanup_path );
			}
		}
	}
}
