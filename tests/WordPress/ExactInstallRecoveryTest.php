<?php

declare(strict_types=1);

namespace RAN\WPReleaseUpdater\V1\Tests\WordPress;

use PHPUnit\Framework\TestCase;

final class ExactInstallRecoveryTest extends TestCase {

	public function test_incomplete_copy_is_rejected_and_left_on_disk(): void {
		$temp = realpath( sys_get_temp_dir() );
		self::assertIsString( $temp );
		$root = $temp . '/ran-exact-copy-' . bin2hex( random_bytes( 8 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Own the private filesystem root for the isolated WordPress fixture.
		self::assertTrue( mkdir( $root, 0700 ) );
		try {
			$probe   = <<<'PHP'
require $argv[1];

final class WP_Error {
	public function __construct( public string $code, string $message ) {}
}

final class WP_Filesystem_Direct {
	public function __construct( private string $root ) {}

	public function wp_content_dir(): string {
		return $this->root . '/';
	}

	public function find_folder( string $folder ): string {
		return $folder . '/';
	}
}

function wp_normalize_path( string $path ): string {
	return str_replace( '\\', '/', $path );
}

function trailingslashit( string $path ): string {
	return rtrim( $path, '/\\' ) . '/';
}

$root        = $argv[2];
$plugins     = $root . '/plugins';
$destination = $plugins . '/widget';
$backup      = $root . '/upgrade-temp-backup/plugins/widget';
if ( ! mkdir( $destination, 0700, true )
	|| false === file_put_contents( $destination . '/widget.php', 'Installed entry' )
	|| false === file_put_contents( $destination . '/extra.txt', 'Additional installed entry' )
) {
	throw new RuntimeException( 'Could not arrange the installed fixture.' );
}

$GLOBALS['wp_filesystem'] = new WP_Filesystem_Direct( $root );
$recovery = new \RAN\WPReleaseUpdater\V1\WordPress\ExactInstallRecovery( $destination, 'plugin' );
$result = $recovery->move_backup(
	array( 'src' => $plugins, 'slug' => 'widget', 'dir' => 'plugins' ),
	static function ( array $arguments ) use ( $destination, $backup ): bool {
		if ( 'widget' !== $arguments['slug'] || ! mkdir( $backup, 0700, true ) || ! copy( $destination . '/widget.php', $backup . '/widget.php' )
			|| ! unlink( $destination . '/widget.php' ) || ! unlink( $destination . '/extra.txt' ) || ! rmdir( $destination )
		) {
			throw new RuntimeException( 'Could not simulate an incomplete copy reported as successful.' );
		}
		return true;
	}
);
$deleted = $recovery->delete_backup(
	static fn (): bool => unlink( $backup . '/widget.php' )
);

echo json_encode(
	array(
		'error'    => $result instanceof WP_Error ? $result->code : null,
		'captured' => null !== ( new ReflectionProperty( $recovery, 'backup' ) )->getValue( $recovery ),
		'deleted'  => $deleted,
		'copy'     => file_get_contents( $backup . '/widget.php' ),
	),
	JSON_THROW_ON_ERROR
);
PHP;
			$output  = array();
			$status  = 0;
			$command = escapeshellarg( PHP_BINARY ) . ' -r ' . escapeshellarg( $probe ) . ' ' . escapeshellarg( dirname( __DIR__ ) . '/bootstrap.php' ) . ' ' . escapeshellarg( $root );
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- Keep the WordPress filesystem stubs isolated from the other test classes.
			exec( $command, $output, $status );
			self::assertSame( 0, $status, implode( "\n", $output ) );
			self::assertSame(
				array(
					'error'    => 'ran_wp_release_updater_backup_source_unverified',
					'captured' => false,
					'deleted'  => false,
					'copy'     => 'Installed entry',
				),
				json_decode( implode( "\n", $output ), true, 64, JSON_THROW_ON_ERROR )
			);
		} finally {
			$children = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ),
				\RecursiveIteratorIterator::CHILD_FIRST
			);
			foreach ( $children as $entry ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Clean the exact private fixture entries through native filesystem operations.
				$entry->isDir() ? rmdir( $entry->getPathname() ) : unlink( $entry->getPathname() );
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove the private fixture root once empty.
			rmdir( $root );
		}
	}
}
