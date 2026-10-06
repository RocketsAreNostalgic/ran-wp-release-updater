<?php

declare(strict_types=1);

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Existing Composer development namespace; this allowance does not apply to production declarations.
namespace Tests;

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

	private function proof( string $name ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read exact local bytes for repository contract assertions without requiring WordPress filesystem initialization.
		return (string) file_get_contents( __DIR__ . '/Integration/' . $name );
	}
}
