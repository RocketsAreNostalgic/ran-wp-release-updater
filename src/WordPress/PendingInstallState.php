<?php

declare(strict_types=1);

namespace RAN\WPReleaseUpdater\V1\WordPress;

use RAN\WPReleaseUpdater\V1\Contract\AcquisitionReceipt;

/** Constrains the mutable state of one admitted native install operation. */
final class PendingInstallState {

	private bool $active              = false;
	private bool $extraction_admitted = false;
	/** @var array<string,array{sha256:string,size:int}>|null */
	private ?array $staged_manifest    = null;
	private ?string $archive           = null;
	private ?string $archive_directory = null;
	/** @var array{dev:int,ino:int,mode:int,mtime:int,ctime:int,size:int}|null */
	private ?array $archive_identity      = null;
	private ?AcquisitionReceipt $receipt  = null;
	private bool $install_result_captured = false;
	private mixed $install_result         = null;
	private bool $completion_observed     = false;
	private bool $multi_run               = false;

	/** @param array{dev:int,ino:int,mode:int,mtime:int,ctime:int,size:int} $identity */
	public function begin( string $archive, string $directory, array $identity, AcquisitionReceipt $receipt, bool $multi_run ): void {
		$this->clear();
		$this->active            = true;
		$this->archive           = $archive;
		$this->archive_directory = $directory;
		$this->archive_identity  = $identity;
		$this->receipt           = $receipt;
		$this->multi_run         = $multi_run;
	}

	public function clear(): void {
		$this->active                  = false;
		$this->extraction_admitted     = false;
		$this->staged_manifest         = null;
		$this->archive                 = null;
		$this->archive_directory       = null;
		$this->archive_identity        = null;
		$this->receipt                 = null;
		$this->install_result_captured = false;
		$this->install_result          = null;
		$this->completion_observed     = false;
		$this->multi_run               = false;
	}

	public function active(): bool {
		return $this->active; }
	public function archive(): ?string {
		return $this->archive; }
	public function archive_directory(): ?string {
		return $this->archive_directory; }
	/** @return array{dev:int,ino:int,mode:int,mtime:int,ctime:int,size:int}|null */
	public function archive_identity(): ?array {
		return $this->archive_identity; }
	public function receipt(): ?AcquisitionReceipt {
		return $this->receipt; }
	public function extraction_admitted(): bool {
		return $this->extraction_admitted; }
	public function install_result_captured(): bool {
		return $this->install_result_captured; }
	public function install_result(): mixed {
		return $this->install_result; }
	public function completion_observed(): bool {
		return $this->completion_observed; }
	public function multi_run(): bool {
		return $this->multi_run; }
	/** @return array<string,array{sha256:string,size:int}>|null */
	public function staged_manifest(): ?array {
		return $this->staged_manifest; }

	public function admit_extraction(): bool {
		if ( ! $this->active || null === $this->archive || null === $this->archive_identity || null === $this->receipt ) {
			return false;
		}
		$this->extraction_admitted = true;
		return true;
	}

	/** @param array<string,array{sha256:string,size:int}> $manifest */
	public function stage_manifest( array $manifest ): bool {
		if ( ! $this->active || ! $this->extraction_admitted ) {
			return false;
		}
		$this->staged_manifest = $manifest;
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
