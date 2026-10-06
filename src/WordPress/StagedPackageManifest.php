<?php

declare(strict_types=1);

namespace RAN\WPReleaseUpdater\V1\WordPress;

use RAN\WPReleaseUpdater\V1\Archive\PackageIdentityValidator;

/** Builds a bounded byte-identity manifest for an extracted package tree. */
final class StagedPackageManifest {

	private const MAX_ENTRIES = 10000;

	/** @return array<string,array{sha256:string,size:int}>|null */
	public function build( string $root ): ?array {
		$root = rtrim( $root, '/\\' );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Observe native filesystem identity without following a replacement abstraction; missing or changed facts fail existing validation.
		$root_stat = @lstat( $root );
		if ( ! is_array( $root_stat ) || 0040000 !== ( $root_stat['mode'] & 0170000 ) ) {
			return null;
		}

		$queue        = array(
			array(
				'path'     => $root,
				'relative' => '',
			),
		);
		$manifest     = array();
		$total        = 0;
		$entries_seen = 0;
		while ( array() !== $queue ) {
			$next = array_pop( $queue );
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Enumerate the local staged directory under existing entry bounds; unreadable directories return no manifest.
			$entries = @scandir( $next['path'] );
			if ( ! is_array( $entries ) ) {
				return null;
			}
			foreach ( $entries as $entry ) {
				if ( '.' === $entry || '..' === $entry ) {
					continue;
				}
				if ( ++$entries_seen > self::MAX_ENTRIES ) {
					return null;
				}
				$path     = $next['path'] . DIRECTORY_SEPARATOR . $entry;
				$relative = '' === $next['relative'] ? $entry : $next['relative'] . '/' . $entry;
				if ( strlen( $relative ) > PackageIdentityValidator::MAX_ARCHIVE_PATH_BYTES || 1 === preg_match( '/[^\x20-\x7E]|[\\\\:]/', $relative ) ) {
					return null;
				}
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Observe native filesystem identity without following a replacement abstraction; missing or changed facts fail existing validation.
				$stat = @lstat( $path );
				if ( ! is_array( $stat ) ) {
					return null;
				}
				$type = $stat['mode'] & 0170000;
				if ( 0040000 === $type ) {
					$queue[] = array(
						'path'     => $path,
						'relative' => $relative,
					);
					continue;
				}
				if ( 0100000 !== $type || $stat['size'] < 0 || $stat['size'] > PackageIdentityValidator::MAX_EXPANDED_ARCHIVE_BYTES - $total ) {
					return null;
				}
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Hash the local bytes and recheck native identity; unreadable or changed content is rejected quietly.
				$hash = @hash_file( 'sha256', $path );
				clearstatcache( true, $path );
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Observe native filesystem identity without following a replacement abstraction; missing or changed facts fail existing validation.
				$after = @lstat( $path );
				if (
					! is_string( $hash )
					|| ! is_array( $after )
					|| $after['dev'] !== $stat['dev']
					|| $after['ino'] !== $stat['ino']
					|| $after['mode'] !== $stat['mode']
					|| $after['mtime'] !== $stat['mtime']
					|| $after['ctime'] !== $stat['ctime']
					|| $after['size'] !== $stat['size']
				) {
					return null;
				}
				$total                += $stat['size'];
				$manifest[ $relative ] = array(
					'sha256' => $hash,
					'size'   => $stat['size'],
				);
			}
		}
		ksort( $manifest, SORT_STRING );
		return array() === $manifest ? null : $manifest;
	}

	/** @param array<string,array{sha256:string,size:int}> $manifest */
	public function hash( array $manifest ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Native JSON preserves protocol or fixture bytes without requiring WordPress helpers or their fallback behavior.
		return hash( 'sha256', json_encode( $manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES ) );
	}

	/** @param array<string,array{sha256:string,size:int}> $manifest */
	public function expanded_bytes( array $manifest ): int {
		$total = 0;
		foreach ( $manifest as $entry ) {
			$total += $entry['size'];
		}
		return $total;
	}
}
