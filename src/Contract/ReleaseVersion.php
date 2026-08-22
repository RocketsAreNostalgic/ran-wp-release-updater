<?php

declare(strict_types=1);

namespace RAN\WPReleaseUpdater\V1\Contract;

/** Canonical release-version parsing and ordering for discovery and headers. */
final class ReleaseVersion {

	public const MAX_LENGTH = 100;

	public const RELATIONSHIP_NEWER = 'newer';
	public const RELATIONSHIP_SAME = 'same';
	public const RELATIONSHIP_OLDER = 'older';
	public const RELATIONSHIP_INVALID = 'invalid';

	private const PATTERN = '/\A(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)'
		. '(?:-(?:0|[1-9]\d*|\d*[A-Za-z-][0-9A-Za-z-]*)'
		. '(?:\.(?:0|[1-9]\d*|\d*[A-Za-z-][0-9A-Za-z-]*))*)?\z/D';

	public static function normalize( string $version ): ?string {
		return strlen( $version ) <= self::MAX_LENGTH && 1 === preg_match( self::PATTERN, $version ) ? $version : null;
	}

	/** WordPress may use two-part stable headers; prereleases remain complete. */
	public static function normalizeHeader( string $version ): ?string {
		if ( strlen( $version ) > self::MAX_LENGTH ) return null;
		if ( 1 === preg_match( '/\A(0|[1-9]\d*)\.(0|[1-9]\d*)\z/D', $version, $matches ) ) return $matches[1] . '.' . $matches[2] . '.0';
		return self::normalize( $version );
	}

	public static function isPrerelease( string $version ): bool {
		return null !== self::normalize( $version ) && str_contains( $version, '-' );
	}

	/** @return -1|0|1|null */
	public static function compare( string $left, string $right ): ?int {
		$left = self::normalizeHeader( $left );
		$right = self::normalizeHeader( $right );
		if ( null === $left || null === $right ) return null;

		$leftParts = explode( '-', $left, 2 );
		$rightParts = explode( '-', $right, 2 );
		$leftCore = explode( '.', $leftParts[0] );
		$rightCore = explode( '.', $rightParts[0] );
		foreach ( array_keys( $leftCore ) as $index ) {
			$comparison = self::compareNumericIdentifier( $leftCore[ $index ], $rightCore[ $index ] );
			if ( 0 !== $comparison ) return $comparison;
		}

		$leftPrerelease = $leftParts[1] ?? null;
		$rightPrerelease = $rightParts[1] ?? null;
		if ( null === $leftPrerelease || null === $rightPrerelease ) return $leftPrerelease === $rightPrerelease ? 0 : ( null === $leftPrerelease ? 1 : -1 );

		$leftIdentifiers = explode( '.', $leftPrerelease );
		$rightIdentifiers = explode( '.', $rightPrerelease );
		$sharedCount = min( count( $leftIdentifiers ), count( $rightIdentifiers ) );
		for ( $index = 0; $index < $sharedCount; ++$index ) {
			$leftIdentifier = $leftIdentifiers[ $index ];
			$rightIdentifier = $rightIdentifiers[ $index ];
			$leftNumeric = 1 === preg_match( '/\A\d+\z/D', $leftIdentifier );
			$rightNumeric = 1 === preg_match( '/\A\d+\z/D', $rightIdentifier );
			$comparison = $leftNumeric && $rightNumeric
				? self::compareNumericIdentifier( $leftIdentifier, $rightIdentifier )
				: ( $leftNumeric || $rightNumeric ? ( $leftNumeric ? -1 : 1 ) : ( strcmp( $leftIdentifier, $rightIdentifier ) <=> 0 ) );
			if ( 0 !== $comparison ) return $comparison;
		}
		return count( $leftIdentifiers ) <=> count( $rightIdentifiers );
	}

	public static function relationship( string $candidate, string $baseline ): string {
		$comparison = self::compare( $candidate, $baseline );
		if ( null === $comparison ) return self::RELATIONSHIP_INVALID;
		if ( 0 === $comparison ) return self::RELATIONSHIP_SAME;
		return $comparison > 0 ? self::RELATIONSHIP_NEWER : self::RELATIONSHIP_OLDER;
	}

	/** @return -1|0|1 */
	private static function compareNumericIdentifier( string $left, string $right ): int {
		$lengthComparison = strlen( $left ) <=> strlen( $right );
		return 0 !== $lengthComparison ? $lengthComparison : ( strcmp( $left, $right ) <=> 0 );
	}
}
