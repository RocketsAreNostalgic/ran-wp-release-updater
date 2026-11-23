<?php

declare(strict_types=1);

namespace RAN\WPReleaseUpdater\V1\WordPress;

/**
 * Tracks WordPress filesystem changes made before Core can schedule recovery.
 *
 * @internal
 * @phpstan-type EntryIdentity array{dev:int,ino:int,mode:int,ctime:int,mtime:int,size:int}
 * @phpstan-type OwnedEntry array{path:string,identity:EntryIdentity}
 */
final class ExactInstallRecovery {

	/** @var OwnedEntry|null */
	private ?array $backup = null;
	/** @var OwnedEntry|null */
	private ?array $maintenance = null;

	private readonly string $destination;

	public function __construct( string $installed_directory, private readonly string $target_type ) {
		$this->destination = wp_normalize_path( rtrim( $installed_directory, '/\\' ) );
	}

	/**
	 * @param (\Closure(array<string,mixed>): (bool|\WP_Error)) $move
	 */
	public function move_backup( mixed $arguments, \Closure $move ): bool|\WP_Error {
		if ( ! is_array( $arguments ) ) {
			return false;
		}
		$filesystem = $GLOBALS['wp_filesystem'] ?? null;
		$group      = 'plugin' === $this->target_type ? 'plugins' : 'themes';
		$slug       = basename( $this->destination );
		if (
			! $filesystem instanceof \WP_Filesystem_Direct
			|| ( $arguments['slug'] ?? null ) !== $slug
			|| ( $arguments['dir'] ?? null ) !== $group
			|| ! is_string( $arguments['src'] ?? null )
		) {
			return $move( $arguments );
		}

		$source  = $filesystem->find_folder( $arguments['src'] );
		$content = $filesystem->wp_content_dir();
		if ( ! is_string( $source ) || '' === $source || ! is_string( $content ) || '' === $content
			|| ! hash_equals( $this->destination, wp_normalize_path( trailingslashit( $source ) . $slug ) )
		) {
			return $move( $arguments );
		}

		$path   = trailingslashit( $content ) . 'upgrade-temp-backup/' . $group . '/' . $slug;
		$result = $move( $arguments );
		if ( true === $result ) {
			$identity = self::entry_identity( $path );
			if ( null !== $identity && 0040000 === ( $identity['mode'] & 0170000 ) ) {
				$this->backup = array(
					'path'     => $path,
					'identity' => $identity,
				);
			}
		}
		return $result;
	}

	/** @param (\Closure(bool): void) $toggle */
	public function maintenance_mode( bool $enable, \Closure $toggle ): void {
		if ( ! $enable ) {
			$this->clear_maintenance();
			return;
		}
		$filesystem = $GLOBALS['wp_filesystem'] ?? null;
		$root       = $filesystem instanceof \WP_Filesystem_Direct ? $filesystem->abspath() : false;
		if ( ! is_string( $root ) || '' === $root ) {
			return;
		}
		$path = trailingslashit( $root ) . '.maintenance';
		if ( null !== self::entry_identity( $path ) ) {
			return;
		}

		$toggle( true );
		$identity = self::entry_identity( $path );
		if ( null !== $identity && 0100000 === ( $identity['mode'] & 0170000 ) ) {
			$this->maintenance = array(
				'path'     => $path,
				'identity' => $identity,
			);
		}
	}

	public function maintenance_pending(): bool {
		return null !== $this->maintenance;
	}

	public function recover_at_shutdown( bool $core_has_backup_callback ): bool {
		try {
			if ( $core_has_backup_callback || null === $this->backup ) {
				return true;
			}
			$backup = $this->backup;
			// A replaced backup cannot authorize deletion of the installed directory.
			return self::entry_identity( $backup['path'] ) === $backup['identity']
				&& ( $GLOBALS['wp_filesystem'] ?? null ) instanceof \WP_Filesystem_Direct
				&& ( null === self::entry_identity( $this->destination ) || null !== ( new StagedPackageManifest() )->build( $this->destination ) )
				&& true === move_dir( $backup['path'], $this->destination, true )
				&& null === self::entry_identity( $backup['path'] );
		} finally {
			$this->clear_maintenance();
		}
	}

	private function clear_maintenance(): void {
		$marker     = $this->maintenance;
		$filesystem = $GLOBALS['wp_filesystem'] ?? null;
		if ( null === $marker ) {
			return;
		}
		if ( self::entry_identity( $marker['path'] ) !== $marker['identity'] ) {
			$this->maintenance = null;
			return;
		}
		if ( $filesystem instanceof \WP_Filesystem_Direct && $filesystem->delete( $marker['path'] ) ) {
			$this->maintenance = null;
		}
	}

	/**
	 * @phpstan-impure Re-read current filesystem identity.
	 * @return EntryIdentity|null
	 */
	private static function entry_identity( string $path ): ?array {
		clearstatcache( true, $path );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Missing entries are expected. Native lstat keeps links and replacements distinct from the WordPress-owned entry.
		$stat = @lstat( $path );
		return is_array( $stat ) ? array(
			'dev'   => $stat['dev'],
			'ino'   => $stat['ino'],
			'mode'  => $stat['mode'],
			'ctime' => $stat['ctime'],
			'mtime' => $stat['mtime'],
			'size'  => $stat['size'],
		) : null;
	}
}
