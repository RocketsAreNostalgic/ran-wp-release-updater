<?php

declare(strict_types=1);

namespace RAN\WPReleaseUpdater\V1\Archive;

use RuntimeException;

/** A verified temporary archive whose cleanup remains with this object. */
final class TemporaryArtifact
{
	private ?bool $discardResult = null;

	/** @param array<string, int> $identity */
	public function __construct(
		private string $path,
		private string $sha256,
		private array $identity
	) {
		if (
			1 !== preg_match('/\A[a-f0-9]{64}\z/D', $sha256)
			|| ! $this->isUnchanged()
		) {
			throw new \InvalidArgumentException('The temporary archive is invalid.');
		}
	}

	public function __destruct()
	{
		$this->discard();
	}

	/** Inspect the exact bytes without transferring cleanup ownership. */
	public function inspect(callable $inspector): mixed
	{
		if (null !== $this->discardResult || ! $this->isUnchanged()) {
			throw new RuntimeException('The temporary archive is unavailable.');
		}

		return $inspector($this->path);
	}

	/** Delete only the exact unchanged file while this object owns it. */
	public function discard(): bool
	{
		if (null !== $this->discardResult) {
			return $this->discardResult;
		}
		if (! $this->isUnchanged()) {
			return false;
		}

		@unlink($this->path);
		clearstatcache(true, $this->path);
		$this->discardResult = ! file_exists($this->path) && ! is_link($this->path);
		return $this->discardResult;
	}

	private function isUnchanged(): bool
	{
		clearstatcache(true, $this->path);
		$identity = self::fileIdentity($this->path);
		$sha256 = is_file($this->path) ? hash_file('sha256', $this->path) : false;

		return null !== $identity
			&& $identity === $this->identity
			&& is_string($sha256)
			&& hash_equals($this->sha256, $sha256);
	}

	/** @return array<string, int>|null */
	private static function fileIdentity(string $path): ?array
	{
		$stat = @lstat($path);
		if (! is_array($stat) || is_link($path) || 0100000 !== ((int) $stat['mode'] & 0170000)
			|| 1 !== (int) $stat['nlink'] || 0600 !== ((int) $stat['mode'] & 0777)) {
			return null;
		}

		return array('dev' => (int) $stat['dev'], 'ino' => (int) $stat['ino'],
			'mode' => (int) $stat['mode'], 'nlink' => (int) $stat['nlink'],
			'uid' => (int) $stat['uid'], 'gid' => (int) $stat['gid'],
			'size' => (int) $stat['size'], 'mtime' => (int) $stat['mtime'],
			'ctime' => (int) $stat['ctime']);
	}
}
