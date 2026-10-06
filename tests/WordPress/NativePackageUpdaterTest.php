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
	if ( ! function_exists( 'get_filesystem_method' ) ) {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress calls this global stub by its exact foreign function name.
		function get_filesystem_method(): string {
			return 'direct';
		}
	}
}

// phpcs:ignore Universal.Namespaces.OneDeclarationPerFile.MultipleFound, Universal.Namespaces.DisallowCurlyBraceSyntax.Forbidden, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- This fixture colocates foreign global stubs and namespaced test or injected provider seams. Keep global WordPress stubs and namespaced test code in the same isolated fixture. Existing Composer development namespace; this allowance does not apply to production declarations.
namespace Tests\WordPress {
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
	use Tests\Support\ControllableReleaseAdapter;
	use Tests\Support\FakeOptionDatabase;
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
			$GLOBALS['ran_wp_release_updater_test_hooks']            = array();
			$GLOBALS['ran_wp_release_updater_test_filter_callbacks'] = array();
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
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encode/decode the existing opaque operation-token format for contract and malformed-token tests.
				strtr( base64_encode( json_encode( $binding_facts, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES ) ), '+/', '-_' ),
				'='
			);
			$fingerprint_facts                          = json_decode( $decoded_token, true, 32, JSON_THROW_ON_ERROR );
			$fingerprint_facts['descriptor']['version'] = '2.0.1';
			$tampered_fingerprint_token                 = 'ran-wp-release-updater:v1:' . rtrim(
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encode/decode the existing opaque operation-token format for contract and malformed-token tests.
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
		/** @return array{NativePackageUpdater,ControllableReleaseAdapter,FakeOptionDatabase,IdentityDescriptor,BindingRecord} */
		private function subject( string $mode = 'manual', ?FakeOptionDatabase $database = null, string $channel = 'stable', bool $prerelease = false, ?SelectedRuntimeState $selected_runtime_state = null, bool $native_discovery_reuse = false, ?PackageIdentityValidator $validator = null ): array {
			$archive_path = $this->archive();
			$descriptor   = $this->descriptor( $archive_path, $channel, $prerelease );
			$binding      = $this->binding( $mode, $channel );
			$adapter      = new ControllableReleaseAdapter( $descriptor, $archive_path, $this->temporary_directory );
			$database   ??= new FakeOptionDatabase( 100 );
			$updater      = NativePackageUpdater::from_configuration( $this->config( $mode ), $binding, $adapter, $database, $this->policy(), $validator, $selected_runtime_state, $native_discovery_reuse );
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
		private function complete( NativePackageUpdater $updater ): void {
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
		/** @return array<string,string> */
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
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encode/decode the existing opaque operation-token format for contract and malformed-token tests.
			return 'ran-wp-release-updater:v1:' . rtrim( strtr( base64_encode( json_encode( $value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES ) ), '+/', '-_' ), '=' );
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
		private function uri(): string {
			return 'https://updates.example.test/owner/package';
		}
	}
}
