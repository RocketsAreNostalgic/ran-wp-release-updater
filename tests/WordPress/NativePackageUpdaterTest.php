<?php
declare(strict_types=1);
// phpcs:ignore Universal.Namespaces.DisallowCurlyBraceSyntax.Forbidden, Universal.Namespaces.DisallowDeclarationWithoutName.Forbidden -- Keep global WordPress stubs and namespaced test code in the same isolated fixture. WordPress stubs must be declared in the global namespace used by production calls.
namespace {
	if ( ! class_exists( 'WP_Error' ) ) {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound, Generic.Classes.DuplicateClassName.Found -- This stub must occupy the WordPress global class identity used by production calls. The same foreign stub name is reused behind conditional or isolated-process load boundaries.
		final class WP_Error {
			public function __construct( public string $code, public string $message ) {}
			public function get_error_code(): string {
				return $this->code;
			}
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
	if ( ! function_exists( 'remove_action' ) ) {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress calls this global stub by its exact foreign function name.
		function remove_action( string $hook, mixed $callback, int $priority = 10 ): bool {
			$GLOBALS['ran_wp_release_updater_test_removed_hooks'][] = array( 'action', $hook, $callback, $priority );
			return true;
		}
	}
	if ( ! function_exists( 'remove_filter' ) ) {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress calls this global stub by its exact foreign function name.
		function remove_filter( string $hook, mixed $callback, int $priority = 10 ): bool {
			$GLOBALS['ran_wp_release_updater_test_removed_hooks'][] = array( 'filter', $hook, $callback, $priority );
			return true;
		}
	}
	if ( ! function_exists( 'get_current_blog_id' ) ) {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Exercise the native WordPress site identity seam.
		function get_current_blog_id(): int {
			return is_int( $GLOBALS['ran_wp_release_updater_test_blog_id'] ?? null ) ? $GLOBALS['ran_wp_release_updater_test_blog_id'] : 1;
		}
	}
	if ( ! function_exists( 'delete_site_transient' ) ) {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Exercise the native WordPress update-check cache seam.
		function delete_site_transient( string $transient ): bool {
			$GLOBALS['ran_wp_release_updater_test_deleted_transients'][] = $transient;
			return true;
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
	use RAN\WPReleaseUpdater\V1\Contract\IdentityDescriptor;
	use RAN\WPReleaseUpdater\V1\Runtime\RequestBroker;
	use RAN\WPReleaseUpdater\V1\Runtime\ReleaseFailure;
	use RAN\WPReleaseUpdater\V1\Runtime\SelectedRuntimeState;
	use RAN\WPReleaseUpdater\V1\WordPress\NativePackageUpdater;
	use RAN\WPReleaseUpdater\V1\WordPress\WordPressUpdaterLock;
	use RAN\WPReleaseUpdater\V1\Tests\Support\ControllableReleaseAdapter;
	use RAN\WPReleaseUpdater\V1\Tests\Support\FakeOptionDatabase;
	// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- Keep the conditional WordPress stub and its test class in the same self-contained fixture.
	final class NativePackageUpdaterTest extends TestCase {
		/** @var list<string> */
		private array $paths = array();
		/** @var list<string> */
		private array $links = array();
		private string $temporary_directory;

		#[\PHPUnit\Framework\Attributes\DataProvider( 'reported_destination_cases' )]
		public function test_ordinary_native_install_verifies_the_resolved_reported_destination( string $route, string $outcome ): void {
			list( $updater ) = $this->subject();
			$prepared        = $this->prepare_install( $updater, null, $route );
			$updater->finalize_pending_install();
			$verified = 'verified_success' === $outcome;
			self::assertSame( $verified, ( new \ReflectionMethod( $updater, 'installed_destination_matches' ) )->invoke( $updater, $prepared['destination'] ) );
			self::assertSame( $verified, in_array( 'update_completed', $updater->diagnostics(), true ) );
			self::assertSame( ! $verified, in_array( 'outcome_uncertain', $updater->diagnostics(), true ) );
			self::assertSame( $verified ? null : 'outcome_uncertain', $updater->status()['failure_code'] );
			self::assertSame( $outcome, $this->retained_outcome( $updater )['outcome'] );
			self::assertFileExists( $this->installed_directory() . '/package.php' );
		}
		/** @return array<string,array{string,string}> */
		public static function reported_destination_cases(): array {
			return array(
				'registered destination'        => array( 'registered', 'verified_success' ),
				'symlinked plugin root'         => array( 'symlinked_root', 'verified_success' ),
				'alternate same-slug directory' => array( 'alternate_directory', 'uncertain' ),
				'symlinked package leaf'        => array( 'symlinked_leaf', 'uncertain' ),
			);
		}

		public function test_acquisition_failure_is_retained_in_status_releases_lock_and_is_replaced_by_verified_success(): void {
			list( $updater, $adapter, $database )   = $this->subject();
			$offer                                  = $this->offer( $updater );
			$adapter->acquire_outcomes['release:2'] = new \RuntimeException( 'Private transport detail.' );
			self::assertInstanceOf( \WP_Error::class, $updater->filter_pre_download( false, $offer['package'], null, $this->extra() ) );
			self::assertSame( array(), $database->rows() );
			$updater->finalize_pending_install();
			$failed = $this->retained_outcome( $updater );
			self::assertSame( 'acquisition_failed', $failed['outcome'] );
			self::assertSame( 'release_updater', $failed['source'] );
			self::assertNull( $failed['wordpress_code'] );
			self::assertSame( 'release:2', $failed['release_identity'] );
			self::assertSame( '2.0.0', $failed['version'] );
			self::assertNull( $failed['last_observed_step'] );
			self::assertIsInt( $failed['recorded_at'] );
			$updater->refresh();
			self::assertSame( 'acquisition_failed', $this->retained_outcome( $updater )['outcome'] );
			unset( $adapter->acquire_outcomes['release:2'] );
			$this->complete( $updater );
			$after = $this->retained_outcome( $updater );
			self::assertSame( 'verified_success', $after['outcome'] );
			self::assertSame( 'completion_observed', $after['last_observed_step'] );
			self::assertIsInt( $after['recorded_at'] );
			self::assertSame( array(), $database->rows() );
		}

		public function test_native_wordpress_reference_is_unchanged_screened_and_never_includes_message(): void {
			foreach ( array(
				'copy_failed_copy_dir' => 'copy_failed_copy_dir',
				'future.error-code'    => 'future.error-code',
				'secret_orchid'        => null,
				'ghp_example'          => null,
				'token_expired'        => null,
			) as $code => $expected ) {
				list( $updater, , $database ) = $this->subject();
				$this->prepare_install( $updater );
				$updater->capture_install_package_result( new \WP_Error( $code, 'Private message and path must not persist.' ), $this->extra() );
				$updater->finalize_pending_install();
				$record = $this->retained_outcome( $updater );
				self::assertSame( 'unverified_install_result', $record['outcome'] );
				// phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText -- Preserve the exact lowercase source wire value.
				self::assertSame( 'wordpress', $record['source'] );
				self::assertSame( $expected, $record['wordpress_code'] );
				self::assertSame( array( 'outcome', 'source', 'wordpress_code', 'release_identity', 'version', 'last_observed_step', 'recorded_at' ), array_keys( $record ) );
				self::assertSame( array(), $database->rows() );
			}
		}

		public function test_unobserved_unpack_termination_keeps_uncertainty_and_last_observed_step(): void {
			foreach ( array( false, true ) as $extraction_observed ) {
				list( $updater, , $database ) = $this->subject();
				$offer                        = $this->offer( $updater );
				$archive                      = $updater->filter_pre_download( false, $offer['package'], null, $this->extra() );
				self::assertIsString( $archive );
				if ( $extraction_observed ) {
					$updater->filter_pre_unzip_file( null, $archive, '/tmp', array(), 0.0 );
				}
				$updater->finalize_pending_install();
				$record = $this->retained_outcome( $updater );
				self::assertSame( 'uncertain', $record['outcome'] );
				self::assertSame( 'release_updater', $record['source'] );
				self::assertNull( $record['wordpress_code'] );
				self::assertSame( $extraction_observed ? 'extraction_admitted' : 'archive_admitted', $record['last_observed_step'] );
				self::assertSame( array(), $database->rows() );
			}
		}

		public function test_background_fatal_result_is_attributed_only_after_observed_install(): void {
			list( $updater, , $database ) = $this->subject( 'automatic' );
			$this->prepare_install( $updater );
			$updater->observe_automatic_results(
				array(
					'plugin' => array(
						(object) array(
							'item'   => (object) array( 'plugin' => 'ordinary/ordinary.php' ),
							'result' => new \WP_Error( 'unrelated', 'Private.' ),
						),
					),
				)
			);
			$updater->observe_automatic_results(
				array(
					'plugin' => array(
						(object) array(
							'item'   => (object) array( 'plugin' => 'package/package.php' ),
							'result' => new \WP_Error( 'plugin_update_fatal_error_rollback_failed', 'Private.' ),
						),
					),
				)
			);
			$updater->finalize_pending_install();
			// phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText -- Preserve the exact lowercase source wire value.
			self::assertSame( 'wordpress', $this->retained_outcome( $updater )['source'] );
			self::assertSame( 'plugin_update_fatal_error_rollback_failed', $this->retained_outcome( $updater )['wordpress_code'] );
			self::assertNotSame( 'verified_success', $this->retained_outcome( $updater )['outcome'] );
			self::assertSame( array(), $database->rows() );
		}

		public function test_core_automatic_error_prevents_host_success_when_candidate_bytes_remain(): void {
			$reported        = array();
			list( $updater ) = $this->subject(
				'automatic',
				operation_guard: static fn(): bool => true,
				native_mutation_begin: static fn(): string => 'owner',
				native_mutation_complete: static function ( string $owner, string $type, string $identity, string $outcome ) use ( &$reported ): void {
					unset( $owner, $type, $identity );
					$reported[] = $outcome;
				}
			);
			$this->prepare_install( $updater );
			$updater->observe_automatic_results(
				array(
					'plugin' => array(
						(object) array(
							'item'   => (object) array( 'plugin' => 'package/package.php' ),
							'result' => new \WP_Error( 'plugin_update_fatal_error_rollback_failed', 'Private.' ),
						),
					),
				)
			);
			$updater->finalize_pending_install();
			$updater->finalize_pending_install();
			self::assertSame( array( 'uncertain' ), $reported );
			self::assertFileExists( $this->installed_directory() . '/package.php' );
			self::assertSame( 'plugin_update_fatal_error_rollback_failed', $this->retained_outcome( $updater )['wordpress_code'] );
			self::assertNotContains( 'update_completed', $updater->diagnostics() );
		}

		public function test_pre_install_and_source_errors_keep_the_foreign_reference_before_wrapping(): void {
			foreach ( array( 'pre_install', 'source' ) as $stage ) {
				list( $updater, , $database ) = $this->subject();
				$offer                        = $this->offer( $updater );
				$archive                      = $updater->filter_pre_download( false, $offer['package'], null, $this->extra() );
				self::assertIsString( $archive );
				$updater->filter_pre_unzip_file( null, $archive, '/tmp', array(), 0.0 );
				$error  = new \WP_Error( 'foreign_guard_refused', 'Private detail.' );
				$result = 'pre_install' === $stage ? $updater->filter_pre_install( $error, $this->extra() ) : $updater->filter_source_selection( $error, '/tmp', null, $this->extra() );
				self::assertInstanceOf( \WP_Error::class, $result );
				self::assertNotSame( $error, $result );
				$updater->finalize_pending_install();
				self::assertSame( 'foreign_guard_refused', $this->retained_outcome( $updater )['wordpress_code'] );
				self::assertSame( 'extraction_admitted', $this->retained_outcome( $updater )['last_observed_step'] );
				self::assertSame( array(), $database->rows() );
			}
		}

		public function test_opaque_reference_uses_the_same_64_byte_screen_without_normalising(): void {
			$maximum = str_repeat( 'g_', 31 ) . 'gg';
			foreach ( array(
				$maximum                               => $maximum,
				$maximum . 'g'                         => null,
				'future.error-code'                    => 'future.error-code',
				' mixed_code'                          => null,
				'ran_wp_release_updater_foreign_error' => 'ran_wp_release_updater_foreign_error',
			) as $code => $expected ) {
				list( $updater, , $database ) = $this->subject();
				$this->prepare_install( $updater );
				$updater->capture_install_package_result( new \WP_Error( $code, 'Private detail.' ), $this->extra() );
				self::assertSame( $expected, $this->retained_outcome( $updater )['wordpress_code'] );
				// phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText -- Preserve the exact lowercase source wire value.
				self::assertSame( 'wordpress', $this->retained_outcome( $updater )['source'] );
				self::assertSame( array(), $database->rows() );
			}
		}

		public function test_working_copy_refusal_retains_the_offered_release_without_acquiring_it(): void {
			list( $updater, $adapter, $database ) = $this->subject();
			$offer                                = $this->offer( $updater );
			$this->working_copy_marker();
			$calls = $adapter->acquire_calls;
			$updater->filter_pre_download( false, $offer['package'], null, $this->extra() );
			self::assertSame( 'target_is_working_copy', $this->retained_outcome( $updater )['outcome'] );
			self::assertSame( 'release:2', $this->retained_outcome( $updater )['release_identity'] );
			self::assertNull( $this->retained_outcome( $updater )['last_observed_step'] );
			self::assertSame( $calls, $adapter->acquire_calls );
			self::assertSame( array(), $database->rows() );
		}

		public function test_automatic_observation_is_passive_after_authority_loss_without_a_native_owner(): void {
			$live                         = true;
			list( $updater, , $database ) = $this->subject(
				operation_guard: static function () use ( &$live ): bool {
					return $live;
				}
			);
			$this->prepare_install( $updater );
			$live = false;
			$updater->observe_automatic_results(
				array(
					'plugin' => array(
						(object) array(
							'item'   => (object) array( 'plugin' => 'package/package.php' ),
							'result' => new \WP_Error( 'late_foreign_error', 'Private.' ),
						),
					),
				)
			);
			$updater->finalize_pending_install();
			self::assertSame( 'release_updater', $this->retained_outcome( $updater )['source'] );
			self::assertSame( 'runtime_liveness_lost', $this->retained_outcome( $updater )['outcome'] );
			self::assertSame( array(), $database->rows() );
		}

		public function test_standalone_automatic_update_reuses_core_lock_and_fails_closed_when_absent_or_corrupt(): void {
			$GLOBALS['ran_wp_release_updater_test_doing_action'] = 'wp_maybe_auto_update';
			// Absent Core lock fails closed without writing a lock row.
			list( $missing, $missing_adapter, $missing_db ) = $this->subject( 'automatic' );
			$offer = $this->offer( $missing );
			$reply = $missing->filter_pre_download( false, $offer['package'], null, $this->extra() );
			self::assertInstanceOf( \WP_Error::class, $reply );
			self::assertSame( 'ran_wp_release_updater_binding_fence_lost', $reply->get_error_code() );
			self::assertSame( array(), $missing_db->rows() );
			self::assertSame( 0, $missing_adapter->acquire_calls );

			// Corrupt Core lock fails closed without modifying the row.
			list( $corrupt, , $corrupt_db ) = $this->subject( 'automatic' );
			$corrupt_offer                  = $this->offer( $corrupt );
			$corrupt_db->seed_option( WordPressUpdaterLock::OPTION_NAME, 'corrupt-value', 'off' );
			$corrupt_reply = $corrupt->filter_pre_download( false, $corrupt_offer['package'], null, $this->extra() );
			self::assertInstanceOf( \WP_Error::class, $corrupt_reply );
			self::assertSame( 'corrupt-value', $corrupt_db->rows()[ WordPressUpdaterLock::OPTION_NAME ]['option_value'] );

			// Active Core integer lock is reused and NEVER deleted on finalization.
			list( $auto, , $auto_db ) = $this->subject( 'automatic' );
			$core_token               = (string) time();
			$auto_db->seed_option( WordPressUpdaterLock::OPTION_NAME, $core_token, 'off' );
			$this->complete( $auto );
			self::assertSame( $core_token, $auto_db->rows()[ WordPressUpdaterLock::OPTION_NAME ]['option_value'] );
		}

		public function test_borrowed_lock_automatic_install_finalizes_during_automatic_updates_complete_before_core_releases_lock(): void {
			$GLOBALS['ran_wp_release_updater_test_doing_action']  = 'wp_maybe_auto_update';
			$GLOBALS['ran_wp_release_updater_test_removed_hooks'] = array();
			list( $updater, , $database )                         = $this->subject( 'automatic' );
			$core_token = (string) time();
			$database->seed_option( WordPressUpdaterLock::OPTION_NAME, $core_token, 'off' );

			$this->prepare_install( $updater );

			// Core fires automatic_updates_complete while auto_updater.lock is still held.
			$updater->observe_automatic_results(
				array(
					'plugin' => array(
						(object) array(
							'item'   => (object) array( 'plugin' => 'package/package.php' ),
							'result' => true,
						),
					),
				)
			);

			// Finalization succeeded synchronously before Core deleted auto_updater.lock, leaving Core's lock intact.
			self::assertSame( $core_token, $database->rows()[ WordPressUpdaterLock::OPTION_NAME ]['option_value'] );
			self::assertSame( 'verified_success', $this->retained_outcome( $updater )['outcome'] );
			self::assertContains( 'update_completed', $updater->diagnostics() );
			self::assertNull( $updater->status()['failure_code'] );
			$headers = ( new \ReflectionProperty( NativePackageUpdater::class, 'headers' ) )->getValue( $updater );
			self::assertSame( '2.0.0', $headers['Version'] );
			self::assertSame(
				array(
					array( 'action', 'shutdown', array( $updater, 'finalize_pending_install' ), PHP_INT_MAX ),
				),
				$GLOBALS['ran_wp_release_updater_test_removed_hooks']
			);

			// Core releases auto_updater.lock before returning, and PHP shutdown runs later as a no-op.
			$database->delete_option( WordPressUpdaterLock::OPTION_NAME );
			$GLOBALS['ran_wp_release_updater_test_doing_action'] = 'shutdown';
			$updater->finalize_pending_install();

			self::assertSame( array(), $database->rows() );
			self::assertSame( 'verified_success', $this->retained_outcome( $updater )['outcome'] );
			self::assertNull( $updater->status()['failure_code'] );
		}

		public function test_borrowed_lock_automatic_install_records_wordpress_error_when_automatic_updates_complete_reports_failure(): void {
			$GLOBALS['ran_wp_release_updater_test_doing_action']  = 'wp_maybe_auto_update';
			$GLOBALS['ran_wp_release_updater_test_removed_hooks'] = array();
			list( $updater, , $database )                         = $this->subject( 'automatic' );
			$core_token = (string) time();
			$database->seed_option( WordPressUpdaterLock::OPTION_NAME, $core_token, 'off' );

			$this->prepare_install( $updater );

			// Core (or rollback) attaches a WP_Error result before firing automatic_updates_complete while lock is held.
			$updater->observe_automatic_results(
				array(
					'plugin' => array(
						(object) array(
							'item'   => (object) array( 'plugin' => 'package/package.php' ),
							'result' => new \WP_Error( 'plugin_update_fatal_error_rollback_failed', 'Private.' ),
						),
					),
				)
			);

			// Core's lock remains intact; outcome is recorded as unverified_install_result with source 'WordPress'.
			self::assertSame( $core_token, $database->rows()[ WordPressUpdaterLock::OPTION_NAME ]['option_value'] );
			$record = $this->retained_outcome( $updater );
			self::assertSame( 'unverified_install_result', $record['outcome'] );
			// phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText -- Preserve the exact lowercase source wire value.
			self::assertSame( 'wordpress', $record['source'] );
			self::assertSame( 'plugin_update_fatal_error_rollback_failed', $record['wordpress_code'] );
			self::assertNotContains( 'update_completed', $updater->diagnostics() );
			$headers = ( new \ReflectionProperty( NativePackageUpdater::class, 'headers' ) )->getValue( $updater );
			self::assertSame( '1.0.0', $headers['Version'] );
			self::assertSame(
				array(
					array( 'action', 'shutdown', array( $updater, 'finalize_pending_install' ), PHP_INT_MAX ),
				),
				$GLOBALS['ran_wp_release_updater_test_removed_hooks']
			);

			// Core releases auto_updater.lock before returning, and PHP shutdown runs later as a no-op.
			$database->delete_option( WordPressUpdaterLock::OPTION_NAME );
			$GLOBALS['ran_wp_release_updater_test_doing_action'] = 'shutdown';
			$updater->finalize_pending_install();

			self::assertSame( array(), $database->rows() );
			self::assertSame( 'unverified_install_result', $this->retained_outcome( $updater )['outcome'] );
			// phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText -- Preserve the exact lowercase source wire value.
			self::assertSame( 'wordpress', $this->retained_outcome( $updater )['source'] );
		}

		public function test_failed_download_paths_always_release_updater_lock_without_orphan_rows(): void {
			// Inspect failure during refresh_download_binding releases auto_updater.lock immediately.
			list( $inspect_fail, $inspect_adapter, $inspect_db ) = $this->subject();
			$offer = $this->offer( $inspect_fail );
			$inspect_adapter->inspect_outcomes['release:2'] = new \RuntimeException( 'Inspect boom.' );
			self::assertInstanceOf( \WP_Error::class, $inspect_fail->filter_pre_download( false, $offer['package'], null, $this->extra() ) );
			self::assertSame( array(), $inspect_db->rows() );

			// Remote release drift during refresh_download_binding releases auto_updater.lock immediately.
			list( $drift, $drift_adapter, $drift_db, $descriptor ) = $this->subject();
			$drift_offer = $this->offer( $drift );
			$changed     = $descriptor->to_array();
			unset( $changed['fingerprint'] );
			$changed['commit_identity']        = 'commit:drifted';
			$drift_adapter->inspect_descriptor = IdentityDescriptor::create( $changed );
			self::assertInstanceOf( \WP_Error::class, $drift->filter_pre_download( false, $drift_offer['package'], null, $this->extra() ) );
			self::assertSame( array(), $drift_db->rows() );

			// Acquire failure releases auto_updater.lock immediately.
			list( $acquire_fail, $acquire_adapter, $acquire_db ) = $this->subject();
			$acquire_offer                                       = $this->offer( $acquire_fail );
			$acquire_adapter->acquire_outcomes['release:2']      = new \RuntimeException( 'Download failed.' );
			self::assertInstanceOf( \WP_Error::class, $acquire_fail->filter_pre_download( false, $acquire_offer['package'], null, $this->extra() ) );
			self::assertSame( array(), $acquire_db->rows() );
		}

		public function test_reentrant_download_refusal_does_not_become_the_pending_installs_cause(): void {
			$updater         = null;
			list( $updater ) = $this->subject(
				operation_guard: static fn(): bool => true,
				native_mutation_begin: function () use ( &$updater ): string {
					self::assertInstanceOf( NativePackageUpdater::class, $updater );
					$updater->filter_pre_download( false, '', null, $this->extra() );
					return 'owner';
				},
				native_mutation_complete: static function (): void {}
			);
			$offer           = $this->offer( $updater );
			$archive         = $updater->filter_pre_download( false, $offer['package'], null, $this->extra() );
			self::assertIsString( $archive );
			$updater->filter_pre_unzip_file( null, $archive, '/tmp', array(), 0.0 );
			$updater->filter_pre_install( true, $this->extra() );
			$updater->filter_source_selection( $this->staged(), '/tmp', null, $this->extra() );
			$updater->finalize_pending_install();
			self::assertSame( 'uncertain', $this->retained_outcome( $updater )['outcome'] );
		}

		public function test_working_copy_is_refused_before_acquisition_on_manual_and_automatic_updates(): void {
			foreach ( array( 'manual', 'automatic' ) as $mode ) {
				list( $updater, $adapter, $database ) = $this->subject( $mode );
				$offer                                = $this->offer( $updater );
				$GLOBALS['ran_wp_release_updater_test_doing_action'] = 'automatic' === $mode ? 'wp_maybe_auto_update' : null;
				if ( 'automatic' === $mode ) {
					$database->seed_option( WordPressUpdaterLock::OPTION_NAME, (string) time(), 'off' );
				}
				$this->working_copy_marker();
				$calls  = $adapter->acquire_calls;
				$result = $updater->filter_pre_download( false, $offer['package'], null, $this->extra() );
				self::assertInstanceOf( \WP_Error::class, $result );
				self::assertSame( 'ran_wp_release_updater_target_is_working_copy', $result->code );
				self::assertSame( 'target_is_working_copy', $updater->status()['failure_code'] );
				self::assertContains( 'target_is_working_copy', $updater->diagnostics() );
				self::assertSame( $calls, $adapter->acquire_calls );
			}
		}

		public function test_marker_created_after_download_is_refused_before_install_and_source_handoff(): void {
			foreach ( array( 'pre_install', 'source_selection' ) as $boundary ) {
				list( $updater ) = $this->subject();
				$offer           = $this->offer( $updater );
				$archive         = $updater->filter_pre_download( false, $offer['package'], null, $this->extra() );
				self::assertIsString( $archive );
				self::assertNull( $updater->filter_pre_unzip_file( null, $archive, '/tmp', array(), 0.0 ) );
				if ( 'source_selection' === $boundary ) {
					self::assertTrue( $updater->filter_pre_install( true, $this->extra() ) );
				}
				$marker = $this->working_copy_marker();
				$stage  = $this->staged();
				$result = 'pre_install' === $boundary
					? $updater->filter_pre_install( true, $this->extra() )
					: $updater->filter_source_selection( $stage, '/tmp', null, $this->extra() );
				self::assertInstanceOf( \WP_Error::class, $result );
				self::assertSame( 'ran_wp_release_updater_target_is_working_copy', $result->code );
				self::assertSame( $result, $updater->capture_install_package_result( $result, $this->extra() ) );
				self::assertFileDoesNotExist( $archive );
				self::assertFileExists( $marker );
				$updater->finalize_pending_install();
				self::assertSame( 'target_is_working_copy', $updater->status()['failure_code'] );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove this test-owned marker between independent lifecycle attempts.
				unlink( $marker );
			}
		}

		public function test_marker_created_by_final_admission_is_checked_before_native_mutation(): void {
			$control         = new class() {
				public bool $create_marker = false;
				public bool $began         = false;
			};
			list( $updater ) = $this->subject(
				operation_guard: static fn(): bool => true,
				native_mutation_begin: static function () use ( $control ): string {
					$control->began = true;
					return 'owner';
				},
				native_mutation_complete: static function (): void {},
				descriptor_admission: function () use ( $control ): bool {
					if ( $control->create_marker ) {
						$this->working_copy_marker();
					}
					return true;
				}
			);
			$offer           = $this->offer( $updater );
			$archive         = $updater->filter_pre_download( false, $offer['package'], null, $this->extra() );
			self::assertIsString( $archive );
			self::assertNull( $updater->filter_pre_unzip_file( null, $archive, '/tmp', array(), 0.0 ) );
			self::assertTrue( $updater->filter_pre_install( true, $this->extra() ) );
			$control->create_marker = true;
			$result                 = $updater->filter_source_selection( $this->staged(), '/tmp', null, $this->extra() );
			self::assertInstanceOf( \WP_Error::class, $result );
			self::assertSame( 'ran_wp_release_updater_target_is_working_copy', $result->code );
			self::assertFalse( $control->began );
			self::assertFileDoesNotExist( $archive );
		}

		private function working_copy_marker(): string {
			$directory     = $this->installed_directory();
			$marker        = $directory . '/.git';
			$this->paths[] = $marker;
			if ( ! is_dir( $directory ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create the actual installed-target fixture directory.
				mkdir( $directory, 0700 );
				$this->paths[] = $directory;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Create a real Git working-copy marker rather than mocking detection.
			file_put_contents( $marker, 'gitdir: separate-metadata' );
			return $marker;
		}

		public function test_marker_created_by_native_begin_is_refused(): void {
			$observed                     = new class() {
				public ?string $outcome = null;
				public ?string $owner   = null;
				public int $completions = 0;
			};
			list( $updater, , $database ) = $this->subject(
				operation_guard: static fn(): bool => true,
				native_mutation_begin: function (): string {
					$this->working_copy_marker();
					return 'owner';
				},
				native_mutation_complete: static function ( string $owner, string $target_type, string $identity, string $outcome ) use ( $observed ): void {
					unset( $target_type, $identity );
					$observed->owner   = $owner;
					$observed->outcome = $outcome;
					++$observed->completions;
				}
			);
			$offer                        = $this->offer( $updater );
			$archive                      = $updater->filter_pre_download( false, $offer['package'], null, $this->extra() );
			self::assertIsString( $archive );
			self::assertNull( $updater->filter_pre_unzip_file( null, $archive, '/tmp', array(), 0.0 ) );
			self::assertTrue( $updater->filter_pre_install( true, $this->extra() ) );
			$stage  = $this->staged();
			$result = $updater->filter_source_selection( $stage, '/tmp', null, $this->extra() );
			self::assertInstanceOf( \WP_Error::class, $result );
			self::assertSame( 'ran_wp_release_updater_target_is_working_copy', $result->code );
			self::assertSame( $result, $updater->capture_install_package_result( $result, $this->extra() ) );
			$updater->finalize_pending_install();
			self::assertSame( 'target_is_working_copy', $updater->status()['failure_code'] );
			self::assertSame( 'uncertain', $observed->outcome );
			self::assertSame( 1, $observed->completions );
			self::assertSame( 'owner', $observed->owner );
			self::assertFileDoesNotExist( $archive );
			self::assertTrue( is_file( $this->installed_directory() . '/.git' ) );
			self::assertSame( 'target_is_working_copy', $this->retained_outcome( $updater )['outcome'] );
			self::assertSame( array(), $database->rows() );
		}
		// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this inherited lifecycle method name.
		protected function setUp(): void {
			$this->temporary_directory = dirname( __DIR__, 2 ) . '/.workspaces/p0.2/php-tmp/native-package-updater-' . bin2hex( random_bytes( 6 ) );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create real directories for installed-package lifecycle fixtures with the specified permissions.
			mkdir( $this->temporary_directory, 0700, true );
			$GLOBALS['ran_wp_release_updater_test_observations']     = array();
			$GLOBALS['ran_wp_release_updater_test_observer_throws']  = false;
			$GLOBALS['ran_wp_release_updater_test_hooks']            = array();
			$GLOBALS['ran_wp_release_updater_test_removed_hooks']    = array();
			$GLOBALS['ran_wp_release_updater_test_filter_callbacks'] = array();
			$GLOBALS['ran_wp_release_updater_test_doing_action']     = null;
		}
		// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit requires this inherited lifecycle method name.
		protected function tearDown(): void {
			foreach ( $this->links as $link ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- Best-effort fixture teardown removes only the link entry, never its target, before directory cleanup.
				@unlink( $link );
			}
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

		public function test_provider_failure_after_start_dispatches_failed_completion_without_db_lock(): void {
			list( $updater, $adapter, $database )   = $this->subject( native_discovery_reuse: true );
			$adapter->inspect_outcomes['release:2'] = new \RuntimeException( 'Unexpected provider failure.' );
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
			self::assertSame( array(), $database->rows() );
			$events = $GLOBALS['ran_wp_release_updater_test_observations'];
			self::assertCount( 2, $events );
			self::assertSame( 'ran_wp_release_updater_check_started_v1', $events[0][0] );
			self::assertSame( 'ran_wp_release_updater_check_completed_v1', $events[1][0] );
			self::assertSame( 'failed', $events[1][1]['status'] );
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
			self::assertSame( array( 1, 1, 0 ), array( $adapter->list_calls, $adapter->inspect_calls, $adapter->acquire_calls ) );
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
			self::assertSame( array( 2, 2, 0 ), array( $adapter->list_calls, $adapter->inspect_calls, $adapter->acquire_calls ) );
			self::assertSame( 'candidate_admission_rejected', $updater->status()['candidate_validation_code'] );
		}

		public function test_descriptor_admission_flip_during_explicit_inspection_refuses_authorization(): void {
			$admit                     = true;
			$validator                 = new PackageIdentityValidator(
				static function () use ( &$admit ): void {
					$admit = false;
				}
			);
			list( $updater, $adapter ) = $this->subject(
				validator: $validator,
				descriptor_admission: static function () use ( &$admit ): bool {
					return $admit; }
			);
			$this->assert_refused( 'candidate_admission_rejected', $this->offer_release( $updater ) );
			self::assertSame( 1, $adapter->acquire_calls );
			self::assertFileDoesNotExist( $adapter->acquired_paths[0] );
			self::assertNull( $this->supplied( $updater ) );
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

		/** @return array<string,array{string}> */
		public static function blocked_policies(): array {
			return array(
				'disabled'   => array( 'disabled' ),
				'forced-off' => array( 'forced-off' ),
			);
		}

		#[\PHPUnit\Framework\Attributes\DataProvider( 'blocked_policies' )]
		public function test_blocked_policy_plugin_information_does_no_discovery_lease_or_token_work( string $mode ): void {
			list( $updater, $adapter, $database ) = $this->subject( $mode );
			$sentinel                             = (object) array( 'slug' => 'other' );
			$result                               = $updater->filter_plugin_information( $sentinel, 'plugin_information', (object) array( 'slug' => 'ran-wp-release-updater-' . substr( hash( 'sha256', "plugin\0package/package.php" ), 0, 24 ) ) );
			self::assertSame( $sentinel, $result );
			self::assertSame( array( 0, 0, 0 ), array( $adapter->list_calls, $adapter->inspect_calls, $adapter->acquire_calls ) );
			self::assertSame( array(), $database->rows() );
			self::assertSame( array(), $database->prepared_sql() );
			self::assertSame( array(), $database->read_option_names() );
		}

		public function test_manual_policy_plugin_information_still_offers_a_download_token(): void {
			list( $updater ) = $this->subject();
			$result          = $updater->filter_plugin_information( false, 'plugin_information', (object) array( 'slug' => 'ran-wp-release-updater-' . substr( hash( 'sha256', "plugin\0package/package.php" ), 0, 24 ) ) );
			self::assertInstanceOf( \stdClass::class, $result );
			self::assertNotSame( '', $result->download_link ?? '' );
		}

		#[\PHPUnit\Framework\Attributes\DataProvider( 'blocked_policies' )]
		public function test_blocked_policy_pre_download_refuses_without_discovery_lease_or_acquisition( string $mode ): void {
			list( $updater, $adapter, $database, $descriptor ) = $this->subject( $mode );
			// A token this target's own binding accepts, so only the policy gate stands between it and acquisition.
			$package = ( new \ReflectionMethod( $updater, 'token' ) )->invoke( $updater, $descriptor );
			self::assertIsString( $package );
			$result = $updater->filter_pre_download( false, $package, null, $this->extra() );
			self::assertInstanceOf( \WP_Error::class, $result );
			self::assertSame( 'ran_wp_release_updater_policy_blocked', $result->code );
			self::assertContains( 'policy_blocked', $updater->diagnostics() );
			$earlier = new \WP_Error( 'earlier', 'Earlier failure.' );
			self::assertSame( $earlier, $updater->filter_pre_download( $earlier, $package, null, $this->extra() ) );
			self::assertSame( array( 0, 0, 0 ), array( $adapter->list_calls, $adapter->inspect_calls, $adapter->acquire_calls ) );
			self::assertSame( array(), $database->rows() );
			self::assertSame( array(), $database->prepared_sql() );
			self::assertSame( array(), $database->read_option_names() );
		}

		public function test_blocked_policy_pre_download_ignores_other_packages(): void {
			list( $updater ) = $this->subject( 'disabled' );
			self::assertFalse( $updater->filter_pre_download( false, 'https://example.test/a.zip', null, array( 'plugin' => 'other/other.php' ) ) );
		}

		#[\PHPUnit\Framework\Attributes\DataProvider( 'blocked_policies' )]
		public function test_blocked_policy_pre_download_refuses_a_non_token_package_for_the_same_target( string $mode ): void {
			list( $updater, $adapter ) = $this->subject( $mode );
			$result                    = $updater->filter_pre_download( false, 'https://example.test/a.zip', null, $this->extra() );
			self::assertInstanceOf( \WP_Error::class, $result );
			self::assertSame( array( 0, 0, 0 ), array( $adapter->list_calls, $adapter->inspect_calls, $adapter->acquire_calls ) );
		}

		#[\PHPUnit\Framework\Attributes\DataProvider( 'blocked_policies' )]
		public function test_blocked_policy_drops_a_cached_offer_made_under_an_earlier_policy( string $mode ): void {
			list( $manual ) = $this->subject();
			$earlier        = $this->offer( $manual )['package'];
			foreach ( array( null, static fn(): bool => true ) as $admission ) {
				list( $updater, $adapter, $database, $descriptor ) = $this->subject( $mode, descriptor_admission: $admission );
				// A token the entry's own binding would still accept, as an earlier policy could have cached it.
				$package = ( new \ReflectionMethod( $updater, 'token' ) )->invoke( $updater, $descriptor );
				self::assertIsString( $package );
				self::assertNotSame( $earlier, $package );
				$transient = (object) array(
					'response' => array(
						'package/package.php' => (object) array( 'package' => $package ),
						'other/other.php'     => (object) array( 'package' => 'https://example.test/other.zip' ),
					),
				);
				self::assertSame( $transient, $updater->filter_cached_update( $transient ) );
				self::assertSame( array( 'other/other.php' ), array_keys( $transient->response ) );
				self::assertSame( array( 0, 0, 0 ), array( $adapter->list_calls, $adapter->inspect_calls, $adapter->acquire_calls ) );
				self::assertSame( array(), $database->rows() );
				self::assertSame( array(), $database->read_option_names() );
			}
		}

		#[\PHPUnit\Framework\Attributes\DataProvider( 'blocked_policies' )]
		public function test_blocked_policy_registers_the_cached_filter_without_a_descriptor_admission( string $mode ): void {
			list( $updater ) = $this->subject( $mode );
			$updater->register();
			$hooks = array_column( $GLOBALS['ran_wp_release_updater_test_hooks'], 1 );
			self::assertContains( 'site_transient_update_plugins', $hooks );
		}

		public function test_cached_filter_preserves_legacy_offers_without_descriptor_admission(): void {
			list( $updater ) = $this->subject();
			$updater->register();
			self::assertContains( 'site_transient_update_plugins', array_column( $GLOBALS['ran_wp_release_updater_test_hooks'], 1 ) );
			$transient = (object) array( 'response' => array( 'package/package.php' => (object) array( 'package' => $this->offer( $updater )['package'] ) ) );
			self::assertSame( $transient, $updater->filter_cached_update( $transient ) );
			self::assertArrayHasKey( 'package/package.php', $transient->response );
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
			self::assertCount( 17, $GLOBALS['ran_wp_release_updater_test_hooks'] );
			self::assertContains( array( 'filter', 'ran_wp_release_updater_install_exact_release_v1', array( $updater, 'filter_install_exact_release' ), 10, 7 ), $GLOBALS['ran_wp_release_updater_test_hooks'] );
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
		public function test_eligible_native_discovery_reuses_its_bound_metadata_snapshot(): void {
			list( $updater, $adapter ) = $this->subject( 'manual', null, 'stable', false, null, true );
			$this->offer( $updater );
			$this->offer( $updater );
			$information = $updater->filter_plugin_information( false, 'plugin_information', (object) array( 'slug' => 'ran-wp-release-updater-' . substr( hash( 'sha256', 'plugin' . "\0" . 'package/package.php' ), 0, 24 ) ) );
			self::assertIsObject( $information );
			if ( ! isset( $information->version ) ) {
				self::fail( 'Plugin information omitted its version.' );
			}
			self::assertSame( '2.0.0', $information->version );
			self::assertSame( array( 1, 1, 0 ), array( $adapter->list_calls, $adapter->inspect_calls, $adapter->acquire_calls ) );
			self::assertNull( $updater->status()['candidate_validation_code'] );

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
			self::assertSame( array( 2, 2, 0 ), array( $adapter->list_calls, $adapter->inspect_calls, $adapter->acquire_calls ) );
			self::assertTrue( $updater->refresh() );
			$this->offer( $updater );
			self::assertSame( array( 3, 3, 0 ), array( $adapter->list_calls, $adapter->inspect_calls, $adapter->acquire_calls ) );
		}
		public function test_reentrant_matching_install_attempt_prevents_discovery_from_publishing_a_snapshot(): void {
			list( $updater, $adapter ) = $this->subject( native_discovery_reuse: true );
			$adapter->on_inspect       = static function () use ( $updater ): void {
				$updater->filter_pre_download( new \WP_Error( 'interrupted', 'Interrupted.' ), '', null, array( 'plugin' => 'package/package.php' ) );
			};
			$this->offer( $updater );
			$this->offer( $updater );
			self::assertSame( array( 2, 2, 0 ), array( $adapter->list_calls, $adapter->inspect_calls, $adapter->acquire_calls ) );
		}
		public function test_reentrant_refresh_prevents_discovery_from_publishing_a_snapshot(): void {
			list( $updater, $adapter ) = $this->subject( native_discovery_reuse: true );
			$adapter->on_inspect       = static function () use ( $updater ): void {
				$updater->refresh();
			};
			$this->offer( $updater );
			$this->offer( $updater );
			self::assertSame( array( 2, 2, 0 ), array( $adapter->list_calls, $adapter->inspect_calls, $adapter->acquire_calls ) );
		}
		public function test_discovery_is_read_only_and_never_writes_database_locks(): void {
			list( $updater, $adapter, $database ) = $this->subject( 'manual', null, 'stable', false, null, true );
			$database->seed_option( WordPressUpdaterLock::OPTION_NAME, (string) time(), 'off' );
			$before = $database->rows();
			self::assertIsArray( $this->offer( $updater ) );
			self::assertSame( $before, $database->rows() );
			self::assertSame( array(), $database->prepared_sql() );
			self::assertSame( array( 1, 1, 0 ), array( $adapter->list_calls, $adapter->inspect_calls, $adapter->acquire_calls ) );
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
			self::assertSame( array( 2, 2, 0 ), array( $adapter->list_calls, $adapter->inspect_calls, $adapter->acquire_calls ) );
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
				self::assertSame( array( 2, 2, 0 ), array( $adapter->list_calls, $adapter->inspect_calls, $adapter->acquire_calls ) );
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
			self::assertSame( array( 2, 2, 0 ), array( $adapter->list_calls, $adapter->inspect_calls, $adapter->acquire_calls ) );
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
					'last_native_outcome'       => null,
				),
				$updater->status()
			);
			self::assertSame( array( 0, 0, 0 ), array( $adapter->list_calls, $adapter->inspect_calls, $adapter->acquire_calls ) );
			self::assertCount( 0, $database->prepared_sql() );
			$this->offer( $updater );
			$status = $updater->status();
			self::assertSame( 'v2.0.0', $status['candidate_tag'] );
			self::assertSame( '2.0.0', $status['candidate_version'] );
			self::assertNull( $status['candidate_validation_code'] );
			self::assertNull( $status['candidate_header_version'] );
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
					'last_native_outcome'       => null,
				),
				$updater->status()
			);
		}
		public function test_refresh_drops_the_update_check_cache_so_wordpress_asks_the_provider_again(): void {
			$GLOBALS['ran_wp_release_updater_test_deleted_transients'] = array();
			list( $updater ) = $this->subject();

			self::assertTrue( $updater->refresh() );
			self::assertSame( array( 'update_plugins' ), $GLOBALS['ran_wp_release_updater_test_deleted_transients'] );
		}
		public function test_a_theme_target_drops_the_theme_update_check_cache(): void {
			$GLOBALS['ran_wp_release_updater_test_deleted_transients'] = array();
			list( $updater ) = $this->subject();
			( new \ReflectionProperty( NativePackageUpdater::class, 'target_type' ) )->setValue( $updater, 'theme' );

			self::assertTrue( $updater->refresh() );
			self::assertSame( array( 'update_themes' ), $GLOBALS['ran_wp_release_updater_test_deleted_transients'] );
		}
		public function test_a_refused_refresh_leaves_the_update_check_cache_alone(): void {
			$GLOBALS['ran_wp_release_updater_test_deleted_transients'] = array();
			list( $updater ) = $this->subject( 'manual', null, 'stable', false, null, false, null, static fn (): bool => false );

			self::assertFalse( $updater->refresh() );
			self::assertSame( array(), $GLOBALS['ran_wp_release_updater_test_deleted_transients'] );
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
			$binding                                     = array(
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
			self::assertSame( array( 1, 2, 0 ), array( $adapter->list_calls, $adapter->inspect_calls, $adapter->acquire_calls ) );
			self::assertSame( 'release:3', $updater->status()['offered_release_identity'] );
			$completion = $GLOBALS['ran_wp_release_updater_test_observations'][1][1];
			self::assertSame( 'succeeded', $completion['status'] );
			self::assertSame( 'update_available', $completion['reason'] );
			self::assertSame( $second->to_array()['version'], $completion['candidate_version'] );
			self::assertSame( 'newer', $completion['relationship'] );
			self::assertNull( $completion['candidate_validation_code'] );
		}
		/** @return array<string,array{ReleaseFailure}> */
		public static function candidate_local_inspection_failures(): array {
			return array(
				'concrete release unavailable' => array( new ReleaseFailure( 'release_unavailable', null, 'complete' ) ),
				'package incompatible'         => array( new ReleaseFailure( 'package_incompatible', null, 'complete' ) ),
			);
		}
		#[\PHPUnit\Framework\Attributes\DataProvider( 'acquisition_failures' )]
		public function test_acquisition_failures_do_not_affect_advisories_and_refuse_explicit_authorization( \Throwable $failure ): void {
			list( $updater, $adapter, , $descriptor )                     = $this->subject();
			$adapter->acquire_outcomes[ $descriptor->release_identity() ] = $failure;

			$this->offer( $updater );
			self::assertSame( array( 1, 1, 0 ), array( $adapter->list_calls, $adapter->inspect_calls, $adapter->acquire_calls ) );
			self::assertSame( $descriptor->release_identity(), $updater->status()['offered_release_identity'] );
			$this->assert_refused( 'candidate_validation_failed', $this->offer_release( $updater ) );
			self::assertSame( array( 1, 2, 1 ), array( $adapter->list_calls, $adapter->inspect_calls, $adapter->acquire_calls ) );
		}
		/** @return array<string,array{\Throwable}> */
		public static function acquisition_failures(): array {
			return array(
				'rate limited'                 => array( new ReleaseFailure( 'rate_limited', 60 ) ),
				'operation failed'             => array( new ReleaseFailure( 'operation_failed' ) ),
				'unknown exception'            => array( new \RuntimeException( 'unexpected' ) ),
				'concrete release unavailable' => array( new ReleaseFailure( 'release_unavailable' ) ),
				'package incompatible'         => array( new ReleaseFailure( 'package_incompatible' ) ),
			);
		}
		public function test_failed_artifact_cleanup_refuses_explicit_authorization(): void {
			$validator  = new PackageIdentityValidator();
			$after_open = new \ReflectionProperty( $validator, 'after_open' );
			$after_open->setValue(
				$validator,
				static function ( string $path ): void {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Set real fixture permission bits for archive custody and permission-boundary checks.
					chmod( $path, 0644 );
				}
			);
			list( $updater, $adapter ) = $this->subject( validator: $validator );
			$this->assert_refused( 'candidate_validation_failed', $this->offer_release( $updater ) );
			self::assertSame( array( 0, 1, 1 ), array( $adapter->list_calls, $adapter->inspect_calls, $adapter->acquire_calls ) );
			self::assertNull( $this->supplied( $updater ) );
			self::assertFileExists( $adapter->acquired_paths[0] );
		}
		public function test_failed_explicit_validation_discards_an_unchanged_artifact(): void {
			$validator  = new PackageIdentityValidator();
			$after_open = new \ReflectionProperty( $validator, 'after_open' );
			$after_open->setValue(
				$validator,
				static function ( string $path ): void {
					unset( $path );
					throw new \RuntimeException( 'unexpected validation failure' );
				}
			);
			list( $updater, $adapter ) = $this->subject( validator: $validator );
			$this->assert_refused( 'candidate_validation_failed', $this->offer_release( $updater ) );
			self::assertSame( array( 0, 1, 1 ), array( $adapter->list_calls, $adapter->inspect_calls, $adapter->acquire_calls ) );
			self::assertNull( $this->supplied( $updater ) );
			self::assertFileDoesNotExist( $adapter->acquired_paths[0] );
		}
		public function test_failed_explicit_validation_preserves_a_changed_artifact(): void {
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
			list( $updater, $adapter ) = $this->subject( validator: $validator );
			$this->assert_refused( 'candidate_validation_failed', $this->offer_release( $updater ) );
			self::assertSame( array( 0, 1, 1 ), array( $adapter->list_calls, $adapter->inspect_calls, $adapter->acquire_calls ) );
			self::assertNull( $this->supplied( $updater ) );
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
			self::assertSame( array( 1, 1, 0 ), array( $adapter->list_calls, $adapter->inspect_calls, $adapter->acquire_calls ) );
		}
		public function test_offer_and_install_use_exact_discovery_and_reacquisition_counts_and_copy_ownership(): void {
			list( $updater, $adapter ) = $this->subject();
			$offer                     = $this->offer( $updater );
			self::assertSame( array( 1, 1, 0 ), array( $adapter->list_calls, $adapter->inspect_calls, $adapter->acquire_calls ) );
			$owned_archive = $updater->filter_pre_download( false, $offer['package'], null, $this->extra() );
			self::assertIsString( $owned_archive );
			self::assertSame( array( 1, 2, 1 ), array( $adapter->list_calls, $adapter->inspect_calls, $adapter->acquire_calls ) );
			self::assertFileDoesNotExist( $adapter->acquired_paths[0] );
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
			self::assertSame( array( 1, 2, 0 ), array( $adapter->list_calls, $adapter->inspect_calls, $adapter->acquire_calls ) );
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
			self::assertSame( array( 1, 2, 0 ), array( $adapter->list_calls, $adapter->inspect_calls, $adapter->acquire_calls ) );
			self::assertContains( 'unverified_pre_download', $updater->diagnostics() );
		}
		public function test_offer_only_does_not_lock_and_manual_download_holds_updater_lock(): void {
			list( $first_updater, , $database ) = $this->subject();
			$offer                              = $this->offer( $first_updater );
			self::assertSame( array(), $database->rows() );
			$first_updater->finalize_pending_install();
			list( $second_updater ) = $this->subject( 'manual', $database );
			self::assertIsArray( $this->offer( $second_updater ) );
			self::assertSame( array(), $database->rows() );

			$owned = $first_updater->filter_pre_download( false, $offer['package'], null, $this->extra() );
			self::assertIsString( $owned );
			self::assertArrayHasKey( WordPressUpdaterLock::OPTION_NAME, $database->rows() );
			self::assertSame( 'off', $database->rows()[ WordPressUpdaterLock::OPTION_NAME ]['autoload'] );
			self::assertNull( ( new WordPressUpdaterLock( $database ) )->acquire() );
			$first_updater->refresh();
			self::assertSame( array(), $database->rows() );
		}
		public function test_competing_owner_after_unzip_rejects_stale_receipt(): void {
			list( $updater, , $database ) = $this->subject();
			$offer                        = $this->offer( $updater );
			$owned_archive                = $updater->filter_pre_download( false, $offer['package'], null, $this->extra() );
			self::assertIsString( $owned_archive );
			self::assertNull( $updater->filter_pre_unzip_file( null, $owned_archive, '/tmp', array(), 0.0 ) );
			$successor = time() . '.999999999999999999999999999';
			$database->force_option_value( WordPressUpdaterLock::OPTION_NAME, $successor );
			self::assertInstanceOf( \WP_Error::class, $updater->filter_source_selection( $this->staged(), '/tmp', null, $this->extra() ) );
			self::assertSame( $successor, $database->rows()[ WordPressUpdaterLock::OPTION_NAME ]['option_value'] );
		}
		public function test_completion_and_rollback_release_claims(): void {
			list( $completed_updater, , $database ) = $this->subject();
			$this->complete( $completed_updater );
			self::assertSame( array(), $database->rows() );
			$lock = new WordPressUpdaterLock( $database );
			$tok  = $lock->acquire();
			self::assertIsString( $tok );
			self::assertTrue( $lock->release( $tok ) );

			list( $rollback_updater, , $database ) = $this->subject();
			$offer                                 = $this->offer( $rollback_updater );
			self::assertIsString( $rollback_updater->filter_pre_download( false, $offer['package'], null, $this->extra() ) );
			self::assertInstanceOf( \WP_Error::class, $rollback_updater->capture_install_package_result( new \WP_Error( 'rollback', 'rollback' ), $this->extra() ) );
			self::assertSame( array(), $database->rows() );
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
			self::assertCount( 17, $GLOBALS['ran_wp_release_updater_test_hooks'] );

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
				self::assertNull( $updater->filter_offer_release( null, 'plugin', 'package/package.php', 'release:2', 'v2.0.0' ), $failure );
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
				list( $updater, , $database ) = $this->subject( 'manual', null, 'stable', false, $state );
				$offer                        = $this->offer( $updater );
				$owned_archive                = $updater->filter_pre_download( false, $offer['package'], null, $this->extra() );
				self::assertIsString( $owned_archive );

				$GLOBALS['ran_wp_release_updater_v1_broker'] = new \stdClass();
				self::assertInstanceOf( \WP_Error::class, $updater->filter_pre_unzip_file( null, $owned_archive, '/tmp', array(), 0.0 ) );
				self::assertFileDoesNotExist( $owned_archive );
				self::assertSame( array(), $database->rows() );
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
				list( $updater, , $database ) = $this->subject( 'manual', null, 'stable', false, $state );
				$offer                        = $this->offer( $updater );
				$owned_archive                = $updater->filter_pre_download( false, $offer['package'], null, $this->extra() );
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
				self::assertSame( array(), $database->rows() );
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
					list( $updater, , $database ) = $this->subject( 'manual', null, 'stable', false, $state );
					$offer                        = $this->offer( $updater );
					$owned_archive                = $updater->filter_pre_download( false, $offer['package'], null, $this->extra() );
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
					self::assertSame( array(), $database->rows() );
				} finally {
					unset( $GLOBALS['ran_wp_release_updater_v1_broker'] );
				}
			}
		}
		#[\PHPUnit\Framework\Attributes\DataProvider( 'missing_descriptor_callbacks' )]
		public function test_missing_descriptor_rejects_pending_lifecycle_and_cleans_up( string $callback, string $code ): void {
			list( $updater, , $database ) = $this->subject();
			$offer                        = $this->offer( $updater );
			$owned_archive                = $updater->filter_pre_download( false, $offer['package'], null, $this->extra() );
			self::assertIsString( $owned_archive );
			if ( 'pre-unzip' !== $callback ) {
				self::assertNull( $updater->filter_pre_unzip_file( null, $owned_archive, '/tmp', array(), 0.0 ) );
			}
			if ( 'finalize' === $callback ) {
				self::assertTrue( $updater->filter_pre_install( true, $this->extra() ) );
				$staged_package = $this->staged();
				self::assertSame( $staged_package, $updater->filter_source_selection( $staged_package, '/tmp', null, $this->extra() ) );
				$updater->capture_install_package_result( array( 'destination' => $this->install_staged( $staged_package ) ), $this->extra() );
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
			self::assertSame( array(), $database->rows() );
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
			list( $updater, $adapter, $database ) = $this->subject();
			$offer                                = $this->offer( $updater );
			$reentrant_adapter                    = new class( $adapter, $updater ) implements \RAN\WPReleaseUpdater\V1\Contract\ReleaseAdapter {
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
			self::assertSame( 0, $adapter->acquire_calls );
			self::assertSame( array(), $database->rows() );
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
			$destination = $this->install_staged( $staged_package );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write exact bytes for installed-package lifecycle fixtures; WordPress helpers would alter the boundary under test.
			file_put_contents( $destination . '/package.php', 'changed' );
			$updater->capture_install_package_result( array( 'destination' => $destination ), $this->extra() );
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
		public function test_theme_source_proof_and_staging_marker_complete_the_actual_native_lifecycle( bool $stage_verified, string $mode, bool $automatic_action, bool $replace_skin, bool $failed_result, string $restore_case, string $reported_route ): void {
			$archive = $this->archive();
			$zip     = new \ZipArchive();
			self::assertTrue( $zip->open( $archive, \ZipArchive::OVERWRITE ) );
			$contents = "/*\nTheme Name: Package\nVersion: 2.0.0\nUpdate URI: {$this->uri()}\n*/";
			self::assertTrue( $zip->addFromString( 'package/style.css', $contents ) );
			$zip->close();
			$facts = $this->descriptor( $archive )->to_array();
			unset( $facts['fingerprint'] );
			$facts['target_type']                        = 'theme';
			$facts['installed_package_identity']         = 'package';
			$descriptor                                  = IdentityDescriptor::create( $facts );
			$binding                                     = $this->binding( $mode );
			$binding['target_type']                      = 'theme';
			$binding['installed_package_identity']       = 'package';
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
			self::assertCount( 16, $GLOBALS['ran_wp_release_updater_test_hooks'] );
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
			$stage = $this->staged( 'style.css', $contents );
			self::assertTrue( $updater->filter_pre_install( true, $extra ) );
			if ( $stage_verified ) {
				$stock = new \Theme_Upgrader();
				$GLOBALS['ran_wp_release_updater_test_doing_action'] = $automatic_action ? 'wp_maybe_auto_update' : null;
				self::assertSame( $stage, $updater->filter_source_selection( $stage, '/tmp', $stock, $extra ) );
				self::assertCount( 17, $GLOBALS['ran_wp_release_updater_test_hooks'] );
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
					self::assertSame( $bridged ? 18 : 17, count( $GLOBALS['ran_wp_release_updater_test_hooks'] ) );
					if ( $bridged ) {
						self::assertSame(
							array( 'action', 'upgrader_process_complete', array( $updater, 'restore_theme_error_result' ), PHP_INT_MIN, 2 ),
							$GLOBALS['ran_wp_release_updater_test_hooks'][17]
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
					$updater->capture_install_package_result( array( 'destination' => $this->reported_destination( $this->install_staged( $stage, 'style.css' ), $reported_route, 'style.css' ) ), $extra );
				}
				$GLOBALS['ran_wp_release_updater_test_doing_action'] = null;
			} else {
				$updater->capture_install_package_result( array( 'destination' => $this->reported_destination( $this->install_staged( $stage, 'style.css' ), $reported_route, 'style.css' ) ), $extra );
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
			$verified = $stage_verified && ! $failed_result && in_array( $reported_route, array( 'registered', 'symlinked_root' ), true );
			self::assertContains( $verified ? 'update_completed' : 'outcome_uncertain', $updater->diagnostics() );
			self::assertSame( $verified, in_array( 'update_completed', $updater->diagnostics(), true ) );
			self::assertSame( 1, $opened );
			self::assertFileDoesNotExist( $owned );
		}
		/** @return array<string,array{bool,string,bool,bool,bool,string,string}> */
		public static function theme_staging_cases(): array {
			return array(
				'genuine theme success'              => array( true, 'manual', false, false, false, 'none', 'registered' ),
				'manual target manual request'       => array( true, 'manual', false, false, true, 'none', 'registered' ),
				'automatic target manual request'    => array( true, 'automatic', false, false, true, 'none', 'registered' ),
				'automatic target automatic request' => array( true, 'automatic', true, false, true, 'none', 'registered' ),
				'skin changed after admission'       => array( true, 'manual', false, true, true, 'none', 'registered' ),
				'wrong upgrader completion'          => array( true, 'manual', false, false, true, 'wrong_upgrader', 'registered' ),
				'changed result refuses restoration' => array( true, 'manual', false, false, true, 'changed_result', 'registered' ),
				'changed skin error refuses restore' => array( true, 'manual', false, false, true, 'changed_skin_error', 'registered' ),
				'second completion is disarmed'      => array( true, 'manual', false, false, true, 'second_completion', 'registered' ),
				'omitted stage'                      => array( false, 'manual', false, false, false, 'none', 'registered' ),
				'symlinked theme root'               => array( true, 'manual', false, false, false, 'none', 'symlinked_root' ),
				'alternate same-slug theme'          => array( true, 'manual', false, false, false, 'none', 'alternate_directory' ),
				'symlinked theme leaf'               => array( true, 'manual', false, false, false, 'none', 'symlinked_leaf' ),
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
		public function test_lock_takeover_before_finalization_preserves_successor_and_reports_uncertain(): void {
			list( $updater, , $database ) = $this->subject();
			$this->prepare_install( $updater );
			$successor = time() . '.999999999999999999999999999';
			$database->force_option_value( WordPressUpdaterLock::OPTION_NAME, $successor );
			$updater->finalize_pending_install();
			self::assertContains( 'outcome_uncertain', $updater->diagnostics() );
			self::assertNotContains( 'update_completed', $updater->diagnostics() );
			self::assertSame( $successor, $database->rows()[ WordPressUpdaterLock::OPTION_NAME ]['option_value'] );
		}
		public function test_failed_lock_release_at_finalization_reports_uncertain(): void {
			list( $updater, , $database ) = $this->subject();
			$this->prepare_install( $updater );
			$database->fail_next_write( WordPressUpdaterLock::OPTION_NAME );
			$updater->finalize_pending_install();
			self::assertContains( 'outcome_uncertain', $updater->diagnostics() );
			self::assertNotContains( 'update_completed', $updater->diagnostics() );
			self::assertSame( 'uncertain', $this->retained_outcome( $updater )['outcome'] );
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
			self::assertCount( 1, $opened, 'Fresh acquisition scans once and reuses its proof for the owned copy.' );
		}
		public function test_owned_bytes_changed_during_the_persistent_fence_cannot_mint_receipt(): void {
			list( $updater, , $database ) = $this->subject();
			$offer                        = $this->offer( $updater );
			$before                       = glob( sys_get_temp_dir() . '/ran-wp-release-updater-*', GLOB_ONLYDIR );
			$changed                      = null;
			$database->mutate_on_read(
				WordPressUpdaterLock::OPTION_NAME,
				1,
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
			self::assertSame( array(), $database->rows() );
		}
		public function test_new_pending_install_without_staging_cannot_reuse_the_old_marker(): void {
			list( $updater ) = $this->subject();
			$this->prepare_install( $updater );
			$offer = $this->offer( $updater );
			$owned = $updater->filter_pre_download( false, $offer['package'], null, $this->extra() );
			self::assertIsString( $owned );
			self::assertNull( $updater->filter_pre_unzip_file( null, $owned, '/tmp', array(), 0.0 ) );
			$updater->capture_install_package_result( array( 'destination' => $this->installed_directory() ), $this->extra() );
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
			$updater->capture_install_package_result( array( 'destination' => $this->installed_directory() ), $this->extra() );
			$updater->finalize_pending_install();
			$updater->finalize_pending_install();
			self::assertSame( array( array( 'begin', 'plugin', 'package/package.php' ), array( 'owner', 'plugin', 'package/package.php', 'verified_success' ) ), $events );
			self::assertFileDoesNotExist( $prepared['archive'] );
			self::assertDirectoryDoesNotExist( dirname( $prepared['archive'] ) );
		}

		public function test_native_completion_failure_never_reports_success(): void {
			$calls                        = 0;
			list( $updater, , $database ) = $this->subject(
				operation_guard: static fn (): bool => true,
				native_mutation_begin: static fn (): string => 'owner',
				native_mutation_complete: static function () use ( &$calls ): void {
					++$calls;
					throw new \RuntimeException( 'Marker retained.' ); }
			);
			$prepared                     = $this->prepare_install( $updater );
			$updater->finalize_pending_install();
			$updater->finalize_pending_install();
			self::assertSame( 1, $calls );
			self::assertNotContains( 'update_completed', $updater->diagnostics() );
			self::assertSame( 'outcome_uncertain', $updater->status()['failure_code'] );
			self::assertFileDoesNotExist( $prepared['archive'] );
			self::assertSame( 'uncertain', $this->retained_outcome( $updater )['outcome'] );
			self::assertSame( array(), $database->rows() );
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

		public function test_guard_alone_fails_closed_before_provider_io(): void {
			foreach ( array( false, new \RuntimeException( 'guard failed' ) ) as $outcome ) {
				list( $updater, $adapter ) = $this->subject(
					operation_guard: static function () use ( $outcome ): bool {
						if ( $outcome instanceof \RuntimeException ) {
							throw $outcome;
						}
						return $outcome;
					}
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
				self::assertSame( array( 0, 0, 0 ), array( $adapter->list_calls, $adapter->inspect_calls, $adapter->acquire_calls ) );
			}
		}

		public function test_mutation_callbacks_require_a_guard_and_a_complete_pair(): void {
			list( , $adapter, $database, , $binding ) = $this->subject();
			$guard                                    = static fn (): bool => true;
			$begin                                    = static fn (): string => 'owner';
			$complete                                 = static function (): void {};
			foreach ( array( array( null, $begin, $complete ), array( $guard, $begin, null ), array( $guard, null, $complete ) ) as $callbacks ) {
				self::assertNull( NativePackageUpdater::from_configuration( $this->config( 'manual' ), $binding, $adapter, $database, $this->policy(), operation_guard: $callbacks[0], native_mutation_begin: $callbacks[1], native_mutation_complete: $callbacks[2] ) );
			}
		}

		private const CHECK_FILTER = 'ran_wp_release_updater_check_releases_v1';
		/** @return array<string,mixed> */
		private function manual_check( NativePackageUpdater $updater, string $type = 'plugin', string $identity = 'package/package.php' ): array {
			$report = $updater->filter_check_releases( null, $type, $identity );
			self::assertIsArray( $report );
			return $report;
		}
		public function test_read_only_check_for_disabled_still_discovers_beside_the_blocked_native_paths(): void {
			list( $updater, $adapter, $database, $descriptor ) = $this->subject( 'disabled', native_discovery_reuse: true );
			$report = $this->manual_check( $updater );
			self::assertSame( 'newer_release_observed', $report['reason'] );
			self::assertFalse( $report['offered'] );
			$discovery = $adapter->list_calls;
			$counts    = array( $adapter->list_calls, $adapter->inspect_calls, $adapter->acquire_calls );
			self::assertGreaterThan( 0, $discovery );
			$rows = $database->rows();
			// The three gated paths neither reuse nor add to what the check did.
			$package   = ( new \ReflectionMethod( $updater, 'token' ) )->invoke( $updater, $descriptor );
			$transient = (object) array( 'response' => array( 'package/package.php' => (object) array( 'package' => $package ) ) );
			self::assertSame( 'keep', $updater->filter_plugin_information( 'keep', 'plugin_information', (object) array( 'slug' => 'ran-wp-release-updater-' . substr( hash( 'sha256', "plugin\0package/package.php" ), 0, 24 ) ) ) );
			self::assertInstanceOf( \WP_Error::class, $updater->filter_pre_download( false, $package, null, $this->extra() ) );
			self::assertSame( $transient, $updater->filter_cached_update( $transient ) );
			self::assertSame( array(), $transient->response );
			self::assertSame( $counts, array( $adapter->list_calls, $adapter->inspect_calls, $adapter->acquire_calls ) );
			self::assertSame( $rows, $database->rows() );
			// The blocked paths do not disable the check: it still discovers afresh.
			self::assertSame( 'newer_release_observed', $this->manual_check( $updater )['reason'] );
			self::assertGreaterThan( $discovery, $adapter->list_calls );
		}
		public function test_manual_check_registers_its_filter_with_every_target(): void {
			list( $updater ) = $this->subject( 'disabled' );
			$updater->register();
			$hooks = array_filter( $GLOBALS['ran_wp_release_updater_test_hooks'], static fn( array $hook ): bool => self::CHECK_FILTER === $hook[1] );
			self::assertCount( 1, $hooks );
		}
		public function test_manual_check_when_disabled_reports_the_release_without_any_offer_state(): void {
			$GLOBALS['ran_wp_release_updater_test_deleted_transients'] = array();
			list( $updater, $adapter )                                 = $this->subject( 'disabled', native_discovery_reuse: true );
			$report = $this->manual_check( $updater );
			self::assertSame(
				array(
					'status'                    => 'succeeded',
					'reason'                    => 'newer_release_observed',
					'policy'                    => 'disabled',
					'relationship'              => 'newer',
					'installed_version'         => '1.0.0',
					'candidate_version'         => '2.0.0',
					'candidate_validation_code' => null,
					'offered'                   => false,
				),
				$report
			);
			self::assertSame( 1, $adapter->list_calls );
			// No offer token or offer fields can be read from anything the check returned or kept.
			foreach ( $report as $value ) {
				self::assertFalse( is_string( $value ) && str_contains( $value, 'ran-wp-release-updater:' ) );
			}
			self::assertNull( $updater->status()['offered_version'] );
			self::assertNull( $updater->status()['offered_release_identity'] );
			$state = new \ReflectionObject( $updater );
			self::assertNull( $state->getProperty( 'descriptor' )->getValue( $updater ) );
			self::assertNull( $state->getProperty( 'discovery_snapshot' )->getValue( $updater ) );
			// The transient is neither written nor cleared by the check, and the cached-entry filter has nothing to remove.
			self::assertSame( array(), $GLOBALS['ran_wp_release_updater_test_deleted_transients'] );
			$transient           = new \stdClass();
			$transient->response = array();
			self::assertSame( array(), $updater->filter_cached_update( $transient )->response );
			// Native checks stay policy-gated: no new discovery and no offer, exactly as before.
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
			self::assertSame( 1, $adapter->list_calls );
			self::assertSame( 'policy_disabled', end( $GLOBALS['ran_wp_release_updater_test_observations'] )[1]['reason'] );
			self::assertSame( 'skipped', end( $GLOBALS['ran_wp_release_updater_test_observations'] )[1]['status'] );
		}
		public function test_manual_check_does_not_leave_a_reusable_discovery_snapshot(): void {
			list( $updater, $adapter ) = $this->subject( 'manual', native_discovery_reuse: true );
			$this->manual_check( $updater );
			$state = new \ReflectionObject( $updater );
			self::assertNull( $state->getProperty( 'descriptor' )->getValue( $updater ) );
			self::assertNull( $state->getProperty( 'discovery_snapshot' )->getValue( $updater ) );
			// A leaked snapshot would let this native check offer without asking the provider again.
			$this->offer( $updater );
			self::assertSame( 2, $adapter->list_calls );
		}
		public function test_manual_check_leaves_native_status_untouched(): void {
			list( $updater, $adapter ) = $this->subject( 'manual' );
			$this->offer( $updater );
			$before = $updater->status();
			$this->manual_check( $updater );
			self::assertSame( $before, $updater->status() );
			self::assertSame( 2, $adapter->list_calls );
		}
		public function test_manual_check_discovers_afresh_and_preserves_earlier_native_discovery_state(): void {
			list( $updater, $adapter ) = $this->subject( 'manual', native_discovery_reuse: true );
			$offer                     = $this->offer( $updater );
			self::assertSame( 1, $adapter->list_calls );
			$state      = new \ReflectionObject( $updater );
			$descriptor = $state->getProperty( 'descriptor' )->getValue( $updater );
			$snapshot   = $state->getProperty( 'discovery_snapshot' )->getValue( $updater );
			$status     = $updater->status();
			// The check discovers a different descriptor object, so restoring the earlier one is observable.
			$second                 = $this->with_release_identity( $descriptor, 'release:3', 'v2.0.1' );
			$adapter->list_response = array( 'candidates' => array( $this->candidate( $second ) ) );
			$adapter->inspect_outcomes[ $second->release_identity() ] = $second;
			self::assertNotSame( $descriptor, $second );
			$GLOBALS['ran_wp_release_updater_test_observations'] = array();
			// The native snapshot must not answer the check: it asks the provider and is observed.
			$report = $this->manual_check( $updater );
			self::assertSame( 'newer_release_observed', $report['reason'] );
			self::assertSame( 2, $adapter->list_calls );
			$events = $GLOBALS['ran_wp_release_updater_test_observations'];
			self::assertSame( array( 'ran_wp_release_updater_check_started_v1', 'ran_wp_release_updater_check_completed_v1' ), array( $events[0][0], $events[1][0] ) );
			// The earlier native state is intact: same descriptor and snapshot, same status, same reusable offer.
			self::assertSame( $descriptor, $state->getProperty( 'descriptor' )->getValue( $updater ) );
			self::assertSame( $snapshot, $state->getProperty( 'discovery_snapshot' )->getValue( $updater ) );
			self::assertSame( $status, $updater->status() );
			$GLOBALS['ran_wp_release_updater_test_observations'] = array();
			$again = $this->offer( $updater );
			self::assertSame( $offer['package'], $again['package'] );
			self::assertSame( 2, $adapter->list_calls );
			self::assertSame( array(), $GLOBALS['ran_wp_release_updater_test_observations'] );
		}
		public function test_a_throwing_observer_cannot_leave_native_state_polluted_by_the_check(): void {
			list( $updater ) = $this->subject( 'manual', native_discovery_reuse: true );
			$this->offer( $updater );
			$status = $updater->status();
			$GLOBALS['ran_wp_release_updater_test_observer_throws'] = true;
			try {
				$this->manual_check( $updater );
				self::fail( 'Listener exception must escape the check.' );
			} catch ( \RuntimeException $failure ) {
				self::assertSame( 'Observer failed.', $failure->getMessage() );
			}
			self::assertSame( $status, $updater->status() );
		}
		public function test_manual_check_reports_liveness_lost_without_facts(): void {
			$calls                     = new \stdClass();
			$calls->adapter            = null;
			list( $updater, $adapter ) = $this->subject(
				'disabled',
				operation_guard: static function () use ( $calls ): bool {
					return ! $calls->adapter instanceof ControllableReleaseAdapter || 0 === $calls->adapter->list_calls;
				}
			);
			$calls->adapter            = $adapter;
			$report                    = $this->manual_check( $updater );
			self::assertSame( 'runtime_liveness_lost', $report['reason'] );
			self::assertSame( 'failed', $report['status'] );
			self::assertNull( $report['candidate_version'] );
			self::assertFalse( $report['offered'] );
			self::assertNull( ( new \ReflectionObject( $updater ) )->getProperty( 'descriptor' )->getValue( $updater ) );
		}
		public function test_manual_check_reports_nothing_stale_when_busy(): void {
			list( $updater ) = $this->subject( 'manual' );
			$this->prepare_install( $updater );
			$busy = $this->manual_check( $updater );
			self::assertSame( 'runtime_busy', $busy['reason'] );
			self::assertNull( $busy['candidate_version'] );
			self::assertNull( $busy['relationship'] );
			self::assertNull( $busy['candidate_validation_code'] );
		}
		public function test_manual_check_reports_a_second_consecutive_check(): void {
			list( $updater, $adapter ) = $this->subject( 'disabled' );
			self::assertSame( 'newer_release_observed', $this->manual_check( $updater )['reason'] );
			self::assertSame( 'newer_release_observed', $this->manual_check( $updater )['reason'] );
			self::assertSame( 2, $adapter->list_calls );
		}
		public function test_manual_check_emits_one_started_and_completed_observation_for_disabled(): void {
			list( $updater ) = $this->subject( 'disabled' );
			$this->manual_check( $updater );
			$events = $GLOBALS['ran_wp_release_updater_test_observations'];
			self::assertSame( array( 'ran_wp_release_updater_check_started_v1', 'ran_wp_release_updater_check_completed_v1' ), array( $events[0][0], $events[1][0] ) );
			self::assertSame( $events[0][1]['check_id'], $events[1][1]['check_id'] );
			self::assertSame( 'succeeded', $events[1][1]['status'] );
			self::assertSame( 'newer_release_observed', $events[1][1]['reason'] );
			self::assertSame( '2.0.0', $events[1][1]['candidate_version'] );
			self::assertNull( $events[1][1]['offered_version'] );
		}
		public function test_forced_off_is_blocked_before_any_provider_work_and_is_reported_distinctly(): void {
			list( $forced, $forced_adapter ) = $this->subject( 'forced-off' );
			$report                          = $this->manual_check( $forced );
			self::assertSame( 'skipped', $report['status'] );
			self::assertSame( 'policy_forced_off', $report['reason'] );
			self::assertSame( 'forced-off', $report['policy'] );
			self::assertFalse( $report['offered'] );
			self::assertNull( $report['candidate_version'] );
			self::assertSame( 0, $forced_adapter->list_calls );
			self::assertSame( 0, $forced_adapter->inspect_calls );
			self::assertSame( 0, $forced_adapter->acquire_calls );
			$events = $GLOBALS['ran_wp_release_updater_test_observations'];
			self::assertSame( 'policy_forced_off', $events[1][1]['reason'] );
			self::assertSame( 'skipped', $events[1][1]['status'] );
			// A disabled package never reports the site-configuration reason.
			$GLOBALS['ran_wp_release_updater_test_observations'] = array();
			list( $disabled )                                    = $this->subject( 'disabled' );
			self::assertNotSame( 'policy_forced_off', $this->manual_check( $disabled )['reason'] );
			// Native checks keep their existing, already distinct skip reasons.
			$GLOBALS['ran_wp_release_updater_test_observations'] = array();
			self::assertFalse(
				$forced->filter_update(
					false,
					array(
						'Version'   => '1.0.0',
						'UpdateURI' => $this->uri(),
					),
					'package/package.php',
					array()
				)
			);
			self::assertSame( 'policy_forced_off', $GLOBALS['ran_wp_release_updater_test_observations'][1][1]['reason'] );
			self::assertSame( 0, $forced_adapter->list_calls );
		}
		public function test_manual_check_reports_rejected_and_failed_outcomes_like_other_policies(): void {
			list( $updater, $adapter ) = $this->subject( 'disabled' );
			$adapter->list_response    = array(
				'candidates' => array(
					array(
						'release_identity' => '',
						'tag'              => 5,
						'version'          => '2.0.0',
					),
				),
			);
			$bad                       = $this->manual_check( $updater );
			self::assertSame( 'failed', $bad['status'] );
			self::assertSame( 'candidate_invalid', $bad['reason'] );
			list( $rejecting ) = $this->subject(
				'disabled',
				descriptor_admission: static function ( IdentityDescriptor $descriptor ): bool {
					unset( $descriptor );
					return false;
				}
			);
			$rejected          = $this->manual_check( $rejecting );
			self::assertSame( 'rejected', $rejected['status'] );
			self::assertSame( 'candidate_rejected', $rejected['reason'] );
			self::assertFalse( $rejected['offered'] );
		}

		public function test_read_only_discovery_defers_archive_validation_to_explicit_inspection(): void {
			$uri   = $this->uri();
			$cases = array(
				'version mismatch' => array(
					array( 'package/package.php' => "<?php\n/*\nPlugin Name: Package\nVersion: 9.9.9\nUpdate URI: {$uri}\n*/" ),
					'archive_version_mismatch',
				),
				'unsafe path'      => array(
					array(
						'package/package.php'   => "<?php\n/*\nPlugin Name: Package\nVersion: 2.0.0\nUpdate URI: {$uri}\n*/",
						'package/../escape.php' => '<?php',
					),
					'archive_path_unsafe',
				),
				'root mismatch'    => array(
					array( 'other-root/package.php' => "<?php\n/*\nPlugin Name: Package\nVersion: 2.0.0\nUpdate URI: {$uri}\n*/" ),
					'archive_root_mismatch',
				),
				'size limit bomb'  => array(
					array(
						'package/package.php' => "<?php\n/*\nPlugin Name: Package\nVersion: 2.0.0\nUpdate URI: {$uri}\n*/",
						'package/bomb.bin'    => str_repeat( "\0", 200000 ),
					),
					'archive_size_limit',
				),
			);

			foreach ( $cases as $label => [$entries, $expected_code] ) {
				$archive_path = tempnam( $this->temporary_directory, 'ran-native-bad-' );
				self::assertIsString( $archive_path );
				$this->paths[] = $archive_path;
				$zip           = new \ZipArchive();
				self::assertTrue( $zip->open( $archive_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) );
				foreach ( $entries as $entry_name => $contents ) {
					self::assertTrue( $zip->addFromString( $entry_name, $contents ) );
				}
				self::assertTrue( $zip->close() );

				$descriptor = $this->descriptor( $archive_path, 'stable', false );
				$binding    = $this->binding( 'manual', 'stable' );
				$adapter    = new ControllableReleaseAdapter( $descriptor, $archive_path, $this->temporary_directory );
				$updater    = NativePackageUpdater::from_configuration( $this->config( 'manual' ), $binding, $adapter, new FakeOptionDatabase( 100 ), $this->policy() );
				self::assertInstanceOf( NativePackageUpdater::class, $updater );
				$GLOBALS['ran_wp_release_updater_test_observations'] = array();

				$report = $this->manual_check( $updater );
				$events = $GLOBALS['ran_wp_release_updater_test_observations'];

				self::assertSame( 'succeeded', $report['status'], $label );
				self::assertSame( 'newer_release_observed', $report['reason'], $label );
				self::assertNull( $report['candidate_validation_code'], $label );
				self::assertSame( 0, $adapter->acquire_calls, $label );
				$this->assert_refused( $expected_code, $this->offer_release( $updater ) );
				self::assertSame( 1, $adapter->acquire_calls, $label );
				self::assertCount( 2, $events, $label );
				self::assertSame( 'ran_wp_release_updater_check_completed_v1', $events[1][0], $label );
				self::assertNull( $events[1][1]['candidate_validation_code'] );
			}

			self::assertFalse( \RAN\WPReleaseUpdater\V1\Runtime\RequestProtocolValidator::is_candidate_validation_code( 'unlisted_code' ) );
			foreach ( array(
				'archive_entry_limit',
				'archive_entry_unreadable',
				'archive_file_identity_mismatch',
				'archive_header_duplicate',
				'archive_header_missing',
				'archive_header_unreadable',
				'archive_identity_verified',
				'archive_metadata_identity_mismatch',
				'archive_path_duplicate',
				'archive_path_unsafe',
				'archive_php_requirement_incompatible',
				'archive_root_mismatch',
				'archive_size_limit',
				'archive_target_policy_invalid',
				'archive_unreadable',
				'archive_update_uri_mismatch',
				'archive_version_mismatch',
				'archive_wordpress_requirement_incompatible',
				'archive_zip_extension_unavailable',
			) as $archive_code ) {
				self::assertTrue(
					\RAN\WPReleaseUpdater\V1\Runtime\RequestProtocolValidator::is_candidate_validation_code( $archive_code ),
					$archive_code
				);
			}
		}
		public function test_manual_check_is_ignored_for_other_targets_and_when_not_live(): void {
			list( $updater, $adapter ) = $this->subject( 'disabled' );
			self::assertNull( $updater->filter_check_releases( null, 'theme', 'package/package.php' ) );
			self::assertNull( $updater->filter_check_releases( null, 'plugin', 'other/other.php' ) );
			self::assertSame( 'kept', $updater->filter_check_releases( 'kept', 'plugin', 'other/other.php' ) );
			self::assertNull( $updater->filter_check_releases( null, 'plugin', 7 ) );
			self::assertSame( 0, $adapter->list_calls );
			list( $guarded, $guarded_adapter ) = $this->subject( 'disabled', operation_guard: static fn(): bool => false );
			self::assertNull( $guarded->filter_check_releases( null, 'plugin', 'package/package.php' ) );
			self::assertSame( 0, $guarded_adapter->list_calls );
		}
		public function test_manual_check_does_not_change_manual_or_automatic_offers(): void {
			foreach ( array( 'manual', 'automatic' ) as $mode ) {
				list( $updater, $adapter ) = $this->subject( $mode );
				$offer                     = $this->offer( $updater );
				$before                    = $updater->status();
				$report                    = $this->manual_check( $updater );
				self::assertSame( 'newer_release_observed', $report['reason'] );
				self::assertSame( $mode, $report['policy'] );
				self::assertFalse( $report['offered'] );
				// The offered facts of the earlier native check remain, and the offer still verifies.
				self::assertSame( $before['offered_version'], $updater->status()['offered_version'] );
				self::assertSame( $before['offered_release_identity'], $updater->status()['offered_release_identity'] );
				self::assertSame( 'automatic' === $mode, $offer['autoupdate'] );
				self::assertSame( 2, $adapter->list_calls );
				$again = $this->offer( $updater );
				self::assertSame( $offer['package'], $again['package'] );
				self::assertSame( $offer['autoupdate'], $again['autoupdate'] );
			}
		}
		public function test_manual_check_refuses_while_a_native_install_is_pending(): void {
			list( $updater, $adapter ) = $this->subject( 'manual' );
			$this->prepare_install( $updater );
			$calls  = $adapter->list_calls;
			$report = $this->manual_check( $updater );
			self::assertSame( 'runtime_busy', $report['reason'] );
			self::assertSame( 'failed', $report['status'] );
			self::assertSame( $calls, $adapter->list_calls );
		}
		private const OFFER_FILTER = 'ran_wp_release_updater_offer_release_v1';
		/** @return array<string,mixed> */
		private function offer_release( NativePackageUpdater $updater, string $release_identity = 'release:2', string $tag = 'v2.0.0', mixed $older_intended = false ): array {
			$report = $updater->filter_offer_release( null, 'plugin', 'package/package.php', $release_identity, $tag, $older_intended );
			self::assertIsArray( $report );
			return $report;
		}
		/**
		 * The plugin entry a WordPress read of the update check would find for this target, as an array.
		 *
		 * @return array<string,mixed>|null
		 */
		private function supplied( NativePackageUpdater $updater ): ?array {
			$transient = $updater->filter_cached_update( (object) array( 'response' => array() ) );
			self::assertIsObject( $transient );
			$entry = $transient->response['package/package.php'] ?? null;
			return is_object( $entry ) ? get_object_vars( $entry ) : null;
		}
		/** @param array<string,mixed> $report */
		private function assert_refused( string $code, array $report ): void {
			self::assertSame( array( 'status', 'code', 'version', 'relationship' ), array_keys( $report ) );
			self::assertSame( array( 'refused', $code ), array( $report['status'], $report['code'] ) );
		}
		public function test_offer_release_installs_an_authorised_equal_release_with_one_fresh_acquisition(): void {
			list( $updater, $adapter ) = $this->subject( 'automatic', installed_version: '2.0.0' );
			$updater->register();
			// WordPress passes the host's sixth argument, the older-release intent, only if it is registered.
			self::assertSame( 6, array_column( $GLOBALS['ran_wp_release_updater_test_hooks'], 4, 1 )[ self::OFFER_FILTER ] ?? null );
			$GLOBALS['ran_wp_release_updater_test_hooks'] = array();
			self::assertSame(
				array(
					'status'       => 'authorized',
					'code'         => 'release_authorized',
					'version'      => '2.0.0',
					'relationship' => 'same',
				),
				$this->offer_release( $updater )
			);
			// One inspection and one validated acquisition, with no listing.
			self::assertSame( array( 0, 1, 1 ), array( $adapter->list_calls, $adapter->inspect_calls, $adapter->acquire_calls ) );
			// The read supply and the save-time strip are registered once something is authorised.
			$registered = array_column( $GLOBALS['ran_wp_release_updater_test_hooks'], 3, 1 );
			self::assertSame( 10, $registered['site_transient_update_plugins'] );
			self::assertSame( PHP_INT_MAX, $registered['pre_set_site_transient_update_plugins'] );
			$entry = $this->supplied( $updater );
			self::assertIsArray( $entry );
			self::assertSame( array( '2.0.0', '2.0.0', false, 'package/package.php' ), array( $entry['version'], $entry['new_version'], $entry['autoupdate'], $entry['plugin'] ) );
			// An authorised equal release never becomes an automatic update.
			self::assertFalse( $updater->filter_auto_update( true, (object) $entry ) );
			$this->complete( $updater, $entry['package'] );
			self::assertSame( array( 0, 2, 2 ), array( $adapter->list_calls, $adapter->inspect_calls, $adapter->acquire_calls ) );
			$outcome = $this->retained_outcome( $updater );
			self::assertSame( array( 'verified_success', 'release:2', '2.0.0' ), array( $outcome['outcome'], $outcome['release_identity'], $outcome['version'] ) );
			// The attempt consumed the authorisation: nothing is supplied and a second attempt is refused.
			self::assertNull( $this->supplied( $updater ) );
			self::assertInstanceOf( \WP_Error::class, $updater->filter_pre_download( false, $entry['package'], null, $this->extra() ) );
		}
		public function test_offer_release_installs_a_chosen_newer_release_that_is_not_the_newest(): void {
			list( $updater, $adapter ) = $this->subject();
			$adapter->list_response    = array(
				'candidates' => array(
					array(
						'release_identity' => 'release:3',
						'tag'              => 'v3.0.0',
						'version'          => '3.0.0',
					),
				),
			);
			self::assertSame( array( 'authorized', 'release_authorized', '2.0.0', 'newer' ), array_values( $this->offer_release( $updater ) ) );
			$entry = $this->supplied( $updater );
			self::assertIsArray( $entry );
			$this->complete( $updater, $entry['package'] );
			self::assertSame( 0, $adapter->list_calls );
			self::assertSame( 'release:2', $this->retained_outcome( $updater )['release_identity'] );
		}
		public function test_offer_release_refuses_with_nothing_supplied(): void {
			list( $older ) = $this->subject( installed_version: '3.0.0' );
			$report        = $this->offer_release( $older );
			$this->assert_refused( 'release_older', $report );
			self::assertSame( array( '2.0.0', 'older' ), array( $report['version'], $report['relationship'] ) );
			self::assertNull( $this->supplied( $older ) );
			list( $mismatch ) = $this->subject();
			$this->assert_refused( 'candidate_descriptor_mismatch', $this->offer_release( $mismatch, 'release:2', 'v9.9.9' ) );
			self::assertNull( $this->supplied( $mismatch ) );
			list( $unavailable, $adapter )          = $this->subject();
			$adapter->inspect_outcomes['release:7'] = new \RuntimeException( 'Missing release.' );
			$this->assert_refused( 'release_unavailable', $this->offer_release( $unavailable, 'release:7', 'v7.0.0' ) );
			self::assertNull( $this->supplied( $unavailable ) );
			foreach ( array(
				'disabled'   => 'policy_disabled',
				'forced-off' => 'policy_forced_off',
			) as $mode => $code ) {
				list( $blocked, $adapter ) = $this->subject( $mode, installed_version: '2.0.0' );
				$this->assert_refused( $code, $this->offer_release( $blocked ) );
				self::assertSame( array( 0, 0, 0 ), array( $adapter->list_calls, $adapter->inspect_calls, $adapter->acquire_calls ) );
			}
			list( $denied ) = $this->subject( installed_version: '2.0.0', descriptor_admission: static fn(): bool => false );
			$this->assert_refused( 'candidate_admission_rejected', $this->offer_release( $denied ) );
			self::assertNull( $this->supplied( $denied ) );
			list( $ineligible, $adapter, , $descriptor ) = $this->subject( installed_version: '2.0.0' );
			$facts                                       = $descriptor->to_array();
			unset( $facts['fingerprint'] );
			$facts['assurance_facts']['exact_reacquisition_supported'] = false;
			$adapter->inspect_descriptor                               = IdentityDescriptor::create( $facts );
			$this->assert_refused( 'candidate_not_eligible', $this->offer_release( $ineligible ) );
			self::assertNull( $this->supplied( $ineligible ) );
			// Without an authorisation an equal release stays refused at download admission.
			list( $unauthorised, $adapter, , $descriptor, $binding ) = $this->subject( installed_version: '2.0.0' );
			self::assertInstanceOf( \WP_Error::class, $unauthorised->filter_pre_download( false, $this->token( $descriptor, $binding ), null, $this->extra() ) );
			self::assertSame( 0, $adapter->inspect_calls );
		}
		public function test_offer_release_authorises_an_older_release_only_when_the_host_intends_it(): void {
			// Without the intent, or with anything but true, an older release is refused as before.
			list( $updater, $adapter, , $descriptor, $binding ) = $this->subject( 'automatic', installed_version: '3.0.0' );
			foreach ( array( false, 1, '1', 'true', null ) as $not_intended ) {
				$report = $this->offer_release( $updater, older_intended: $not_intended );
				$this->assert_refused( 'release_older', $report );
				self::assertSame( array( '2.0.0', 'older' ), array( $report['version'], $report['relationship'] ) );
				self::assertNull( $this->supplied( $updater ) );
			}
			// An unauthorised older token stays refused at download admission.
			self::assertInstanceOf( \WP_Error::class, $updater->filter_pre_download( false, $this->token( $descriptor, $binding ), null, $this->extra() ) );
			// With it, the older release is authorised even on an automatic package, and reported as older.
			self::assertSame(
				array(
					'status'       => 'authorized',
					'code'         => 'release_authorized',
					'version'      => '2.0.0',
					'relationship' => 'older',
				),
				$this->offer_release( $updater, older_intended: true )
			);
			$entry = $this->supplied( $updater );
			self::assertIsArray( $entry );
			self::assertSame( array( '2.0.0', false ), array( $entry['new_version'], $entry['autoupdate'] ) );
			// The automatic path never installs it.
			self::assertFalse( $updater->filter_auto_update( true, (object) $entry ) );
			$calls = $adapter->acquire_calls;
			$this->complete( $updater, $entry['package'] );
			self::assertSame( $calls + 1, $adapter->acquire_calls );
			$outcome = $this->retained_outcome( $updater );
			self::assertSame( array( 'verified_success', 'release:2', '2.0.0' ), array( $outcome['outcome'], $outcome['release_identity'], $outcome['version'] ) );
			self::assertSame( array(), $updater->filter_store_update( (object) array( 'response' => array( 'package/package.php' => (object) $entry ) ) )->response );
			// The intent changes nothing for the same or a newer release.
			foreach ( array(
				'2.0.0' => 'same',
				'1.0.0' => 'newer',
			) as $installed => $relationship ) {
				foreach ( array( false, true ) as $intended ) {
					list( $other ) = $this->subject( installed_version: $installed );
					self::assertSame( array( 'authorized', 'release_authorized', '2.0.0', $relationship ), array_values( $this->offer_release( $other, older_intended: $intended ) ) );
				}
			}
			// Disabled and forced off still refuse, intent or not.
			foreach ( array(
				'disabled'   => 'policy_disabled',
				'forced-off' => 'policy_forced_off',
			) as $mode => $code ) {
				list( $blocked ) = $this->subject( $mode, installed_version: '3.0.0' );
				$this->assert_refused( $code, $this->offer_release( $blocked, older_intended: true ) );
				self::assertNull( $this->supplied( $blocked ) );
			}
		}
		public function test_offer_release_answers_only_for_this_live_target(): void {
			$live            = true;
			list( $updater ) = $this->subject(
				installed_version: '2.0.0',
				operation_guard: static function () use ( &$live ): bool {
					return $live;
				}
			);
			self::assertSame( 'keep', $updater->filter_offer_release( 'keep', 'theme', 'package/package.php', 'release:2', 'v2.0.0' ) );
			self::assertSame( 'keep', $updater->filter_offer_release( 'keep', 'plugin', 'other/other.php', 'release:2', 'v2.0.0' ) );
			$this->offer_release( $updater );
			$live = false;
			self::assertNull( $updater->filter_offer_release( null, 'plugin', 'package/package.php', 'release:2', 'v2.0.0' ) );
			self::assertNull( $this->supplied( $updater ) );
			// The unanswered call still replaced the earlier authorisation.
			$live = true;
			self::assertNull( $this->supplied( $updater ) );
		}
		public function test_offer_release_is_stripped_when_wordpress_saves_the_update_check(): void {
			list( $updater, , , $descriptor, $binding ) = $this->subject( installed_version: '2.0.0' );
			$this->offer_release( $updater );
			$entry = $this->supplied( $updater );
			self::assertIsArray( $entry );
			$other  = (object) array( 'package' => 'https://example.test/other.zip' );
			$stored = (object) array(
				'last_checked' => 1,
				'response'     => array(
					'package/package.php' => (object) $entry,
					'other/other.php'     => $other,
				),
			);
			self::assertSame( array( 'other/other.php' => $other ), $updater->filter_store_update( $stored )->response );
			// An ordinary offer for another release is kept.
			$ordinary = (object) array( 'response' => array( 'package/package.php' => (object) array( 'package' => $this->token( $this->with_release_identity( $descriptor, 'release:3', 'v3.0.0' ), $binding ) ) ) );
			self::assertArrayHasKey( 'package/package.php', $updater->filter_store_update( $ordinary )->response );
			self::assertSame( 'not-an-object', $updater->filter_store_update( 'not-an-object' ) );
			// The strip outlives the attempt that consumed the authorisation, until refresh().
			$this->prepare_install( $updater, $entry['package'] );
			$after = (object) array( 'response' => array( 'package/package.php' => (object) $entry ) );
			self::assertSame( array(), $updater->filter_store_update( $after )->response );
		}
		public function test_offer_release_strip_covers_every_release_authorised_in_the_request(): void {
			list( $updater, $adapter, , $descriptor ) = $this->subject( installed_version: '2.0.0' );
			$adapter->inspect_outcomes['release:3']   = $this->with_release_identity( $descriptor, 'release:3', 'v3.0.0' );
			$this->offer_release( $updater );
			$first = $this->supplied( $updater );
			self::assertIsArray( $first );
			$earlier_read = (object) array( 'response' => array( 'package/package.php' => (object) $first ) );
			// A refused second call keeps the first release stripped at save.
			$this->assert_refused( 'candidate_descriptor_mismatch', $this->offer_release( $updater, 'release:2', 'v9.9.9' ) );
			self::assertSame( array(), $updater->filter_store_update( clone $earlier_read )->response );
			// So does a second successful authorisation of another release.
			self::assertSame( 'release_authorized', $this->offer_release( $updater, 'release:3', 'v3.0.0' )['code'] );
			$second = $this->supplied( $updater );
			self::assertIsArray( $second );
			self::assertNotSame( $first['package'], $second['package'] );
			self::assertSame( array(), $updater->filter_store_update( clone $earlier_read )->response );
			self::assertSame( array(), $updater->filter_store_update( (object) array( 'response' => array( 'package/package.php' => (object) $second ) ) )->response );
			// A download is admitted only for the release held now.
			$refused = $updater->filter_pre_download( false, $first['package'], null, $this->extra() );
			self::assertInstanceOf( \WP_Error::class, $refused );
			self::assertSame( 'ran_wp_release_updater_unverified_pre_download', $refused->get_error_code() );
			$this->offer_release( $updater, 'release:3', 'v3.0.0' );
			self::assertIsString( $updater->filter_pre_download( false, $second['package'], null, $this->extra() ) );
			// refresh() ends the strip for both.
			self::assertTrue( $updater->refresh() );
			self::assertArrayHasKey( 'package/package.php', $updater->filter_store_update( clone $earlier_read )->response );
		}
		public function test_offer_release_lifetime_is_request_local(): void {
			list( $updater, $adapter, $database ) = $this->subject( installed_version: '2.0.0' );
			$this->offer_release( $updater );
			$entry = $this->supplied( $updater );
			self::assertIsArray( $entry );
			// A new instance holds nothing.
			$fresh = NativePackageUpdater::from_configuration( $this->config( 'manual', '2.0.0' ), $this->binding( 'manual' ), $adapter, $database, $this->policy() );
			self::assertInstanceOf( NativePackageUpdater::class, $fresh );
			self::assertNull( $this->supplied( $fresh ) );
			self::assertInstanceOf( \WP_Error::class, $fresh->filter_pre_download( false, $entry['package'], null, $this->extra() ) );
			// refresh() clears the authorisation and the save-time strip.
			self::assertTrue( $updater->refresh() );
			self::assertNull( $this->supplied( $updater ) );
			$stored = (object) array( 'response' => array( 'package/package.php' => (object) $entry ) );
			self::assertArrayHasKey( 'package/package.php', $updater->filter_store_update( $stored )->response );
			// An attempt refused before any download still consumes the authorisation.
			$this->offer_release( $updater );
			self::assertInstanceOf( \WP_Error::class, $updater->filter_pre_download( 'not-false', $entry['package'], null, $this->extra() ) );
			self::assertNull( $this->supplied( $updater ) );
			// A refused call replaces an earlier authorisation.
			$this->offer_release( $updater );
			$this->assert_refused( 'candidate_descriptor_mismatch', $this->offer_release( $updater, 'release:2', 'v9.9.9' ) );
			self::assertNull( $this->supplied( $updater ) );
			// A pending install makes the target busy.
			$this->offer_release( $updater );
			self::assertIsString( $updater->filter_pre_download( false, $entry['package'], null, $this->extra() ) );
			$this->assert_refused( 'runtime_busy', $this->offer_release( $updater ) );
		}
		public function test_offer_release_still_refuses_a_release_that_changed_before_download(): void {
			list( $updater, $adapter, , $descriptor ) = $this->subject( installed_version: '2.0.0' );
			$this->offer_release( $updater );
			$entry = $this->supplied( $updater );
			self::assertIsArray( $entry );
			$facts = $descriptor->to_array();
			unset( $facts['fingerprint'] );
			$facts['commit_identity']    = 'changed';
			$adapter->inspect_descriptor = IdentityDescriptor::create( $facts );
			self::assertInstanceOf( \WP_Error::class, $updater->filter_pre_download( false, $entry['package'], null, $this->extra() ) );
			self::assertContains( 'remote_release_changed', $updater->diagnostics() );
			self::assertSame( 2, $adapter->inspect_calls );
			self::assertSame( 1, $adapter->acquire_calls );
		}

		public function test_exact_install_answers_only_once_for_the_matching_live_target(): void {
			list( $updater, $adapter, $database ) = $this->subject( operation_guard: static fn (): bool => false );
			self::assertNull( $updater->filter_install_exact_release( null, 'plugin', 'package/package.php', 'release:2', 'v2.0.0', '2.0.0' ) );
			list( $updater ) = $this->subject( mode: 'disabled', database: $database );
			self::assertSame( 'keep', $updater->filter_install_exact_release( 'keep', 'plugin', 'package/package.php', 'release:2', 'v2.0.0', '2.0.0' ) );
			self::assertNull( $updater->filter_install_exact_release( null, 'theme', 'package/package.php', 'release:2', 'v2.0.0', '2.0.0' ) );
			self::assertNull( $updater->filter_install_exact_release( null, 'plugin', 'other/other.php', 'release:2', 'v2.0.0', '2.0.0' ) );
			self::assertSame(
				array(
					'status'               => 'refused',
					'code'                 => 'policy_disabled',
					'version'              => null,
					'relationship'         => null,
					'finalization_pending' => false,
					'finalization'         => null,
				),
				$updater->filter_install_exact_release( null, 'plugin', 'package/package.php', 'release:2', 'v2.0.0', '2.0.0' )
			);
			self::assertSame( 0, $adapter->inspect_calls );
			self::assertSame( array(), $database->rows() );
		}

		public function test_exact_install_requires_a_valid_listed_version_before_claiming_or_inspecting(): void {
			list( $updater, $adapter, $database ) = $this->subject();
			$report                               = $updater->filter_install_exact_release( null, 'plugin', 'package/package.php', 'release:2', 'v2.0.0', '2.0.0+unknown' );
			self::assertIsArray( $report );
			self::assertSame( 'release_invalid', $report['code'] );
			self::assertSame( 0, $adapter->inspect_calls );
			self::assertSame( array(), $database->rows() );
		}

		public function test_orphaned_exact_install_offer_cannot_be_saved_or_replayed(): void {
			list( $updater, $adapter ) = $this->subject();
			$package                   = 'ran-wp-release-updater:exact:v1:' . str_repeat( 'b', 64 );
			$stored                    = $updater->filter_store_update(
				(object) array(
					'response' => array(
						'package/package.php' => (object) array( 'package' => $package ),
						'other/other.php'     => (object) array( 'package' => 'another-offer' ),
					),
				)
			);
			self::assertInstanceOf( \stdClass::class, $stored );
			self::assertIsArray( $stored->response );
			self::assertSame( array( 'other/other.php' ), array_keys( $stored->response ) );
			$reply = $updater->filter_pre_download( false, $package, null, $this->extra() );
			self::assertInstanceOf( \WP_Error::class, $reply );
			self::assertSame( 'ran_wp_release_updater_unverified_pre_download', $reply->get_error_code() );
			self::assertSame( 0, $adapter->acquire_calls );
		}

		public function test_filter_cached_update_injects_authorized_offer_when_response_is_unset_or_null(): void {
			list( $updater ) = $this->subject();
			$report          = $this->offer_release( $updater );
			self::assertSame( 'authorized', $report['status'] );

			$empty_object = new \stdClass();
			$injected     = $updater->filter_cached_update( $empty_object );
			self::assertInstanceOf( \stdClass::class, $injected );
			self::assertIsArray( $injected->response ?? null );
			self::assertArrayHasKey( 'package/package.php', $injected->response );
			self::assertSame( '2.0.0', $injected->response['package/package.php']->new_version );

			$null_response = (object) array( 'response' => null );
			$injected_null = $updater->filter_cached_update( $null_response );
			self::assertInstanceOf( \stdClass::class, $injected_null );
			self::assertIsArray( $injected_null->response ?? null );
			self::assertArrayHasKey( 'package/package.php', $injected_null->response );
			self::assertSame( '2.0.0', $injected_null->response['package/package.php']->new_version );
		}

		public function test_routine_offer_and_information_leave_candidate_archive_requirements_unknown(): void {
			$archive_path = tempnam( $this->temporary_directory, 'ran-candidate-headers-' );
			self::assertIsString( $archive_path );
			$this->paths[] = $archive_path;
			$zip           = new \ZipArchive();
			self::assertTrue( $zip->open( $archive_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) );
			$zip->addFromString(
				'package/package.php',
				"<?php\n/*\nPlugin Name: Package\nVersion: 2.0.0\nAuthor: Candidate Author\nDescription: Candidate Description\nPlugin URI: https://candidate.example.test/package\nRequires PHP: 8.1\nRequires at least: 6.7\nUpdate URI: {$this->uri()}\n*/"
			);
			$zip->close();

			$descriptor = $this->descriptor( $archive_path );
			$binding    = $this->binding( 'manual' );
			$adapter    = new ControllableReleaseAdapter( $descriptor, $archive_path, $this->temporary_directory );
			$updater    = NativePackageUpdater::from_configuration( $this->config( 'manual' ), $binding, $adapter, new FakeOptionDatabase( 100 ), $this->policy() );
			self::assertInstanceOf( NativePackageUpdater::class, $updater );

			$slug = 'ran-wp-release-updater-' . substr( hash( 'sha256', "plugin\0package/package.php" ), 0, 24 );
			$info = $updater->filter_plugin_information( false, 'plugin_information', (object) array( 'slug' => $slug ) );
			self::assertIsObject( $info );
			self::assertSame( 'A', $info->author ?? null );
			self::assertSame( $this->uri(), $info->homepage ?? null );
			self::assertSame( '', $info->requires ?? null );
			self::assertSame( '', $info->requires_php ?? null );
			self::assertSame( 'D', $info->sections['description'] ?? null );
			// Modal rendering must remain read-only with respect to native check status.
			self::assertNull( $updater->status()['candidate_validation_code'] );
			self::assertNull( $updater->status()['candidate_version'] );
			self::assertNull( $updater->status()['offered_version'] );

			$offer = $this->offer( $updater );
			self::assertSame( '', $offer['requires'] );
			self::assertSame( '', $offer['requires_php'] );
			self::assertSame( 0, $adapter->acquire_calls );
			self::assertSame( 'authorized', $this->offer_release( $updater )['status'] );
			$authorized = $this->supplied( $updater );
			self::assertSame( '6.7', $authorized['requires'] ?? null );
			self::assertSame( '8.1', $authorized['requires_php'] ?? null );
			self::assertSame( 1, $adapter->acquire_calls );
		}

		public function test_filter_offer_release_refusal_writes_no_lock(): void {
			list( $updater, $adapter, $database, $descriptor )            = $this->subject();
			$adapter->acquire_outcomes[ $descriptor->release_identity() ] = new ReleaseFailure( 'package_incompatible', null, 'complete', null, 'header_requires_php_unmet' );

			$report = $this->offer_release( $updater );
			self::assertSame( 'refused', $report['status'] );
			self::assertSame( array(), $database->rows() );
		}

		public function test_verified_install_advances_installed_version_baseline_for_same_request_operations(): void {
			list( $updater, $adapter, , $descriptor ) = $this->subject();
			$adapter->list_response                   = array(
				'candidates'       => array( $this->candidate( $descriptor ) ),
				'not_modified'     => false,
				'search_exhausted' => false,
			);
			$this->complete( $updater );

			$check = $updater->filter_check_releases( null, 'plugin', 'package/package.php' );
			self::assertIsArray( $check );
			self::assertSame( '2.0.0', $check['installed_version'] );
			self::assertSame( 'same', $check['relationship'] );
			self::assertSame( 'no_newer_candidate', $check['reason'] );

			$offer_report = $this->offer_release( $updater );
			self::assertSame( 'authorized', $offer_report['status'] );
			self::assertSame( 'same', $offer_report['relationship'] );
		}

		public function test_mixed_case_update_uri_host_registers_both_canonical_and_raw_host_hooks(): void {
			$mixed_uri                      = 'https://Updates.Example.Test/owner/package';
			$config                         = $this->config( 'manual' );
			$config['headers']['UpdateURI'] = $mixed_uri;
			$config['update_uri']           = $mixed_uri;
			$archive_path                   = $this->archive();
			$descriptor                     = $this->descriptor( $archive_path );
			$binding                        = $this->binding( 'manual' );
			$adapter                        = new ControllableReleaseAdapter( $descriptor, $archive_path, $this->temporary_directory );
			$updater                        = NativePackageUpdater::from_configuration( $config, $binding, $adapter, new FakeOptionDatabase( 100 ), $this->policy() );
			self::assertInstanceOf( NativePackageUpdater::class, $updater );

			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Reset controlled hook capture state used by this fixture.
			$GLOBALS['ran_wp_release_updater_test_hooks'] = array();
			$updater->register();
			$hooks = array_column( $GLOBALS['ran_wp_release_updater_test_hooks'], 1 );
			self::assertContains( 'update_plugins_updates.example.test', $hooks );
			self::assertContains( 'update_plugins_Updates.Example.Test', $hooks );
		}

		public function test_lazy_header_loader_registers_mixed_case_host_and_maps_package_uri(): void {
			$mixed_uri         = 'https://Updates.Example.Test/owner/package';
			$config            = $this->config( 'manual' );
			$config['headers'] = null;
			$archive_path      = $this->archive();
			$descriptor        = $this->descriptor( $archive_path );
			$binding           = $this->binding( 'manual' );
			$adapter           = new ControllableReleaseAdapter( $descriptor, $archive_path, $this->temporary_directory );
			$loader_calls      = 0;
			$updater           = NativePackageUpdater::from_configuration(
				$config,
				$binding,
				$adapter,
				new FakeOptionDatabase( 100 ),
				$this->policy(),
				header_loader: static function () use ( $mixed_uri, &$loader_calls ): array {
					++$loader_calls;
					return array(
						'code'    => 'installed_header_verified',
						'headers' => array(
							'Author'      => 'Installed Author',
							'Description' => 'Installed Description',
							'Name'        => 'Package',
							'PackageURI'  => 'https://homepage.example.test/package',
							'RequiresPHP' => '8.2',
							'RequiresWP'  => '6.5',
							'Template'    => '',
							'UpdateURI'   => $mixed_uri,
							'Version'     => '1.0.0',
						),
					);
				}
			);
			self::assertInstanceOf( NativePackageUpdater::class, $updater );

			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Reset controlled hook capture state used by this fixture.
			$GLOBALS['ran_wp_release_updater_test_hooks'] = array();
			$updater->register();
			self::assertSame( 0, $loader_calls );
			$hooks = array_column( $GLOBALS['ran_wp_release_updater_test_hooks'], 1 );
			self::assertContains( 'update_plugins_updates.example.test', $hooks );
			self::assertContains( 'plugins_update_check_locales', $hooks );
			self::assertNotContains( 'update_plugins_Updates.Example.Test', $hooks );

			self::assertSame( array( 'en_US' ), $updater->prepare_update_check_routing( array( 'en_US' ) ) );
			self::assertSame( 1, $loader_calls );
			$hooks_after = array_column( $GLOBALS['ran_wp_release_updater_test_hooks'], 1 );
			self::assertContains( 'update_plugins_Updates.Example.Test', $hooks_after );

			$offer = $updater->filter_update(
				false,
				array(
					'Version'   => '1.0.0',
					'UpdateURI' => $mixed_uri,
				),
				'package/package.php',
				array()
			);
			self::assertIsArray( $offer );
			$slug = $offer['slug'];
			self::assertIsString( $slug );
			$info = $updater->filter_plugin_information( false, 'plugin_information', (object) array( 'slug' => $slug ) );
			self::assertInstanceOf( \stdClass::class, $info );
			self::assertSame( 'https://homepage.example.test/package', $info->homepage );
		}

		public function test_lazy_header_loader_refresh_rotates_mixed_case_raw_host_hook_and_deduplicates(): void {
			$current_uri       = 'https://Updates.Example.Test/owner/package';
			$config            = $this->config( 'manual' );
			$config['headers'] = null;
			$archive_path      = $this->archive();
			$descriptor        = $this->descriptor( $archive_path );
			$binding           = $this->binding( 'manual' );
			$adapter           = new ControllableReleaseAdapter( $descriptor, $archive_path, $this->temporary_directory );
			$loader_calls      = 0;
			$updater           = NativePackageUpdater::from_configuration(
				$config,
				$binding,
				$adapter,
				new FakeOptionDatabase( 100 ),
				$this->policy(),
				header_loader: static function () use ( &$current_uri, &$loader_calls ): array {
					++$loader_calls;
					return array(
						'code'    => 'installed_header_verified',
						'headers' => array(
							'Author'      => 'Installed Author',
							'Description' => 'Installed Description',
							'Name'        => 'Package',
							'PackageURI'  => 'https://homepage.example.test/package',
							'RequiresPHP' => '8.2',
							'RequiresWP'  => '6.5',
							'Template'    => '',
							'UpdateURI'   => $current_uri,
							'Version'     => '1.0.0',
						),
					);
				}
			);
			self::assertInstanceOf( NativePackageUpdater::class, $updater );

			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Reset controlled hook capture state used by this fixture.
			$GLOBALS['ran_wp_release_updater_test_hooks'] = array();
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Reset controlled hook removal state used by this fixture.
			$GLOBALS['ran_wp_release_updater_test_removed_hooks'] = array();
			$updater->register();
			$updater->prepare_update_check_routing( array( 'en_US' ) );
			$updater->prepare_update_check_routing( array( 'en_US' ) );
			self::assertSame( 1, $loader_calls );
			$initial_raw_hooks = array_values(
				array_filter(
					array_column( $GLOBALS['ran_wp_release_updater_test_hooks'], 1 ),
					static fn ( string $hook ): bool => 'update_plugins_Updates.Example.Test' === $hook
				)
			);
			self::assertCount( 1, $initial_raw_hooks );

			self::assertTrue( $updater->refresh() );
			self::assertContains(
				array( 'filter', 'update_plugins_Updates.Example.Test', array( $updater, 'filter_update' ), 10 ),
				$GLOBALS['ran_wp_release_updater_test_removed_hooks']
			);

			$current_uri = 'https://UPDATES.Example.Test/owner/package';
			$updater->prepare_update_check_routing( array( 'en_US' ) );
			self::assertSame( 2, $loader_calls );
			$rotated_raw_hooks = array_values(
				array_filter(
					array_column( $GLOBALS['ran_wp_release_updater_test_hooks'], 1 ),
					static fn ( string $hook ): bool => 'update_plugins_UPDATES.Example.Test' === $hook
				)
			);
			self::assertCount( 1, $rotated_raw_hooks );
		}

		public function test_lazy_header_loader_failure_preserves_mixed_case_raw_host_routing(): void {
			$mixed_uri         = 'https://Updates.Example.Test/owner/package';
			$config            = $this->config( 'manual' );
			$config['headers'] = null;
			$archive_path      = $this->archive();
			$descriptor        = $this->descriptor( $archive_path );
			$binding           = $this->binding( 'manual' );
			$adapter           = new ControllableReleaseAdapter( $descriptor, $archive_path, $this->temporary_directory );
			$loader_calls      = 0;
			$updater           = NativePackageUpdater::from_configuration(
				$config,
				$binding,
				$adapter,
				new FakeOptionDatabase( 100 ),
				$this->policy(),
				header_loader: static function () use ( $mixed_uri, &$loader_calls ): array {
					++$loader_calls;
					return array(
						'code'           => 'installed_requirement_incompatible',
						'raw_update_uri' => $mixed_uri,
					);
				}
			);
			self::assertInstanceOf( NativePackageUpdater::class, $updater );

			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Reset controlled hook capture state used by this fixture.
			$GLOBALS['ran_wp_release_updater_test_hooks'] = array();
			$updater->register();
			self::assertSame( array( 'en_US' ), $updater->prepare_update_check_routing( array( 'en_US' ) ) );
			self::assertSame( 1, $loader_calls );
			$hooks = array_column( $GLOBALS['ran_wp_release_updater_test_hooks'], 1 );
			self::assertContains( 'update_plugins_Updates.Example.Test', $hooks );

			self::assertFalse(
				$updater->filter_update(
					false,
					array(
						'Version'   => '1.0.0',
						'UpdateURI' => $mixed_uri,
					),
					'package/package.php',
					array()
				)
			);
			self::assertSame( 1, $loader_calls );
			self::assertSame( array( 'runtime_package_identity_invalid' ), $updater->diagnostics() );
			self::assertSame( 0, $adapter->list_calls + $adapter->inspect_calls + $adapter->acquire_calls );
		}

		public function test_same_size_and_mtime_owned_zip_tampering_before_extraction_is_rejected(): void {
			list( $updater, , $database, $descriptor ) = $this->subject();
			$offer                                     = $this->offer( $updater );
			$owned                                     = $updater->filter_pre_download( false, $offer['package'], null, $this->extra() );
			self::assertIsString( $owned );

			clearstatcache( true, $owned );
			$before_stat = lstat( $owned );
			self::assertIsArray( $before_stat );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Mutate one owned fixture byte in place while preserving exact inode and byte length.
			$handle = fopen( $owned, 'r+b' );
			self::assertIsResource( $handle );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Read the first byte so the in-place mutation is guaranteed to change content without changing size.
			$first = fread( $handle, 1 );
			self::assertIsString( $first );
			self::assertSame( 0, fseek( $handle, 0 ) );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Write one altered byte in place without changing file size or inode.
			self::assertSame( 1, fwrite( $handle, 'X' === $first ? 'Y' : 'X' ) );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the fixture stream after in-place mutation.
			fclose( $handle );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch -- Restore the original mtime/atime so stat-only checks could be fooled if ctime matches.
			self::assertTrue( touch( $owned, $before_stat['mtime'], $before_stat['atime'] ) );

			clearstatcache( true, $owned );
			$after_stat = lstat( $owned );
			self::assertIsArray( $after_stat );
			self::assertSame( $before_stat['ino'], $after_stat['ino'] );
			self::assertSame( $before_stat['size'], $after_stat['size'] );
			self::assertSame( $before_stat['mtime'], $after_stat['mtime'] );

			// Align pending archive_identity with the post-tamper stat (including ctime) to prove digest verification rejects same-stat byte tampering.
			$pending = ( new \ReflectionProperty( $updater, 'pending_install' ) )->getValue( $updater );
			self::assertInstanceOf( \RAN\WPReleaseUpdater\V1\WordPress\PendingInstallState::class, $pending );
			$store         = new \RAN\WPReleaseUpdater\V1\WordPress\OwnedArchiveStore();
			$matching_stat = $store->stat_identity( $owned, $descriptor );
			self::assertIsArray( $matching_stat );
			self::assertTrue( $store->same_stat_identity( $owned, $descriptor, $matching_stat ) );
			self::assertFalse( $store->verify_digest_and_stat( $owned, $descriptor, $matching_stat ) );
			( new \ReflectionProperty( $pending, 'archive_identity' ) )->setValue( $pending, $matching_stat );

			$result = $updater->filter_pre_unzip_file( null, $owned, '/tmp', array(), 0.0 );
			self::assertInstanceOf( \WP_Error::class, $result );
			self::assertSame( 'ran_wp_release_updater_archive_changed_before_extraction', $result->get_error_code() );
			self::assertContains( 'archive_changed_before_extraction', $updater->diagnostics() );
			self::assertFileDoesNotExist( $owned );
			self::assertSame( array(), $database->rows() );
		}

		public function test_begin_native_mutation_failure_after_owner_assignment_completes_owner_and_clears_state(): void {
			$database        = new FakeOptionDatabase( 100 );
			$live            = true;
			$completions     = array();
			list( $updater ) = $this->subject(
				database: $database,
				operation_guard: static function () use ( &$live ): bool {
					return $live;
				},
				native_mutation_begin: static function () use ( &$live ): string {
					$live = false;
					return 'owner-lost-fence';
				},
				native_mutation_complete: static function ( string $owner, string $type, string $identity, string $outcome ) use ( &$completions ): void {
					$completions[] = array( $owner, $type, $identity, $outcome );
				}
			);

			$offer         = $this->offer( $updater );
			$owned_archive = $updater->filter_pre_download( false, $offer['package'], null, $this->extra() );
			self::assertIsString( $owned_archive );
			self::assertNull( $updater->filter_pre_unzip_file( null, $owned_archive, '/tmp', array(), 0.0 ) );
			self::assertTrue( $updater->filter_pre_install( true, $this->extra() ) );
			$staged = $this->staged();

			$result = $updater->filter_source_selection( $staged, '/tmp', null, $this->extra() );
			self::assertInstanceOf( \WP_Error::class, $result );
			self::assertSame( 'ran_wp_release_updater_runtime_liveness_lost', $result->get_error_code() );
			self::assertSame( array( array( 'owner-lost-fence', 'plugin', 'package/package.php', 'uncertain' ) ), $completions );
			self::assertNull( ( new \ReflectionProperty( NativePackageUpdater::class, 'native_owner' ) )->getValue( $updater ) );
			self::assertFileDoesNotExist( $owned_archive );
		}

		public function test_repeated_source_selection_while_native_owner_is_held_preserves_custody_until_finalization(): void {
			$database        = new FakeOptionDatabase( 100 );
			$completions     = array();
			list( $updater ) = $this->subject(
				database: $database,
				operation_guard: static fn (): bool => true,
				native_mutation_begin: static fn (): string => 'owner-in-flight',
				native_mutation_complete: static function ( string $owner, string $type, string $identity, string $outcome ) use ( &$completions ): void {
					$completions[] = array( $owner, $type, $identity, $outcome );
				}
			);

			$prepared = $this->prepare_install( $updater );
			$second   = $updater->filter_source_selection( $prepared['stage'], '/tmp', null, $this->extra() );
			self::assertInstanceOf( \WP_Error::class, $second );
			self::assertSame( 'ran_wp_release_updater_runtime_liveness_lost', $second->get_error_code() );
			self::assertSame( array(), $completions );
			self::assertSame( 'owner-in-flight', ( new \ReflectionProperty( NativePackageUpdater::class, 'native_owner' ) )->getValue( $updater ) );
			self::assertCount( 0, $database->rows() );

			$updater->finalize_pending_install();
			self::assertSame( array( array( 'owner-in-flight', 'plugin', 'package/package.php', 'verified_success' ) ), $completions );
			self::assertNull( ( new \ReflectionProperty( NativePackageUpdater::class, 'native_owner' ) )->getValue( $updater ) );
			self::assertCount( 0, $database->rows() );
		}

		public function test_standalone_liveness_loss_fails_closed_for_updater_package_token(): void {
			$state  = new SelectedRuntimeState();
			$broker = new RequestBroker( false, $state );
			$state->bind( $broker );
			( new \ReflectionProperty( $broker, 'state' ) )->setValue( $broker, 'active' );
			$previous_broker = $GLOBALS['ran_wp_release_updater_v1_broker'] ?? null;
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Controlled broker global observed by SelectedRuntimeState::broker_is_live().
			$GLOBALS['ran_wp_release_updater_v1_broker'] = $broker;
			try {
				list( $updater ) = $this->subject( selected_runtime_state: $state );
				$offer           = $this->offer( $updater );
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Displace the broker to simulate standalone runtime liveness loss.
				$GLOBALS['ran_wp_release_updater_v1_broker'] = new \stdClass();

				$reply = $updater->filter_pre_download( false, $offer['package'], null, $this->extra() );
				self::assertInstanceOf( \WP_Error::class, $reply );
				self::assertSame( 'ran_wp_release_updater_runtime_liveness_lost', $reply->get_error_code() );
			} finally {
				if ( null === $previous_broker ) {
					unset( $GLOBALS['ran_wp_release_updater_v1_broker'] );
				} else {
					// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Restore controlled broker global state.
					$GLOBALS['ran_wp_release_updater_v1_broker'] = $previous_broker;
				}
			}
		}

		public function test_invalid_candidate_version_sets_candidate_invalid_validation_code(): void {
			list( $updater, $adapter ) = $this->subject();
			$adapter->list_response    = array(
				'candidates'       => array(
					array(
						'release_identity' => 'release:bad',
						'tag'              => 'vbad',
						'version'          => 'not-a-version',
					),
				),
				'not_modified'     => false,
				'search_exhausted' => false,
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
			self::assertSame( 'candidate_invalid', $updater->status()['candidate_validation_code'] );
		}

		public function test_registered_wp_plugin_paths_symlink_completes_ordinary_native_install(): void {
			$real_dir = $this->temporary_directory . '/shared-plugin-repo';
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create real target directory for symlinked plugin test.
			self::assertTrue( mkdir( $real_dir, 0700, true ) );
			$this->paths[] = $real_dir;

			$symlink_dir = $this->temporary_directory . '/package';
			self::assertTrue( symlink( $real_dir, $symlink_dir ) );
			$this->links[] = $symlink_dir;

			$previous_paths = $GLOBALS['wp_plugin_paths'] ?? null;
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Seed the controlled WordPress $wp_plugin_paths map for symlinked plugin resolution.
			$GLOBALS['wp_plugin_paths'] = array( $symlink_dir => $real_dir );
			try {
				list( $updater ) = $this->subject();
				$offer           = $this->offer( $updater );
				$owned_archive   = $updater->filter_pre_download( false, $offer['package'], null, $this->extra() );
				self::assertIsString( $owned_archive );
				self::assertNull( $updater->filter_pre_unzip_file( null, $owned_archive, '/tmp', array(), 0.0 ) );
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- Simulate Core consuming the archive.
				@unlink( $owned_archive );
				self::assertTrue( $updater->filter_pre_install( true, $this->extra() ) );
				$staged = $this->staged();
				self::assertSame( $staged, $updater->filter_source_selection( $staged, '/tmp', null, $this->extra() ) );
				self::assertTrue( copy( $staged . '/package.php', $real_dir . '/package.php' ) );
				$this->paths[] = $real_dir . '/package.php';
				$updater->capture_install_package_result( array( 'destination' => $symlink_dir ), $this->extra() );
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
				self::assertSame( 'verified_success', $this->retained_outcome( $updater )['outcome'] );
			} finally {
				if ( null === $previous_paths ) {
					unset( $GLOBALS['wp_plugin_paths'] );
				} else {
					// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore controlled WordPress $wp_plugin_paths global state.
					$GLOBALS['wp_plugin_paths'] = $previous_paths;
				}
			}
		}

		public function test_post_update_cache_file_does_not_fail_installed_package_verification(): void {
			list( $updater ) = $this->subject();
			$prepared        = $this->prepare_install( $updater );
			$cache_dir       = $prepared['destination'] . '/cache';
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Simulate a post-update hook creating a cache directory before shutdown.
			self::assertTrue( mkdir( $cache_dir, 0700, true ) );
			$cache_file = $cache_dir . '/routes.php';
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Simulate a post-update hook writing a cache file before shutdown.
			file_put_contents( $cache_file, "<?php return array('cached' => true);\n" );
			$this->paths[] = $cache_file;
			$this->paths[] = $cache_dir;

			$updater->finalize_pending_install();

			self::assertContains( 'update_completed', $updater->diagnostics() );
			self::assertNull( $updater->status()['failure_code'] );
			self::assertSame( 'verified_success', $this->retained_outcome( $updater )['outcome'] );
		}

		public function test_installed_version_mismatch_fails_lightweight_installed_package_verification(): void {
			list( $updater ) = $this->subject();
			$prepared        = $this->prepare_install( $updater );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Simulate rolled-back or stale installed header version before shutdown.
			file_put_contents(
				$prepared['destination'] . '/package.php',
				"<?php\n/*\nPlugin Name: Package\nVersion: 1.0.0\nUpdate URI: {$this->uri()}\n*/"
			);

			$updater->finalize_pending_install();

			self::assertContains( 'outcome_uncertain', $updater->diagnostics() );
			self::assertNotContains( 'update_completed', $updater->diagnostics() );
			self::assertSame( 'uncertain', $this->retained_outcome( $updater )['outcome'] );
		}

		public function test_staged_package_verification_checks_header_identity_without_tree_hashing(): void {
			list( $updater ) = $this->subject();
			$offer           = $this->offer( $updater );
			$owned_archive   = $updater->filter_pre_download( false, $offer['package'], null, $this->extra() );
			self::assertIsString( $owned_archive );
			self::assertNull( $updater->filter_pre_unzip_file( null, $owned_archive, '/tmp', array(), 0.0 ) );
			self::assertTrue( $updater->filter_pre_install( true, $this->extra() ) );

			$bad_stage = $this->staged(
				'package.php',
				"<?php\n/*\nPlugin Name: Package\nVersion: 1.9.9\nUpdate URI: {$this->uri()}\n*/"
			);
			$rejected  = $updater->filter_source_selection( $bad_stage, '/tmp', null, $this->extra() );
			self::assertInstanceOf( \WP_Error::class, $rejected );
			self::assertSame( 'ran_wp_release_updater_staged_package_identity_invalid', $rejected->get_error_code() );

			$offer_two         = $this->offer( $updater );
			$owned_archive_two = $updater->filter_pre_download( false, $offer_two['package'], null, $this->extra() );
			self::assertIsString( $owned_archive_two );
			self::assertNull( $updater->filter_pre_unzip_file( null, $owned_archive_two, '/tmp', array(), 0.0 ) );
			self::assertTrue( $updater->filter_pre_install( true, $this->extra() ) );
			$stage = $this->staged();
			$extra = $stage . '/runtime-generated.txt';
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Prove source selection checks package header/identity without walking the full directory tree.
			file_put_contents( $extra, 'generated-during-staging' );
			$this->paths[] = $extra;
			self::assertSame( $stage, $updater->filter_source_selection( $stage, '/tmp', null, $this->extra() ) );
			$updater->finalize_pending_install();
		}

		public function test_exact_install_invocation_uses_native_word_press_upgraders_without_subclasses(): void {
			$script = <<<'PHP'
<?php
declare(strict_types=1);
require $argv[1];
class WP_Upgrader_Skin {}
class Bulk_Plugin_Upgrader_Skin extends WP_Upgrader_Skin {
	public function flush_output(): void {}
}
class Theme_Upgrader_Skin extends WP_Upgrader_Skin {
	public function feedback( $feedback, ...$args ): void { unset( $feedback, $args ); }
}
class WP_Upgrader {
	public mixed $result = null;
	public function __construct( public WP_Upgrader_Skin $skin ) {}
}
class Plugin_Upgrader extends WP_Upgrader {}
class Theme_Upgrader extends WP_Upgrader {}

$plugin = \RAN\WPReleaseUpdater\V1\WordPress\ExactInstallInvocation::create( 'plugin', 'token-p', '1.0.0' );
$theme  = \RAN\WPReleaseUpdater\V1\WordPress\ExactInstallInvocation::create( 'theme', 'token-t', '1.0.0' );
echo json_encode(
	array(
		'plugin_class' => get_class( $plugin->upgrader ),
		'theme_class'  => get_class( $theme->upgrader ),
		'plugin_skin'  => $plugin->uses_original_skin(),
		'theme_skin'   => $theme->uses_original_skin(),
	),
	JSON_THROW_ON_ERROR
);
PHP;
			$file   = tempnam( $this->temporary_directory, 'ran-exact-upgrader-' );
			self::assertIsString( $file );
			$this->paths[] = $file;
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write isolated subprocess script for native WP_Upgrader class verification.
			file_put_contents( $file, $script );
			$output = array();
			$status = 0;
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- Run minimal WordPress upgrader stubs in an isolated PHP process.
			exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $file ) . ' ' . escapeshellarg( dirname( __DIR__ ) . '/bootstrap.php' ), $output, $status );
			self::assertSame( 0, $status, implode( "\n", $output ) );
			self::assertSame(
				array(
					'plugin_class' => 'Plugin_Upgrader',
					'theme_class'  => 'Theme_Upgrader',
					'plugin_skin'  => true,
					'theme_skin'   => true,
				),
				json_decode( implode( "\n", $output ), true, 64, JSON_THROW_ON_ERROR )
			);
		}

		public function test_exact_install_invocation_disarms_backup_hooks_only_before_backup_move(): void {
			$script = <<<'PHP'
<?php
declare(strict_types=1);
require $argv[1];
$GLOBALS['ran_hooks'] = array();
function add_filter( string $hook, mixed $callback, int $priority = 10, int $accepted_args = 1 ): bool {
	$GLOBALS['ran_hooks'][ $hook ][ $priority ][] = array( 'callback' => $callback, 'args' => $accepted_args );
	return true;
}
function add_action( string $hook, mixed $callback, int $priority = 10, int $accepted_args = 1 ): bool {
	return add_filter( $hook, $callback, $priority, $accepted_args );
}
function remove_filter( string $hook, mixed $callback, int $priority = 10 ): bool {
	if ( ! isset( $GLOBALS['ran_hooks'][ $hook ][ $priority ] ) ) {
		return false;
	}
	$removed = false;
	foreach ( $GLOBALS['ran_hooks'][ $hook ][ $priority ] as $index => $entry ) {
		if ( $entry['callback'] === $callback ) {
			unset( $GLOBALS['ran_hooks'][ $hook ][ $priority ][ $index ] );
			$removed = true;
		}
	}
	if ( array() === $GLOBALS['ran_hooks'][ $hook ][ $priority ] ) {
		unset( $GLOBALS['ran_hooks'][ $hook ][ $priority ] );
	}
	return $removed;
}
function remove_action( string $hook, mixed $callback, int $priority = 10 ): bool {
	return remove_filter( $hook, $callback, $priority );
}
function has_action( string $hook, mixed $callback = false ): bool|int {
	if ( ! isset( $GLOBALS['ran_hooks'][ $hook ] ) ) {
		return false;
	}
	if ( false === $callback ) {
		return array() !== $GLOBALS['ran_hooks'][ $hook ];
	}
	foreach ( $GLOBALS['ran_hooks'][ $hook ] as $priority => $entries ) {
		foreach ( $entries as $entry ) {
			if ( $entry['callback'] === $callback ) {
				return $priority;
			}
		}
	}
	return false;
}
function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
	$priorities = $GLOBALS['ran_hooks'][ $hook ] ?? array();
	ksort( $priorities );
	foreach ( $priorities as $entries ) {
		foreach ( $entries as $entry ) {
			$call_args = array_slice( array_merge( array( $value ), $args ), 0, $entry['args'] );
			$value     = ( $entry['callback'] )( ...$call_args );
		}
	}
	return $value;
}
class WP_Error {
	public function __construct( private string $code, private string $message = '' ) {}
	public function get_error_code(): string { return $this->code; }
}
class WP_Upgrader_Skin {}
class Bulk_Plugin_Upgrader_Skin extends WP_Upgrader_Skin {
	public function flush_output(): void {}
}
class Theme_Upgrader_Skin extends WP_Upgrader_Skin {
	public function feedback( $feedback, ...$args ): void { unset( $feedback, $args ); }
}
class WP_Upgrader {
	public mixed $result = null;
	public function __construct( public WP_Upgrader_Skin $skin ) {}
	/** @param array<string,string> $hook_extra */
	protected function simulate_run( string $scenario, array $hook_extra ): mixed {
		if ( 'source-rejected' === $scenario ) {
			apply_filters( 'upgrader_source_selection', new WP_Error( 'staged_package_identity_invalid' ), '/remote', $this );
			$install_result = new WP_Error( 'staged_package_identity_invalid' );
		} elseif ( 'pre-install-refused' === $scenario ) {
			$install_result = new WP_Error( 'pre_install_refused' );
		} elseif ( 'mkdir-failed' === $scenario ) {
			apply_filters( 'upgrader_source_selection', '/staged/package', '/remote', $this );
			$install_result = new WP_Error( 'fs_temp_backup_mkdir' );
		} elseif ( 'no-content-dir' === $scenario ) {
			apply_filters( 'upgrader_source_selection', '/staged/package', '/remote', $this );
			$install_result = new WP_Error( 'fs_no_content_dir' );
		} elseif ( 'move-failed' === $scenario ) {
			apply_filters( 'upgrader_source_selection', '/staged/package', '/remote', $this );
			$install_result = new WP_Error( 'fs_temp_backup_move' );
		} else {
			apply_filters( 'upgrader_source_selection', '/staged/package', '/remote', $this );
			$install_result = new WP_Error( 'post_copy_failure' );
			// Core populates $this->result once installation reaches upgrader_post_install.
			$this->result = $install_result;
		}
		// Mirror WP_Upgrader::run(): filter install_package() errors through upgrader_install_package_result
		// (where NativePackageUpdater at PHP_INT_MAX rewrites WP_Error to ran_wp_release_updater_unverified_install_result)
		// and leave $this->result null on pre-post-install failures while still hooking shutdown backup callbacks.
		$filtered = apply_filters( 'upgrader_install_package_result', $install_result, $hook_extra );
		add_action( 'shutdown', array( $this, 'restore_temp_backup' ), 10, 0 );
		add_action( 'shutdown', array( $this, 'delete_temp_backup' ), 100, 0 );
		return $filtered;
	}
}
class Plugin_Upgrader extends WP_Upgrader {
	/** @param list<string> $plugins @return array<string,mixed> */
	public function bulk_upgrade( array $plugins ): array {
		// Mirror real Core Plugin_Upgrader::bulk_upgrade(), which omits 'type' and 'action' from hook_extra.
		$hook_extra = array(
			'plugin' => $plugins[0],
		);
		return array( $plugins[0] => $this->simulate_run( $GLOBALS['ran_scenario'], $hook_extra ) );
	}
}
class Theme_Upgrader extends WP_Upgrader {
	public function upgrade( string $theme ): mixed {
		$hook_extra = array(
			'theme'  => $theme,
			'type'   => 'theme',
			'action' => 'update',
		);
		$this->simulate_run( $GLOBALS['ran_scenario'], $hook_extra );
		// Mirror Theme_Upgrader::upgrade(): returns $this->result (null on move-stage failures).
		return $this->result;
	}
}

$summary = array();
foreach ( array( 'plugin', 'theme' ) as $type ) {
	$identity = 'plugin' === $type ? 'package/package.php' : 'package';
	foreach ( array( 'pre-install-refused', 'source-rejected', 'mkdir-failed', 'no-content-dir', 'move-failed', 'post-move-failure' ) as $scenario ) {
		$GLOBALS['ran_hooks']    = array();
		$GLOBALS['ran_scenario'] = $scenario;
		// Mirror NativePackageUpdater::capture_install_package_result() rewriting every WP_Error at PHP_INT_MAX.
		add_filter(
			'upgrader_install_package_result',
			static fn ( mixed $result ): mixed => $result instanceof WP_Error ? new WP_Error( 'ran_wp_release_updater_unverified_install_result' ) : $result,
			PHP_INT_MAX,
			2
		);
		$invocation = \RAN\WPReleaseUpdater\V1\WordPress\ExactInstallInvocation::create( $type, 'token', '1.0.0' );
		$invocation->run( $identity );
		$summary[ $type . ':' . $scenario ] = array(
			'restore_pending'    => $invocation->restore_pending(),
			'delete_pending'     => false !== has_action( 'shutdown', array( $invocation->upgrader, 'delete_temp_backup' ) ),
			'source_hook'        => has_action( 'upgrader_source_selection' ),
			'result_min_hook'    => isset( $GLOBALS['ran_hooks']['upgrader_install_package_result'][ PHP_INT_MIN ] ),
		);
	}
}
echo json_encode( $summary, JSON_THROW_ON_ERROR );
PHP;
			$file   = tempnam( $this->temporary_directory, 'ran-exact-backup-disarm-' );
			self::assertIsString( $file );
			$this->paths[] = $file;
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write isolated subprocess script for ExactInstallInvocation shutdown-hook tests.
			file_put_contents( $file, $script );
			$output = array();
			$status = 0;
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- Run minimal WordPress upgrader stubs in an isolated PHP process.
			exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $file ) . ' ' . escapeshellarg( dirname( __DIR__ ) . '/bootstrap.php' ), $output, $status );
			self::assertSame( 0, $status, implode( "\n", $output ) );
			$actual = json_decode( implode( "\n", $output ), true, 64, JSON_THROW_ON_ERROR );
			self::assertIsArray( $actual );
			foreach ( array( 'plugin', 'theme' ) as $type ) {
				foreach ( array( 'pre-install-refused', 'source-rejected', 'mkdir-failed', 'no-content-dir', 'move-failed' ) as $skipped ) {
					self::assertSame(
						array(
							'restore_pending' => false,
							'delete_pending'  => false,
							'source_hook'     => false,
							'result_min_hook' => false,
						),
						$actual[ $type . ':' . $skipped ] ?? null,
						$type . ':' . $skipped
					);
				}
				self::assertSame(
					array(
						'restore_pending' => true,
						'delete_pending'  => true,
						'source_hook'     => false,
						'result_min_hook' => false,
					),
					$actual[ $type . ':post-move-failure' ] ?? null,
					$type . ':post-move-failure'
				);
			}
		}
		/** @return array<string,mixed> */
		private function retained_outcome( NativePackageUpdater $updater ): array {
			$record = $updater->status()['last_native_outcome'];
			self::assertIsArray( $record );
			self::assertSame( array( 'outcome', 'source', 'wordpress_code', 'release_identity', 'version', 'last_observed_step', 'recorded_at' ), array_keys( $record ) );
			return $record;
		}
		/** @return array{NativePackageUpdater,ControllableReleaseAdapter,FakeOptionDatabase,IdentityDescriptor,array<string,mixed>} */
		private function subject( string $mode = 'manual', ?FakeOptionDatabase $database = null, string $channel = 'stable', bool $prerelease = false, ?SelectedRuntimeState $selected_runtime_state = null, bool $native_discovery_reuse = false, ?PackageIdentityValidator $validator = null, ?callable $operation_guard = null, ?callable $native_mutation_begin = null, ?callable $native_mutation_complete = null, ?callable $descriptor_admission = null, string $installed_version = '1.0.0' ): array {
			$archive_path = $this->archive();
			$descriptor   = $this->descriptor( $archive_path, $channel, $prerelease );
			$binding      = $this->binding( $mode, $channel );
			$adapter      = new ControllableReleaseAdapter( $descriptor, $archive_path, $this->temporary_directory );
			$database   ??= new FakeOptionDatabase( 100 );
			$updater      = NativePackageUpdater::from_configuration( $this->config( $mode, $installed_version ), $binding, $adapter, $database, $this->policy(), $validator, $selected_runtime_state, $native_discovery_reuse, $operation_guard, $native_mutation_begin, $native_mutation_complete, $descriptor_admission );
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
		/** @return array{archive:string,stage:string,destination:string} */
		private function prepare_install( NativePackageUpdater $updater, ?string $package = null, string $reported_route = 'registered' ): array {
			$package     ??= $this->offer( $updater )['package'];
			$owned_archive = $updater->filter_pre_download( false, $package, null, $this->extra() );
			self::assertIsString( $owned_archive );
			self::assertNull( $updater->filter_pre_unzip_file( null, $owned_archive, '/tmp', array(), 0.0 ) );
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- Simulate Core consuming the acquired archive; it may already be absent after extraction. Remove native fixture entries directly, preserving the surrounding ownership and link-handling checks.
			@unlink( $owned_archive );
			self::assertTrue( $updater->filter_pre_install( true, $this->extra() ) );
			$staged_package = $this->staged();
			self::assertSame( $staged_package, $updater->filter_source_selection( $staged_package, '/tmp', null, $this->extra() ) );
			$destination = $this->reported_destination( $this->install_staged( $staged_package ), $reported_route );
			$updater->capture_install_package_result( array( 'destination' => $destination ), $this->extra() );
			$updater->observe_completion(
				null,
				array(
					'action'  => 'update',
					'type'    => 'plugin',
					'plugins' => array( 'package/package.php' ),
				)
			);
			return array(
				'archive'     => $owned_archive,
				'stage'       => $staged_package,
				'destination' => $destination,
			);
		}
		private function complete( NativePackageUpdater $updater, ?string $package = null ): void {
			$this->prepare_install( $updater, $package );
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
		private function staged( string $header = 'package.php', ?string $contents = null ): string {
			$parent_path = $this->temporary_directory . '/ran-native-stage-' . bin2hex( random_bytes( 5 ) );
			$staged_path = $parent_path . '/package';
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create real directories for installed-package lifecycle fixtures with the specified permissions.
			mkdir( $staged_path, 0700, true );
			$this->paths[] = $staged_path;
			$this->paths[] = $parent_path;
			$contents    ??= "<?php\n/*\nPlugin Name: Package\nVersion: 2.0.0\nUpdate URI: {$this->uri()}\n*/";
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write exact bytes for installed-package lifecycle fixtures; WordPress helpers would alter the boundary under test.
			file_put_contents( $staged_path . '/' . $header, $contents );
			return $staged_path;
		}
		private function installed_directory(): string {
			return $this->temporary_directory . '/package';
		}
		/** Model the destination Core reports: the registered path, a symlinked root, an alternate same-slug directory, or a linked leaf. */
		private function reported_destination( string $installed, string $route, string $header = 'package.php' ): string {
			if ( 'registered' === $route ) {
				return $installed;
			}
			$root = $this->temporary_directory . '/reported-' . bin2hex( random_bytes( 5 ) );
			if ( 'symlinked_root' === $route ) {
				self::assertTrue( symlink( dirname( $installed ), $root ) );
				$this->links[] = $root;
				return $root . '/' . basename( $installed );
			}
			$destination = $root . '/' . basename( $installed );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create real directories for installed-package lifecycle fixtures with the specified permissions.
			self::assertTrue( mkdir( $root, 0700 ) );
			if ( 'symlinked_leaf' === $route ) {
				self::assertTrue( symlink( $installed, $destination ) );
				$this->links[] = $destination;
			} else {
				self::assertSame( 'alternate_directory', $route );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create real directories for installed-package lifecycle fixtures with the specified permissions.
				self::assertTrue( mkdir( $destination, 0700 ) );
				self::assertTrue( copy( $installed . '/' . $header, $destination . '/' . $header ) );
				$this->paths[] = $destination;
			}
			$this->paths[] = $root;
			return $destination;
		}
		private function install_staged( string $stage, string $header = 'package.php' ): string {
			$destination = $this->installed_directory();
			if ( is_dir( $destination ) ) {
				$backup = $this->temporary_directory . '/backup-' . bin2hex( random_bytes( 5 ) );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Model Core moving the old installed directory out of the way, including any hidden marker.
				self::assertTrue( rename( $destination, $backup ) );
				$this->paths[] = $backup . '/.git';
				$this->paths[] = $backup;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create the actual registered destination for installed-manifest verification.
			self::assertTrue( mkdir( $destination, 0700 ) );
			$this->paths[] = $destination;
			self::assertTrue( copy( $stage . '/' . $header, $destination . '/' . $header ) );
			return $destination;
		}
		/** @return array<string,mixed> */
		private function config( string $mode, string $installed_version = '1.0.0' ): array {
			return array(
				'installed_directory'        => $this->installed_directory(),
				'headers'                    => array(
					'Author'      => 'A',
					'Description' => 'D',
					'Name'        => 'Package',
					'PluginURI'   => $this->uri(),
					'RequiresPHP' => '8.2',
					'RequiresWP'  => '6.8',
					'UpdateURI'   => $this->uri(),
					'Version'     => $installed_version,
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
		/** @return array<string,mixed> */
		private function binding( string $mode, string $channel = 'stable' ): array {
			return array(
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
		/** @param array<string,mixed> $binding */
		private function token( IdentityDescriptor $descriptor, array $binding ): string {
			$normalized = NativePackageUpdater::normalize_target_binding( $binding );
			self::assertIsArray( $normalized );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Native JSON preserves protocol or fixture bytes without requiring WordPress helpers or their fallback behavior.
			$binding_hash = hash( 'sha256', json_encode( $normalized, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
			$value        = array(
				'binding_hash' => $binding_hash,
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
