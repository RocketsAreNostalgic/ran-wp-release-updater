<?php

declare(strict_types=1);

namespace {
	if ( ! class_exists( 'WP_Error' ) ) {
		final class WP_Error {

			public function __construct( public string $code, public string $message ) {
			}
		}
	}

	if ( ! function_exists( 'is_wp_error' ) ) {
		function is_wp_error( mixed $value ): bool {
			return $value instanceof WP_Error;
		}
	}

	if ( ! function_exists( 'wp_safe_remote_get' ) ) {
		function wp_safe_remote_get( string $url, array $args ): array|WP_Error {
			$GLOBALS['ran_github_requests'][] = array( $url, $args );
			$callback                         = $GLOBALS['ran_github_request_callback'] ?? null;
			if ( is_callable( $callback ) ) {
				$callback( $url, $args );
			}
			$response = array_shift( $GLOBALS['ran_github_responses'] );
			if ( $response instanceof WP_Error ) {
				return $response;
			}
			if ( ! is_array( $response ) ) {
				throw new RuntimeException( 'Unexpected GitHub test request.' );
			}
			if ( isset( $args['filename'], $response['file'] ) ) {
				file_put_contents( $args['filename'], $response['file'] );
				chmod( $args['filename'], 0600 );
			}
			if ( isset( $args['filename'], $response['file_size'] ) ) {
				$handle = fopen( $args['filename'], 'c+b' );
				if ( false === $handle || ! ftruncate( $handle, $response['file_size'] ) ) {
					throw new RuntimeException( 'Could not create the sparse GitHub test response.' );
				}
				fclose( $handle );
				chmod( $args['filename'], 0600 );
			}
			return $response;
		}
	}

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

	if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
		function wp_remote_retrieve_body( array $response ): string {
			return $response['body'] ?? '';
		}
	}

	if ( ! function_exists( 'wp_http_validate_url' ) ) {
		function wp_http_validate_url( string $url ): string|false {
			return false === ( $GLOBALS['ran_github_validate_urls'] ?? true ) ? false : $url;
		}
	}

	if ( ! function_exists( 'wp_tempnam' ) ) {
		function wp_tempnam( string $filename ): string|false {
			unset( $filename );
			$path = tempnam( sys_get_temp_dir(), 'ran-github-test-' );
			if ( is_string( $path ) ) {
				chmod( $path, 0600 );
				$GLOBALS['ran_github_temp_paths'][] = $path;
			}
			return $path;
		}
	}

	if ( ! defined( 'FS_METHOD' ) ) {
		define( 'FS_METHOD', 'direct' ); }

	if ( ! function_exists( 'add_filter' ) ) {
		function add_filter( string $hook, mixed $callback, int $priority, int $arguments ): void {
			$GLOBALS['ran_wp_release_updater_test_hooks'][] = array( 'filter', $hook, $callback, $priority, $arguments );
		}
	}

	if ( ! function_exists( 'add_action' ) ) {
		function add_action( string $hook, mixed $callback, int $priority, int $arguments ): void {
			$GLOBALS['ran_wp_release_updater_test_hooks'][] = array( 'action', $hook, $callback, $priority, $arguments );
		}
	}
}

namespace RAN\WPReleaseUpdater\V1\Provider\GitHub {
	function chmod( string $path, int $permissions ): bool {
		if ( ( $GLOBALS['ran_github_chmod_failures'] ?? 0 ) > 0 ) {
			--$GLOBALS['ran_github_chmod_failures'];
			return false;
		}
		return \chmod( $path, $permissions );
	}

	function lstat( string $path ): array|false {
		if ( ( $GLOBALS['ran_github_lstat_failure_at'] ?? 0 ) > 0 ) {
			--$GLOBALS['ran_github_lstat_failure_at'];
			if ( 0 === $GLOBALS['ran_github_lstat_failure_at'] ) {
				return false;
			}
		}
		return \lstat( $path );
	}

	/** Scoped test seam for the service's synchronous two-attempt cleanup. */
	function unlink( string $path ): bool {
		if ( ( $GLOBALS['ran_github_unlink_failures'] ?? 0 ) > 0 ) {
			--$GLOBALS['ran_github_unlink_failures'];
			return false;
		}
		return \unlink( $path );
	}
}

namespace Tests\Provider {

	use PHPUnit\Framework\TestCase;
	use RAN\WPReleaseUpdater\V1\Archive\TemporaryArtifact;
	use RAN\WPReleaseUpdater\V1\Contract\BindingRecord;
	use RAN\WPReleaseUpdater\V1\Contract\IdentityDescriptor;
	use RAN\WPReleaseUpdater\V1\Contract\ReleaseAdapter;
	use RAN\WPReleaseUpdater\V1\Provider\GitHub\GitHubCredentialResolver;
	use RAN\WPReleaseUpdater\V1\Provider\GitHub\GitHubReleaseAdapter;
	use RAN\WPReleaseUpdater\V1\Provider\GitHub\GitHubReleaseReadUnavailable;
	use RAN\WPReleaseUpdater\V1\Provider\GitHub\GitHubReleaseService;
	use RAN\WPReleaseUpdater\V1\Provider\GitHub\ProspectiveReleaseArtifact;
	use RAN\WPReleaseUpdater\V1\Provider\GitHub\ProspectiveReleaseInspection;
	use RAN\WPReleaseUpdater\V1\Runtime\ReleaseFailure;
	use RuntimeException;

	final class GitHubReleaseAdapterTest extends TestCase {

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
			parent::tearDown();
		}

		public function testDormantLoadAndConstructionPerformNoWork(): void {
			$calls    = 0;
			$resolver = new GitHubCredentialResolver(
				static function () use ( &$calls ): string {
					++$calls;
					return 'private-token';
				}
			);

			$adapter = new GitHubReleaseAdapter( $this->binding(), $resolver );

			self::assertInstanceOf( GitHubReleaseAdapter::class, $adapter );
			self::assertInstanceOf( ReleaseAdapter::class, $adapter );
			self::assertSame( 0, $calls );
			self::assertSame( array(), $GLOBALS['ran_github_requests'] );
			self::assertSame( array(), $GLOBALS['ran_github_temp_paths'] );
		}

		public function testProductionCompositionActivatesSelectedRootAndRemainsDormantUntilOffer(): void {
			$root = dirname( __DIR__, 2 );
			$GLOBALS['ran_wp_release_updater_test_hooks'] = array();
			require $root . '/bootstrap.php';
			$broker = $GLOBALS['ran_wp_release_updater_v1_broker'];
			self::assertSame(
				array(
					'loaded'      => true,
					'state'       => 'active',
					'code'        => 'runtime_active',
					'diagnostics' => array(),
				),
				$broker->activate(
					array(
						'php_version'       => '8.2.0',
						'runtime_protocol'  => 4,
						'wordpress_version' => '6.8.0',
					)
				)
			);

			$calls         = 0;
			$binding       = $this->binding();
			$configuration = array(
				'headers'                    => array(
					'Author'      => 'Test',
					'Description' => 'Test',
					'Name'        => 'Repository',
					'PluginURI'   => 'https://github.com/owner/repository',
					'RequiresPHP' => '8.2',
					'RequiresWP'  => '6.8',
					'UpdateURI'   => 'https://github.com/owner/repository',
					'Version'     => '1.0.0',
				),
				'installed_package_identity' => 'repository/repository.php',
				'policy'                     => 'automatic',
				'target_type'                => 'plugin',
				'update_uri'                 => 'https://github.com/owner/repository',
			);
			$updater       = GitHubReleaseAdapter::registerFromConfiguration(
				$configuration,
				$binding,
				new GitHubCredentialResolver(
					static function () use ( &$calls ): string {
						++$calls;
						return 'private-token'; }
				),
				new class() {},
				array(
					'archive_root'   => 'repository',
					'theme_template' => '',
				)
			);

			self::assertNotNull( $updater );
			foreach ( array( BindingRecord::class, GitHubCredentialResolver::class, GitHubReleaseAdapter::class, '\\RAN\\WPReleaseUpdater\\V1\\WordPress\\NativePackageUpdater' ) as $class ) {
				self::assertSame( $root, dirname( ( new \ReflectionClass( $class ) )->getFileName(), str_contains( $class, 'Provider\\GitHub' ) ? 4 : 3 ), $class );
			}
			self::assertSame( 0, $calls );
			self::assertSame( array(), $GLOBALS['ran_github_requests'] );
			self::assertCount( 10, $GLOBALS['ran_wp_release_updater_test_hooks'] );
			$updater->register();
			self::assertCount( 10, $GLOBALS['ran_wp_release_updater_test_hooks'] );

			$invalid           = $configuration;
			$invalid['policy'] = 'unsupported';
			self::assertNull( GitHubReleaseAdapter::registerFromConfiguration( $invalid, $binding, null, new class() {}, array() ) );
			$facts = $binding->toArray();
			unset( $facts['binding_hash'] );
			$facts['provider_code'] = 'gitlab';
			self::assertNull( GitHubReleaseAdapter::registerFromConfiguration( $configuration, BindingRecord::create( $facts ), null, new class() {}, array() ) );
			self::assertSame( 0, $calls );
			self::assertSame( array(), $GLOBALS['ran_github_requests'] );
			self::assertCount( 10, $GLOBALS['ran_wp_release_updater_test_hooks'] );
		}

		#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
		#[\PHPUnit\Framework\Attributes\PreserveGlobalState( false )]
		public function testPublicReleaseSourceListsEmptyAndMapsCredentialFailureWithoutHttp(): void {
			$GLOBALS['wp_version'] = '6.8.0';
			$registrar             = require dirname( __DIR__, 2 ) . '/bootstrap.php';
			$broker                = $GLOBALS['ran_wp_release_updater_v1_broker'] ?? null;
			self::assertIsObject( $broker );
			self::assertSame(
				'runtime_active',
				$broker->activate(
					array(
						'php_version'       => PHP_VERSION,
						'runtime_protocol'  => 4,
						'wordpress_version' => '6.8.0',
					)
				)['code']
			);
			$source             = $registrar->releases( 'github', 'plugin', 'owner/repository', '99' );
			$invalidConditional = $source->list( array( 'etag' => 'invalid' ) );
			self::assertSame( 'invalid_configuration', $invalidConditional['code'] );
			self::assertSame( array(), $GLOBALS['ran_github_requests'] );
			$GLOBALS['ran_github_responses'] = array( $this->response( 200, array() ) );
			$result                          = $source->list();
			self::assertSame(
				array(
					'ok'             => true,
					'code'           => 'releases_listed',
					'value'          => $result['value'],
					'retry_after'    => null,
					'cleanup_status' => 'not_applicable',
				),
				$result
			);
			self::assertSame( array(), $result['value']['candidates'] );
			$GLOBALS['ran_github_responses'] = array( $this->response( 304, null, array( 'etag' => '"same"' ) ) );
			$notModified                     = $source->list( array( 'etag' => '"old"' ) );
			self::assertSame( 'releases_not_modified', $notModified['code'] );
			self::assertTrue( $notModified['value']['not_modified'] );
			$GLOBALS['ran_github_responses'] = array( $this->response( 429, null, array( 'retry-after' => '12' ) ) );
			$limited                         = $source->list();
			self::assertSame(
				array(
					'ok'             => false,
					'code'           => 'rate_limited',
					'value'          => null,
					'retry_after'    => 12,
					'cleanup_status' => 'not_applicable',
				),
				$limited
			);
			$GLOBALS['ran_github_responses'] = array( $this->response( 403, null, array( 'retry-after' => '8' ) ) );
			self::assertSame( 8, $source->list()['retry_after'] );
			$GLOBALS['ran_github_responses'] = array( $this->response( 404, null ) );
			self::assertSame( 'repository_access_unavailable', $source->list()['code'] );
			$GLOBALS['ran_github_responses'] = array(
				$this->response( 200, array( 'id' => 99 ) ),
				$this->response( 404, null ),
			);
			self::assertSame( 'release_unavailable', $source->inspect( '7', 'v1.2.3' )['code'] );
			$GLOBALS['ran_github_responses'] = array(
				$this->response( 200, array( 'id' => 99 ) ),
				$this->response( 200, $this->release( 7, 'v1.2.3' ) ),
				$this->response( 404, null ),
			);
			self::assertSame( 'release_unavailable', $source->inspect( '7', 'v1.2.3' )['code'] );
			$calls   = 0;
			$private = $registrar->releases(
				'github',
				'plugin',
				'owner/repository',
				'99',
				'stable',
				static function () use ( &$calls ): string {
					++$calls;
					throw new RuntimeException( 'resolver' );
				}
			);
			self::assertSame( 'invalid_release', $private->inspect( "bad\nrelease", 'v1.2.3' )['code'] );
			self::assertSame( 'invalid_release', $private->acquire( '7', 'v1.2.3', 'v1:' . str_repeat( '0', 64 ) )['code'] );
			self::assertSame( 0, $calls );
			$failure = $private->list();
			self::assertSame( 'credential_unavailable', $failure['code'] );
			self::assertSame( 1, $calls );
			self::assertCount( 10, $GLOBALS['ran_github_requests'] );
		}

		#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
		#[\PHPUnit\Framework\Attributes\PreserveGlobalState( false )]
		public function testPublicReleaseSourceMapsAssetAndProspectiveArchiveFailures(): void {
			$GLOBALS['wp_version'] = '6.8.0';
			$registrar             = require dirname( __DIR__, 2 ) . '/bootstrap.php';
			$broker                = $GLOBALS['ran_wp_release_updater_v1_broker'] ?? null;
			self::assertIsObject( $broker );
			self::assertSame(
				'runtime_active',
				$broker->activate(
					array(
						'php_version'       => PHP_VERSION,
						'runtime_protocol'  => 4,
						'wordpress_version' => '6.8.0',
					)
				)['code']
			);
			$header                          = "<?php\n/*\nPlugin Name: Repository\nVersion: 1.2.3\nUpdate URI: https://github.com/owner/repository\nRequires PHP: 8.2\nRequires at least: 6.8\n*/";
			$archive                         = $this->prospectiveArchive( $header );
			$source                          = $registrar->releases( 'github', 'plugin', 'owner/repository', '99' );
			$GLOBALS['ran_github_responses'] = $this->prospectiveInspectionResponses( 7, 'v1.2.3', $archive );
			$inspection                      = $source->inspect( '7', 'v1.2.3' );
			self::assertSame( 'release_inspected', $inspection['code'] );
			$GLOBALS['ran_github_responses'] = array(
				$this->response( 200, array( 'id' => 99 ) ),
				$this->response( 200, $this->release( 7, 'v1.2.3' ) ),
				$this->response( 200, array( 'sha' => str_repeat( 'a', 40 ) ) ),
				$this->response( 200, array( 'id' => 99 ) ),
				$this->response( 404, null ),
			);
			self::assertSame( 'release_unavailable', $source->acquire( '7', 'v1.2.3', $inspection['value']['fingerprint'] )['code'] );
			$GLOBALS['ran_github_responses'] = $this->prospectiveInspectionResponses( 7, 'v1.2.3', $archive );
			$inspection                      = $source->inspect( '7', 'v1.2.3' );
			$GLOBALS['ran_github_responses'] = $this->prospectiveInspectionResponses( 7, 'v1.2.3', $archive );
			$changed                         = $source->acquire( '7', 'v1.2.3', 'v2:' . str_repeat( '0', 64 ) );
			self::assertSame( 'release_changed', $changed['code'] );
			self::assertSame( 'complete', $changed['cleanup_status'] );
			self::assertAllTemporaryPathsAbsent();
			$theme                           = $registrar->releases( 'github', 'theme', 'owner/repository', '99' );
			$invalid                         = 'not-a-zip';
			$release                         = $this->release( 7, 'v1.2.3' );
			$release['assets'][0]['digest']  = 'sha256:' . hash( 'sha256', $invalid );
			$release['assets'][0]['size']    = strlen( $invalid );
			$GLOBALS['ran_github_responses'] = array(
				$this->response( 200, array( 'id' => 99 ) ),
				$this->response( 200, $release ),
				$this->response( 200, array( 'sha' => str_repeat( 'a', 40 ) ) ),
				$this->response( 200, array( 'id' => 99 ) ),
				$this->response( 200, null, array(), $invalid ),
				$this->response( 200, array( 'id' => 99 ) ),
			);
			$broken                          = $theme->inspect( '7', 'v1.2.3' );
			self::assertSame( 'package_incompatible', $broken['code'] );
			self::assertSame( 'complete', $broken['cleanup_status'] );
			self::assertAllTemporaryPathsAbsent();
		}

		public function testPublicServiceKeepsRepositoryAndExactReleaseFailuresDistinct(): void {
			$service                         = $this->publicService();
			$GLOBALS['ran_github_responses'] = array( $this->response( 404, null ) );
			try {
				$service->inspect( '7', 'v1.2.3' );
				self::fail( 'The repository access failure must be structured.' );
			} catch ( ReleaseFailure $failure ) {
				self::assertSame( 'repository_access_unavailable', $failure->releaseCode );
				self::assertSame( 'not_applicable', $failure->cleanupStatus );
			}

			$GLOBALS['ran_github_responses'] = array(
				$this->response( 200, array( 'id' => 99 ) ),
				$this->response( 404, null ),
			);
			try {
				$service->inspect( '7', 'v1.2.3' );
				self::fail( 'The exact release failure must be structured.' );
			} catch ( ReleaseFailure $failure ) {
				self::assertSame( 'release_unavailable', $failure->releaseCode );
				self::assertSame( 'not_applicable', $failure->cleanupStatus );
			}
		}

		public function testPublicServiceRejectsMalformedConditionalBeforeHttp(): void {
			try {
				$this->publicService()->list( array( 'etag' => 'invalid' ) );
				self::fail( 'Malformed conditional state must fail before provider work.' );
			} catch ( ReleaseFailure $failure ) {
				self::assertSame( 'invalid_configuration', $failure->releaseCode );
			}
			self::assertSame( array(), $GLOBALS['ran_github_requests'] );
		}

		public function testPublicServiceCleansRateLimitedAndInvalidStreamFailures(): void {
			$archive                         = $this->prospectiveArchive( "<?php\n/*\nPlugin Name: Repository\nVersion: 1.2.3\nUpdate URI: https://github.com/owner/repository\nRequires PHP: 8.2\nRequires at least: 6.8\n*/" );
			$service                         = $this->publicService();
			$GLOBALS['ran_github_responses'] = $this->prospectiveInspectionResponses( 7, 'v1.2.3', $archive );
			$inspection                      = $service->inspect( '7', 'v1.2.3' );

			$GLOBALS['ran_github_responses'] = array(
				$this->response( 200, array( 'id' => 99 ) ),
				$this->response( 200, $this->release( 7, 'v1.2.3' ) ),
				$this->response( 200, array( 'sha' => str_repeat( 'a', 40 ) ) ),
				$this->response( 200, array( 'id' => 99 ) ),
				$this->response( 429, null, array( 'retry-after' => '30' ) ),
			);
			try {
				$service->acquire( '7', 'v1.2.3', $inspection['fingerprint'] );
				self::fail( 'A streamed rate limit must be structured.' );
			} catch ( ReleaseFailure $failure ) {
				self::assertSame( 'rate_limited', $failure->releaseCode );
				self::assertSame( 30, $failure->retryAfter );
				self::assertSame( 'complete', $failure->cleanupStatus );
			}
			$this->assertAllTemporaryPathsAbsent();

			$GLOBALS['ran_github_responses'] = array(
				$this->response( 200, array( 'id' => 99 ) ),
				$this->response( 200, $this->release( 7, 'v1.2.3' ) ),
				$this->response( 200, array( 'sha' => str_repeat( 'a', 40 ) ) ),
				$this->response( 200, array( 'id' => 99 ) ),
				$this->response( 200, null, array(), 'invalid bytes' ),
			);
			try {
				$service->acquire( '7', 'v1.2.3', $inspection['fingerprint'] );
				self::fail( 'Invalid streamed bytes must be structured.' );
			} catch ( ReleaseFailure $failure ) {
				self::assertSame( 'package_incompatible', $failure->releaseCode );
				self::assertSame( 'complete', $failure->cleanupStatus );
			}
			$this->assertAllTemporaryPathsAbsent();
		}

		public function testPublicServiceStopsAfterLivenessChangesDuringCredentialResolution(): void {
			$calls   = 0;
			$service = new GitHubReleaseService(
				$this->serviceConfiguration( $this->binding() ),
				new GitHubCredentialResolver(
					static function (): string {
						return 'private-token'; }
				),
				static function () use ( &$calls ): ?string {
					return ++$calls >= 3 ? 'runtime_unavailable' : null; }
			);
			try {
				$service->list();
				self::fail( 'A revoked runtime must stop before HTTP.' );
			} catch ( ReleaseFailure $failure ) {
				self::assertSame( 'runtime_unavailable', $failure->releaseCode );
			}
			self::assertSame( array(), $GLOBALS['ran_github_requests'] );
		}

		public function testPublicServiceStopsAfterLivenessChangesDuringHttpCallback(): void {
			$revoked                                = false;
			$service                                = new GitHubReleaseService(
				$this->serviceConfiguration( $this->binding() ),
				null,
				static function () use ( &$revoked ): ?string {
					return $revoked ? 'runtime_unavailable' : null; }
			);
			$GLOBALS['ran_github_request_callback'] = static function () use ( &$revoked ): void {
				$revoked = true;
			};
			$GLOBALS['ran_github_responses']        = array( $this->response( 200, array() ) );
			try {
				$service->list();
				self::fail( 'A revoked runtime must stop after the current HTTP return.' );
			} catch ( ReleaseFailure $failure ) {
				self::assertSame( 'runtime_unavailable', $failure->releaseCode );
			}
			self::assertCount( 1, $GLOBALS['ran_github_requests'] );
		}

		public function testPublicServiceRetriesCleanupOnceAndReportsBothOutcomes(): void {
			$archive                         = $this->prospectiveArchive( "<?php\n/*\nPlugin Name: Repository\nVersion: 1.2.3\nUpdate URI: https://github.com/owner/repository\nRequires PHP: 8.2\nRequires at least: 6.8\n*/" );
			$service                         = $this->publicService();
			$GLOBALS['ran_github_responses'] = $this->prospectiveInspectionResponses( 7, 'v1.2.3', $archive );
			$inspection                      = $service->inspect( '7', 'v1.2.3' );

			$GLOBALS['ran_github_unlink_failures'] = 1;
			$GLOBALS['ran_github_responses']       = $this->invalidStreamResponses();
			try {
				$service->acquire( '7', 'v1.2.3', $inspection['fingerprint'] );
				self::fail( 'Invalid bytes must fail after cleanup retry.' );
			} catch ( ReleaseFailure $failure ) {
				self::assertSame( 'package_incompatible', $failure->releaseCode );
				self::assertSame( 'complete', $failure->cleanupStatus );
			}
			self::assertSame( 0, $GLOBALS['ran_github_unlink_failures'] );
			$this->assertAllTemporaryPathsAbsent();

			$GLOBALS['ran_github_unlink_failures'] = 2;
			$GLOBALS['ran_github_responses']       = $this->invalidStreamResponses();
			try {
				$service->acquire( '7', 'v1.2.3', $inspection['fingerprint'] );
				self::fail( 'Invalid bytes must expose an unreleased owned file.' );
			} catch ( ReleaseFailure $failure ) {
				self::assertSame( 'package_incompatible', $failure->releaseCode );
				self::assertSame( 'failed', $failure->cleanupStatus );
			}
			self::assertSame( 0, $GLOBALS['ran_github_unlink_failures'] );
			self::assertFileExists( $GLOBALS['ran_github_temp_paths'][ count( $GLOBALS['ran_github_temp_paths'] ) - 1 ] );
		}

		public function testPublicServiceReportsTemporaryAllocationCleanupForInspectAndAcquire(): void {
			$archive = $this->prospectiveArchive( "<?php\n/*\nPlugin Name: Repository\nVersion: 1.2.3\nUpdate URI: https://github.com/owner/repository\nRequires PHP: 8.2\nRequires at least: 6.8\n*/" );

			$service                              = $this->publicService();
			$GLOBALS['ran_github_chmod_failures'] = 1;
			$GLOBALS['ran_github_responses']      = $this->prospectiveInspectionResponses( 7, 'v1.2.3', $archive );
			try {
				$service->inspect( '7', 'v1.2.3' );
				self::fail( 'A chmod allocation failure must be structured.' );
			} catch ( ReleaseFailure $failure ) {
				self::assertSame( 'operation_failed', $failure->releaseCode );
				self::assertSame( 'complete', $failure->cleanupStatus );
			}
			$this->assertAllTemporaryPathsAbsent();

			$service                               = $this->publicService();
			$GLOBALS['ran_github_chmod_failures']  = 1;
			$GLOBALS['ran_github_unlink_failures'] = 2;
			$GLOBALS['ran_github_responses']       = $this->prospectiveInspectionResponses( 7, 'v1.2.3', $archive );
			try {
				$service->inspect( '7', 'v1.2.3' );
				self::fail( 'An unreleased chmod allocation failure must be structured.' );
			} catch ( ReleaseFailure $failure ) {
				self::assertSame( 'operation_failed', $failure->releaseCode );
				self::assertSame( 'failed', $failure->cleanupStatus );
			}
			$unreleasedPath = $GLOBALS['ran_github_temp_paths'][ count( $GLOBALS['ran_github_temp_paths'] ) - 1 ];
			self::assertFileExists( $unreleasedPath );
			self::assertTrue( \unlink( $unreleasedPath ) );

			$service                                = $this->publicService();
			$GLOBALS['ran_github_unlink_failures']  = 0;
			$GLOBALS['ran_github_responses']        = $this->prospectiveInspectionResponses( 7, 'v1.2.3', $archive );
			$inspection                             = $service->inspect( '7', 'v1.2.3' );
			$GLOBALS['ran_github_lstat_failure_at'] = 2;
			$GLOBALS['ran_github_responses']        = $this->prospectiveInspectionResponses( 7, 'v1.2.3', $archive );
			try {
				$service->acquire( '7', 'v1.2.3', $inspection['fingerprint'] );
				self::fail( 'An identity allocation failure must be structured.' );
			} catch ( ReleaseFailure $failure ) {
				self::assertSame( 'operation_failed', $failure->releaseCode );
				self::assertSame( 'complete', $failure->cleanupStatus );
			}
			$this->assertAllTemporaryPathsAbsent();

			$service                                = $this->publicService();
			$GLOBALS['ran_github_lstat_failure_at'] = 1;
			$GLOBALS['ran_github_responses']        = $this->prospectiveInspectionResponses( 7, 'v1.2.3', $archive );
			try {
				$service->inspect( '7', 'v1.2.3' );
				self::fail( 'An unproven temporary file must be structured.' );
			} catch ( ReleaseFailure $failure ) {
				self::assertSame( 'operation_failed', $failure->releaseCode );
				self::assertSame( 'failed', $failure->cleanupStatus );
			}
			self::assertFileExists( $GLOBALS['ran_github_temp_paths'][ count( $GLOBALS['ran_github_temp_paths'] ) - 1 ] );
		}

		public function testPublicServicesResolveIndependentCredentialsPerOperation(): void {
			$firstCalls                      = 0;
			$secondCalls                     = 0;
			$first                           = new GitHubReleaseService(
				$this->serviceConfiguration( $this->binding() ),
				new GitHubCredentialResolver(
					static function () use ( &$firstCalls ): string {
						++$firstCalls;
						return 'first-token'; }
				),
				static fn (): ?string => null
			);
			$second                          = new GitHubReleaseService(
				$this->serviceConfiguration( $this->binding() ),
				new GitHubCredentialResolver(
					static function () use ( &$secondCalls ): string {
						++$secondCalls;
						return 'second-token'; }
				),
				static fn (): ?string => null
			);
			$GLOBALS['ran_github_responses'] = array( $this->response( 200, array() ), $this->response( 200, array() ) );
			$first->list();
			$second->list();
			self::assertSame( 1, $firstCalls );
			self::assertSame( 1, $secondCalls );
			self::assertSame( 'Bearer first-token', $GLOBALS['ran_github_requests'][0][1]['headers']['Authorization'] );
			self::assertSame( 'Bearer second-token', $GLOBALS['ran_github_requests'][1][1]['headers']['Authorization'] );
		}

		public function testPublicServiceMapsRuntimeLossDuringPackageInspection(): void {
			$checks                          = 0;
			$service                         = new GitHubReleaseService(
				$this->serviceConfiguration( $this->binding() ),
				null,
				static function () use ( &$checks ): ?string {
					return ++$checks >= 17 ? 'runtime_unavailable' : null; }
			);
			$archive                         = $this->prospectiveArchive( "<?php\n/*\nPlugin Name: Repository\nVersion: 1.2.3\nUpdate URI: https://github.com/owner/repository\nRequires PHP: 8.2\nRequires at least: 6.8\n*/" );
			$GLOBALS['ran_github_responses'] = $this->prospectiveInspectionResponses( 7, 'v1.2.3', $archive );
			try {
				$service->inspect( '7', 'v1.2.3' );
				self::fail( 'Runtime loss after package inspection must suppress the inspection.' );
			} catch ( ReleaseFailure $failure ) {
				self::assertSame( 'runtime_unavailable', $failure->releaseCode );
				self::assertSame( 'complete', $failure->cleanupStatus );
			}
			$this->assertAllTemporaryPathsAbsent();
		}

		public function testCredentialsResolveOncePerTopLevelChainAndFailBeforeHttp(): void {
			$calls                           = 0;
			$resolver                        = new GitHubCredentialResolver(
				static function () use ( &$calls ): string {
					++$calls;
					return 'private-token';
				}
			);
			$adapter                         = new GitHubReleaseAdapter( $this->binding(), $resolver );
			$GLOBALS['ran_github_responses'] = array(
				$this->response( 200, array( $this->release( 1, 'v1.0.0' ) ) ),
			);

			$adapter->listReleases();

			self::assertSame( 1, $calls );
			self::assertSame(
				'Bearer private-token',
				$GLOBALS['ran_github_requests'][0][1]['headers']['Authorization']
			);

			$invalid = new GitHubReleaseAdapter(
				$this->binding(),
				new GitHubCredentialResolver( static fn (): string => 'bad token' )
			);
			$this->expectException( ReleaseFailure::class );
			try {
				$invalid->listReleases();
			} finally {
				self::assertCount( 1, $GLOBALS['ran_github_requests'] );
			}
		}

		public function testNullCredentialResultUsesAnonymousRequest(): void {
			$calls                           = 0;
			$adapter                         = new GitHubReleaseAdapter(
				$this->binding(),
				new GitHubCredentialResolver(
					static function () use ( &$calls ): ?string {
						++$calls;
						return null;
					}
				)
			);
			$GLOBALS['ran_github_responses'] = array(
				$this->response( 200, array( $this->release( 1, 'v1.0.0' ) ) ),
			);

			$adapter->listReleases();

			self::assertSame( 1, $calls );
			self::assertArrayNotHasKey( 'Authorization', $GLOBALS['ran_github_requests'][0][1]['headers'] );
		}

		public function testInvalidCallerInputDoesNotResolveCredentialsOrCallGitHub(): void {
			$valid                           = new GitHubReleaseAdapter( $this->binding() );
			$GLOBALS['ran_github_responses'] = $this->inspectionResponses( 7, 'v1.2.3' );
			$descriptor                      = $valid->inspect( '7' );
			$facts                           = $descriptor->toArray();
			unset( $facts['fingerprint'] );
			$facts['artifact_identity'] = 'invalid-asset';
			$invalidDescriptor          = IdentityDescriptor::create( $facts );

			$calls                          = 0;
			$service                        = $this->service(
				$this->binding(),
				new GitHubCredentialResolver(
					static function () use ( &$calls ): string {
						++$calls;
						return 'private-token';
					}
				)
			);
			$adapter                        = new GitHubReleaseAdapter(
				$this->binding(),
				new GitHubCredentialResolver(
					static function () use ( &$calls ): string {
						++$calls;
						return 'private-token';
					}
				)
			);
			$GLOBALS['ran_github_requests'] = array();

			foreach (
			array(
				static fn (): IdentityDescriptor => $adapter->inspect( 'not-a-release' ),
				static fn (): TemporaryArtifact => $adapter->acquire( $invalidDescriptor ),
				static fn (): ProspectiveReleaseInspection => $service->inspectProspective( 'not-a-release' ),
			) as $operation
			) {
				try {
					$operation();
					self::fail( 'Invalid caller input unexpectedly reached the request chain.' );
				} catch ( \InvalidArgumentException ) {
					self::addToAssertionCount( 1 );
				}
			}

			self::assertSame( 0, $calls );
			self::assertSame( array(), $GLOBALS['ran_github_requests'] );
		}

		public function testProspectiveConfigurationAndZeroIdentityFailBeforeCredentialsOrHttp(): void {
			$calls         = 0;
			$resolver      = new GitHubCredentialResolver(
				static function () use ( &$calls ): string {
					++$calls;
					return 'private-token';
				}
			);
			$configuration = $this->serviceConfiguration( $this->binding() );
			$invalid       = array(
				array_replace( $configuration, array( 'stable_repository_identity' => '0' ) ),
				array_replace( $configuration, array( 'canonical_repository_locator' => 'owner' ) ),
				array_replace( $configuration, array( 'canonical_update_uri' => 'https://github.com/other/repository' ) ),
				array_replace( $configuration, array( 'target_type' => 'package' ) ),
				array_replace( $configuration, array( 'release_channel' => 'nightly' ) ),
				array_replace( $configuration, array( 'php_runtime_version' => 'eight.two' ) ),
				array_merge( $configuration, array( 'unexpected' => true ) ),
			);
			foreach ( $invalid as $facts ) {
				try {
					new GitHubReleaseService( $facts, $resolver );
					self::fail( 'Invalid prospective configuration unexpectedly constructed.' );
				} catch ( \InvalidArgumentException ) {
					self::addToAssertionCount( 1 );
				}
			}

			$service = new GitHubReleaseService( $configuration, $resolver );
			try {
				$service->inspectProspective( '0' );
				self::fail( 'GitHub release zero unexpectedly reached provider work.' );
			} catch ( \InvalidArgumentException ) {
				self::addToAssertionCount( 1 );
			}
			self::assertSame( 0, $calls );
			self::assertSame( array(), $GLOBALS['ran_github_requests'] );
			self::assertSame( array(), $GLOBALS['ran_github_temp_paths'] );
		}

		public function testListingSortsStableNumericIdentitiesAndReturnsReleaseDetails(): void {
			$GLOBALS['ran_github_responses'] = array(
				$this->response(
					200,
					array(
						$this->release( 2, 'v1.1.0' ),
						$this->release( 3, 'v2.0.0-beta.1', true ),
						$this->release( 1, 'v1.0.0' ),
					),
					array(
						'etag'          => '"release-list"',
						'last-modified' => 'Fri, 22 Aug 2026 10:00:00 GMT',
					)
				),
			);

			$result = ( new GitHubReleaseAdapter( $this->binding() ) )->listReleases();

			self::assertSame(
				array( '1.1.0', '1.0.0' ),
				array_column( $result['candidates'], 'version' )
			);
			self::assertSame(
				array( '2', '1' ),
				array_column( $result['candidates'], 'release_identity' )
			);
			self::assertSame(
				array( 'repository.zip' ),
				$result['candidates'][0]['expected_asset_names']
			);
			self::assertSame(
				'https://github.com/owner/repository/releases/tag/v1.1.0',
				$result['candidates'][0]['details_url']
			);
			self::assertSame( '"release-list"', $result['conditional']['etag'] );
			self::assertFalse( $result['not_modified'] );
			self::assertFalse( $result['search_exhausted'] );
		}

		public function testPrereleaseListingKeepsSemverOrderingWithinItsChannel(): void {
			$GLOBALS['ran_github_responses'] = array(
				$this->response(
					200,
					array(
						$this->release( 1, 'v2.0.0-beta.1', true ),
						$this->release( 3, 'v2.0.0', false ),
						$this->release( 2, 'v2.0.0-beta.2', true ),
					)
				),
			);

			$result = ( new GitHubReleaseAdapter( $this->binding( 'prerelease' ) ) )->listReleases();

			self::assertSame(
				array( '2.0.0', '2.0.0-beta.2', '2.0.0-beta.1' ),
				array_column( $result['candidates'], 'version' )
			);
			self::assertSame( array( false, true, true ), array_column( $result['candidates'], 'prerelease' ) );
		}

		public function testListingRejectsMalformedMembersAndOversizedBodies(): void {
			$GLOBALS['ran_github_responses'] = array(
				$this->response( 200, array( $this->release( 1, 'v1.0.0' ), 'invalid-member' ) ),
			);
			try {
				( new GitHubReleaseAdapter( $this->binding() ) )->listReleases();
				self::fail( 'A malformed release-list member must reject the complete response.' );
			} catch ( ReleaseFailure $exception ) {
				self::assertSame( 'operation_failed', $exception->releaseCode );
				self::assertCount( 1, $GLOBALS['ran_github_requests'] );
			}

			$oversized                       = $this->response( 200, null );
			$oversized['body']               = str_repeat( 'x', 262145 );
			$GLOBALS['ran_github_responses'] = array( $oversized );
			try {
				( new GitHubReleaseAdapter( $this->binding() ) )->listReleases();
				self::fail( 'An oversized response must stop the operation.' );
			} catch ( ReleaseFailure $exception ) {
				self::assertSame( 'operation_failed', $exception->releaseCode );
			}
		}

		public function testResponseContainersDistinguishOperationAndCandidateFailures(): void {
			$GLOBALS['ran_github_responses'] = array( $this->response( 200, (object) array() ) );
			try {
				( new GitHubReleaseAdapter( $this->binding() ) )->listReleases();
				self::fail( 'A JSON object is not a release listing.' );
			} catch ( ReleaseFailure $failure ) {
				self::assertSame( 'operation_failed', $failure->releaseCode );
			}

			$GLOBALS['ran_github_responses'] = array(
				$this->response( 200, array( 'id' => 99 ) ),
				$this->response( 200, (object) array() ),
			);
			try {
				( new GitHubReleaseAdapter( $this->binding() ) )->inspect( '7', 'v1.2.3' );
				self::fail( 'An empty release object is not a usable candidate.' );
			} catch ( ReleaseFailure $failure ) {
				self::assertSame( 'package_incompatible', $failure->releaseCode );
			}
		}

		public function testListingUsesTwoBoundedPagesAndReturnsAtMostEightCandidates(): void {
			$credentialCalls = 0;
			$service         = $this->service(
				$this->binding(),
				new GitHubCredentialResolver(
					static function () use ( &$credentialCalls ): string {
						++$credentialCalls;
						return 'private-token';
					}
				)
			);
			$drafts          = array();
			for ( $index = 1; $index <= 20; ++$index ) {
				$release          = $this->release( $index, 'v1.0.' . $index );
				$release['draft'] = true;
				$drafts[]         = $release;
			}
			$pageTwo = array();
			for ( $index = 21; $index <= 30; ++$index ) {
				$pageTwo[] = $this->release( $index, 'v2.0.' . ( $index - 21 ) );
			}
			$GLOBALS['ran_github_responses'] = array(
				$this->response( 200, $drafts ),
				$this->response( 200, $pageTwo ),
			);

			$result = $service->listReleases(
				array( 'etag' => '"prior"' )
			);

			self::assertSame( 1, $credentialCalls );
			self::assertCount( 2, $GLOBALS['ran_github_requests'] );
			self::assertStringContainsString( 'page=2', $GLOBALS['ran_github_requests'][1][0] );
			self::assertArrayNotHasKey(
				'If-None-Match',
				$GLOBALS['ran_github_requests'][1][1]['headers']
			);
			self::assertCount( 8, $result['candidates'] );
			self::assertTrue( $result['search_exhausted'] );
		}

		public function testConditionalNotModifiedAndRateLimitRemainClosedProviderState(): void {
			$adapter                         = new GitHubReleaseAdapter( $this->binding() );
			$GLOBALS['ran_github_responses'] = array(
				$this->response(
					304,
					null,
					array(
						'etag'          => '"fresh"',
						'last-modified' => 'Fri, 22 Aug 2026 10:00:00 GMT',
					)
				),
			);

			$result = $adapter->listReleases(
				array(
					'etag'          => '"prior"',
					'last_modified' => 'Thu, 21 Aug 2026 10:00:00 GMT',
				)
			);

			self::assertTrue( $result['not_modified'] );
			self::assertSame( '"fresh"', $result['conditional']['etag'] );
			self::assertSame(
				'"prior"',
				$GLOBALS['ran_github_requests'][0][1]['headers']['If-None-Match']
			);

			$GLOBALS['ran_github_responses'] = array(
				$this->response(
					429,
					null,
					array( 'retry-after' => '999999' )
				),
			);
			try {
				$adapter->listReleases();
				self::fail( 'An over-bound rate-limit delay must stop the operation.' );
			} catch ( ReleaseFailure $failure ) {
				self::assertSame( 'operation_failed', $failure->releaseCode );
			}
		}

		#[\PHPUnit\Framework\Attributes\DataProvider( 'readUnavailableStatusProvider' )]
		public function testAuthenticatedAuthorizationFailuresAreNotRateLimits( int $status ): void {
			$GLOBALS['ran_github_responses'] = array( $this->response( $status, null ) );
			$this->expectException( ReleaseFailure::class );
			( new GitHubReleaseAdapter( $this->binding() ) )->listReleases();
		}

		/** @return iterable<string,array{0:int}> */
		public static function readUnavailableStatusProvider(): iterable {
			yield 'unauthorized' => array( 401 );
			yield 'forbidden' => array( 403 );
			yield 'not found' => array( 404 );
		}

		public function testTransportAndCredentialFailuresSignalUnavailableRead(): void {
			$GLOBALS['ran_github_responses'] = array( new \WP_Error( 'transport', 'not connected' ) );
			$this->expectException( ReleaseFailure::class );
			try {
				( new GitHubReleaseAdapter( $this->binding() ) )->listReleases();
			} catch ( ReleaseFailure $exception ) {
				self::assertSame( 'operation_failed', $exception->releaseCode );
				throw $exception;
			}
		}

		public function testInvalidCredentialSignalsUnavailableReadBeforeHttp(): void {
			$adapter = new GitHubReleaseAdapter(
				$this->binding(),
				new GitHubCredentialResolver( static fn (): string => 'bad token' )
			);

			$this->expectException( ReleaseFailure::class );
			try {
				$adapter->listReleases();
			} finally {
				self::assertSame( array(), $GLOBALS['ran_github_requests'] );
			}
		}

		public function testNonStringCredentialResultSignalsUnavailableReadBeforeHttp(): void {
			$adapter = new GitHubReleaseAdapter(
				$this->binding(),
				new GitHubCredentialResolver( static fn (): int => 42 )
			);

			$this->expectException( ReleaseFailure::class );
			try {
				$adapter->listReleases();
			} finally {
				self::assertSame( array(), $GLOBALS['ran_github_requests'] );
			}
		}

		public function testUnavailableCredentialSignalsUnavailableReadBeforeHttp(): void {
			$adapter = new GitHubReleaseAdapter(
				$this->binding(),
				new GitHubCredentialResolver(
					static function (): string {
						throw new RuntimeException( 'credential source failed' ); }
				)
			);

			$this->expectException( ReleaseFailure::class );
			try {
				$adapter->listReleases();
			} finally {
				self::assertSame( array(), $GLOBALS['ran_github_requests'] );
			}
		}

		public function testServerErrorsRemainGenericRuntimeFailures(): void {
			$GLOBALS['ran_github_responses'] = array( $this->response( 500, null ) );
			$this->expectException( ReleaseFailure::class );
			try {
				( new GitHubReleaseAdapter( $this->binding() ) )->listReleases();
			} catch ( ReleaseFailure $exception ) {
				self::assertSame( 'operation_failed', $exception->releaseCode );
				throw $exception; }
		}

		public function testInspectionBindsExactNumericRepositoryReleaseCommitAndAsset(): void {
			$adapter                         = new GitHubReleaseAdapter( $this->binding() );
			$GLOBALS['ran_github_responses'] = $this->inspectionResponses(
				7,
				'v1.2.3',
				false,
				true
			);

			$facts = $adapter->inspect( '7', 'v1.2.3' )->toArray();

			self::assertSame( '99', $facts['repository_identity'] );
			self::assertSame( '7', $facts['release_identity'] );
			self::assertSame( '8', $facts['artifact_identity'] );
			self::assertSame( str_repeat( 'a', 40 ), $facts['commit_identity'] );
			self::assertSame( hash( 'sha256', 'zip-data' ), $facts['artifact_sha256'] );
			self::assertTrue( $facts['assurance_facts']['publication_immutable'] );
			self::assertTrue( $facts['assurance_facts']['provenance_verified'] );
			self::assertCount( 3, $GLOBALS['ran_github_requests'] );
			self::assertSame(
				'https://api.github.com/repos/owner/repository',
				$GLOBALS['ran_github_requests'][0][0]
			);
		}

		#[\PHPUnit\Framework\Attributes\DataProvider( 'installedInspectionFailureProvider' )]
		public function testInstalledInspectionMapsEndpointFailuresToNeutralFailures(
			string $scenario,
			string $expectedCode,
			int $expectedRequests
		): void {
			$GLOBALS['ran_github_responses'] = match ( $scenario ) {
				'repository_access_denied' => array( $this->response( 401, null ) ),
				'concrete_release_unavailable' => array(
					$this->response( 200, array( 'id' => 99 ) ),
					$this->response( 404, null ),
				),
				'concrete_commit_server_failure' => array(
					$this->response( 200, array( 'id' => 99 ) ),
					$this->response( 200, $this->release( 7, 'v1.2.3' ) ),
					$this->response( 500, null ),
				),
			};

			try {
				( new GitHubReleaseAdapter( $this->binding() ) )->inspect( '7', 'v1.2.3' );
				self::fail( 'Installed inspection failures must be neutral typed failures.' );
			} catch ( ReleaseFailure $failure ) {
				self::assertSame( $expectedCode, $failure->releaseCode );
				self::assertSame( 'not_applicable', $failure->cleanupStatus );
			}

			self::assertCount( $expectedRequests, $GLOBALS['ran_github_requests'] );
		}

		/** @return iterable<string,array{0:string,1:string,2:int}> */
		public static function installedInspectionFailureProvider(): iterable {
			yield 'repository access denied' => array(
				'repository_access_denied',
				'repository_access_unavailable',
				1,
			);
			yield 'concrete release unavailable' => array(
				'concrete_release_unavailable',
				'release_unavailable',
				2,
			);
			yield 'concrete commit server failure' => array(
				'concrete_commit_server_failure',
				'operation_failed',
				3,
			);
		}

		#[\PHPUnit\Framework\Attributes\DataProvider( 'invalidZipMetadataProvider' )]
		public function testInstalledInspectionRejectsInvalidZipMetadataBeforeCommitLookup( string $scenario ): void {
			$release = $this->release( 7, 'v1.2.3' );
			switch ( $scenario ) {
				case 'missing_zip':
					$release['assets'] = array();
					break;
				case 'multiple_zip':
					$release['assets'][] = $release['assets'][0];
					break;
				case 'invalid_id':
					$release['assets'][0]['id'] = 0;
					break;
				case 'invalid_size':
					$release['assets'][0]['size'] = 0;
					break;
				case 'missing_digest':
					unset( $release['assets'][0]['digest'] );
					break;
				case 'over_limit':
					$release['assets'][0]['size'] = 52_428_801;
					break;
				case 'not_uploaded':
					$release['assets'][0]['state'] = 'new';
					break;
			}
			$GLOBALS['ran_github_responses'] = array(
				$this->response( 200, array( 'id' => 99 ) ),
				$this->response( 200, $release ),
			);

			try {
				( new GitHubReleaseAdapter( $this->binding() ) )->inspect( '7', 'v1.2.3' );
				self::fail( 'Invalid ZIP metadata must reject the candidate before commit lookup.' );
			} catch ( ReleaseFailure $failure ) {
				self::assertSame( 'package_incompatible', $failure->releaseCode );
				self::assertSame( 'not_applicable', $failure->cleanupStatus );
			}

			self::assertSame(
				array(
					'https://api.github.com/repos/owner/repository',
					'https://api.github.com/repos/owner/repository/releases/7',
				),
				array_column( $GLOBALS['ran_github_requests'], 0 )
			);
		}

		/** @return iterable<string,array{0:string}> */
		public static function invalidZipMetadataProvider(): iterable {
			foreach ( array( 'missing_zip', 'multiple_zip', 'invalid_id', 'invalid_size', 'missing_digest', 'over_limit', 'not_uploaded' ) as $scenario ) {
				yield $scenario => array( $scenario );
			}
		}

		public function testInspectionTreatsMissingConcreteReleaseAsGenericCandidateFailure(): void {
			$GLOBALS['ran_github_responses'] = array(
				$this->response( 200, array( 'id' => 99 ) ),
				$this->response( 404, null ),
			);

			try {
				( new GitHubReleaseAdapter( $this->binding() ) )->inspect( '7', 'v1.2.3' );
				self::fail( 'A missing concrete release must fail without credential fallback.' );
			} catch ( ReleaseFailure $exception ) {
				self::assertSame( 'release_unavailable', $exception->releaseCode );
			}

			self::assertSame(
				array(
					'https://api.github.com/repos/owner/repository',
					'https://api.github.com/repos/owner/repository/releases/7',
				),
				array_column( $GLOBALS['ran_github_requests'], 0 )
			);
		}

		public function testInspectionTreatsMissingConcreteCommitAsGenericCandidateFailure(): void {
			$GLOBALS['ran_github_responses'] = array(
				$this->response( 200, array( 'id' => 99 ) ),
				$this->response( 200, $this->release( 7, 'v1.2.3' ) ),
				$this->response( 404, null ),
			);

			try {
				( new GitHubReleaseAdapter( $this->binding() ) )->inspect( '7', 'v1.2.3' );
				self::fail( 'A missing concrete commit must fail without credential fallback.' );
			} catch ( ReleaseFailure $exception ) {
				self::assertSame( 'release_unavailable', $exception->releaseCode );
			}

			self::assertSame(
				array(
					'https://api.github.com/repos/owner/repository',
					'https://api.github.com/repos/owner/repository/releases/7',
					'https://api.github.com/repos/owner/repository/commits/v1.2.3',
				),
				array_column( $GLOBALS['ran_github_requests'], 0 )
			);
		}

		public function testInspectionRejectsLocatorThatDoesNotMatchStableRepositoryIdentity(): void {
			$GLOBALS['ran_github_responses'] = array(
				$this->response( 200, array( 'id' => 100 ) ),
			);

			$this->expectException( ReleaseFailure::class );
			try {
				( new GitHubReleaseAdapter( $this->binding() ) )->inspect( '7' );
			} finally {
				self::assertCount( 1, $GLOBALS['ran_github_requests'] );
				self::assertSame(
					'https://api.github.com/repos/owner/repository',
					$GLOBALS['ran_github_requests'][0][0]
				);
			}
		}

		public function testMutableReleaseRemainsManualOnlyAndPrereleaseThemeIsBound(): void {
			$adapter                         = new GitHubReleaseAdapter( $this->binding( 'prerelease', 'theme' ) );
			$GLOBALS['ran_github_responses'] = $this->inspectionResponses(
				7,
				'v2.0.0-beta.1',
				true,
				false
			);

			$facts = $adapter->inspect( '7' )->toArray();

			self::assertSame( 'theme', $facts['target_type'] );
			self::assertTrue( $facts['prerelease'] );
			self::assertFalse( $facts['assurance_facts']['publication_immutable'] );
			self::assertFalse( $facts['assurance_facts']['provenance_verified'] );
			self::assertTrue( $facts['assurance_facts']['trusted_digest_source'] );
		}

		public function testInspectionPreservesUppercaseZipSuffixIdentity(): void {
			$release                         = $this->release( 7, 'v1.2.3', false, true );
			$release['assets'][0]['name']    = 'Repository.ZIP';
			$GLOBALS['ran_github_responses'] = array(
				$this->response( 200, array( 'id' => 99 ) ),
				$this->response( 200, $release ),
				$this->response( 200, array( 'sha' => str_repeat( 'a', 40 ) ) ),
			);

			$facts = ( new GitHubReleaseAdapter( $this->binding() ) )->inspect( '7' )->toArray();

			self::assertSame( 'Repository.ZIP', $facts['artifact_filename'] );
		}

		public function testInspectionAcceptsNativeIntegerIdentityAndSizeBoundaries(): void {
			$identity                        = PHP_INT_MAX;
			$release                         = $this->release( $identity, 'v1.2.3', false, true );
			$release['assets'][0]['id']      = $identity;
			$release['assets'][0]['size']    = 52_428_800;
			$GLOBALS['ran_github_responses'] = array(
				$this->response( 200, array( 'id' => $identity ) ),
				$this->response( 200, $release ),
				$this->response( 200, array( 'sha' => str_repeat( 'a', 40 ) ) ),
			);

			$facts = ( new GitHubReleaseAdapter(
				$this->binding( 'stable', 'plugin', (string) $identity )
			) )->inspect( (string) $identity )->toArray();

			self::assertSame( (string) $identity, $facts['repository_identity'] );
			self::assertSame( (string) $identity, $facts['release_identity'] );
			self::assertSame( (string) $identity, $facts['artifact_identity'] );
			self::assertSame( 52_428_800, $facts['artifact_size'] );
		}

		public function testCustomBindingAcceptsItsExactLargerAssetMetadata(): void {
			$limit                           = 52_428_800 + 1;
			$release                         = $this->release( 7, 'v1.2.3' );
			$release['assets'][0]['size']    = $limit;
			$GLOBALS['ran_github_responses'] = array(
				$this->response( 200, array( 'id' => 99 ) ),
				$this->response( 200, $release ),
				$this->response( 200, array( 'sha' => str_repeat( 'a', 40 ) ) ),
			);
			self::assertSame( $limit, ( new GitHubReleaseAdapter( $this->binding( maximumArtifactBytes: $limit ) ) )->inspect( '7' )->toArray()['artifact_size'] );
		}

		#[\PHPUnit\Framework\Attributes\DataProvider( 'invalidInspectionProvider' )]
		public function testInspectionRejectsChangedOrAmbiguousIdentity(
			string $failure,
			callable $mutate
		): void {
			$release    = $this->release( 7, 'v1.2.3', false, true );
			$repository = array( 'id' => 99 );
			$commit     = array( 'sha' => str_repeat( 'a', 40 ) );
			$mutate( $repository, $release, $commit );
			$GLOBALS['ran_github_responses'] = array(
				$this->response( 200, $repository ),
				$this->response( 200, $release ),
				$this->response( 200, $commit ),
			);

			try {
				( new GitHubReleaseAdapter( $this->binding() ) )->inspect( '7', 'v1.2.3' );
				self::fail( 'Invalid provider metadata must not produce a usable descriptor.' );
			} catch ( ReleaseFailure $exception ) {
				self::assertSame(
					str_contains( $failure, 'repository identity' ) || str_contains( $failure, 'commit identity' ) ? 'operation_failed' : 'package_incompatible',
					$exception->releaseCode
				);
			}
		}

		/** @return iterable<string,array{0:string,1:callable}> */
		public static function invalidInspectionProvider(): iterable {
			yield 'repository transfer' => array(
				'repository identity changed',
				static function ( array &$repository ): void {
					$repository['id'] = 100;
				},
			);
			yield 'quoted repository identity' => array(
				'repository identity changed',
				static function ( array &$repository ): void {
					$repository['id'] = '99';
				},
			);
			yield 'release changed' => array(
				'release identity is invalid',
				static function ( array &$repository, array &$release ): void {
					unset( $repository );
					$release['id'] = 9;
				},
			);
			yield 'quoted release identity' => array(
				'release identity is invalid',
				static function ( array &$repository, array &$release ): void {
					unset( $repository );
					$release['id'] = '7';
				},
			);
			yield 'floating release identity' => array(
				'release identity is invalid',
				static function ( array &$repository, array &$release ): void {
					unset( $repository );
					$release['id'] = 7.0;
				},
			);
			yield 'zero release identity' => array(
				'release identity is invalid',
				static function ( array &$repository, array &$release ): void {
					unset( $repository );
					$release['id'] = 0;
				},
			);
			yield 'negative release identity' => array(
				'release identity is invalid',
				static function ( array &$repository, array &$release ): void {
					unset( $repository );
					$release['id'] = -7;
				},
			);
			yield 'wrong release page' => array(
				'release contract is invalid',
				static function ( array &$repository, array &$release ): void {
					unset( $repository );
					$release['html_url'] = 'https://github.com/other/repository/releases/tag/v1.2.3';
				},
			);
			yield 'changed tag' => array(
				'release contract is invalid',
				static function ( array &$repository, array &$release ): void {
					unset( $repository );
					$release['tag_name'] = 'v1.2.4';
				},
			);
			yield 'ambiguous zip' => array(
				'exactly one uploaded ZIP',
				static function ( array &$repository, array &$release ): void {
					unset( $repository );
					$release['assets'][] = $release['assets'][0];
				},
			);
			yield 'missing digest' => array(
				'ZIP artifact is invalid',
				static function ( array &$repository, array &$release ): void {
					unset( $repository );
					$release['assets'][0]['digest'] = null;
				},
			);
			yield 'quoted artifact identity' => array(
				'ZIP artifact is invalid',
				static function ( array &$repository, array &$release ): void {
					unset( $repository );
					$release['assets'][0]['id'] = '8';
				},
			);
			yield 'floating artifact identity' => array(
				'ZIP artifact is invalid',
				static function ( array &$repository, array &$release ): void {
					unset( $repository );
					$release['assets'][0]['id'] = 8.0;
				},
			);
			yield 'zero artifact identity' => array(
				'ZIP artifact is invalid',
				static function ( array &$repository, array &$release ): void {
					unset( $repository );
					$release['assets'][0]['id'] = 0;
				},
			);
			yield 'negative artifact identity' => array(
				'ZIP artifact is invalid',
				static function ( array &$repository, array &$release ): void {
					unset( $repository );
					$release['assets'][0]['id'] = -8;
				},
			);
			yield 'quoted artifact size' => array(
				'ZIP artifact is invalid',
				static function ( array &$repository, array &$release ): void {
					unset( $repository );
					$release['assets'][0]['size'] = '8';
				},
			);
			yield 'floating artifact size' => array(
				'ZIP artifact is invalid',
				static function ( array &$repository, array &$release ): void {
					unset( $repository );
					$release['assets'][0]['size'] = 8.0;
				},
			);
			yield 'zero artifact size' => array(
				'ZIP artifact is invalid',
				static function ( array &$repository, array &$release ): void {
					unset( $repository );
					$release['assets'][0]['size'] = 0;
				},
			);
			yield 'negative artifact size' => array(
				'ZIP artifact is invalid',
				static function ( array &$repository, array &$release ): void {
					unset( $repository );
					$release['assets'][0]['size'] = -8;
				},
			);
			yield 'oversized artifact' => array(
				'ZIP artifact is invalid',
				static function ( array &$repository, array &$release ): void {
					unset( $repository );
					$release['assets'][0]['size'] = 52_428_800 + 1;
				},
			);
			yield 'changed commit' => array(
				'commit identity is invalid',
				static function ( array &$repository, array &$release, array &$commit ): void {
					unset( $repository, $release );
					$commit['sha'] = 'short';
				},
			);
		}

		public function testPrivateCredentialIsStrippedOnAssetRedirectAndCustodyTransfersOnce(): void {
			$calls                           = 0;
			$adapter                         = new GitHubReleaseAdapter(
				$this->binding(),
				new GitHubCredentialResolver(
					static function () use ( &$calls ): string {
						++$calls;
						return 'private-token';
					}
				)
			);
			$GLOBALS['ran_github_responses'] = $this->inspectionResponses( 7, 'v1.2.3' );
			$descriptor                      = $adapter->inspect( '7' );
			self::assertSame( 1, $calls );
			self::assertCount( 3, $GLOBALS['ran_github_requests'] );
			$GLOBALS['ran_github_responses'] = array(
				$this->response( 200, array( 'id' => 99 ) ),
				$this->response(
					302,
					null,
					array( 'location' => 'https://release-assets.githubusercontent.com/file' )
				),
				$this->response( 200, null, array(), 'zip-data' ),
				$this->response( 200, array( 'id' => 99 ) ),
			);

			$artifact = $adapter->acquire( $descriptor );
			self::assertInstanceOf( TemporaryArtifact::class, $artifact );

			self::assertSame( 2, $calls );
			self::assertCount( 7, $GLOBALS['ran_github_requests'] );
			self::assertSame(
				'Bearer private-token',
				$GLOBALS['ran_github_requests'][4][1]['headers']['Authorization']
			);
			self::assertArrayNotHasKey(
				'Authorization',
				$GLOBALS['ran_github_requests'][5][1]['headers']
			);
			self::assertTrue( $GLOBALS['ran_github_requests'][5][1]['stream'] );
			self::assertSame(
				52_428_800 + 1,
				$GLOBALS['ran_github_requests'][5][1]['limit_response_size']
			);
			self::assertSame( 'zip-data', $artifact->inspect( 'file_get_contents' ) );
			$path = $artifact->inspect( static fn ( string $path ): string => $path );
			self::assertFileExists( $path );
			unset( $artifact );
			self::assertFileDoesNotExist( $path );
		}

		public function testProspectiveInspectionKeepsReleaseAndDescriptorFactsThenDiscards(): void {
			$calls                           = 0;
			$archive                         = $this->prospectiveArchive(
				"<?php\n/*\nPlugin Name: Repository\nVersion: 1.2.3\n"
				. "Update URI: https://github.com/owner/repository\n"
				. "Requires PHP: 8.2\nRequires at least: 6.8\n*/"
			);
			$service                         = $this->service(
				$this->binding(),
				new GitHubCredentialResolver(
					static function () use ( &$calls ): string {
						++$calls;
						return 'private-token';
					}
				)
			);
			$GLOBALS['ran_github_responses'] = $this->prospectiveInspectionResponses(
				7,
				'v1.2.3',
				$archive
			);

			$facts = $service->inspectProspective( '7', 'v1.2.3' )->toArray();

			self::assertSame( '7', $facts['release_identity'] );
			self::assertSame( 'v1.2.3', $facts['tag'] );
			self::assertSame( '1.2.3', $facts['version'] );
			self::assertSame( 'repository', $facts['package_root'] );
			self::assertSame( 'repository.php', $facts['main_file'] );
			self::assertArrayHasKey( 'fingerprint', $facts );
			self::assertSame( 1, $calls );
			self::assertCount( 6, $GLOBALS['ran_github_requests'] );
			$this->assertAllTemporaryPathsAbsent();
		}

		public function testPrivateThemeProspectiveFlowResolvesOncePerChainAndDiscards(): void {
			$calls                           = 0;
			$service                         = $this->service(
				$this->binding( 'stable', 'theme' ),
				new GitHubCredentialResolver(
					static function () use ( &$calls ): string {
						++$calls;
						return 'private-token';
					}
				)
			);
			$GLOBALS['ran_github_responses'] = array(
				$this->response( 200, array( $this->release( 7, 'v1.2.3' ) ) ),
			);
			$candidate                       = $service->listReleases()['candidates'][0];
			self::assertSame( 1, $calls );
			self::assertSame( '7', $candidate['release_identity'] );

			$archive                         = $this->prospectiveThemeArchive(
				"/*\nTheme Name: Repository\nVersion: 1.2.3\n"
				. "Update URI: https://github.com/owner/repository\n"
				. "Requires PHP: 8.2\nRequires at least: 6.8\n*/"
			);
			$GLOBALS['ran_github_responses'] = $this->prospectiveInspectionResponses(
				7,
				'v1.2.3',
				$archive
			);

			$facts = $service->inspectProspective(
				$candidate['release_identity'],
				$candidate['tag']
			)->toArray();

			self::assertSame( 2, $calls );
			self::assertSame( 'theme', $facts['target_type'] );
			self::assertSame( 'repository', $facts['package_root'] );
			self::assertSame( 'style.css', $facts['main_file'] );
			foreach ( $GLOBALS['ran_github_requests'] as $request ) {
				self::assertSame( 'Bearer private-token', $request[1]['headers']['Authorization'] );
			}
			$this->assertAllTemporaryPathsAbsent();
		}

		public function testPublicPluginProspectiveFlowSendsNoAuthorization(): void {
			$archive                         = $this->prospectiveArchive(
				"<?php\n/*\nPlugin Name: Repository\nVersion: 1.2.3\n"
				. "Update URI: https://github.com/owner/repository\n"
				. "Requires PHP: 8.2\nRequires at least: 6.8\n*/"
			);
			$GLOBALS['ran_github_responses'] = $this->prospectiveInspectionResponses( 7, 'v1.2.3', $archive );

			$inspection = $this->service( $this->binding() )->inspectProspective( '7', 'v1.2.3' );

			self::assertSame( 'plugin', $inspection->toArray()['target_type'] );
			foreach ( $GLOBALS['ran_github_requests'] as $request ) {
				self::assertArrayNotHasKey( 'Authorization', $request[1]['headers'] );
			}
			$this->assertAllTemporaryPathsAbsent();
		}

		public function testProspectiveInspectionRejectsArchiveAndStillDiscards(): void {
			$archive                         = $this->prospectiveArchive(
				"<?php\n/*\nPlugin Name: Repository\nVersion: 1.2.3\n"
				. "Update URI: https://github.com/owner/repository\n*/",
				array(
					'repository/other.php' => "<?php\n/*\nPlugin Name: Other\nVersion: 1.2.3\n"
						. "Update URI: https://github.com/owner/repository\n*/",
				)
			);
			$service                         = $this->service( $this->binding() );
			$GLOBALS['ran_github_responses'] = $this->prospectiveInspectionResponses(
				7,
				'v1.2.3',
				$archive
			);

			$this->expectException( ReleaseFailure::class );
			try {
				$service->inspectProspective( '7', 'v1.2.3' );
			} finally {
				$this->assertAllTemporaryPathsAbsent();
			}
		}

		public function testProspectiveAcquisitionRepeatsProofAndTransfersExactCustody(): void {
			$calls                           = 0;
			$archive                         = $this->prospectiveArchive(
				"<?php\n/*\nPlugin Name: Repository\nVersion: 1.2.3\n"
				. "Update URI: https://github.com/owner/repository\n"
				. "Requires PHP: 8.2\nRequires at least: 6.8\n*/"
			);
			$service                         = $this->service(
				$this->binding(),
				new GitHubCredentialResolver(
					static function () use ( &$calls ): string {
						++$calls;
						return 'private-token';
					}
				)
			);
			$GLOBALS['ran_github_responses'] = $this->prospectiveInspectionResponses( 7, 'v1.2.3', $archive );
			$inspection                      = $service->inspectProspective( '7', 'v1.2.3' );
			$persisted                       = ProspectiveReleaseInspection::rehydrate( $inspection->toArray() );
			$GLOBALS['ran_github_responses'] = $this->prospectiveInspectionResponses( 7, 'v1.2.3', $archive );

			$owned = $service->acquireProspective( $persisted, $persisted->fingerprintValue() );

			self::assertInstanceOf( ProspectiveReleaseArtifact::class, $owned );
			self::assertSame( $persisted->toArray(), $owned->inspection()->toArray() );
			self::assertSame( 2, $calls );
			self::assertCount( 12, $GLOBALS['ran_github_requests'] );
			$artifact = $owned->claimTemporaryArtifact();
			$path     = $artifact->inspect( static fn ( string $path ): string => $path );
			self::assertFileExists( $path );
			unset( $owned );
			self::assertFileExists( $path );
			self::assertTrue( $artifact->discard() );
			self::assertFileDoesNotExist( $path );
		}

		public function testProspectiveAcquisitionRejectsWrongFingerprintBeforeCredentialOrHttp(): void {
			$archive                         = $this->prospectiveArchive(
				"<?php\n/*\nPlugin Name: Repository\nVersion: 1.2.3\n"
				. "Update URI: https://github.com/owner/repository\n"
				. "Requires PHP: 8.2\nRequires at least: 6.8\n*/"
			);
			$service                         = $this->service( $this->binding() );
			$GLOBALS['ran_github_responses'] = $this->prospectiveInspectionResponses( 7, 'v1.2.3', $archive );
			$inspection                      = $service->inspectProspective( '7', 'v1.2.3' );
			$requests                        = count( $GLOBALS['ran_github_requests'] );
			$calls                           = 0;
			$service                         = $this->service(
				$this->binding(),
				new GitHubCredentialResolver(
					static function () use ( &$calls ): string {
						++$calls;
						return 'private-token'; }
				)
			);

			try {
				$service->acquireProspective( $inspection, 'v1:' . str_repeat( '0', 64 ) );
				self::fail( 'A stale fingerprint must fail before provider work.' );
			} catch ( \InvalidArgumentException ) {
				self::addToAssertionCount( 1 );
			}
			self::assertSame( 0, $calls );
			self::assertCount( $requests, $GLOBALS['ran_github_requests'] );
			$this->assertAllTemporaryPathsAbsent();
		}

		public function testProspectiveAcquisitionDiscardsAChangedArchiveProof(): void {
			$calls                           = 0;
			$header                          = "<?php\n/*\nPlugin Name: Repository\nVersion: 1.2.3\n"
			. "Update URI: https://github.com/owner/repository\n"
			. "Requires PHP: 8.2\nRequires at least: 6.8\n*/";
			$initial                         = $this->prospectiveArchive( $header );
			$changed                         = $this->prospectiveArchive( $header, array( 'repository/payload.php' => '<?php return true;' ) );
			$service                         = $this->service(
				$this->binding(),
				new GitHubCredentialResolver(
					static function () use ( &$calls ): string {
						++$calls;
						return 'private-token'; }
				)
			);
			$GLOBALS['ran_github_responses'] = $this->prospectiveInspectionResponses( 7, 'v1.2.3', $initial );
			$inspection                      = $service->inspectProspective( '7', 'v1.2.3' );
			$GLOBALS['ran_github_responses'] = $this->prospectiveInspectionResponses( 7, 'v1.2.3', $changed );

			try {
				$service->acquireProspective( $inspection, $inspection->fingerprintValue() );
				self::fail( 'Changed archive facts must reject reacquisition.' );
			} catch ( RuntimeException $exception ) {
				self::assertStringContainsString( 'changed before acquisition', $exception->getMessage() );
			}
			self::assertSame( 2, $calls );
			$this->assertAllTemporaryPathsAbsent();
		}

		public function testProspectiveAcquisitionDiscardsChangedReleaseFacts(): void {
			$archive                         = $this->prospectiveArchive(
				"<?php\n/*\nPlugin Name: Repository\nVersion: 1.2.3\n"
				. "Update URI: https://github.com/owner/repository\n"
				. "Requires PHP: 8.2\nRequires at least: 6.8\n*/"
			);
			$service                         = $this->service( $this->binding() );
			$GLOBALS['ran_github_responses'] = $this->prospectiveInspectionResponses( 7, 'v1.2.3', $archive );
			$inspection                      = $service->inspectProspective( '7', 'v1.2.3' );
			$responses                       = $this->prospectiveInspectionResponses( 7, 'v1.2.3', $archive );
			$responses[2]                    = $this->response( 200, array( 'sha' => str_repeat( 'b', 40 ) ) );
			$GLOBALS['ran_github_responses'] = $responses;

			try {
				$service->acquireProspective( $inspection, $inspection->fingerprintValue() );
				self::fail( 'Changed release facts must reject reacquisition.' );
			} catch ( RuntimeException $exception ) {
				self::assertStringContainsString( 'changed before acquisition', $exception->getMessage() );
			}
			$this->assertAllTemporaryPathsAbsent();
		}

		public function testProspectiveAcquisitionDiscardsAChangedHostileArchive(): void {
			$header                          = "<?php\n/*\nPlugin Name: Repository\nVersion: 1.2.3\n"
			. "Update URI: https://github.com/owner/repository\n"
			. "Requires PHP: 8.2\nRequires at least: 6.8\n*/";
			$initial                         = $this->prospectiveArchive( $header );
			$hostile                         = $this->prospectiveArchive(
				$header,
				array( 'repository/other.php' => str_replace( 'Repository', 'Other', $header ) )
			);
			$service                         = $this->service( $this->binding() );
			$GLOBALS['ran_github_responses'] = $this->prospectiveInspectionResponses( 7, 'v1.2.3', $initial );
			$inspection                      = $service->inspectProspective( '7', 'v1.2.3' );
			$GLOBALS['ran_github_responses'] = $this->prospectiveInspectionResponses( 7, 'v1.2.3', $hostile );

			try {
				$service->acquireProspective( $inspection, $inspection->fingerprintValue() );
				self::fail( 'A hostile changed archive must reject reacquisition.' );
			} catch ( ReleaseFailure $exception ) {
				self::assertSame( 'package_incompatible', $exception->releaseCode );
			}
			$this->assertAllTemporaryPathsAbsent();
		}

		public function testProspectiveInspectionFingerprintRejectsChangedRuntimeAndPackageFacts(): void {
			$archive                         = $this->prospectiveArchive(
				"<?php\n/*\nPlugin Name: Repository\nVersion: 1.2.3\n"
				. "Update URI: https://github.com/owner/repository\n"
				. "Requires PHP: 8.2\nRequires at least: 6.8\n*/"
			);
			$GLOBALS['ran_github_responses'] = $this->prospectiveInspectionResponses( 7, 'v1.2.3', $archive );
			$inspection                      = $this->service( $this->binding() )->inspectProspective( '7', 'v1.2.3' );
			$facts                           = $inspection->toArray();
			unset( $facts['fingerprint'] );
			$changedRuntime = ProspectiveReleaseInspection::create( array_replace( $facts, array( 'php_runtime_version' => '8.3' ) ) );
			$changedRoot    = ProspectiveReleaseInspection::create( array_replace( $facts, array( 'package_root' => 'renamed' ) ) );

			self::assertNotSame( $inspection->fingerprintValue(), $changedRuntime->fingerprintValue() );
			self::assertNotSame( $inspection->fingerprintValue(), $changedRoot->fingerprintValue() );
			$tampered              = $inspection->toArray();
			$tampered['main_file'] = 'other.php';
			$this->expectException( \InvalidArgumentException::class );
			ProspectiveReleaseInspection::rehydrate( $tampered );
		}

		public function testProspectiveInspectionUsesExactKeysAndDefensiveAccessors(): void {
			$archive                         = $this->prospectiveArchive(
				"<?php\n/*\nPlugin Name: Repository\nVersion: 1.2.3\n"
				. "Update URI: https://github.com/owner/repository\n"
				. "Requires PHP: 8.2\nRequires at least: 6.8\n*/"
			);
			$GLOBALS['ran_github_responses'] = $this->prospectiveInspectionResponses( 7, 'v1.2.3', $archive );
			$inspection                      = $this->service( $this->binding() )->inspectProspective( '7', 'v1.2.3' );
			self::assertSame( '7', $inspection->releaseIdentity() );
			self::assertSame( 'v1.2.3', $inspection->tag() );
			$copy                     = $inspection->toArray();
			$copy['release_identity'] = 'changed';
			self::assertSame( '7', $inspection->releaseIdentity() );

			$facts = $inspection->toArray();
			unset( $facts['fingerprint'] );
			try {
				ProspectiveReleaseInspection::create( array_merge( $facts, array( 'unexpected' => true ) ) );
				self::fail( 'Unknown prospective facts must fail closed.' );
			} catch ( \InvalidArgumentException ) {
				self::addToAssertionCount( 1 );
			}
			$opaque  = ProspectiveReleaseInspection::create(
				array_replace( $facts, array( 'release_identity' => 'release:opaque' ) )
			);
			$calls   = 0;
			$service = $this->service(
				$this->binding(),
				new GitHubCredentialResolver(
					static function () use ( &$calls ): string {
						++$calls;
						return 'private-token'; }
				)
			);
			try {
				$service->acquireProspective( $opaque, $opaque->fingerprintValue() );
				self::fail( 'Provider-private numeric validation must reject opaque GitHub IDs.' );
			} catch ( \InvalidArgumentException ) {
				self::addToAssertionCount( 1 );
			}
			self::assertSame( 0, $calls );
		}

		#[\PHPUnit\Framework\Attributes\DataProvider( 'unsafeRedirectProvider' )]
		public function testUnsafeExpiredAndExcessRedirectsFailClosed( string $location ): void {
			$adapter                         = new GitHubReleaseAdapter( $this->binding() );
			$GLOBALS['ran_github_responses'] = $this->inspectionResponses( 7, 'v1.2.3' );
			$descriptor                      = $adapter->inspect( '7' );
			$GLOBALS['ran_github_responses'] = array(
				$this->response( 200, array( 'id' => 99 ) ),
				$this->response( 302, null, array( 'location' => $location ) ),
			);

			$this->expectException( RuntimeException::class );
			try {
				$adapter->acquire( $descriptor );
			} finally {
				$this->assertAllTemporaryPathsAbsent();
			}
		}

		/** @return iterable<string,array{0:string}> */
		public static function unsafeRedirectProvider(): iterable {
			yield 'external host' => array( 'https://example.invalid/file' );
			yield 'userinfo' => array( 'https://user@release-assets.githubusercontent.com/file' );
			yield 'ip host' => array( 'https://127.0.0.1/file' );
			yield 'fragment' => array( 'https://release-assets.githubusercontent.com/file#fragment' );
			yield 'expired epoch' => array(
				'https://release-assets.githubusercontent.com/file?expires=1',
			);
			yield 'duplicate expiry' => array(
				'https://release-assets.githubusercontent.com/file?expires=4102444800&expires=4102444801',
			);
			yield 'mixed expiry families' => array(
				'https://release-assets.githubusercontent.com/file?expires=4102444800&se=2099-01-01T00:00:00Z',
			);
			yield 'invalid Azure calendar date' => array(
				'https://release-assets.githubusercontent.com/file?se=2099-02-30T00:00:00Z',
			);
			yield 'invalid AWS calendar date' => array(
				'https://release-assets.githubusercontent.com/file?X-Amz-Date=20990230T000000Z&X-Amz-Expires=60',
			);
		}

		public function testSecondRedirectAndDownloadedDigestMismatchCleanOwnedFile(): void {
			$adapter                         = new GitHubReleaseAdapter( $this->binding() );
			$GLOBALS['ran_github_responses'] = $this->inspectionResponses( 7, 'v1.2.3' );
			$descriptor                      = $adapter->inspect( '7' );
			$GLOBALS['ran_github_responses'] = array(
				$this->response( 200, array( 'id' => 99 ) ),
				$this->response(
					302,
					null,
					array( 'location' => 'https://release-assets.githubusercontent.com/one' )
				),
				$this->response(
					302,
					null,
					array( 'location' => 'https://objects.githubusercontent.com/two' )
				),
			);

			try {
				$adapter->acquire( $descriptor );
				self::fail( 'A second redirect must fail.' );
			} catch ( RuntimeException ) {
				$this->assertAllTemporaryPathsAbsent();
			}

			$GLOBALS['ran_github_responses'] = array(
				$this->response( 200, array( 'id' => 99 ) ),
				$this->response( 200, null, array(), 'changed!' ),
			);
			try {
				$adapter->acquire( $descriptor );
				self::fail( 'Changed downloaded bytes must fail.' );
			} catch ( RuntimeException ) {
				$this->assertAllTemporaryPathsAbsent();
			}
		}

		public function testAcquisitionRateLimitIsDistinctAndCleansOwnedFile(): void {
			$adapter                         = new GitHubReleaseAdapter( $this->binding() );
			$GLOBALS['ran_github_responses'] = $this->inspectionResponses( 7, 'v1.2.3' );
			$descriptor                      = $adapter->inspect( '7' );
			$GLOBALS['ran_github_responses'] = array(
				$this->response( 200, array( 'id' => 99 ) ),
				$this->response( 429, null, array( 'retry-after' => '30' ) ),
			);

			$this->expectException( ReleaseFailure::class );
			try {
				$adapter->acquire( $descriptor );
			} finally {
				$this->assertAllTemporaryPathsAbsent();
			}
		}

		public function testAcquisitionAssetNotFoundIsGenericAndCleansOwnedFile(): void {
			$adapter                         = new GitHubReleaseAdapter( $this->binding() );
			$GLOBALS['ran_github_responses'] = $this->inspectionResponses( 7, 'v1.2.3' );
			$descriptor                      = $adapter->inspect( '7' );
			$GLOBALS['ran_github_responses'] = array(
				$this->response( 200, array( 'id' => 99 ) ),
				$this->response( 404, null ),
			);

			try {
				$adapter->acquire( $descriptor );
				self::fail( 'A missing release asset must fail.' );
			} catch ( ReleaseFailure $exception ) {
				self::assertSame( 'release_unavailable', $exception->releaseCode );
				$this->assertAllTemporaryPathsAbsent();
			}

			self::assertSame(
				array(
					'https://api.github.com/repos/owner/repository',
					'https://api.github.com/repos/owner/repository/releases/assets/8',
				),
				array_slice( array_column( $GLOBALS['ran_github_requests'], 0 ), 3 )
			);
		}

		public function testOversizedStreamIsRejectedAndCleanedBeforeDigesting(): void {
			$adapter                         = new GitHubReleaseAdapter( $this->binding() );
			$GLOBALS['ran_github_responses'] = $this->inspectionResponses( 7, 'v1.2.3' );
			$descriptor                      = $adapter->inspect( '7' );
			$oversized                       = $this->response( 200, null );
			$oversized['file_size']          = 52_428_800 + 1;
			$GLOBALS['ran_github_responses'] = array(
				$this->response( 200, array( 'id' => 99 ) ),
				$oversized,
			);

			$this->expectException( ReleaseFailure::class );
			try {
				$adapter->acquire( $descriptor );
			} finally {
				$this->assertAllTemporaryPathsAbsent();
			}
		}

		public function testCustomLimitBoundsTheDownloadAndRejectsAnOverCustomActualSize(): void {
			$limit                           = 83886080;
			$adapter                         = new GitHubReleaseAdapter( $this->binding( maximumArtifactBytes: $limit ) );
			$GLOBALS['ran_github_responses'] = $this->inspectionResponses( 7, 'v1.2.3' );
			$descriptor                      = $adapter->inspect( '7' );
			$oversized                       = $this->response( 200, null );
			$oversized['file_size']          = $limit + 1;
			$GLOBALS['ran_github_responses'] = array( $this->response( 200, array( 'id' => 99 ) ), $oversized );
			try {
				$adapter->acquire( $descriptor );
				self::fail( 'An over-custom downloaded size was accepted.' );
			} catch ( ReleaseFailure $exception ) {
				self::assertSame( 'package_incompatible', $exception->releaseCode );
				self::assertSame( $limit + 1, $GLOBALS['ran_github_requests'][4][1]['limit_response_size'] );
			} finally {
				$this->assertAllTemporaryPathsAbsent(); }
		}

		public function testMaximumIntegerLimitDoesNotOverflowTheDownloadResponseLimit(): void {
			$service                         = $this->service( $this->binding( maximumArtifactBytes: PHP_INT_MAX ) );
			$GLOBALS['ran_github_responses'] = array( $this->response( 200, array() ) );
			$service->listReleases();
			self::assertSame( 262145, $GLOBALS['ran_github_requests'][0][1]['limit_response_size'] );
			// The artifact request is the only request sized from the target limit; invoke its private boundary through acquisition setup below.
			$adapter                         = new GitHubReleaseAdapter( $this->binding( maximumArtifactBytes: PHP_INT_MAX ) );
			$GLOBALS['ran_github_responses'] = $this->inspectionResponses( 7, 'v1.2.3' );
			$descriptor                      = $adapter->inspect( '7' );
			$GLOBALS['ran_github_responses'] = array( $this->response( 200, array( 'id' => 99 ) ), $this->response( 500, null ) );
			try {
				$adapter->acquire( $descriptor ); } catch ( RuntimeException ) {
				}
				self::assertSame( PHP_INT_MAX, $GLOBALS['ran_github_requests'][5][1]['limit_response_size'] );
		}

		public function testTemporaryArtifactRejectsReplacementAndNeverDeletesUnownedBytes(): void {
			$adapter                         = new GitHubReleaseAdapter( $this->binding() );
			$GLOBALS['ran_github_responses'] = $this->inspectionResponses( 7, 'v1.2.3' );
			$descriptor                      = $adapter->inspect( '7' );
			$GLOBALS['ran_github_responses'] = array(
				$this->response( 200, array( 'id' => 99 ) ),
				$this->response( 200, null, array(), 'zip-data' ),
				$this->response( 200, array( 'id' => 99 ) ),
			);
			$artifact                        = $adapter->acquire( $descriptor );
			$path                            = $artifact->inspect( static fn ( string $path ): string => $path );
			file_put_contents( $path, 'replacement' );
			chmod( $path, 0600 );

			self::assertFalse( $artifact->discard() );
			unset( $artifact );
			self::assertFileExists( $path );
			self::assertSame( 'replacement', file_get_contents( $path ) );
			self::assertTrue( unlink( $path ) );
		}

		private function binding(
			string $channel = 'stable',
			string $targetType = 'plugin',
			string $repositoryIdentity = '99',
			int $maximumArtifactBytes = 52428800
		): BindingRecord {
			return BindingRecord::create(
				array(
					'canonical_repository_locator' => 'owner/repository',
					'canonical_update_uri'         => 'https://github.com/owner/repository',
					'installed_package_identity'   => 'plugin' === $targetType
						? 'repository/repository.php'
						: 'repository',
					'maximum_artifact_bytes'       => $maximumArtifactBytes,
					'network_id'                   => 1,
					'php_runtime_version'          => '8.2.0',
					'provider_code'                => 'github',
					'release_channel'              => $channel,
					'stable_repository_identity'   => $repositoryIdentity,
					'target_type'                  => $targetType,
					'theme_template'               => '',
					'update_policy'                => 'automatic',
					'wordpress_runtime_version'    => '6.8.0',
				)
			);
		}

		private function service(
			BindingRecord $binding,
			?GitHubCredentialResolver $credentials = null
		): GitHubReleaseService {
			return new GitHubReleaseService( $this->serviceConfiguration( $binding ), $credentials );
		}

		private function publicService(): GitHubReleaseService {
			return new GitHubReleaseService(
				$this->serviceConfiguration( $this->binding() ),
				null,
				static fn (): ?string => null
			);
		}

		/** @return list<array<string,mixed>> */
		private function invalidStreamResponses(): array {
			return array(
				$this->response( 200, array( 'id' => 99 ) ),
				$this->response( 200, $this->release( 7, 'v1.2.3' ) ),
				$this->response( 200, array( 'sha' => str_repeat( 'a', 40 ) ) ),
				$this->response( 200, array( 'id' => 99 ) ),
				$this->response( 200, null, array(), 'invalid bytes' ),
			);
		}

		/** @return array<string,mixed> */
		private function serviceConfiguration( BindingRecord $binding ): array {
			$facts = $binding->toArray();
			return array(
				'canonical_repository_locator' => $facts['canonical_repository_locator'],
				'canonical_update_uri'         => $facts['canonical_update_uri'],
				'maximum_artifact_bytes'       => $facts['maximum_artifact_bytes'],
				'php_runtime_version'          => $facts['php_runtime_version'],
				'release_channel'              => $facts['release_channel'],
				'stable_repository_identity'   => $facts['stable_repository_identity'],
				'target_type'                  => $facts['target_type'],
				'wordpress_runtime_version'    => $facts['wordpress_runtime_version'],
			);
		}

		/** @return list<array<string,mixed>> */
		private function inspectionResponses(
			int $releaseIdentity,
			string $tag,
			bool $prerelease = false,
			bool $immutable = true
		): array {
			return array(
				$this->response( 200, array( 'id' => 99 ) ),
				$this->response(
					200,
					$this->release(
						$releaseIdentity,
						$tag,
						$prerelease,
						true,
						$immutable
					)
				),
				$this->response( 200, array( 'sha' => str_repeat( 'a', 40 ) ) ),
			);
		}

		/** @return list<array<string,mixed>> */
		private function prospectiveInspectionResponses(
			int $releaseIdentity,
			string $tag,
			string $archive
		): array {
			$release                        = $this->release( $releaseIdentity, $tag );
			$release['assets'][0]['digest'] = 'sha256:' . hash( 'sha256', $archive );
			$release['assets'][0]['size']   = strlen( $archive );
			return array(
				$this->response( 200, array( 'id' => 99 ) ),
				$this->response( 200, $release ),
				$this->response( 200, array( 'sha' => str_repeat( 'a', 40 ) ) ),
				$this->response( 200, array( 'id' => 99 ) ),
				$this->response( 200, null, array(), $archive ),
				$this->response( 200, array( 'id' => 99 ) ),
			);
		}

		/** @param array<string,string> $additionalEntries */
		private function prospectiveArchive( string $header, array $additionalEntries = array() ): string {
			$path = tempnam( sys_get_temp_dir(), 'ran-github-prospective-' );
			self::assertIsString( $path );
			$zip = new \ZipArchive();
			self::assertTrue( $zip->open( $path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) );
			self::assertTrue( $zip->addFromString( 'repository/repository.php', $header ) );
			foreach ( $additionalEntries as $name => $contents ) {
				self::assertTrue( $zip->addFromString( $name, $contents ) );
			}
			$zip->close();
			$archive = file_get_contents( $path );
			self::assertIsString( $archive );
			self::assertTrue( unlink( $path ) );
			return $archive;
		}

		private function prospectiveThemeArchive( string $header ): string {
			$path = tempnam( sys_get_temp_dir(), 'ran-github-prospective-' );
			self::assertIsString( $path );
			$zip = new \ZipArchive();
			self::assertTrue( $zip->open( $path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) );
			self::assertTrue( $zip->addFromString( 'repository/style.css', $header ) );
			$zip->close();
			$archive = file_get_contents( $path );
			self::assertIsString( $archive );
			self::assertTrue( unlink( $path ) );
			return $archive;
		}

		/** @return array<string,mixed> */
		private function release(
			int $id,
			string $tag,
			bool $prerelease = false,
			bool $full = true,
			bool $immutable = true
		): array {
			$release = array(
				'assets'       => array( array( 'name' => 'repository.zip' ) ),
				'draft'        => false,
				'html_url'     => 'https://github.com/owner/repository/releases/tag/' . $tag,
				'id'           => $id,
				'immutable'    => $immutable,
				'prerelease'   => $prerelease,
				'published_at' => '2026-08-22T10:00:00Z',
				'tag_name'     => $tag,
			);
			if ( ! $full ) {
				return $release;
			}
			$release['assets'] = array(
				array(
					'digest' => 'sha256:' . hash( 'sha256', 'zip-data' ),
					'id'     => 8,
					'name'   => 'repository.zip',
					'size'   => 8,
					'state'  => 'uploaded',
				),
			);
			return $release;
		}

		/** @return array<string,mixed> */
		private function response(
			int|string $code,
			mixed $json,
			array $headers = array(),
			?string $file = null
		): array {
			$response = array(
				'body'     => null === $json
					? ''
					: json_encode( $json, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION ),
				'headers'  => $headers,
				'response' => array( 'code' => $code ),
			);
			if ( null !== $file ) {
				$response['file'] = $file;
			}
			return $response;
		}

		private function assertAllTemporaryPathsAbsent(): void {
			self::assertNotEmpty( $GLOBALS['ran_github_temp_paths'] );
			foreach ( $GLOBALS['ran_github_temp_paths'] as $path ) {
				self::assertFileDoesNotExist( $path );
				self::assertFalse( is_link( $path ) );
			}
		}
	}
}
