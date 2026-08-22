<?php

declare(strict_types=1);

namespace RAN\WPReleaseUpdater\V1\Provider\GitHub;

use RAN\WPReleaseUpdater\V1\Archive\TemporaryArtifact;
use RuntimeException;

/** Owns an exactly reacquired prospective artifact until custody is claimed. */
final class ProspectiveReleaseArtifact
{
	private bool $claimed = false;
	private ?TemporaryArtifact $artifact;

	public function __construct(
		private ProspectiveReleaseInspection $inspection,
		TemporaryArtifact $artifact
	) {
		$this->artifact = $artifact;
	}

	private function __clone(): void
	{
	}

	public function __destruct()
	{
		$this->discard();
	}

	public function inspection(): ProspectiveReleaseInspection
	{
		return $this->inspection;
	}

	public function claimTemporaryArtifact(): TemporaryArtifact
	{
		if ($this->claimed || ! $this->artifact instanceof TemporaryArtifact) {
			throw new RuntimeException('The prospective GitHub release artifact is unavailable.');
		}
		$artifact = $this->artifact;
		$this->artifact = null;
		$this->claimed = true;
		return $artifact;
	}

	public function discard(): bool
	{
		if (! $this->artifact instanceof TemporaryArtifact) {
			return true;
		}
		$result = $this->artifact->discard();
		if ($result) {
			$this->artifact = null;
		}
		return $result;
	}
}
