<?php
declare(strict_types=1);
// phpcs:ignore Universal.Namespaces.DisallowCurlyBraceSyntax.Forbidden, Universal.Namespaces.DisallowDeclarationWithoutName.Forbidden -- Keep global WordPress stubs and namespaced test code in the same isolated fixture. WordPress stubs must be declared in the global namespace used by production calls.
namespace {
	if ( ! class_exists( 'WP_Error' ) ) {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound, Generic.Classes.DuplicateClassName.Found -- This stub must occupy the WordPress global class identity used by production calls. The same foreign stub name is reused behind conditional or isolated-process load boundaries.
		final class WP_Error {
			public function __construct( public string $code, public string $message ) {}
		}
	}
	if ( ! class_exists( 'Theme_Upgrader_Skin' ) ) {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound, Generic.Classes.DuplicateClassName.Found, Generic.Files.OneObjectStructurePerFile.MultipleFound -- This stub occupies the exact Core skin identity required by the bounded compatibility bridge.
		class Theme_Upgrader_Skin {
			public mixed $result = null;
		}
	}
	if ( ! class_exists( 'Theme_Upgrader' ) ) {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound, Generic.Classes.DuplicateClassName.Found, Generic.Files.OneObjectStructurePerFile.MultipleFound -- This stub occupies the exact Core upgrader identity required by the bounded compatibility bridge.
		class Theme_Upgrader {
			public mixed $result = null;
			public Theme_Upgrader_Skin $skin;
			public function __construct() {
				$this->skin = new Theme_Upgrader_Skin();
			}
		}
	}
	if ( ! function_exists( 'add_filter' ) ) {
		// phpcs:ignore Universal.Files.SeparateFunctionsFromOO.Mixed, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- The self-contained fixture combines foreign functions/classes with the test harness that exercises them. WordPress calls this global stub by its exact foreign function name.
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
	if ( ! function_exists( 'get_current_blog_id' ) ) {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Exercise the native WordPress site identity seam.
		function get_current_blog_id(): int {
			return 1;
		}
	}
	if ( ! function_exists( 'do_action' ) ) {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Exercise the native WordPress observation seam.
		function do_action( string $hook, mixed ...$arguments ): void {
			$GLOBALS['ran_wp_release_updater_test_observations'][] = array( $hook, $arguments[0] );
			if ( true === ( $GLOBALS['ran_wp_release_updater_test_observer_throws'] ?? false ) ) {
				throw new \RuntimeException( 'Observer failed.' );
			}
		}
	}
	if ( ! function_exists( 'doing_action' ) ) {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Exercise the exact Core automatic-update action gate.
		function doing_action( string $hook ): bool {
			return ( $GLOBALS['ran_wp_release_updater_test_doing_action'] ?? null ) === $hook;
		}
	}
	if ( ! function_exists( 'get_filesystem_method' ) ) {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress calls this global stub by its exact foreign function name.
		function get_filesystem_method(): string {
			return 'direct';
		}
	}
}

// phpcs:ignore Universal.Namespaces.OneDeclarationPerFile.MultipleFound, Universal.Namespaces.DisallowCurlyBraceSyntax.Forbidden -- This fixture colocates foreign global stubs and namespaced test or injected provider seams. Keep global WordPress stubs and namespaced test code in the same isolated fixture.
namespace RAN\WPReleaseUpdater\V1\Tests\WordPress {
	require_once dirname( __DIR__ ) . '/Support/FakeOptionDatabase.php';
	require_once dirname( __DIR__ ) . '/Support/ControllableReleaseAdapter.php';
	use PHPUnit\Framework\TestCase;
	use RAN\WPReleaseUpdater\V1\Archive\PackageIdentityValidator;
	use RAN\WPReleaseUpdater\V1\Contract\BindingRecord;
	use RAN\WPReleaseUpdater\V1\Contract\IdentityDescriptor;
	use RAN\WPReleaseUpdater\V1\Runtime\RequestBroker;
	use RAN\WPReleaseUpdater\V1\Runtime\ReleaseFailure;
	use RAN\WPReleaseUpdater\V1\Runtime\SelectedRuntimeState;
	use RAN\WPReleaseUpdater\V1\WordPress\BindingState;
	use RAN\WPReleaseUpdater\V1\WordPress\NativePackageUpdater;
	use RAN\WPReleaseUpdater\V1\WordPress\BindingFenceCoordinator;
	use RAN\WPReleaseUpdater\V1\Tests\Support\ControllableReleaseAdapter;
	use RAN\WPReleaseUpdater\V1\Tests\Support\FakeOptionDatabase;
	// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- Keep the conditional WordPress stub and its test class in the same self-contained fixture.
	final class NativePackageUpdaterTest extends TestCase {
		/** @var list<string> */
		private array $paths = array();
		private string $temporary_directory;
		// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this inherited lifecycle method name.
		protected function setUp(): void {
			$this->temporary_directory = dirname( __DIR__, 2 ) . '/.workspaces/p0.2/php-tmp/native-package-updater-' . bin2hex( random_bytes( 6 ) );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create real directories for installed-package lifecycle fixtures with the specified permissions.
			mkdir( $this->temporary_directory, 0700, true );
			$GLOBALS['ran_wp_release_updater_test_observations']     = array();
			$GLOBALS['ran_wp_release_updater_test_observer_throws']  = false;
			$GLOBALS['ran_wp_release_updater_test_hooks']            = array();
			$GLOBALS['ran_wp_release_updater_test_filter_callbacks'] = array();
			$GLOBALS['ran_wp_release_updater_test_doing_action']     = null;
		}
		// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this inherited lifecycle method name.
		protected function tearDown(): void {
			foreach ( $this->paths as $path ) {
				if ( is_file( $path ) ) {
					// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- Best-effort fixture teardown tolerates paths already removed by the scenario. Remove native fixture entries directly, preserving the surrounding ownership and link-handling checks.
					@unlink( $path );
				}
				if ( is_dir( $path ) ) {
					$fixture_entries = glob( $path . '/*' );
					foreach ( $fixture_entries ? $fixture_entries : array() as $child_path ) {
						// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- Best-effort fixture teardown tolerates paths already removed by the scenario. Remove native fixture entries directly, preserving the surrounding ownership and link-handling checks.
						@unlink( $child_path );
					}
					// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Best-effort fixture teardown tolerates paths already removed by the scenario. Remove native fixture entries directly, preserving the surrounding ownership and link-handling checks.
					@rmdir( $path );
				}
			}
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Best-effort fixture teardown tolerates paths already removed by the scenario. Remove native fixture entries directly, preserving the surrounding ownership and link-handling checks.
			@rmdir( $this->temporary_directory );
		}
		public function test_observation_captures_actual_check(): void {
			list( $updater, $adapter ) = $this->subject();
			$this->offer( $updater );
			$events = $GLOBALS['ran_wp_release_updater_test_observations'];
			self::assertCount( 2, $events );
			self::assertSame( 'ran_wp_release_updater_check_started_v1', $events[0][0] );
			self::assertSame( 'ran_wp_release_updater_check_completed_v1', $events[1][0] );
			self::assertMatchesRegularExpression( '/^[a-f0-9]{32}$/', $events[0][1]['check_id'] );
			self::assertSame( $events[0][1]['check_id'], $events[1][1]['check_id'] );
			self::assertSame( 'succeeded', $events[1][1]['status'] );
			self::assertSame( 'update_available', $events[1][1]['reason'] );
			self::assertSame( 1, $adapter->list_calls );
			$GLOBALS['ran_wp_release_updater_test_observations'] = array();
			$updater->refresh();
			$updater->filter_update( false, array(), 'unrelated/unrelated.php', array() );
			self::assertSame( array(), $GLOBALS['ran_wp_release_updater_test_observations'] );
		}

		public function test_throwing_start_listener_aborts_before_provider_work(): void {
			list( $updater, $adapter )                              = $this->subject();
			$GLOBALS['ran_wp_release_updater_test_observer_throws'] = true;
			try {
				$this->offer( $updater );
				self::fail( 'Listener exception must escape the native check.' );
			} catch ( \RuntimeException $failure ) {
				self::assertSame( 'Observer failed.', $failure->getMessage() );
			}
			self::assertSame( 0, $adapter->list_calls );
			self::assertCount( 1, $GLOBALS['ran_wp_release_updater_test_observations'] );
		}

		public function test_unexpected_failure_after_start_does_not_dispatch_completion(): void {
			list( $updater, $adapter, $database ) = $this->subject( native_discovery_reuse: true );
			$failure                              = new \RuntimeException( 'Unexpected binding read failure.' );
			$database->mutate_on_time_read(
				1,
				static function () use ( $failure ): void {
					throw $failure;
				}
			);
			try {
				$this->offer( $updater );
				self::fail( 'Unexpected failure must escape the native check.' );
			} catch ( \RuntimeException $caught ) {
				self::assertSame( $failure, $caught );
			}
			self::assertSame( array( 1, 1, 1 ), array( $adapter->list_calls, $adapter->inspect_calls, $adapter->acquire_calls ) );
			$events = $GLOBALS['ran_wp_release_updater_test_observations'];
			self::assertCount( 1, $events );
			self::assertSame( 'ran_wp_release_updater_check_started_v1', $events[0][0] );
		}

		public function test_reused_discovery_emits_no_fresh_check(): void {
			list( $updater, $adapter ) = $this->subject( native_discovery_reuse: true );
			$this->offer( $updater );
			self::assertCount( 2, $GLOBALS['ran_wp_release_updater_test_observations'] );
			$GLOBALS['ran_wp_release_updater_test_observations'] = array();
			$this->offer( $updater );
			self::assertSame( array(), $GLOBALS['ran_wp_release_updater_test_observations'] );
			self::assertSame( 1, $adapter->list_calls );
			$updater->refresh();
			$this->offer( $updater );
			self::assertCount( 2, $GLOBALS['ran_wp_release_updater_test_observations'] );
			self::assertSame( 2, $adapter->list_calls );
		}

		public function test_descriptor_admission_rejects_fresh_and_reused_offers_without_acquisition(): void {
			$admit                     = true;
			list( $updater, $adapter ) = $this->subject(
				native_discovery_reuse: true,
				descriptor_admission: static function ( IdentityDescriptor $descriptor ) use ( &$admit ): bool {
					unset( $descriptor );
					return $admit;
				}
			);
			$offer                     = $this->offer( $updater );
			self::assertSame( array( 1, 1, 1 ), array( $adapter->list_calls, $adapter->inspect_calls, $adapter->acquire_calls ) );
			$admit = false;
			self::assertFalse(
				$updater->filter_update(
					array( 'package' => $offer['package'] ),
					array(
						'Version'   => '1.0.0',
						'UpdateURI' => $this->uri(),
					),
					'package/package.php',
					array()
				)
			);
			self::assertSame( array( 2, 2, 1 ), array( $adapter->list_calls, $adapter->inspect_calls, $adapter->acquire_calls ) );
			self::assertSame( 'candidate_admission_rejected', $updater->status()['candidate_validation_code'] );
		}

		public function test_descriptor_admission_removes_unprovable_or_rejected_cached_offer(): void {
			$admit           = true;
			list( $updater ) = $this->subject(
				descriptor_admission: static function ( IdentityDescriptor $descriptor ) use ( &$admit ): bool {
					unset( $descriptor );
					return $admit;
				}
			);
			$offer           = $this->offer( $updater );
			$transient       = (object) array( 'response' => array( 'package/package.php' => (object) array( 'package' => $offer['package'] ) ) );
			$unprovable      = (object) array( 'response' => array( 'package/package.php' => (object) array( 'package' => 'https://example.test/archive.zip' ) ) );
			self::assertSame( $unprovable, $updater->filter_cached_update( $unprovable ) );
			self::assertArrayNotHasKey( 'package/package.php', $unprovable->response );
			$admit = false;
			self::assertSame( $transient, $updater->filter_cached_update( $transient ) );
			self::assertArrayNotHasKey( 'package/package.php', $transient->response );
		}

		public function test_descriptor_admission_requires_literal_true_and_fails_closed_on_throw(): void {
			foreach ( array( false, 'true', new \RuntimeException( 'Rejected.' ) ) as $outcome ) {
				list( $updater, $adapter ) = $this->subject(
					descriptor_admission: static function ( IdentityDescriptor $descriptor ) use ( $outcome ): mixed {
						unset( $descriptor );
						if ( $outcome instanceof \RuntimeException ) {
							throw $outcome;
						}
						return $outcome;
					}
				);
				self::assertFalse(
					$updater->filter_update(
						array( 'package' => 'stale' ),
						array(
							'Version'   => '1.0.0',
							'UpdateURI' => $this->uri(),
						),
						'package/package.php',
						array()
					)
				);
				self::assertSame( array( 1, 1, 0 ), array( $adapter->list_calls, $adapter->inspect_calls, $adapter->acquire_calls ) );
			}
		}

		public function test_descriptor_admission_flip_after_acquisition_blocks_native_mutation(): void {
			$admit           = true;
			$begins          = 0;
			list( $updater ) = $this->subject(
				descriptor_admission: static function ( IdentityDescriptor $descriptor ) use ( &$admit ): bool {
					unset( $descriptor );
					return $admit;
				},
				operation_guard: static fn (): bool => true,
				native_mutation_begin: static function () use ( &$begins ): string {
					++$begins;
					return 'owner';
				},
				native_mutation_complete: static function (): void {}
			);
			$offer           = $this->offer( $updater );
			$archive         = $updater->filter_pre_download( false, $offer['package'], null, $this->extra() );
			self::assertIsString( $archive );
			self::assertNull( $updater->filter_pre_unzip_file( null, $archive, '/tmp', array(), 0.0 ) );
			self::assertTrue( $updater->filter_pre_install( true, $this->extra() ) );
			$admit = false;
			self::assertInstanceOf( \WP_Error::class, $updater->filter_source_selection( $this->staged(), '/tmp', null, $this->extra() ) );
			self::assertSame( 0, $begins );
		}

		public function test_only_complete_listing_establishes_no_candidates(): void {
			foreach ( array( false, true ) as $exhausted ) {
				list( $updater, $adapter )                           = $this->subject();
				$adapter->list_response                              = array(
					'candidates'       => array(),
					'search_exhausted' => $exhausted,
					'not_modified'     => false,
				);
				$GLOBALS['ran_wp_release_updater_test_observations'] = array();
				self::assertFalse(
					$updater->filter_update(
						false,
						array(
							'Version'   => '1.0.0',
							'UpdateURI' => $this->uri(),
						),
						'package/package.php',
						array()
					)
				);
				$events = $GLOBALS['ran_wp_release_updater_test_observations'];
				self::assertSame( $exhausted ? 'release_list_failed' : 'no_candidates', $events[1][1]['reason'] );
			}
		}

		public function test_complete_valid_older_listing_establishes_no_newer_candidate(): void {
			list( $updater, $adapter, , $descriptor ) = $this->subject();
			$candidate                                = $this->candidate( $descriptor );
			$candidate['version']                     = '0.9.0';
			$adapter->list_response                   = array(
				'candidates'       => array( $candidate ),
				'search_exhausted' => false,
				'not_modified'     => false,
			);
			self::assertFalse(
				$updater->filter_update(
					false,
					array(
						'Version'   => '1.0.0',
						'UpdateURI' => $this->uri(),
					),
					'package/package.php',
					array()
				)
			);
			$events = $GLOBALS['ran_wp_release_updater_test_observations'];
			self::assertSame( 'no_newer_candidate', $events[1][1]['reason'] );
			self::assertSame( 'succeeded', $events[1][1]['status'] );
			self::assertSame( 0, $adapter->inspect_calls );
		}

		public function test_observation_drops_invalid_version_and_has_exact_safe_keys(): void {
			list( $updater, $adapter ) = $this->subject();
			self::assertFalse(
				$updater->filter_update(
					false,
					array(
						'Version'   => 'https://secret:credential@example.test',
						'UpdateURI' => $this->uri(),
					),
					'package/package.php',
					array()
				)
			);
			$events = $GLOBALS['ran_wp_release_updater_test_observations'];
			self::assertSame( array( 'check_id', 'network_id', 'blog_id', 'target_type', 'installed_identity', 'provider_code', 'repository_identity', 'channel', 'binding_hash' ), array_keys( $events[0][1] ) );
			self::assertSame( array( 'check_id', 'status', 'reason', 'relationship', 'installed_version', 'candidate_version', 'offered_version', 'candidate_validation_code' ), array_keys( $events[1][1] ) );
			self::assertNull( $events[1][1]['installed_version'] );
			self::assertSame( 'runtime_identity_invalid', $events[1][1]['reason'] );
			self::assertSame( 0, $adapter->list_calls );
		}

		#[\PHPUnit\Framework\Attributes\DataProvider( 'later_non_candidates' )]
		public function test_rejected_newer_candidate_is_not_reported_as_no_newer_candidate( string $version ): void {
			list( $updater, $adapter, , $descriptor ) = $this->subject();
			$candidate                                = $this->candidate( $descriptor );
			$older                                    = $candidate;
			$older['version']                         = $version;
			$adapter->list_response                   = array(
				'candidates'       => array( $candidate, $older ),
				'search_exhausted' => false,
				'not_modified'     => false,
			);
			$adapter->inspect_outcomes[ $candidate['release_identity'] ] = new ReleaseFailure( 'release_unavailable' );
			self::assertFalse(
				$updater->filter_update(
					false,
					array(
						'Version'   => '1.0.0',
						'UpdateURI' => $this->uri(),
					),
					'package/package.php',
					array()
				)
			);
			$events = $GLOBALS['ran_wp_release_updater_test_observations'];
			self::assertSame( 'candidate_rejected', $events[1][1]['reason'] );
			self::assertSame( 'rejected', $events[1][1]['status'] );
			self::assertSame( '2.0.0', $events[1][1]['candidate_version'] );
			self::assertSame( 'newer', $events[1][1]['relationship'] );
			self::assertSame( 'candidate_inspection_failed', $events[1][1]['candidate_validation_code'] );
		}

		/** @return array<string,array{string}> */
		public static function later_non_candidates(): array {
			return array(
				'older' => array( '0.9.0' ),
				'same'  => array( '1.0.0' ),
			);
		}

		public function test_state_construction_and_register_are_passive(): void {
			list( $updater, $adapter, $database ) = $this->subject();
			self::assertSame( array( 0, 0, 0 ), array( $adapter->list_calls, $adapter->inspect_calls, $adapter->acquire_calls ) );
			self::assertSame( array(), $database->prepared_sql() );
			self::assertSame( array(), $database->read_option_names() );
			$updater->register();
			$updater->register();
			self::assertCount( 10, $GLOBALS['ran_wp_release_updater_test_hooks'] );
			self::assertSame( array( 0, 0, 0 ), array( $adapter->list_calls, $adapter->inspect_calls, $adapter->acquire_calls ) );
		}
		public function test_offer_rechecks_runtime_uri_uses_unique_info_slug_and_default_denies_automatic(): void {
			list( $manual_updater ) = $this->subject();
			$manual_offer           = $this->offer( $manual_updater );
			self::assertFalse( $manual_offer['autoupdate'] );
			self::assertFalse(
				$manual_updater->filter_auto_update(
					true,
					(object) array(
						'plugin'  => 'package/package.php',
						'package' => $manual_offer['package'],
					)
				)
			);
			list( $automatic_updater ) = $this->subject( 'automatic' );
			$automatic_offer           = $this->offer( $automatic_updater );
			self::assertTrue( $automatic_offer['autoupdate'] );
			self::assertTrue(
				$automatic_updater->filter_auto_update(
					false,
					(object) array(
						'plugin'  => 'package/package.php',
						'package' => $automatic_offer['package'],
					)
				)
			);
		}
		public function test_eligible_native_discovery_reuses_only_its_validated_request_snapshot(): void {
			list( $updater, $adapter ) = $this->subject( 'manual', null, 'stable', false, null, true );
			$this->offer( $updater );
			$this->offer( $updater );
			$information = $updater->filter_plugin_information( false, 'plugin_information', (object) array( 'slug' => 'ran-wp-release-updater-' . substr( hash( 'sha256', 'plugin' . "\0" . 'package/package.php' ), 0, 24 ) ) );
			self::assertIsObject( $information );
			if ( ! isset( $information->version ) ) {
				self::fail( 'Plugin information omitted its version.' );
			}
			self::assertSame( '2.0.0', $information->version );
			self::assertSame( array( 1, 1, 1 ), array( $adapter->list_calls, $adapter->inspect_calls, $adapter->acquire_calls ) );
			self::assertSame( 'archive_identity_verified', $updater->status()['candidate_validation_code'] );

			self::assertIsArray(
				$updater->filter_update(
					false,
					array(
						'Version'   => '1.1.0',
						'UpdateURI' => $this->uri(),
					),
					'package/package.php',
					array()
				)
			);
			self::assertSame( array( 2, 2, 2 ), array( $adapter->list_calls, $adapter->inspect_calls, $adapter->acquire_calls ) );
			self::assertTrue( $updater->refresh() );
			$this->offer( $updater );
			self::assertSame( array( 3, 3, 3 ), array( $adapter->list_calls, $adapter->inspect_calls, $adapter->acquire_calls ) );
		}
		public function test_reentrant_matching_install_attempt_prevents_discovery_from_publishing_a_snapshot(): void {
			$validator                 = new PackageIdentityValidator();
			list( $updater, $adapter ) = $this->subject( 'manual', null, 'stable', false, null, true, $validator );
			$after_open                = new \ReflectionProperty( $validator, 'after_open' );
			$after_open->setValue(
				$validator,
				static function ( string $path ) use ( $updater ): void {
					unset( $path );
					$updater->filter_pre_download( new \WP_Error( 'interrupted', 'Interrupted.' ), '', null, array( 'plugin' => 'package/package.php' ) );
				}
			);
			$this->offer( $updater );
			$this->offer( $updater );
			self::assertSame( array( 2, 2, 2 ), array( $adapter->list_calls, $adapter->inspect_calls, $adapter->acquire_calls ) );
		}
		public function test_reentrant_refresh_prevents_discovery_from_publishing_a_snapshot(): void {
			$validator                 = new PackageIdentityValidator();
			list( $updater, $adapter ) = $this->subject( 'manual', null, 'stable', false, null, true, $validator );
			$after_open                = new \ReflectionProperty( $validator, 'after_open' );
			$after_open->setValue(
				$validator,
				static function ( string $path ) use ( $updater ): void {
					unset( $path );
					$updater->refresh();
				}
			);
			$this->offer( $updater );
			$this->offer( $updater );
			self::assertSame( array( 2, 2, 2 ), array( $adapter->list_calls, $adapter->inspect_calls, $adapter->acquire_calls ) );
		}
		public function test_claim_takeover_and_expiry_reclaim_never_reuse_a_snapshot(): void {
			list( $updater, $adapter, $database, , $binding ) = $this->subject( 'manual', null, 'stable', false, null, true );
			$this->offer( $updater );
			$name      = 'ran_wp_release_updater_target_v1_' . BindingRecord::target_fence_key(
				array(
					'network_id'                 => 1,
					'target_type'                => 'plugin',
					'installed_package_identity' => 'package/package.php',
				)
			);
			$state     = BindingState::rehydrate( json_decode( $database->rows()[ $name ]['option_value'], true, 32, JSON_THROW_ON_ERROR ) );
			$successor = BindingState::create( $binding, str_repeat( 'b', 64 ), 101, $state->binding_generation() + 1, $state->fence_epoch() + 1 );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Native JSON preserves protocol or fixture bytes without requiring WordPress helpers or their fallback behavior.
			$database->force_option_value( $name, json_encode( $successor->to_array(), JSON_THROW_ON_ERROR ) );
			self::assertFalse(
				$updater->filter_update(
					false,
					array(
						'Version'   => '1.0.0',
						'UpdateURI' => $this->uri(),
					),
					'package/package.php',
					array()
				)
			);
			$database->set_time( 102 );
			$this->offer( $updater );
			self::assertSame( array( 2, 2, 2 ), array( $adapter->list_calls, $adapter->inspect_calls, $adapter->acquire_calls ) );
		}
		public function test_failed_discovery_and_runtime_displacement_never_reuse_a_snapshot(): void {
			list( $updater, $adapter, , $descriptor ) = $this->subject( 'manual', null, 'stable', false, null, true );
			$facts                                    = $descriptor->to_array();
			unset( $facts['fingerprint'] );
			$facts['tag']                = 'v2.0.1';
			$adapter->inspect_descriptor = IdentityDescriptor::create( $facts );
			self::assertFalse(
				$updater->filter_update(
					false,
					array(
						'Version'   => '1.0.0',
						'UpdateURI' => $this->uri(),
					),
					'package/package.php',
					array()
				)
			);
			$adapter->inspect_descriptor = $descriptor;
			$this->offer( $updater );
			self::assertSame( array( 2, 2, 1 ), array( $adapter->list_calls, $adapter->inspect_calls, $adapter->acquire_calls ) );
		}
		public function test_runtime_displacement_and_restore_never_reuse_a_snapshot(): void {
			$state  = new SelectedRuntimeState();
			$broker = new RequestBroker( false, $state );
			$state->bind( $broker );
			$GLOBALS['ran_wp_release_updater_v1_broker'] = $broker;
			( new \ReflectionProperty( $broker, 'state' ) )->setValue( $broker, 'active' );
			try {
				list( $updater, $adapter ) = $this->subject( 'manual', null, 'stable', false, $state, true );
				$this->offer( $updater );
				$GLOBALS['ran_wp_release_updater_v1_broker'] = new \stdClass();
				self::assertFalse(
					$updater->filter_update(
						false,
						array(
							'Version'   => '1.0.0',
							'UpdateURI' => $this->uri(),
						),
						'package/package.php',
						array()
					)
				);
				$GLOBALS['ran_wp_release_updater_v1_broker'] = $broker;
				$this->offer( $updater );
				self::assertSame( array( 2, 2, 2 ), array( $adapter->list_calls, $adapter->inspect_calls, $adapter->acquire_calls ) );
			} finally {
				unset( $GLOBALS['ran_wp_release_updater_v1_broker'] );
			}
		}
		public function test_matching_completion_invalidates_discovery_snapshot(): void {
			list( $updater, $adapter ) = $this->subject( 'manual', null, 'stable', false, null, true );
			$this->offer( $updater );
			$updater->observe_completion(
				null,
				array(
					'action'  => 'update',
					'type'    => 'plugin',
					'plugins' => array( 'package/package.php' ),
				)
			);
			self::assertNull( $updater->status()['offered_release_identity'] );
			$this->offer( $updater );
			self::assertSame( array( 2, 2, 2 ), array( $adapter->list_calls, $adapter->inspect_calls, $adapter->acquire_calls ) );
		}
		public function test_status_projects_only_the_observed_offer_and_failure_and_refresh_clears_it(): void {
			list( $updater, $adapter, $database ) = $this->subject();
			self::assertSame(
				array(
					'candidate_header_version'  => null,
					'candidate_tag'             => null,
					'candidate_validation_code' => null,
					'candidate_version'         => null,
					'failure_code'              => null,
					'installed_version'         => null,
					'last_check'                => null,
					'offered_release_identity'  => null,
					'offered_version'           => null,
					'relationship'              => null,
				),
				$updater->status()
			);
			self::assertSame( array( 0, 0, 0 ), array( $adapter->list_calls, $adapter->inspect_calls, $adapter->acquire_calls ) );
			self::assertSame( array(), $database->prepared_sql() );
			$this->offer( $updater );
			$status = $updater->status();
			self::assertSame( 'v2.0.0', $status['candidate_tag'] );
			self::assertSame( '2.0.0', $status['candidate_version'] );
			self::assertSame( 'archive_identity_verified', $status['candidate_validation_code'] );
			self::assertSame( '2.0.0', $status['candidate_header_version'] );
			self::assertSame( '1.0.0', $status['installed_version'] );
			self::assertIsInt( $status['last_check'] );
			self::assertSame( 'release:2', $status['offered_release_identity'] );
			self::assertSame( '2.0.0', $status['offered_version'] );
			self::assertSame( 'newer', $status['relationship'] );
			self::assertNull( $status['failure_code'] );
			$updater->filter_update(
				false,
				array(
					'Version'   => 'bad',
					'UpdateURI' => $this->uri(),
				),
				'package/package.php',
				array()
			);
			self::assertSame( 'runtime_package_identity_invalid', $updater->status()['failure_code'] );
			self::assertNull( $updater->status()['offered_release_identity'] );
			$updater->refresh();
			self::assertSame(
				array(
					'candidate_header_version'  => null,
					'candidate_tag'             => null,
					'candidate_validation_code' => null,
					'candidate_version'         => null,
					'failure_code'              => null,
					'installed_version'         => null,
					'last_check'                => null,
					'offered_release_identity'  => null,
					'offered_version'           => null,
					'relationship'              => null,
				),
				$updater->status()
			);
		}
		public function test_listing_rate_limit_facts_stop_before_any_candidate_inspection(): void {
			list( $updater, $adapter, , $descriptor ) = $this->subject();
			$adapter->list_response                   = array(
				'candidates' => array( $this->candidate( $descriptor ) ),
				'rate_limit' => array(
					'limited'     => true,
					'remaining'   => 0,
					'reset_at'    => null,
					'retry_after' => 60,
				),
			);

			self::assertFalse(
				$updater->filter_update(
					false,
					array(
						'Version'   => '1.0.0',
						'UpdateURI' => $this->uri(),
					),
					'package/package.php',
					array()
				)
			);
			self::assertSame( array( 1, 0, 0 ), array( $adapter->list_calls, $adapter->inspect_calls, $adapter->acquire_calls ) );
			self::assertSame( 'release_list_failed', $updater->status()['candidate_validation_code'] );
		}
		public function test_theme_listing_rate_limit_facts_stop_before_any_candidate_inspection(): void {
			$archive = $this->archive();
			$facts   = $this->descriptor( $archive )->to_array();
			unset( $facts['fingerprint'] );
			$facts['installed_package_identity']         = 'package';
			$facts['target_type']                        = 'theme';
			$descriptor                                  = IdentityDescriptor::create( $facts );
			$binding                                     = BindingRecord::create(
				array(
					'canonical_repository_locator' => 'owner/package',
					'canonical_update_uri'         => $this->uri(),
					'installed_package_identity'   => 'package',
					'maximum_artifact_bytes'       => 52428800,
					'network_id'                   => 1,
					'php_runtime_version'          => '8.2',
					'provider_code'                => 'neutral',
					'release_channel'              => 'stable',
					'stable_repository_identity'   => 'repo:1',
					'target_type'                  => 'theme',
					'theme_template'               => '',
					'update_policy'                => 'manual',
					'wordpress_runtime_version'    => '6.8',
				)
			);
			$adapter                                     = new ControllableReleaseAdapter( $descriptor, $archive, $this->temporary_directory );
			$adapter->list_response                      = array(
				'candidates' => array( $this->candidate( $descriptor ) ),
				'rate_limit' => array(
					'limited'     => true,
					'remaining'   => 0,
					'reset_at'    => null,
					'retry_after' => 60,
				),
			);
			$configuration                               = $this->config( 'manual' );
			$configuration['target_type']                = 'theme';
			$configuration['installed_package_identity'] = 'package';
			$policy                                      = $this->policy();
			$policy['header_file']                       = 'style.css';
			$policy['installed_package_identity']        = 'package';
			$policy['target_type']                       = 'theme';
			$updater                                     = NativePackageUpdater::from_configuration( $configuration, $binding, $adapter, new FakeOptionDatabase( 100 ), $policy );

			self::assertInstanceOf( NativePackageUpdater::class, $updater );
			self::assertFalse(
				$updater->filter_update(
					false,
					array(
						'Version'   => '1.0.0',
						'UpdateURI' => $this->uri(),
					),
					'package',
					array()
				)
			);
			self::assertSame( array( 1, 0, 0 ), array( $adapter->list_calls, $adapter->inspect_calls, $adapter->acquire_calls ) );
			self::assertSame( 'release_list_failed', $updater->status()['candidate_validation_code'] );
		}
		public function test_malformed_candidate_stops_discovery_before_a_later_candidate(): void {
			list( $updater, $adapter, , $descriptor ) = $this->subject();
			$adapter->list_response                   = array(
				'candidates' => array(
					array(
						'release_identity' => 'release:invalid',
						'tag'              => 'v2.0.0',
					),
					$this->candidate( $descriptor ),
				),
			);

			self::assertFalse(
				$updater->filter_update(
					false,
					array(
						'Version'   => '1.0.0',
						'UpdateURI' => $this->uri(),
					),
					'package/package.php',
					array()
				)
			);
			self::assertSame( array( 1, 0, 0 ), array( $adapter->list_calls, $adapter->inspect_calls, $adapter->acquire_calls ) );
			self::assertSame( 'candidate_invalid', $updater->status()['candidate_validation_code'] );
		}
		public function test_descriptor_mismatch_stops_discovery_before_a_later_candidate(): void {
			list( $updater, $adapter, , $first ) = $this->subject();
			$second                              = $this->with_release_identity( $first, 'release:3', 'v2.0.1' );
			$adapter->list_response              = array( 'candidates' => array( $this->candidate( $first ), $this->candidate( $second ) ) );
			$adapter->inspect_outcomes[ $first->release_identity() ]  = $second;
			$adapter->inspect_outcomes[ $second->release_identity() ] = $second;

			self::assertFalse(
				$updater->filter_update(
					false,
					array(
						'Version'   => '1.0.0',
						'UpdateURI' => $this->uri(),
					),
					'package/package.php',
					array()
				)
			);
			self::assertSame( array( 1, 1, 0 ), array( $adapter->list_calls, $adapter->inspect_calls, $adapter->acquire_calls ) );
			self::assertSame( 'candidate_descriptor_mismatch', $updater->status()['candidate_validation_code'] );
		}
		#[\PHPUnit\Framework\Attributes\DataProvider( 'operation_stopping_inspection_failures' )]
		public function test_operation_stopping_inspection_failures_do_not_reach_later_candidates( \Throwable $failure ): void {
			list( $updater, $adapter, , $first ) = $this->subject();
			$second                              = $this->with_release_identity( $first, 'release:3', 'v2.0.1' );
			$adapter->list_response              = array( 'candidates' => array( $this->candidate( $first ), $this->candidate( $second ) ) );
			$adapter->inspect_outcomes[ $first->release_identity() ]  = $failure;
			$adapter->inspect_outcomes[ $second->release_identity() ] = $second;

			self::assertFalse(
				$updater->filter_update(
					false,
					array(
						'Version'   => '1.0.0',
						'UpdateURI' => $this->uri(),
					),
					'package/package.php',
					array()
				)
			);
			self::assertSame( array( 1, 1, 0 ), array( $adapter->list_calls, $adapter->inspect_calls, $adapter->acquire_calls ) );
			self::assertSame( 'candidate_inspection_failed', $updater->status()['candidate_validation_code'] );
		}
		/** @return array<string,array{\Throwable}> */
		public static function operation_stopping_inspection_failures(): array {
			return array(
				'rate limited'                  => array( new ReleaseFailure( 'rate_limited', 60 ) ),
				'repository access unavailable' => array( new ReleaseFailure( 'repository_access_unavailable' ) ),
				'operation failed'              => array( new ReleaseFailure( 'operation_failed' ) ),
				'cleanup failed'                => array( new ReleaseFailure( 'package_incompatible', null, 'failed' ) ),
				'unknown exception'             => array( new \RuntimeException( 'unexpected' ) ),
			);
		}
		#[\PHPUnit\Framework\Attributes\DataProvider( 'candidate_local_inspection_failures' )]
		public function test_candidate_local_inspection_failures_allow_a_later_valid_candidate( ReleaseFailure $failure ): void {
			list( $updater, $adapter, , $first ) = $this->subject();
			$second                              = $this->with_release_identity( $first, 'release:3', 'v2.0.1' );
			$adapter->list_response              = array( 'candidates' => array( $this->candidate( $first ), $this->candidate( $second ) ) );
			$adapter->inspect_outcomes[ $first->release_identity() ]  = $failure;
			$adapter->inspect_outcomes[ $second->release_identity() ] = $second;

			$offer = $updater->filter_update(
				false,
				array(
					'Version'   => '1.0.0',
					'UpdateURI' => $this->uri(),
				),
				'package/package.php',
				array()
			);
			self::assertIsArray( $offer );
			self::assertSame( array( 1, 2, 1 ), array( $adapter->list_calls, $adapter->inspect_calls, $adapter->acquire_calls ) );
			self::assertSame( 'release:3', $updater->status()['offered_release_identity'] );
			$completion = $GLOBALS['ran_wp_release_updater_test_observations'][1][1];
			self::assertSame( 'succeeded', $completion['status'] );
			self::assertSame( 'update_available', $completion['reason'] );
			self::assertSame( $second->to_array()['version'], $completion['candidate_version'] );
			self::assertSame( 'newer', $completion['relationship'] );
			self::assertSame( 'archive_identity_verified', $completion['candidate_validation_code'] );
		}
		/** @return array<string,array{ReleaseFailure}> */
		public static function candidate_local_inspection_failures(): array {
			return array(
				'concrete release unavailable' => array( new ReleaseFailure( 'release_unavailable', null, 'complete' ) ),
				'package incompatible'         => array( new ReleaseFailure( 'package_incompatible', null, 'complete' ) ),
			);
		}
		#[\PHPUnit\Framework\Attributes\DataProvider( 'operation_stopping_acquisition_failures' )]
		public function test_operation_stopping_acquisition_failures_do_not_reach_later_candidates( \Throwable $failure ): void {
			list( $updater, $adapter, , $first ) = $this->subject();
			$second                              = $this->with_release_identity( $first, 'release:3', 'v2.0.1' );
			$adapter->list_response              = array( 'candidates' => array( $this->candidate( $first ), $this->candidate( $second ) ) );
			$adapter->inspect_outcomes[ $first->release_identity() ]  = $first;
			$adapter->inspect_outcomes[ $second->release_identity() ] = $second;
			$adapter->acquire_outcomes[ $first->release_identity() ]  = $failure;

			self::assertFalse(
				$updater->filter_update(
					false,
					array(
						'Version'   => '1.0.0',
						'UpdateURI' => $this->uri(),
					),
					'package/package.php',
					array()
				)
			);
			self::assertSame( array( 1, 1, 1 ), array( $adapter->list_calls, $adapter->inspect_calls, $adapter->acquire_calls ) );
			self::assertSame( 'candidate_validation_failed', $updater->status()['candidate_validation_code'] );
		}
		/** @return array<string,array{\Throwable}> */
		public static function operation_stopping_acquisition_failures(): array {
			return array(
				'rate limited'      => array( new ReleaseFailure( 'rate_limited', 60 ) ),
				'operation failed'  => array( new ReleaseFailure( 'operation_failed' ) ),
				'unknown exception' => array( new \RuntimeException( 'unexpected' ) ),
			);
		}
		#[\PHPUnit\Framework\Attributes\DataProvider( 'candidate_local_acquisition_failures' )]
		public function test_candidate_local_acquisition_failures_allow_a_later_valid_candidate( ReleaseFailure $failure ): void {
			list( $updater, $adapter, , $first ) = $this->subject();
			$second                              = $this->with_release_identity( $first, 'release:3', 'v2.0.1' );
			$adapter->list_response              = array( 'candidates' => array( $this->candidate( $first ), $this->candidate( $second ) ) );
			$adapter->inspect_outcomes[ $first->release_identity() ]  = $first;
			$adapter->inspect_outcomes[ $second->release_identity() ] = $second;
			$adapter->acquire_outcomes[ $first->release_identity() ]  = $failure;

			self::assertIsArray(
				$updater->filter_update(
					false,
					array(
						'Version'   => '1.0.0',
						'UpdateURI' => $this->uri(),
					),
					'package/package.php',
					array()
				)
			);
			self::assertSame( array( 1, 2, 2 ), array( $adapter->list_calls, $adapter->inspect_calls, $adapter->acquire_calls ) );
			self::assertSame( 'release:3', $updater->status()['offered_release_identity'] );
		}
		/** @return array<string,array{ReleaseFailure}> */
		public static function candidate_local_acquisition_failures(): array {
			return array(
				'concrete release unavailable' => array( new ReleaseFailure( 'release_unavailable' ) ),
				'package incompatible'         => array( new ReleaseFailure( 'package_incompatible' ) ),
			);
		}
		public function test_failed_artifact_cleanup_stops_discovery_before_a_later_candidate(): void {
			$validator  = new PackageIdentityValidator();
			$after_open = new \ReflectionProperty( $validator, 'after_open' );
			$after_open->setValue(
				$validator,
				static function ( string $path ): void {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Set real fixture permission bits for archive custody and permission-boundary checks.
					chmod( $path, 0644 );
				}
			);
			list( $updater, $adapter, , $first ) = $this->subject( 'manual', null, 'stable', false, null, false, $validator );
			$second                              = $this->with_release_identity( $first, 'release:3', 'v2.0.1' );
			$adapter->list_response              = array( 'candidates' => array( $this->candidate( $first ), $this->candidate( $second ) ) );
			$adapter->inspect_outcomes[ $first->release_identity() ]  = $first;
			$adapter->inspect_outcomes[ $second->release_identity() ] = $second;

			self::assertFalse(
				$updater->filter_update(
					false,
					array(
						'Version'   => '1.0.0',
						'UpdateURI' => $this->uri(),
					),
					'package/package.php',
					array()
				)
			);
			self::assertSame( array( 1, 1, 1 ), array( $adapter->list_calls, $adapter->inspect_calls, $adapter->acquire_calls ) );
			self::assertSame( 'candidate_validation_failed', $updater->status()['candidate_validation_code'] );
			self::assertFileExists( $adapter->acquired_paths[0] );
		}
		public function test_unexpected_validation_failure_discards_an_unchanged_artifact_and_stops_discovery(): void {
			$validator  = new PackageIdentityValidator();
			$after_open = new \ReflectionProperty( $validator, 'after_open' );
			$after_open->setValue(
				$validator,
				static function ( string $path ): void {
					unset( $path );
					throw new \RuntimeException( 'unexpected validation failure' );
				}
			);
			list( $updater, $adapter, , $first ) = $this->subject( 'manual', null, 'stable', false, null, false, $validator );
			$second                              = $this->with_release_identity( $first, 'release:3', 'v2.0.1' );
			$adapter->list_response              = array( 'candidates' => array( $this->candidate( $first ), $this->candidate( $second ) ) );
			$adapter->inspect_outcomes[ $first->release_identity() ]  = $first;
			$adapter->inspect_outcomes[ $second->release_identity() ] = $second;

			self::assertFalse(
				$updater->filter_update(
					false,
					array(
						'Version'   => '1.0.0',
						'UpdateURI' => $this->uri(),
					),
					'package/package.php',
					array()
				)
			);
			self::assertSame( array( 1, 1, 1 ), array( $adapter->list_calls, $adapter->inspect_calls, $adapter->acquire_calls ) );
			self::assertSame( 'candidate_validation_failed', $updater->status()['candidate_validation_code'] );
			self::assertNull( $updater->status()['offered_release_identity'] );
			self::assertFileDoesNotExist( $adapter->acquired_paths[0] );
		}
		public function test_unexpected_validation_failure_preserves_changed_artifact_and_stops_discovery(): void {
			$validator  = new PackageIdentityValidator();
			$after_open = new \ReflectionProperty( $validator, 'after_open' );
			$after_open->setValue(
				$validator,
				static function ( string $path ): void {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write exact bytes for installed-package lifecycle fixtures; WordPress helpers would alter the boundary under test.
					file_put_contents( $path, 'replacement bytes' );
					throw new \RuntimeException( 'unexpected validation failure' );
				}
			);
			list( $updater, $adapter, , $first ) = $this->subject( 'manual', null, 'stable', false, null, false, $validator );
			$second                              = $this->with_release_identity( $first, 'release:3', 'v2.0.1' );
			$adapter->list_response              = array( 'candidates' => array( $this->candidate( $first ), $this->candidate( $second ) ) );
			$adapter->inspect_outcomes[ $first->release_identity() ]  = $first;
			$adapter->inspect_outcomes[ $second->release_identity() ] = $second;

			self::assertFalse(
				$updater->filter_update(
					false,
					array(
						'Version'   => '1.0.0',
						'UpdateURI' => $this->uri(),
					),
					'package/package.php',
					array()
				)
			);
			self::assertSame( array( 1, 1, 1 ), array( $adapter->list_calls, $adapter->inspect_calls, $adapter->acquire_calls ) );
			self::assertSame( 'candidate_validation_failed', $updater->status()['candidate_validation_code'] );
			self::assertNull( $updater->status()['offered_release_identity'] );
			self::assertFileExists( $adapter->acquired_paths[0] );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read exact local bytes for installed-package lifecycle fixtures without requiring WordPress filesystem initialization.
			self::assertSame( 'replacement bytes', file_get_contents( $adapter->acquired_paths[0] ) );
		}
		public function test_offer_status_binds_the_exact_verified_release_identity_rather_than_the_version(): void {
			list( $updater, $adapter, , $descriptor ) = $this->subject();
			$this->offer( $updater );
			self::assertSame( 'release:2', $updater->status()['offered_release_identity'] );
			$facts = $descriptor->to_array();
			unset( $facts['fingerprint'] );
			$facts['release_identity']   = 'release:3';
			$replacement                 = IdentityDescriptor::create( $facts );
			$adapter->inspect_descriptor = $replacement;
			( new \ReflectionProperty( $adapter, 'descriptor' ) )->setValue( $adapter, $replacement );
			$this->offer( $updater );
			self::assertSame( '2.0.0', $updater->status()['offered_version'] );
			self::assertSame( 'release:3', $updater->status()['offered_release_identity'] );
		}
		public function test_prerelease_channel_offer_is_manual_and_automatic_is_denied(): void {
			list( $updater ) = $this->subject( 'manual', null, 'prerelease', true );
			$offer           = $this->offer( $updater );
			self::assertFalse( $offer['autoupdate'] );
			self::assertFalse(
				$updater->filter_auto_update(
					true,
					(object) array(
						'plugin'  => 'package/package.php',
						'package' => $offer['package'],
					)
				)
			);
		}
		public function test_canonical_stale_and_older_tokens_are_rejected_at_automatic_and_download_admission(): void {
			list( $updater, $adapter, , $descriptor, $binding ) = $this->subject( 'automatic' );
			foreach ( array( '1.0.0', '0.9.0' ) as $version ) {
				$facts = $descriptor->to_array();
				unset( $facts['fingerprint'] );
				$facts['version'] = $version;
				$facts['tag']     = 'v' . $version;
				$token            = $this->token( IdentityDescriptor::create( $facts ), $binding );
				self::assertFalse(
					$updater->filter_auto_update(
						true,
						(object) array(
							'plugin'  => 'package/package.php',
							'package' => $token,
						)
					)
				);
				self::assertInstanceOf( \WP_Error::class, $updater->filter_pre_download( false, $token, null, $this->extra() ) );
			}
			self::assertSame( array( 0, 0, 0 ), array( $adapter->list_calls, $adapter->inspect_calls, $adapter->acquire_calls ) );
		}
		public function test_noncanonical_and_tampered_tokens_are_rejected_without_install_calls(): void {
			list( $updater, $adapter ) = $this->subject();
			$offer                     = $this->offer( $updater );
			$token                     = $offer['package'];
			$encoded_token             = substr( $token, strrpos( $token, ':' ) + 1 );
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Encode/decode the existing opaque operation-token format for contract and malformed-token tests.
			$decoded_token = base64_decode( strtr( $encoded_token, '-_', '+/' ) . str_repeat( '=', ( 4 - strlen( $encoded_token ) % 4 ) % 4 ), true );
			self::assertIsString( $decoded_token );
			$binding_facts                 = json_decode( $decoded_token, true, 32, JSON_THROW_ON_ERROR );
			$binding_facts['binding_hash'] = str_repeat( 'b', 64 );
			$tampered_binding_token        = 'ran-wp-release-updater:v1:' . rtrim(
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode, WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Encode/decode the existing opaque operation-token format for contract and malformed-token tests. Native JSON preserves protocol or fixture bytes without requiring WordPress helpers or their fallback behavior.
				strtr( base64_encode( json_encode( $binding_facts, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES ) ), '+/', '-_' ),
				'='
			);
			$fingerprint_facts                          = json_decode( $decoded_token, true, 32, JSON_THROW_ON_ERROR );
			$fingerprint_facts['descriptor']['version'] = '2.0.1';
			$tampered_fingerprint_token                 = 'ran-wp-release-updater:v1:' . rtrim(
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode, WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Encode/decode the existing opaque operation-token format for contract and malformed-token tests. Native JSON preserves protocol or fixture bytes without requiring WordPress helpers or their fallback behavior.
				strtr( base64_encode( json_encode( $fingerprint_facts, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES ) ), '+/', '-_' ),
				'='
			);
			$invalid_tokens = array(
				$token . '=',
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encode/decode the existing opaque operation-token format for contract and malformed-token tests.
				'ran-wp-release-updater:v1:' . rtrim( strtr( base64_encode( '{"schema":1,"binding_hash":"x","descriptor":{}}' ), '+/', '-_' ), '=' ),
				$tampered_binding_token,
				$tampered_fingerprint_token,
			);
			foreach ( $invalid_tokens as $invalid_token ) {
				self::assertInstanceOf( \WP_Error::class, $updater->filter_pre_download( false, $invalid_token, null, $this->extra() ) );
			}
			self::assertSame( array( 1, 1, 1 ), array( $adapter->list_calls, $adapter->inspect_calls, $adapter->acquire_calls ) );
		}
		public function test_offer_and_install_use_exact_discovery_and_reacquisition_counts_and_copy_ownership(): void {
			list( $updater, $adapter ) = $this->subject();
			$offer                     = $this->offer( $updater );
			self::assertSame( array( 1, 1, 1 ), array( $adapter->list_calls, $adapter->inspect_calls, $adapter->acquire_calls ) );
			self::assertFileDoesNotExist( $adapter->acquired_paths[0] );
			$owned_archive = $updater->filter_pre_download( false, $offer['package'], null, $this->extra() );
			self::assertIsString( $owned_archive );
			self::assertSame( array( 1, 2, 2 ), array( $adapter->list_calls, $adapter->inspect_calls, $adapter->acquire_calls ) );
			self::assertFileDoesNotExist( $adapter->acquired_paths[1] );
			self::assertFileExists( $owned_archive );
			$updater->refresh();
			self::assertFileDoesNotExist( $owned_archive );
		}
		public function test_fresh_inspection_drift_rejects_before_reacquisition(): void {
			list( $updater, $adapter, , $descriptor ) = $this->subject();
			$offer                                    = $this->offer( $updater );
			$descriptor_facts                         = $descriptor->to_array();
			unset( $descriptor_facts['fingerprint'] );
			$descriptor_facts['commit_identity'] = 'changed';
			$adapter->inspect_descriptor         = IdentityDescriptor::create( $descriptor_facts );
			self::assertInstanceOf( \WP_Error::class, $updater->filter_pre_download( false, $offer['package'], null, $this->extra() ) );
			self::assertSame( array( 1, 2, 1 ), array( $adapter->list_calls, $adapter->inspect_calls, $adapter->acquire_calls ) );
			self::assertContains( 'remote_release_changed', $updater->diagnostics() );
		}
		public function test_fresh_inspection_must_remain_newer_than_the_installed_header(): void {
			list( $updater, $adapter, , $descriptor ) = $this->subject();
			$offer                                    = $this->offer( $updater );
			$facts                                    = $descriptor->to_array();
			unset( $facts['fingerprint'] );
			$facts['version']            = '1.0.0';
			$facts['tag']                = 'v1.0.0';
			$adapter->inspect_descriptor = IdentityDescriptor::create( $facts );
			self::assertInstanceOf( \WP_Error::class, $updater->filter_pre_download( false, $offer['package'], null, $this->extra() ) );
			self::assertSame( array( 1, 2, 1 ), array( $adapter->list_calls, $adapter->inspect_calls, $adapter->acquire_calls ) );
			self::assertContains( 'unverified_pre_download', $updater->diagnostics() );
		}
		public function test_offer_only_shutdown_releases_and_automatic_install_promotes_lease(): void {
			list( $first_updater, , $database ) = $this->subject();
			$this->offer( $first_updater );
			$first_updater->finalize_pending_install();
			list( $second_updater ) = $this->subject( 'manual', $database );
			self::assertIsArray( $this->offer( $second_updater ) );
			list( $updater, , $database, , $binding ) = $this->subject( 'automatic' );
			$offer                                    = $this->offer( $updater );
			$database->set_time( 650 );
			self::assertIsString( $updater->filter_pre_download( false, $offer['package'], null, $this->extra() ) );
			$database->set_time( 701 );
			self::assertSame( 'binding_fence_lost', BindingFenceCoordinator::claim_persistent_binding_state( $database, $binding, str_repeat( 'c', 64 ), 1 )['result'] );
			$updater->refresh();
		}
		public function test_competing_owner_after_unzip_rejects_stale_receipt(): void {
			list( $updater, , $database, , $binding ) = $this->subject();
			$offer                                    = $this->offer( $updater );
			$owned_archive                            = $updater->filter_pre_download( false, $offer['package'], null, $this->extra() );
			self::assertIsString( $owned_archive );
			self::assertNull( $updater->filter_pre_unzip_file( null, $owned_archive, '/tmp', array(), 0.0 ) );
			$state         = BindingState::rehydrate( json_decode( array_values( $database->rows() )[0]['option_value'], true, 32, JSON_THROW_ON_ERROR ) );
			$binding_facts = $binding->to_array();
			unset( $binding_facts['binding_hash'] );
			$binding_facts['update_policy'] = 'automatic';
			$rebound_binding                = BindingRecord::create( $binding_facts );
			$name                           = 'ran_wp_release_updater_target_v1_' . BindingRecord::target_fence_key(
				array(
					'network_id'                 => 1,
					'target_type'                => 'plugin',
					'installed_package_identity' => 'package/package.php',
				)
			);
			$successor                      = BindingState::create( $rebound_binding, str_repeat( 'b', 64 ), $state->lease_deadline(), $state->binding_generation() + 1, $state->fence_epoch() + 1 );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Native JSON preserves protocol or fixture bytes without requiring WordPress helpers or their fallback behavior.
			$database->force_option_value( $name, json_encode( $successor->to_array(), JSON_THROW_ON_ERROR ) );
			self::assertInstanceOf( \WP_Error::class, $updater->filter_source_selection( $this->staged(), '/tmp', null, $this->extra() ) );
		}
		public function test_completion_and_rollback_release_claims(): void {
			list( $completed_updater, , $database, , $binding ) = $this->subject();
			$this->complete( $completed_updater );
			self::assertSame( 'claimed', BindingFenceCoordinator::claim_persistent_binding_state( $database, $binding, str_repeat( 'd', 64 ), 1 )['result'] );
			list( $rollback_updater, , $database, , $binding ) = $this->subject();
			$offer = $this->offer( $rollback_updater );
			self::assertIsString( $rollback_updater->filter_pre_download( false, $offer['package'], null, $this->extra() ) );
			self::assertInstanceOf( \WP_Error::class, $rollback_updater->capture_install_package_result( new \WP_Error( 'rollback', 'rollback' ), $this->extra() ) );
			self::assertSame( 'claimed', BindingFenceCoordinator::claim_persistent_binding_state( $database, $binding, str_repeat( 'e', 64 ), 1 )['result'] );
		}
		public function test_passive_seams_do_not_acquire_and_diagnostics_never_expose_caller_input(): void {
			list( $updater, $adapter ) = $this->subject();
			self::assertSame( 'keep', $updater->filter_plugin_information( 'keep', 'plugin_information', (object) array( 'slug' => 'other' ) ) );
			self::assertFalse(
				$updater->filter_auto_update(
					false,
					(object) array(
						'plugin'  => 'other',
						'package' => 'secret',
					)
				)
			);
			self::assertInstanceOf( \WP_Error::class, $updater->filter_pre_download( false, 'secret', null, $this->extra() ) );
			self::assertSame( array( 0, 0, 0 ), array( $adapter->list_calls, $adapter->inspect_calls, $adapter->acquire_calls ) );
			self::assertNotContains( 'secret', $updater->diagnostics() );
		}
		public function test_protocol_liveness_makes_every_public_callback_passive_before_adapter_or_database_work(): void {
			$state  = new SelectedRuntimeState();
			$broker = new RequestBroker( false, $state );
			$state->bind( $broker );
			$GLOBALS['ran_wp_release_updater_v1_broker'] = $broker;
			$property                                    = new \ReflectionProperty( $broker, 'state' );
			$property->setValue( $broker, 'active' );
			list( $updater, $adapter, $database ) = $this->subject( 'manual', null, 'stable', false, $state );
			$updater->register();
			self::assertCount( 10, $GLOBALS['ran_wp_release_updater_test_hooks'] );

			foreach ( array( 'stale_global', 'wrong_protocol' ) as $failure ) {
				if ( 'stale_global' === $failure ) {
					$GLOBALS['ran_wp_release_updater_v1_broker'] = new \stdClass();
				} elseif ( 'wrong_protocol' === $failure ) {
					$GLOBALS['ran_wp_release_updater_v1_broker'] = new class() { public function protocol_version(): int {
							return 1;
					} };
				}
				self::assertFalse(
					$updater->filter_update(
						false,
						array(
							'Version'   => '1.0.0',
							'UpdateURI' => $this->uri(),
						),
						'package/package.php',
						array()
					),
					$failure
				);
				self::assertSame( 'keep', $updater->filter_plugin_information( 'keep', 'plugin_information', (object) array( 'slug' => 'ran-wp-release-updater-' . substr( hash( 'sha256', "plugin\0package/package.php" ), 0, 24 ) ) ), $failure );
				self::assertTrue(
					$updater->filter_auto_update(
						true,
						(object) array(
							'plugin'  => 'package/package.php',
							'package' => 'ignored',
						)
					),
					$failure
				);
				self::assertSame( array( 'hook_extra' => $this->extra() ), $updater->capture_package_options( array( 'hook_extra' => $this->extra() ) ), $failure );
				self::assertSame( 'download', $updater->filter_pre_download( 'download', 'sentinel', null, $this->extra() ), $failure );
				self::assertSame( 'unzip', $updater->filter_pre_unzip_file( 'unzip', 'sentinel', 'destination', array(), 0.0 ), $failure );
				self::assertSame( 'source', $updater->filter_source_selection( 'source', 'remote', null, $this->extra() ), $failure );
				self::assertSame( 'install', $updater->filter_pre_install( 'install', $this->extra() ), $failure );
				self::assertSame( 'result', $updater->capture_install_package_result( 'result', $this->extra() ), $failure );
				$updater->observe_completion(
					null,
					array(
						'action'  => 'update',
						'type'    => 'plugin',
						'plugins' => array( 'package/package.php' ),
					)
				);
				$updater->finalize_pending_install();
				self::assertSame( array( 0, 0, 0 ), array( $adapter->list_calls, $adapter->inspect_calls, $adapter->acquire_calls ), $failure );
				self::assertSame( array(), $database->rows(), $failure );
				self::assertSame( array(), $database->prepared_sql(), $failure );
				$GLOBALS['ran_wp_release_updater_v1_broker'] = $broker;
			}
		}
		public function test_liveness_loss_after_archive_admission_aborts_and_releases_the_persistent_lease(): void {
			$state  = new SelectedRuntimeState();
			$broker = new RequestBroker( false, $state );
			$state->bind( $broker );
			$GLOBALS['ran_wp_release_updater_v1_broker'] = $broker;
			$property                                    = new \ReflectionProperty( $broker, 'state' );
			$property->setValue( $broker, 'active' );
			try {
				list( $updater, , $database, , $binding ) = $this->subject( 'manual', null, 'stable', false, $state );
				$offer                                    = $this->offer( $updater );
				$owned_archive                            = $updater->filter_pre_download( false, $offer['package'], null, $this->extra() );
				self::assertIsString( $owned_archive );

				$GLOBALS['ran_wp_release_updater_v1_broker'] = new \stdClass();
				self::assertInstanceOf( \WP_Error::class, $updater->filter_pre_unzip_file( null, $owned_archive, '/tmp', array(), 0.0 ) );
				self::assertFileDoesNotExist( $owned_archive );
				self::assertSame( 'claimed', BindingFenceCoordinator::claim_persistent_binding_state( $database, $binding, str_repeat( 'f', 64 ), 1 )['result'] );
			} finally {
				unset( $GLOBALS['ran_wp_release_updater_v1_broker'] );
			}
		}
		#[\PHPUnit\Framework\Attributes\DataProvider( 'pending_liveness_loss_callbacks' )]
		public function test_liveness_loss_aborts_every_matching_pending_lifecycle_callback( string $callback ): void {
			$state  = new SelectedRuntimeState();
			$broker = new RequestBroker( false, $state );
			$state->bind( $broker );
			$GLOBALS['ran_wp_release_updater_v1_broker'] = $broker;
			$property                                    = new \ReflectionProperty( $broker, 'state' );
			$property->setValue( $broker, 'active' );
			try {
				list( $updater, , $database, , $binding ) = $this->subject( 'manual', null, 'stable', false, $state );
				$offer                                    = $this->offer( $updater );
				$owned_archive                            = $updater->filter_pre_download( false, $offer['package'], null, $this->extra() );
				self::assertIsString( $owned_archive );
				self::assertNull( $updater->filter_pre_unzip_file( null, $owned_archive, '/tmp', array(), 0.0 ) );

				$GLOBALS['ran_wp_release_updater_v1_broker'] = new \stdClass();
				$result                                      = match ( $callback ) {
					'source-selection' => $updater->filter_source_selection( 'source', '/tmp', null, $this->extra() ),
					'pre-install' => $updater->filter_pre_install( true, $this->extra() ),
					'install-result' => $updater->capture_install_package_result( array(), $this->extra() ),
					default => throw new \UnhandledMatchError(),
				};
				self::assertInstanceOf( \WP_Error::class, $result );
				self::assertFileDoesNotExist( $owned_archive );
				self::assertSame( 'claimed', BindingFenceCoordinator::claim_persistent_binding_state( $database, $binding, str_repeat( 'd', 64 ), 1 )['result'] );
			} finally {
				unset( $GLOBALS['ran_wp_release_updater_v1_broker'] );
			}
		}
		/** @return array<string,array{string}> */
		public static function pending_liveness_loss_callbacks(): array {
			return array(
				'source selection' => array( 'source-selection' ),
				'pre install'      => array( 'pre-install' ),
				'install result'   => array( 'install-result' ),
			);
		}
		public function test_liveness_loss_finalization_and_refresh_clear_pending_archives_and_leases(): void {
			foreach ( array( 'finalize', 'refresh' ) as $path ) {
				$state  = new SelectedRuntimeState();
				$broker = new RequestBroker( false, $state );
				$state->bind( $broker );
				$GLOBALS['ran_wp_release_updater_v1_broker'] = $broker;
				$property                                    = new \ReflectionProperty( $broker, 'state' );
				$property->setValue( $broker, 'active' );
				try {
					list( $updater, , $database, , $binding ) = $this->subject( 'manual', null, 'stable', false, $state );
					$offer                                    = $this->offer( $updater );
					$owned_archive                            = $updater->filter_pre_download( false, $offer['package'], null, $this->extra() );
					self::assertIsString( $owned_archive );
					$status = $updater->status();

					$GLOBALS['ran_wp_release_updater_v1_broker'] = new \stdClass();
					if ( 'finalize' === $path ) {
						$updater->finalize_pending_install();
						self::assertContains( 'runtime_liveness_lost', $updater->diagnostics() );
					} else {
						self::assertFalse( $updater->refresh() );
						self::assertSame( $status, $updater->status() );
					}
					self::assertFileDoesNotExist( $owned_archive );
					self::assertSame( 'claimed', BindingFenceCoordinator::claim_persistent_binding_state( $database, $binding, str_repeat( 'e', 64 ), 1 )['result'] );
				} finally {
					unset( $GLOBALS['ran_wp_release_updater_v1_broker'] );
				}
			}
		}
		#[\PHPUnit\Framework\Attributes\DataProvider( 'missing_descriptor_callbacks' )]
		public function test_missing_descriptor_rejects_pending_lifecycle_and_cleans_up( string $callback, string $code ): void {
			list( $updater, , $database, , $binding ) = $this->subject();
			$offer                                    = $this->offer( $updater );
			$owned_archive                            = $updater->filter_pre_download( false, $offer['package'], null, $this->extra() );
			self::assertIsString( $owned_archive );
			if ( 'pre-unzip' !== $callback ) {
				self::assertNull( $updater->filter_pre_unzip_file( null, $owned_archive, '/tmp', array(), 0.0 ) );
			}
			if ( 'finalize' === $callback ) {
				self::assertTrue( $updater->filter_pre_install( true, $this->extra() ) );
				$staged_package = $this->staged();
				self::assertSame( $staged_package, $updater->filter_source_selection( $staged_package, '/tmp', null, $this->extra() ) );
				$updater->capture_install_package_result( array( 'destination' => $staged_package ), $this->extra() );
				$updater->observe_completion(
					null,
					array(
						'action'  => 'update',
						'type'    => 'plugin',
						'plugins' => array( 'package/package.php' ),
					)
				);
			}
			( new \ReflectionProperty( $updater, 'descriptor' ) )->setValue( $updater, null );
			if ( 'finalize' === $callback ) {
				$updater->finalize_pending_install();
			} else {
				$result = match ( $callback ) {
					'pre-unzip' => $updater->filter_pre_unzip_file( null, $owned_archive, '/tmp', array(), 0.0 ),
					'source-selection' => $updater->filter_source_selection( $this->staged(), '/tmp', null, $this->extra() ),
					'pre-install' => $updater->filter_pre_install( true, $this->extra() ),
					default => throw new \UnhandledMatchError(),
				};
				self::assertInstanceOf( \WP_Error::class, $result );
			}
			self::assertContains( $code, $updater->diagnostics() );
			self::assertNotContains( 'update_completed', $updater->diagnostics() );
			self::assertFileDoesNotExist( $owned_archive );
			self::assertSame( 'claimed', BindingFenceCoordinator::claim_persistent_binding_state( $database, $binding, str_repeat( 'd', 64 ), 1 )['result'] );
		}
		/** @return array<string,array{string,string}> */
		public static function missing_descriptor_callbacks(): array {
			return array(
				'pre unzip'        => array( 'pre-unzip', 'archive_changed_before_extraction' ),
				'source selection' => array( 'source-selection', 'staged_package_identity_invalid' ),
				'pre install'      => array( 'pre-install', 'unverified_pre_install' ),
				'finalize'         => array( 'finalize', 'outcome_uncertain' ),
			);
		}
		public function test_refresh_during_fresh_inspection_rejects_before_reacquisition(): void {
			list( $updater, $adapter, $database, , $binding ) = $this->subject();
			$offer             = $this->offer( $updater );
			$reentrant_adapter = new class( $adapter, $updater ) implements \RAN\WPReleaseUpdater\V1\Contract\ReleaseAdapter {
				public function __construct( private ControllableReleaseAdapter $inner, private NativePackageUpdater $updater ) {}
				/** @return array<string,mixed> */
				public function list_releases( array $conditional = array() ): array {
					return $this->inner->list_releases( $conditional );
				}
				public function inspect( string $release_identity, ?string $expected_tag = null ): IdentityDescriptor {
					$descriptor = $this->inner->inspect( release_identity: $release_identity, expected_tag: $expected_tag );
					$this->updater->refresh();
					return $descriptor;
				}
				public function acquire( IdentityDescriptor $descriptor ): \RAN\WPReleaseUpdater\V1\Archive\TemporaryArtifact {
					return $this->inner->acquire( $descriptor );
				}
			};
			( new \ReflectionProperty( $updater, 'adapter' ) )->setValue( $updater, $reentrant_adapter );
			$result = $updater->filter_pre_download( false, $offer['package'], null, $this->extra() );
			self::assertInstanceOf( \WP_Error::class, $result );
			self::assertContains( 'binding_fence_lost', $updater->diagnostics() );
			self::assertSame( 1, $adapter->acquire_calls );
			self::assertSame( 'claimed', BindingFenceCoordinator::claim_persistent_binding_state( $database, $binding, str_repeat( 'd', 64 ), 1 )['result'] );
		}
		public function test_refresh_clears_diagnostics_and_destroys_pending_owned_archive(): void {
			list( $updater ) = $this->subject();
			$offer           = $this->offer( $updater );
			$owned_archive   = $updater->filter_pre_download( false, $offer['package'], null, $this->extra() );
			self::assertIsString( $owned_archive );
			$updater->refresh();
			self::assertFileDoesNotExist( $owned_archive );
			self::assertSame( array(), $updater->diagnostics() );
		}
		public function test_installed_mutation_cannot_complete_against_the_archive_manifest(): void {
			list( $updater ) = $this->subject();
			$offer           = $this->offer( $updater );
			$owned_archive   = $updater->filter_pre_download( false, $offer['package'], null, $this->extra() );
			self::assertIsString( $owned_archive );
			self::assertNull( $updater->filter_pre_unzip_file( null, $owned_archive, '/tmp', array(), 0.0 ) );
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- Simulate Core consuming the acquired archive; it may already be absent after extraction. Remove native fixture entries directly, preserving the surrounding ownership and link-handling checks.
			@unlink( $owned_archive );
			self::assertTrue( $updater->filter_pre_install( true, $this->extra() ) );
			$staged_package = $this->staged();
			self::assertSame( $staged_package, $updater->filter_source_selection( $staged_package, '/tmp', null, $this->extra() ) );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write exact bytes for installed-package lifecycle fixtures; WordPress helpers would alter the boundary under test.
			file_put_contents( $staged_package . '/package.php', 'changed' );
			$updater->capture_install_package_result( array( 'destination' => $staged_package ), $this->extra() );
			$updater->observe_completion(
				null,
				array(
					'action'  => 'update',
					'type'    => 'plugin',
					'plugins' => array( 'package/package.php' ),
				)
			);
			$updater->finalize_pending_install();
			self::assertContains( 'outcome_uncertain', $updater->diagnostics() );
		}
		public function test_theme_identity_uses_theme_hooks_and_rejects_plugin_style_identity(): void {
			list( $updater, $adapter, $database, , $binding ) = $this->subject();
			$configuration                                    = $this->config( 'manual' );
			$configuration['target_type']                     = 'theme';
			self::assertNull( NativePackageUpdater::from_configuration( $configuration, $binding, $adapter, $database, $this->policy() ) );
		}
		public function test_archive_policy_must_carry_the_exact_binding_template(): void {
			list( , $adapter, $database, , $binding ) = $this->subject();
			$policy                                   = $this->policy();
			$policy['theme_template']                 = 'parent-theme';
			self::assertNull( NativePackageUpdater::from_configuration( $this->config( 'manual' ), $binding, $adapter, $database, $policy ) );
			unset( $policy['theme_template'] );
			self::assertNull( NativePackageUpdater::from_configuration( $this->config( 'manual' ), $binding, $adapter, $database, $policy ) );
		}
		public function test_non_false_pre_download_result_cannot_bypass_release_validation(): void {
			list( $updater, $adapter, $database ) = $this->subject();
			$path                                 = tempnam( $this->temporary_directory, 'ran-unverified-download-' );
			self::assertIsString( $path );
			$this->paths[] = $path;
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Set real fixture permission bits for archive custody and permission-boundary checks.
			chmod( $path, 0600 );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write exact bytes for installed-package lifecycle fixtures; WordPress helpers would alter the boundary under test.
			file_put_contents( $path, 'untrusted archive' );
			$calls = 0;
			$GLOBALS['ran_wp_release_updater_test_filter_callbacks']['ran_wp_release_updater_v1_core_artifact_handoff'] = static function () use ( &$calls ): mixed {
				++$calls;
				return null;
			};
			$result = $updater->filter_pre_download( $path, $path, null, $this->extra() );
			self::assertInstanceOf( \WP_Error::class, $result );
			self::assertSame( 0, $calls );
			self::assertFileExists( $path );
			self::assertSame( array( 0, 0, 0 ), array( $adapter->list_calls, $adapter->inspect_calls, $adapter->acquire_calls ) );
			self::assertSame( array(), $database->rows() );
			self::assertContains( 'unverified_pre_download_result', $updater->diagnostics() );
		}
		#[\PHPUnit\Framework\Attributes\DataProvider( 'theme_staging_cases' )]
		public function test_theme_source_proof_and_staging_marker_complete_the_actual_native_lifecycle( bool $stage_verified, string $mode, bool $automatic_action, bool $replace_skin, bool $failed_result, string $restore_case ): void {
			$archive = $this->archive();
			$zip     = new \ZipArchive();
			self::assertTrue( $zip->open( $archive, \ZipArchive::OVERWRITE ) );
			$contents = "/*\nTheme Name: Package\nVersion: 2.0.0\nUpdate URI: {$this->uri()}\n*/";
			self::assertTrue( $zip->addFromString( 'package/style.css', $contents ) );
			$zip->close();
			$facts = $this->descriptor( $archive )->to_array();
			unset( $facts['fingerprint'] );
			$facts['target_type']                = 'theme';
			$facts['installed_package_identity'] = 'package';
			$descriptor                          = IdentityDescriptor::create( $facts );
			$binding_facts                       = $this->binding( $mode )->to_array();
			unset( $binding_facts['binding_hash'] );
			$binding_facts['target_type']                = 'theme';
			$binding_facts['installed_package_identity'] = 'package';
			$binding                                     = BindingRecord::create( $binding_facts );
			$configuration                               = $this->config( $mode );
			$configuration['target_type']                = 'theme';
			$configuration['installed_package_identity'] = 'package';
			$policy                                      = $this->policy();
			$policy['target_type']                       = 'theme';
			$policy['installed_package_identity']        = 'package';
			$policy['header_file']                       = 'style.css';
			$opened                                      = 0;
			$validator                                   = new PackageIdentityValidator(
				static function ( string $path ) use ( &$opened ): void {
					unset( $path );
					++$opened;
				}
			);
			$adapter                                     = new ControllableReleaseAdapter( $descriptor, $archive, $this->temporary_directory );
			$updater                                     = NativePackageUpdater::from_configuration(
				$configuration,
				$binding,
				$adapter,
				new FakeOptionDatabase( 100 ),
				$policy,
				$validator,
				null,
				false,
				static fn (): bool => true,
				static fn (): string => 'owner',
				static function (): void {}
			);
			self::assertInstanceOf( NativePackageUpdater::class, $updater );
			$updater->register();
			self::assertCount( 9, $GLOBALS['ran_wp_release_updater_test_hooks'] );
			$offer = $updater->filter_update(
				false,
				array(
					'Version'   => '1.0.0',
					'UpdateURI' => $this->uri(),
				),
				'package',
				array()
			);
			self::assertIsArray( $offer );
			$extra = array(
				'theme'  => 'package',
				'action' => 'update',
				'type'   => 'theme',
			);
			$owned = $updater->filter_pre_download( false, $offer['package'], null, $extra );
			self::assertIsString( $owned );
			self::assertNull( $updater->filter_pre_unzip_file( null, $owned, '/tmp', array(), 0.0 ) );
			$stage = $this->temporary_directory . '/package';
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create the real staged theme directory for installed-tree verification with native permissions.
			self::assertTrue( mkdir( $stage, 0700 ) );
			$this->paths[] = $stage;
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Install the exact validated theme fixture bytes; no WordPress filesystem abstraction applies.
			file_put_contents( $stage . '/style.css', $contents );
			self::assertTrue( $updater->filter_pre_install( true, $extra ) );
			if ( $stage_verified ) {
				$stock = new \Theme_Upgrader();
				$GLOBALS['ran_wp_release_updater_test_doing_action'] = $automatic_action ? 'wp_maybe_auto_update' : null;
				self::assertSame( $stage, $updater->filter_source_selection( $stage, '/tmp', $stock, $extra ) );
				self::assertCount( 10, $GLOBALS['ran_wp_release_updater_test_hooks'] );
				if ( $failed_result ) {
					$original      = new \WP_Error( 'post_copy_failed', 'Post-copy failure.' );
					$stock->result = $original;
					if ( $replace_skin ) {
						$stock->skin = new class() extends \Theme_Upgrader_Skin {};
					}
					$outgoing = $updater->capture_install_package_result( $original, $extra );
					self::assertInstanceOf( \WP_Error::class, $outgoing );
					self::assertNotSame( $original, $outgoing );
					$bridged = ! $automatic_action && ! $replace_skin;
					self::assertSame( $bridged ? array() : $original, $stock->result );
					self::assertSame( $bridged ? 11 : 10, count( $GLOBALS['ran_wp_release_updater_test_hooks'] ) );
					if ( $bridged ) {
						self::assertSame(
							array( 'action', 'upgrader_process_complete', array( $updater, 'restore_theme_error_result' ), PHP_INT_MIN, 2 ),
							$GLOBALS['ran_wp_release_updater_test_hooks'][10]
						);
					}
					$stock->skin->result = $outgoing;
					if ( 'wrong_upgrader' === $restore_case ) {
						$updater->restore_theme_error_result(
							new \Theme_Upgrader(),
							array(
								'type'   => 'theme',
								'action' => 'update',
								'themes' => array( 'package' ),
							)
						);
						self::assertSame( array(), $stock->result );
					}
					if ( 'changed_result' === $restore_case ) {
						$stock->result = 'foreign-result';
					}
					if ( 'changed_skin_error' === $restore_case ) {
						$stock->skin->result = new \WP_Error( 'foreign', 'Foreign error.' );
					}
					$updater->restore_theme_error_result(
						$stock,
						array(
							'type'   => 'theme',
							'action' => 'update',
							'themes' => array( 'package' ),
						)
					);
					self::assertSame( 'changed_result' === $restore_case ? 'foreign-result' : ( $bridged && 'changed_skin_error' !== $restore_case ? $original : ( $bridged ? array() : $original ) ), $stock->result );
					if ( 'second_completion' === $restore_case ) {
						$stock->result = 'foreign-result';
						$updater->restore_theme_error_result(
							$stock,
							array(
								'type'   => 'theme',
								'action' => 'update',
								'themes' => array( 'package' ),
							)
						);
						self::assertSame( 'foreign-result', $stock->result );
					}
				} else {
					$updater->capture_install_package_result( array( 'destination' => $stage ), $extra );
				}
				$GLOBALS['ran_wp_release_updater_test_doing_action'] = null;
			} else {
				$updater->capture_install_package_result( array( 'destination' => $stage ), $extra );
			}
			$updater->observe_completion(
				null,
				array(
					'type'   => 'theme',
					'action' => 'update',
					'themes' => array( 'package' ),
				)
			);
			$updater->finalize_pending_install();
			self::assertContains( $stage_verified && ! $failed_result ? 'update_completed' : 'outcome_uncertain', $updater->diagnostics() );
			self::assertSame( $stage_verified && ! $failed_result, in_array( 'update_completed', $updater->diagnostics(), true ) );
			self::assertSame( 2, $opened );
			self::assertFileDoesNotExist( $owned );
		}
		/** @return array<string,array{bool,string,bool,bool,bool,string}> */
		public static function theme_staging_cases(): array {
			return array(
				'genuine theme success'              => array( true, 'manual', false, false, false, 'none' ),
				'manual target manual request'       => array( true, 'manual', false, false, true, 'none' ),
				'automatic target manual request'    => array( true, 'automatic', false, false, true, 'none' ),
				'automatic target automatic request' => array( true, 'automatic', true, false, true, 'none' ),
				'skin changed after admission'       => array( true, 'manual', false, true, true, 'none' ),
				'wrong upgrader completion'          => array( true, 'manual', false, false, true, 'wrong_upgrader' ),
				'changed result refuses restoration' => array( true, 'manual', false, false, true, 'changed_result' ),
				'changed skin error refuses restore' => array( true, 'manual', false, false, true, 'changed_skin_error' ),
				'second completion is disarmed'      => array( true, 'manual', false, false, true, 'second_completion' ),
				'omitted stage'                      => array( false, 'manual', false, false, false, 'none' ),
			);
		}
		public function test_theme_error_bridge_admission_excludes_automatic_and_nonstock_upgraders_or_skins(): void {
			list( $updater ) = $this->subject();
			( new \ReflectionProperty( $updater, 'target_type' ) )->setValue( $updater, 'theme' );
			( new \ReflectionProperty( $updater, 'native_owner' ) )->setValue( $updater, 'owner' );
			$admit = new \ReflectionMethod( $updater, 'admit_theme_error_bridge' );
			$held  = new \ReflectionProperty( $updater, 'theme_error_upgrader' );
			$extra = array( 'action' => 'update' );

			$GLOBALS['ran_wp_release_updater_test_doing_action'] = 'wp_maybe_auto_update';
			$admit->invoke( $updater, new \Theme_Upgrader(), $extra );
			self::assertNull( $held->getValue( $updater ) );
			$GLOBALS['ran_wp_release_updater_test_doing_action'] = null;

			$admit->invoke( $updater, new class() extends \Theme_Upgrader {}, $extra );
			self::assertNull( $held->getValue( $updater ) );
			$nonstock_skin       = new \Theme_Upgrader();
			$nonstock_skin->skin = new class() extends \Theme_Upgrader_Skin {};
			$admit->invoke( $updater, $nonstock_skin, $extra );
			self::assertNull( $held->getValue( $updater ) );
		}
		public function test_rediscovered_same_release_cannot_replace_an_admitted_receipt_authority(): void {
			list( $updater, $adapter, , $descriptor ) = $this->subject();
			$this->prepare_install( $updater );
			$facts = $descriptor->to_array();
			unset( $facts['fingerprint'] );
			$facts['commit_identity']    = 'commit:changed';
			$adapter->inspect_descriptor = IdentityDescriptor::create( $facts );
			$this->offer( $updater );
			$pending = ( new \ReflectionProperty( $updater, 'pending_install' ) )->getValue( $updater );
			self::assertInstanceOf( \RAN\WPReleaseUpdater\V1\WordPress\PendingInstallState::class, $pending );
			self::assertTrue( $pending->staging_verified() );
			$updater->finalize_pending_install();
			self::assertContains( 'outcome_uncertain', $updater->diagnostics() );
			self::assertNotContains( 'update_completed', $updater->diagnostics() );
		}
		public function test_successful_cas_with_changed_readback_preserves_successor_and_uncertain_cleanup(): void {
			list( $updater, , $database, , $binding ) = $this->subject();
			$this->prepare_install( $updater );
			$name = array_key_first( $database->rows() );
			self::assertIsString( $name );
			$successor = null;
			$database->mutate_on_read(
				$name,
				7,
				static function () use ( $database, $binding, &$successor ): void {
					$claim = BindingFenceCoordinator::claim_persistent_binding_state( $database, $binding, str_repeat( 'b', 64 ), 3600 );
					self::assertSame( 'claimed', $claim['result'] );
					$successor = $claim['current'];
				}
			);
			$updater->finalize_pending_install();
			self::assertContains( 'outcome_uncertain', $updater->diagnostics() );
			self::assertNotContains( 'update_completed', $updater->diagnostics() );
			self::assertInstanceOf( BindingState::class, $successor );
			self::assertSame( str_repeat( 'b', 64 ), $successor->owner_token() );
			self::assertSame( 'binding_fence_lost', BindingFenceCoordinator::claim_persistent_binding_state( $database, $binding, str_repeat( 'c', 64 ), 1 )['result'] );
		}
		public function test_confirmed_release_does_not_visit_the_redundant_cleanup_time_failure(): void {
			list( $updater, , $database ) = $this->subject();
			$this->prepare_install( $updater );
			$database->mutate_on_time_read(
				4,
				static function (): void {
					throw new \RuntimeException( 'redundant cleanup time read' );
				}
			);
			$updater->finalize_pending_install();
			self::assertContains( 'update_completed', $updater->diagnostics() );
			self::assertFalse( ( new \ReflectionProperty( $updater, 'lease_held' ) )->getValue( $updater ) );
			$this->expectException( \RuntimeException::class );
			$database->get_var( 'SELECT UNIX_TIMESTAMP()' );
		}
		public function test_fresh_install_reuses_one_source_scan_after_the_persistent_fence(): void {
			$opened          = array();
			$validator       = new PackageIdentityValidator(
				static function ( string $path ) use ( &$opened ): void {
					$opened[] = $path;
				}
			);
			list( $updater ) = $this->subject( 'manual', null, 'stable', false, null, false, $validator );
			$this->complete( $updater );
			self::assertCount( 2, $opened, 'Discovery and fresh acquisition each scan once; no third owned-copy ZIP scan.' );
		}
		public function test_owned_bytes_changed_during_the_persistent_fence_cannot_mint_receipt(): void {
			list( $updater, , $database ) = $this->subject();
			$offer                        = $this->offer( $updater );
			$before                       = glob( sys_get_temp_dir() . '/ran-wp-release-updater-*', GLOB_ONLYDIR );
			$name                         = array_key_first( $database->rows() );
			self::assertIsString( $name );
			$changed = null;
			$database->mutate_on_read(
				$name,
				4,
				static function () use ( $before, &$changed ): void {
					$now     = glob( sys_get_temp_dir() . '/ran-wp-release-updater-*', GLOB_ONLYDIR );
					$created = array_values( array_diff( false === $now ? array() : $now, false === $before ? array() : $before ) );
					self::assertCount( 1, $created );
					$changed = $created[0] . '/package.zip';
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- The owner changes real fixture permissions before replacing bytes to exercise the post-fence custody guard.
					self::assertTrue( chmod( $changed, 0600 ) );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Replace exact owned fixture bytes after its earlier identity observation; no WordPress abstraction preserves this race.
					self::assertSame( 7, file_put_contents( $changed, 'changed' ) );
				}
			);
			$result = $updater->filter_pre_download( false, $offer['package'], null, $this->extra() );
			self::assertInstanceOf( \WP_Error::class, $result );
			self::assertContains( 'acquisition_identity_invalid', $updater->diagnostics() );
			self::assertIsString( $changed );
			self::assertFileDoesNotExist( $changed );
		}
		public function test_new_pending_install_without_staging_cannot_reuse_the_old_marker(): void {
			list( $updater ) = $this->subject();
			$prepared        = $this->prepare_install( $updater );
			$offer           = $this->offer( $updater );
			$owned           = $updater->filter_pre_download( false, $offer['package'], null, $this->extra() );
			self::assertIsString( $owned );
			self::assertNull( $updater->filter_pre_unzip_file( null, $owned, '/tmp', array(), 0.0 ) );
			$updater->capture_install_package_result( array( 'destination' => $prepared['stage'] ), $this->extra() );
			$updater->observe_completion(
				null,
				array(
					'action'  => 'update',
					'type'    => 'plugin',
					'plugins' => array( 'package/package.php' ),
				)
			);
			$updater->finalize_pending_install();
			self::assertContains( 'outcome_uncertain', $updater->diagnostics() );
			self::assertNotContains( 'update_completed', $updater->diagnostics() );
			self::assertFileDoesNotExist( $owned );
		}
		public function test_failed_repeated_staging_clears_the_verified_marker(): void {
			list( $updater ) = $this->subject();
			$prepared        = $this->prepare_install( $updater );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Alter the exact staged fixture after its first verification to exercise repeated-callback refusal.
			file_put_contents( $prepared['stage'] . '/package.php', 'changed' );
			self::assertInstanceOf( \WP_Error::class, $updater->filter_source_selection( $prepared['stage'], '/tmp', null, $this->extra() ) );
			$updater->finalize_pending_install();
			self::assertNotContains( 'update_completed', $updater->diagnostics() );
		}
		#[\PHPUnit\Framework\Attributes\DataProvider( 'completion_release_cases' )]
		public function test_only_confirmed_release_suppresses_the_cleanup_retry( bool $fail_first, int $expected_reads, int $expected_writes, string $code ): void {
			list( $updater, , $database ) = $this->subject();
			$this->prepare_install( $updater );
			$name = array_key_first( $database->rows() );
			self::assertIsString( $name );
			if ( $fail_first ) {
				$database->fail_next_write( $name );
			}
			$before_reads  = count( $database->read_option_names() );
			$before_writes = count( $database->prepared_sql() );
			$updater->finalize_pending_install();
			self::assertSame( $expected_reads, count( $database->read_option_names() ) - $before_reads );
			$sql = array_slice( $database->prepared_sql(), $before_writes );
			self::assertCount( $expected_writes, array_filter( $sql, static fn( string $query ): bool => str_starts_with( $query, 'UPDATE ' ) ) );
			self::assertContains( $code, $updater->diagnostics() );
			$binding = ( new \ReflectionProperty( $updater, 'binding' ) )->getValue( $updater );
			self::assertInstanceOf( BindingRecord::class, $binding );
			self::assertSame( 'claimed', BindingFenceCoordinator::claim_persistent_binding_state( $database, $binding, str_repeat( 'e', 64 ), 1 )['result'] );
		}
		/** @return array<string,array{bool,int,int,string}> */
		public static function completion_release_cases(): array {
			return array(
				'confirmed'        => array( false, 8, 1, 'update_completed' ),
				'failed first CAS' => array( true, 9, 2, 'outcome_uncertain' ),
			);
		}
		#[\PHPUnit\Framework\Attributes\DataProvider( 'invalid_native_owners' )]
		public function test_begin_refusal_never_authorizes_mutation_or_completion( string $scenario ): void {
			$completed       = 0;
			list( $updater ) = $this->subject(
				operation_guard: static fn (): bool => true,
				native_mutation_begin: static function () use ( $scenario ): mixed {
					return match ( $scenario ) {
						'throw' => throw new \RuntimeException( 'Begin failed.' ), 'empty' => '', default => false }; },
				native_mutation_complete: static function () use ( &$completed ): void {
					++$completed; }
			);
			$offer           = $this->offer( $updater );
			$archive         = $updater->filter_pre_download( false, $offer['package'], null, $this->extra() );
			self::assertIsString( $archive );
			self::assertNull( $updater->filter_pre_unzip_file( null, $archive, '/tmp', array(), 0.0 ) );
			self::assertTrue( $updater->filter_pre_install( true, $this->extra() ) );
			self::assertInstanceOf( \WP_Error::class, $updater->filter_source_selection( $this->staged(), '/tmp', null, $this->extra() ) );
			$updater->finalize_pending_install();
			self::assertSame( 0, $completed );
			self::assertFileDoesNotExist( $archive );
		}
		/** @return list<array{string}> */
		public static function invalid_native_owners(): array {
			return array( array( 'throw' ), array( 'empty' ), array( 'nonstring' ) ); }

		public function test_captured_native_completion_survives_provider_loss_and_refresh(): void {
			$available       = true;
			$events          = array();
			list( $updater ) = $this->subject(
				operation_guard: static function () use ( &$available ): bool {
					return $available; },
				native_mutation_begin: static function ( string $type, string $identity ) use ( &$events ): string {
					$events[] = array( 'begin', $type, $identity );
					return 'owner'; },
				native_mutation_complete: static function ( string $owner, string $type, string $identity, string $outcome ) use ( &$events ): void {
					$events[] = array( $owner, $type, $identity, $outcome ); }
			);
			$prepared        = $this->prepare_install( $updater );
			self::assertCount( 1, $events );
			$available = false;
			self::assertFalse( $updater->refresh() );
			$updater->capture_install_package_result( array( 'destination' => $prepared['stage'] ), $this->extra() );
			$updater->finalize_pending_install();
			$updater->finalize_pending_install();
			self::assertSame( array( array( 'begin', 'plugin', 'package/package.php' ), array( 'owner', 'plugin', 'package/package.php', 'verified_success' ) ), $events );
			self::assertFileDoesNotExist( $prepared['archive'] );
			self::assertDirectoryDoesNotExist( dirname( $prepared['archive'] ) );
		}

		public function test_native_completion_failure_never_reports_success(): void {
			$calls           = 0;
			list( $updater ) = $this->subject(
				operation_guard: static fn (): bool => true,
				native_mutation_begin: static fn (): string => 'owner',
				native_mutation_complete: static function () use ( &$calls ): void {
					++$calls;
					throw new \RuntimeException( 'Marker retained.' ); }
			);
			$prepared        = $this->prepare_install( $updater );
			$updater->finalize_pending_install();
			$updater->finalize_pending_install();
			self::assertSame( 1, $calls );
			self::assertNotContains( 'update_completed', $updater->diagnostics() );
			self::assertSame( 'outcome_uncertain', $updater->status()['failure_code'] );
			self::assertFileDoesNotExist( $prepared['archive'] );
		}

		public function test_failed_native_result_defers_uncertain_callback_until_finalization(): void {
			$outcomes        = array();
			list( $updater ) = $this->subject(
				operation_guard: static fn (): bool => true,
				native_mutation_begin: static fn (): string => 'owner',
				native_mutation_complete: static function ( string $owner, string $type, string $identity, string $outcome ) use ( &$outcomes ): void {
					$outcomes[] = array( $owner, $type, $identity, $outcome ); }
			);
			$prepared        = $this->prepare_install( $updater );
			$updater->capture_install_package_result( false, $this->extra() );
			self::assertSame( array(), $outcomes );
			$updater->finalize_pending_install();
			self::assertSame( array( array( 'owner', 'plugin', 'package/package.php', 'uncertain' ) ), $outcomes );
			self::assertFileDoesNotExist( $prepared['archive'] );
		}

		public function test_verified_install_with_failed_private_cleanup_reports_uncertain(): void {
			$outcomes        = array();
			list( $updater ) = $this->subject(
				operation_guard: static fn (): bool => true,
				native_mutation_begin: static fn (): string => 'owner',
				native_mutation_complete: static function ( string $owner, string $type, string $identity, string $outcome ) use ( &$outcomes ): void {
					$outcomes[] = array( $owner, $type, $identity, $outcome ); }
			);
			$prepared        = $this->prepare_install( $updater );
			$directory       = dirname( $prepared['archive'] );
			$this->paths[]   = $directory;
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- A foreign leftover in the owned fixture directory prevents verified cleanup.
			file_put_contents( $directory . '/leftover', 'fixture' );
			$updater->finalize_pending_install();
			self::assertSame( array( array( 'owner', 'plugin', 'package/package.php', 'uncertain' ) ), $outcomes );
			self::assertNotContains( 'update_completed', $updater->diagnostics() );
		}

		public function test_unavailable_guard_rejects_preexisting_offer_before_provider_io(): void {
			list( $updater, $adapter ) = $this->subject(
				operation_guard: static fn (): bool => false,
				native_mutation_begin: static fn (): string => 'owner',
				native_mutation_complete: static function (): void {}
			);
			self::assertFalse( $updater->filter_update( array( 'package' => 'cached' ), array(), 'package/package.php', array() ) );
			self::assertInstanceOf( \WP_Error::class, $updater->filter_pre_download( false, 'cached', null, $this->extra() ) );
			self::assertInstanceOf( \WP_Error::class, $updater->filter_source_selection( '/prepared', '/tmp', null, $this->extra() ) );
			self::assertSame( 0, $adapter->list_calls + $adapter->inspect_calls + $adapter->acquire_calls );
		}

		/** @return array{NativePackageUpdater,ControllableReleaseAdapter,FakeOptionDatabase,IdentityDescriptor,BindingRecord} */
		private function subject( string $mode = 'manual', ?FakeOptionDatabase $database = null, string $channel = 'stable', bool $prerelease = false, ?SelectedRuntimeState $selected_runtime_state = null, bool $native_discovery_reuse = false, ?PackageIdentityValidator $validator = null, ?callable $operation_guard = null, ?callable $native_mutation_begin = null, ?callable $native_mutation_complete = null, ?callable $descriptor_admission = null ): array {
			$archive_path = $this->archive();
			$descriptor   = $this->descriptor( $archive_path, $channel, $prerelease );
			$binding      = $this->binding( $mode, $channel );
			$adapter      = new ControllableReleaseAdapter( $descriptor, $archive_path, $this->temporary_directory );
			$database   ??= new FakeOptionDatabase( 100 );
			$updater      = NativePackageUpdater::from_configuration( $this->config( $mode ), $binding, $adapter, $database, $this->policy(), $validator, $selected_runtime_state, $native_discovery_reuse, $operation_guard, $native_mutation_begin, $native_mutation_complete, $descriptor_admission );
			self::assertInstanceOf( NativePackageUpdater::class, $updater );
			return array( $updater, $adapter, $database, $descriptor, $binding );
		}
		/** @return array<string,mixed> */
		private function offer( NativePackageUpdater $updater ): array {
			$offer = $updater->filter_update(
				false,
				array(
					'Version'   => '1.0.0',
					'UpdateURI' => $this->uri(),
				),
				'package/package.php',
				array()
			);
			self::assertIsArray( $offer );
			return $offer;
		}
		/** @return array{archive:string,stage:string} */
		private function prepare_install( NativePackageUpdater $updater ): array {
			$offer         = $this->offer( $updater );
			$owned_archive = $updater->filter_pre_download( false, $offer['package'], null, $this->extra() );
			self::assertIsString( $owned_archive );
			self::assertNull( $updater->filter_pre_unzip_file( null, $owned_archive, '/tmp', array(), 0.0 ) );
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- Simulate Core consuming the acquired archive; it may already be absent after extraction. Remove native fixture entries directly, preserving the surrounding ownership and link-handling checks.
			@unlink( $owned_archive );
			self::assertTrue( $updater->filter_pre_install( true, $this->extra() ) );
			$staged_package = $this->staged();
			self::assertSame( $staged_package, $updater->filter_source_selection( $staged_package, '/tmp', null, $this->extra() ) );
			$updater->capture_install_package_result( array( 'destination' => $staged_package ), $this->extra() );
			$updater->observe_completion(
				null,
				array(
					'action'  => 'update',
					'type'    => 'plugin',
					'plugins' => array( 'package/package.php' ),
				)
			);
			return array(
				'archive' => $owned_archive,
				'stage'   => $staged_package,
			);
		}
		private function complete( NativePackageUpdater $updater ): void {
			$this->prepare_install( $updater );
			$updater->finalize_pending_install();
			self::assertContains( 'update_completed', $updater->diagnostics() );
			self::assertNull( $updater->status()['failure_code'] );
		}
		/** @return array<string,string> */
		private function extra(): array {
			return array( 'plugin' => 'package/package.php' );
		}
		private function archive(): string {
			$archive_path = tempnam( $this->temporary_directory, 'ran-native-' );
			self::assertIsString( $archive_path );
			$this->paths[] = $archive_path;
			$archive       = new \ZipArchive();
			self::assertTrue( $archive->open( $archive_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) );
			$archive->addFromString( 'package/package.php', "<?php\n/*\nPlugin Name: Package\nVersion: 2.0.0\nUpdate URI: {$this->uri()}\n*/" );
			$archive->close();
			return $archive_path;
		}
		private function staged(): string {
			$parent_path = $this->temporary_directory . '/ran-native-stage-' . bin2hex( random_bytes( 5 ) );
			$staged_path = $parent_path . '/package';
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create real directories for installed-package lifecycle fixtures with the specified permissions.
			mkdir( $staged_path, 0700, true );
			$this->paths[] = $parent_path;
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write exact bytes for installed-package lifecycle fixtures; WordPress helpers would alter the boundary under test.
			file_put_contents( $staged_path . '/package.php', "<?php\n/*\nPlugin Name: Package\nVersion: 2.0.0\nUpdate URI: {$this->uri()}\n*/" );
			return $staged_path;
		}
		/** @return array<string,mixed> */
		private function config( string $mode ): array {
			return array(
				'headers'                    => array(
					'Author'      => 'A',
					'Description' => 'D',
					'Name'        => 'Package',
					'PluginURI'   => $this->uri(),
					'RequiresPHP' => '8.2',
					'RequiresWP'  => '6.8',
					'UpdateURI'   => $this->uri(),
					'Version'     => '1.0.0',
				),
				'installed_package_identity' => 'package/package.php',
				'policy'                     => $mode,
				'target_type'                => 'plugin',
				'update_uri'                 => $this->uri(),
			);
		}
		/** @return array<string,int|string> */
		private function policy(): array {
			return array(
				'archive_root'               => 'package',
				'configuration_update_uri'   => $this->uri(),
				'header_file'                => 'package.php',
				'installed_package_identity' => 'package/package.php',
				'maximum_artifact_bytes'     => 52428800,
				'metadata_name'              => 'Package',
				'offer_update_uri'           => $this->uri(),
				'php_runtime_version'        => '8.2',
				'provider_code'              => 'neutral',
				'repository_identity'        => 'repo:1',
				'repository_locator'         => 'owner/package',
				'staged_package_update_uri'  => $this->uri(),
				'target_type'                => 'plugin',
				'theme_template'             => '',
				'wordpress_runtime_version'  => '6.8',
			);
		}
		private function binding( string $mode, string $channel = 'stable' ): BindingRecord {
			return BindingRecord::create(
				array(
					'canonical_repository_locator' => 'owner/package',
					'canonical_update_uri'         => $this->uri(),
					'installed_package_identity'   => 'package/package.php',
					'maximum_artifact_bytes'       => 52428800,
					'network_id'                   => 1,
					'php_runtime_version'          => '8.2',
					'provider_code'                => 'neutral',
					'release_channel'              => $channel,
					'stable_repository_identity'   => 'repo:1',
					'target_type'                  => 'plugin',
					'theme_template'               => '',
					'update_policy'                => $mode,
					'wordpress_runtime_version'    => '6.8',
				)
			);
		}
		private function descriptor( string $archive_path, string $channel = 'stable', bool $prerelease = false ): IdentityDescriptor {
			return IdentityDescriptor::create(
				array(
					'artifact_filename'          => 'package.zip',
					'artifact_identity'          => 'asset:2',
					'artifact_sha256'            => hash_file( 'sha256', $archive_path ),
					'artifact_size'              => filesize( $archive_path ),
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
					'canonical_update_uri'       => $this->uri(),
					'channel'                    => $channel,
					'commit_identity'            => 'commit:2',
					'installed_package_identity' => 'package/package.php',
					'prerelease'                 => $prerelease,
					'provider_code'              => 'neutral',
					'release_identity'           => 'release:2',
					'repository_identity'        => 'repo:1',
					'repository_locator'         => 'owner/package',
					'tag'                        => 'v2.0.0',
					'target_type'                => 'plugin',
					'version'                    => '2.0.0',
				)
			);
		}
		/** @return array{release_identity:string,tag:string,version:string} */
		private function candidate( IdentityDescriptor $descriptor ): array {
			$facts = $descriptor->to_array();
			return array(
				'release_identity' => $facts['release_identity'],
				'tag'              => $facts['tag'],
				'version'          => $facts['version'],
			);
		}
		private function with_release_identity( IdentityDescriptor $descriptor, string $release_identity, string $tag ): IdentityDescriptor {
			$facts = $descriptor->to_array();
			unset( $facts['fingerprint'] );
			$facts['release_identity'] = $release_identity;
			$facts['tag']              = $tag;
			return IdentityDescriptor::create( $facts );
		}
		private function token( IdentityDescriptor $descriptor, BindingRecord $binding ): string {
			$value = array(
				'binding_hash' => $binding->binding_hash(),
				'descriptor'   => $descriptor->to_array(),
				'schema'       => 1,
			);
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode, WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Encode/decode the existing opaque operation-token format for contract and malformed-token tests. Native JSON preserves protocol or fixture bytes without requiring WordPress helpers or their fallback behavior.
			return 'ran-wp-release-updater:v1:' . rtrim( strtr( base64_encode( json_encode( $value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES ) ), '+/', '-_' ), '=' );
		}
		private function uri(): string {
			return 'https://updates.example.test/owner/package';
		}
	}
}
