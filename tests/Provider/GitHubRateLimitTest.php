<?php

declare(strict_types=1);

namespace {
	if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
		function wp_remote_retrieve_response_code( array $response ): int|string {
			return $response['response']['code'];
		}
	}
	if ( ! function_exists( 'wp_remote_retrieve_header' ) ) {
		function wp_remote_retrieve_header( array $response, string $name ): mixed {
			return $response['headers'][ strtolower( $name ) ] ?? null;
		}
	}
}

namespace Tests\Provider {
	use PHPUnit\Framework\TestCase;
	use RAN\WPReleaseUpdater\V1\Provider\GitHub\GitHubReleaseService;
	use RAN\WPReleaseUpdater\V1\Runtime\ReleaseFailure;

	final class GitHubRateLimitTest extends TestCase {
		#[\PHPUnit\Framework\Attributes\DataProvider( 'classificationProvider' )]
		public function testRateLimitClassificationUsesOnlyGitHubSignals( int $status, array $headers, int $now, array $expected ): void {
			self::assertSame( $expected, $this->rateLimit( $status, $headers, $now ) );
		}
		/** @return array<string,array{int,array<string,string>,int,array{limited:bool,remaining:?int,reset_at:?int,retry_after:int}}> */
		public static function classificationProvider(): array {
			return array(
				'429 without headers uses the bounded fallback' => array(
					429,
					array(),
					1000,
					array(
						'limited'     => true,
						'remaining'   => null,
						'reset_at'    => null,
						'retry_after' => 900,
					),
				),
				'403 Retry-After is limited'               => array(
					403,
					array( 'retry-after' => '60' ),
					1000,
					array(
						'limited'     => true,
						'remaining'   => null,
						'reset_at'    => null,
						'retry_after' => 60,
					),
				),
				'403 zero remaining uses reset'            => array(
					403,
					array(
						'x-ratelimit-remaining' => '0',
						'x-ratelimit-reset'     => '1050',
					),
					1000,
					array(
						'limited'     => true,
						'remaining'   => 0,
						'reset_at'    => 1050,
						'retry_after' => 50,
					),
				),
				'ordinary 401 is not limited'              => array(
					401,
					array(
						'retry-after'           => '60',
						'x-ratelimit-remaining' => '0',
						'x-ratelimit-reset'     => '1050',
					),
					1000,
					array(
						'limited'     => false,
						'remaining'   => 0,
						'reset_at'    => 1050,
						'retry_after' => 0,
					),
				),
				'503 with Retry-After is not limited'      => array(
					503,
					array( 'retry-after' => '60' ),
					1000,
					array(
						'limited'     => false,
						'remaining'   => null,
						'reset_at'    => null,
						'retry_after' => 0,
					),
				),
				'non-rate long Retry-After is ignored'     => array(
					503,
					array( 'retry-after' => '86401' ),
					1000,
					array(
						'limited'     => false,
						'remaining'   => null,
						'reset_at'    => null,
						'retry_after' => 0,
					),
				),
				'zero Retry-After falls back for 429'      => array(
					429,
					array( 'retry-after' => '0' ),
					1000,
					array(
						'limited'     => true,
						'remaining'   => null,
						'reset_at'    => null,
						'retry_after' => 900,
					),
				),
				'malformed Retry-After falls back for 429' => array(
					429,
					array( 'retry-after' => 'later' ),
					1000,
					array(
						'limited'     => true,
						'remaining'   => null,
						'reset_at'    => null,
						'retry_after' => 900,
					),
				),
				'HTTP date Retry-After falls back for 429' => array(
					429,
					array( 'retry-after' => 'Sun, 06 Sep 2026 12:00:00 GMT' ),
					1000,
					array(
						'limited'     => true,
						'remaining'   => null,
						'reset_at'    => null,
						'retry_after' => 900,
					),
				),
				'fractional Retry-After falls back for 429' => array(
					429,
					array( 'retry-after' => '86400.0' ),
					1000,
					array(
						'limited'     => true,
						'remaining'   => null,
						'reset_at'    => null,
						'retry_after' => 900,
					),
				),
				'conflicting usable timing chooses the later delay' => array(
					403,
					array(
						'retry-after'           => '30',
						'x-ratelimit-remaining' => '0',
						'x-ratelimit-reset'     => '1100',
					),
					1000,
					array(
						'limited'     => true,
						'remaining'   => 0,
						'reset_at'    => 1100,
						'retry_after' => 100,
					),
				),
				'nonzero remaining ignores reset'          => array(
					403,
					array(
						'x-ratelimit-remaining' => '1',
						'x-ratelimit-reset'     => '999999',
					),
					1000,
					array(
						'limited'     => false,
						'remaining'   => 1,
						'reset_at'    => 999999,
						'retry_after' => 0,
					),
				),
				'past reset uses fallback'                 => array(
					403,
					array(
						'x-ratelimit-remaining' => '0',
						'x-ratelimit-reset'     => '999',
					),
					1000,
					array(
						'limited'     => true,
						'remaining'   => 0,
						'reset_at'    => 999,
						'retry_after' => 900,
					),
				),
				'equal reset uses fallback'                => array(
					403,
					array(
						'x-ratelimit-remaining' => '0',
						'x-ratelimit-reset'     => '1000',
					),
					1000,
					array(
						'limited'     => true,
						'remaining'   => 0,
						'reset_at'    => 1000,
						'retry_after' => 900,
					),
				),
				'exact reset boundary is retained'         => array(
					403,
					array(
						'x-ratelimit-remaining' => '0',
						'x-ratelimit-reset'     => '87400',
					),
					1000,
					array(
						'limited'     => true,
						'remaining'   => 0,
						'reset_at'    => 87400,
						'retry_after' => 86400,
					),
				),
			);
		}
		#[\PHPUnit\Framework\Attributes\DataProvider( 'outOfRangeTimingProvider' )]
		public function testOutOfRangeTimingMapsToOperationFailed( array $headers ): void {
			try {
				$this->rateLimit( 429, $headers, 1000 );
				self::fail( 'Expected bounded rate-limit timing to be rejected.' );
			} catch ( \RuntimeException $exception ) {
				$method  = new \ReflectionMethod( GitHubReleaseService::class, 'operationFailure' );
				$failure = $method->invoke( $this->service(), $exception );
				self::assertInstanceOf( ReleaseFailure::class, $failure );
				self::assertSame( 'operation_failed', $failure->releaseCode );
				self::assertNull( $failure->retryAfter );
			}
		}
		/** @return array<string,array{array<string,string>}> */
		public static function outOfRangeTimingProvider(): array {
			return array(
				'integer above the maximum' => array( array( 'retry-after' => '86401' ) ),
				'overflowing decimal'       => array( array( 'retry-after' => '999999999999999999999999999999' ) ),
				'reset above the maximum'   => array(
					array(
						'x-ratelimit-remaining' => '0',
						'x-ratelimit-reset'     => '87401',
					),
				),
			);
		}
		/** @param array<string,string> $headers @return array{limited:bool,remaining:?int,reset_at:?int,retry_after:int} */
		private function rateLimit( int $status, array $headers, int $now ): array {
			$method = new \ReflectionMethod( GitHubReleaseService::class, 'rateLimit' );
			return $method->invoke(
				null,
				array(
					'response' => array( 'code' => $status ),
					'headers'  => $headers,
					'body'     => '',
				),
				$now
			);
		}
		private function service(): GitHubReleaseService {
			return new GitHubReleaseService(
				array(
					'canonical_repository_locator' => 'owner/package',
					'canonical_update_uri'         => 'https://github.com/owner/package',
					'maximum_artifact_bytes'       => 52428800,
					'php_runtime_version'          => '8.2',
					'release_channel'              => 'stable',
					'stable_repository_identity'   => '1',
					'target_type'                  => 'plugin',
					'wordpress_runtime_version'    => '6.8',
				)
			);
		}
	}
}
