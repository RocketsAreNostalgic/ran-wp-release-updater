<?php

declare(strict_types=1);

// phpcs:ignore Universal.Namespaces.DisallowCurlyBraceSyntax.Forbidden, Universal.Namespaces.DisallowDeclarationWithoutName.Forbidden -- Keep global WordPress stubs and namespaced test code in the same isolated fixture. WordPress stubs must be declared in the global namespace used by production calls.
namespace {
	if ( ! class_exists( 'WP_Error' ) ) {
		// phpcs:ignore Generic.Classes.DuplicateClassName.Found, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Conditional WP_Error stubs model the same foreign class without redeclaring it when already loaded. This stub must occupy the WordPress global class identity used by production calls.
		final class WP_Error {
			public function __construct( public string $code, public string $message ) {}
		}
	}
	if ( ! function_exists( 'add_filter' ) ) {
		// phpcs:ignore Universal.Files.SeparateFunctionsFromOO.Mixed, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- The self-contained fixture combines foreign functions/classes with the test harness that exercises them. WordPress calls this global stub by its exact foreign function name.
		function add_filter( string $hook, mixed $callback, int $priority, int $arguments ): void {
			$GLOBALS['ran_wp_release_updater_test_hooks'][] = array( 'filter', $hook, $callback, $priority, $arguments ); }
	}
	if ( ! function_exists( 'add_action' ) ) {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress calls this global stub by its exact foreign function name.
		function add_action( string $hook, mixed $callback, int $priority, int $arguments ): void {
			$GLOBALS['ran_wp_release_updater_test_hooks'][] = array( 'action', $hook, $callback, $priority, $arguments ); }
	}
	if ( ! function_exists( 'get_filesystem_method' ) ) {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress calls this global stub by its exact foreign function name.
		function get_filesystem_method(): string {
			return $GLOBALS['ran_wp_release_updater_test_filesystem_method'] ?? 'direct'; }
	}
}

// phpcs:ignore Universal.Namespaces.OneDeclarationPerFile.MultipleFound, Universal.Namespaces.DisallowCurlyBraceSyntax.Forbidden -- This fixture colocates foreign global stubs and namespaced test or injected provider seams. Keep global WordPress stubs and namespaced test code in the same isolated fixture.
namespace RAN\WPReleaseUpdater\V1\Tests\WordPress {

	require_once dirname( __DIR__ ) . '/Support/FakeOptionDatabase.php';

	use PHPUnit\Framework\TestCase;
	use RAN\WPReleaseUpdater\V1\Archive\PackageIdentityValidator;
	use RAN\WPReleaseUpdater\V1\Contract\AcquisitionReceipt;
	use RAN\WPReleaseUpdater\V1\Contract\BindingRecord;
	use RAN\WPReleaseUpdater\V1\Contract\IdentityDescriptor;
	use RAN\WPReleaseUpdater\V1\WordPress\BindingState;
	use RAN\WPReleaseUpdater\V1\WordPress\NativePackageUpdater;
	use RAN\WPReleaseUpdater\V1\WordPress\BindingFenceCoordinator;
	use RAN\WPReleaseUpdater\V1\Tests\Support\FakeOptionDatabase;

	/** Exercises updater seams only; Core backup, rollback, and activation remain out of scope. */
	// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- Keep the conditional WordPress stub and its test class in the same self-contained fixture.
	final class FakeAdapterLifecycleTest extends TestCase {

		/** @var list<string> */
		private array $paths = array();

		// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this inherited lifecycle method name.
		protected function setUp(): void {
			$GLOBALS['ran_wp_release_updater_test_hooks'] = array();
		}

		// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this inherited lifecycle method name.
		protected function tearDown(): void {
			foreach ( array_reverse( $this->paths ) as $path ) {
				$this->remove( $path );
			}
			parent::tearDown();
		}

		/** @return array<string,array{string,string,string,string,bool}> */
		public static function lifecycle_cases(): array {
			return array(
				'stable plugin'     => array( 'plugin', 'stable', '2.0.0', 'v2.0.0', false ),
				'prerelease plugin' => array( 'plugin', 'prerelease', '2.0.0-beta.1', 'v2.0.0-beta.1', true ),
				'stable theme'      => array( 'theme', 'stable', '2.0.0', 'v2.0.0', false ),
				'prerelease theme'  => array( 'theme', 'prerelease', '2.0.0-beta.1', 'v2.0.0-beta.1', true ),
			);
		}

		#[\PHPUnit\Framework\Attributes\DataProvider( 'lifecycle_cases' )]
		public function test_discovery_reaches_verified_lifecycle_completion_across_the_four_boundaries( string $target_type, string $channel, string $version, string $tag, bool $prerelease ): void {
			$this->assert_completed_lifecycle( $target_type, $channel, $version, $tag, $prerelease );
		}

		public function test_staged_headers_share_canonical_normalization_for_plugin_and_theme(): void {
			foreach ( array( "\r\n", "\r" ) as $line_ending ) {
				foreach ( array( 'plugin', 'theme' ) as $target_type ) {
					$this->assert_completed_lifecycle(
						$target_type,
						'stable',
						'2.0.0',
						'v2.0.0',
						false,
						$line_ending,
						true
					);
				}
			}
		}

		public function test_staged_headers_permit_absent_requirements_and_reject_post_closing_duplicates(): void {
			foreach ( array( 'plugin', 'theme' ) as $target_type ) {
				$uri        = 'https://updates.example.test/owner/fake-release';
				$archive    = $this->archive( $target_type, $uri, '2.0.0' );
				$descriptor = $this->descriptor( $target_type, $archive, $uri, 'stable', '2.0.0', 'v2.0.0', false );
				$updater    = $this->updater(
					$this->configuration( $target_type, $uri ),
					$this->binding( $target_type, $uri, 'stable' ),
					new FakeOptionDatabase( 100 ),
					$descriptor,
					$archive,
					$this->policy( $target_type, $uri )
				);
				self::assertInstanceOf( NativePackageUpdater::class, $updater );
				$matches = new \ReflectionMethod( NativePackageUpdater::class, 'matches_staged_metadata' );
				$staged  = $this->tree( $target_type, $uri, 'optional', '2.0.0' );
				self::assertTrue( $matches->invoke( $updater, $staged, '2.0.0' ) );

				$file = $staged . '/' . ( 'plugin' === $target_type ? 'fake-release.php' : 'style.css' );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write exact bytes for installed-package lifecycle fixtures; WordPress helpers would alter the boundary under test.
				file_put_contents( $file, "\nVersion: 2.0.0", FILE_APPEND );
				self::assertFalse( $matches->invoke( $updater, $staged, '2.0.0' ) );
			}
		}

		public function test_theme_staging_rejects_a_child_template_added_after_archive_validation(): void {
			$uri        = 'https://updates.example.test/owner/fake-release';
			$archive    = $this->archive( 'theme', $uri, '2.0.0' );
			$descriptor = $this->descriptor( 'theme', $archive, $uri, 'stable', '2.0.0', 'v2.0.0', false );
			$updater    = $this->updater( $this->configuration( 'theme', $uri ), $this->binding( 'theme', $uri, 'stable' ), new FakeOptionDatabase( 100 ), $descriptor, $archive, $this->policy( 'theme', $uri ) );
			self::assertInstanceOf( NativePackageUpdater::class, $updater );
			$staged = $this->tree( 'theme', $uri, 'template-added', '2.0.0' );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write exact bytes for installed-package lifecycle fixtures; WordPress helpers would alter the boundary under test.
			file_put_contents( $staged . '/style.css', "\nTemplate: parent-theme", FILE_APPEND );
			$matches = new \ReflectionMethod( NativePackageUpdater::class, 'matches_staged_metadata' );
			self::assertFalse( $matches->invoke( $updater, $staged, '2.0.0' ) );
		}

		private function assert_completed_lifecycle(
			string $target_type,
			string $channel,
			string $version,
			string $tag,
			bool $prerelease,
			string $line_ending = "\n",
			bool $closing_comment_markers = false
		): void {
			$uri        = 'https://updates.example.test/owner/fake-release';
			$archive    = $this->archive(
				$target_type,
				$uri,
				$version,
				$line_ending,
				$closing_comment_markers
			);
			$descriptor = $this->descriptor( $target_type, $archive, $uri, $channel, $version, $tag, $prerelease );
			$validator  = new PackageIdentityValidator();
			$policy     = $this->policy( $target_type, $uri );

			$redirect_candidate                     = $policy;
			$redirect_candidate['offer_update_uri'] = 'https://updates.example.test/owner/fake-release-redirect';
			self::assertSame( 'archive_target_policy_invalid', $validator->validate( $descriptor, $redirect_candidate, $archive )->code() );

			$package = $validator->validate( $descriptor, $policy, $archive );
			self::assertTrue( $package->is_valid() );

			$binding      = $this->binding( $target_type, $uri, $channel );
			$database     = new FakeOptionDatabase( 100 );
			$claim_result = BindingFenceCoordinator::claim_persistent_binding_state( $database, $binding, str_repeat( 'a', 64 ), 20 );
			self::assertSame( 'claimed', $claim_result['result'] );
			$state              = $claim_result['current'];
			$claim              = $this->claim( $state );
			$destination_parent = sys_get_temp_dir() . '/ran-fake-adapter-destination-' . bin2hex( random_bytes( 8 ) );

			$configuration_mismatch               = $this->configuration( $target_type, $uri );
			$configuration_mismatch['update_uri'] = 'https://updates.example.test/owner/other-path';
			$configuration_package                = $validator->validate( $descriptor, $policy, $archive );
			self::assertNull( $this->updater( $configuration_mismatch, $binding, $database, $descriptor, $archive, $policy ) );
			self::assertDirectoryDoesNotExist( $destination_parent );

			$this->assert_staged_header_mismatch_does_not_create_destination( $target_type, $uri, $version, $descriptor, $binding, $database, $state, $claim, $validator, $policy, $archive, $destination_parent );
			$GLOBALS['ran_wp_release_updater_test_hooks'] = array();

			$database->set_time( 121 );
			$updater = $this->updater( $this->configuration( $target_type, $uri ), $binding, $database, $descriptor, $archive, $policy );
			self::assertInstanceOf( NativePackageUpdater::class, $updater );
			$updater->register();
			$updater->register();
			self::assertSame( $this->expected_target_hooks( $target_type ), array_column( $GLOBALS['ran_wp_release_updater_test_hooks'], 1 ) );

			$identity = 'plugin' === $target_type ? 'fake-release/fake-release.php' : 'fake-release';
			$offer    = $updater->filter_update(
				false,
				array(
					'Version'   => '1.0.0',
					'UpdateURI' => $uri,
				),
				$identity,
				array()
			);
			self::assertIsArray( $offer );
			self::assertNotSame( '', $offer['package'] );
			self::assertFalse( $offer['autoupdate'] );
			self::assertSame(
				false,
				$updater->filter_update(
					false,
					array(
						'Version'   => '1.0.0',
						'UpdateURI' => 'https://updates.example.test/owner/other-path',
					),
					$identity,
					array()
				)
			);

			$extra = array( 'plugin' === $target_type ? 'plugin' : 'theme' => $identity );
			$owned = $updater->filter_pre_download( false, $offer['package'], null, $extra );
			self::assertIsString( $owned );
			$shutdown = array_values( array_filter( $GLOBALS['ran_wp_release_updater_test_hooks'], static fn ( array $hook ): bool => 'shutdown' === $hook[1] ) );
			self::assertCount( 1, $shutdown );
			self::assertSame( array( 'action', 'shutdown', array( $updater, 'finalize_pending_install' ), PHP_INT_MAX, 0 ), $shutdown[0] );
			self::assertNull( $updater->filter_pre_unzip_file( null, $owned, sys_get_temp_dir(), array(), 0.0 ) );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Simulate Core deleting the acquired archive after successful extraction.
			unlink( $owned ); // Core deletes the archive after successful extraction.
			self::assertTrue( $updater->filter_pre_install( true, $extra ) );

			$staged = $this->tree(
				$target_type,
				$uri,
				'staged',
				$version,
				$line_ending,
				$closing_comment_markers
			);
			self::assertSame( $staged, $updater->filter_source_selection( $staged, sys_get_temp_dir(), null, $extra ) );
			$destination = $this->tree(
				$target_type,
				$uri,
				'destination',
				$version,
				$line_ending,
				$closing_comment_markers
			);
			self::assertSame( array( 'destination' => $destination ), $updater->capture_install_package_result( array( 'destination' => $destination ), $extra ) );
			$updater->observe_completion(
				null,
				array(
					'action' => 'update',
					'type'   => $target_type,
					'plugin' === $target_type ? 'plugins' : 'themes' => array( $identity ),
				)
			);
			$updater->finalize_pending_install();

			$diagnostics = $updater->diagnostics();
			self::assertSame( 'update_completed', end( $diagnostics ) );
		}

		private function assert_staged_header_mismatch_does_not_create_destination( string $target_type, string $uri, string $version, IdentityDescriptor $descriptor, BindingRecord $binding, FakeOptionDatabase $database, BindingState $state, array $claim, PackageIdentityValidator $validator, array $policy, string $archive, string $destination_parent ): void {
			$database->set_time( 121 );
			$updater = $this->updater( $this->configuration( $target_type, $uri ), $binding, $database, $descriptor, $archive, $policy );
			self::assertInstanceOf( NativePackageUpdater::class, $updater );
			$identity = 'plugin' === $target_type ? 'fake-release/fake-release.php' : 'fake-release';
			$extra    = array( 'plugin' === $target_type ? 'plugin' : 'theme' => $identity );
			$offer    = $updater->filter_update(
				false,
				array(
					'Version'   => '1.0.0',
					'UpdateURI' => $uri,
				),
				$identity,
				array()
			);
			$owned    = $updater->filter_pre_download( false, $offer['package'], null, $extra );
			self::assertIsString( $owned );
			self::assertNull( $updater->filter_pre_unzip_file( null, $owned, sys_get_temp_dir(), array(), 0.0 ) );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove native fixture entries directly, preserving the surrounding ownership and link-handling checks.
			unlink( $owned );
			self::assertTrue( $updater->filter_pre_install( true, $extra ) );
			$staged = $this->tree( $target_type, 'https://updates.example.test/owner/other-path', 'staged-header-mismatch', $version );
			self::assertInstanceOf( \WP_Error::class, $updater->filter_source_selection( $staged, sys_get_temp_dir(), null, $extra ) );
			self::assertDirectoryDoesNotExist( $destination_parent );
		}

		private function updater( array $configuration, BindingRecord $binding, FakeOptionDatabase $database, IdentityDescriptor $descriptor, string $archive, array $policy ): ?NativePackageUpdater {
			$adapter = new class( $descriptor, $archive ) implements \RAN\WPReleaseUpdater\V1\Contract\ReleaseAdapter { public function __construct( private IdentityDescriptor $descriptor, private string $archive ) {} public function list_releases( array $conditional = array() ): array {
					$facts = $this->descriptor->to_array();
					return array(
						'candidates' => array(
							array(
								'release_identity' => $facts['release_identity'],
								'tag'              => $facts['tag'],
								'version'          => $facts['version'],
							),
						),
					);
			} public function inspect( string $release_identity, ?string $expected_tag = null ): IdentityDescriptor {
				return $this->descriptor;
			} public function acquire( IdentityDescriptor $descriptor ): \RAN\WPReleaseUpdater\V1\Archive\TemporaryArtifact {
				$path = tempnam( sys_get_temp_dir(), 'ran-fake-adapter-' );
				copy( $this->archive, $path );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Set real fixture permission bits for archive custody and permission-boundary checks.
				chmod( $path, 0600 );
				$stat = lstat( $path );
				return new \RAN\WPReleaseUpdater\V1\Archive\TemporaryArtifact(
					$path,
					hash_file( 'sha256', $path ),
					array(
						'dev'   => $stat['dev'],
						'ino'   => $stat['ino'],
						'mode'  => $stat['mode'],
						'nlink' => $stat['nlink'],
						'uid'   => $stat['uid'],
						'gid'   => $stat['gid'],
						'size'  => $stat['size'],
						'mtime' => $stat['mtime'],
						'ctime' => $stat['ctime'],
					)
				);
			} };
			return NativePackageUpdater::from_configuration( $configuration, $binding, $adapter, $database, $policy ); }

		/** @return list<string> */
		private function expected_target_hooks( string $target_type ): array {
			$hooks = array( 'plugin' === $target_type ? 'update_plugins_updates.example.test' : 'update_themes_updates.example.test' );
			if ( 'plugin' === $target_type ) {
				$hooks[] = 'plugins_api';
			}
			return array_merge( $hooks, array( 'plugin' === $target_type ? 'auto_update_plugin' : 'auto_update_theme', 'upgrader_package_options', 'upgrader_pre_download', 'upgrader_pre_install', 'pre_unzip_file', 'upgrader_source_selection', 'upgrader_install_package_result', 'upgrader_process_complete' ) );
		}

		private function archive(
			string $target_type,
			string $uri,
			string $version,
			string $line_ending = "\n",
			bool $closing_comment_markers = false
		): string {
			$path = tempnam( sys_get_temp_dir(), 'ran-phase24-' );
			self::assertIsString( $path );
			$this->paths[] = $path;
			$zip           = new \ZipArchive();
			self::assertTrue( $zip->open( $path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) );
			self::assertTrue(
				$zip->addFromString(
					'fake-release/' . ( 'plugin' === $target_type ? 'fake-release.php' : 'style.css' ),
					$this->header(
						$target_type,
						$uri,
						$version,
						$line_ending,
						$closing_comment_markers
					)
				)
			);
			self::assertTrue( $zip->addFromString( 'fake-release/payload.php', '<?php return true;' ) );
			$zip->close();
			return $path;
		}

		private function tree(
			string $target_type,
			string $uri,
			string $suffix,
			string $version,
			string $line_ending = "\n",
			bool $closing_comment_markers = false
		): string {
			$parent = sys_get_temp_dir() . '/ran-phase24-' . $suffix . '-' . bin2hex( random_bytes( 8 ) );
			$root   = $parent . '/fake-release';
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create real directories for installed-package lifecycle fixtures with the specified permissions.
			self::assertTrue( mkdir( $root, 0700, true ) );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write exact bytes for installed-package lifecycle fixtures; WordPress helpers would alter the boundary under test.
			file_put_contents(
				$root . '/' . ( 'plugin' === $target_type ? 'fake-release.php' : 'style.css' ),
				$this->header(
					$target_type,
					$uri,
					$version,
					$line_ending,
					$closing_comment_markers
				)
			);
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write exact bytes for installed-package lifecycle fixtures; WordPress helpers would alter the boundary under test.
			file_put_contents( $root . '/payload.php', '<?php return true;' );
			$this->paths[] = $parent;
			return $root;
		}

		private function header(
			string $target_type,
			string $uri,
			string $version,
			string $line_ending = "\n",
			bool $closing_comment_markers = false
		): string {
			$name   = 'plugin' === $target_type ? 'Plugin Name: Fake Release' : 'Theme Name: Fake Release';
			$suffix = $closing_comment_markers ? ' */' : '';
			return str_replace( "\n", $line_ending, "<?php\n/*\n{$name}{$suffix}\nVersion: {$version}{$suffix}\nUpdate URI: {$uri}{$suffix}\n*/" );
		}

		/** @return array<string,string> */
		private function policy( string $target_type, string $uri ): array {
			$header = 'plugin' === $target_type ? 'fake-release.php' : 'style.css';
			return array(
				'archive_root'               => 'fake-release',
				'configuration_update_uri'   => $uri,
				'header_file'                => $header,
				'installed_package_identity' => 'plugin' === $target_type ? 'fake-release/fake-release.php' : 'fake-release',
				'maximum_artifact_bytes'     => 52428800,
				'metadata_name'              => 'Fake Release',
				'offer_update_uri'           => $uri,
				'php_runtime_version'        => '8.2',
				'provider_code'              => 'fake',
				'repository_identity'        => 'fake:repository',
				'repository_locator'         => 'owner/fake-release',
				'staged_package_update_uri'  => $uri,
				'target_type'                => $target_type,
				'theme_template'             => '',
				'wordpress_runtime_version'  => '6.8',
			);
		}

		/** @return array<string,mixed> */
		private function configuration( string $target_type, string $uri ): array {
			return array(
				'headers'                    => array(
					'Author'      => 'Test',
					'Description' => 'Local fake adapter proof',
					'Name'        => 'Fake Release',
					'PluginURI'   => $uri,
					'RequiresPHP' => '8.2',
					'RequiresWP'  => '6.8',
					'UpdateURI'   => $uri,
					'Version'     => '1.0.0',
				),
				'installed_package_identity' => 'plugin' === $target_type ? 'fake-release/fake-release.php' : 'fake-release',
				'policy'                     => 'manual',
				'target_type'                => $target_type,
				'update_uri'                 => $uri,
			);
		}

		private function binding( string $target_type, string $uri, string $channel ): BindingRecord {
			return BindingRecord::create(
				array(
					'canonical_repository_locator' => 'owner/fake-release',
					'canonical_update_uri'         => $uri,
					'installed_package_identity'   => 'plugin' === $target_type ? 'fake-release/fake-release.php' : 'fake-release',
					'maximum_artifact_bytes'       => 52428800,
					'network_id'                   => 1,
					'php_runtime_version'          => '8.2',
					'provider_code'                => 'fake',
					'release_channel'              => $channel,
					'stable_repository_identity'   => 'fake:repository',
					'target_type'                  => $target_type,
					'theme_template'               => '',
					'update_policy'                => 'manual',
					'wordpress_runtime_version'    => '6.8',
				)
			);
		}

		private function descriptor( string $target_type, string $archive, string $uri, string $channel, string $version, string $tag, bool $prerelease ): IdentityDescriptor {
			return IdentityDescriptor::create(
				array(
					'artifact_filename'          => 'fake-release.zip',
					'artifact_identity'          => 'fake-artifact:2',
					'artifact_sha256'            => hash_file( 'sha256', $archive ),
					'artifact_size'              => filesize( $archive ),
					'assurance_facts'            => array(
						'exact_artifact_identity'       => true,
						'exact_commit_identity'         => true,
						'exact_reacquisition_supported' => true,
						'exact_release_identity'        => true,
						'provenance_verified'           => true,
						'publication_immutable'         => true,
						'repository_identity_stable'    => true,
						'trusted_digest_source'         => true,
					),
					'canonical_update_uri'       => $uri,
					'channel'                    => $channel,
					'commit_identity'            => 'fake-commit:2',
					'installed_package_identity' => 'plugin' === $target_type ? 'fake-release/fake-release.php' : 'fake-release',
					'prerelease'                 => $prerelease,
					'provider_code'              => 'fake',
					'release_identity'           => 'fake-release:2',
					'repository_identity'        => 'fake:repository',
					'repository_locator'         => 'owner/fake-release',
					'tag'                        => $tag,
					'target_type'                => $target_type,
					'version'                    => $version,
				)
			);
		}

		/** @return array<string,mixed> */
		private function claim( BindingState $state ): array {
			return array(
				'binding_generation' => $state->binding_generation(),
				'binding_hash'       => $state->binding()->binding_hash(),
				'lease_deadline'     => $state->lease_deadline(),
				'owner_token'        => $state->owner_token(),
			);
		}

		private function remove( string $path ): void {
			if ( is_link( $path ) || is_file( $path ) ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- Best-effort fixture teardown tolerates paths already removed by the scenario. Remove native fixture entries directly, preserving the surrounding ownership and link-handling checks.
				@unlink( $path );
				return;
			}
			if ( ! is_dir( $path ) ) {
				return;
			}
			$fixture_entries = scandir( $path );
			foreach ( $fixture_entries ? $fixture_entries : array() as $entry ) {
				if ( '.' !== $entry && '..' !== $entry ) {
					$this->remove( $path . DIRECTORY_SEPARATOR . $entry );
				}
			}
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Best-effort fixture teardown tolerates paths already removed by the scenario. Remove native fixture entries directly, preserving the surrounding ownership and link-handling checks.
			@rmdir( $path );
		}
	}
}
