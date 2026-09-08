<?php

declare(strict_types=1);

namespace Tests\Provider;

use PHPUnit\Framework\TestCase;
use RAN\WPReleaseUpdater\V1\Contract\IdentityDescriptor;
use RAN\WPReleaseUpdater\V1\Provider\GitHub\GitHubReleaseService;
use RAN\WPReleaseUpdater\V1\Runtime\ReleaseFailure;

final class GitHubResponseRoutingTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['ran_github_requests']         = array();
		$GLOBALS['ran_github_responses']        = array();
		$GLOBALS['ran_github_temp_paths']       = array();
		$GLOBALS['ran_github_validate_urls']    = true;
		$GLOBALS['ran_github_request_callback'] = null;
		$GLOBALS['ran_github_chmod_failures']   = 0;
		$GLOBALS['ran_github_lstat_failure_at'] = 0;
		$GLOBALS['ran_github_unlink_failures']  = 0;
	}
	protected function tearDown(): void {
		foreach ( $GLOBALS['ran_github_temp_paths'] as $path ) {
			if ( is_string( $path ) && ( is_file( $path ) || is_link( $path ) ) ) {
				@unlink( $path );
			}
		}
	}
	public function testListingRateFactsStopOnTheFirstAndSecondPage(): void {
		$GLOBALS['ran_github_responses'] = array( $this->response( 429, null, array( 'retry-after' => '12' ) ) );
		$first                           = $this->service()->listReleases();
		self::assertSame( array(), $first['candidates'] );
		self::assertSame(
			array(
				'limited'     => true,
				'remaining'   => null,
				'reset_at'    => null,
				'retry_after' => 12,
			),
			$first['rate_limit']
		);
		self::assertCount( 1, $GLOBALS['ran_github_requests'] );
		self::assertSame( array(), $GLOBALS['ran_github_responses'] );

		$page = array();
		for ( $id = 1; $id <= 20; ++$id ) {
			$release          = $this->release( $id, 'v1.0.' . $id );
			$release['draft'] = true;
			$page[]           = $release; }
		$GLOBALS['ran_github_requests']  = array();
		$GLOBALS['ran_github_responses'] = array( $this->response( 200, $page ), $this->response( 429, null, array( 'retry-after' => '13' ) ) );
		$second                          = $this->service()->listReleases();
		self::assertTrue( $second['rate_limit']['limited'] );
		self::assertSame( 13, $second['rate_limit']['retry_after'] );
		self::assertCount( 2, $GLOBALS['ran_github_requests'] );
		self::assertStringContainsString( 'page=2', $GLOBALS['ran_github_requests'][1][0] );
		self::assertSame( array(), $GLOBALS['ran_github_responses'] );
	}
	#[\PHPUnit\Framework\Attributes\DataProvider( 'inspectionRateProvider' )]
	public function testInstalledInspectionRateFailuresStopAtTheirEndpoint( string $endpoint, int $requests ): void {
		$GLOBALS['ran_github_responses'] = $this->inspectionRateResponses( $endpoint );
		$this->assertFailure( fn() => $this->service()->inspectInstalled( 'repository/repository.php', '7', 'v1.2.3' ), 'rate_limited', $requests, 60 );
	}
	/** @return array<string,array{string,int}> */
	public static function inspectionRateProvider(): array {
		return array(
			'repository' => array( 'repository', 1 ),
			'release'    => array( 'release', 2 ),
			'commit'     => array( 'commit', 3 ),
		);
	}
	#[\PHPUnit\Framework\Attributes\DataProvider( 'acquisitionRateProvider' )]
	public function testInstalledAcquisitionRateFailuresCleanUpAndStop( string $endpoint, int $requests ): void {
		$descriptor                      = $this->descriptor();
		$GLOBALS['ran_github_requests']  = array();
		$GLOBALS['ran_github_responses'] = $this->acquisitionRateResponses( $endpoint );
		$this->assertFailure( fn() => $this->service()->acquireInstalled( $descriptor ), 'rate_limited', $requests, 60, 'complete' );
		$this->assertTemporaryPathsAbsent();
	}
	/** @return array<string,array{string,int}> */
	public static function acquisitionRateProvider(): array {
		return array(
			'asset API'             => array( 'asset', 2 ),
			'final repository read' => array( 'final_repository', 3 ),
			'CDN redirect'          => array( 'cdn', 3 ),
		);
	}
	public function testInstalledResponseAcceptanceAndPartialAssetCleanup(): void {
		$GLOBALS['ran_github_responses'] = array( $this->response( 201, array( 'id' => 99 ) ), $this->response( 201, $this->release( 7, 'v1.2.3' ) ), $this->response( 201, array( 'sha' => str_repeat( 'a', 40 ) ) ) );
		self::assertInstanceOf( IdentityDescriptor::class, $this->service()->inspectInstalled( 'repository/repository.php', '7', 'v1.2.3' ) );
		self::assertCount( 3, $GLOBALS['ran_github_requests'] );

		$GLOBALS['ran_github_requests']  = array();
		$GLOBALS['ran_github_responses'] = array( $this->response( 204, null ) );
		$this->assertFailure( fn() => $this->service()->inspectInstalled( 'repository/repository.php', '7', 'v1.2.3' ), 'operation_failed', 1 );

		$descriptor                      = $this->descriptor();
		$GLOBALS['ran_github_requests']  = array();
		$GLOBALS['ran_github_responses'] = array( $this->response( 200, array( 'id' => 99 ) ), $this->response( 206, null, array(), 'zip' ) );
		$this->assertFailure( fn() => $this->service()->acquireInstalled( $descriptor ), 'package_incompatible', 2, null, 'complete' );
		$this->assertTemporaryPathsAbsent();
	}
	#[\PHPUnit\Framework\Attributes\DataProvider( 'endpointFailureProvider' )]
	public function testEndpointFailuresUseNeutralCodesAndMakeNoLaterRequest( string $operation, string $scenario, string $code, int $requests ): void {
		$GLOBALS['ran_github_responses'] = $this->endpointResponses( $scenario );
		$call                            = 'list' === $operation
			? fn() => $this->service()->listReleases()
			: fn() => $this->service()->inspectInstalled( 'repository/repository.php', '7', 'v1.2.3' );
		$this->assertFailure( $call, $code, $requests );
	}
	/** @return array<string,array{string,string,string,int}> */
	public static function endpointFailureProvider(): array {
		return array(
			'list 401'             => array( 'list', 'list401', 'repository_access_unavailable', 1 ),
			'list 404'             => array( 'list', 'list404', 'repository_access_unavailable', 1 ),
			'repository 403'       => array( 'inspect', 'repository403', 'repository_access_unavailable', 1 ),
			'repository 404'       => array( 'inspect', 'repository404', 'repository_access_unavailable', 1 ),
			'concrete release 404' => array( 'inspect', 'release404', 'release_unavailable', 2 ),
			'server error'         => array( 'inspect', 'server', 'operation_failed', 1 ),
			'network error'        => array( 'inspect', 'network', 'operation_failed', 1 ),
			'malformed status'     => array( 'inspect', 'malformed', 'operation_failed', 1 ),
			'unsafe redirect'      => array( 'inspect', 'unsafe_redirect', 'operation_failed', 1 ),
			'second redirect'      => array( 'inspect', 'second_redirect', 'operation_failed', 2 ),
		);
	}
	/** @return list<array<string,mixed>> */
	private function inspectionRateResponses( string $endpoint ): array {
		$limited = $this->response( 429, null, array( 'retry-after' => '60' ) );
		return match ( $endpoint ) {
			'repository' => array( $limited ),
			'release' => array( $this->response( 200, array( 'id' => 99 ) ), $limited ),
			'commit' => array( $this->response( 200, array( 'id' => 99 ) ), $this->response( 200, $this->release( 7, 'v1.2.3' ) ), $limited ),
		};
	}
	/** @return list<array<string,mixed>> */
	private function acquisitionRateResponses( string $endpoint ): array {
		$limited = $this->response( 429, null, array( 'retry-after' => '60' ) );
		return match ( $endpoint ) {
			'asset' => array( $this->response( 200, array( 'id' => 99 ) ), $limited ),
			'final_repository' => array( $this->response( 200, array( 'id' => 99 ) ), $this->response( 200, null, array(), 'zip-data' ), $limited ),
			'cdn' => array( $this->response( 200, array( 'id' => 99 ) ), $this->response( 302, null, array( 'location' => 'https://objects.githubusercontent.com/repository.zip' ) ), $limited ),
		};
	}
	/** @return list<mixed> */
	private function endpointResponses( string $scenario ): array {
		return match ( $scenario ) {
			'list401' => array( $this->response( 401, null ) ),
			'list404' => array( $this->response( 404, null ) ),
			'repository403' => array( $this->response( 403, null ) ),
			'repository404' => array( $this->response( 404, null ) ),
			'release404' => array( $this->response( 200, array( 'id' => 99 ) ), $this->response( 404, null ) ),
			'server' => array( $this->response( 500, null ) ),
			'network' => array( new \WP_Error( 'transport', 'offline' ) ),
			'malformed' => array( $this->response( 99, null ) ),
			'unsafe_redirect' => array( $this->response( 302, null, array( 'location' => 'https://example.test/repository.zip' ) ) ),
			'second_redirect' => array( $this->response( 302, null, array( 'location' => 'https://api.github.com/repos/owner/repository' ) ), $this->response( 302, null, array( 'location' => 'https://api.github.com/repos/owner/repository' ) ) ),
		};
	}
	private function descriptor(): IdentityDescriptor {
		$GLOBALS['ran_github_responses'] = array( $this->response( 200, array( 'id' => 99 ) ), $this->response( 200, $this->release( 7, 'v1.2.3' ) ), $this->response( 200, array( 'sha' => str_repeat( 'a', 40 ) ) ) );
		return $this->service()->inspectInstalled( 'repository/repository.php', '7', 'v1.2.3' );
	}
	private function service(): GitHubReleaseService {
		return new GitHubReleaseService(
			array(
				'canonical_repository_locator' => 'owner/repository',
				'canonical_update_uri'         => 'https://github.com/owner/repository',
				'maximum_artifact_bytes'       => 52428800,
				'php_runtime_version'          => '8.2',
				'release_channel'              => 'stable',
				'stable_repository_identity'   => '99',
				'target_type'                  => 'plugin',
				'wordpress_runtime_version'    => '6.8',
			)
		);
	}
	/** @return array<string,mixed> */
	private function release( int $id, string $tag ): array {
		return array(
			'assets'       => array(
				array(
					'digest' => 'sha256:' . hash( 'sha256', 'zip-data' ),
					'id'     => 8,
					'name'   => 'repository.zip',
					'size'   => 8,
					'state'  => 'uploaded',
				),
			),
			'draft'        => false,
			'html_url'     => 'https://github.com/owner/repository/releases/tag/' . $tag,
			'id'           => $id,
			'immutable'    => true,
			'prerelease'   => false,
			'published_at' => '2026-08-22T10:00:00Z',
			'tag_name'     => $tag,
		);
	}
	/** @return array<string,mixed> */
	private function response( int $code, mixed $json, array $headers = array(), ?string $file = null ): array {
		$response = array(
			'body'     => null === $json ? '' : json_encode( $json, JSON_THROW_ON_ERROR ),
			'headers'  => $headers,
			'response' => array( 'code' => $code ),
		);
		if ( null !== $file ) {
			$response['file'] = $file;
		}
		return $response;
	}
	private function assertFailure( callable $call, string $code, int $requests, ?int $retry = null, string $cleanup = 'not_applicable' ): void {
		try {
			$call();
			self::fail( 'Expected a typed provider failure.' ); } catch ( ReleaseFailure $failure ) {
			self::assertSame( $code, $failure->releaseCode );
			self::assertSame( $retry, $failure->retryAfter );
			self::assertSame( $cleanup, $failure->cleanupStatus ); }
			self::assertCount( $requests, $GLOBALS['ran_github_requests'] );
			self::assertSame( array(), $GLOBALS['ran_github_responses'] );
	}
	private function assertTemporaryPathsAbsent(): void {
		self::assertNotEmpty( $GLOBALS['ran_github_temp_paths'] );
		foreach ( $GLOBALS['ran_github_temp_paths'] as $path ) {
			self::assertFileDoesNotExist( $path );
		}
	}
}
