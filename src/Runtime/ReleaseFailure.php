<?php

declare(strict_types=1);

namespace RAN\WPReleaseUpdater\V1\Runtime;

/** @internal Closed provider/source failure facts; never expose exception messages. */
final class ReleaseFailure extends \RuntimeException
{
	private const CODES = array('invalid_configuration', 'invalid_release', 'runtime_not_ready', 'runtime_unavailable', 'provider_unavailable', 'filesystem_unsupported', 'credential_unavailable', 'repository_access_unavailable', 'rate_limited', 'release_unavailable', 'package_incompatible', 'release_changed', 'operation_failed', 'cleanup_failed');
	private const CLEANUP = array('not_applicable', 'complete', 'retained', 'failed');
	public function __construct(
		public readonly string $releaseCode,
		public readonly ?int $retryAfter = null,
		public readonly string $cleanupStatus = 'not_applicable',
		?\Throwable $previous = null
	) {
		parent::__construct( 'Release operation failed.', 0, $previous );
	}

	public static function validCode(string $code): bool { return in_array($code, self::CODES, true); }
	public static function validCleanup(string $status): bool { return in_array($status, self::CLEANUP, true); }
}
