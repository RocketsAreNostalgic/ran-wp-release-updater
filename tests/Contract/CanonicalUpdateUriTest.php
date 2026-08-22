<?php

declare(strict_types=1);

namespace Tests\Contract;

use PHPUnit\Framework\TestCase;
use RAN\WPReleaseUpdater\V1\Contract\CanonicalUpdateUri;

final class CanonicalUpdateUriTest extends TestCase {

	/**
	 * @dataProvider canonicalUris
	 */
	public function testCanonicalizesOnlySchemeAndHostAndRemovesOneTerminalSlash(
		string $uri,
		string $expected
	): void {
		self::assertSame( $expected, CanonicalUpdateUri::canonicalize( $uri ) );
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function canonicalUris(): array {
		return array(
			'scheme and host case' => array(
				'HTTPS://Updates.Example.test/Release/Plugin',
				'https://updates.example.test/Release/Plugin',
			),
			'one terminal slash' => array(
				'https://updates.example.test/Release/Plugin/',
				'https://updates.example.test/Release/Plugin',
			),
		);
	}

	/**
	 * @dataProvider invalidUris
	 */
	public function testRejectsNonCanonicalV1Inputs( string $uri ): void {
		self::assertNull( CanonicalUpdateUri::canonicalize( $uri ) );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function invalidUris(): array {
		return array(
			'leading whitespace' => array( ' https://updates.example.test/release' ),
			'trailing whitespace' => array( "https://updates.example.test/release\n" ),
			'control byte' => array( "https://updates.example.test/re\x01lease" ),
			'non ascii' => array( 'https://updates.example.test/rélease' ),
			'backslash' => array( 'https://updates.example.test\\release' ),
			'relative' => array( '/release' ),
			'non https' => array( 'http://updates.example.test/release' ),
			'invalid host' => array( 'https://-updates.example.test/release' ),
			'idna host' => array( 'https://xn--bcher-kva.example/release' ),
			'ip literal' => array( 'https://127.0.0.1/release' ),
			'short ip literal' => array( 'https://127.1/release' ),
			'shorter ip literal' => array( 'https://127.0.1/release' ),
			'integer ip literal' => array( 'https://2130706433/release' ),
			'hexadecimal ip literal' => array( 'https://0x7f000001/release' ),
			'trailing host dot' => array( 'https://updates.example.test./release' ),
			'userinfo' => array( 'https://user@updates.example.test/release' ),
			'port' => array( 'https://updates.example.test:443/release' ),
			'query' => array( 'https://updates.example.test/release?channel=stable' ),
			'fragment' => array( 'https://updates.example.test/release#current' ),
			'root path' => array( 'https://updates.example.test/' ),
			'missing path' => array( 'https://updates.example.test' ),
			'percent encoding' => array( 'https://updates.example.test/re%6cease' ),
			'empty interior segment' => array( 'https://updates.example.test/release//plugin' ),
			'dot segment' => array( 'https://updates.example.test/release/./plugin' ),
			'dot dot segment' => array( 'https://updates.example.test/release/../plugin' ),
		);
	}

	public function testCanonicalizeEqualityMatchesEquivalentCaseAndTerminalSlash(): void {
		self::assertSame(
			CanonicalUpdateUri::canonicalize( 'HTTPS://Updates.Example.test/Release/Plugin/' ),
			CanonicalUpdateUri::canonicalize( 'https://updates.example.test/Release/Plugin' )
		);
	}

	public function testCanonicalizeComparisonRejectsDifferentUris(): void {
		self::assertNotSame(
			CanonicalUpdateUri::canonicalize( 'https://updates.example.test/Release/Plugin' ),
			CanonicalUpdateUri::canonicalize( 'https://updates.example.test/release/Plugin' )
		);
		self::assertNotSame(
			CanonicalUpdateUri::canonicalize( 'https://updates.example.test/Release/Plugin' ),
			CanonicalUpdateUri::canonicalize( 'http://updates.example.test/Release/Plugin' )
		);
	}

	public function testCanonicalizesOnlyTheClosedFourBoundaryTuple(): void {
		$boundaries = array(
			'configuration' => 'HTTPS://Updates.Example.test/Release/Plugin/',
			'offer_or_cache' => 'https://updates.example.test/Release/Plugin',
			'archive_preflight' => 'https://updates.example.test/Release/Plugin',
			'staged_package' => 'https://updates.example.test/Release/Plugin',
		);

		self::assertSame(
			'https://updates.example.test/Release/Plugin',
			CanonicalUpdateUri::canonicalizeBoundaries( $boundaries )
		);

		self::assertNull(
			CanonicalUpdateUri::canonicalizeBoundaries(
				array_merge( $boundaries, array( 'unexpected' => true ) )
			)
		);

		unset( $boundaries['staged_package'] );
		self::assertNull( CanonicalUpdateUri::canonicalizeBoundaries( $boundaries ) );
	}

	public function testFourBoundaryTupleRejectsAChangedPathOrInvalidValueType(): void {
		$boundaries = array(
			'configuration' => 'https://updates.example.test/Release/Plugin',
			'offer_or_cache' => 'https://updates.example.test/Release/Plugin',
			'archive_preflight' => 'https://updates.example.test/Release/Plugin',
			'staged_package' => 'https://updates.example.test/release/Plugin',
		);

		self::assertNull( CanonicalUpdateUri::canonicalizeBoundaries( $boundaries ) );

		$boundaries['staged_package'] = array();
		self::assertNull( CanonicalUpdateUri::canonicalizeBoundaries( $boundaries ) );
	}
}
