<?php

declare(strict_types=1);

namespace RAN\WPReleaseUpdater\V1\Tests;

use PHPUnit\Framework\TestCase;

final class IntegrationProofSecurityContractTest extends TestCase {

	public function test_disposable_proofs_require_and_validate_an_explicit_word_press_root(): void {
		foreach ( array( 'wordpress-native-mixed-bulk-proof.php', 'phase-2.4-wordpress-core-proof.php' ) as $script ) {
			$proof = $this->proof( $script );

			self::assertStringContainsString( "getenv( 'RAN_WP_RELEASE_UPDATER_LOCAL_WP_ROOT' )", $proof );
			self::assertStringContainsString( 'realpath( $wp_root_input )', $proof );
			self::assertStringNotContainsString( '?: $wp_root', $proof );
			foreach ( array( '/wp-load.php', '/wp-settings.php', '/wp-includes/version.php' ) as $required_file ) {
				self::assertStringContainsString( $required_file, $proof );
			}
		}
	}

	public function test_disposable_proofs_keep_admin_passwords_out_of_source_and_process_arguments(): void {
		foreach ( array( 'wordpress-native-mixed-bulk-proof.php', 'phase-2.4-wordpress-core-proof.php' ) as $script ) {
			$proof = $this->proof( $script );

			self::assertStringNotContainsString( '--admin_password=', $proof );
			self::assertStringNotContainsString( 'password123!', $proof );
			self::assertStringContainsString( '--prompt=admin_password', $proof );
			self::assertStringContainsString( 'bin2hex( random_bytes( 32 ) )', $proof );
			self::assertStringContainsString( 'fwrite( $pipes[0], $stdin )', $proof );
			self::assertStringContainsString( 'proc_terminate(', $proof );
			self::assertStringContainsString( 'fclose( $pipes[1] );', $proof );
			self::assertStringContainsString( 'fclose( $pipes[2] );', $proof );
			self::assertStringContainsString( 'proc_close(', $proof );
		}
	}

	public function test_distribution_manifest_rejects_native_zip_symlink_attributes(): void {
		$source = $this->proof( 'wordpress-integration/distribution.php' );
		self::assertSame( 1, preg_match( '/function ran_wp_release_updater_test_archive_manifest\\(.*?^}/ms', $source, $matches ) );
		$function = str_replace( 'function ran_wp_release_updater_test_archive_manifest', 'static function', $matches[0] );
		// phpcs:ignore Squiz.PHP.Eval.Discouraged -- Execute only the exact repository-owned manifest helper in isolation, without WordPress bootstrap or fixture side effects.
		$reader = eval( 'namespace { return ' . $function . '; }' );
		self::assertInstanceOf( \Closure::class, $reader );
		$path = sys_get_temp_dir() . '/ran-release-manifest-' . bin2hex( random_bytes( 8 ) ) . '.zip';
		try {
			foreach ( array( 0100644, 0120777 ) as $mode ) {
				$archive = new \ZipArchive();
				self::assertTrue( $archive->open( $path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) );
				self::assertTrue( $archive->addFromString( 'package/file.php', 'fixture' ) );
				self::assertTrue( $archive->setExternalAttributesName( 'package/file.php', \ZipArchive::OPSYS_UNIX, $mode << 16 ) );
				self::assertTrue( $archive->close() );
				if ( 0100644 === $mode ) {
					self::assertSame(
						array(
							'file.php' => array(
								'sha256' => hash( 'sha256', 'fixture' ),
								'size'   => 7,
							),
						),
						$reader( $path, 'package' )
					);
				} else {
					try {
						$reader( $path, 'package' );
						self::fail( 'A non-directory ZIP symlink must not be treated as a regular entry.' );
					} catch ( \RuntimeException $error ) {
						self::assertSame( 'Consumer ZIP contains a non-regular entry.', $error->getMessage() );
					}
				}
			}
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only the private native ZIP fixture created by this test.
			unlink( $path );
		}
	}

	private function proof( string $name ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read exact local bytes for repository contract assertions without requiring WordPress filesystem initialization.
		return (string) file_get_contents( __DIR__ . '/Integration/' . $name );
	}
}
