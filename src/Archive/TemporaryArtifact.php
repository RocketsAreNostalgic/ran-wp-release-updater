<?php

declare(strict_types=1);

namespace RAN\WPReleaseUpdater\V1\Archive;

use RuntimeException;

/** A verified temporary archive whose cleanup remains with this object. */
final class TemporaryArtifact {

	private bool $constructed = false;

	private bool $discard_attempted = false;

	private bool $discarded = false;

	private bool $busy = false;

	private readonly ?\Closure $liveness_guard;

	/** @param array<string, int> $identity */
	public function __construct(
		private string $path,
		private string $sha256,
		private array $identity,
		?callable $liveness_guard = null
	) {
		$this->liveness_guard = null === $liveness_guard ? null : \Closure::fromCallable( $liveness_guard );
		if (
			1 !== preg_match( '/\A[a-f0-9]{64}\z/D', $sha256 )
			|| ! $this->is_unchanged()
		) {
			throw new \InvalidArgumentException( 'The temporary archive is invalid.' );
		}
		$this->constructed = true;
	}

	public function __destruct() {
		if ( $this->constructed && ! $this->busy ) {
			$this->discard();
		}
	}

	/** Temporary artifacts cannot be cloned. */
	private function __clone() {}

	/** @throws \LogicException Always: temporary artifacts cannot be serialized. */
	public function __serialize(): array {
		throw new \LogicException( 'Temporary artifacts cannot be serialized.' );
	}

	/** @param array<string, mixed> $data @throws \LogicException Always: temporary artifacts cannot be unserialized. */
	public function __unserialize( array $data ): void {
		throw new \LogicException( 'Temporary artifacts cannot be unserialized.' );
	}

	/** Inspect the exact bytes without transferring cleanup ownership. */
	public function inspect( callable $inspector ): mixed {
		if ( $this->busy ) {
			throw self::busy_exception();
		}
		$this->assert_available();
		$this->assert_runtime_live();

		$this->busy = true;
		try {
			$result = $inspector( $this->path );
		} finally {
			$this->busy = false;
		}

		$this->assert_available();
		$this->assert_runtime_live();

		return $result;
	}

	/**
	 * Delete only the exact unchanged file while this object owns it.
	 *
	 * A retry can observe changed filesystem state and return a different result.
	 *
	 * @phpstan-impure
	 */
	public function discard(): bool {
		if ( $this->busy ) {
			throw self::busy_exception();
		}
		if ( $this->discarded ) {
			return true;
		}

		$this->discard_attempted = true;
		if ( ! $this->is_unchanged() ) {
			return false;
		}

		@unlink( $this->path );
		clearstatcache( true, $this->path );
		$this->discarded = ! file_exists( $this->path ) && ! is_link( $this->path );
		return $this->discarded;
	}

	private function assert_available(): void {
		if ( $this->discard_attempted || ! $this->is_unchanged() ) {
			throw new RuntimeException( 'The temporary archive is unavailable.', 1001 );
		}
	}

	private function assert_runtime_live(): void {
		if ( null === $this->liveness_guard ) {
			return;
		}

		try {
			$revocation = ( $this->liveness_guard )();
		} catch ( \Throwable ) {
			$revocation = true;
		}
		if ( null !== $revocation ) {
			throw new RuntimeException( 'The selected runtime is unavailable.', 1002 );
		}
	}

	private static function busy_exception(): RuntimeException {
		return new RuntimeException( 'The temporary archive is busy.', 1003 );
	}

	private function is_unchanged(): bool {
		clearstatcache( true, $this->path );
		$identity = self::file_identity( $this->path );
		$sha256   = is_file( $this->path ) ? hash_file( 'sha256', $this->path ) : false;

		return null !== $identity
			&& $identity === $this->identity
			&& is_string( $sha256 )
			&& hash_equals( $this->sha256, $sha256 );
	}

	/** @return array<string, int>|null */
	private static function file_identity( string $path ): ?array {
		$stat = @lstat( $path );
		if ( ! is_array( $stat ) || is_link( $path ) || 0100000 !== ( (int) $stat['mode'] & 0170000 )
			|| 1 !== (int) $stat['nlink'] || 0600 !== ( (int) $stat['mode'] & 0777 ) ) {
			return null;
		}

		return array(
			'dev'   => (int) $stat['dev'],
			'ino'   => (int) $stat['ino'],
			'mode'  => (int) $stat['mode'],
			'nlink' => (int) $stat['nlink'],
			'uid'   => (int) $stat['uid'],
			'gid'   => (int) $stat['gid'],
			'size'  => (int) $stat['size'],
			'mtime' => (int) $stat['mtime'],
			'ctime' => (int) $stat['ctime'],
		);
	}
}
