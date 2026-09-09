<?php

declare(strict_types=1);

namespace RAN\UpdaterSupport\V1;

/** Pure path, metadata, and collision rules for ZIP archive inventories. */
final class ArchiveSafety {

	public const MAX_PATH_BYTES      = 4096;
	public const MAX_COMPONENT_BYTES = 255;

	/** @return array{path:string,directory:bool}|null */
	public static function normalizePath( string $name ): ?array {
		if (
			'' === $name || strlen( $name ) > self::MAX_PATH_BYTES || str_starts_with( $name, '/' )
			|| 1 === preg_match( '/[\\x00-\\x1f\\x7f]/', $name )
			|| 1 === preg_match( '/[^\\x20-\\x7e]|[\\\\:<>"|?*]/', $name )
		) {
			return null;
		}

		$directory = str_ends_with( $name, '/' );
		$path      = $directory ? substr( $name, 0, -1 ) : $name;
		if ( '' === $path || str_contains( $path, '//' ) ) {
			return null;
		}
		foreach ( explode( '/', $path ) as $component ) {
			if (
				'' === $component || '.' === $component || '..' === $component
				|| strlen( $component ) > self::MAX_COMPONENT_BYTES
				|| str_ends_with( $component, '.' ) || str_ends_with( $component, ' ' )
				|| 1 === preg_match( '/\\A(?:con|prn|aux|nul|com[1-9]|lpt[1-9])(?:\\.|\\z)/iD', $component )
			) {
				return null;
			}
		}

		return array(
			'path'      => $path,
			'directory' => $directory,
		);
	}

	public static function entryTypeFailure( ?int $originOs, ?int $attributes, bool $directory ): ?string {
		if ( null === $originOs || null === $attributes ) {
			return 'entry_metadata_invalid';
		}
		if ( 3 === $originOs ) {
			$type = ( $attributes >> 16 ) & 0170000;
			if ( ! in_array( $type, array( 0, 0040000, 0100000 ), true ) ) {
				return 'entry_type_unsupported';
			}
			return 0 === $type || $directory === ( 0040000 === $type ) ? null : 'entry_metadata_invalid';
		}
		if ( 0 !== $originOs ) {
			return 'entry_type_unsupported';
		}
		$flags = $attributes & 0xff;
		if ( 0 !== ( $flags & 0x08 ) ) {
			return 'entry_type_unsupported';
		}
		return 0 === $flags || $directory === ( 0 !== ( $flags & 0x10 ) ) ? null : 'entry_metadata_invalid';
	}

	/** @param list<array{path:string,directory:bool}> $entries */
	public static function collisionFailure( array $entries ): ?string {
		$entriesByPath = array();
		foreach ( $entries as $entry ) {
			$key = strtolower( $entry['path'] ) . '/';
			if ( isset( $entriesByPath[ $key ] ) ) {
				return 'path_duplicate';
			}
			$entriesByPath[ $key ] = $entry['directory'];
		}
		ksort( $entriesByPath, SORT_STRING );
		$previousPath      = null;
		$previousDirectory = true;
		foreach ( $entriesByPath as $path => $directory ) {
			if ( ! $previousDirectory && is_string( $previousPath ) && str_starts_with( $path, $previousPath ) ) {
				return 'file_parent_collision';
			}
			$previousPath      = $path;
			$previousDirectory = $directory;
		}

		return null;
	}
}
