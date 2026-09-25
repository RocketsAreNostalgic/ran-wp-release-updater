<?php

declare(strict_types=1);

namespace RAN\WPReleaseUpdater\V1\Contract;

/** Canonical release-version parsing and ordering for discovery and headers. */
final class ReleaseVersion {

	public const MAX_LENGTH = 100;

	public const RELATIONSHIP_NEWER   = 'newer';
	public const RELATIONSHIP_SAME    = 'same';
	public const RELATIONSHIP_OLDER   = 'older';
	public const RELATIONSHIP_INVALID = 'invalid';

	private const PATTERN = '/\A(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)'
		. '(?:-(?:0|[1-9]\d*|\d*[A-Za-z-][0-9A-Za-z-]*)'
		. '(?:\.(?:0|[1-9]\d*|\d*[A-Za-z-][0-9A-Za-z-]*))*)?\z/D';

	public static function normalize( string $version ): ?string {
		return strlen( $version ) <= self::MAX_LENGTH && 1 === preg_match( self::PATTERN, $version ) ? $version : null;
	}

	/** WordPress may use two-part stable headers; prereleases remain complete. */
	public static function normalize_header( string $version ): ?string {
		if ( strlen( $version ) > self::MAX_LENGTH ) {
			return null;
		}
		if ( 1 === preg_match( '/\A(0|[1-9]\d*)\.(0|[1-9]\d*)\z/D', $version, $matches ) ) {
			return $matches[1] . '.' . $matches[2] . '.0';
		}
		return self::normalize( $version );
	}

	public static function is_prerelease( string $version ): bool {
		return null !== self::normalize( $version ) && str_contains( $version, '-' );
	}

	/** @return -1|0|1|null */
	public static function compare( string $left, string $right ): ?int {
		$left  = self::normalize_header( $left );
		$right = self::normalize_header( $right );
		if ( null === $left || null === $right ) {
			return null;
		}

		$left_parts  = explode( '-', $left, 2 );
		$right_parts = explode( '-', $right, 2 );
		$left_core   = explode( '.', $left_parts[0] );
		$right_core  = explode( '.', $right_parts[0] );
		foreach ( array_keys( $left_core ) as $index ) {
			$comparison = self::compare_numeric_identifier( $left_core[ $index ], $right_core[ $index ] );
			if ( 0 !== $comparison ) {
				return $comparison;
			}
		}

		$left_prerelease  = $left_parts[1] ?? null;
		$right_prerelease = $right_parts[1] ?? null;
		if ( null === $left_prerelease || null === $right_prerelease ) {
			return $left_prerelease === $right_prerelease ? 0 : ( null === $left_prerelease ? 1 : -1 );
		}

		$left_identifiers  = explode( '.', $left_prerelease );
		$right_identifiers = explode( '.', $right_prerelease );
		$shared_count      = min( count( $left_identifiers ), count( $right_identifiers ) );
		for ( $index = 0; $index < $shared_count; ++$index ) {
			$left_identifier  = $left_identifiers[ $index ];
			$right_identifier = $right_identifiers[ $index ];
			$left_numeric     = 1 === preg_match( '/\A\d+\z/D', $left_identifier );
			$right_numeric    = 1 === preg_match( '/\A\d+\z/D', $right_identifier );
			$comparison       = $left_numeric && $right_numeric
				? self::compare_numeric_identifier( $left_identifier, $right_identifier )
				: ( $left_numeric || $right_numeric ? ( $left_numeric ? -1 : 1 ) : ( strcmp( $left_identifier, $right_identifier ) <=> 0 ) );
			if ( 0 !== $comparison ) {
				return $comparison;
			}
		}
		return count( $left_identifiers ) <=> count( $right_identifiers );
	}

	public static function relationship( string $candidate, string $baseline ): string {
		$comparison = self::compare( $candidate, $baseline );
		if ( null === $comparison ) {
			return self::RELATIONSHIP_INVALID;
		}
		if ( 0 === $comparison ) {
			return self::RELATIONSHIP_SAME;
		}
		return $comparison > 0 ? self::RELATIONSHIP_NEWER : self::RELATIONSHIP_OLDER;
	}

	/** @return -1|0|1 */
	private static function compare_numeric_identifier( string $left, string $right ): int {
		$length_comparison = strlen( $left ) <=> strlen( $right );
		return 0 !== $length_comparison ? $length_comparison : ( strcmp( $left, $right ) <=> 0 );
	}
}
