<?php

declare(strict_types=1);

namespace RAN\WPReleaseUpdater\V1\WordPress;

use RAN\WPReleaseUpdater\V1\Archive\PackageIdentityValidator;

/**
 * Builds a bounded byte-identity manifest for a package tree.
 *
 * @phpstan-type EntryIdentity array{dev:int,ino:int,mode:int,ctime:int,mtime:int,size:int}
 * @phpstan-type PackageTree array{files:array<string,array{sha256:string,size:int}>,directories:array<string,true>}
 */
final class StagedPackageManifest {

	private const MAX_ENTRIES     = 10000;
	private const MAX_DIRECTORIES = 10000;

	/** @return array<string,array{sha256:string,size:int}>|null */
	public function build( string $root ): ?array {
		$tree = $this->snapshot( $root );
		return null === $tree || array() === $tree['files'] ? null : $tree['files'];
	}

	/** @return PackageTree|null */
	public function snapshot( string $root ): ?array {
		$root          = rtrim( $root, '/\\' );
		$root_identity = self::entry_identity( $root );
		if ( null === $root_identity || 0040000 !== ( $root_identity['mode'] & 0170000 ) ) {
			return null;
		}

		$queue            = array(
			array(
				'path'     => $root,
				'relative' => '',
				'identity' => $root_identity,
			),
		);
		$manifest         = array();
		$directories      = array();
		$total            = 0;
		$files_seen       = 0;
		$directories_seen = 0;
		while ( array() !== $queue ) {
			$next = array_pop( $queue );
			if ( self::entry_identity( $next['path'] ) !== $next['identity'] ) {
				return null;
			}
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Enumerate the local staged directory under existing entry bounds; unreadable directories return no manifest.
			$entries = @scandir( $next['path'] );
			if ( ! is_array( $entries ) ) {
				return null;
			}
			foreach ( $entries as $entry ) {
				if ( '.' === $entry || '..' === $entry ) {
					continue;
				}
				$path     = $next['path'] . DIRECTORY_SEPARATOR . $entry;
				$relative = '' === $next['relative'] ? $entry : $next['relative'] . '/' . $entry;
				if ( strlen( $relative ) > PackageIdentityValidator::MAX_ARCHIVE_PATH_BYTES || 0 !== preg_match( '/[\x00-\x1F\x7F]|[\\\\:]/u', $relative ) ) {
					return null;
				}
				$stat = self::entry_identity( $path );
				if ( null === $stat ) {
					return null;
				}
				$type = $stat['mode'] & 0170000;
				if ( 0040000 === $type ) {
					if ( ++$directories_seen > self::MAX_DIRECTORIES ) {
						return null;
					}
					$directories[ $relative ] = true;
					$queue[]                  = array(
						'path'     => $path,
						'relative' => $relative,
						'identity' => $stat,
					);
					continue;
				}
				if ( ++$files_seen > self::MAX_ENTRIES ) {
					return null;
				}
				if ( 0100000 !== $type || $stat['size'] < 0 || $stat['size'] > PackageIdentityValidator::MAX_EXPANDED_ARCHIVE_BYTES - $total ) {
					return null;
				}
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Hash the local bytes and recheck native identity; unreadable or changed content is rejected quietly.
				$hash = @hash_file( 'sha256', $path );
				if ( ! is_string( $hash ) || self::entry_identity( $path ) !== $stat ) {
					return null;
				}
				$total                += $stat['size'];
				$manifest[ $relative ] = array(
					'sha256' => $hash,
					'size'   => $stat['size'],
				);
			}
			if ( self::entry_identity( $next['path'] ) !== $next['identity'] ) {
				return null;
			}
		}
		ksort( $manifest, SORT_STRING );
		ksort( $directories, SORT_STRING );
		return array(
			'files'       => $manifest,
			'directories' => $directories,
		);
	}

	/**
	 * @phpstan-impure Re-read current filesystem identity.
	 * @return EntryIdentity|null
	 */
	public static function entry_identity( string $path ): ?array {
		clearstatcache( true, $path );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Missing entries are expected. Native lstat distinguishes links and replacements from files and directories.
		$stat = @lstat( $path );
		return is_array( $stat ) ? array(
			'dev'   => $stat['dev'],
			'ino'   => $stat['ino'],
			'mode'  => $stat['mode'],
			'ctime' => $stat['ctime'],
			'mtime' => $stat['mtime'],
			'size'  => $stat['size'],
		) : null;
	}

	/** @param PackageTree $tree */
	public function tree_digest( array $tree ): string {
		return self::digest( $tree );
	}

	/** @param array<string,array{sha256:string,size:int}> $manifest */
	public function hash( array $manifest ): string {
		return self::digest( $manifest );
	}

	/** @param array<array-key,mixed> $value */
	private static function digest( array $value ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Native JSON preserves protocol or fixture bytes without requiring WordPress helpers or their fallback behavior.
		return hash( 'sha256', json_encode( $value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES ) );
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
