<?php

declare(strict_types=1);

namespace RAN\WPReleaseUpdater\V1\Runtime;

use RAN\WPReleaseUpdater\V1\Contract\ReleaseRejection;

/** @internal Closed provider/source failure facts; never expose exception messages. */
final class ReleaseFailure extends \RuntimeException {

	private const CODES   = array( 'invalid_configuration', 'invalid_release', 'runtime_not_ready', 'runtime_unavailable', 'provider_unavailable', 'filesystem_unsupported', 'credential_unavailable', 'repository_access_unavailable', 'rate_limited', 'release_unavailable', 'package_incompatible', 'release_changed', 'operation_failed', 'cleanup_failed' );
	private const CLEANUP = array( 'not_applicable', 'complete', 'retained', 'failed' );
	public readonly ?string $rejection_reason;
	public readonly ?string $rejection_detail;
	public function __construct(
		public readonly string $release_code,
		public readonly ?int $retry_after = null,
		public readonly string $cleanup_status = 'not_applicable',
		?\Throwable $previous = null,
		?string $rejection_reason = null,
		?string $rejection_detail = null
	) {
		$valid                  = 'package_incompatible' === $release_code && null !== $rejection_reason && ReleaseRejection::valid( $rejection_reason );
		$this->rejection_reason = $valid ? $rejection_reason : null;
		$this->rejection_detail = $valid ? ReleaseRejection::detail( (string) $rejection_reason, $rejection_detail ) : null;
		parent::__construct( 'Release operation failed.', 0, $previous );
	}

	/** A `package_incompatible` failure that names its closed reason. */
	public static function rejected( string $reason, ?string $detail = null ): self {
		return new self( 'package_incompatible', null, 'not_applicable', null, $reason, $detail );
	}

	public static function valid_code( string $code ): bool {
		return in_array( $code, self::CODES, true );
	}

	public static function valid_cleanup( string $status ): bool {
		return in_array( $status, self::CLEANUP, true );
	}
}
