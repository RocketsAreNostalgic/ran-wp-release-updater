<?php

declare(strict_types=1);

namespace RAN\WPReleaseUpdater\V1\Archive;

use RuntimeException;

/** A verified temporary archive whose cleanup remains with this object. */
final class TemporaryArtifact
{
	private ?bool $discardResult = null;
	private ?string $coreTargetType = null;
	private ?string $coreTargetIdentifier = null;
	private ?string $coreExpectedVersion = null;
	private bool $coreUpdateAccepted = false;

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

	public static function forCoreUpdate(string $path, string $sha256, string $type, string $identifier, string $expectedVersion): self
	{
		if (! self::validCoreTarget($type, $identifier)
			|| 1 !== preg_match('/\A[A-Za-z0-9][A-Za-z0-9._+-]{0,63}\z/D', $expectedVersion)) {
			throw new \InvalidArgumentException('The Core update artifact is invalid.');
		}
		$identity = self::fileIdentity($path);
		if (! is_array($identity)) {
			throw new \InvalidArgumentException('The temporary archive is invalid.');
		}
		$artifact = new self($path, $sha256, $identity);
		$artifact->coreTargetType = $type;
		$artifact->coreTargetIdentifier = $identifier;
		$artifact->coreExpectedVersion = $expectedVersion;
		return $artifact;
	}

	/** Inspect the exact bytes without transferring cleanup ownership. */
	public function inspect(callable $inspector): mixed
	{
		if (null !== $this->discardResult || ! $this->isUnchanged()) {
			throw new RuntimeException('The temporary archive is unavailable.');
		}

		return $inspector($this->path);
	}

	public function acceptCoreUpdate(string $type, string $identifier, string $action, string $path): string
	{
		if ($this->coreUpdateAccepted || ! is_string($this->coreTargetType)
			|| ! is_string($this->coreTargetIdentifier) || ! is_string($this->coreExpectedVersion)
			|| 'update' !== $action || ! hash_equals($this->coreTargetType, $type)
			|| ! hash_equals($this->coreTargetIdentifier, $identifier) || ! hash_equals($this->path, $path)) {
			throw new RuntimeException('The Core update artifact is unavailable.');
		}
		$this->inspect(static fn(string $currentPath): string => $currentPath);
		$this->coreUpdateAccepted = true;
		return $this->coreExpectedVersion;
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

	private static function validCoreTarget(string $type, string $identifier): bool
	{
		if ('theme' === $type) {
			return 1 === preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,99}\z/D', $identifier);
		}
		return 'plugin' === $type
			&& 1 === preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,99}\/[A-Za-z0-9][A-Za-z0-9._-]{0,99}\.php\z/D', $identifier);
	}
}
