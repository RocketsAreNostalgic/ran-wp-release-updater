<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled provider transport globals are shared with the WordPress stubs and reset by the fixture lifecycle; keep their cross-file identities.

declare(strict_types=1);

// phpcs:ignore Universal.Namespaces.DisallowCurlyBraceSyntax.Forbidden, Universal.Namespaces.DisallowDeclarationWithoutName.Forbidden -- Keep global WordPress stubs and namespaced test code in the same isolated fixture. WordPress stubs must be declared in the global namespace used by production calls.
namespace {
	if ( ! class_exists( 'WP_Error' ) ) {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound, Generic.Classes.DuplicateClassName.Found -- This stub must occupy the WordPress global class identity used by production calls. The same foreign stub name is reused behind conditional or isolated-process load boundaries.
		final class WP_Error {

			public function __construct( public string $code, public string $message ) {
			}
		}
	}

	if ( ! function_exists( 'is_wp_error' ) ) {
		// phpcs:ignore Universal.Files.SeparateFunctionsFromOO.Mixed, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- The self-contained fixture combines foreign functions/classes with the test harness that exercises them. WordPress calls this global stub by its exact foreign function name.
		function is_wp_error( mixed $value ): bool {
			return $value instanceof WP_Error;
		}
	}

	if ( ! function_exists( 'wp_safe_remote_get' ) ) {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress calls this global stub by its exact foreign function name.
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
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write exact bytes for provider response and archive fixtures; WordPress helpers would alter the boundary under test.
				file_put_contents( $args['filename'], $response['file'] );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Set real fixture permission bits for archive custody and permission-boundary checks.
				chmod( $args['filename'], 0600 );
			}
			if ( isset( $args['filename'], $response['file_size'] ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- The fixture needs the native stream mode and handle, including exclusive creation or archive truncation semantics.
				$handle = fopen( $args['filename'], 'c+b' );
				if ( false === $handle || ! ftruncate( $handle, $response['file_size'] ) ) {
					throw new RuntimeException( 'Could not create the sparse GitHub test response.' );
				}
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the native stream owned by this fixture; WordPress filesystem abstractions do not own process or file handles.
				fclose( $handle );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Set real fixture permission bits for archive custody and permission-boundary checks.
				chmod( $args['filename'], 0600 );
			}
			return $response;
		}
	}

	if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress calls this global stub by its exact foreign function name.
		function wp_remote_retrieve_response_code( array $response ): int|string {
			return $response['response']['code'];
		}
	}

	if ( ! function_exists( 'wp_remote_retrieve_header' ) ) {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress calls this global stub by its exact foreign function name.
		function wp_remote_retrieve_header( array $response, string $name ): mixed {
			return $response['headers'][ strtolower( $name ) ] ?? null;
		}
	}

	if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress calls this global stub by its exact foreign function name.
		function wp_remote_retrieve_body( array $response ): string {
			return $response['body'] ?? '';
		}
	}

	if ( ! function_exists( 'wp_http_validate_url' ) ) {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress calls this global stub by its exact foreign function name.
		function wp_http_validate_url( string $url ): string|false {
			return false === ( $GLOBALS['ran_github_validate_urls'] ?? true ) ? false : $url;
		}
	}

	if ( ! function_exists( 'wp_tempnam' ) ) {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress calls this global stub by its exact foreign function name.
		function wp_tempnam( string $filename ): string|false {
			unset( $filename );
			$path = tempnam( sys_get_temp_dir(), 'ran-github-test-' );
			if ( is_string( $path ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Set real fixture permission bits for archive custody and permission-boundary checks.
				chmod( $path, 0600 );
				$GLOBALS['ran_github_temp_paths'][] = $path;
			}
			return $path;
		}
	}

	if ( ! defined( 'FS_METHOD' ) ) {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- The fixture supplies the WordPress FS_METHOD constant consumed by the filesystem gate.
		define( 'FS_METHOD', 'direct' ); }

	if ( ! function_exists( 'add_filter' ) ) {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress calls this global stub by its exact foreign function name.
		function add_filter( string $hook, mixed $callback, int $priority, int $arguments ): void {
			$GLOBALS['ran_wp_release_updater_test_hooks'][] = array( 'filter', $hook, $callback, $priority, $arguments );
		}
	}

	if ( ! function_exists( 'add_action' ) ) {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress calls this global stub by its exact foreign function name.
		function add_action( string $hook, mixed $callback, int $priority, int $arguments ): void {
			$GLOBALS['ran_wp_release_updater_test_hooks'][] = array( 'action', $hook, $callback, $priority, $arguments );
		}
	}
}

// phpcs:ignore Universal.Namespaces.OneDeclarationPerFile.MultipleFound, Universal.Namespaces.DisallowCurlyBraceSyntax.Forbidden -- This fixture colocates foreign global stubs and namespaced test or injected provider seams. Keep global WordPress stubs and namespaced test code in the same isolated fixture.
namespace RAN\WPReleaseUpdater\V1\Provider\GitHub {
	function chmod( string $path, int $permissions ): bool {
		if ( ( $GLOBALS['ran_github_chmod_failures'] ?? 0 ) > 0 ) {
			--$GLOBALS['ran_github_chmod_failures'];
			return false;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- The namespaced test seam delegates to native chmod after the injected permission-failure case.
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
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- The namespaced test seam delegates to native unlink after the injected deletion-failure case.
		return \unlink( $path );
	}
}

// phpcs:ignore Universal.Namespaces.OneDeclarationPerFile.MultipleFound, Universal.Namespaces.DisallowCurlyBraceSyntax.Forbidden -- This fixture colocates foreign global stubs and namespaced test or injected provider seams. Keep global WordPress stubs and namespaced test code in the same isolated fixture.
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

	// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- Keep the conditional WordPress stub and its test class in the same self-contained fixture.
	final class GitHubReleaseAdapterTest extends TestCase {

		// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this inherited lifecycle method name.
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

		// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this inherited lifecycle method name.
		protected function tearDown(): void {
			foreach ( $GLOBALS['ran_github_temp_paths'] as $path ) {
				if ( is_string( $path ) && ( is_file( $path ) || is_link( $path ) ) ) {
					// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- Best-effort fixture teardown tolerates paths already removed by the scenario. Remove native fixture entries directly, preserving the surrounding ownership and link-handling checks.
					@unlink( $path );
				}
			}
			parent::tearDown();
		}

		public function test_dormant_load_and_construction_perform_no_work(): void {
			$calls    = 0;
			$resolver = new GitHubCredentialResolver(
				static function () use ( &$calls ): string {
					++$calls;
					return 'private-token';
				}
			);

			$adapter = new GitHubReleaseAdapter( binding_record: $this->binding(), credentials: $resolver );

			self::assertInstanceOf( GitHubReleaseAdapter::class, $adapter );
			self::assertInstanceOf( ReleaseAdapter::class, $adapter );
			self::assertSame( 0, $calls );
			self::assertSame( array(), $GLOBALS['ran_github_requests'] );
			self::assertSame( array(), $GLOBALS['ran_github_temp_paths'] );
		}

		public function test_production_composition_activates_selected_root_and_remains_dormant_until_offer(): void {
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
						'runtime_protocol'  => 5,
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
			$updater       = GitHubReleaseAdapter::register_from_configuration(
				$configuration,
				$binding,
				new GitHubCredentialResolver(
					static function () use ( &$calls ): string {
						++$calls;
						return 'private-token'; }
				),
				new class() {},
				archive_policy: array(
					'archive_root'   => 'repository',
					'theme_template' => '',
				),
				selected_runtime_state: null,
				native_discovery_reuse: false
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
			self::assertNull( GitHubReleaseAdapter::register_from_configuration( $invalid, $binding, null, new class() {}, array() ) );
			$facts = $binding->to_array();
			unset( $facts['binding_hash'] );
			$facts['provider_code'] = 'gitlab';
			self::assertNull( GitHubReleaseAdapter::register_from_configuration( $configuration, BindingRecord::create( $facts ), null, new class() {}, array() ) );
			self::assertSame( 0, $calls );
			self::assertSame( array(), $GLOBALS['ran_github_requests'] );
			self::assertCount( 10, $GLOBALS['ran_wp_release_updater_test_hooks'] );
		}

		#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
		#[\PHPUnit\Framework\Attributes\PreserveGlobalState( false )]
		public function test_public_release_source_lists_empty_and_maps_credential_failure_without_http(): void {
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Seed the controlled WordPress global state observed by this fixture and its native callbacks.
			$GLOBALS['wp_version'] = '6.8.0';
			$registrar             = require dirname( __DIR__, 2 ) . '/bootstrap.php';
			$broker                = $GLOBALS['ran_wp_release_updater_v1_broker'] ?? null;
			self::assertIsObject( $broker );
			self::assertSame(
				'runtime_active',
				$broker->activate(
					array(
						'php_version'       => PHP_VERSION,
						'runtime_protocol'  => 5,
						'wordpress_version' => '6.8.0',
					)
				)['code']
			);
			$source              = $registrar->releases( 'github', 'plugin', 'owner/repository', '99' );
			$invalid_conditional = $source->list( array( 'etag' => 'invalid' ) );
			self::assertSame( 'invalid_configuration', $invalid_conditional['code'] );
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
			$not_modified                    = $source->list( array( 'etag' => '"old"' ) );
			self::assertSame( 'releases_not_modified', $not_modified['code'] );
			self::assertTrue( $not_modified['value']['not_modified'] );
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
		public function test_public_release_source_maps_asset_and_prospective_archive_failures(): void {
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Seed the controlled WordPress global state observed by this fixture and its native callbacks.
			$GLOBALS['wp_version'] = '6.8.0';
			$registrar             = require dirname( __DIR__, 2 ) . '/bootstrap.php';
			$broker                = $GLOBALS['ran_wp_release_updater_v1_broker'] ?? null;
			self::assertIsObject( $broker );
			self::assertSame(
				'runtime_active',
				$broker->activate(
					array(
						'php_version'       => PHP_VERSION,
						'runtime_protocol'  => 5,
						'wordpress_version' => '6.8.0',
					)
				)['code']
			);
			$header                          = "<?php\n/*\nPlugin Name: Repository\nVersion: 1.2.3\nUpdate URI: https://github.com/owner/repository\nRequires PHP: 8.2\nRequires at least: 6.8\n*/";
			$archive                         = $this->prospective_archive( $header );
			$source                          = $registrar->releases( 'github', 'plugin', 'owner/repository', '99' );
			$GLOBALS['ran_github_responses'] = $this->prospective_inspection_responses( 7, 'v1.2.3', $archive );
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
			$GLOBALS['ran_github_responses'] = $this->prospective_inspection_responses( 7, 'v1.2.3', $archive );
			$inspection                      = $source->inspect( '7', 'v1.2.3' );
			$GLOBALS['ran_github_responses'] = $this->prospective_inspection_responses( 7, 'v1.2.3', $archive );
			$changed                         = $source->acquire( '7', 'v1.2.3', 'v2:' . str_repeat( '0', 64 ) );
			self::assertSame( 'release_changed', $changed['code'] );
			self::assertSame( 'complete', $changed['cleanup_status'] );
			self::assert_all_temporary_paths_absent();
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
			self::assert_all_temporary_paths_absent();
		}

		public function test_public_service_keeps_repository_and_exact_release_failures_distinct(): void {
			$service                         = $this->public_service();
			$GLOBALS['ran_github_responses'] = array( $this->response( 404, null ) );
			try {
				$service->inspect( '7', 'v1.2.3' );
				self::fail( 'The repository access failure must be structured.' );
			} catch ( ReleaseFailure $failure ) {
				self::assertSame( 'repository_access_unavailable', $failure->release_code );
				self::assertSame( 'not_applicable', $failure->cleanup_status );
			}

			$GLOBALS['ran_github_responses'] = array(
				$this->response( 200, array( 'id' => 99 ) ),
				$this->response( 404, null ),
			);
			try {
				$service->inspect( '7', 'v1.2.3' );
				self::fail( 'The exact release failure must be structured.' );
			} catch ( ReleaseFailure $failure ) {
				self::assertSame( 'release_unavailable', $failure->release_code );
				self::assertSame( 'not_applicable', $failure->cleanup_status );
			}
		}

		public function test_public_service_rejects_malformed_conditional_before_http(): void {
			try {
				$this->public_service()->list( array( 'etag' => 'invalid' ) );
				self::fail( 'Malformed conditional state must fail before provider work.' );
			} catch ( ReleaseFailure $failure ) {
				self::assertSame( 'invalid_configuration', $failure->release_code );
			}
			self::assertSame( array(), $GLOBALS['ran_github_requests'] );
		}

		public function test_public_service_cleans_rate_limited_and_invalid_stream_failures(): void {
			$archive                         = $this->prospective_archive( "<?php\n/*\nPlugin Name: Repository\nVersion: 1.2.3\nUpdate URI: https://github.com/owner/repository\nRequires PHP: 8.2\nRequires at least: 6.8\n*/" );
			$service                         = $this->public_service();
			$GLOBALS['ran_github_responses'] = $this->prospective_inspection_responses( 7, 'v1.2.3', $archive );
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
				self::assertSame( 'rate_limited', $failure->release_code );
				self::assertSame( 30, $failure->retry_after );
				self::assertSame( 'complete', $failure->cleanup_status );
			}
			$this->assert_all_temporary_paths_absent();

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
				self::assertSame( 'package_incompatible', $failure->release_code );
				self::assertSame( 'complete', $failure->cleanup_status );
			}
			$this->assert_all_temporary_paths_absent();
		}

		public function test_public_service_stops_after_liveness_changes_during_credential_resolution(): void {
			$calls   = 0;
			$service = new GitHubReleaseService(
				$this->service_configuration( $this->binding() ),
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
				self::assertSame( 'runtime_unavailable', $failure->release_code );
			}
			self::assertSame( array(), $GLOBALS['ran_github_requests'] );
		}

		public function test_public_service_stops_after_liveness_changes_during_http_callback(): void {
			$revoked                                = false;
			$service                                = new GitHubReleaseService(
				$this->service_configuration( $this->binding() ),
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
				self::assertSame( 'runtime_unavailable', $failure->release_code );
			}
			self::assertCount( 1, $GLOBALS['ran_github_requests'] );
		}

		public function test_public_service_retries_cleanup_once_and_reports_both_outcomes(): void {
			$archive                         = $this->prospective_archive( "<?php\n/*\nPlugin Name: Repository\nVersion: 1.2.3\nUpdate URI: https://github.com/owner/repository\nRequires PHP: 8.2\nRequires at least: 6.8\n*/" );
			$service                         = $this->public_service();
			$GLOBALS['ran_github_responses'] = $this->prospective_inspection_responses( 7, 'v1.2.3', $archive );
			$inspection                      = $service->inspect( '7', 'v1.2.3' );

			$GLOBALS['ran_github_unlink_failures'] = 1;
			$GLOBALS['ran_github_responses']       = $this->invalid_stream_responses();
			try {
				$service->acquire( '7', 'v1.2.3', $inspection['fingerprint'] );
				self::fail( 'Invalid bytes must fail after cleanup retry.' );
			} catch ( ReleaseFailure $failure ) {
				self::assertSame( 'package_incompatible', $failure->release_code );
				self::assertSame( 'complete', $failure->cleanup_status );
			}
			self::assertSame( 0, $GLOBALS['ran_github_unlink_failures'] );
			$this->assert_all_temporary_paths_absent();

			$GLOBALS['ran_github_unlink_failures'] = 2;
			$GLOBALS['ran_github_responses']       = $this->invalid_stream_responses();
			try {
				$service->acquire( '7', 'v1.2.3', $inspection['fingerprint'] );
				self::fail( 'Invalid bytes must expose an unreleased owned file.' );
			} catch ( ReleaseFailure $failure ) {
				self::assertSame( 'package_incompatible', $failure->release_code );
				self::assertSame( 'failed', $failure->cleanup_status );
			}
			self::assertSame( 0, $GLOBALS['ran_github_unlink_failures'] );
			self::assertFileExists( $GLOBALS['ran_github_temp_paths'][ count( $GLOBALS['ran_github_temp_paths'] ) - 1 ] );
		}

		public function test_public_service_reports_temporary_allocation_cleanup_for_inspect_and_acquire(): void {
			$archive = $this->prospective_archive( "<?php\n/*\nPlugin Name: Repository\nVersion: 1.2.3\nUpdate URI: https://github.com/owner/repository\nRequires PHP: 8.2\nRequires at least: 6.8\n*/" );

			$service                              = $this->public_service();
			$GLOBALS['ran_github_chmod_failures'] = 1;
			$GLOBALS['ran_github_responses']      = $this->prospective_inspection_responses( 7, 'v1.2.3', $archive );
			try {
				$service->inspect( '7', 'v1.2.3' );
				self::fail( 'A chmod allocation failure must be structured.' );
			} catch ( ReleaseFailure $failure ) {
				self::assertSame( 'operation_failed', $failure->release_code );
				self::assertSame( 'complete', $failure->cleanup_status );
			}
			$this->assert_all_temporary_paths_absent();

			$service                               = $this->public_service();
			$GLOBALS['ran_github_chmod_failures']  = 1;
			$GLOBALS['ran_github_unlink_failures'] = 2;
			$GLOBALS['ran_github_responses']       = $this->prospective_inspection_responses( 7, 'v1.2.3', $archive );
			try {
				$service->inspect( '7', 'v1.2.3' );
				self::fail( 'An unreleased chmod allocation failure must be structured.' );
			} catch ( ReleaseFailure $failure ) {
				self::assertSame( 'operation_failed', $failure->release_code );
				self::assertSame( 'failed', $failure->cleanup_status );
			}
			$unreleased_path = $GLOBALS['ran_github_temp_paths'][ count( $GLOBALS['ran_github_temp_paths'] ) - 1 ];
			self::assertFileExists( $unreleased_path );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove native fixture entries directly, preserving the surrounding ownership and link-handling checks.
			self::assertTrue( \unlink( $unreleased_path ) );

			$service                                = $this->public_service();
			$GLOBALS['ran_github_unlink_failures']  = 0;
			$GLOBALS['ran_github_responses']        = $this->prospective_inspection_responses( 7, 'v1.2.3', $archive );
			$inspection                             = $service->inspect( '7', 'v1.2.3' );
			$GLOBALS['ran_github_lstat_failure_at'] = 2;
			$GLOBALS['ran_github_responses']        = $this->prospective_inspection_responses( 7, 'v1.2.3', $archive );
			try {
				$service->acquire( '7', 'v1.2.3', $inspection['fingerprint'] );
				self::fail( 'An identity allocation failure must be structured.' );
			} catch ( ReleaseFailure $failure ) {
				self::assertSame( 'operation_failed', $failure->release_code );
				self::assertSame( 'complete', $failure->cleanup_status );
			}
			$this->assert_all_temporary_paths_absent();

			$service                                = $this->public_service();
			$GLOBALS['ran_github_lstat_failure_at'] = 1;
			$GLOBALS['ran_github_responses']        = $this->prospective_inspection_responses( 7, 'v1.2.3', $archive );
			try {
				$service->inspect( '7', 'v1.2.3' );
				self::fail( 'An unproven temporary file must be structured.' );
			} catch ( ReleaseFailure $failure ) {
				self::assertSame( 'operation_failed', $failure->release_code );
				self::assertSame( 'failed', $failure->cleanup_status );
			}
			self::assertFileExists( $GLOBALS['ran_github_temp_paths'][ count( $GLOBALS['ran_github_temp_paths'] ) - 1 ] );
		}

		public function test_public_services_resolve_independent_credentials_per_operation(): void {
			$first_calls                     = 0;
			$second_calls                    = 0;
			$first                           = new GitHubReleaseService(
				$this->service_configuration( $this->binding() ),
				new GitHubCredentialResolver(
					static function () use ( &$first_calls ): string {
						++$first_calls;
						return 'first-token'; }
				),
				static fn (): ?string => null
			);
			$second                          = new GitHubReleaseService(
				$this->service_configuration( $this->binding() ),
				new GitHubCredentialResolver(
					static function () use ( &$second_calls ): string {
						++$second_calls;
						return 'second-token'; }
				),
				static fn (): ?string => null
			);
			$GLOBALS['ran_github_responses'] = array( $this->response( 200, array() ), $this->response( 200, array() ) );
			$first->list();
			$second->list();
			self::assertSame( 1, $first_calls );
			self::assertSame( 1, $second_calls );
			self::assertSame( 'Bearer first-token', $GLOBALS['ran_github_requests'][0][1]['headers']['Authorization'] );
			self::assertSame( 'Bearer second-token', $GLOBALS['ran_github_requests'][1][1]['headers']['Authorization'] );
		}

		public function test_public_service_maps_runtime_loss_during_package_inspection(): void {
			$checks                          = 0;
			$service                         = new GitHubReleaseService(
				$this->service_configuration( $this->binding() ),
				null,
				static function () use ( &$checks ): ?string {
					return ++$checks >= 17 ? 'runtime_unavailable' : null; }
			);
			$archive                         = $this->prospective_archive( "<?php\n/*\nPlugin Name: Repository\nVersion: 1.2.3\nUpdate URI: https://github.com/owner/repository\nRequires PHP: 8.2\nRequires at least: 6.8\n*/" );
			$GLOBALS['ran_github_responses'] = $this->prospective_inspection_responses( 7, 'v1.2.3', $archive );
			try {
				$service->inspect( '7', 'v1.2.3' );
				self::fail( 'Runtime loss after package inspection must suppress the inspection.' );
			} catch ( ReleaseFailure $failure ) {
				self::assertSame( 'runtime_unavailable', $failure->release_code );
				self::assertSame( 'complete', $failure->cleanup_status );
			}
			$this->assert_all_temporary_paths_absent();
		}

		public function test_credentials_resolve_once_per_top_level_chain_and_fail_before_http(): void {
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

			$adapter->list_releases();

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
				$invalid->list_releases();
			} finally {
				self::assertCount( 1, $GLOBALS['ran_github_requests'] );
			}
		}

		public function test_null_credential_result_uses_anonymous_request(): void {
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

			$adapter->list_releases();

			self::assertSame( 1, $calls );
			self::assertArrayNotHasKey( 'Authorization', $GLOBALS['ran_github_requests'][0][1]['headers'] );
		}

		public function test_invalid_caller_input_does_not_resolve_credentials_or_call_git_hub(): void {
			$valid                           = new GitHubReleaseAdapter( $this->binding() );
			$GLOBALS['ran_github_responses'] = $this->inspection_responses( 7, 'v1.2.3' );
			$descriptor                      = $valid->inspect( '7' );
			$facts                           = $descriptor->to_array();
			unset( $facts['fingerprint'] );
			$facts['artifact_identity'] = 'invalid-asset';
			$invalid_descriptor         = IdentityDescriptor::create( $facts );

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
				static fn (): TemporaryArtifact => $adapter->acquire( $invalid_descriptor ),
				static fn (): ProspectiveReleaseInspection => $service->inspect_prospective( 'not-a-release' ),
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

		public function test_prospective_configuration_and_zero_identity_fail_before_credentials_or_http(): void {
			$calls         = 0;
			$resolver      = new GitHubCredentialResolver(
				static function () use ( &$calls ): string {
					++$calls;
					return 'private-token';
				}
			);
			$configuration = $this->service_configuration( $this->binding() );
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
				$service->inspect_prospective( '0' );
				self::fail( 'GitHub release zero unexpectedly reached provider work.' );
			} catch ( \InvalidArgumentException ) {
				self::addToAssertionCount( 1 );
			}
			self::assertSame( 0, $calls );
			self::assertSame( array(), $GLOBALS['ran_github_requests'] );
			self::assertSame( array(), $GLOBALS['ran_github_temp_paths'] );
		}

		public function test_listing_sorts_stable_numeric_identities_and_returns_release_details(): void {
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

			$result = ( new GitHubReleaseAdapter( $this->binding() ) )->list_releases();

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

		public function test_listing_filters_invalid_versions_and_breaks_version_ties_by_identity(): void {
			$GLOBALS['ran_github_responses'] = array(
				$this->response(
					200,
					array(
						$this->release( 2, 'v1.1.0' ),
						$this->release( 3, 'v1.2' ),
						$this->release( 4, 'v01.2.3' ),
						$this->release( 10, 'v1.1.0' ),
						$this->release( 5, 'invalid' ),
						$this->release( 6, 'v2.0.0' ),
					)
				),
			);

			$result = ( new GitHubReleaseAdapter( $this->binding() ) )->list_releases();

			self::assertSame( array( '2.0.0', '1.1.0', '1.1.0' ), array_column( $result['candidates'], 'version' ) );
			self::assertSame( array( '6', '10', '2' ), array_column( $result['candidates'], 'release_identity' ) );
		}

		public function test_prerelease_listing_keeps_semver_ordering_within_its_channel(): void {
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

			$result = ( new GitHubReleaseAdapter( $this->binding( 'prerelease' ) ) )->list_releases();

			self::assertSame(
				array( '2.0.0', '2.0.0-beta.2', '2.0.0-beta.1' ),
				array_column( $result['candidates'], 'version' )
			);
			self::assertSame( array( false, true, true ), array_column( $result['candidates'], 'prerelease' ) );
		}

		public function test_listing_rejects_malformed_members_and_oversized_bodies(): void {
			$GLOBALS['ran_github_responses'] = array(
				$this->response( 200, array( $this->release( 1, 'v1.0.0' ), 'invalid-member' ) ),
			);
			try {
				( new GitHubReleaseAdapter( $this->binding() ) )->list_releases();
				self::fail( 'A malformed release-list member must reject the complete response.' );
			} catch ( ReleaseFailure $exception ) {
				self::assertSame( 'operation_failed', $exception->release_code );
				self::assertCount( 1, $GLOBALS['ran_github_requests'] );
			}

			$oversized                       = $this->response( 200, null );
			$oversized['body']               = str_repeat( 'x', 262145 );
			$GLOBALS['ran_github_responses'] = array( $oversized );
			try {
				( new GitHubReleaseAdapter( $this->binding() ) )->list_releases();
				self::fail( 'An oversized response must stop the operation.' );
			} catch ( ReleaseFailure $exception ) {
				self::assertSame( 'operation_failed', $exception->release_code );
			}
		}

		public function test_response_containers_distinguish_operation_and_candidate_failures(): void {
			$GLOBALS['ran_github_responses'] = array( $this->response( 200, (object) array() ) );
			try {
				( new GitHubReleaseAdapter( $this->binding() ) )->list_releases();
				self::fail( 'A JSON object is not a release listing.' );
			} catch ( ReleaseFailure $failure ) {
				self::assertSame( 'operation_failed', $failure->release_code );
			}

			$GLOBALS['ran_github_responses'] = array(
				$this->response( 200, array( 'id' => 99 ) ),
				$this->response( 200, (object) array() ),
			);
			try {
				( new GitHubReleaseAdapter( $this->binding() ) )->inspect( '7', 'v1.2.3' );
				self::fail( 'An empty release object is not a usable candidate.' );
			} catch ( ReleaseFailure $failure ) {
				self::assertSame( 'package_incompatible', $failure->release_code );
			}
		}

		public function test_listing_uses_two_bounded_pages_and_returns_at_most_eight_candidates(): void {
			$credential_calls = 0;
			$service          = $this->service(
				$this->binding(),
				new GitHubCredentialResolver(
					static function () use ( &$credential_calls ): string {
						++$credential_calls;
						return 'private-token';
					}
				)
			);
			$drafts           = array();
			for ( $index = 1; $index <= 20; ++$index ) {
				$release          = $this->release( $index, 'v1.0.' . $index );
				$release['draft'] = true;
				$drafts[]         = $release;
			}
			$page_two = array();
			for ( $index = 21; $index <= 30; ++$index ) {
				$page_two[] = $this->release( $index, 'v2.0.' . ( $index - 21 ) );
			}
			$GLOBALS['ran_github_responses'] = array(
				$this->response( 200, $drafts ),
				$this->response( 200, $page_two ),
			);

			$result = $service->list_releases(
				array( 'etag' => '"prior"' )
			);

			self::assertSame( 1, $credential_calls );
			self::assertCount( 2, $GLOBALS['ran_github_requests'] );
			self::assertStringContainsString( 'page=2', $GLOBALS['ran_github_requests'][1][0] );
			self::assertArrayNotHasKey(
				'If-None-Match',
				$GLOBALS['ran_github_requests'][1][1]['headers']
			);
			self::assertCount( 8, $result['candidates'] );
			self::assertTrue( $result['search_exhausted'] );
		}

		public function test_conditional_not_modified_and_rate_limit_remain_closed_provider_state(): void {
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

			$result = $adapter->list_releases(
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
				$adapter->list_releases();
				self::fail( 'An over-bound rate-limit delay must stop the operation.' );
			} catch ( ReleaseFailure $failure ) {
				self::assertSame( 'operation_failed', $failure->release_code );
			}
		}

		#[\PHPUnit\Framework\Attributes\DataProvider( 'read_unavailable_status_provider' )]
		public function test_authenticated_authorization_failures_are_not_rate_limits( int $status ): void {
			$GLOBALS['ran_github_responses'] = array( $this->response( $status, null ) );
			$this->expectException( ReleaseFailure::class );
			( new GitHubReleaseAdapter( $this->binding() ) )->list_releases();
		}

		/** @return iterable<string,array{0:int}> */
		public static function read_unavailable_status_provider(): iterable {
			yield 'unauthorized' => array( 401 );
			yield 'forbidden' => array( 403 );
			yield 'not found' => array( 404 );
		}

		public function test_transport_and_credential_failures_signal_unavailable_read(): void {
			$GLOBALS['ran_github_responses'] = array( new \WP_Error( 'transport', 'not connected' ) );
			$this->expectException( ReleaseFailure::class );
			try {
				( new GitHubReleaseAdapter( $this->binding() ) )->list_releases();
			} catch ( ReleaseFailure $exception ) {
				self::assertSame( 'operation_failed', $exception->release_code );
				throw $exception;
			}
		}

		public function test_invalid_credential_signals_unavailable_read_before_http(): void {
			$adapter = new GitHubReleaseAdapter(
				$this->binding(),
				new GitHubCredentialResolver( static fn (): string => 'bad token' )
			);

			$this->expectException( ReleaseFailure::class );
			try {
				$adapter->list_releases();
			} finally {
				self::assertSame( array(), $GLOBALS['ran_github_requests'] );
			}
		}

		public function test_non_string_credential_result_signals_unavailable_read_before_http(): void {
			$adapter = new GitHubReleaseAdapter(
				$this->binding(),
				new GitHubCredentialResolver( static fn (): int => 42 )
			);

			$this->expectException( ReleaseFailure::class );
			try {
				$adapter->list_releases();
			} finally {
				self::assertSame( array(), $GLOBALS['ran_github_requests'] );
			}
		}

		public function test_unavailable_credential_signals_unavailable_read_before_http(): void {
			$adapter = new GitHubReleaseAdapter(
				$this->binding(),
				new GitHubCredentialResolver(
					static function (): string {
						throw new RuntimeException( 'credential source failed' ); }
				)
			);

			$this->expectException( ReleaseFailure::class );
			try {
				$adapter->list_releases();
			} finally {
				self::assertSame( array(), $GLOBALS['ran_github_requests'] );
			}
		}

		public function test_server_errors_remain_generic_runtime_failures(): void {
			$GLOBALS['ran_github_responses'] = array( $this->response( 500, null ) );
			$this->expectException( ReleaseFailure::class );
			try {
				( new GitHubReleaseAdapter( $this->binding() ) )->list_releases();
			} catch ( ReleaseFailure $exception ) {
				self::assertSame( 'operation_failed', $exception->release_code );
				throw $exception; }
		}

		public function test_inspection_binds_exact_numeric_repository_release_commit_and_asset(): void {
			$adapter                         = new GitHubReleaseAdapter( $this->binding() );
			$GLOBALS['ran_github_responses'] = $this->inspection_responses(
				7,
				'v1.2.3',
				false,
				true
			);

			$facts = $adapter->inspect( release_identity: '7', expected_tag: 'v1.2.3' )->to_array();

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

		#[\PHPUnit\Framework\Attributes\DataProvider( 'installed_inspection_failure_provider' )]
		public function test_installed_inspection_maps_endpoint_failures_to_neutral_failures(
			string $scenario,
			string $expected_code,
			int $expected_requests
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
				self::assertSame( $expected_code, $failure->release_code );
				self::assertSame( 'not_applicable', $failure->cleanup_status );
			}

			self::assertCount( $expected_requests, $GLOBALS['ran_github_requests'] );
		}

		/** @return iterable<string,array{0:string,1:string,2:int}> */
		public static function installed_inspection_failure_provider(): iterable {
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

		#[\PHPUnit\Framework\Attributes\DataProvider( 'invalid_zip_metadata_provider' )]
		public function test_installed_inspection_rejects_invalid_zip_metadata_before_commit_lookup( string $scenario ): void {
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
				self::assertSame( 'package_incompatible', $failure->release_code );
				self::assertSame( 'not_applicable', $failure->cleanup_status );
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
		public static function invalid_zip_metadata_provider(): iterable {
			foreach ( array( 'missing_zip', 'multiple_zip', 'invalid_id', 'invalid_size', 'missing_digest', 'over_limit', 'not_uploaded' ) as $scenario ) {
				yield $scenario => array( $scenario );
			}
		}

		public function test_inspection_treats_missing_concrete_release_as_generic_candidate_failure(): void {
			$GLOBALS['ran_github_responses'] = array(
				$this->response( 200, array( 'id' => 99 ) ),
				$this->response( 404, null ),
			);

			try {
				( new GitHubReleaseAdapter( $this->binding() ) )->inspect( '7', 'v1.2.3' );
				self::fail( 'A missing concrete release must fail without credential fallback.' );
			} catch ( ReleaseFailure $exception ) {
				self::assertSame( 'release_unavailable', $exception->release_code );
			}

			self::assertSame(
				array(
					'https://api.github.com/repos/owner/repository',
					'https://api.github.com/repos/owner/repository/releases/7',
				),
				array_column( $GLOBALS['ran_github_requests'], 0 )
			);
		}

		public function test_inspection_treats_missing_concrete_commit_as_generic_candidate_failure(): void {
			$GLOBALS['ran_github_responses'] = array(
				$this->response( 200, array( 'id' => 99 ) ),
				$this->response( 200, $this->release( 7, 'v1.2.3' ) ),
				$this->response( 404, null ),
			);

			try {
				( new GitHubReleaseAdapter( $this->binding() ) )->inspect( '7', 'v1.2.3' );
				self::fail( 'A missing concrete commit must fail without credential fallback.' );
			} catch ( ReleaseFailure $exception ) {
				self::assertSame( 'release_unavailable', $exception->release_code );
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

		public function test_inspection_rejects_locator_that_does_not_match_stable_repository_identity(): void {
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

		public function test_mutable_release_remains_manual_only_and_prerelease_theme_is_bound(): void {
			$adapter                         = new GitHubReleaseAdapter( $this->binding( 'prerelease', 'theme' ) );
			$GLOBALS['ran_github_responses'] = $this->inspection_responses(
				7,
				'v2.0.0-beta.1',
				true,
				false
			);

			$facts = $adapter->inspect( '7' )->to_array();

			self::assertSame( 'theme', $facts['target_type'] );
			self::assertTrue( $facts['prerelease'] );
			self::assertFalse( $facts['assurance_facts']['publication_immutable'] );
			self::assertFalse( $facts['assurance_facts']['provenance_verified'] );
			self::assertTrue( $facts['assurance_facts']['trusted_digest_source'] );
		}

		public function test_inspection_preserves_uppercase_zip_suffix_identity(): void {
			$release                         = $this->release( 7, 'v1.2.3', false, true );
			$release['assets'][0]['name']    = 'Repository.ZIP';
			$GLOBALS['ran_github_responses'] = array(
				$this->response( 200, array( 'id' => 99 ) ),
				$this->response( 200, $release ),
				$this->response( 200, array( 'sha' => str_repeat( 'a', 40 ) ) ),
			);

			$facts = ( new GitHubReleaseAdapter( $this->binding() ) )->inspect( '7' )->to_array();

			self::assertSame( 'Repository.ZIP', $facts['artifact_filename'] );
		}

		public function test_inspection_accepts_native_integer_identity_and_size_boundaries(): void {
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
			) )->inspect( (string) $identity )->to_array();

			self::assertSame( (string) $identity, $facts['repository_identity'] );
			self::assertSame( (string) $identity, $facts['release_identity'] );
			self::assertSame( (string) $identity, $facts['artifact_identity'] );
			self::assertSame( 52_428_800, $facts['artifact_size'] );
		}

		public function test_custom_binding_accepts_its_exact_larger_asset_metadata(): void {
			$limit                           = 52_428_800 + 1;
			$release                         = $this->release( 7, 'v1.2.3' );
			$release['assets'][0]['size']    = $limit;
			$GLOBALS['ran_github_responses'] = array(
				$this->response( 200, array( 'id' => 99 ) ),
				$this->response( 200, $release ),
				$this->response( 200, array( 'sha' => str_repeat( 'a', 40 ) ) ),
			);
			self::assertSame( $limit, ( new GitHubReleaseAdapter( $this->binding( maximum_artifact_bytes: $limit ) ) )->inspect( '7' )->to_array()['artifact_size'] );
		}

		#[\PHPUnit\Framework\Attributes\DataProvider( 'invalid_inspection_provider' )]
		public function test_inspection_rejects_changed_or_ambiguous_identity(
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
					$exception->release_code
				);
			}
		}

		/** @return iterable<string,array{0:string,1:callable}> */
		public static function invalid_inspection_provider(): iterable {
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

		public function test_private_credential_is_stripped_on_asset_redirect_and_custody_transfers_once(): void {
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
			$GLOBALS['ran_github_responses'] = $this->inspection_responses( 7, 'v1.2.3' );
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

		public function test_prospective_inspection_keeps_release_and_descriptor_facts_then_discards(): void {
			$calls                           = 0;
			$archive                         = $this->prospective_archive(
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
			$GLOBALS['ran_github_responses'] = $this->prospective_inspection_responses(
				7,
				'v1.2.3',
				$archive
			);

			$facts = $service->inspect_prospective( release_identity: '7', expected_tag: 'v1.2.3' )->to_array();

			self::assertSame( '7', $facts['release_identity'] );
			self::assertSame( 'v1.2.3', $facts['tag'] );
			self::assertSame( '1.2.3', $facts['version'] );
			self::assertSame( 'repository', $facts['package_root'] );
			self::assertSame( 'repository.php', $facts['main_file'] );
			self::assertArrayHasKey( 'fingerprint', $facts );
			self::assertSame( 1, $calls );
			self::assertCount( 6, $GLOBALS['ran_github_requests'] );
			$this->assert_all_temporary_paths_absent();
		}

		public function test_private_theme_prospective_flow_resolves_once_per_chain_and_discards(): void {
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
			$candidate                       = $service->list_releases()['candidates'][0];
			self::assertSame( 1, $calls );
			self::assertSame( '7', $candidate['release_identity'] );

			$archive                         = $this->prospective_theme_archive(
				"/*\nTheme Name: Repository\nVersion: 1.2.3\n"
				. "Update URI: https://github.com/owner/repository\n"
				. "Requires PHP: 8.2\nRequires at least: 6.8\n*/"
			);
			$GLOBALS['ran_github_responses'] = $this->prospective_inspection_responses(
				7,
				'v1.2.3',
				$archive
			);

			$facts = $service->inspect_prospective(
				$candidate['release_identity'],
				$candidate['tag']
			)->to_array();

			self::assertSame( 2, $calls );
			self::assertSame( 'theme', $facts['target_type'] );
			self::assertSame( 'repository', $facts['package_root'] );
			self::assertSame( 'style.css', $facts['main_file'] );
			foreach ( $GLOBALS['ran_github_requests'] as $request ) {
				self::assertSame( 'Bearer private-token', $request[1]['headers']['Authorization'] );
			}
			$this->assert_all_temporary_paths_absent();
		}

		public function test_public_plugin_prospective_flow_sends_no_authorization(): void {
			$archive                         = $this->prospective_archive(
				"<?php\n/*\nPlugin Name: Repository\nVersion: 1.2.3\n"
				. "Update URI: https://github.com/owner/repository\n"
				. "Requires PHP: 8.2\nRequires at least: 6.8\n*/"
			);
			$GLOBALS['ran_github_responses'] = $this->prospective_inspection_responses( 7, 'v1.2.3', $archive );

			$inspection = $this->service( $this->binding() )->inspect_prospective( '7', 'v1.2.3' );

			self::assertSame( 'plugin', $inspection->to_array()['target_type'] );
			foreach ( $GLOBALS['ran_github_requests'] as $request ) {
				self::assertArrayNotHasKey( 'Authorization', $request[1]['headers'] );
			}
			$this->assert_all_temporary_paths_absent();
		}

		public function test_prospective_inspection_rejects_archive_and_still_discards(): void {
			$archive                         = $this->prospective_archive(
				"<?php\n/*\nPlugin Name: Repository\nVersion: 1.2.3\n"
				. "Update URI: https://github.com/owner/repository\n*/",
				array(
					'repository/other.php' => "<?php\n/*\nPlugin Name: Other\nVersion: 1.2.3\n"
						. "Update URI: https://github.com/owner/repository\n*/",
				)
			);
			$service                         = $this->service( $this->binding() );
			$GLOBALS['ran_github_responses'] = $this->prospective_inspection_responses(
				7,
				'v1.2.3',
				$archive
			);

			$this->expectException( ReleaseFailure::class );
			try {
				$service->inspect_prospective( '7', 'v1.2.3' );
			} finally {
				$this->assert_all_temporary_paths_absent();
			}
		}

		public function test_prospective_acquisition_repeats_proof_and_transfers_exact_custody(): void {
			$calls                           = 0;
			$archive                         = $this->prospective_archive(
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
			$GLOBALS['ran_github_responses'] = $this->prospective_inspection_responses( 7, 'v1.2.3', $archive );
			$inspection                      = $service->inspect_prospective( '7', 'v1.2.3' );
			$persisted                       = ProspectiveReleaseInspection::rehydrate( $inspection->to_array() );
			$GLOBALS['ran_github_responses'] = $this->prospective_inspection_responses( 7, 'v1.2.3', $archive );

			$owned = $service->acquire_prospective( $persisted, expected_fingerprint: $persisted->fingerprint_value() );

			self::assertInstanceOf( ProspectiveReleaseArtifact::class, $owned );
			self::assertSame( $persisted->to_array(), $owned->inspection()->to_array() );
			self::assertSame( 2, $calls );
			self::assertCount( 12, $GLOBALS['ran_github_requests'] );
			$artifact = $owned->claim_temporary_artifact();
			try {
				$owned->claim_temporary_artifact();
				self::fail( 'A second custody claim was accepted.' );
			} catch ( \RuntimeException $failure ) {
				self::assertSame( 'The prospective GitHub release artifact is unavailable.', $failure->getMessage() );
			}
			$path = $artifact->inspect( static fn ( string $path ): string => $path );
			self::assertFileExists( $path );
			unset( $owned );
			self::assertFileExists( $path );
			self::assertTrue( $artifact->discard() );
			self::assertFileDoesNotExist( $path );
		}

		public function test_prospective_acquisition_rejects_wrong_fingerprint_before_credential_or_http(): void {
			$archive                         = $this->prospective_archive(
				"<?php\n/*\nPlugin Name: Repository\nVersion: 1.2.3\n"
				. "Update URI: https://github.com/owner/repository\n"
				. "Requires PHP: 8.2\nRequires at least: 6.8\n*/"
			);
			$service                         = $this->service( $this->binding() );
			$GLOBALS['ran_github_responses'] = $this->prospective_inspection_responses( 7, 'v1.2.3', $archive );
			$inspection                      = $service->inspect_prospective( '7', 'v1.2.3' );
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
				$service->acquire_prospective( $inspection, 'v1:' . str_repeat( '0', 64 ) );
				self::fail( 'A stale fingerprint must fail before provider work.' );
			} catch ( \InvalidArgumentException ) {
				self::addToAssertionCount( 1 );
			}
			self::assertSame( 0, $calls );
			self::assertCount( $requests, $GLOBALS['ran_github_requests'] );
			$this->assert_all_temporary_paths_absent();
		}

		public function test_prospective_acquisition_discards_a_changed_archive_proof(): void {
			$calls                           = 0;
			$header                          = "<?php\n/*\nPlugin Name: Repository\nVersion: 1.2.3\n"
			. "Update URI: https://github.com/owner/repository\n"
			. "Requires PHP: 8.2\nRequires at least: 6.8\n*/";
			$initial                         = $this->prospective_archive( $header );
			$changed                         = $this->prospective_archive( $header, array( 'repository/payload.php' => '<?php return true;' ) );
			$service                         = $this->service(
				$this->binding(),
				new GitHubCredentialResolver(
					static function () use ( &$calls ): string {
						++$calls;
						return 'private-token'; }
				)
			);
			$GLOBALS['ran_github_responses'] = $this->prospective_inspection_responses( 7, 'v1.2.3', $initial );
			$inspection                      = $service->inspect_prospective( '7', 'v1.2.3' );
			$GLOBALS['ran_github_responses'] = $this->prospective_inspection_responses( 7, 'v1.2.3', $changed );

			try {
				$service->acquire_prospective( $inspection, $inspection->fingerprint_value() );
				self::fail( 'Changed archive facts must reject reacquisition.' );
			} catch ( RuntimeException $exception ) {
				self::assertStringContainsString( 'changed before acquisition', $exception->getMessage() );
			}
			self::assertSame( 2, $calls );
			$this->assert_all_temporary_paths_absent();
		}

		public function test_prospective_acquisition_discards_changed_release_facts(): void {
			$archive                         = $this->prospective_archive(
				"<?php\n/*\nPlugin Name: Repository\nVersion: 1.2.3\n"
				. "Update URI: https://github.com/owner/repository\n"
				. "Requires PHP: 8.2\nRequires at least: 6.8\n*/"
			);
			$service                         = $this->service( $this->binding() );
			$GLOBALS['ran_github_responses'] = $this->prospective_inspection_responses( 7, 'v1.2.3', $archive );
			$inspection                      = $service->inspect_prospective( '7', 'v1.2.3' );
			$responses                       = $this->prospective_inspection_responses( 7, 'v1.2.3', $archive );
			$responses[2]                    = $this->response( 200, array( 'sha' => str_repeat( 'b', 40 ) ) );
			$GLOBALS['ran_github_responses'] = $responses;

			try {
				$service->acquire_prospective( $inspection, $inspection->fingerprint_value() );
				self::fail( 'Changed release facts must reject reacquisition.' );
			} catch ( RuntimeException $exception ) {
				self::assertStringContainsString( 'changed before acquisition', $exception->getMessage() );
			}
			$this->assert_all_temporary_paths_absent();
		}

		public function test_prospective_acquisition_discards_a_changed_hostile_archive(): void {
			$header                          = "<?php\n/*\nPlugin Name: Repository\nVersion: 1.2.3\n"
			. "Update URI: https://github.com/owner/repository\n"
			. "Requires PHP: 8.2\nRequires at least: 6.8\n*/";
			$initial                         = $this->prospective_archive( $header );
			$hostile                         = $this->prospective_archive(
				$header,
				array( 'repository/other.php' => str_replace( 'Repository', 'Other', $header ) )
			);
			$service                         = $this->service( $this->binding() );
			$GLOBALS['ran_github_responses'] = $this->prospective_inspection_responses( 7, 'v1.2.3', $initial );
			$inspection                      = $service->inspect_prospective( '7', 'v1.2.3' );
			$GLOBALS['ran_github_responses'] = $this->prospective_inspection_responses( 7, 'v1.2.3', $hostile );

			try {
				$service->acquire_prospective( $inspection, $inspection->fingerprint_value() );
				self::fail( 'A hostile changed archive must reject reacquisition.' );
			} catch ( ReleaseFailure $exception ) {
				self::assertSame( 'package_incompatible', $exception->release_code );
			}
			$this->assert_all_temporary_paths_absent();
		}

		public function test_prospective_inspection_fingerprint_rejects_changed_runtime_and_package_facts(): void {
			$archive                         = $this->prospective_archive(
				"<?php\n/*\nPlugin Name: Repository\nVersion: 1.2.3\n"
				. "Update URI: https://github.com/owner/repository\n"
				. "Requires PHP: 8.2\nRequires at least: 6.8\n*/"
			);
			$GLOBALS['ran_github_responses'] = $this->prospective_inspection_responses( 7, 'v1.2.3', $archive );
			$inspection                      = $this->service( $this->binding() )->inspect_prospective( '7', 'v1.2.3' );
			$facts                           = $inspection->to_array();
			unset( $facts['fingerprint'] );
			$changed_runtime = ProspectiveReleaseInspection::create( array_replace( $facts, array( 'php_runtime_version' => '8.3' ) ) );
			$changed_root    = ProspectiveReleaseInspection::create( array_replace( $facts, array( 'package_root' => 'renamed' ) ) );

			self::assertNotSame( $inspection->fingerprint_value(), $changed_runtime->fingerprint_value() );
			self::assertNotSame( $inspection->fingerprint_value(), $changed_root->fingerprint_value() );
			$tampered              = $inspection->to_array();
			$tampered['main_file'] = 'other.php';
			$this->expectException( \InvalidArgumentException::class );
			ProspectiveReleaseInspection::rehydrate( $tampered );
		}

		public function test_prospective_inspection_uses_exact_keys_and_defensive_accessors(): void {
			$archive                         = $this->prospective_archive(
				"<?php\n/*\nPlugin Name: Repository\nVersion: 1.2.3\n"
				. "Update URI: https://github.com/owner/repository\n"
				. "Requires PHP: 8.2\nRequires at least: 6.8\n*/"
			);
			$GLOBALS['ran_github_responses'] = $this->prospective_inspection_responses( 7, 'v1.2.3', $archive );
			$inspection                      = $this->service( $this->binding() )->inspect_prospective( '7', 'v1.2.3' );
			self::assertSame( '7', $inspection->release_identity() );
			self::assertSame( 'v1.2.3', $inspection->tag() );
			$copy                     = $inspection->to_array();
			$copy['release_identity'] = 'changed';
			self::assertSame( '7', $inspection->release_identity() );

			$facts = $inspection->to_array();
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
				$service->acquire_prospective( $opaque, $opaque->fingerprint_value() );
				self::fail( 'Provider-private numeric validation must reject opaque GitHub IDs.' );
			} catch ( \InvalidArgumentException ) {
				self::addToAssertionCount( 1 );
			}
			self::assertSame( 0, $calls );
		}

		#[\PHPUnit\Framework\Attributes\DataProvider( 'unsafe_redirect_provider' )]
		public function test_unsafe_expired_and_excess_redirects_fail_closed( string $location ): void {
			$adapter                         = new GitHubReleaseAdapter( $this->binding() );
			$GLOBALS['ran_github_responses'] = $this->inspection_responses( 7, 'v1.2.3' );
			$descriptor                      = $adapter->inspect( '7' );
			$GLOBALS['ran_github_responses'] = array(
				$this->response( 200, array( 'id' => 99 ) ),
				$this->response( 302, null, array( 'location' => $location ) ),
			);

			$this->expectException( RuntimeException::class );
			try {
				$adapter->acquire( $descriptor );
			} finally {
				$this->assert_all_temporary_paths_absent();
			}
		}

		/** @return iterable<string,array{0:string}> */
		public static function unsafe_redirect_provider(): iterable {
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

		public function test_second_redirect_and_downloaded_digest_mismatch_clean_owned_file(): void {
			$adapter                         = new GitHubReleaseAdapter( $this->binding() );
			$GLOBALS['ran_github_responses'] = $this->inspection_responses( 7, 'v1.2.3' );
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
				$this->assert_all_temporary_paths_absent();
			}

			$GLOBALS['ran_github_responses'] = array(
				$this->response( 200, array( 'id' => 99 ) ),
				$this->response( 200, null, array(), 'changed!' ),
			);
			try {
				$adapter->acquire( $descriptor );
				self::fail( 'Changed downloaded bytes must fail.' );
			} catch ( RuntimeException ) {
				$this->assert_all_temporary_paths_absent();
			}
		}

		public function test_acquisition_rate_limit_is_distinct_and_cleans_owned_file(): void {
			$adapter                         = new GitHubReleaseAdapter( $this->binding() );
			$GLOBALS['ran_github_responses'] = $this->inspection_responses( 7, 'v1.2.3' );
			$descriptor                      = $adapter->inspect( '7' );
			$GLOBALS['ran_github_responses'] = array(
				$this->response( 200, array( 'id' => 99 ) ),
				$this->response( 429, null, array( 'retry-after' => '30' ) ),
			);

			$this->expectException( ReleaseFailure::class );
			try {
				$adapter->acquire( $descriptor );
			} finally {
				$this->assert_all_temporary_paths_absent();
			}
		}

		public function test_acquisition_asset_not_found_is_generic_and_cleans_owned_file(): void {
			$adapter                         = new GitHubReleaseAdapter( $this->binding() );
			$GLOBALS['ran_github_responses'] = $this->inspection_responses( 7, 'v1.2.3' );
			$descriptor                      = $adapter->inspect( '7' );
			$GLOBALS['ran_github_responses'] = array(
				$this->response( 200, array( 'id' => 99 ) ),
				$this->response( 404, null ),
			);

			try {
				$adapter->acquire( $descriptor );
				self::fail( 'A missing release asset must fail.' );
			} catch ( ReleaseFailure $exception ) {
				self::assertSame( 'release_unavailable', $exception->release_code );
				$this->assert_all_temporary_paths_absent();
			}

			self::assertSame(
				array(
					'https://api.github.com/repos/owner/repository',
					'https://api.github.com/repos/owner/repository/releases/assets/8',
				),
				array_slice( array_column( $GLOBALS['ran_github_requests'], 0 ), 3 )
			);
		}

		public function test_oversized_stream_is_rejected_and_cleaned_before_digesting(): void {
			$adapter                         = new GitHubReleaseAdapter( $this->binding() );
			$GLOBALS['ran_github_responses'] = $this->inspection_responses( 7, 'v1.2.3' );
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
				$this->assert_all_temporary_paths_absent();
			}
		}

		public function test_custom_limit_bounds_the_download_and_rejects_an_over_custom_actual_size(): void {
			$limit                           = 83886080;
			$adapter                         = new GitHubReleaseAdapter( $this->binding( maximum_artifact_bytes: $limit ) );
			$GLOBALS['ran_github_responses'] = $this->inspection_responses( 7, 'v1.2.3' );
			$descriptor                      = $adapter->inspect( '7' );
			$oversized                       = $this->response( 200, null );
			$oversized['file_size']          = $limit + 1;
			$GLOBALS['ran_github_responses'] = array( $this->response( 200, array( 'id' => 99 ) ), $oversized );
			try {
				$adapter->acquire( $descriptor );
				self::fail( 'An over-custom downloaded size was accepted.' );
			} catch ( ReleaseFailure $exception ) {
				self::assertSame( 'package_incompatible', $exception->release_code );
				self::assertSame( $limit + 1, $GLOBALS['ran_github_requests'][4][1]['limit_response_size'] );
			} finally {
				$this->assert_all_temporary_paths_absent(); }
		}

		public function test_maximum_integer_limit_does_not_overflow_the_download_response_limit(): void {
			$service                         = $this->service( $this->binding( maximum_artifact_bytes: PHP_INT_MAX ) );
			$GLOBALS['ran_github_responses'] = array( $this->response( 200, array() ) );
			$service->list_releases();
			self::assertSame( 262145, $GLOBALS['ran_github_requests'][0][1]['limit_response_size'] );
			// The artifact request is the only request sized from the target limit; invoke its private boundary through acquisition setup below.
			$adapter                         = new GitHubReleaseAdapter( $this->binding( maximum_artifact_bytes: PHP_INT_MAX ) );
			$GLOBALS['ran_github_responses'] = $this->inspection_responses( 7, 'v1.2.3' );
			$descriptor                      = $adapter->inspect( '7' );
			$GLOBALS['ran_github_responses'] = array( $this->response( 200, array( 'id' => 99 ) ), $this->response( 500, null ) );
			try {
				// phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- The deliberately failing acquisition only sets up the subsequent request-limit observation.
				$adapter->acquire( $descriptor ); } catch ( RuntimeException ) {
				}
				self::assertSame( PHP_INT_MAX, $GLOBALS['ran_github_requests'][5][1]['limit_response_size'] );
		}

		public function test_temporary_artifact_rejects_replacement_and_never_deletes_unowned_bytes(): void {
			$adapter                         = new GitHubReleaseAdapter( $this->binding() );
			$GLOBALS['ran_github_responses'] = $this->inspection_responses( 7, 'v1.2.3' );
			$descriptor                      = $adapter->inspect( '7' );
			$GLOBALS['ran_github_responses'] = array(
				$this->response( 200, array( 'id' => 99 ) ),
				$this->response( 200, null, array(), 'zip-data' ),
				$this->response( 200, array( 'id' => 99 ) ),
			);
			$artifact                        = $adapter->acquire( $descriptor );
			$path                            = $artifact->inspect( static fn ( string $path ): string => $path );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write exact bytes for provider response and archive fixtures; WordPress helpers would alter the boundary under test.
			file_put_contents( $path, 'replacement' );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Set real fixture permission bits for archive custody and permission-boundary checks.
			chmod( $path, 0600 );

			self::assertFalse( $artifact->discard() );
			unset( $artifact );
			self::assertFileExists( $path );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read exact local bytes for provider response and archive fixtures without requiring WordPress filesystem initialization.
			self::assertSame( 'replacement', file_get_contents( $path ) );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove native fixture entries directly, preserving the surrounding ownership and link-handling checks.
			self::assertTrue( unlink( $path ) );
		}

		private function binding(
			string $channel = 'stable',
			string $target_type = 'plugin',
			string $repository_identity = '99',
			int $maximum_artifact_bytes = 52428800
		): BindingRecord {
			return BindingRecord::create(
				array(
					'canonical_repository_locator' => 'owner/repository',
					'canonical_update_uri'         => 'https://github.com/owner/repository',
					'installed_package_identity'   => 'plugin' === $target_type
						? 'repository/repository.php'
						: 'repository',
					'maximum_artifact_bytes'       => $maximum_artifact_bytes,
					'network_id'                   => 1,
					'php_runtime_version'          => '8.2.0',
					'provider_code'                => 'github',
					'release_channel'              => $channel,
					'stable_repository_identity'   => $repository_identity,
					'target_type'                  => $target_type,
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
			return new GitHubReleaseService( $this->service_configuration( $binding ), $credentials, liveness_guard: null );
		}

		private function public_service(): GitHubReleaseService {
			return new GitHubReleaseService(
				$this->service_configuration( $this->binding() ),
				null,
				static fn (): ?string => null
			);
		}

		/** @return list<array<string,mixed>> */
		private function invalid_stream_responses(): array {
			return array(
				$this->response( 200, array( 'id' => 99 ) ),
				$this->response( 200, $this->release( 7, 'v1.2.3' ) ),
				$this->response( 200, array( 'sha' => str_repeat( 'a', 40 ) ) ),
				$this->response( 200, array( 'id' => 99 ) ),
				$this->response( 200, null, array(), 'invalid bytes' ),
			);
		}

		/** @return array<string,mixed> */
		private function service_configuration( BindingRecord $binding ): array {
			$facts = $binding->to_array();
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
		private function inspection_responses(
			int $release_identity,
			string $tag,
			bool $prerelease = false,
			bool $immutable = true
		): array {
			return array(
				$this->response( 200, array( 'id' => 99 ) ),
				$this->response(
					200,
					$this->release(
						$release_identity,
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
		private function prospective_inspection_responses(
			int $release_identity,
			string $tag,
			string $archive
		): array {
			$release                        = $this->release( $release_identity, $tag );
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

		/** @param array<string,string> $additional_entries */
		private function prospective_archive( string $header, array $additional_entries = array() ): string {
			$path = tempnam( sys_get_temp_dir(), 'ran-github-prospective-' );
			self::assertIsString( $path );
			$zip = new \ZipArchive();
			self::assertTrue( $zip->open( $path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) );
			self::assertTrue( $zip->addFromString( 'repository/repository.php', $header ) );
			foreach ( $additional_entries as $name => $contents ) {
				self::assertTrue( $zip->addFromString( $name, $contents ) );
			}
			$zip->close();
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read exact local bytes for provider response and archive fixtures without requiring WordPress filesystem initialization.
			$archive = file_get_contents( $path );
			self::assertIsString( $archive );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove native fixture entries directly, preserving the surrounding ownership and link-handling checks.
			self::assertTrue( unlink( $path ) );
			return $archive;
		}

		private function prospective_theme_archive( string $header ): string {
			$path = tempnam( sys_get_temp_dir(), 'ran-github-prospective-' );
			self::assertIsString( $path );
			$zip = new \ZipArchive();
			self::assertTrue( $zip->open( $path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) );
			self::assertTrue( $zip->addFromString( 'repository/style.css', $header ) );
			$zip->close();
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read exact local bytes for provider response and archive fixtures without requiring WordPress filesystem initialization.
			$archive = file_get_contents( $path );
			self::assertIsString( $archive );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove native fixture entries directly, preserving the surrounding ownership and link-handling checks.
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

		private function assert_all_temporary_paths_absent(): void {
			self::assertNotEmpty( $GLOBALS['ran_github_temp_paths'] );
			foreach ( $GLOBALS['ran_github_temp_paths'] as $path ) {
				self::assertFileDoesNotExist( $path );
				self::assertFalse( is_link( $path ) );
			}
		}
	}
}

// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
