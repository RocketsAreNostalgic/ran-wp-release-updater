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
	public bool $ready_to_commit         = false;
	public bool $artifact_cleanup_failed = false;
	public ?string $download_failure     = null;
	/** @var Finalization|null */
	public ?array $finalization = null;

	private function __construct(
		public readonly \Plugin_Upgrader|\Theme_Upgrader $upgrader,
		public readonly string $token,
		public readonly string $installed_version,
		private readonly \WP_Upgrader_Skin $skin
	) {}

	public static function create( string $target_type, string $token, string $installed_version ): self {
		if ( 'plugin' === $target_type ) {
			$skin     = new class() extends \Bulk_Plugin_Upgrader_Skin {
				public function flush_output(): void {
					// The operation buffers progress without flushing the caller's response.
				}
			};
			$upgrader = new \Plugin_Upgrader( $skin );
		} else {
			$skin     = new class() extends \Theme_Upgrader_Skin {
				public function feedback( $feedback, ...$args ): void {
					// The stock feedback method calls show_message(), which flushes every buffer.
					unset( $feedback, $args );
				}
			};
			$upgrader = new \Theme_Upgrader( $skin );
		}
		return new self( $upgrader, $token, $installed_version, $skin );
	}

	public function run( string $identity ): mixed {
		$this->wordpress_started = true;
		$level                   = ob_get_level();
		$upgrader                = $this->upgrader;
		$target_type             = $upgrader instanceof \Plugin_Upgrader ? 'plugin' : 'theme';
		$theme_cache_args        = $upgrader instanceof \Theme_Upgrader ? self::theme_cache_action_arguments() : null;
		$source_admitted         = false;
		$install_package_error   = null;
		// Core's translation upgrader uses its own output-flushing skin. Leave its offers for a later update.
		$defer_translations = static fn (): bool => false;
		$observe_source     = static function ( mixed $source, mixed $remote_source = null, mixed $calling_upgrader = null ) use ( $upgrader, &$source_admitted ): mixed {
			unset( $remote_source );
			if ( $calling_upgrader === $upgrader && ! ( $source instanceof \WP_Error ) ) {
				$source_admitted = true;
			}
			return $source;
		};
		$observe_result     = static function ( mixed $install_result, mixed $hook_extra = null ) use ( $target_type, $identity, &$install_package_error ): mixed {
			if (
				$install_result instanceof \WP_Error
				&& is_array( $hook_extra )
				&& ( $hook_extra['type'] ?? null ) === $target_type
				&& ( $hook_extra['action'] ?? null ) === 'update'
				&& ( $hook_extra[ $target_type ] ?? null ) === $identity
			) {
				$install_package_error = (string) $install_result->get_error_code();
			}
			return $install_result;
		};
		add_filter( 'async_update_translation', $defer_translations, PHP_INT_MAX, 0 );
		add_filter( 'upgrader_source_selection', $observe_source, PHP_INT_MAX, 3 );
		add_filter( 'upgrader_install_package_result', $observe_result, PHP_INT_MIN, 2 );
		ob_start();
		try {
			if ( $upgrader instanceof \Plugin_Upgrader ) {
				$results = $upgrader->bulk_upgrade( array( $identity ) );
				return is_array( $results ) && array( $identity ) === array_keys( $results ) ? $results[ $identity ] : false;
			}
			$upgrade = $upgrader->upgrade( $identity );
			return true === $upgrade ? $upgrader->result : $upgrade;
		} finally {
			remove_filter( 'async_update_translation', $defer_translations, PHP_INT_MAX );
			remove_filter( 'upgrader_source_selection', $observe_source, PHP_INT_MAX );
			remove_filter( 'upgrader_install_package_result', $observe_result, PHP_INT_MIN );
			$backup_move_skipped = ! $source_admitted
				|| in_array( $install_package_error, array( 'fs_no_content_dir', 'fs_temp_backup_mkdir', 'fs_temp_backup_move' ), true );
			if ( $backup_move_skipped ) {
				remove_action( 'shutdown', array( $upgrader, 'restore_temp_backup' ), 10 );
				remove_action( 'shutdown', array( $upgrader, 'delete_temp_backup' ), 100 );
			}
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

	public function uses_original_skin(): bool {
		return $this->upgrader->skin === $this->skin;
	}
}
