<?php

declare(strict_types=1);

namespace RAN\WPReleaseUpdater\V1\WordPress;

use RAN\WPReleaseUpdater\V1\Contract\IdentityDescriptor;

/** Owns the security-sensitive local copy and identity checks for acquired archives. */
final class OwnedArchiveStore {

	/** @return array{directory:string,path:string}|null */
	public function copy( string $source, IdentityDescriptor $descriptor ): ?array {
		$facts  = $descriptor->to_array();
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
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_mkdir, WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Create the local directory with the specified native permissions; failure follows the existing rejection path.
		if ( ! @mkdir( $directory, 0700 ) || ! @chmod( $directory, 0700 ) ) {
			return null;
		}

		$path = $directory . DIRECTORY_SEPARATOR . 'package.zip';
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Keep native stream identity and access mode; failed opens follow the existing rejection and cleanup path.
		$input = @fopen( $source, 'rb' );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Keep native stream identity and access mode; failed opens follow the existing rejection and cleanup path.
		$output = @fopen( $path, 'x+b' );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Apply the required native file permissions; failure follows the existing rejection and cleanup path.
		if ( ! is_resource( $input ) || ! is_resource( $output ) || ! @chmod( $path, 0600 ) ) {
			if ( is_resource( $input ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the exact native stream owned by this operation; no WordPress filesystem abstraction applies.
				fclose( $input );
			}
			if ( is_resource( $output ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the exact native stream owned by this operation; no WordPress filesystem abstraction applies.
				fclose( $output );
			}
			$this->remove( $path, $directory );
			return null;
		}

		$context = hash_init( 'sha256' );
		$size    = 0;
		$ok      = true;
		while ( ! feof( $input ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Read bounded bytes from the already opened native stream; existing type, length and identity checks govern acceptance.
			$chunk = fread( $input, 65536 );
			if ( ! is_string( $chunk ) || ( '' === $chunk && ! feof( $input ) ) || strlen( $chunk ) > $facts['artifact_size'] - $size ) {
				$ok = false;
				break;
			}
			$length = strlen( $chunk );
			for ( $written = 0; $written < $length; ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Complete short writes in the bounded loop; failed or zero-progress writes reject the copy.
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
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the exact native stream owned by this operation; no WordPress filesystem abstraction applies.
		fclose( $input );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the exact native stream owned by this operation; no WordPress filesystem abstraction applies.
		fclose( $output );

		clearstatcache( true, $source );
		$after = $this->identity( $source, $descriptor );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Apply the required native file permissions; failure follows the existing rejection and cleanup path.
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
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Inspect the exact cleanup entry type; a failed stat skips entry removal and leaves the existing best-effort directory cleanup.
			$entry = @lstat( $path );
			if ( is_array( $entry ) ) {
				if ( 0040000 === ( $entry['mode'] & 0170000 ) ) {
					// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Retain best-effort native cleanup of the owned path/directory; this void cleanup deliberately suppresses local failures.
					@rmdir( $path );
				} else {
					// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- Retain best-effort native cleanup of the owned path/directory; this void cleanup deliberately suppresses local failures.
					@unlink( $path );
				}
			}
		}
		if ( is_string( $directory ) && is_dir( $directory ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Retain best-effort native cleanup of the owned path/directory; this void cleanup deliberately suppresses local failures.
			@rmdir( $directory );
		}
	}

	/** @return array{dev:int,ino:int,mode:int,mtime:int,ctime:int,size:int}|null */
	public function identity( string $path, IdentityDescriptor $descriptor ): ?array {
		$facts = $descriptor->to_array();
		clearstatcache( true, $path );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Observe native filesystem identity without following a replacement abstraction; missing or changed facts fail existing validation.
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
	public function same_identity( string $path, IdentityDescriptor $descriptor, array $identity ): bool {
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
