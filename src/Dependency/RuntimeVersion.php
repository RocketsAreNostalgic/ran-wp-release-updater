<?php

declare(strict_types=1);

namespace RAN\WPReleaseUpdater\V1\Dependency;

/** Normalize observed runtime versions for compatibility comparisons. */
final class RuntimeVersion {

	private const MAX_BYTES = 100;
	private const NUMBER    = '(?:0|[1-9][0-9]*)';
	private const CORE      = '(' . self::NUMBER . '\.' . self::NUMBER . ')(?:\.(' . self::NUMBER . '))?';
	private const SERIAL    = '(?:\.?(' . self::NUMBER . '))?';
	private const PHP_STAGE = 'dev|alpha|beta|rc';
	private const PHP_BUILD = '(?!-(?:' . self::PHP_STAGE . ')(?=[0-9.+_~-]|\z))[-+][A-Za-z0-9]+(?:[.+_~-][A-Za-z0-9]+)*';

	/**
	 * Return a three-component PHP core version, with any upstream stage retained.
	 * Downstream build suffixes, such as "-ubuntu1" and "-1~deb12u1", are omitted.
	 * A leading tilde requires a recognized prerelease stage.
	 */
	public static function php( string $value ): ?string {
		// The PHP_BUILD lookahead reserves upstream stage spellings, including malformed serials.
		$pattern = '/\A' . self::CORE . '(?:[-~]?(' . self::PHP_STAGE . ')' . self::SERIAL . ')?(?:' . self::PHP_BUILD . ')?\z/i';
		if ( strlen( $value ) > self::MAX_BYTES || 1 !== preg_match( $pattern, $value, $matches, PREG_UNMATCHED_AS_NULL ) ) {
			return null;
		}

		return self::format( $matches[1], $matches[2], $matches[3], $matches[4] );
	}

	/**
	 * Normalize WordPress core alpha, beta and RC spellings, dropping revision
	 * and source-tree suffixes from prereleases. A standalone "-src" is retained.
	 */
	public static function wordpress( string $value ): ?string {
		$pattern = '/\A' . self::CORE . '(?:-(?:(alpha|beta|rc)' . self::SERIAL . '(?:-[1-9][0-9]*)?(?:-src)?|(src)))?\z/i';
		if ( strlen( $value ) > self::MAX_BYTES || 1 !== preg_match( $pattern, $value, $matches, PREG_UNMATCHED_AS_NULL ) ) {
			return null;
		}

		return self::format( $matches[1], $matches[2], $matches[3] ?? $matches[5], $matches[4] );
	}

	private static function format( string $major_minor, ?string $patch, ?string $stage, ?string $serial ): ?string {
		$core = $major_minor . '.' . ( $patch ?? '0' );
		if ( null !== $stage ) {
			$stage = strtolower( $stage );
			$core .= '-' . $stage . ( 'src' === $stage ? '' : '.' . ( $serial ?? '0' ) );
		}
		return strlen( $core ) <= self::MAX_BYTES ? $core : null;
	}
}
