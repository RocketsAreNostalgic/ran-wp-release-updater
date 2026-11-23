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

	public function __construct(
		public readonly \Plugin_Upgrader|\Theme_Upgrader $upgrader,
		public readonly string $token,
		public readonly string $installed_version
	) {}

	public function run( string $identity ): mixed {
		$this->wordpress_started = true;
		$level                   = ob_get_level();
		ob_start();
		try {
			if ( $this->upgrader instanceof \Plugin_Upgrader ) {
				$results = $this->upgrader->bulk_upgrade( array( $identity ) );
				return is_array( $results ) && array( $identity ) === array_keys( $results ) ? $results[ $identity ] : false;
			}
			$result = $this->upgrader->upgrade( $identity );
			return true === $result ? $this->upgrader->result : $result;
		} finally {
			if ( ob_get_level() === $level + 1 ) {
				ob_end_clean();
			}
		}
	}

	/** @phpstan-impure The Core hook graph can change during filesystem cleanup. */
	public function restore_pending(): bool {
		return false !== has_action( 'shutdown', array( $this->upgrader, 'restore_temp_backup' ) );
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
