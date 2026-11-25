<?php

declare(strict_types=1);

namespace RAN\WPReleaseUpdater\V1\WordPress;

use RAN\WPReleaseUpdater\V1\Contract\IdentityDescriptor;

/**
 * Constrains the mutable state of one admitted native install operation.
 *
 * @phpstan-type ArchiveProof array{descriptor_fingerprint:string,sha256:string,size:int,update_uri:string}
 */
final class PendingInstallState {

	private bool $active               = false;
	private bool $extraction_admitted  = false;
	private bool $staging_verified     = false;
	private ?string $archive           = null;
	private ?string $archive_directory = null;
	/** @var array{dev:int,ino:int,mode:int,mtime:int,ctime:int,size:int}|null */
	private ?array $archive_identity = null;
	/** @var ArchiveProof|null */
	private ?array $archive_proof         = null;
	private bool $install_result_captured = false;
	private mixed $install_result         = null;
	private bool $completion_observed     = false;
	private bool $multi_run               = false;

	/**
	 * @param array{dev:int,ino:int,mode:int,mtime:int,ctime:int,size:int} $identity
	 * @param ArchiveProof                                                  $archive_proof
	 */
	public function begin( string $archive, string $directory, array $identity, array $archive_proof, bool $multi_run ): void {
		$this->clear();
		$this->active            = true;
		$this->archive           = $archive;
		$this->archive_directory = $directory;
		$this->archive_identity  = $identity;
		$this->archive_proof     = $archive_proof;
		$this->multi_run         = $multi_run;
	}

	public function clear(): void {
		$this->active                  = false;
		$this->extraction_admitted     = false;
		$this->staging_verified        = false;
		$this->archive                 = null;
		$this->archive_directory       = null;
		$this->archive_identity        = null;
		$this->archive_proof           = null;
		$this->install_result_captured = false;
		$this->install_result          = null;
		$this->completion_observed     = false;
		$this->multi_run               = false;
	}

	public function active(): bool {
		return $this->active;
	}
	public function archive(): ?string {
		return $this->archive;
	}
	public function archive_directory(): ?string {
		return $this->archive_directory;
	}
	/** @return array{dev:int,ino:int,mode:int,mtime:int,ctime:int,size:int}|null */
	public function archive_identity(): ?array {
		return $this->archive_identity;
	}
	/** @return ArchiveProof|null */
	public function archive_proof(): ?array {
		return $this->archive_proof;
	}
	public function extraction_admitted(): bool {
		return $this->extraction_admitted;
	}
	public function install_result_captured(): bool {
		return $this->install_result_captured;
	}
	public function install_result(): mixed {
		return $this->install_result;
	}
	public function completion_observed(): bool {
		return $this->completion_observed;
	}
	public function multi_run(): bool {
		return $this->multi_run;
	}
	public function staging_verified(): bool {
		return $this->staging_verified;
	}

	public function matches_descriptor( IdentityDescriptor $descriptor ): bool {
		if ( null === $this->archive_proof ) {
			return false;
		}
		$facts = $descriptor->to_array();
		return hash_equals( $this->archive_proof['descriptor_fingerprint'], $descriptor->fingerprint_value() )
			&& hash_equals( $this->archive_proof['sha256'], $facts['artifact_sha256'] )
			&& $this->archive_proof['size'] === $facts['artifact_size']
			&& hash_equals( $this->archive_proof['update_uri'], $facts['canonical_update_uri'] );
	}

	/** Last observed evidence only; this does not assert that a stage succeeded. */
	public function last_observed_step(): ?string {
		return match ( true ) {
			$this->completion_observed => 'completion_observed',
			$this->install_result_captured => 'install_result_captured',
			$this->staging_verified => 'staging_verified',
			$this->extraction_admitted => 'extraction_admitted',
			$this->active => 'archive_admitted',
			default => null,
		};
	}

	public function admit_extraction(): bool {
		if ( ! $this->active || null === $this->archive || null === $this->archive_identity || null === $this->archive_proof ) {
			return false;
		}
		$this->extraction_admitted = true;
		return true;
	}

	public function verify_staging(): bool {
		if ( ! $this->active || ! $this->extraction_admitted ) {
			return false;
		}
		$this->staging_verified = true;
		return true;
	}

	public function capture_install_result( mixed $result ): bool {
		if ( ! $this->active || ! $this->extraction_admitted ) {
			return false;
		}
		$this->install_result_captured = true;
		$this->install_result          = $result;
		return true;
	}

	public function observe_completion(): void {
		if ( $this->active ) {
			$this->completion_observed = true;
		}
	}
}
