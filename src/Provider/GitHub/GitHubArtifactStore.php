<?php

declare(strict_types=1);

namespace RAN\WPReleaseUpdater\V1\Provider\GitHub;

use RuntimeException;

/** Owns private temporary-file allocation, identity checks and cleanup for GitHub artifacts. */
final class GitHubArtifactStore {

	/**
	 * @return array{0:string,1:array{dev:int,ino:int,mode:int,nlink:int,uid:int,gid:int,size:int,mtime:int,ctime:int}}
	 */
	public function allocate( string $filename, ?bool &$allocationClean ): array {
		$allocationClean = null;
		if ( ! function_exists( 'wp_tempnam' ) ) {
			throw new RuntimeException( 'WordPress temporary-file custody is unavailable.' );
		}
		$path = wp_tempnam( $filename );
		if ( ! is_string( $path ) || '' === $path ) {
			throw new RuntimeException( 'A private temporary file could not be created.' );
		}
		$createdIdentity = $this->identity( $path );
		if ( null === $createdIdentity || ! @chmod( $path, 0600 ) ) {
			if ( is_array( $createdIdentity ) ) {
				$allocationClean = $this->remove( $path, $createdIdentity );
			} else {
				$allocationClean = ! file_exists( $path ) && ! is_link( $path );
			}
			throw new RuntimeException( 'A private temporary file could not be created.' );
		}
		$identity = $this->identity( $path );
		if ( null === $identity || 1 !== $identity['nlink'] ) {
			$allocationClean = $this->remove( $path, $createdIdentity );
			throw new RuntimeException( 'The private temporary file is invalid.' );
		}
		return array( $path, $identity );
	}

	/** @return array{dev:int,ino:int,mode:int,nlink:int,uid:int,gid:int,size:int,mtime:int,ctime:int}|null */
	public function identity( string $path ): ?array {
		clearstatcache( true, $path );
		$stat = @lstat( $path );
		if ( ! is_array( $stat ) || is_link( $path ) || 0100000 !== ( (int) $stat['mode'] & 0170000 ) ) {
			return null;
		}
		return array(
			'dev'   => (int) $stat['dev'],
			'ino'   => (int) $stat['ino'],
			'mode'  => (int) $stat['mode'],
			'nlink' => (int) $stat['nlink'],
			'uid'   => (int) $stat['uid'],
			'gid'   => (int) $stat['gid'],
			'size'  => (int) $stat['size'],
			'mtime' => (int) $stat['mtime'],
			'ctime' => (int) $stat['ctime'],
		);
	}

	/** @param array{dev:int,ino:int,mode:int,nlink:int,uid:int,gid:int,size:int,mtime:int,ctime:int} $identity */
	public function remove( string $path, array $identity ): bool {
		for ( $attempt = 0; $attempt < 2; ++$attempt ) {
			$current = $this->identity( $path );
			if ( ! is_array( $current ) || $current['dev'] !== $identity['dev'] || $current['ino'] !== $identity['ino'] ) {
				return ! file_exists( $path ) && ! is_link( $path );
			}
			@unlink( $path );
			clearstatcache( true, $path );
			if ( ! file_exists( $path ) && ! is_link( $path ) ) {
				return true;
			}
		}
		return false;
	}
}
