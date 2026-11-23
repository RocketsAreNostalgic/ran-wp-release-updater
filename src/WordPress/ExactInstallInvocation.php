<?php

declare(strict_types=1);

namespace RAN\WPReleaseUpdater\V1\WordPress;

use RAN\WPReleaseUpdater\V1\Archive\ValidatedPackage;
use RAN\WPReleaseUpdater\V1\Contract\IdentityDescriptor;

/**
 * Request-local custody and completion evidence for one exact native upgrade.
 *
 * @internal
 * @phpstan-type OwnedCopy array{directory:string,path:string,proof:ValidatedPackage}
 * @phpstan-type ArchiveIdentity array{dev:int,ino:int,mode:int,mtime:int,ctime:int,size:int}
 * @phpstan-type Finalization array{commit:string,host_completion:string,lease:string,archive_cleanup:string}
 */
final class ExactInstallInvocation {

	public ?IdentityDescriptor $descriptor = null;
	/** @var OwnedCopy|null */
	public ?array $archive = null;
	/** @var array{path:string,directory:string}|null */
	public ?array $archive_location = null;
	/** @var ArchiveIdentity|null */
	public ?array $archive_identity      = null;
	public bool $download_consumed       = false;
	public bool $lease_claimed           = false;
	public bool $wordpress_started       = false;
	public bool $backup_deleted          = false;
	public bool $artifact_cleanup_failed = false;
	public ?string $download_failure     = null;
	/** @var Finalization|null */
	public ?array $finalization = null;

	private function __construct(
		public readonly \Plugin_Upgrader|\Theme_Upgrader $upgrader,
		public readonly string $token,
		public readonly string $installed_version,
		private readonly ExactInstallRecovery $recovery,
		private readonly \WP_Upgrader_Skin $skin
	) {}

	public static function create( string $target_type, string $directory, string $token, string $installed_version ): self {
		$recovery = new ExactInstallRecovery( $directory, $target_type );
		if ( 'plugin' === $target_type ) {
			$skin     = new class() extends \Bulk_Plugin_Upgrader_Skin {
				public function flush_output(): void {
					// The operation buffers progress without flushing the caller's response.
				}
			};
			$upgrader = new class( $skin, $recovery ) extends \Plugin_Upgrader {
				public function __construct( \Bulk_Plugin_Upgrader_Skin $skin, private readonly ExactInstallRecovery $recovery ) {
					parent::__construct( $skin );
				}

				public function move_to_temp_backup_dir( $args ): bool|\WP_Error {
					return $this->recovery->move_backup( $args, parent::move_to_temp_backup_dir( ... ) );
				}

				public function restore_temp_backup( mixed $temp_backups = array() ): bool|\WP_Error {
					unset( $temp_backups );
					return $this->recovery->restore_backup( parent::restore_temp_backup( ... ) );
				}

				public function delete_temp_backup( mixed $temp_backups = array() ): bool|\WP_Error {
					unset( $temp_backups );
					return $this->recovery->delete_backup( parent::delete_temp_backup( ... ) );
				}

				public function maintenance_mode( $enable = false ): void {
					$this->recovery->maintenance_mode( true === $enable, parent::maintenance_mode( ... ) );
				}
			};
		} else {
			$skin     = new class() extends \Theme_Upgrader_Skin {
				public function feedback( $feedback, ...$args ): void {
					// The stock feedback method calls show_message(), which flushes every buffer.
					unset( $feedback, $args );
				}
			};
			$upgrader = new class( $skin, $recovery ) extends \Theme_Upgrader {
				public function __construct( \Theme_Upgrader_Skin $skin, private readonly ExactInstallRecovery $recovery ) {
					parent::__construct( $skin );
				}

				public function move_to_temp_backup_dir( $args ): bool|\WP_Error {
					return $this->recovery->move_backup( $args, parent::move_to_temp_backup_dir( ... ) );
				}

				public function restore_temp_backup( mixed $temp_backups = array() ): bool|\WP_Error {
					unset( $temp_backups );
					return $this->recovery->restore_backup( parent::restore_temp_backup( ... ) );
				}

				public function delete_temp_backup( mixed $temp_backups = array() ): bool|\WP_Error {
					unset( $temp_backups );
					return $this->recovery->delete_backup( parent::delete_temp_backup( ... ) );
				}

				public function maintenance_mode( $enable = false ): void {
					$this->recovery->maintenance_mode( true === $enable, parent::maintenance_mode( ... ) );
				}
			};
		}
		return new self( $upgrader, $token, $installed_version, $recovery, $skin );
	}

	public function run( string $identity ): mixed {
		$this->wordpress_started = true;
		$level                   = ob_get_level();
		$upgrader                = $this->upgrader;
		$theme_cache_args        = $upgrader instanceof \Theme_Upgrader ? self::theme_cache_action_arguments() : null;
		// Core's translation upgrader uses its own output-flushing skin. Leave its offers for a later update.
		$defer_translations = static fn (): bool => false;
		add_filter( 'async_update_translation', $defer_translations, PHP_INT_MAX, 0 );
		ob_start();
		try {
			if ( $upgrader instanceof \Plugin_Upgrader ) {
				$results = $upgrader->bulk_upgrade( array( $identity ) );
				return is_array( $results ) && array( $identity ) === array_keys( $results ) ? $results[ $identity ] : false;
			}
			$result = $upgrader->upgrade( $identity );
			return true === $result ? $upgrader->result : $result;
		} finally {
			remove_filter( 'async_update_translation', $defer_translations, PHP_INT_MAX );
			$this->remove_upgrade_filters( $theme_cache_args );
			if ( ob_get_level() === $level + 1 ) {
				ob_end_clean();
			}
		}
	}

	private function remove_upgrade_filters( ?int $theme_cache_args ): void {
		$upgrader = $this->upgrader;
		if ( $upgrader instanceof \Plugin_Upgrader ) {
			remove_filter( 'upgrader_clear_destination', array( $upgrader, 'delete_old_plugin' ), 10 );
			remove_filter( 'upgrader_source_selection', array( $upgrader, 'check_package' ), 10 );
			return;
		}

		remove_filter( 'upgrader_pre_install', array( $upgrader, 'current_before' ), 10 );
		remove_filter( 'upgrader_post_install', array( $upgrader, 'current_after' ), 10 );
		remove_filter( 'upgrader_clear_destination', array( $upgrader, 'delete_old_theme' ), 10 );
		if ( null === $theme_cache_args ) {
			remove_action( 'upgrader_process_complete', 'wp_clean_themes_cache', 9 );
		} else {
			// Theme_Upgrader may remove a pre-existing registration when it removes its own.
			add_action( 'upgrader_process_complete', 'wp_clean_themes_cache', 9, $theme_cache_args );
		}
	}

	private static function theme_cache_action_arguments(): ?int {
		$hook = $GLOBALS['wp_filter']['upgrader_process_complete'] ?? null;
		// Check the precise priority slot used by Theme_Upgrader. The callback can have other priorities.
		return $hook instanceof \WP_Hook ? ( $hook->callbacks[9]['wp_clean_themes_cache']['accepted_args'] ?? null ) : null;
	}

	/** @phpstan-impure The Core hook graph can change during filesystem cleanup. */
	public function restore_pending(): bool {
		return false !== has_action( 'shutdown', array( $this->upgrader, 'restore_temp_backup' ) );
	}

	public function maintenance_pending(): bool {
		return $this->recovery->maintenance_pending();
	}

	/** @param array<string,array{sha256:string,size:int}> $files */
	public function expect_incoming( array $files ): void {
		$this->recovery->expect_incoming( $files );
	}

	public function uses_original_skin(): bool {
		return $this->upgrader->skin === $this->skin;
	}

	public function recover_at_shutdown(): bool {
		return $this->recovery->recover_at_shutdown( $this->backup_deleted || $this->restore_pending() || false !== $this->backup_deletion_priority() );
	}

	/** Complete only this instance's scheduled deletion, after ruling out its scheduled restoration. */
	public function finish_backup(): ?string {
		$delete = array( $this->upgrader, 'delete_temp_backup' );
		if ( $this->restore_pending() ) {
			return 'backup_restore_pending';
		}
		if ( 100 !== $this->backup_deletion_priority() ) {
			return 'backup_cleanup_unconfirmed';
		}
		try {
			$deleted = $this->upgrader->delete_temp_backup();
		} catch ( \Throwable ) {
			return 'backup_cleanup_failed';
		}
		if ( true !== $deleted || ! remove_action( 'shutdown', $delete, 100 ) ) {
			return 'backup_cleanup_failed';
		}
		if ( $this->restore_pending() || false !== $this->backup_deletion_priority() ) {
			return 'backup_cleanup_unconfirmed';
		}
		$this->backup_deleted = true;
		return null;
	}

	/** @phpstan-impure Core and the host can add or remove the callback. */
	private function backup_deletion_priority(): bool|int {
		return has_action( 'shutdown', array( $this->upgrader, 'delete_temp_backup' ) );
	}
}
