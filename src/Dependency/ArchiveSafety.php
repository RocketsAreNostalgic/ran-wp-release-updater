<?php

declare(strict_types=1);

namespace RAN\WPReleaseUpdater\V1\Dependency;

/** Pure path, metadata, and collision rules for ZIP archive inventories. */
final class ArchiveSafety {

	public const MAX_PATH_BYTES      = 4096;
	public const MAX_COMPONENT_BYTES = 255;

	/**
	 * Closed vocabulary returned by path_failure().
	 *
	 * Each value is a fixed identifier chosen by this package. None carries
	 * archive text, so a value is safe to log, display or map without escaping.
	 */
	public const PATH_FAILURES = array(
		'path_empty',
		'path_too_long',
		'path_absolute',
		'path_control_character',
		'path_unsafe_character',
		'path_empty_component',
		'path_dot_segment',
		'component_too_long',
		'component_trailing_dot_or_space',
		'component_reserved_name',
	);

	/**
	 * Closed vocabulary returned by entry_type_reason().
	 *
	 * Fixed identifiers only; none carries archive text.
	 */
	public const ENTRY_TYPE_REASONS = array(
		'entry_metadata_unavailable',
		'entry_type_mismatch',
		'entry_type_special',
		'entry_origin_unsupported',
		'entry_volume_label',
	);

	/**
	 * Say which rule rejects an archive entry name, or null when it is admitted.
	 *
	 * Returns the first failing check, in the order of the PATH_FAILURES list
	 * for whole-name checks and then per component from the first component.
	 * The result is one of PATH_FAILURES and never contains the name.
	 */
	public static function path_failure( string $name ): ?string {
		if ( '' === $name ) {
			return 'path_empty';
		}
		if ( strlen( $name ) > self::MAX_PATH_BYTES ) {
			return 'path_too_long';
		}
		if ( str_starts_with( $name, '/' ) ) {
			return 'path_absolute';
		}
		if ( 1 === preg_match( '/[\\x00-\\x1f\\x7f]/', $name ) ) {
			return 'path_control_character';
		}
		if ( 1 === preg_match( '/[^\\x20-\\x7e]|[\\\\:<>"|?*]/', $name ) ) {
			return 'path_unsafe_character';
		}

		$path = str_ends_with( $name, '/' ) ? substr( $name, 0, -1 ) : $name;
		if ( '' === $path || str_contains( $path, '//' ) ) {
			return 'path_empty_component';
		}
		foreach ( explode( '/', $path ) as $component ) {
			if ( '' === $component ) {
				return 'path_empty_component';
			}
			if ( '.' === $component || '..' === $component ) {
				return 'path_dot_segment';
			}
			if ( strlen( $component ) > self::MAX_COMPONENT_BYTES ) {
				return 'component_too_long';
			}
			if ( str_ends_with( $component, '.' ) || str_ends_with( $component, ' ' ) ) {
				return 'component_trailing_dot_or_space';
			}
			if ( 1 === preg_match( '/\\A(?:con|prn|aux|nul|com[1-9]|lpt[1-9])(?:\\.|\\z)/iD', $component ) ) {
				return 'component_reserved_name';
			}
		}

		return null;
	}

	/** @return array{path:string,directory:bool}|null */
	public static function normalize_path( string $name ): ?array {
		if ( null !== self::path_failure( $name ) ) {
			return null;
		}

		$directory = str_ends_with( $name, '/' );

		return array(
			'path'      => $directory ? substr( $name, 0, -1 ) : $name,
			'directory' => $directory,
		);
	}

	/**
	 * Legacy two-code entry check. Consumers compare against exactly these
	 * codes, so no other non-null value may ever be returned here.
	 */
	public static function entry_type_failure( ?int $origin_os, ?int $attributes, bool $directory ): ?string {
		$reason = self::entry_type_reason( $origin_os, $attributes, $directory );
		if ( null === $reason ) {
			return null;
		}

		return in_array( $reason, array( 'entry_metadata_unavailable', 'entry_type_mismatch' ), true )
			? 'entry_metadata_invalid'
			: 'entry_type_unsupported';
	}

	/**
	 * Say why an entry's type metadata is rejected, or null when it is admitted.
	 *
	 * The result is one of ENTRY_TYPE_REASONS and never contains archive text.
	 */
	public static function entry_type_reason( ?int $origin_os, ?int $attributes, bool $directory ): ?string {
		if ( null === $origin_os || null === $attributes ) {
			return 'entry_metadata_unavailable';
		}
		if ( 3 === $origin_os ) {
			$type = ( $attributes >> 16 ) & 0170000;
			if ( ! in_array( $type, array( 0, 0040000, 0100000 ), true ) ) {
				return 'entry_type_special';
			}
			return 0 === $type || ( 0040000 === $type ) === $directory ? null : 'entry_type_mismatch';
		}
		if ( 0 !== $origin_os ) {
			return 'entry_origin_unsupported';
		}
		$flags = $attributes & 0xff;
		if ( 0 !== ( $flags & 0x08 ) ) {
			return 'entry_volume_label';
		}
		return 0 === $flags || ( 0 !== ( $flags & 0x10 ) ) === $directory ? null : 'entry_type_mismatch';
	}

	/** @param list<array{path:string,directory:bool}> $entries */
	public static function collision_failure( array $entries ): ?string {
		$entries_by_path = array();
		foreach ( $entries as $entry ) {
			$key = strtolower( $entry['path'] ) . '/';
			if ( isset( $entries_by_path[ $key ] ) ) {
				return 'path_duplicate';
			}
			$entries_by_path[ $key ] = $entry['directory'];
		}
		ksort( $entries_by_path, SORT_STRING );
		$previous_path      = null;
		$previous_directory = true;
		foreach ( $entries_by_path as $path => $directory ) {
			if ( ! $previous_directory && is_string( $previous_path ) && str_starts_with( $path, $previous_path ) ) {
				return 'file_parent_collision';
			}
			$previous_path      = $path;
			$previous_directory = $directory;
		}

		return null;
	}
}
