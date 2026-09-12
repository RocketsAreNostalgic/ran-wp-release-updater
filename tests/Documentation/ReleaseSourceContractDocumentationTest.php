<?php

declare(strict_types=1);

namespace Tests\Documentation;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class ReleaseSourceContractDocumentationTest extends TestCase {

	public function testIssue44PublicContractClarificationsRemainDocumented(): void {
		$root           = dirname( __DIR__, 2 );
		$integration    = file_get_contents( $root . '/docs/integration.md' );
		$releaseSources = file_get_contents( $root . '/docs/release-sources.md' );

		self::assertIsString( $integration );
		self::assertIsString( $releaseSources );

		self::assertStringContainsString(
			'`MAJOR.MINOR.PATCH` or `vMAJOR.MINOR.PATCH`',
			$integration
		);
		self::assertStringContainsString(
			'build metadata (`+...`) is not supported',
			$integration
		);
		self::assertStringContainsString(
			'could not establish, retain, or verify the persistent target fence',
			$integration
		);
		self::assertStringContainsString(
			'storage/CAS/database-time failures',
			$integration
		);

		foreach ( array( '`channel`', '`credentials`', '`maximumArtifactBytes`' ) as $argument ) {
			self::assertStringContainsString( $argument, $releaseSources );
		}
		self::assertStringContainsString(
			'`release_identity` and `tag`',
			$releaseSources
		);
		self::assertStringContainsString(
			'A successful inspection returns the opaque `fingerprint`',
			$releaseSources
		);
		self::assertStringContainsString(
			'downloads and validates the full ZIP',
			$releaseSources
		);
		self::assertStringContainsString(
			'A later `acquire()` performs a fresh download',
			$releaseSources
		);
	}

	public function testReleaseSourceOptionalArgumentDefaultsMatchThePublicBootstrap(): void {
		$registrar  = require dirname( __DIR__, 2 ) . '/bootstrap.php';
		$parameters = ( new ReflectionMethod( $registrar, 'releases' ) )->getParameters();

		self::assertSame( 'channel', $parameters[4]->getName() );
		self::assertSame( 'stable', $parameters[4]->getDefaultValue() );
		self::assertSame( 'credentials', $parameters[5]->getName() );
		self::assertNull( $parameters[5]->getDefaultValue() );
		self::assertSame( 'maximumArtifactBytes', $parameters[6]->getName() );
		self::assertSame( 52_428_800, $parameters[6]->getDefaultValue() );
	}
}
