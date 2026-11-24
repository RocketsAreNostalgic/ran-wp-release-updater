<?php

declare(strict_types=1);

namespace RAN\WPReleaseUpdater\V1\Dependency;

use InvalidArgumentException;

/** Pure package folder names and repository subdirectories; no destination policy. */
final class PackageDirectory {

	/** Folder admission only: preserve case and compare admitted names exactly. */
	public static function is_valid_name( string $value ): bool {
		if ( 1 !== preg_match( '/\A[A-Za-z0-9][A-Za-z0-9._-]{0,190}\z/D', $value ) ) {
			return false;
		}
		if ( str_ends_with( $value, '.' ) ) {
			return false;
		}

		return 0 === preg_match( '/\A(?:con|prn|aux|nul|(?:com|lpt)[1-9])(?:\.|\z)/iD', $value );
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
		$separator  = strpos( $identifier, '/' );
		return false === $separator
			? (string) pathinfo( $identifier, PATHINFO_FILENAME )
			: substr( $identifier, 0, $separator );
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

	/**
	 * Identity: the installed folder is the source-derived name exactly, or its lower-case new-install form.
	 * The installed folder's case is authoritative and never folded; admission remains is_valid_name().
	 */
	public static function installed_folder_matches( string $installed_folder, string $source_name ): bool {
		return $installed_folder === $source_name || strtolower( $source_name ) === $installed_folder;
	}

	/**
	 * Case-insensitive filesystem key: ASCII A-Z folded to a-z, every other byte unchanged.
	 * Bind it against LOWER(column) in SQL; see the README for that form's collation precondition.
	 */
	public static function collision_key( string $name ): string {
		return strtolower( $name );
	}

	/** Lock, scope and collision checks: whether two names could be one folder on a case-insensitive filesystem. */
	public static function names_collide( string $first, string $second ): bool {
		return self::collision_key( $first ) === self::collision_key( $second );
	}

	private static function invalid(): InvalidArgumentException {
		return new InvalidArgumentException( 'The package subdirectory must be a normalized relative path.' );
	}
}
