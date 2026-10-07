<?php

declare(strict_types=1);

namespace RAN\WPReleaseUpdater\V1\Tests\Contract;

use PHPUnit\Framework\TestCase;
use RAN\WPReleaseUpdater\V1\Contract\CanonicalUpdateUri;

final class CanonicalUpdateUriTest extends TestCase {

	#[\PHPUnit\Framework\Attributes\DataProvider( 'canonical_uris' )]
	public function test_canonicalizes_only_scheme_and_host_and_removes_one_terminal_slash(
		string $uri,
		string $expected
	): void {
		self::assertSame( $expected, CanonicalUpdateUri::canonicalize( $uri ) );
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function canonical_uris(): array {
		return array(
			'scheme and host case' => array(
				'HTTPS://Updates.Example.test/Release/Plugin',
				'https://updates.example.test/Release/Plugin',
			),
			'one terminal slash'   => array(
				'https://updates.example.test/Release/Plugin/',
				'https://updates.example.test/Release/Plugin',
			),
		);
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'invalid_uris' )]
	public function test_rejects_non_canonical_v1_inputs( string $uri ): void {
		self::assertNull( CanonicalUpdateUri::canonicalize( $uri ) );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function invalid_uris(): array {
		return array(
			'leading whitespace'     => array( ' https://updates.example.test/release' ),
			'trailing whitespace'    => array( "https://updates.example.test/release\n" ),
			'control byte'           => array( "https://updates.example.test/re\x01lease" ),
			'non ascii'              => array( 'https://updates.example.test/rélease' ),
			'backslash'              => array( 'https://updates.example.test\\release' ),
			'relative'               => array( '/release' ),
			'non https'              => array( 'http://updates.example.test/release' ),
			'invalid host'           => array( 'https://-updates.example.test/release' ),
			'idna host'              => array( 'https://xn--bcher-kva.example/release' ),
			'ip literal'             => array( 'https://127.0.0.1/release' ),
			'short ip literal'       => array( 'https://127.1/release' ),
			'shorter ip literal'     => array( 'https://127.0.1/release' ),
			'integer ip literal'     => array( 'https://2130706433/release' ),
			'hexadecimal ip literal' => array( 'https://0x7f000001/release' ),
			'trailing host dot'      => array( 'https://updates.example.test./release' ),
			'userinfo'               => array( 'https://user@updates.example.test/release' ),
			'port'                   => array( 'https://updates.example.test:443/release' ),
			'query'                  => array( 'https://updates.example.test/release?channel=stable' ),
			'fragment'               => array( 'https://updates.example.test/release#current' ),
			'root path'              => array( 'https://updates.example.test/' ),
			'missing path'           => array( 'https://updates.example.test' ),
			'percent encoding'       => array( 'https://updates.example.test/re%6cease' ),
			'empty interior segment' => array( 'https://updates.example.test/release//plugin' ),
			'dot segment'            => array( 'https://updates.example.test/release/./plugin' ),
			'dot dot segment'        => array( 'https://updates.example.test/release/../plugin' ),
		);
	}

	public function test_canonicalize_equality_matches_equivalent_case_and_terminal_slash(): void {
		self::assertSame(
			CanonicalUpdateUri::canonicalize( 'HTTPS://Updates.Example.test/Release/Plugin/' ),
			CanonicalUpdateUri::canonicalize( 'https://updates.example.test/Release/Plugin' )
		);
	}

	public function test_canonicalize_comparison_rejects_different_uris(): void {
		self::assertNotSame(
			CanonicalUpdateUri::canonicalize( 'https://updates.example.test/Release/Plugin' ),
			CanonicalUpdateUri::canonicalize( 'https://updates.example.test/release/Plugin' )
		);
		self::assertNotSame(
			CanonicalUpdateUri::canonicalize( 'https://updates.example.test/Release/Plugin' ),
			CanonicalUpdateUri::canonicalize( 'http://updates.example.test/Release/Plugin' )
		);
	}

	public function test_canonicalizes_only_the_closed_four_boundary_tuple(): void {
		$boundaries = array(
			'configuration'     => 'HTTPS://Updates.Example.test/Release/Plugin/',
			'offer'             => 'https://updates.example.test/Release/Plugin',
			'archive_preflight' => 'https://updates.example.test/Release/Plugin',
			'staged_package'    => 'https://updates.example.test/Release/Plugin',
		);

		self::assertSame(
			'https://updates.example.test/Release/Plugin',
			CanonicalUpdateUri::canonicalize_boundaries( $boundaries )
		);

		self::assertNull(
			CanonicalUpdateUri::canonicalize_boundaries(
				array_merge( $boundaries, array( 'unexpected' => true ) )
			)
		);

		unset( $boundaries['staged_package'] );
		self::assertNull( CanonicalUpdateUri::canonicalize_boundaries( $boundaries ) );
	}

	public function test_four_boundary_tuple_rejects_a_changed_path_or_invalid_value_type(): void {
		$boundaries = array(
			'configuration'     => 'https://updates.example.test/Release/Plugin',
			'offer'             => 'https://updates.example.test/Release/Plugin',
			'archive_preflight' => 'https://updates.example.test/Release/Plugin',
			'staged_package'    => 'https://updates.example.test/release/Plugin',
		);

		self::assertNull( CanonicalUpdateUri::canonicalize_boundaries( $boundaries ) );

		$boundaries['staged_package'] = array();
		self::assertNull( CanonicalUpdateUri::canonicalize_boundaries( $boundaries ) );
	}
}
