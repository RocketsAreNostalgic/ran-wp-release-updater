<?php

declare(strict_types=1);

namespace RAN\WPReleaseUpdater\V1\Dependency;

/** Extract header occurrences from WordPress plugin PHP and theme CSS bytes. */
final class WordPressHeaders {

	public const MAX_BYTES = 8192;

	/**
	 * Read and parse one local header file on demand, up to MAX_BYTES.
	 *
	 * An unavailable or non-regular file yields null, as does a change in
	 * observed file identity or a read that ends before the header window
	 * declared by the opened file. A readable file without matching headers yields
	 * an empty array. The caller resolves the installed path and enforces
	 * containment and package identity before calling.
	 *
	 * @param string                $path   Resolved local header file.
	 * @param array<string, string> $labels Result keys mapped to their on-disk labels.
	 * @phpstan-impure
	 * @return array<string, non-empty-list<string>>|null
	 */
	public static function read( string $path, array $labels ): ?array {
		$before = RegularFileStat::read( $path );
		if ( null === $before ) {
			return null;
		}

		// The "n" mode flag adds O_NONBLOCK where the platform defines it. A path
		// swapped for a FIFO or device after the lstat() then opens without
		// waiting, and fstat() rejects it. Reads from a regular file are unaffected.
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Open one local read-only handle for identity checks and the bounded read. An unavailable file yields null.
		$handle = @fopen( $path, 'rbn' );
		if ( false === $handle ) {
			return null;
		}
		try {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- An unavailable handle snapshot yields null.
			$opened = @fstat( $handle );
			if ( false === $opened || 0100000 !== ( $opened['mode'] & 0170000 ) || ! self::same_identity( $before, $opened ) ) {
				return null;
			}

			// Read the whole window the opened file declares. A failed, short or
			// early-ending read yields null, never a partial header block.
			$remaining = min( self::MAX_BYTES, $opened['size'] );
			$contents  = '';
			while ( $remaining > 0 ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Read only the header window from the checked handle. A read failure yields null.
				$chunk = @fread( $handle, $remaining );
				if ( false === $chunk || '' === $chunk ) {
					return null;
				}
				$contents  .= $chunk;
				$remaining -= strlen( $chunk );
			}

			$after = RegularFileStat::read( $path );
			return null !== $after && self::same_identity( $opened, $after ) ? self::parse( $contents, $labels ) : null;
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Release the same local handle on every exit.
			fclose( $handle );
		}
	}

	/**
	 * @param array{dev:int,ino:int,size:int,mtime:int,ctime:int} $left  File snapshot.
	 * @param array{dev:int,ino:int,size:int,mtime:int,ctime:int} $right File snapshot.
	 */
	private static function same_identity( array $left, array $right ): bool {
		return $left['dev'] === $right['dev'] && $left['ino'] === $right['ino']
			&& ( 0 !== $left['ino'] || (
				$left['size'] === $right['size'] && $left['mtime'] === $right['mtime'] && $left['ctime'] === $right['ctime']
			) );
	}

	/**
	 * Scan the first 8 KiB using WordPress-style, case-insensitive header labels.
	 *
	 * Each requested key maps to its occurrences in file order. Absent keys are
	 * omitted. Empty values, "0", and identical duplicates are retained.
	 *
	 * @param string                $contents Source bytes.
	 * @param array<string, string> $labels   Result keys mapped to their on-disk labels.
	 * @return array<string, non-empty-list<string>>
	 */
	public static function parse( string $contents, array $labels ): array {
		$contents = substr( $contents, 0, self::MAX_BYTES );
		if ( "\xef\xbb\xbf" === substr( $contents, 0, 3 ) ) {
			$contents = substr( $contents, 3 );
		}
		$contents = str_replace( array( "\r\n", "\r" ), "\n", $contents );

		$headers = array();
		foreach ( $labels as $key => $label ) {
			$pattern = '/^[ \t]*(?:<\?(?:php)?)?[ \t\/*#@]*'
				. preg_quote( $label, '/' ) . ':(.*?)(?:\*\/|\?>|$)/mi';
			preg_match_all( $pattern, $contents, $matches );
			$values = $matches[1] ?? array();
			if ( array() !== $values ) {
				$headers[ $key ] = array_map(
					static fn ( string $value ): string => trim( $value, " \t\v\f" ),
					$values
				);
			}
		}

		return $headers;
	}
}
