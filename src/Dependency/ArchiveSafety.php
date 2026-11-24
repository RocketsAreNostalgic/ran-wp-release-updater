<?php

declare(strict_types=1);

namespace RAN\WPReleaseUpdater\V1\Dependency;

use InvalidArgumentException;

/** Pure path, metadata, and collision rules for ZIP archive inventories. */
final class ArchiveSafety {

	public const MAX_PATH_BYTES                      = 4096;
	public const MAX_COMPONENT_BYTES                 = 255;
	public const MAX_ENTRIES                         = 10000;
	public const DEFAULT_MAX_COMPRESSION_RATIO       = 100;
	public const DEFAULT_COMPRESSION_RATIO_MIN_BYTES = 131072;

	private const EXPANDED_SIZE_MULTIPLIER = 4;
	private const ZIP_ENCRYPTION_NONE      = 0; // ZipArchive::EM_NONE, also usable without ext-zip.

	/**
	 * Closed vocabulary returned by entry_size_failure().
	 *
	 * Fixed identifiers only; none carries archive text.
	 */
	public const ENTRY_SIZE_FAILURES = array(
		'entry_size_invalid',
		'entry_directory_payload',
		'entry_compression_ratio',
	);

	/** Closed vocabulary returned by entry_policy_failure(), including the size rules. */
	public const ENTRY_POLICY_FAILURES = array(
		'entry_policy_metadata_invalid',
		'entry_encrypted',
		...self::ENTRY_SIZE_FAILURES,
		'archive_expanded_limit',
	);

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
	 * Like normalize_path(), this admits ASCII by default. The result is one of
	 * PATH_FAILURES and never contains the name.
	 */
	public static function path_failure( string $name, bool $allow_utf8 = false ): ?string {
		if ( '' === $name ) {
			return 'path_empty';
		}
		if ( strlen( $name ) > self::MAX_PATH_BYTES ) {
			return 'path_too_long';
		}
		if ( str_starts_with( $name, '/' ) ) {
			return 'path_absolute';
		}
		// ASCII control bytes keep precedence over malformed UTF-8, as before UTF-8 admission.
		if ( 1 === preg_match( '/[\x00-\x1f\x7f]/', $name ) ) {
			return 'path_control_character';
		}
		$control = preg_match( '/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u', $name );
		if ( 1 === $control ) {
			return 'path_control_character';
		}
		if (
			false === $control
			|| ( ! $allow_utf8 && 0 !== preg_match( '/[^\x00-\x7f]/', $name ) )
			|| 0 !== preg_match( '/[\\\\:<>"|?*]/', $name )
		) {
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
			if ( 0 !== preg_match( '/\\A(?:con|prn|aux|nul|(?:com|lpt)[1-9¹²³])(?:\\.|\\z)/iu', $component ) ) {
				return 'component_reserved_name';
			}
		}

		return null;
	}

	/**
	 * Preserve ASCII admission for existing extractors. Opt in to UTF-8 after
	 * accounting for additional filename equivalences on the destination filesystem.
	 *
	 * @return array{path:string,directory:bool}|null
	 */
	public static function normalize_path( string $name, bool $allow_utf8 = false ): ?array {
		if ( null !== self::path_failure( $name, $allow_utf8 ) ) {
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
		if ( 3 === $origin_os || 19 === $origin_os ) {
			$type = ( $attributes >> 16 ) & 0170000;
			if ( ! in_array( $type, array( 0, 0040000, 0100000 ), true ) ) {
				return 'entry_type_special';
			}
			return 0 === $type || ( 0040000 === $type ) === $directory ? null : 'entry_type_mismatch';
		}
		if ( 0 !== $origin_os && 10 !== $origin_os ) {
			return 'entry_origin_unsupported';
		}
		$flags = $attributes & 0xff;
		if ( 0 !== ( $flags & 0x08 ) ) {
			return 'entry_volume_label';
		}
		if ( 10 === $origin_os && 0 !== ( $attributes & ( 0x0040 | 0x0400 ) ) ) {
			return 'entry_type_special';
		}
		return 0 === $flags || ( 0 !== ( $flags & 0x10 ) ) === $directory ? null : 'entry_type_mismatch';
	}

	/**
	 * Say why a ZIP entry's declared size or compression ratio is rejected, or null when admitted.
	 *
	 * Rejects negative sizes or invalid limits, directory entries declaring non-zero payloads,
	 * and entries at or above $min_ratio_bytes whose uncompressed/compressed ratio exceeds
	 * $max_compression_ratio (including zero compressed size).
	 *
	 * The result is one of ENTRY_SIZE_FAILURES and never contains archive text.
	 */
	public static function entry_size_failure(
		int $uncompressed_size,
		int $compressed_size,
		bool $directory,
		int $max_compression_ratio = self::DEFAULT_MAX_COMPRESSION_RATIO,
		int $min_ratio_bytes = self::DEFAULT_COMPRESSION_RATIO_MIN_BYTES
	): ?string {
		if ( $uncompressed_size < 0 || $compressed_size < 0 || $max_compression_ratio < 1 || $min_ratio_bytes < 0 ) {
			return 'entry_size_invalid';
		}
		if ( $directory && 0 !== $uncompressed_size ) {
			return 'entry_directory_payload';
		}
		if ( $uncompressed_size > 0 && $uncompressed_size >= $min_ratio_bytes ) {
			if (
				0 === $compressed_size
				|| ( $compressed_size <= intdiv( PHP_INT_MAX, $max_compression_ratio ) && $uncompressed_size > $max_compression_ratio * $compressed_size )
			) {
				return 'entry_compression_ratio';
			}
		}

		return null;
	}

	/**
	 * Compute the shared expanded-size budget without integer overflow.
	 *
	 * @throws InvalidArgumentException If the artifact limit cannot be scaled.
	 */
	public static function maximum_expanded_bytes( int $maximum_artifact_bytes ): int {
		if ( $maximum_artifact_bytes < 1 || $maximum_artifact_bytes > intdiv( PHP_INT_MAX, self::EXPANDED_SIZE_MULTIPLIER ) ) {
			throw new InvalidArgumentException( 'The maximum artifact size cannot be scaled to an expanded archive limit.' );
		}

		return $maximum_artifact_bytes * self::EXPANDED_SIZE_MULTIPLIER;
	}

	/** Both an empty ZIP and a ZIP above the inventory limit are rejected. */
	public static function entry_count_failure( int $entries ): ?string {
		return $entries < 1 || $entries > self::MAX_ENTRIES ? 'archive_entry_limit' : null;
	}

	/**
	 * Apply the shared ZIP metadata, size and encryption policy to one entry.
	 *
	 * Pass the size budget left after admitted entries, starting from
	 * maximum_expanded_bytes(). Subtract the entry size after admission.
	 * Validate names, type metadata and actual decompressed bytes separately.
	 *
	 * @param array<string,mixed> $stat Entry metadata from ZipArchive::statIndex().
	 */
	public static function entry_policy_failure( array $stat, bool $directory, int $remaining_expanded_bytes ): ?string {
		if (
			! is_int( $stat['size'] ?? null )
			|| ! is_int( $stat['comp_size'] ?? null )
			|| ! is_int( $stat['encryption_method'] ?? null )
			|| $stat['encryption_method'] < 0
			|| $remaining_expanded_bytes < 0
		) {
			return 'entry_policy_metadata_invalid';
		}
		if ( self::ZIP_ENCRYPTION_NONE !== $stat['encryption_method'] ) {
			return 'entry_encrypted';
		}

		$failure = self::entry_size_failure( $stat['size'], $stat['comp_size'], $directory );
		if ( null !== $failure ) {
			return $failure;
		}

		return $stat['size'] > $remaining_expanded_bytes ? 'archive_expanded_limit' : null;
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
