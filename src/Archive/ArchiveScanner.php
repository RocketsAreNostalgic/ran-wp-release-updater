<?php

declare(strict_types=1);

namespace RAN\WPReleaseUpdater\V1\Archive;

use RAN\WPReleaseUpdater\V1\Dependency\ArchiveSafety;

/** Performs the shared bounded, security-sensitive ZIP inventory scan. */
final class ArchiveScanner {

	public const MAX_EXPANDED_ARCHIVE_BYTES = 127826407;
	public const MAX_ENTRIES                = ArchiveSafety::MAX_ENTRIES;

	public static function scan( \ZipArchive $zip, ?string $expected_root = null, ?int $maximum_expanded_bytes = null ): ArchiveScanResult {
		$expanded_limit = $maximum_expanded_bytes ?? self::MAX_EXPANDED_ARCHIVE_BYTES;
		if ( $expanded_limit < 1 ) {
			return ArchiveScanResult::blocked( 'archive_size_limit' );
		}
		$count_failure = ArchiveSafety::entry_count_failure( $zip->numFiles ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- ZipArchive exposes this native extension property.
		if ( null !== $count_failure ) {
			return ArchiveScanResult::blocked( $count_failure );
		}

		$root              = null;
		$seen              = array();
		$entries           = array();
		$collision_entries = array();
		$expanded          = 0;

		for ( $index = 0; $index < $zip->numFiles; ++$index ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- ZipArchive exposes this native extension property.
			$name           = $zip->getNameIndex( $index, \ZipArchive::FL_UNCHANGED );
			$stat           = $zip->statIndex( $index, \ZipArchive::FL_UNCHANGED );
			$path           = is_string( $name ) ? ArchiveSafety::normalize_path( $name, allow_utf8: true ) : null;
			$origin         = 0;
			$attributes     = 0;
			$has_attributes = null !== $path && $zip->getExternalAttributesIndex( $index, $origin, $attributes, \ZipArchive::FL_UNCHANGED );
			$type_failure   = $has_attributes
				? ArchiveSafety::entry_type_failure( $origin, $attributes, $path['directory'] )
				: ArchiveSafety::entry_type_failure( null, null, false );
			if ( null === $path || ! is_array( $stat ) || null !== $type_failure ) {
				return ArchiveScanResult::blocked( 'archive_path_unsafe' );
			}
			$policy_failure = ArchiveSafety::entry_policy_failure( $stat, $path['directory'], $expanded_limit - $expanded );
			if ( null !== $policy_failure ) {
				return ArchiveScanResult::blocked(
					match ( $policy_failure ) {
						'entry_encrypted' => 'archive_unreadable',
						'entry_directory_payload', 'entry_compression_ratio', 'archive_expanded_limit' => 'archive_size_limit',
						default => 'archive_path_unsafe',
					}
				);
			}
			if ( ! is_int( $stat['size'] ?? null ) || ! is_int( $stat['comp_size'] ?? null ) ) {
				return ArchiveScanResult::blocked( 'archive_path_unsafe' );
			}
			$expanded += $stat['size'];
			$key       = self::collision_key( $path['path'] );
			if ( null === $key ) {
				return ArchiveScanResult::blocked( 'archive_path_unsafe' );
			}
			if ( isset( $seen[ $key ] ) ) {
				return ArchiveScanResult::blocked( 'archive_path_duplicate' );
			}
			$seen[ $key ] = true;

			$parts  = explode( '/', $path['path'] );
			$root ??= $parts[0];
			if (
				( null !== $expected_root && ! hash_equals( $expected_root, $parts[0] ) )
				|| ! hash_equals( $root, $parts[0] )
				|| ( 1 === count( $parts ) && ! $path['directory'] )
			) {
				return ArchiveScanResult::blocked( 'archive_root_mismatch' );
			}

			$entries[]           = array(
				'name'            => $name,
				'path'            => $path['path'],
				'directory'       => $path['directory'],
				'size'            => $stat['size'],
				'compressed_size' => $stat['comp_size'],
			);
			$collision_entries[] = array(
				'path'      => $key,
				'directory' => $path['directory'],
			);
		}

		$collision = ArchiveSafety::collision_failure( $collision_entries );
		if ( 'path_duplicate' === $collision ) {
			return ArchiveScanResult::blocked( 'archive_path_duplicate' );
		}
		if ( null !== $collision ) {
			return ArchiveScanResult::blocked( 'archive_path_unsafe' );
		}

		if ( null === $root ) {
			return ArchiveScanResult::blocked( 'archive_entry_limit' );
		}

		return ArchiveScanResult::ready( $root, $entries );
	}

	/**
	 * Fold an archive path to a case- and canonical-normalization-insensitive collision key.
	 *
	 * Preserves the ASCII fast path while failing closed when non-ASCII paths cannot be
	 * safely case-folded or canonically normalized for destination filesystem collision checks.
	 */
	private static function collision_key( string $path ): ?string {
		if ( 0 === preg_match( '/[^\x00-\x7f]/', $path ) ) {
			return strtolower( $path );
		}

		$has_normalizer = class_exists( \Normalizer::class );
		if ( $has_normalizer ) {
			$normalized = \Normalizer::normalize( $path, \Normalizer::FORM_D );
			if ( ! is_string( $normalized ) ) {
				return null;
			}
		} else {
			// Without ext-intl, fail closed on combining marks, Hangul Jamo, CJK Compatibility
			// Ideographs, canonical singletons / composition exclusions, and Kirat Rai compositions
			// whose NFC/NFD equivalence cannot be normalized safely without \Normalizer.
			if ( 0 !== preg_match( '/[\p{M}\x{0374}\x{037E}\x{0387}\x{0958}-\x{095F}\x{09DC}-\x{09DF}\x{0A33}\x{0A36}\x{0A59}-\x{0A5B}\x{0A5E}\x{0B5C}\x{0B5D}\x{0F43}\x{0F4D}\x{0F52}\x{0F57}\x{0F5C}\x{0F69}\x{1100}-\x{11FF}\x{1F71}\x{1F73}\x{1F75}\x{1F77}\x{1F79}\x{1F7B}\x{1F7D}\x{1FBB}\x{1FBE}\x{1FC9}\x{1FCB}\x{1FD3}\x{1FDB}\x{1FE3}\x{1FEB}\x{1FEE}\x{1FEF}\x{1FF9}\x{1FFB}\x{1FFD}\x{2000}\x{2001}\x{2126}\x{212A}\x{212B}\x{2329}\x{232A}\x{2ADC}\x{F900}-\x{FAFF}\x{FB1D}-\x{FB4E}\x{16D63}\x{16D67}-\x{16D6A}\x{1D15E}-\x{1D164}\x{1D1BB}-\x{1D1C0}\x{2F800}-\x{2FA1F}]/u', $path ) ) {
				return null;
			}
			$normalized = $path;
		}

		if ( ! function_exists( 'mb_convert_case' ) ) {
			return null;
		}
		$folded = mb_convert_case( $normalized, MB_CASE_FOLD, 'UTF-8' );

		if ( $has_normalizer ) {
			$folded = \Normalizer::normalize( $folded, \Normalizer::FORM_C );
			if ( ! is_string( $folded ) ) {
				return null;
			}
		}

		return substr_count( $folded, '/' ) === substr_count( $path, '/' ) ? $folded : null;
	}
}
