<?php

declare(strict_types=1);

namespace RAN\WPReleaseUpdater\V1\Contract;

final class CanonicalUpdateUri {

	private const BOUNDARY_KEYS = array(
		'archive_preflight',
		'configuration',
		'offer_or_cache',
		'staged_package',
	);

	/**
	 * @param array<string, mixed> $boundaries
	 */
	public static function canonicalizeBoundaries( array $boundaries ): ?string {
		if ( count( $boundaries ) !== count( self::BOUNDARY_KEYS ) ) {
			return null;
		}

		$canonical = null;
		foreach ( self::BOUNDARY_KEYS as $key ) {
			if ( ! array_key_exists( $key, $boundaries ) || ! is_string( $boundaries[ $key ] ) ) {
				return null;
			}

			$current = self::canonicalize( $boundaries[ $key ] );
			if ( null === $current || ( null !== $canonical && ! hash_equals( $canonical, $current ) ) ) {
				return null;
			}

			$canonical = $current;
		}

		return $canonical;
	}

	public static function canonicalize( string $uri ): ?string {
		if ( '' === $uri
			|| 1 === preg_match( '/[\x00-\x1F\x7F-\xFF]/D', $uri )
			|| str_contains( $uri, '\\' )
		) {
			return null;
		}

		if ( 1 !== preg_match(
			'#\A(?<scheme>https)://(?<host>[A-Za-z0-9.-]+)/(?<path>[A-Za-z0-9._~-]+(?:/[A-Za-z0-9._~-]+)*/?)\z#Di',
			$uri,
			$matches
		) ) {
			return null;
		}

		$host = strtolower( $matches['host'] );
		if ( strlen( $host ) > 253
			|| str_ends_with( $host, '.' )
			|| self::isNumericIpLiteral( $host )
		) {
			return null;
		}

		foreach ( explode( '.', $host ) as $label ) {
			if ( str_starts_with( $label, 'xn--' )
				|| 1 !== preg_match( '/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/D', $label )
			) {
				return null;
			}
		}

		$path = $matches['path'];
		foreach ( explode( '/', rtrim( $path, '/' ) ) as $segment ) {
			if ( '.' === $segment || '..' === $segment ) {
				return null;
			}
		}

		return 'https://' . $host . '/' . rtrim( $path, '/' );
	}

	private static function isNumericIpLiteral( string $host ): bool {
		return 1 === preg_match(
			'/\A(?:0x[0-9a-f]+|[0-9]+)(?:\.(?:0x[0-9a-f]+|[0-9]+)){0,3}\z/Di',
			$host
		);
	}

}
