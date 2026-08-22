<?php

declare(strict_types=1);

namespace Tests\Contract;

use PHPUnit\Framework\TestCase;
use RAN\WPReleaseUpdater\V1\Contract\ReleaseVersion;

final class ReleaseVersionTest extends TestCase {

	public function testAcceptedVersionOrderIsAntisymmetricAndTransitive(): void {
		$ordered = array( '0.0.1', '1.0.0-999999999999999999999999999999', '1.0.0-1000000000000000000000000000000', '1.0.0-alpha', '1.0.0-alpha.1', '1.0.0-alpha.beta', '1.0.0-beta', '1.0.0-beta.2', '1.0.0-beta.11', '1.0.0-rc.1', '1.0.0', '1.0.1-x.1', '1.0.1-y.1', '1.0.1', '1.1.0', '2.0.0' );
		foreach ( $ordered as $leftIndex => $left ) {
			self::assertSame( 0, ReleaseVersion::compare( $left, $left ) );
			foreach ( $ordered as $rightIndex => $right ) {
				if ( $leftIndex >= $rightIndex ) continue;
				self::assertSame( -1, ReleaseVersion::compare( $left, $right ) );
				self::assertSame( 1, ReleaseVersion::compare( $right, $left ) );
				self::assertSame( ReleaseVersion::RELATIONSHIP_OLDER, ReleaseVersion::relationship( $left, $right ) );
				self::assertSame( ReleaseVersion::RELATIONSHIP_NEWER, ReleaseVersion::relationship( $right, $left ) );
				foreach ( $ordered as $thirdIndex => $third ) if ( $rightIndex < $thirdIndex ) self::assertSame( -1, ReleaseVersion::compare( $left, $third ) );
			}
		}
	}

	public function testStableHeaderShorthandHasCanonicalEquality(): void {
		self::assertSame( 0, ReleaseVersion::compare( '2.1', '2.1.0' ) );
	}

	/** @dataProvider invalidComparisonProvider */
	public function testInvalidVersionsHaveOneFixedRelationship( string $version ): void {
		self::assertNull( ReleaseVersion::compare( $version, '1.0.0' ) );
		self::assertNull( ReleaseVersion::compare( '1.0.0', $version ) );
		self::assertSame( ReleaseVersion::RELATIONSHIP_INVALID, ReleaseVersion::relationship( $version, '1.0.0' ) );
	}

	/** @return array<string, array{string}> */
	public static function invalidComparisonProvider(): array {
		return array( 'build metadata' => array( '1.0.0+build.1' ), 'tag marker' => array( 'v1.0.0' ), 'short prerelease' => array( '1.0-beta.1' ), 'leading zero' => array( '01.0.0' ) );
	}
}
