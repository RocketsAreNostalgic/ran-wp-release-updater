<?php

declare(strict_types=1);

namespace RAN\WPReleaseUpdater\V1\Dependency;

/**
 * Normalize one required repository-relative path.
 *
 * Consumer packages retain optional-value policy, exception mapping, and
 * package-specific slug semantics. This class owns only the shared path-safety
 * rule.
 */
final class RepositoryRelativePath {

	private const MAX_DECODE_PASSES = 8;

	/**
	 * Normalize one non-empty repository-relative path.
	 *
	 * @throws InvalidRepositoryPathException When the path is unsafe or empty;
	 *                                        a \InvalidArgumentException whose message is fixed.
	 */
	public static function normalize( string $value ): string {
		if ( 1 === preg_match( '/[\x00-\x1F\x7F]/', $value ) ) {
			throw self::invalid( 'path_control_character' );
		}

		$value = trim( $value );

		if ( '' === $value ) {
			throw self::invalid( 'path_empty' );
		}
		if ( str_starts_with( $value, '/' ) ) {
			throw self::invalid( 'path_absolute' );
		}
		if ( str_contains( $value, '\\' ) ) {
			throw self::invalid( 'path_backslash' );
		}
		if ( 1 === preg_match( '/^[A-Za-z]:/', $value ) ) {
			throw self::invalid( 'path_drive_letter' );
		}

		if ( str_ends_with( $value, '/' ) ) {
			$value = substr( $value, 0, -1 );
		}

		$segments = explode( '/', $value );
		foreach ( $segments as $index => $segment ) {
			if ( '' === $segment ) {
				throw self::invalid( 'path_empty_component' );
			}
			if ( '.' === $segment || '..' === $segment ) {
				throw self::invalid( 'path_dot_segment' );
			}

			self::assert_decoded_segment_is_safe( $segment, 0 === $index );
		}

		return implode( '/', $segments );
	}

	private static function assert_decoded_segment_is_safe( string $segment, bool $first_segment ): void {
		$decoded = $segment;

		for ( $pass = 0; $pass < self::MAX_DECODE_PASSES; ++$pass ) {
			$next = rawurldecode( $decoded );

			if ( $next === $decoded ) {
				return;
			}

			$decoded = $next;

			if ( '.' === $decoded
				|| '..' === $decoded
				|| str_contains( $decoded, '/' )
				|| str_contains( $decoded, '\\' ) ) {
				throw self::invalid( 'path_encoded_traversal' );
			}
			if ( $first_segment && 1 === preg_match( '/^[A-Za-z]:/', $decoded ) ) {
				throw self::invalid( 'path_encoded_drive_letter' );
			}
			if ( 1 === preg_match( '/[\x00-\x1F\x7F]/', $decoded ) ) {
				throw self::invalid( 'path_encoded_control_character' );
			}
		}

		if ( rawurldecode( $decoded ) !== $decoded ) {
			throw self::invalid( 'path_decode_limit' );
		}
	}

	private static function invalid( string $reason ): InvalidRepositoryPathException {
		return new InvalidRepositoryPathException( $reason );
	}
}
