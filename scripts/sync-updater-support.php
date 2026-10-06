<?php

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- This standalone CLI entrypoint owns process-local variables and never loads into WordPress global scope.

$root   = dirname( __DIR__ );
$source = $root . '/vendor/ran/updater-support/src/ArchiveSafety.php';
$target = $root . '/src/Dependency/ArchiveSafety.php';
$check  = array_slice( $argv, 1 ) === array( '--check' );
if ( ! $check && 0 !== count( array_slice( $argv, 1 ) ) ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Write directly to the CLI descriptor without requiring a WordPress runtime.
	fwrite( STDERR, "Usage: sync-updater-support.php [--check]\n" );
	exit( 2 );
}
if ( is_link( $source ) || ! is_file( $source ) || ! is_readable( $source ) ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Write directly to the CLI descriptor without requiring a WordPress runtime.
	fwrite( STDERR, "Installed updater-support source is unavailable or unsafe.\n" );
	exit( 1 );
}
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read exact local source bytes for namespace-only parity; no WordPress runtime is loaded.
$canonical = file_get_contents( $source );
if ( ! is_string( $canonical ) ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Write directly to the CLI descriptor without requiring a WordPress runtime.
	fwrite( STDERR, "Installed updater-support source could not be read.\n" );
	exit( 1 );
}
$from = 'RAN\\UpdaterSupport\\V1';
$to   = 'RAN\\WPReleaseUpdater\\V1\\Dependency';
if ( 1 !== substr_count( $canonical, $from ) || 1 !== substr_count( $canonical, 'namespace ' . $from . ';' ) ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Write directly to the CLI descriptor without requiring a WordPress runtime.
	fwrite( STDERR, "Canonical updater-support namespace shape is unexpected.\n" );
	exit( 1 );
}
$generated = str_replace( $from, $to, $canonical );
if ( 1 !== substr_count( $generated, $to ) || str_contains( $generated, $from ) ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Write directly to the CLI descriptor without requiring a WordPress runtime.
	fwrite( STDERR, "Updater-support namespace rewrite is unexpected.\n" );
	exit( 1 );
}
if ( $check ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read exact local source bytes for namespace-only parity; no WordPress runtime is loaded.
	if ( is_link( $target ) || ! is_file( $target ) || ! hash_equals( $generated, (string) file_get_contents( $target ) ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Write directly to the CLI descriptor without requiring a WordPress runtime.
		fwrite( STDERR, "Generated updater-support runtime copy is stale.\n" );
		exit( 1 );
	}
	exit( 0 );
}
if ( is_link( $target ) ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Write directly to the CLI descriptor without requiring a WordPress runtime.
	fwrite( STDERR, "Generated updater-support runtime copy is unsafe.\n" );
	exit( 1 );
}
$target_directory = dirname( $target );
if ( is_link( $target_directory ) ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Write directly to the CLI descriptor without requiring a WordPress runtime.
	fwrite( STDERR, "Generated dependency directory is unsafe.\n" );
	exit( 1 );
}
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create the local directory with the specified native permissions; failure follows the existing rejection path.
if ( ! is_dir( $target_directory ) && ! mkdir( $target_directory, 0755, true ) ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Write directly to the CLI descriptor without requiring a WordPress runtime.
	fwrite( STDERR, "Generated dependency directory could not be created.\n" );
	exit( 1 );
}
$temporary = tempnam( $target_directory, '.updater-support-' );
if ( ! is_string( $temporary ) || is_link( $temporary ) || ! is_file( $temporary ) || realpath( dirname( $temporary ) ) !== realpath( $target_directory ) ) {
	if ( is_string( $temporary ) && is_file( $temporary ) && ! is_link( $temporary ) ) {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- Best-effort removal of this synchronizer temporary file precedes the existing failure exit; retain quiet cleanup.
		@unlink( $temporary );
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Write directly to the CLI descriptor without requiring a WordPress runtime.
	fwrite( STDERR, "Generated updater-support temporary file could not be created safely.\n" );
	exit( 1 );
}
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write exact generated bytes with the native exclusive lock; the byte count is checked before atomic replacement.
$written = file_put_contents( $temporary, $generated, LOCK_EX );
// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Apply the required native file permissions; failure follows the existing rejection and cleanup path.
if ( strlen( $generated ) !== $written || ! @chmod( $temporary, 0644 ) ) {
	// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- Best-effort removal of this synchronizer temporary file precedes the existing failure exit; retain quiet cleanup.
	@unlink( $temporary );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Write directly to the CLI descriptor without requiring a WordPress runtime.
	fwrite( STDERR, "Generated updater-support runtime copy could not be written.\n" );
	exit( 1 );
}
// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename -- Atomically replace the generated copy on the local filesystem; failure triggers cleanup and a nonzero exit.
if ( ! @rename( $temporary, $target ) ) {
	// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- Best-effort removal of this synchronizer temporary file precedes the existing failure exit; retain quiet cleanup.
	@unlink( $temporary );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Write directly to the CLI descriptor without requiring a WordPress runtime.
	fwrite( STDERR, "Generated updater-support runtime copy could not be replaced atomically.\n" );
	exit( 1 );
}
