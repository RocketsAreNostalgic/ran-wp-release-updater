<?php

declare(strict_types=1);

namespace RAN\WPReleaseUpdater\V1\Dependency;

/** Observe the native metadata of a local regular-file directory entry. */
final class RegularFileStat {

	/**
	 * Read a fresh snapshot or return null for an unavailable or non-regular entry.
	 * Mode includes type and special bits. The final path component is not dereferenced.
	 * The caller owns ancestor containment, repeat observations and custody policy.
	 *
	 * @phpstan-impure
	 * @return array{dev:int,ino:int,mode:int,nlink:int,uid:int,gid:int,size:int,mtime:int,ctime:int}|null
	 */
	public static function read( string $path ): ?array {
		// PHP recognizes two or more scheme characters before "://", plus bare "data:".
		if ( '' === $path || false !== strpos( $path, "\0" ) || 0 !== preg_match( '/\A(?:[[:alnum:]+.-]{2,}:\/\/|data:)/', $path ) ) {
			return null;
		}

		clearstatcache( true, $path );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A missing or concurrently removed path is an expected null observation.
		$stat = @lstat( $path );
		if ( false === $stat || 0100000 !== ( $stat['mode'] & 0170000 ) ) {
			return null;
		}

		return array(
			'dev'   => $stat['dev'],
			'ino'   => $stat['ino'],
			'mode'  => $stat['mode'],
			'nlink' => $stat['nlink'],
			'uid'   => $stat['uid'],
			'gid'   => $stat['gid'],
			'size'  => $stat['size'],
			'mtime' => $stat['mtime'],
			'ctime' => $stat['ctime'],
		);
	}
}
