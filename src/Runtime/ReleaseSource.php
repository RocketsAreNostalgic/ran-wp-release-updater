<?php

declare(strict_types=1);

namespace RAN\WPReleaseUpdater\V1\Runtime;

use RAN\WPReleaseUpdater\V1\Archive\TemporaryArtifact;

/** @internal Selected-runtime operations; bootstrap owns the exact wire validator. */
final class ReleaseSource
{
	private bool $terminalUnavailable = false;

	public function __construct(private object $service, private SelectedRuntimeState $state)
	{
	}

	private static function directFilesystemAvailable(): bool
	{
		return (defined('FS_METHOD') && 'direct' === FS_METHOD)
			|| (! defined('FS_METHOD') && (($GLOBALS['wp_filesystem'] ?? null) instanceof \WP_Filesystem_Direct));
	}

	public function list(array $conditional = array()): array
	{
		foreach ($conditional as $key => $value) {
			if (! in_array($key, array('etag', 'last_modified'), true) || (null !== $value && ! is_string($value))) {
				return $this->invalid('invalid_configuration');
			}
		}
		return $this->operate('list', array($conditional));
	}

	public function inspect(string $releaseId, string $expectedTag): array
	{
		if (! $this->opaque($releaseId) || ! $this->opaque($expectedTag)) {
			return $this->invalid('invalid_release');
		}
		return $this->operate('inspect', array($releaseId, $expectedTag));
	}

	public function acquire(string $releaseId, string $expectedTag, string $expectedFingerprint): array
	{
		if (! $this->opaque($releaseId) || ! $this->opaque($expectedTag) || 1 !== preg_match('/\Av2:[a-f0-9]{64}\z/D', $expectedFingerprint)) {
			return $this->invalid('invalid_release');
		}
		return $this->operate('acquire', array($releaseId, $expectedTag, $expectedFingerprint));
	}

	private function operate(string $method, array $arguments): array
	{
		if (null !== ($failure = $this->readiness())) {
			return $failure;
		}
		if (! self::directFilesystemAvailable()) {
			return $this->failure(new ReleaseFailure('filesystem_unsupported'));
		}
		$artifact = null;
		$cleanup = 'not_applicable';
		try {
			$value = $this->service->{$method}(...$arguments);
			$cleanup = 'inspect' === $method ? 'complete' : 'not_applicable';
			if ('acquire' === $method) {
				$candidate = is_array($value) ? ($value['artifact'] ?? null) : null;
				$artifact = $candidate instanceof TemporaryArtifact ? $candidate : null;
				$cleanup = 'failed';
				if (! is_array($value) || array_keys($value) !== array('inspection', 'artifact')
					|| ! is_array($value['inspection']) || null === $artifact) {
					throw new ReleaseFailure('operation_failed', null, $cleanup);
				}
				$cleanup = 'retained';
			}
			if (! is_array($value)) {
				throw new ReleaseFailure('operation_failed', null, $cleanup);
			}
			if (null !== $this->readiness()) {
				throw new ReleaseFailure('runtime_unavailable', null, $cleanup);
			}
			$code = match ($method) {
				'list' => ($value['not_modified'] ?? false) ? 'releases_not_modified' : 'releases_listed',
				'inspect' => 'release_inspected',
				'acquire' => 'release_acquired',
			};
			return array('ok' => true, 'code' => $code, 'value' => $value, 'retry_after' => null, 'cleanup_status' => $cleanup);
		} catch (\Throwable $failure) {
			if (null !== $artifact) {
				$cleanup = $this->discard($artifact);
			}
			if ($failure instanceof ReleaseFailure) {
				return $this->failure(new ReleaseFailure($failure->releaseCode, $failure->retryAfter,
					null !== $artifact ? $cleanup : $failure->cleanupStatus));
			}
			return $this->failure(new ReleaseFailure('operation_failed', null, $cleanup));
		}
	}

	private function readiness(): ?array
	{
		if ($this->terminalUnavailable) {
			return $this->failure(new ReleaseFailure('runtime_unavailable'));
		}
		$code = $this->state->releaseReadinessCode();
		return null === $code ? null : $this->failure(new ReleaseFailure($code));
	}

	private function failure(ReleaseFailure $failure): array
	{
		if ('runtime_unavailable' === $failure->releaseCode) {
			$this->terminalUnavailable = true;
		}
		$code = $failure->releaseCode;
		$cleanup = $failure->cleanupStatus;
		$retry = $failure->retryAfter;
		if (! ReleaseFailure::validCode($code) || ! in_array($cleanup, array('not_applicable', 'complete', 'failed'), true)
			|| ('rate_limited' === $code ? null === $retry || $retry < 1 || $retry > 86400 : null !== $retry)) {
			$code = 'operation_failed';
			$retry = null;
			$cleanup = in_array($cleanup, array('not_applicable', 'complete', 'failed'), true) ? $cleanup : 'failed';
		}
		return array('ok' => false, 'code' => $code, 'value' => null, 'retry_after' => $retry, 'cleanup_status' => $cleanup);
	}

	private function invalid(string $code): array
	{
		if ($this->terminalUnavailable || 'runtime_unavailable' === $this->state->releaseReadinessCode()) {
			return $this->failure(new ReleaseFailure('runtime_unavailable'));
		}
		return $this->failure(new ReleaseFailure($code));
	}

	private function opaque(string $value): bool
	{
		return '' !== $value && 191 >= strlen($value) && 1 === preg_match('//u', $value)
			&& 1 === preg_match('/\A[^\p{C}\p{Z}\s]+\z/u', $value);
	}

	private function discard(TemporaryArtifact $artifact): string
	{
		try {
			return $artifact->discard() || $artifact->discard() ? 'complete' : 'failed';
		} catch (\Throwable) {
			return 'failed';
		}
	}
}
