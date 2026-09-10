<?php

declare(strict_types=1);

namespace Tests\Archive;

use PHPUnit\Framework\TestCase;
use RAN\WPReleaseUpdater\V1\Archive\TemporaryArtifact;

final class TemporaryArtifactCustodyTest extends TestCase {

	private string $directory;

	protected function setUp(): void {
		$this->directory = sys_get_temp_dir() . '/ran-artifact-custody-' . bin2hex( random_bytes( 8 ) );
		self::assertTrue( mkdir( $this->directory, 0700 ) );
	}

	protected function tearDown(): void {
		@chmod( $this->directory, 0700 );
		foreach ( glob( $this->directory . '/*' ) ?: array() as $path ) {
			@unlink( $path );
		}
		@rmdir( $this->directory );
	}

	public function testInspectChecksContinuityBeforeAndAfterReader(): void {
		$artifact = $this->artifact( 'original' );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionCode( 1001 );
		$artifact->inspect(
			function ( string $path ): string {
				file_put_contents( $path, 'changed' );
				return 'must not escape';
			}
		);
	}

	public function testInspectPreservesCallerExceptionAndClearsBusyState(): void {
		$artifact = $this->artifact( 'original' );
		$expected = new \DomainException( 'reader failure' );

		try {
			$artifact->inspect(
				static function () use ( $expected ): never {
					throw $expected;
				}
			);
			self::fail( 'Expected reader exception.' );
		} catch ( \DomainException $actual ) {
			self::assertSame( $expected, $actual );
		}

		self::assertSame( 'ok', $artifact->inspect( static fn(): string => 'ok' ) );
	}

	public function testInspectAndDiscardRejectReentryWhileReaderIsBusy(): void {
		$artifact = $this->artifact( 'original' );
		$codes    = $artifact->inspect(
			function () use ( $artifact ): array {
				$codes = array();
				foreach ( array(
					static fn() => $artifact->inspect( static fn(): null => null ),
					static fn() => $artifact->discard(),
				) as $operation ) {
					try {
						$operation();
					} catch ( \RuntimeException $exception ) {
						$codes[] = $exception->getCode();
					}
				}
				return $codes;
			}
		);

		self::assertSame( array( 1003, 1003 ), $codes );
		self::assertTrue( $artifact->discard() );
	}

	public function testLivenessIsCheckedBeforeAndAfterSuccessfulReader(): void {
		$live     = true;
		$artifact = $this->artifact(
			'original',
			function () use ( &$live ): ?string {
				return $live ? null : 'runtime_revoked';
			}
		);

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionCode( 1002 );
		$artifact->inspect(
			function () use ( &$live ): string {
				$live = false;
				return 'must not escape';
			}
		);
	}

	public function testDiscardRemainsAvailableAfterRuntimeLoss(): void {
		$artifact = $this->artifact( 'original', static fn(): string => 'runtime_revoked' );

		$this->expectExceptionCode( 1002 );
		try {
			$artifact->inspect( static fn(): null => null );
		} finally {
			self::assertTrue( $artifact->discard() );
		}
	}

	public function testDiscardAttemptDeniesFurtherUseAndCanRetryUnchangedFile(): void {
		$artifact = $this->artifact( 'original' );
		chmod( $this->directory, 0500 );
		try {
			self::assertFalse( $artifact->discard() );
		} finally {
			chmod( $this->directory, 0700 );
		}

		try {
			$artifact->inspect( static fn(): null => null );
			self::fail( 'A discard attempt must deny later use.' );
		} catch ( \RuntimeException $exception ) {
			self::assertSame( 1001, $exception->getCode() );
		}
		self::assertTrue( $artifact->discard() );
		self::assertTrue( $artifact->discard() );
	}

	public function testDiscardWillNotDeleteForeignReplacement(): void {
		$artifact = $this->artifact( 'original' );
		$path     = $this->path();
		self::assertTrue( rename( $path, $path . '.owned' ) );
		file_put_contents( $path, 'foreign' );
		chmod( $path, 0600 );

		self::assertFalse( $artifact->discard() );
		self::assertSame( 'foreign', file_get_contents( $path ) );
	}

	public function testFailedCloneLeavesOriginalArtifactUsable(): void {
		$artifact = $this->artifact( 'original' );
		try {
			clone $artifact;
			self::fail( 'Cloning must be denied.' );
		} catch ( \Error ) {
		}

		self::assertSame( 'ok', $artifact->inspect( static fn(): string => 'ok' ) );
		self::assertTrue( $artifact->discard() );
	}

	public function testSerializationIsDenied(): void {
		$this->expectException( \LogicException::class );
		serialize( $this->artifact( 'original' ) );
	}

	public function testCraftedUnserializeFailsWithoutDamagingOriginalArtifact(): void {
		$artifact = $this->artifact( 'original' );
		$class    = TemporaryArtifact::class;
		$payload  = sprintf( 'O:%d:"%s":0:{}', strlen( $class ), $class );

		try {
			unserialize( $payload, array( 'allowed_classes' => array( $class ) ) );
			self::fail( 'Unserialization must be denied.' );
		} catch ( \LogicException ) {
		}

		self::assertSame( 'ok', $artifact->inspect( static fn(): string => 'ok' ) );
		self::assertTrue( $artifact->discard() );
	}

	private function artifact( string $contents, ?callable $livenessGuard = null ): TemporaryArtifact {
		$path = $this->path();
		file_put_contents( $path, $contents );
		chmod( $path, 0600 );
		$stat = lstat( $path );
		self::assertIsArray( $stat );

		return new TemporaryArtifact(
			$path,
			hash_file( 'sha256', $path ),
			array(
				'dev'   => (int) $stat['dev'],
				'ino'   => (int) $stat['ino'],
				'mode'  => (int) $stat['mode'],
				'nlink' => (int) $stat['nlink'],
				'uid'   => (int) $stat['uid'],
				'gid'   => (int) $stat['gid'],
				'size'  => (int) $stat['size'],
				'mtime' => (int) $stat['mtime'],
				'ctime' => (int) $stat['ctime'],
			),
			$livenessGuard
		);
	}

	private function path(): string {
		return $this->directory . '/artifact.zip';
	}
}
