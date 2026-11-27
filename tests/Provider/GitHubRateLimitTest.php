<?php

declare(strict_types=1);

// phpcs:ignore Universal.Namespaces.DisallowCurlyBraceSyntax.Forbidden, Universal.Namespaces.DisallowDeclarationWithoutName.Forbidden -- Keep global WordPress stubs and namespaced test code in the same isolated fixture. WordPress stubs must be declared in the global namespace used by production calls.
namespace {
	if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
		/**
		 * @param array<string,mixed> $response
		 */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress calls this global stub by its exact foreign function name.
		function wp_remote_retrieve_response_code( array $response ): int|string {
			return $response['response']['code'];
		}
	}
	if ( ! function_exists( 'wp_remote_retrieve_header' ) ) {
		/**
		 * @param array<string,mixed> $response
		 */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress calls this global stub by its exact foreign function name.
		function wp_remote_retrieve_header( array $response, string $name ): mixed {
			return $response['headers'][ strtolower( $name ) ] ?? null;
		}
	}
}

// phpcs:ignore Universal.Namespaces.OneDeclarationPerFile.MultipleFound, Universal.Namespaces.DisallowCurlyBraceSyntax.Forbidden -- This fixture colocates foreign global stubs and namespaced test or injected provider seams. Keep global WordPress stubs and namespaced test code in the same isolated fixture.
namespace RAN\WPReleaseUpdater\V1\Tests\Provider {
	use PHPUnit\Framework\TestCase;
	use RAN\WPReleaseUpdater\V1\Provider\GitHub\GitHubReleaseService;
	use RAN\WPReleaseUpdater\V1\Runtime\ReleaseFailure;

	// phpcs:ignore Universal.Files.SeparateFunctionsFromOO.Mixed -- The self-contained fixture combines foreign functions/classes with the test harness that exercises them.
	final class GitHubRateLimitTest extends TestCase {
		/**
		 * @param array<string,string> $headers
		 * @param array{limited:bool,remaining:?int,reset_at:?int,retry_after:int} $expected
		 */
		#[\PHPUnit\Framework\Attributes\DataProvider( 'classification_provider' )]
		public function test_rate_limit_classification_uses_only_git_hub_signals( int $status, array $headers, int $now, array $expected ): void {
			self::assertSame( $expected, $this->rate_limit( $status, $headers, $now ) );
		}
		/**
		 * @return array<string,array{int,array<string,string>,int,array{limited:bool,remaining:?int,reset_at:?int,retry_after:int}}>
		 */
		public static function classification_provider(): array {
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
				'HTTP date Retry-After is bounded for 429' => array(
					429,
					array( 'retry-after' => 'Thu, 01 Jan 1970 00:18:20 GMT' ),
					1000,
					array(
						'limited'     => true,
						'remaining'   => null,
						'reset_at'    => null,
						'retry_after' => 100,
					),
				),
				'future HTTP date on 403 uses remaining time' => array(
					403,
					array( 'retry-after' => 'Thu, 01 Jan 1970 00:18:20 GMT' ),
					1000,
					array(
						'limited'     => true,
						'remaining'   => null,
						'reset_at'    => null,
						'retry_after' => 100,
					),
				),
				'far future HTTP date is bounded'          => array(
					403,
					array( 'retry-after' => 'Sat, 03 Jan 1970 00:00:00 GMT' ),
					1000,
					array(
						'limited'     => true,
						'remaining'   => null,
						'reset_at'    => null,
						'retry_after' => 86400,
					),
				),
				'elapsed HTTP date uses fallback'          => array(
					403,
					array( 'retry-after' => 'Thu, 01 Jan 1970 00:10:00 GMT' ),
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
		/** @param array<string,string> $headers */
		#[\PHPUnit\Framework\Attributes\DataProvider( 'out_of_range_timing_provider' )]
		public function test_out_of_range_timing_maps_to_operation_failed( array $headers ): void {
			try {
				$this->rate_limit( 429, $headers, 1000 );
				self::fail( 'Expected bounded rate-limit timing to be rejected.' );
			} catch ( \RuntimeException $exception ) {
				$method  = new \ReflectionMethod( GitHubReleaseService::class, 'operation_failure' );
				$failure = $method->invoke( $this->service(), $exception );
				self::assertInstanceOf( ReleaseFailure::class, $failure );
				self::assertSame( 'operation_failed', $failure->release_code );
				self::assertNull( $failure->retry_after );
			}
		}
		/** @return array<string,array{array<string,string>}> */
		public static function out_of_range_timing_provider(): array {
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
		/**
		 * @param array<string,string> $headers
		 * @return array{limited:bool,remaining:?int,reset_at:?int,retry_after:int}
		 */
		private function rate_limit( int $status, array $headers, int $now ): array {
			$script     = tempnam( sys_get_temp_dir(), 'ran-rate-limit-probe-' );
			$facts_file = tempnam( sys_get_temp_dir(), 'ran-rate-limit-facts-' );
			self::assertIsString( $script );
			self::assertIsString( $facts_file );
			try {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Encode exact fixture facts for an isolated process; WordPress JSON filters must not alter fixture bytes.
				$encoded = json_encode(
					array(
						'bootstrap' => dirname( __DIR__ ) . '/bootstrap.php',
						'headers'   => $headers,
						'now'       => $now,
						'status'    => $status,
					),
					JSON_THROW_ON_ERROR
				);
				$fixture = <<<'PHP'
<?php
namespace RAN\WPReleaseUpdater\V1\Provider\GitHub {
	function time(): int {
		return $GLOBALS['ran_rate_limit_now'];
	}
}
namespace {
	function wp_remote_retrieve_response_code( array $response ): int|string {
		return $response['response']['code'];
	}
	function wp_remote_retrieve_header( array $response, string $name ): mixed {
		return $response['headers'][ strtolower( $name ) ] ?? null;
	}
	$ran_facts                     = json_decode( (string) file_get_contents( $argv[1] ), true, 64, JSON_THROW_ON_ERROR );
	$GLOBALS['ran_rate_limit_now'] = $ran_facts['now'];
	require $ran_facts['bootstrap'];
	$ran_method = new \ReflectionMethod( \RAN\WPReleaseUpdater\V1\Provider\GitHub\GitHubReleaseService::class, 'rate_limit' );
	try {
		$ran_result = $ran_method->invoke(
			null,
			array(
				'response' => array( 'code' => $ran_facts['status'] ),
				'headers'  => $ran_facts['headers'],
				'body'     => '',
			)
		);
		echo json_encode( array( 'ok' => true, 'result' => $ran_result ), JSON_THROW_ON_ERROR );
	} catch ( \RuntimeException $ran_exception ) {
		echo json_encode( array( 'ok' => false, 'message' => $ran_exception->getMessage() ), JSON_THROW_ON_ERROR );
	}
}
PHP;
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write isolated subprocess script for deterministic rate-limit time verification.
				file_put_contents( $script, $fixture );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write exact fixture input bytes for the native subprocess.
				file_put_contents( $facts_file, $encoded );
				$output = array();
				$code   = 0;
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- Run the fixture in a separate PHP process with escaped arguments; assertions inspect its exit status and output.
				exec( escapeshellarg( PHP_BINARY ) . ' -n ' . escapeshellarg( $script ) . ' ' . escapeshellarg( $facts_file ), $output, $code );
				self::assertSame( 0, $code, implode( "\n", $output ) );
				$decoded = json_decode( implode( "\n", $output ), true, 64, JSON_THROW_ON_ERROR );
				self::assertIsArray( $decoded );
				if ( true !== ( $decoded['ok'] ?? false ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Re-throw closed internal fixture error text from the isolated rate-limit probe.
					throw new \RuntimeException( is_string( $decoded['message'] ?? null ) ? $decoded['message'] : 'Rate-limit timing invalid.' );
				}
				return $decoded['result'];
			} finally {
				foreach ( array( $script, $facts_file ) as $cleanup_path ) {
					// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- Best-effort fixture teardown tolerates paths already removed by the scenario.
					@unlink( $cleanup_path );
				}
			}
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
