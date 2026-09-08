<?php

declare(strict_types=1);

$package = dirname( __DIR__, 2 );
$root    = sys_get_temp_dir() . '/ran-release-updater-no-dev-' . bin2hex( random_bytes( 6 ) );

if ( ! mkdir( $root, 0700, true ) ) {
	throw new RuntimeException( 'Could not create the isolated consumer root.' );
}

register_shutdown_function(
	static function () use ( $root ): void {
		$paths = glob( $root . '/*' ) ?: array();
		foreach ( $paths as $path ) {
			if ( is_dir( $path ) ) {
				$iterator = new RecursiveIteratorIterator(
					new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ),
					RecursiveIteratorIterator::CHILD_FIRST
				);
				foreach ( $iterator as $file ) {
					$file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
				}
				rmdir( $path );
			} else {
				unlink( $path );
			}
		}
		rmdir( $root );
	}
);

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
file_put_contents( $root . '/composer.json', json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) );

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
fclose( $pipes[0] );
$stdout = stream_get_contents( $pipes[1] );
$stderr = stream_get_contents( $pipes[2] );
fclose( $pipes[1] );
fclose( $pipes[2] );
if ( 0 !== proc_close( $process ) ) {
	throw new RuntimeException( 'No-dev consumer installation failed: ' . $stdout . $stderr );
}

$installed = $root . '/vendor/ran/wp-release-updater';
if ( is_link( $installed ) || ! is_file( $installed . '/bootstrap.php' ) || is_dir( $root . '/vendor/ran/updater-support' ) ) {
	throw new RuntimeException( 'The no-dev consumer has an unexpected dependency layout.' );
}

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
$safe = $archiveClass::normalizePath('package/asset.php');
$rejected = $archiveClass::normalizePath('../asset.php');
echo json_encode(array('registrar' => is_object($registrar), 'file' => $archiveSafety->getFileName(), 'safe' => $safe, 'rejected' => $rejected), JSON_THROW_ON_ERROR);
PHP;
file_put_contents( $root . '/probe.php', $probe );
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
fclose( $pipes[0] );
$stdout = stream_get_contents( $pipes[1] );
$stderr = stream_get_contents( $pipes[2] );
fclose( $pipes[1] );
fclose( $pipes[2] );
if ( 0 !== proc_close( $process ) ) {
	throw new RuntimeException( 'No-dev consumer bootstrap failed: ' . $stdout . $stderr );
}
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
	throw new RuntimeException( 'No-dev consumer bootstrap did not load the packaged scoped helper: ' . $stdout );
}

echo "PASS no-dev Composer consumer bootstrap uses the packaged scoped helper\n";
