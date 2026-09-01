<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class IntegrationProofSecurityContractTest extends TestCase {

	public function testDisposableProofsRequireAndValidateAnExplicitWordPressRoot(): void {
		foreach ( array( 'wordpress-native-mixed-bulk-proof.php', 'phase-2.4-wordpress-core-proof.php' ) as $script ) {
			$proof = $this->proof( $script );

			self::assertStringContainsString( "getenv( 'RAN_WP_RELEASE_UPDATER_LOCAL_WP_ROOT' )", $proof );
			self::assertStringContainsString( 'realpath( $wpRootInput )', $proof );
			self::assertStringNotContainsString( '?: $wpRoot', $proof );
			foreach ( array( '/wp-load.php', '/wp-settings.php', '/wp-includes/version.php' ) as $requiredFile ) {
				self::assertStringContainsString( $requiredFile, $proof );
			}
		}
	}

	public function testDisposableProofsKeepAdminPasswordsOutOfSourceAndProcessArguments(): void {
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
		return (string) file_get_contents( __DIR__ . '/Integration/' . $name );
	}
}
