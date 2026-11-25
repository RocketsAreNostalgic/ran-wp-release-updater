<?php

declare(strict_types=1);

namespace RAN\WPReleaseUpdater\V1\WordPress;

use RAN\WPReleaseUpdater\V1\Contract\IdentityDescriptor;
use RAN\WPReleaseUpdater\V1\Dependency\RegularFileStat;

/** Owns the security-sensitive local copy and identity checks for acquired archives. */
final class OwnedArchiveStore {

	/**
	 * @param (\Closure(string,string): void)|null $on_allocate
	 * @return array{directory:string,identity:array{dev:int,ino:int,mode:int,mtime:int,ctime:int,size:int},path:string}|null
	 */
	public function copy( string $source, IdentityDescriptor $descriptor, ?\Closure $on_allocate = null ): ?array {
		$facts  = $descriptor->to_array();
		$before = $this->stat_identity( $source, $descriptor );
		if ( null === $before ) {
			return null;
		}

		try {
			$suffix = bin2hex( random_bytes( 16 ) );
		} catch ( \Throwable ) {
			return null;
		}

		$temp_root = function_exists( 'get_temp_dir' ) ? get_temp_dir() : sys_get_temp_dir();
		$directory = rtrim( $temp_root, '/\\' ) . DIRECTORY_SEPARATOR . 'ran-wp-release-updater-' . $suffix;
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create an owned local directory or reject the copy.
		if ( ! @mkdir( $directory, 0700 ) ) {
			return null;
		}
		$path = $directory . DIRECTORY_SEPARATOR . 'package.zip';
		$on_allocate?->__invoke( $path, $directory );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Keep native stream identity and access mode; failed opens follow the existing rejection and cleanup path.
		$input = @fopen( $source, 'rb' );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Keep native stream identity and access mode; failed opens follow the existing rejection and cleanup path.
		$output = @fopen( $path, 'x+b' );
		if ( ! is_resource( $input ) || ! is_resource( $output ) ) {
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

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Best-effort tighten permissions on the owned archive copy without rejecting ACL/setgid/Windows hosts.
		@chmod( $path, 0600 );
		$after = $this->stat_identity( $source, $descriptor );
		$copy  = $this->stat_identity( $path, $descriptor );
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
			'identity'  => $copy,
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
					if ( 0100000 === ( $entry['mode'] & 0170000 ) ) {
						// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Restore writable permissions on the regular owned copy before unlinking on Windows.
						@chmod( $path, 0600 );
					}
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

	/**
	 * @phpstan-impure
	 * @return array{dev:int,ino:int,mode:int,mtime:int,ctime:int,size:int}|null
	 */
	public function stat_identity( string $path, IdentityDescriptor $descriptor ): ?array {
		$facts = $descriptor->to_array();
		$stat  = RegularFileStat::read( $path );
		if ( null === $stat || $stat['size'] !== $facts['artifact_size'] ) {
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

	/**
	 * @phpstan-impure
	 * @param array{dev:int,ino:int,mode:int,mtime:int,ctime:int,size:int} $identity
	 */
	public function same_stat_identity( string $path, IdentityDescriptor $descriptor, array $identity ): bool {
		$current = $this->stat_identity( $path, $descriptor );
		return is_array( $current ) && $current === $identity;
	}

	/**
	 * @phpstan-impure
	 * @param array{dev:int,ino:int,mode:int,mtime:int,ctime:int,size:int} $identity
	 */
	public function verify_digest_and_stat( string $path, IdentityDescriptor $descriptor, array $identity ): bool {
		if ( ! $this->same_stat_identity( $path, $descriptor, $identity ) ) {
			return false;
		}
		$facts = $descriptor->to_array();
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Verify the exact owned archive digest immediately before extraction; failed reads reject the archive.
		$hash = @hash_file( 'sha256', $path );
		return is_string( $hash )
			&& hash_equals( $facts['artifact_sha256'], $hash )
			&& $this->same_stat_identity( $path, $descriptor, $identity );
	}
}
