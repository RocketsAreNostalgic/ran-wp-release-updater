<?php

declare(strict_types=1);

namespace RAN\WPReleaseUpdater\V1\Tests\Dependency;

use PHPUnit\Framework\TestCase;
use RAN\WPReleaseUpdater\V1\Dependency\TargetWorkingCopy;

final class TargetWorkingCopyDependencyTest extends TestCase {
	public function test_generated_helper_detects_every_marker_form_only_on_the_target(): void {
		$root   = dirname( __DIR__, 2 ) . '/.workspaces/working-copy-' . bin2hex( random_bytes( 6 ) );
		$target = $root . '/target';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create owned real filesystem fixtures, including their target directory.
		mkdir( $root, 0700, true );
		try {
			self::assertFalse( TargetWorkingCopy::is_working_copy( $target ) );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create the real target directory for marker detection.
			mkdir( $target, 0700 );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Composer metadata must not be interpreted as working-copy evidence.
			file_put_contents( $target . '/composer.json', '{}' );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- An ancestor marker must not protect the target.
			mkdir( $root . '/.git', 0700 );
			self::assertFalse( TargetWorkingCopy::is_working_copy( $target ) );
			foreach ( array( '.git', '.svn', '.hg', '.bzr' ) as $marker ) {
				$path = $target . '/' . $marker;
				foreach ( array( 'directory', 'file', 'symlink', 'dangling' ) as $form ) {
					if ( 'directory' === $form ) {
						// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Exercise an actual marker directory.
						mkdir( $path, 0700 );
					} elseif ( 'file' === $form ) {
						// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Exercise an actual marker file.
						file_put_contents( $path, 'marker' );
					} else {
						self::assertTrue( symlink( 'dangling' === $form ? $root . '/missing' : $root, $path ) );
					}
					self::assertTrue( TargetWorkingCopy::is_working_copy( $target ), $marker . ' ' . $form );
					$this->remove( $path );
					clearstatcache();
					self::assertFalse( TargetWorkingCopy::is_working_copy( $target ) );
				}
			}
		} finally {
			$this->remove( $root );
		}
	}

	private function remove( string $path ): void {
		if ( is_link( $path ) || is_file( $path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only this test-owned entry without following links.
			unlink( $path );
		} elseif ( is_dir( $path ) ) {
			foreach ( new \FilesystemIterator( $path ) as $entry ) {
				if ( $entry instanceof \SplFileInfo ) {
					$this->remove( $entry->getPathname() );
				}
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove this test-owned directory after its entries.
			rmdir( $path );
		}
	}
}
