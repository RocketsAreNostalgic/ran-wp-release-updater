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
 * @phpstan-type ParentIdentity array{resolved:string,dev:int,ino:int,mode:int}
 */
final class ExactInstallRecovery {

	/** @var array{path:string,source:string,identity:EntryIdentity,digest:string}|null */
	private ?array $backup = null;
	/** @var OwnedEntry|null */
	private ?array $maintenance = null;
	/** @var PackageTree|null */
	private ?array $incoming      = null;
	private bool $preserve_backup = false;

	private readonly string $destination;
	private readonly string $group;
	private readonly StagedPackageManifest $manifest;
	/** @var ParentIdentity|null */
	private readonly ?array $parent;

	public function __construct( string $installed_directory, string $target_type ) {
		$this->destination = wp_normalize_path( rtrim( $installed_directory, '/\\' ) );
		$this->group       = 'plugin' === $target_type ? 'plugins' : 'themes';
		$this->manifest    = new StagedPackageManifest();
		$this->parent      = $this->parent_identity( dirname( $this->destination ) );
	}

	/** @param array<string,array{sha256:string,size:int}> $files */
	public function expect_incoming( array $files ): void {
		$directories = array();
		foreach ( array_keys( $files ) as $path ) {
			// Only receipt-verified files can establish their enclosing directories.
			for ( $dir = dirname( (string) $path ); '.' !== $dir && ! isset( $directories[ $dir ] ); $dir = dirname( $dir ) ) {
				$directories[ $dir ] = true;
			}
		}
		$this->incoming = array(
			'files'       => $files,
			'directories' => $directories,
		);
	}

	/**
	 * @param (\Closure(array<string,mixed>): (bool|\WP_Error)) $move
	 */
	public function move_backup( mixed $arguments, \Closure $move ): bool|\WP_Error {
		$filesystem = $GLOBALS['wp_filesystem'] ?? null;
		$slug       = basename( $this->destination );
		if (
			! is_array( $arguments )
			|| ! $filesystem instanceof \WP_Filesystem_Direct
			|| ( $arguments['slug'] ?? null ) !== $slug
			|| ( $arguments['dir'] ?? null ) !== $this->group
			|| ! is_string( $arguments['src'] ?? null )
		) {
			return self::unverified_backup_source();
		}

		$source = $arguments['src'];
		$path   = $this->mapped_backup( $filesystem );
		if ( null === $path || ! $this->destination_matches( $filesystem, $source ) ) {
			return self::unverified_backup_source();
		}

		$previous_backup = StagedPackageManifest::entry_identity( $path );
		$source_identity = StagedPackageManifest::entry_identity( $this->destination );
		$source_tree     = $this->manifest->snapshot( $this->destination );
		if ( null === $source_identity || null === $source_tree ) {
			return self::unverified_backup_source();
		}
		// Core's copy fallback skips root entries named after the destination.
		if ( isset( $source_tree['files'][ $slug ] ) || isset( $source_tree['directories'][ $slug ] ) ) {
			return self::unverified_backup_source();
		}
		$digest = $this->manifest->tree_digest( $source_tree );
		if ( $this->parent_changed() || StagedPackageManifest::entry_identity( $this->destination ) !== $source_identity ) {
			return self::unverified_backup_source();
		}
		try {
			$result = $move( $arguments );
		} finally {
			$this->capture_backup( $path, $source, $previous_backup, $source_identity, $digest );
		}
		return true === $result && null === $this->backup ? self::unverified_backup_source() : $result;
	}

	/** @param (\Closure(): (bool|\WP_Error)) $restore */
	public function restore_backup( \Closure $restore ): bool|\WP_Error {
		if ( $this->preserve_backup ) {
			return false;
		}
		// Block deletion during rollback and retain that block if rollback fails.
		$this->preserve_backup = true;
		try {
			$backup     = $this->backup;
			$filesystem = $GLOBALS['wp_filesystem'] ?? null;
			if ( null === $backup || ! $filesystem instanceof \WP_Filesystem_Direct
				|| ! $this->destination_matches( $filesystem, $backup['source'] )
				|| ! $this->has_owned_backup( $filesystem ) || $this->parent_changed()
			) {
				return false;
			}
			$result                = $restore();
			$this->preserve_backup = true !== $result;
			return $result;
		} catch ( \Throwable ) {
			return false;
		}
	}

	/** @param (\Closure(): (bool|\WP_Error)) $delete */
	public function delete_backup( \Closure $delete ): bool|\WP_Error {
		try {
			$filesystem = $GLOBALS['wp_filesystem'] ?? null;
			return ! $this->preserve_backup && $filesystem instanceof \WP_Filesystem_Direct && $this->has_owned_backup( $filesystem )
				? $delete() : false;
		} catch ( \Throwable ) {
			return false;
		}
	}

	private function mapped_backup( \WP_Filesystem_Direct $filesystem ): ?string {
		$content = $filesystem->wp_content_dir();
		// Core appends the backup suffix directly to wp_content_dir().
		return is_string( $content ) && '' !== $content
			? $content . 'upgrade-temp-backup/' . $this->group . '/' . basename( $this->destination )
			: null;
	}

	private function destination_matches( \WP_Filesystem_Direct $filesystem, string $source ): bool {
		$mapped = $filesystem->find_folder( $source );
		if ( ! is_string( $mapped ) || '' === $mapped || null === $this->parent ) {
			return false;
		}
		$destination = trailingslashit( $mapped ) . basename( $this->destination );
		return wp_normalize_path( $destination ) === $this->destination
			&& $this->parent_identity( dirname( $destination ) ) === $this->parent;
	}

	private function has_owned_backup( \WP_Filesystem_Direct $filesystem ): bool {
		$backup = $this->backup;
		if ( null === $backup ) {
			return false;
		}
		$effective = $this->mapped_backup( $filesystem );
		if ( null === $effective || wp_normalize_path( $effective ) !== wp_normalize_path( $backup['path'] )
			|| ( $GLOBALS['wp_filesystem'] ?? null ) !== $filesystem
		) {
			return false;
		}
		$identity = StagedPackageManifest::entry_identity( $effective );
		// A partial deletion can change the directory contents before Core retries.
		return null !== $identity
			&& $identity['dev'] === $backup['identity']['dev']
			&& $identity['ino'] === $backup['identity']['ino']
			&& 0040000 === ( $identity['mode'] & 0170000 );
	}

	private static function unverified_backup_source(): \WP_Error {
		return new \WP_Error( 'ran_wp_release_updater_backup_source_unverified', 'The installed package could not be verified for recovery.' );
	}

	/**
	 * @param EntryIdentity|null $previous_backup
	 * @param EntryIdentity $source
	 */
	private function capture_backup( string $path, string $source_parent, ?array $previous_backup, array $source, string $digest ): void {
		$identity = StagedPackageManifest::entry_identity( $path );
		if ( null === $identity || 0040000 !== ( $identity['mode'] & 0170000 ) || $identity === $previous_backup ) {
			return;
		}
		// A rename retains the source inode even if Core throws in cache invalidation.
		$renamed = 0 !== $source['ino'] && $identity['dev'] === $source['dev'] && $identity['ino'] === $source['ino'];
		if ( ! $renamed ) {
			if ( null !== StagedPackageManifest::entry_identity( $this->destination ) ) {
				return;
			}
			$copied_tree = $this->manifest->snapshot( $path );
			if ( null === $copied_tree || ! hash_equals( $digest, $this->manifest->tree_digest( $copied_tree ) )
				|| StagedPackageManifest::entry_identity( $path ) !== $identity
			) {
				return;
			}
		}
		$this->backup = array(
			'path'     => $path,
			'source'   => $source_parent,
			'identity' => $identity,
			'digest'   => $digest,
		);
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
			if ( $this->preserve_backup ) {
				return false;
			}
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
				&& ! $this->parent_changed()
				&& $this->destination_is_replaceable()
				&& ! $this->parent_changed()
				&& true === move_dir( $backup['path'], $this->destination, true )
				&& null === StagedPackageManifest::entry_identity( $backup['path'] );
		} finally {
			$this->clear_maintenance();
		}
	}

	/** @phpstan-impure The destination parent may change during recovery. */
	private function parent_changed(): bool {
		return null === $this->parent || $this->parent_identity( dirname( $this->destination ) ) !== $this->parent;
	}

	/**
	 * @phpstan-impure Recheck the resolved parent and its native directory identity.
	 * @return ParentIdentity|null
	 */
	private function parent_identity( string $path ): ?array {
		clearstatcache( true );
		$identity = StagedPackageManifest::entry_identity( $path );
		$resolved = realpath( $path );
		if ( null === $identity || 0040000 !== ( $identity['mode'] & 0170000 ) || false === $resolved
			|| StagedPackageManifest::entry_identity( $resolved ) !== $identity
		) {
			return null;
		}
		// Moving a child changes the parent's timestamps and size while preserving its identity.
		return array(
			'resolved' => $resolved,
			'dev'      => $identity['dev'],
			'ino'      => $identity['ino'],
			'mode'     => $identity['mode'],
		);
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
