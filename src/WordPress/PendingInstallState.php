<?php

declare(strict_types=1);

namespace RAN\WPReleaseUpdater\V1\WordPress;

use RAN\WPReleaseUpdater\V1\Contract\AcquisitionReceipt;

/** Constrains the mutable state of one admitted native install operation. */
final class PendingInstallState {

	private bool $active = false;
	private bool $extractionAdmitted = false;
	/** @var array<string,array{sha256:string,size:int}>|null */
	private ?array $stagedManifest = null;
	private ?string $archive = null;
	private ?string $archiveDirectory = null;
	/** @var array{dev:int,ino:int,mode:int,mtime:int,ctime:int,size:int}|null */
	private ?array $archiveIdentity = null;
	private ?AcquisitionReceipt $receipt = null;
	private bool $installResultCaptured = false;
	private mixed $installResult = null;
	private bool $completionObserved = false;
	private bool $multiRun = false;

	/** @param array{dev:int,ino:int,mode:int,mtime:int,ctime:int,size:int} $identity */
	public function begin( string $archive, string $directory, array $identity, AcquisitionReceipt $receipt, bool $multiRun ): void {
		$this->clear();
		$this->active           = true;
		$this->archive          = $archive;
		$this->archiveDirectory = $directory;
		$this->archiveIdentity  = $identity;
		$this->receipt          = $receipt;
		$this->multiRun         = $multiRun;
	}

	public function clear(): void {
		$this->active                = false;
		$this->extractionAdmitted    = false;
		$this->stagedManifest        = null;
		$this->archive               = null;
		$this->archiveDirectory      = null;
		$this->archiveIdentity       = null;
		$this->receipt               = null;
		$this->installResultCaptured = false;
		$this->installResult         = null;
		$this->completionObserved    = false;
		$this->multiRun              = false;
	}

	public function active(): bool { return $this->active; }
	public function archive(): ?string { return $this->archive; }
	public function archiveDirectory(): ?string { return $this->archiveDirectory; }
	/** @return array{dev:int,ino:int,mode:int,mtime:int,ctime:int,size:int}|null */
	public function archiveIdentity(): ?array { return $this->archiveIdentity; }
	public function receipt(): ?AcquisitionReceipt { return $this->receipt; }
	public function extractionAdmitted(): bool { return $this->extractionAdmitted; }
	public function installResultCaptured(): bool { return $this->installResultCaptured; }
	public function installResult(): mixed { return $this->installResult; }
	public function completionObserved(): bool { return $this->completionObserved; }
	public function multiRun(): bool { return $this->multiRun; }
	/** @return array<string,array{sha256:string,size:int}>|null */
	public function stagedManifest(): ?array { return $this->stagedManifest; }

	public function admitExtraction(): bool {
		if ( ! $this->active || null === $this->archive || null === $this->archiveIdentity || null === $this->receipt ) {
			return false;
		}
		$this->extractionAdmitted = true;
		return true;
	}

	/** @param array<string,array{sha256:string,size:int}> $manifest */
	public function stageManifest( array $manifest ): bool {
		if ( ! $this->active || ! $this->extractionAdmitted ) {
			return false;
		}
		$this->stagedManifest = $manifest;
		return true;
	}

	public function captureInstallResult( mixed $result ): bool {
		if ( ! $this->active || ! $this->extractionAdmitted ) {
			return false;
		}
		$this->installResultCaptured = true;
		$this->installResult         = $result;
		return true;
	}

	public function observeCompletion(): void {
		if ( $this->active ) {
			$this->completionObserved = true;
		}
	}
}
