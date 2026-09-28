<?php

declare(strict_types=1);

namespace Tests\Provider;

use PHPUnit\Framework\TestCase;
use RAN\WPReleaseUpdater\V1\Provider\GitHub\GitHubApiClient;
use RAN\WPReleaseUpdater\V1\Provider\GitHub\GitHubArtifactCustodyFailure;
use RAN\WPReleaseUpdater\V1\Runtime\ReleaseFailure;

final class NamedConstructorContractTest extends TestCase {

	public function test_client_named_guard_refuses_transport(): void {
		$client = new GitHubApiClient(
			liveness_guard: static function (): void {
				throw new \RuntimeException( 'Named guard refused transport.' );
			}
		);
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Named guard refused transport.' );
		$client->request( 'https://api.github.com/repos/example/example', null, array(), 1024 );
	}

	public function test_custody_named_cleanup_outcome_is_preserved(): void {
		foreach ( array( true, false ) as $complete ) {
			$failure = new GitHubArtifactCustodyFailure( cleanup_complete: $complete, message: 'Custody failed.' );
			self::assertSame( $complete, $failure->cleanup_complete );
			self::assertSame( 'Custody failed.', $failure->getMessage() );
		}
	}

	public function test_release_named_facts_and_exception_cause_are_preserved(): void {
		$cause   = new \RuntimeException( 'Private provider detail.' );
		$failure = new ReleaseFailure( release_code: 'rate_limited', retry_after: 42, cleanup_status: 'complete', previous: $cause );
		self::assertSame( 'rate_limited', $failure->release_code );
		self::assertSame( 42, $failure->retry_after );
		self::assertSame( 'complete', $failure->cleanup_status );
		self::assertSame( $cause, $failure->getPrevious() );
		self::assertSame( 'Release operation failed.', $failure->getMessage() );
	}
}
