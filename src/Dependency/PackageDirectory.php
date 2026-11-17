<?php

declare(strict_types=1);

namespace RAN\WPReleaseUpdater\V1\Dependency;

use InvalidArgumentException;

/** Pure package folder names and repository subdirectories; no destination policy. */
final class PackageDirectory {

	/** Folder admission only: preserve case and compare admitted names exactly. */
	public static function is_valid_name( string $value ): bool {
		return 1 === preg_match( '/\A[A-Za-z0-9][A-Za-z0-9._-]{0,190}\z/D', $value );
	}

	/** Derive from a consumer-validated WordPress identifier, retaining installed case. */
	public static function installed_slug( string $package_type, string $identifier ): string {
		if ( 'theme' === $package_type ) {
			return $identifier;
		}
		if ( 'plugin' !== $package_type ) {
			throw new InvalidArgumentException( 'The package type is invalid.' );
		}
		$identifier = trim( $identifier, '/' );
		$directory  = dirname( $identifier );
		return '.' === $directory ? (string) pathinfo( $identifier, PATHINFO_FILENAME ) : basename( $directory );
	}

	/** Optional subdirectory; throws InvalidArgumentException with a fixed message. */
	public static function normalize( mixed $value ): ?string {
		if ( null === $value ) {
			return null;
		}
		if ( ! is_string( $value ) ) {
			throw self::invalid();
		}
		if ( '' === trim( $value ) ) {
			if ( 1 === preg_match( '/[\x00-\x1F\x7F]/', $value ) ) {
				throw self::invalid();
			}
			return null;
		}

		try {
			return RepositoryRelativePath::normalize( $value );
		} catch ( InvalidArgumentException ) {
			throw self::invalid();
		}
	}

	/** Last repository segment, with its original case; not folder-name admission. */
	public static function slug( mixed $value ): string {
		$path = self::normalize( $value );
		if ( null === $path ) {
			throw self::invalid();
		}
		$segments = explode( '/', $path );
		return (string) end( $segments );
	}

	/** Legacy provider-segment normalization; admission uses is_valid_name() separately. */
	public static function normalize_slug( mixed $value ): string {
		$slug = self::normalize( $value );
		if ( null === $slug
			|| ! is_string( $value )
			|| str_ends_with( trim( $value ), '/' )
			|| str_contains( $slug, '/' ) ) {
			throw self::invalid();
		}
		return $slug;
	}

	/** Source-derived name: a subdirectory takes precedence over a provider name. */
	public static function installation_slug( mixed $provider_slug, mixed $subdirectory ): string {
		$path = self::normalize( $subdirectory );
		return null === $path ? self::normalize_slug( $provider_slug ) : self::slug( $path );
	}

	/** Lower-case destination only when creating a new installation. */
	public static function deployment_slug( mixed $provider_slug, mixed $subdirectory ): string {
		return strtolower( self::installation_slug( $provider_slug, $subdirectory ) );
	}

	private static function invalid(): InvalidArgumentException {
		return new InvalidArgumentException( 'The package subdirectory must be a normalized relative path.' );
	}
}
