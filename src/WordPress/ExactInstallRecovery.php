<?php

declare(strict_types=1);

namespace RAN\WPReleaseUpdater\V1\WordPress;

/**
 * Tracks WordPress filesystem changes made before Core can schedule recovery.
 *
 * @internal
 * @phpstan-import-type EntryIdentity from StagedPackageManifest
 * @phpstan-import-type PackageTree from StagedPackageManifest
 * @phpstan-type OwnedEntry array{path:string,identity:EntryIdentity}
 */
final class ExactInstallRecovery {

	/** @var array{path:string,identity:EntryIdentity,digest:string}|null */
	private ?array $backup = null;
	/** @var OwnedEntry|null */
	private ?array $maintenance = null;
	/** @var PackageTree|null */
	private ?array $incoming = null;

	private readonly string $destination;
	private readonly StagedPackageManifest $manifest;

	public function __construct( string $installed_directory, private readonly string $target_type ) {
		$this->destination = wp_normalize_path( rtrim( $installed_directory, '/\\' ) );
		$this->manifest    = new StagedPackageManifest();
	}

	/** @param PackageTree $incoming */
	public function expect_incoming( array $incoming ): void {
		$this->incoming = $incoming;
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

		$path            = trailingslashit( $content ) . 'upgrade-temp-backup/' . $group . '/' . $slug;
		$previous_backup = StagedPackageManifest::entry_identity( $path );
		$source_identity = StagedPackageManifest::entry_identity( $this->destination );
		$source_tree     = $this->manifest->snapshot( $this->destination );
		$digest          = null === $source_tree || StagedPackageManifest::entry_identity( $this->destination ) !== $source_identity
			? null : $this->manifest->tree_digest( $source_tree );
		$result          = false;
		try {
			$result = $move( $arguments );
			return $result;
		} finally {
			if ( null !== $source_identity && null !== $digest ) {
				$this->capture_backup( $path, $previous_backup, $source_identity, $digest, true === $result );
			}
		}
	}

	/**
	 * @param EntryIdentity|null $previous_backup
	 * @param EntryIdentity $source
	 */
	private function capture_backup( string $path, ?array $previous_backup, array $source, string $digest, bool $move_succeeded ): void {
		$identity = StagedPackageManifest::entry_identity( $path );
		if ( null === $identity || 0040000 !== ( $identity['mode'] & 0170000 ) || $identity === $previous_backup ) {
			return;
		}
		// A rename retains the source inode even if Core throws in cache invalidation.
		$renamed = $identity['dev'] === $source['dev'] && $identity['ino'] === $source['ino'];
		$copied  = $move_succeeded && null === StagedPackageManifest::entry_identity( $this->destination );
		if ( $renamed || $copied ) {
			$this->backup = array(
				'path'     => $path,
				'identity' => $identity,
				'digest'   => $digest,
			);
		}
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
		if ( null !== StagedPackageManifest::entry_identity( $path ) ) {
			return;
		}

		try {
			$toggle( true );
		} finally {
			$identity = StagedPackageManifest::entry_identity( $path );
			if ( null !== $identity && 0100000 === ( $identity['mode'] & 0170000 ) ) {
				$this->maintenance = array(
					'path'     => $path,
					'identity' => $identity,
				);
			}
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
			if ( ! ( ( $GLOBALS['wp_filesystem'] ?? null ) instanceof \WP_Filesystem_Direct )
				|| StagedPackageManifest::entry_identity( $backup['path'] ) !== $backup['identity']
			) {
				return false;
			}
			$backup_tree = $this->manifest->snapshot( $backup['path'] );
			return null !== $backup_tree
				&& hash_equals( $backup['digest'], $this->manifest->tree_digest( $backup_tree ) )
				&& $this->destination_is_replaceable()
				&& true === move_dir( $backup['path'], $this->destination, true )
				&& null === StagedPackageManifest::entry_identity( $backup['path'] );
		} finally {
			$this->clear_maintenance();
		}
	}

	private function destination_is_replaceable(): bool {
		$identity = StagedPackageManifest::entry_identity( $this->destination );
		if ( null === $identity ) {
			return true;
		}
		$current = $this->manifest->snapshot( $this->destination );
		if ( null === $current || StagedPackageManifest::entry_identity( $this->destination ) !== $identity ) {
			return false;
		}
		$incoming_files = $this->incoming['files'] ?? array();
		$incoming_dirs  = $this->incoming['directories'] ?? array();
		return array_intersect_key( $incoming_files, $current['files'] ) === $current['files']
			&& array() === array_diff_key( $current['directories'], $incoming_dirs );
	}

	private function clear_maintenance(): void {
		$marker     = $this->maintenance;
		$filesystem = $GLOBALS['wp_filesystem'] ?? null;
		if ( null === $marker ) {
			return;
		}
		if ( StagedPackageManifest::entry_identity( $marker['path'] ) !== $marker['identity'] ) {
			$this->maintenance = null;
			return;
		}
		if ( $filesystem instanceof \WP_Filesystem_Direct && $filesystem->delete( $marker['path'] ) ) {
			$this->maintenance = null;
		}
	}
}
