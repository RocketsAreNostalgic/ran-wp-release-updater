<?php

declare(strict_types=1);

namespace RAN\WPReleaseUpdater\V1\WordPress;

/** @return array<array-key,int>|false */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Match the native function name in the updater namespace for the zero-inode integration fixture.
function lstat( string $path ): array|false {
	$stat = \lstat( $path );
	if ( is_array( $stat ) && ( $GLOBALS['ran_wp_release_updater_test_zero_inode_backup'] ?? null ) === $path && 0040000 === ( $stat['mode'] & 0170000 ) ) {
		// Report a deterministic metadata collision at the backup root. File bytes and all other entries remain native.
		$stat['ino']   = 0;
		$stat['mode']  = 0040755;
		$stat['mtime'] = 1;
		$stat['ctime'] = 1;
		$stat['size']  = 0;
	}
	return $stat;
}
