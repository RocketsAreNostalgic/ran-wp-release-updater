<?php

declare(strict_types=1);

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$package = dirname( __DIR__, 2 );
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$root = sys_get_temp_dir() . '/ran-release-updater-no-dev-' . bin2hex( random_bytes( 6 ) );

// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create real directories for the disposable integration fixture with the specified permissions.
if ( ! mkdir( $root, 0700, true ) ) {
	throw new RuntimeException( 'Could not create the isolated consumer root.' );
}

register_shutdown_function(
	static function () use ( $root ): void {
		$paths = glob( $root . '/*' );
		$paths = $paths ? $paths : array();
		foreach ( $paths as $path ) {
			if ( is_dir( $path ) ) {
				$iterator = new RecursiveIteratorIterator(
					new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ),
					RecursiveIteratorIterator::CHILD_FIRST
				);
				foreach ( $iterator as $file ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove native fixture entries directly, preserving the surrounding ownership and link-handling checks.
					$file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
				}
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove native fixture entries directly, preserving the surrounding ownership and link-handling checks.
				rmdir( $path );
			} else {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove native fixture entries directly, preserving the surrounding ownership and link-handling checks.
				unlink( $path );
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove native fixture entries directly, preserving the surrounding ownership and link-handling checks.
		rmdir( $root );
	}
);

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$manifest = array(
	'require'      => array( 'ran/wp-release-updater' => 'dev-main' ),
	'repositories' => array(
		array(
			'type'    => 'path',
			'url'     => $package,
			'options' => array(
				'symlink'  => false,
				'versions' => array( 'ran/wp-release-updater' => 'dev-main' ),
			),
		),
	),
);
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Write exact bytes for the disposable integration fixture; WordPress helpers would alter the boundary under test. Native JSON preserves protocol or fixture bytes without requiring WordPress helpers or their fallback behavior.
file_put_contents( $root . '/composer.json', json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) );

// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Run the isolated no-dev consumer command with an argument vector and captured exit status. Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$process = proc_open(
	array( 'composer', 'update', '--no-dev', '--no-interaction', '--prefer-dist', '--no-progress' ),
	array(
		0 => array( 'pipe', 'r' ),
		1 => array( 'pipe', 'w' ),
		2 => array( 'pipe', 'w' ),
	),
	$pipes,
	$root
);
if ( ! is_resource( $process ) ) {
	throw new RuntimeException( 'Could not start Composer for the isolated consumer.' );
}
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the native stream owned by this fixture; WordPress filesystem abstractions do not own process or file handles.
fclose( $pipes[0] );
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$stdout = stream_get_contents( $pipes[1] );
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$stderr = stream_get_contents( $pipes[2] );
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the native stream owned by this fixture; WordPress filesystem abstractions do not own process or file handles.
fclose( $pipes[1] );
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the native stream owned by this fixture; WordPress filesystem abstractions do not own process or file handles.
fclose( $pipes[2] );
if ( 0 !== proc_close( $process ) ) {
	// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- This exception carries internal failure data rather than an HTML response; escaping would alter the failure contract.
	throw new RuntimeException( 'No-dev consumer installation failed: ' . $stdout . $stderr );
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$installed = $root . '/vendor/ran/wp-release-updater';
if ( is_link( $installed ) || ! is_file( $installed . '/bootstrap.php' ) || is_dir( $root . '/vendor/ran/updater-support' ) ) {
	throw new RuntimeException( 'The no-dev consumer has an unexpected dependency layout.' );
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$probe = <<<'PHP'
<?php
declare(strict_types=1);
function add_action(string $hook, callable $callback, int $priority = 10, int $arguments = 1): void { $GLOBALS['actions'][] = $callback; }
function add_filter(string $hook, callable $callback, int $priority = 10, int $arguments = 1): void {}
function doing_action(string $hook): bool { return false; }
function did_action(string $hook): int { return 0; }
$GLOBALS['wpdb'] = new stdClass();
$GLOBALS['wp_version'] = '6.8.0';
$GLOBALS['actions'] = array();
$registrar = require $argv[1] . '/bootstrap.php';
foreach ($GLOBALS['actions'] as $action) { $action(); }
$archiveSafety = new ReflectionClass('RAN\\WPReleaseUpdater\\V1\\Dependency\\ArchiveSafety');
$archiveClass = 'RAN\\WPReleaseUpdater\\V1\\Dependency\\ArchiveSafety';
$safe = $archiveClass::normalize_path('package/asset.php');
$rejected = $archiveClass::normalize_path('../asset.php');
echo json_encode(array('registrar' => is_object($registrar), 'file' => $archiveSafety->getFileName(), 'safe' => $safe, 'rejected' => $rejected), JSON_THROW_ON_ERROR);
PHP;
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write exact bytes for the disposable integration fixture; WordPress helpers would alter the boundary under test.
file_put_contents( $root . '/probe.php', $probe );
// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Run the isolated no-dev consumer command with an argument vector and captured exit status. Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$process = proc_open(
	array( PHP_BINARY, $root . '/probe.php', $installed ),
	array(
		0 => array( 'pipe', 'r' ),
		1 => array( 'pipe', 'w' ),
		2 => array( 'pipe', 'w' ),
	),
	$pipes
);
if ( ! is_resource( $process ) ) {
	throw new RuntimeException( 'Could not start the isolated bootstrap probe.' );
}
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the native stream owned by this fixture; WordPress filesystem abstractions do not own process or file handles.
fclose( $pipes[0] );
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$stdout = stream_get_contents( $pipes[1] );
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$stderr = stream_get_contents( $pipes[2] );
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the native stream owned by this fixture; WordPress filesystem abstractions do not own process or file handles.
fclose( $pipes[1] );
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the native stream owned by this fixture; WordPress filesystem abstractions do not own process or file handles.
fclose( $pipes[2] );
if ( 0 !== proc_close( $process ) ) {
	// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- This exception carries internal failure data rather than an HTML response; escaping would alter the failure contract.
	throw new RuntimeException( 'No-dev consumer bootstrap failed: ' . $stdout . $stderr );
}
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled CLI or shared fixture state retains its existing variable identity; this occurrence does not authorize new globals.
$result = json_decode( $stdout, true, 512, JSON_THROW_ON_ERROR );
if (
	true !== ( $result['registrar'] ?? false )
	|| realpath( $installed . '/src/Dependency/ArchiveSafety.php' ) !== ( $result['file'] ?? null )
	|| array(
		'path'      => 'package/asset.php',
		'directory' => false,
	) !== ( $result['safe'] ?? null )
	|| ! array_key_exists( 'rejected', $result )
	|| null !== $result['rejected']
) {
	// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- This exception carries internal failure data rather than an HTML response; escaping would alter the failure contract.
	throw new RuntimeException( 'No-dev consumer bootstrap did not load the packaged scoped helper: ' . $stdout );
}

echo "PASS no-dev Composer consumer bootstrap uses the packaged scoped helper\n";
