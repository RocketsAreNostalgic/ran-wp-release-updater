<?php

declare(strict_types=1);

namespace RAN\WPReleaseUpdater\V1\Dependency;

/** Detect version-control markers directly inside a target folder. */
final class TargetWorkingCopy {
	/**
	 * The caller supplies the absolute target folder path.
	 *
	 * @phpstan-impure
	 */
	public static function is_working_copy( string $folder ): bool {
		if ( '' === $folder ) {
			return false;
		}
		foreach ( array( '.git', '.svn', '.hg', '.bzr' ) as $marker ) {
			$path = rtrim( $folder, '/\\' ) . DIRECTORY_SEPARATOR . $marker;
			clearstatcache( true, $path );
			if ( file_exists( $path ) || is_link( $path ) ) {
				return true;
			}
		}

		return false;
	}
}
