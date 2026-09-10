<?php

declare(strict_types=1);

namespace RAN\WPReleaseUpdater\V1\WordPress;

use RAN\WPReleaseUpdater\V1\Contract\IdentityDescriptor;

/** Owns the security-sensitive local copy and identity checks for acquired archives. */
final class OwnedArchiveStore {

	/** @return array{directory:string,path:string}|null */
	public function copy( string $source, IdentityDescriptor $descriptor ): ?array {
		$facts  = $descriptor->toArray();
		$before = $this->identity( $source, $descriptor );
		if ( null === $before ) {
			return null;
		}

		try {
			$suffix = bin2hex( random_bytes( 16 ) );
		} catch ( \Throwable ) {
			return null;
		}

		$directory = rtrim( sys_get_temp_dir(), '/\\' ) . DIRECTORY_SEPARATOR . 'ran-wp-release-updater-' . $suffix;
		if ( ! @mkdir( $directory, 0700 ) || ! @chmod( $directory, 0700 ) ) {
			return null;
		}

		$path   = $directory . DIRECTORY_SEPARATOR . 'package.zip';
		$input  = @fopen( $source, 'rb' );
		$output = @fopen( $path, 'x+b' );
		if ( ! is_resource( $input ) || ! is_resource( $output ) || ! @chmod( $path, 0600 ) ) {
			if ( is_resource( $input ) ) {
				fclose( $input );
			}
			if ( is_resource( $output ) ) {
				fclose( $output );
			}
			$this->remove( $path, $directory );
			return null;
		}

		$context = hash_init( 'sha256' );
		$size    = 0;
		$ok      = true;
		while ( ! feof( $input ) ) {
			$chunk = fread( $input, 65536 );
			if ( ! is_string( $chunk ) || ( '' === $chunk && ! feof( $input ) ) || strlen( $chunk ) > $facts['artifact_size'] - $size ) {
				$ok = false;
				break;
			}
			$length = strlen( $chunk );
			for ( $written = 0; $written < $length; ) {
				$result = fwrite( $output, substr( $chunk, $written ) );
				if ( ! is_int( $result ) || 0 === $result ) {
					$ok = false;
					break 2;
				}
				$written += $result;
			}
			$size += $length;
			hash_update( $context, $chunk );
		}
		fflush( $output );
		fclose( $input );
		fclose( $output );

		clearstatcache( true, $source );
		$after = $this->identity( $source, $descriptor );
		if ( ! @chmod( $path, 0400 ) ) {
			$ok = false;
		}
		$copy = $this->identity( $path, $descriptor );
		if (
			! $ok
			|| $size !== $facts['artifact_size']
			|| ! hash_equals( $facts['artifact_sha256'], hash_final( $context ) )
			|| ! is_array( $after )
			|| $after !== $before
			|| null === $copy
		) {
			$this->remove( $path, $directory );
			return null;
		}

		return array(
			'directory' => $directory,
			'path'      => $path,
		);
	}

	public function remove( ?string $path, ?string $directory ): void {
		if ( is_string( $path ) && is_string( $directory ) && hash_equals( $directory . DIRECTORY_SEPARATOR . 'package.zip', $path ) ) {
			$entry = @lstat( $path );
			if ( is_array( $entry ) ) {
				if ( 0040000 === ( $entry['mode'] & 0170000 ) ) {
					@rmdir( $path );
				} else {
					@unlink( $path );
				}
			}
		}
		if ( is_string( $directory ) && is_dir( $directory ) ) {
			@rmdir( $directory );
		}
	}

	/** @return array{dev:int,ino:int,mode:int,mtime:int,ctime:int,size:int}|null */
	public function identity( string $path, IdentityDescriptor $descriptor ): ?array {
		$facts = $descriptor->toArray();
		clearstatcache( true, $path );
		$stat = @lstat( $path );
		$hash = is_file( $path ) ? hash_file( 'sha256', $path ) : false;
		if (
			! is_array( $stat )
			|| 0100000 !== ( $stat['mode'] & 0170000 )
			|| $stat['size'] !== $facts['artifact_size']
			|| ! is_string( $hash )
			|| ! hash_equals( $facts['artifact_sha256'], $hash )
		) {
			return null;
		}

		return array(
			'dev'   => $stat['dev'],
			'ino'   => $stat['ino'],
			'mode'  => $stat['mode'],
			'mtime' => $stat['mtime'],
			'ctime' => $stat['ctime'],
			'size'  => $stat['size'],
		);
	}

	/** @param array{dev:int,ino:int,mode:int,mtime:int,ctime:int,size:int} $identity */
	public function sameIdentity( string $path, IdentityDescriptor $descriptor, array $identity ): bool {
		$current = $this->identity( $path, $descriptor );
		if ( ! is_array( $current ) ) {
			return false;
		}
		foreach ( $identity as $key => $value ) {
			if ( ! array_key_exists( $key, $current ) || $current[ $key ] !== $value ) {
				return false;
			}
		}
		return true;
	}
}
