<?php

declare(strict_types=1);

namespace Tests\Documentation;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class ReleaseSourceContractDocumentationTest extends TestCase {

	public function test_issue44_public_contract_clarifications_remain_documented(): void {
		$root            = dirname( __DIR__, 2 );
		$readme          = file_get_contents( $root . '/README.md' );
		$integration     = file_get_contents( $root . '/docs/integration.md' );
		$release_sources = file_get_contents( $root . '/docs/release-sources.md' );

		self::assertIsString( $readme );
		self::assertIsString( $integration );
		self::assertIsString( $release_sources );

		foreach ( array( $readme, $integration ) as $release_guide ) {
			self::assertStringContainsString(
				'`MAJOR.MINOR.PATCH` or `vMAJOR.MINOR.PATCH` form',
				$release_guide
			);
			self::assertStringContainsString(
				'prerelease suffix subset such as `1.2.3-beta.1` or `v1.2.3-beta.1`',
				$release_guide
			);
			self::assertStringContainsString(
				'build metadata (`+...`) is not supported',
				$release_guide
			);
		}
		self::assertStringContainsString(
			'could not establish, retain, or verify the persistent target fence',
			$integration
		);
		self::assertStringContainsString(
			'competing ownership and storage/CAS/database-time failures',
			$integration
		);

		self::assertStringContainsString(
			'`channel` accepts `stable` or `prerelease` and defaults to `stable`',
			$release_sources
		);
		self::assertStringContainsString(
			'`credentials` is an optional request-local callable returning a token string or `null` and defaults to anonymous access',
			$release_sources
		);
		self::assertStringContainsString(
			'`maximum_artifact_bytes` is a positive compressed-ZIP byte ceiling and defaults to 52,428,800 bytes',
			$release_sources
		);
		self::assertStringContainsString(
			'`release_identity` and `tag`',
			$release_sources
		);
		self::assertStringContainsString(
			'A successful inspection returns the opaque `fingerprint`',
			$release_sources
		);
		self::assertStringContainsString(
			'downloads and validates the full ZIP',
			$release_sources
		);
		self::assertStringContainsString(
			'discards those inspection bytes synchronously',
			$release_sources
		);
		self::assertStringContainsString(
			'A later `acquire()` performs a fresh download',
			$release_sources
		);
	}

	public function test_release_source_optional_argument_defaults_match_the_public_bootstrap(): void {
		$registrar  = require dirname( __DIR__, 2 ) . '/bootstrap.php';
		$parameters = ( new ReflectionMethod( $registrar, 'releases' ) )->getParameters();

		self::assertSame( 'channel', $parameters[4]->getName() );
		self::assertSame( 'stable', $parameters[4]->getDefaultValue() );
		self::assertSame( 'credentials', $parameters[5]->getName() );
		self::assertNull( $parameters[5]->getDefaultValue() );
		self::assertSame( 'maximum_artifact_bytes', $parameters[6]->getName() );
		self::assertSame( 52_428_800, $parameters[6]->getDefaultValue() );
	}
}
