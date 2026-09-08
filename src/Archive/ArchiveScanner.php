<?php

declare(strict_types=1);

namespace RAN\WPReleaseUpdater\V1\Archive;

use RAN\WPReleaseUpdater\V1\Dependency\ArchiveSafety;

/** Performs the shared bounded, security-sensitive ZIP inventory scan. */
final class ArchiveScanner {

	public const MAX_EXPANDED_ARCHIVE_BYTES = 127826407;
	public const MAX_ENTRIES = 10000;
	private const MAX_COMPRESSION_RATIO = 100;

	public static function scan( \ZipArchive $zip, ?string $expectedRoot = null ): ArchiveScanResult {
		if ( $zip->numFiles < 1 || $zip->numFiles > self::MAX_ENTRIES ) {
			return ArchiveScanResult::blocked( 'archive_entry_limit' );
		}

		$root = null;
		$seen = array();
		$entries = array();
		$collisionEntries = array();
		$expanded = 0;

		for ( $index = 0; $index < $zip->numFiles; ++$index ) {
			$name = $zip->getNameIndex( $index, \ZipArchive::FL_UNCHANGED );
			$stat = $zip->statIndex( $index, \ZipArchive::FL_UNCHANGED );
			$path = is_string( $name ) ? ArchiveSafety::normalizePath( $name ) : null;
			$origin = 0;
			$attributes = 0;
			$typeFailure = null === $path || ! $zip->getExternalAttributesIndex( $index, $origin, $attributes, \ZipArchive::FL_UNCHANGED )
				? ArchiveSafety::entryTypeFailure( null, null, false )
				: ArchiveSafety::entryTypeFailure( $origin, $attributes, $path['directory'] );
			if (
				null === $path
				|| ! is_array( $stat )
				|| ! is_int( $stat['size'] ?? null )
				|| ! is_int( $stat['comp_size'] ?? null )
				|| $stat['size'] < 0
				|| $stat['comp_size'] < 0
				|| null !== $typeFailure
			) {
				return ArchiveScanResult::blocked( 'archive_path_unsafe' );
			}
			if (
				$stat['size'] > self::MAX_EXPANDED_ARCHIVE_BYTES - $expanded
				|| (
					$stat['size'] > 0
					&& (
						0 === $stat['comp_size']
						|| $stat['size'] > self::MAX_COMPRESSION_RATIO * $stat['comp_size']
					)
				)
			) {
				return ArchiveScanResult::blocked( 'archive_size_limit' );
			}
			$expanded += $stat['size'];
			$key = strtolower( $path['path'] );
			if ( isset( $seen[ $key ] ) ) {
				return ArchiveScanResult::blocked( 'archive_path_duplicate' );
			}
			$seen[ $key ] = true;

			$parts = explode( '/', $path['path'] );
			$root ??= $parts[0];
			if (
				( null !== $expectedRoot && ! hash_equals( $expectedRoot, $parts[0] ) )
				|| ! hash_equals( $root, $parts[0] )
				|| ( 1 === count( $parts ) && ! $path['directory'] )
			) {
				return ArchiveScanResult::blocked( 'archive_root_mismatch' );
			}

			$entries[] = array(
				'name' => $name,
				'path' => $path['path'],
				'directory' => $path['directory'],
				'size' => $stat['size'],
				'compressed_size' => $stat['comp_size'],
			);
			$collisionEntries[] = $path;
		}

		$collision = ArchiveSafety::collisionFailure( $collisionEntries );
		if ( 'path_duplicate' === $collision ) {
			return ArchiveScanResult::blocked( 'archive_path_duplicate' );
		}
		if ( null !== $collision ) {
			return ArchiveScanResult::blocked( 'archive_path_unsafe' );
		}
		if ( ! is_string( $root ) ) {
			return ArchiveScanResult::blocked( 'archive_root_mismatch' );
		}

		return ArchiveScanResult::ready( $root, $entries, $expanded );
	}
}
