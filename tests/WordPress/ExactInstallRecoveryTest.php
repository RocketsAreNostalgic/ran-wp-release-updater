<?php

declare(strict_types=1);

namespace RAN\WPReleaseUpdater\V1\Tests\WordPress;

use PHPUnit\Framework\TestCase;

final class ExactInstallRecoveryTest extends TestCase {

	public function test_incomplete_copy_is_rejected_and_left_on_disk(): void {
		$probe = <<<'PHP'
$result = $recovery->move_backup(
	$arguments,
	static function ( array $arguments ) use ( $destination, $backup ): bool {
		if ( 'widget' !== $arguments['slug'] || ! mkdir( $backup, 0700, true ) || ! copy( $destination . '/widget.php', $backup . '/widget.php' )
			|| ! unlink( $destination . '/widget.php' ) || ! unlink( $destination . '/extra.txt' ) || ! rmdir( $destination )
		) {
			throw new RuntimeException( 'Could not simulate an incomplete copy reported as successful.' );
		}
		return true;
	}
);
$recovery->authorize_backup_deletion();
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
		self::assertSame(
			array(
				'error'    => 'ran_wp_release_updater_backup_source_unverified',
				'captured' => false,
				'deleted'  => false,
				'copy'     => 'Installed entry',
			),
			$this->run_probe( $probe )
		);
	}

	public function test_zero_inode_requires_exact_backup_or_matching_delete_retry(): void {
		$probe = <<<'PHP'
if ( ! mkdir( dirname( $backup ), 0700, true ) || true !== $recovery->move_backup( $arguments, static fn (): bool => rename( $destination, $backup ) ) ) {
	throw new RuntimeException( 'Could not capture the zero-inode backup.' );
}
$identity = \RAN\WPReleaseUpdater\V1\WordPress\StagedPackageManifest::entry_identity( $backup );
$would_mutate = static fn (): bool => true;
$unauthorized = $recovery->delete_backup( $would_mutate );
$recovery->authorize_backup_deletion();
$original = $backup . '-original';
$main = $backup . '/widget.php';
$extra = $backup . '/extra.txt';
if ( ! rename( $backup, $original ) || ! mkdir( $backup, 0700 ) || false === file_put_contents( $main, 'Replaced entry!' ) ) {
	throw new RuntimeException( 'Could not replace the zero-inode backup.' );
}
$replacement_restored = ( clone $recovery )->restore_backup( $would_mutate );
$replacement_deleted = $recovery->delete_backup( $would_mutate );
if ( ! unlink( $main ) || ! rmdir( $backup ) || ! rename( $original, $backup ) || ! unlink( $extra ) ) {
	throw new RuntimeException( 'Could not arrange the partial backup before deletion.' );
}
$partial_deleted = $recovery->delete_backup( $would_mutate );
if ( false === file_put_contents( $extra, 'Additional installed entry' ) ) {
	throw new RuntimeException( 'Could not reconstruct the exact backup.' );
}
$exact_restored = ( clone $recovery )->restore_backup( $would_mutate );
$delete_started = false;
$recovery->delete_backup(
	static function () use ( $extra, &$delete_started ): bool {
		$delete_started = unlink( $extra );
		return false;
	}
);
if ( false === file_put_contents( $main, 'Changed bytes!!' ) ) {
	throw new RuntimeException( 'Could not change the remaining backup file.' );
}
$changed_retry = $recovery->delete_backup( $would_mutate );
if ( false === file_put_contents( $main, 'Installed entry' ) || ! mkdir( $backup . '/foreign', 0700 ) ) {
	throw new RuntimeException( 'Could not add an unrelated backup directory.' );
}
$foreign_retry = $recovery->delete_backup( $would_mutate );
if ( ! rmdir( $backup . '/foreign' ) ) {
	throw new RuntimeException( 'Could not remove the unrelated backup directory.' );
}
$matching_retry = $recovery->delete_backup( static fn (): bool => unlink( $main ) && rmdir( $backup ) );

echo json_encode(
	array(
		'backup_inode'         => $identity['ino'] ?? null,
		'unauthorized'         => $unauthorized,
		'replacement_restored' => $replacement_restored,
		'replacement_deleted'  => $replacement_deleted,
		'partial_deleted'      => $partial_deleted,
		'exact_restored'        => $exact_restored,
		'delete_started'        => $delete_started,
		'changed_retry'         => $changed_retry,
		'foreign_retry'         => $foreign_retry,
		'matching_retry'        => $matching_retry,
		'backup_removed'        => ! file_exists( $backup ),
	),
	JSON_THROW_ON_ERROR
);
PHP;
		self::assertSame(
			array(
				'backup_inode'         => 0,
				'unauthorized'         => false,
				'replacement_restored' => false,
				'replacement_deleted'  => false,
				'partial_deleted'      => false,
				'exact_restored'       => true,
				'delete_started'       => true,
				'changed_retry'        => false,
				'foreign_retry'        => false,
				'matching_retry'       => true,
				'backup_removed'       => true,
			),
			$this->run_probe( $probe, true )
		);
	}

	private function run_probe( string $probe, bool $zero_inode = false ): mixed {
		$temp = realpath( sys_get_temp_dir() );
		self::assertIsString( $temp );
		$root = $temp . '/ran-exact-copy-' . bin2hex( random_bytes( 8 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Own the private filesystem root for the isolated WordPress fixture.
		self::assertTrue( mkdir( $root, 0700 ) );
		try {
			$setup   = <<<'PHP'
namespace RAN\WPReleaseUpdater\V1\WordPress {
	function lstat( string $path ): array|false {
		$stat = \lstat( $path );
		if ( is_array( $stat ) && 'zero-inode' === $GLOBALS['argv'][3]
			&& $path === $GLOBALS['argv'][2] . '/upgrade-temp-backup/plugins/widget' && 0040000 === ( $stat['mode'] & 0170000 )
		) {
			$stat['ino'] = 0;
		}
		return $stat;
	}
}

namespace {
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
$arguments = array( 'src' => $plugins, 'slug' => 'widget', 'dir' => 'plugins' );
PHP;
			$output  = array();
			$status  = 0;
			$command = escapeshellarg( PHP_BINARY ) . ' -r ' . escapeshellarg( $setup . "\n" . $probe . "\n}" ) . ' '
				. escapeshellarg( dirname( __DIR__ ) . '/bootstrap.php' ) . ' ' . escapeshellarg( $root ) . ' ' . ( $zero_inode ? 'zero-inode' : 'native-inode' );
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- Keep the WordPress filesystem stubs isolated from the other test classes.
			exec( $command, $output, $status );
			self::assertSame( 0, $status, implode( "\n", $output ) );
			return json_decode( implode( "\n", $output ), true, 64, JSON_THROW_ON_ERROR );
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
