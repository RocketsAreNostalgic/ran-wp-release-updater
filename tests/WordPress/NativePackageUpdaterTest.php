<?php
declare(strict_types=1);
namespace {
	if ( ! class_exists( 'WP_Error' ) ) {
		final class WP_Error {
			public function __construct( public string $code, public string $message ) {}
		}
	}
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
	if ( ! function_exists( 'get_filesystem_method' ) ) {
		function get_filesystem_method(): string {
			return 'direct';
		}
	}
}

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
	use RAN\WPReleaseUpdater\V1\WordPress\ReleaseOperationCoordinator;
	use Tests\Support\ControllableReleaseAdapter;
	use Tests\Support\FakeOptionDatabase;
	final class NativePackageUpdaterTest extends TestCase {
		/** @var list<string> */
		private array $paths = array();
		private string $temporaryDirectory;
		protected function setUp(): void {
			$this->temporaryDirectory = dirname( __DIR__, 2 ) . '/.workspaces/p0.2/php-tmp/native-package-updater-' . bin2hex( random_bytes( 6 ) );
			mkdir( $this->temporaryDirectory, 0700, true );
			$GLOBALS['ran_wp_release_updater_test_hooks']            = array();
			$GLOBALS['ran_wp_release_updater_test_filter_callbacks'] = array();
		}
		protected function tearDown(): void {
			foreach ( $this->paths as $path ) {
				if ( is_file( $path ) ) {
					@unlink( $path );
				}
				if ( is_dir( $path ) ) {
					foreach ( glob( $path . '/*' ) ?: array() as $childPath ) {
						@unlink( $childPath );
					}
					@rmdir( $path );
				}
			}
			@rmdir( $this->temporaryDirectory );
		}
		public function testStateConstructionAndRegisterArePassive(): void {
			list( $updater, $adapter, $database ) = $this->subject();
			self::assertSame( array( 0, 0, 0 ), array( $adapter->listCalls, $adapter->inspectCalls, $adapter->acquireCalls ) );
			self::assertSame( array(), $database->preparedSql() );
			self::assertSame( array(), $database->readOptionNames() );
			$updater->register();
			$updater->register();
			self::assertCount( 10, $GLOBALS['ran_wp_release_updater_test_hooks'] );
			self::assertSame( array( 0, 0, 0 ), array( $adapter->listCalls, $adapter->inspectCalls, $adapter->acquireCalls ) );
		}
		public function testOfferRechecksRuntimeUriUsesUniqueInfoSlugAndDefaultDeniesAutomatic(): void {
			list( $manualUpdater ) = $this->subject();
			$manualOffer           = $this->offer( $manualUpdater );
			self::assertFalse( $manualOffer['autoupdate'] );
			self::assertFalse(
				$manualUpdater->filterAutoUpdate(
					true,
					(object) array(
						'plugin'  => 'package/package.php',
						'package' => $manualOffer['package'],
					)
				)
			);
			list( $automaticUpdater ) = $this->subject( 'automatic' );
			$automaticOffer           = $this->offer( $automaticUpdater );
			self::assertTrue( $automaticOffer['autoupdate'] );
			self::assertTrue(
				$automaticUpdater->filterAutoUpdate(
					false,
					(object) array(
						'plugin'  => 'package/package.php',
						'package' => $automaticOffer['package'],
					)
				)
			);
		}
		public function testEligibleNativeDiscoveryReusesOnlyItsValidatedRequestSnapshot(): void {
			list( $updater, $adapter ) = $this->subject( 'manual', null, 'stable', false, null, true );
			$this->offer( $updater );
			$this->offer( $updater );
			$information = $updater->filterPluginInformation( false, 'plugin_information', (object) array( 'slug' => 'ran-wp-release-updater-' . substr( hash( 'sha256', 'plugin' . "\0" . 'package/package.php' ), 0, 24 ) ) );
			self::assertIsObject( $information );
			self::assertSame( '2.0.0', $information->version );
			self::assertSame( array( 1, 1, 1 ), array( $adapter->listCalls, $adapter->inspectCalls, $adapter->acquireCalls ) );
			self::assertSame( 'archive_identity_verified', $updater->status()['candidate_validation_code'] );

			self::assertIsArray(
				$updater->filterUpdate(
					false,
					array(
						'Version'   => '1.1.0',
						'UpdateURI' => $this->uri(),
					),
					'package/package.php',
					array()
				)
			);
			self::assertSame( array( 2, 2, 2 ), array( $adapter->listCalls, $adapter->inspectCalls, $adapter->acquireCalls ) );
			self::assertTrue( $updater->refresh() );
			$this->offer( $updater );
			self::assertSame( array( 3, 3, 3 ), array( $adapter->listCalls, $adapter->inspectCalls, $adapter->acquireCalls ) );
		}
		public function testReentrantMatchingInstallAttemptPreventsDiscoveryFromPublishingASnapshot(): void {
			$validator                 = new PackageIdentityValidator();
			list( $updater, $adapter ) = $this->subject( 'manual', null, 'stable', false, null, true, $validator );
			$afterOpen                 = new \ReflectionProperty( $validator, 'afterOpen' );
			$afterOpen->setValue(
				$validator,
				static function ( string $path ) use ( $updater ): void {
					unset( $path );
					$updater->filterPreDownload( new \WP_Error( 'interrupted', 'Interrupted.' ), '', null, array( 'plugin' => 'package/package.php' ) );
				}
			);
			$this->offer( $updater );
			$this->offer( $updater );
			self::assertSame( array( 2, 2, 2 ), array( $adapter->listCalls, $adapter->inspectCalls, $adapter->acquireCalls ) );
		}
		public function testReentrantRefreshPreventsDiscoveryFromPublishingASnapshot(): void {
			$validator                 = new PackageIdentityValidator();
			list( $updater, $adapter ) = $this->subject( 'manual', null, 'stable', false, null, true, $validator );
			$afterOpen                 = new \ReflectionProperty( $validator, 'afterOpen' );
			$afterOpen->setValue(
				$validator,
				static function ( string $path ) use ( $updater ): void {
					unset( $path );
					$updater->refresh();
				}
			);
			$this->offer( $updater );
			$this->offer( $updater );
			self::assertSame( array( 2, 2, 2 ), array( $adapter->listCalls, $adapter->inspectCalls, $adapter->acquireCalls ) );
		}
		public function testClaimTakeoverAndExpiryReclaimNeverReuseASnapshot(): void {
			list( $updater, $adapter, $database, , $binding ) = $this->subject( 'manual', null, 'stable', false, null, true );
			$this->offer( $updater );
			$name      = 'ran_wp_release_updater_target_v1_' . BindingRecord::targetFenceKey(
				array(
					'network_id'                 => 1,
					'target_type'                => 'plugin',
					'installed_package_identity' => 'package/package.php',
				)
			);
			$state     = BindingState::rehydrate( json_decode( $database->rows()[ $name ]['option_value'], true, 32, JSON_THROW_ON_ERROR ) );
			$successor = BindingState::create( $binding, str_repeat( 'b', 64 ), 101, $state->bindingGeneration() + 1, $state->fenceEpoch() + 1 );
			$database->forceOptionValue( $name, json_encode( $successor->toArray(), JSON_THROW_ON_ERROR ) );
			self::assertFalse(
				$updater->filterUpdate(
					false,
					array(
						'Version'   => '1.0.0',
						'UpdateURI' => $this->uri(),
					),
					'package/package.php',
					array()
				)
			);
			$database->setTime( 102 );
			$this->offer( $updater );
			self::assertSame( array( 2, 2, 2 ), array( $adapter->listCalls, $adapter->inspectCalls, $adapter->acquireCalls ) );
		}
		public function testFailedDiscoveryAndRuntimeDisplacementNeverReuseASnapshot(): void {
			list( $updater, $adapter, , $descriptor ) = $this->subject( 'manual', null, 'stable', false, null, true );
			$facts                                    = $descriptor->toArray();
			unset( $facts['fingerprint'] );
			$facts['tag']               = 'v2.0.1';
			$adapter->inspectDescriptor = IdentityDescriptor::create( $facts );
			self::assertFalse(
				$updater->filterUpdate(
					false,
					array(
						'Version'   => '1.0.0',
						'UpdateURI' => $this->uri(),
					),
					'package/package.php',
					array()
				)
			);
			$adapter->inspectDescriptor = $descriptor;
			$this->offer( $updater );
			self::assertSame( array( 2, 2, 1 ), array( $adapter->listCalls, $adapter->inspectCalls, $adapter->acquireCalls ) );
		}
		public function testRuntimeDisplacementAndRestoreNeverReuseASnapshot(): void {
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
					$updater->filterUpdate(
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
				self::assertSame( array( 2, 2, 2 ), array( $adapter->listCalls, $adapter->inspectCalls, $adapter->acquireCalls ) );
			} finally {
				unset( $GLOBALS['ran_wp_release_updater_v1_broker'] );
			}
		}
		public function testMatchingCompletionInvalidatesDiscoverySnapshot(): void {
			list( $updater, $adapter ) = $this->subject( 'manual', null, 'stable', false, null, true );
			$this->offer( $updater );
			$updater->observeCompletion(
				null,
				array(
					'action'  => 'update',
					'type'    => 'plugin',
					'plugins' => array( 'package/package.php' ),
				)
			);
			self::assertNull( $updater->status()['offered_release_identity'] );
			$this->offer( $updater );
			self::assertSame( array( 2, 2, 2 ), array( $adapter->listCalls, $adapter->inspectCalls, $adapter->acquireCalls ) );
		}
		public function testStatusProjectsOnlyTheObservedOfferAndFailureAndRefreshClearsIt(): void {
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
			self::assertSame( array( 0, 0, 0 ), array( $adapter->listCalls, $adapter->inspectCalls, $adapter->acquireCalls ) );
			self::assertSame( array(), $database->preparedSql() );
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
			$updater->filterUpdate(
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
		public function testListingRateLimitFactsStopBeforeAnyCandidateInspection(): void {
			list( $updater, $adapter, , $descriptor ) = $this->subject();
			$adapter->listResponse                    = array(
				'candidates' => array( $this->candidate( $descriptor ) ),
				'rate_limit' => array(
					'limited'     => true,
					'remaining'   => 0,
					'reset_at'    => null,
					'retry_after' => 60,
				),
			);

			self::assertFalse(
				$updater->filterUpdate(
					false,
					array(
						'Version'   => '1.0.0',
						'UpdateURI' => $this->uri(),
					),
					'package/package.php',
					array()
				)
			);
			self::assertSame( array( 1, 0, 0 ), array( $adapter->listCalls, $adapter->inspectCalls, $adapter->acquireCalls ) );
			self::assertSame( 'release_list_failed', $updater->status()['candidate_validation_code'] );
		}
		public function testThemeListingRateLimitFactsStopBeforeAnyCandidateInspection(): void {
			$archive = $this->archive();
			$facts   = $this->descriptor( $archive )->toArray();
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
			$adapter                                     = new ControllableReleaseAdapter( $descriptor, $archive, $this->temporaryDirectory );
			$adapter->listResponse                       = array(
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
			$updater                                     = NativePackageUpdater::fromConfiguration( $configuration, $binding, $adapter, new FakeOptionDatabase( 100 ), $policy );

			self::assertInstanceOf( NativePackageUpdater::class, $updater );
			self::assertFalse(
				$updater->filterUpdate(
					false,
					array(
						'Version'   => '1.0.0',
						'UpdateURI' => $this->uri(),
					),
					'package',
					array()
				)
			);
			self::assertSame( array( 1, 0, 0 ), array( $adapter->listCalls, $adapter->inspectCalls, $adapter->acquireCalls ) );
			self::assertSame( 'release_list_failed', $updater->status()['candidate_validation_code'] );
		}
		public function testMalformedCandidateStopsDiscoveryBeforeALaterCandidate(): void {
			list( $updater, $adapter, , $descriptor ) = $this->subject();
			$adapter->listResponse                    = array(
				'candidates' => array(
					array(
						'release_identity' => 'release:invalid',
						'tag'              => 'v2.0.0',
					),
					$this->candidate( $descriptor ),
				),
			);

			self::assertFalse(
				$updater->filterUpdate(
					false,
					array(
						'Version'   => '1.0.0',
						'UpdateURI' => $this->uri(),
					),
					'package/package.php',
					array()
				)
			);
			self::assertSame( array( 1, 0, 0 ), array( $adapter->listCalls, $adapter->inspectCalls, $adapter->acquireCalls ) );
			self::assertSame( 'candidate_invalid', $updater->status()['candidate_validation_code'] );
		}
		public function testDescriptorMismatchStopsDiscoveryBeforeALaterCandidate(): void {
			list( $updater, $adapter, , $first ) = $this->subject();
			$second                              = $this->withReleaseIdentity( $first, 'release:3', 'v2.0.1' );
			$adapter->listResponse               = array( 'candidates' => array( $this->candidate( $first ), $this->candidate( $second ) ) );
			$adapter->inspectOutcomes[ $first->releaseIdentity() ]  = $second;
			$adapter->inspectOutcomes[ $second->releaseIdentity() ] = $second;

			self::assertFalse(
				$updater->filterUpdate(
					false,
					array(
						'Version'   => '1.0.0',
						'UpdateURI' => $this->uri(),
					),
					'package/package.php',
					array()
				)
			);
			self::assertSame( array( 1, 1, 0 ), array( $adapter->listCalls, $adapter->inspectCalls, $adapter->acquireCalls ) );
			self::assertSame( 'candidate_descriptor_mismatch', $updater->status()['candidate_validation_code'] );
		}
		#[\PHPUnit\Framework\Attributes\DataProvider( 'operationStoppingInspectionFailures' )]
		public function testOperationStoppingInspectionFailuresDoNotReachLaterCandidates( \Throwable $failure ): void {
			list( $updater, $adapter, , $first ) = $this->subject();
			$second                              = $this->withReleaseIdentity( $first, 'release:3', 'v2.0.1' );
			$adapter->listResponse               = array( 'candidates' => array( $this->candidate( $first ), $this->candidate( $second ) ) );
			$adapter->inspectOutcomes[ $first->releaseIdentity() ]  = $failure;
			$adapter->inspectOutcomes[ $second->releaseIdentity() ] = $second;

			self::assertFalse(
				$updater->filterUpdate(
					false,
					array(
						'Version'   => '1.0.0',
						'UpdateURI' => $this->uri(),
					),
					'package/package.php',
					array()
				)
			);
			self::assertSame( array( 1, 1, 0 ), array( $adapter->listCalls, $adapter->inspectCalls, $adapter->acquireCalls ) );
			self::assertSame( 'candidate_inspection_failed', $updater->status()['candidate_validation_code'] );
		}
		/** @return array<string,array{\Throwable}> */
		public static function operationStoppingInspectionFailures(): array {
			return array(
				'rate limited'                  => array( new ReleaseFailure( 'rate_limited', 60 ) ),
				'repository access unavailable' => array( new ReleaseFailure( 'repository_access_unavailable' ) ),
				'operation failed'              => array( new ReleaseFailure( 'operation_failed' ) ),
				'cleanup failed'                => array( new ReleaseFailure( 'package_incompatible', null, 'failed' ) ),
				'unknown exception'             => array( new \RuntimeException( 'unexpected' ) ),
			);
		}
		#[\PHPUnit\Framework\Attributes\DataProvider( 'candidateLocalInspectionFailures' )]
		public function testCandidateLocalInspectionFailuresAllowALaterValidCandidate( ReleaseFailure $failure ): void {
			list( $updater, $adapter, , $first ) = $this->subject();
			$second                              = $this->withReleaseIdentity( $first, 'release:3', 'v2.0.1' );
			$adapter->listResponse               = array( 'candidates' => array( $this->candidate( $first ), $this->candidate( $second ) ) );
			$adapter->inspectOutcomes[ $first->releaseIdentity() ]  = $failure;
			$adapter->inspectOutcomes[ $second->releaseIdentity() ] = $second;

			$offer = $updater->filterUpdate(
				false,
				array(
					'Version'   => '1.0.0',
					'UpdateURI' => $this->uri(),
				),
				'package/package.php',
				array()
			);
			self::assertIsArray( $offer );
			self::assertSame( array( 1, 2, 1 ), array( $adapter->listCalls, $adapter->inspectCalls, $adapter->acquireCalls ) );
			self::assertSame( 'release:3', $updater->status()['offered_release_identity'] );
		}
		/** @return array<string,array{ReleaseFailure}> */
		public static function candidateLocalInspectionFailures(): array {
			return array(
				'concrete release unavailable' => array( new ReleaseFailure( 'release_unavailable', null, 'complete' ) ),
				'package incompatible'         => array( new ReleaseFailure( 'package_incompatible', null, 'complete' ) ),
			);
		}
		#[\PHPUnit\Framework\Attributes\DataProvider( 'operationStoppingAcquisitionFailures' )]
		public function testOperationStoppingAcquisitionFailuresDoNotReachLaterCandidates( \Throwable $failure ): void {
			list( $updater, $adapter, , $first ) = $this->subject();
			$second                              = $this->withReleaseIdentity( $first, 'release:3', 'v2.0.1' );
			$adapter->listResponse               = array( 'candidates' => array( $this->candidate( $first ), $this->candidate( $second ) ) );
			$adapter->inspectOutcomes[ $first->releaseIdentity() ]  = $first;
			$adapter->inspectOutcomes[ $second->releaseIdentity() ] = $second;
			$adapter->acquireOutcomes[ $first->releaseIdentity() ]  = $failure;

			self::assertFalse(
				$updater->filterUpdate(
					false,
					array(
						'Version'   => '1.0.0',
						'UpdateURI' => $this->uri(),
					),
					'package/package.php',
					array()
				)
			);
			self::assertSame( array( 1, 1, 1 ), array( $adapter->listCalls, $adapter->inspectCalls, $adapter->acquireCalls ) );
			self::assertSame( 'candidate_validation_failed', $updater->status()['candidate_validation_code'] );
		}
		/** @return array<string,array{\Throwable}> */
		public static function operationStoppingAcquisitionFailures(): array {
			return array(
				'rate limited'      => array( new ReleaseFailure( 'rate_limited', 60 ) ),
				'operation failed'  => array( new ReleaseFailure( 'operation_failed' ) ),
				'unknown exception' => array( new \RuntimeException( 'unexpected' ) ),
			);
		}
		#[\PHPUnit\Framework\Attributes\DataProvider( 'candidateLocalAcquisitionFailures' )]
		public function testCandidateLocalAcquisitionFailuresAllowALaterValidCandidate( ReleaseFailure $failure ): void {
			list( $updater, $adapter, , $first ) = $this->subject();
			$second                              = $this->withReleaseIdentity( $first, 'release:3', 'v2.0.1' );
			$adapter->listResponse               = array( 'candidates' => array( $this->candidate( $first ), $this->candidate( $second ) ) );
			$adapter->inspectOutcomes[ $first->releaseIdentity() ]  = $first;
			$adapter->inspectOutcomes[ $second->releaseIdentity() ] = $second;
			$adapter->acquireOutcomes[ $first->releaseIdentity() ]  = $failure;

			self::assertIsArray(
				$updater->filterUpdate(
					false,
					array(
						'Version'   => '1.0.0',
						'UpdateURI' => $this->uri(),
					),
					'package/package.php',
					array()
				)
			);
			self::assertSame( array( 1, 2, 2 ), array( $adapter->listCalls, $adapter->inspectCalls, $adapter->acquireCalls ) );
			self::assertSame( 'release:3', $updater->status()['offered_release_identity'] );
		}
		/** @return array<string,array{ReleaseFailure}> */
		public static function candidateLocalAcquisitionFailures(): array {
			return array(
				'concrete release unavailable' => array( new ReleaseFailure( 'release_unavailable' ) ),
				'package incompatible'         => array( new ReleaseFailure( 'package_incompatible' ) ),
			);
		}
		public function testFailedArtifactCleanupStopsDiscoveryBeforeALaterCandidate(): void {
			$validator = new PackageIdentityValidator();
			$afterOpen = new \ReflectionProperty( $validator, 'afterOpen' );
			$afterOpen->setValue(
				$validator,
				static function ( string $path ): void {
					chmod( $path, 0644 );
				}
			);
			list( $updater, $adapter, , $first ) = $this->subject( 'manual', null, 'stable', false, null, false, $validator );
			$second                              = $this->withReleaseIdentity( $first, 'release:3', 'v2.0.1' );
			$adapter->listResponse               = array( 'candidates' => array( $this->candidate( $first ), $this->candidate( $second ) ) );
			$adapter->inspectOutcomes[ $first->releaseIdentity() ]  = $first;
			$adapter->inspectOutcomes[ $second->releaseIdentity() ] = $second;

			self::assertFalse(
				$updater->filterUpdate(
					false,
					array(
						'Version'   => '1.0.0',
						'UpdateURI' => $this->uri(),
					),
					'package/package.php',
					array()
				)
			);
			self::assertSame( array( 1, 1, 1 ), array( $adapter->listCalls, $adapter->inspectCalls, $adapter->acquireCalls ) );
			self::assertSame( 'candidate_validation_failed', $updater->status()['candidate_validation_code'] );
			self::assertFileExists( $adapter->acquiredPaths[0] );
		}
		public function testUnexpectedValidationFailureDiscardsAnUnchangedArtifactAndStopsDiscovery(): void {
			$validator = new PackageIdentityValidator();
			$afterOpen = new \ReflectionProperty( $validator, 'afterOpen' );
			$afterOpen->setValue(
				$validator,
				static function ( string $path ): void {
					unset( $path );
					throw new \RuntimeException( 'unexpected validation failure' );
				}
			);
			list( $updater, $adapter, , $first ) = $this->subject( 'manual', null, 'stable', false, null, false, $validator );
			$second                              = $this->withReleaseIdentity( $first, 'release:3', 'v2.0.1' );
			$adapter->listResponse               = array( 'candidates' => array( $this->candidate( $first ), $this->candidate( $second ) ) );
			$adapter->inspectOutcomes[ $first->releaseIdentity() ]  = $first;
			$adapter->inspectOutcomes[ $second->releaseIdentity() ] = $second;

			self::assertFalse(
				$updater->filterUpdate(
					false,
					array(
						'Version'   => '1.0.0',
						'UpdateURI' => $this->uri(),
					),
					'package/package.php',
					array()
				)
			);
			self::assertSame( array( 1, 1, 1 ), array( $adapter->listCalls, $adapter->inspectCalls, $adapter->acquireCalls ) );
			self::assertSame( 'candidate_validation_failed', $updater->status()['candidate_validation_code'] );
			self::assertNull( $updater->status()['offered_release_identity'] );
			self::assertFileDoesNotExist( $adapter->acquiredPaths[0] );
		}
		public function testUnexpectedValidationFailurePreservesChangedArtifactAndStopsDiscovery(): void {
			$validator = new PackageIdentityValidator();
			$afterOpen = new \ReflectionProperty( $validator, 'afterOpen' );
			$afterOpen->setValue(
				$validator,
				static function ( string $path ): void {
					file_put_contents( $path, 'replacement bytes' );
					throw new \RuntimeException( 'unexpected validation failure' );
				}
			);
			list( $updater, $adapter, , $first ) = $this->subject( 'manual', null, 'stable', false, null, false, $validator );
			$second                              = $this->withReleaseIdentity( $first, 'release:3', 'v2.0.1' );
			$adapter->listResponse               = array( 'candidates' => array( $this->candidate( $first ), $this->candidate( $second ) ) );
			$adapter->inspectOutcomes[ $first->releaseIdentity() ]  = $first;
			$adapter->inspectOutcomes[ $second->releaseIdentity() ] = $second;

			self::assertFalse(
				$updater->filterUpdate(
					false,
					array(
						'Version'   => '1.0.0',
						'UpdateURI' => $this->uri(),
					),
					'package/package.php',
					array()
				)
			);
			self::assertSame( array( 1, 1, 1 ), array( $adapter->listCalls, $adapter->inspectCalls, $adapter->acquireCalls ) );
			self::assertSame( 'candidate_validation_failed', $updater->status()['candidate_validation_code'] );
			self::assertNull( $updater->status()['offered_release_identity'] );
			self::assertFileExists( $adapter->acquiredPaths[0] );
			self::assertSame( 'replacement bytes', file_get_contents( $adapter->acquiredPaths[0] ) );
		}
		public function testOfferStatusBindsTheExactVerifiedReleaseIdentityRatherThanTheVersion(): void {
			list( $updater, $adapter, , $descriptor ) = $this->subject();
			$this->offer( $updater );
			self::assertSame( 'release:2', $updater->status()['offered_release_identity'] );
			$facts = $descriptor->toArray();
			unset( $facts['fingerprint'] );
			$facts['release_identity']  = 'release:3';
			$replacement                = IdentityDescriptor::create( $facts );
			$adapter->inspectDescriptor = $replacement;
			( new \ReflectionProperty( $adapter, 'descriptor' ) )->setValue( $adapter, $replacement );
			$this->offer( $updater );
			self::assertSame( '2.0.0', $updater->status()['offered_version'] );
			self::assertSame( 'release:3', $updater->status()['offered_release_identity'] );
		}
		public function testPrereleaseChannelOfferIsManualAndAutomaticIsDenied(): void {
			list( $updater ) = $this->subject( 'manual', null, 'prerelease', true );
			$offer           = $this->offer( $updater );
			self::assertFalse( $offer['autoupdate'] );
			self::assertFalse(
				$updater->filterAutoUpdate(
					true,
					(object) array(
						'plugin'  => 'package/package.php',
						'package' => $offer['package'],
					)
				)
			);
		}
		public function testCanonicalStaleAndOlderTokensAreRejectedAtAutomaticAndDownloadAdmission(): void {
			list( $updater, $adapter, , $descriptor, $binding ) = $this->subject( 'automatic' );
			foreach ( array( '1.0.0', '0.9.0' ) as $version ) {
				$facts = $descriptor->toArray();
				unset( $facts['fingerprint'] );
				$facts['version'] = $version;
				$facts['tag']     = 'v' . $version;
				$token            = $this->token( IdentityDescriptor::create( $facts ), $binding );
				self::assertFalse(
					$updater->filterAutoUpdate(
						true,
						(object) array(
							'plugin'  => 'package/package.php',
							'package' => $token,
						)
					)
				);
				self::assertInstanceOf( \WP_Error::class, $updater->filterPreDownload( false, $token, null, $this->extra() ) );
			}
			self::assertSame( array( 0, 0, 0 ), array( $adapter->listCalls, $adapter->inspectCalls, $adapter->acquireCalls ) );
		}
		public function testNoncanonicalAndTamperedTokensAreRejectedWithoutInstallCalls(): void {
			list( $updater, $adapter ) = $this->subject();
			$offer                     = $this->offer( $updater );
			$token                     = $offer['package'];
			$encodedToken              = substr( $token, strrpos( $token, ':' ) + 1 );
			$decodedToken              = base64_decode( strtr( $encodedToken, '-_', '+/' ) . str_repeat( '=', ( 4 - strlen( $encodedToken ) % 4 ) % 4 ), true );
			self::assertIsString( $decodedToken );
			$bindingFacts                              = json_decode( $decodedToken, true, 32, JSON_THROW_ON_ERROR );
			$bindingFacts['binding_hash']              = str_repeat( 'b', 64 );
			$tamperedBindingToken                      = 'ran-wp-release-updater:v1:' . rtrim(
				strtr( base64_encode( json_encode( $bindingFacts, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES ) ), '+/', '-_' ),
				'='
			);
			$fingerprintFacts                          = json_decode( $decodedToken, true, 32, JSON_THROW_ON_ERROR );
			$fingerprintFacts['descriptor']['version'] = '2.0.1';
			$tamperedFingerprintToken                  = 'ran-wp-release-updater:v1:' . rtrim(
				strtr( base64_encode( json_encode( $fingerprintFacts, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES ) ), '+/', '-_' ),
				'='
			);
			$invalidTokens                             = array(
				$token . '=',
				'ran-wp-release-updater:v1:' . rtrim( strtr( base64_encode( '{"schema":1,"binding_hash":"x","descriptor":{}}' ), '+/', '-_' ), '=' ),
				$tamperedBindingToken,
				$tamperedFingerprintToken,
			);
			foreach ( $invalidTokens as $invalidToken ) {
				self::assertInstanceOf( \WP_Error::class, $updater->filterPreDownload( false, $invalidToken, null, $this->extra() ) );
			}
			self::assertSame( array( 1, 1, 1 ), array( $adapter->listCalls, $adapter->inspectCalls, $adapter->acquireCalls ) );
		}
		public function testOfferAndInstallUseExactDiscoveryAndReacquisitionCountsAndCopyOwnership(): void {
			list( $updater, $adapter ) = $this->subject();
			$offer                     = $this->offer( $updater );
			self::assertSame( array( 1, 1, 1 ), array( $adapter->listCalls, $adapter->inspectCalls, $adapter->acquireCalls ) );
			self::assertFileDoesNotExist( $adapter->acquiredPaths[0] );
			$ownedArchive = $updater->filterPreDownload( false, $offer['package'], null, $this->extra() );
			self::assertIsString( $ownedArchive );
			self::assertSame( array( 1, 2, 2 ), array( $adapter->listCalls, $adapter->inspectCalls, $adapter->acquireCalls ) );
			self::assertFileDoesNotExist( $adapter->acquiredPaths[1] );
			self::assertFileExists( $ownedArchive );
			$updater->refresh();
			self::assertFileDoesNotExist( $ownedArchive );
		}
		public function testFreshInspectionDriftRejectsBeforeReacquisition(): void {
			list( $updater, $adapter, , $descriptor ) = $this->subject();
			$offer                                    = $this->offer( $updater );
			$descriptorFacts                          = $descriptor->toArray();
			unset( $descriptorFacts['fingerprint'] );
			$descriptorFacts['commit_identity'] = 'changed';
			$adapter->inspectDescriptor         = IdentityDescriptor::create( $descriptorFacts );
			self::assertInstanceOf( \WP_Error::class, $updater->filterPreDownload( false, $offer['package'], null, $this->extra() ) );
			self::assertSame( array( 1, 2, 1 ), array( $adapter->listCalls, $adapter->inspectCalls, $adapter->acquireCalls ) );
			self::assertContains( 'remote_release_changed', $updater->diagnostics() );
		}
		public function testFreshInspectionMustRemainNewerThanTheInstalledHeader(): void {
			list( $updater, $adapter, , $descriptor ) = $this->subject();
			$offer                                    = $this->offer( $updater );
			$facts                                    = $descriptor->toArray();
			unset( $facts['fingerprint'] );
			$facts['version']           = '1.0.0';
			$facts['tag']               = 'v1.0.0';
			$adapter->inspectDescriptor = IdentityDescriptor::create( $facts );
			self::assertInstanceOf( \WP_Error::class, $updater->filterPreDownload( false, $offer['package'], null, $this->extra() ) );
			self::assertSame( array( 1, 2, 1 ), array( $adapter->listCalls, $adapter->inspectCalls, $adapter->acquireCalls ) );
			self::assertContains( 'unverified_pre_download', $updater->diagnostics() );
		}
		public function testOfferOnlyShutdownReleasesAndAutomaticInstallPromotesLease(): void {
			list( $firstUpdater, , $database ) = $this->subject();
			$this->offer( $firstUpdater );
			$firstUpdater->finalizePendingInstall();
			list( $secondUpdater ) = $this->subject( 'manual', $database );
			self::assertIsArray( $this->offer( $secondUpdater ) );
			list( $updater, , $database, , $binding ) = $this->subject( 'automatic' );
			$offer                                    = $this->offer( $updater );
			$database->setTime( 650 );
			self::assertIsString( $updater->filterPreDownload( false, $offer['package'], null, $this->extra() ) );
			$database->setTime( 701 );
			self::assertSame( 'binding_fence_lost', ReleaseOperationCoordinator::claimPersistentBindingState( $database, $binding, str_repeat( 'c', 64 ), 1 )['result'] );
			$updater->refresh();
		}
		public function testCompetingOwnerAfterUnzipRejectsStaleReceipt(): void {
			list( $updater, , $database, , $binding ) = $this->subject();
			$offer                                    = $this->offer( $updater );
			$ownedArchive                             = $updater->filterPreDownload( false, $offer['package'], null, $this->extra() );
			self::assertIsString( $ownedArchive );
			self::assertNull( $updater->filterPreUnzipFile( null, $ownedArchive, '/tmp', array(), 0.0 ) );
			$state        = BindingState::rehydrate( json_decode( array_values( $database->rows() )[0]['option_value'], true, 32, JSON_THROW_ON_ERROR ) );
			$bindingFacts = $binding->toArray();
			unset( $bindingFacts['binding_hash'] );
			$bindingFacts['update_policy'] = 'automatic';
			$reboundBinding                = BindingRecord::create( $bindingFacts );
			$name                          = 'ran_wp_release_updater_target_v1_' . BindingRecord::targetFenceKey(
				array(
					'network_id'                 => 1,
					'target_type'                => 'plugin',
					'installed_package_identity' => 'package/package.php',
				)
			);
			$successor                     = BindingState::create( $reboundBinding, str_repeat( 'b', 64 ), $state->leaseDeadline(), $state->bindingGeneration() + 1, $state->fenceEpoch() + 1 );
			$database->forceOptionValue( $name, json_encode( $successor->toArray(), JSON_THROW_ON_ERROR ) );
			self::assertInstanceOf( \WP_Error::class, $updater->filterSourceSelection( $this->staged(), '/tmp', null, $this->extra() ) );
		}
		public function testCompletionAndRollbackReleaseClaims(): void {
			list( $completedUpdater, , $database, , $binding ) = $this->subject();
			$this->complete( $completedUpdater );
			self::assertSame( 'claimed', ReleaseOperationCoordinator::claimPersistentBindingState( $database, $binding, str_repeat( 'd', 64 ), 1 )['result'] );
			list( $rollbackUpdater, , $database, , $binding ) = $this->subject();
			$offer = $this->offer( $rollbackUpdater );
			self::assertIsString( $rollbackUpdater->filterPreDownload( false, $offer['package'], null, $this->extra() ) );
			self::assertInstanceOf( \WP_Error::class, $rollbackUpdater->captureInstallPackageResult( new \WP_Error( 'rollback', 'rollback' ), $this->extra() ) );
			self::assertSame( 'claimed', ReleaseOperationCoordinator::claimPersistentBindingState( $database, $binding, str_repeat( 'e', 64 ), 1 )['result'] );
		}
		public function testPassiveSeamsDoNotAcquireAndDiagnosticsNeverExposeCallerInput(): void {
			list( $updater, $adapter ) = $this->subject();
			self::assertSame( 'keep', $updater->filterPluginInformation( 'keep', 'plugin_information', (object) array( 'slug' => 'other' ) ) );
			self::assertFalse(
				$updater->filterAutoUpdate(
					false,
					(object) array(
						'plugin'  => 'other',
						'package' => 'secret',
					)
				)
			);
			self::assertInstanceOf( \WP_Error::class, $updater->filterPreDownload( false, 'secret', null, $this->extra() ) );
			self::assertSame( array( 0, 0, 0 ), array( $adapter->listCalls, $adapter->inspectCalls, $adapter->acquireCalls ) );
			self::assertNotContains( 'secret', $updater->diagnostics() );
		}
		public function testProtocolLivenessMakesEveryPublicCallbackPassiveBeforeAdapterOrDatabaseWork(): void {
			$state  = new SelectedRuntimeState();
			$broker = new RequestBroker( false, $state );
			$state->bind( $broker );
			$GLOBALS['ran_wp_release_updater_v1_broker'] = $broker;
			$property                                    = new \ReflectionProperty( $broker, 'state' );
			$property->setValue( $broker, 'active' );
			list( $updater, $adapter, $database ) = $this->subject( 'manual', null, 'stable', false, $state );
			$updater->register();
			self::assertCount( 10, $GLOBALS['ran_wp_release_updater_test_hooks'] );

			foreach ( array( 'stale_global', 'wrong_protocol', 'legacy_broker' ) as $failure ) {
				if ( 'stale_global' === $failure ) {
					$GLOBALS['ran_wp_release_updater_v1_broker'] = new \stdClass();
				} elseif ( 'wrong_protocol' === $failure ) {
					$GLOBALS['ran_wp_release_updater_v1_broker'] = new class() { public function protocolVersion(): int {
							return 1;
					} };
				} else {
					$GLOBALS['ran_wp_release_updater_v1_broker']        = $broker;
					$GLOBALS['ran_wp_github_release_updater_v1_broker'] = new \stdClass();
				}
				self::assertFalse(
					$updater->filterUpdate(
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
				self::assertSame( 'keep', $updater->filterPluginInformation( 'keep', 'plugin_information', (object) array( 'slug' => 'ran-wp-release-updater-' . substr( hash( 'sha256', "plugin\0package/package.php" ), 0, 24 ) ) ), $failure );
				self::assertTrue(
					$updater->filterAutoUpdate(
						true,
						(object) array(
							'plugin'  => 'package/package.php',
							'package' => 'ignored',
						)
					),
					$failure
				);
				self::assertSame( array( 'hook_extra' => $this->extra() ), $updater->capturePackageOptions( array( 'hook_extra' => $this->extra() ) ), $failure );
				self::assertSame( 'download', $updater->filterPreDownload( 'download', 'sentinel', null, $this->extra() ), $failure );
				self::assertSame( 'unzip', $updater->filterPreUnzipFile( 'unzip', 'sentinel', 'destination', array(), 0.0 ), $failure );
				self::assertSame( 'source', $updater->filterSourceSelection( 'source', 'remote', null, $this->extra() ), $failure );
				self::assertSame( 'install', $updater->filterPreInstall( 'install', $this->extra() ), $failure );
				self::assertSame( 'result', $updater->captureInstallPackageResult( 'result', $this->extra() ), $failure );
				$updater->observeCompletion(
					null,
					array(
						'action'  => 'update',
						'type'    => 'plugin',
						'plugins' => array( 'package/package.php' ),
					)
				);
				$updater->finalizePendingInstall();
				self::assertSame( array( 0, 0, 0 ), array( $adapter->listCalls, $adapter->inspectCalls, $adapter->acquireCalls ), $failure );
				self::assertSame( array(), $database->rows(), $failure );
				self::assertSame( array(), $database->preparedSql(), $failure );
				unset( $GLOBALS['ran_wp_github_release_updater_v1_broker'] );
			}
		}
		public function testLivenessLossAfterArchiveAdmissionAbortsAndReleasesThePersistentLease(): void {
			$state  = new SelectedRuntimeState();
			$broker = new RequestBroker( false, $state );
			$state->bind( $broker );
			$GLOBALS['ran_wp_release_updater_v1_broker'] = $broker;
			$property                                    = new \ReflectionProperty( $broker, 'state' );
			$property->setValue( $broker, 'active' );
			try {
				list( $updater, , $database, , $binding ) = $this->subject( 'manual', null, 'stable', false, $state );
				$offer                                    = $this->offer( $updater );
				$ownedArchive                             = $updater->filterPreDownload( false, $offer['package'], null, $this->extra() );
				self::assertIsString( $ownedArchive );

				$GLOBALS['ran_wp_release_updater_v1_broker'] = new \stdClass();
				self::assertInstanceOf( \WP_Error::class, $updater->filterPreUnzipFile( null, $ownedArchive, '/tmp', array(), 0.0 ) );
				self::assertFileDoesNotExist( $ownedArchive );
				self::assertSame( 'claimed', ReleaseOperationCoordinator::claimPersistentBindingState( $database, $binding, str_repeat( 'f', 64 ), 1 )['result'] );
			} finally {
				unset( $GLOBALS['ran_wp_release_updater_v1_broker'] );
			}
		}
		#[\PHPUnit\Framework\Attributes\DataProvider( 'pendingLivenessLossCallbacks' )]
		public function testLivenessLossAbortsEveryMatchingPendingLifecycleCallback( string $callback ): void {
			$state  = new SelectedRuntimeState();
			$broker = new RequestBroker( false, $state );
			$state->bind( $broker );
			$GLOBALS['ran_wp_release_updater_v1_broker'] = $broker;
			$property                                    = new \ReflectionProperty( $broker, 'state' );
			$property->setValue( $broker, 'active' );
			try {
				list( $updater, , $database, , $binding ) = $this->subject( 'manual', null, 'stable', false, $state );
				$offer                                    = $this->offer( $updater );
				$ownedArchive                             = $updater->filterPreDownload( false, $offer['package'], null, $this->extra() );
				self::assertIsString( $ownedArchive );
				self::assertNull( $updater->filterPreUnzipFile( null, $ownedArchive, '/tmp', array(), 0.0 ) );

				$GLOBALS['ran_wp_release_updater_v1_broker'] = new \stdClass();
				$result                                      = match ( $callback ) {
					'source-selection' => $updater->filterSourceSelection( 'source', '/tmp', null, $this->extra() ),
					'pre-install' => $updater->filterPreInstall( true, $this->extra() ),
					'install-result' => $updater->captureInstallPackageResult( array(), $this->extra() ),
				};
				self::assertInstanceOf( \WP_Error::class, $result );
				self::assertFileDoesNotExist( $ownedArchive );
				self::assertSame( 'claimed', ReleaseOperationCoordinator::claimPersistentBindingState( $database, $binding, str_repeat( 'd', 64 ), 1 )['result'] );
			} finally {
				unset( $GLOBALS['ran_wp_release_updater_v1_broker'] );
			}
		}
		/** @return array<string,array{string}> */
		public static function pendingLivenessLossCallbacks(): array {
			return array(
				'source selection' => array( 'source-selection' ),
				'pre install'      => array( 'pre-install' ),
				'install result'   => array( 'install-result' ),
			);
		}
		public function testLivenessLossFinalizationAndRefreshClearPendingArchivesAndLeases(): void {
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
					$ownedArchive                             = $updater->filterPreDownload( false, $offer['package'], null, $this->extra() );
					self::assertIsString( $ownedArchive );
					$status = $updater->status();

					$GLOBALS['ran_wp_release_updater_v1_broker'] = new \stdClass();
					if ( 'finalize' === $path ) {
						$updater->finalizePendingInstall();
						self::assertContains( 'runtime_liveness_lost', $updater->diagnostics() );
					} else {
						self::assertFalse( $updater->refresh() );
						self::assertSame( $status, $updater->status() );
					}
					self::assertFileDoesNotExist( $ownedArchive );
					self::assertSame( 'claimed', ReleaseOperationCoordinator::claimPersistentBindingState( $database, $binding, str_repeat( 'e', 64 ), 1 )['result'] );
				} finally {
					unset( $GLOBALS['ran_wp_release_updater_v1_broker'] );
				}
			}
		}
		public function testRefreshClearsDiagnosticsAndDestroysPendingOwnedArchive(): void {
			list( $updater ) = $this->subject();
			$offer           = $this->offer( $updater );
			$ownedArchive    = $updater->filterPreDownload( false, $offer['package'], null, $this->extra() );
			self::assertIsString( $ownedArchive );
			$updater->refresh();
			self::assertFileDoesNotExist( $ownedArchive );
			self::assertSame( array(), $updater->diagnostics() );
		}
		public function testInstalledMutationCannotCompleteAgainstTheArchiveManifest(): void {
			list( $updater ) = $this->subject();
			$offer           = $this->offer( $updater );
			$ownedArchive    = $updater->filterPreDownload( false, $offer['package'], null, $this->extra() );
			self::assertIsString( $ownedArchive );
			self::assertNull( $updater->filterPreUnzipFile( null, $ownedArchive, '/tmp', array(), 0.0 ) );
			@unlink( $ownedArchive );
			self::assertTrue( $updater->filterPreInstall( true, $this->extra() ) );
			$stagedPackage = $this->staged();
			self::assertSame( $stagedPackage, $updater->filterSourceSelection( $stagedPackage, '/tmp', null, $this->extra() ) );
			file_put_contents( $stagedPackage . '/package.php', 'changed' );
			$updater->captureInstallPackageResult( array( 'destination' => $stagedPackage ), $this->extra() );
			$updater->observeCompletion(
				null,
				array(
					'action'  => 'update',
					'type'    => 'plugin',
					'plugins' => array( 'package/package.php' ),
				)
			);
			$updater->finalizePendingInstall();
			self::assertContains( 'outcome_uncertain', $updater->diagnostics() );
		}
		public function testThemeIdentityUsesThemeHooksAndRejectsPluginStyleIdentity(): void {
			list( $updater, $adapter, $database, , $binding ) = $this->subject();
			$configuration                                    = $this->config( 'manual' );
			$configuration['target_type']                     = 'theme';
			self::assertNull( NativePackageUpdater::fromConfiguration( $configuration, $binding, $adapter, $database, $this->policy() ) );
		}
		public function testArchivePolicyMustCarryTheExactBindingTemplate(): void {
			list( , $adapter, $database, , $binding ) = $this->subject();
			$policy                                   = $this->policy();
			$policy['theme_template']                 = 'parent-theme';
			self::assertNull( NativePackageUpdater::fromConfiguration( $this->config( 'manual' ), $binding, $adapter, $database, $policy ) );
			unset( $policy['theme_template'] );
			self::assertNull( NativePackageUpdater::fromConfiguration( $this->config( 'manual' ), $binding, $adapter, $database, $policy ) );
		}
		public function testNonFalsePreDownloadResultCannotBypassReleaseValidation(): void {
			list( $updater, $adapter, $database ) = $this->subject();
			$path                                 = tempnam( $this->temporaryDirectory, 'ran-unverified-download-' );
			self::assertIsString( $path );
			$this->paths[] = $path;
			chmod( $path, 0600 );
			file_put_contents( $path, 'untrusted archive' );
			$calls = 0;
			$GLOBALS['ran_wp_release_updater_test_filter_callbacks']['ran_wp_release_updater_v1_core_artifact_handoff'] = static function () use ( &$calls ): mixed {
				++$calls;
				return null;
			};
			$result = $updater->filterPreDownload( $path, $path, null, $this->extra() );
			self::assertInstanceOf( \WP_Error::class, $result );
			self::assertSame( 0, $calls );
			self::assertFileExists( $path );
			self::assertSame( array( 0, 0, 0 ), array( $adapter->listCalls, $adapter->inspectCalls, $adapter->acquireCalls ) );
			self::assertSame( array(), $database->rows() );
			self::assertContains( 'unverified_pre_download_result', $updater->diagnostics() );
		}
		/** @return array{NativePackageUpdater,ControllableReleaseAdapter,FakeOptionDatabase,IdentityDescriptor,BindingRecord} */
		private function subject( string $mode = 'manual', ?FakeOptionDatabase $database = null, string $channel = 'stable', bool $prerelease = false, ?SelectedRuntimeState $selectedRuntimeState = null, bool $nativeDiscoveryReuse = false, ?PackageIdentityValidator $validator = null ): array {
			$archivePath = $this->archive();
			$descriptor  = $this->descriptor( $archivePath, $channel, $prerelease );
			$binding     = $this->binding( $mode, $channel );
			$adapter     = new ControllableReleaseAdapter( $descriptor, $archivePath, $this->temporaryDirectory );
			$database  ??= new FakeOptionDatabase( 100 );
			$updater     = NativePackageUpdater::fromConfiguration( $this->config( $mode ), $binding, $adapter, $database, $this->policy(), $validator, $selectedRuntimeState, $nativeDiscoveryReuse );
			self::assertInstanceOf( NativePackageUpdater::class, $updater );
			return array( $updater, $adapter, $database, $descriptor, $binding );
		}
		/** @return array<string,mixed> */
		private function offer( NativePackageUpdater $updater ): array {
			$offer = $updater->filterUpdate(
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
			$offer        = $this->offer( $updater );
			$ownedArchive = $updater->filterPreDownload( false, $offer['package'], null, $this->extra() );
			self::assertIsString( $ownedArchive );
			self::assertNull( $updater->filterPreUnzipFile( null, $ownedArchive, '/tmp', array(), 0.0 ) );
			@unlink( $ownedArchive );
			self::assertTrue( $updater->filterPreInstall( true, $this->extra() ) );
			$stagedPackage = $this->staged();
			self::assertSame( $stagedPackage, $updater->filterSourceSelection( $stagedPackage, '/tmp', null, $this->extra() ) );
			$updater->captureInstallPackageResult( array( 'destination' => $stagedPackage ), $this->extra() );
			$updater->observeCompletion(
				null,
				array(
					'action'  => 'update',
					'type'    => 'plugin',
					'plugins' => array( 'package/package.php' ),
				)
			);
			$updater->finalizePendingInstall();
			self::assertContains( 'update_completed', $updater->diagnostics() );
			self::assertNull( $updater->status()['failure_code'] );
		}
		/** @return array<string,string> */
		private function extra(): array {
			return array( 'plugin' => 'package/package.php' );
		}
		private function archive(): string {
			$archivePath = tempnam( $this->temporaryDirectory, 'ran-native-' );
			self::assertIsString( $archivePath );
			$this->paths[] = $archivePath;
			$archive       = new \ZipArchive();
			self::assertTrue( $archive->open( $archivePath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) );
			$archive->addFromString( 'package/package.php', "<?php\n/*\nPlugin Name: Package\nVersion: 2.0.0\nUpdate URI: {$this->uri()}\n*/" );
			$archive->close();
			return $archivePath;
		}
		private function staged(): string {
			$parentPath = $this->temporaryDirectory . '/ran-native-stage-' . bin2hex( random_bytes( 5 ) );
			$stagedPath = $parentPath . '/package';
			mkdir( $stagedPath, 0700, true );
			$this->paths[] = $parentPath;
			file_put_contents( $stagedPath . '/package.php', "<?php\n/*\nPlugin Name: Package\nVersion: 2.0.0\nUpdate URI: {$this->uri()}\n*/" );
			return $stagedPath;
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
		private function descriptor( string $archivePath, string $channel = 'stable', bool $prerelease = false ): IdentityDescriptor {
			return IdentityDescriptor::create(
				array(
					'artifact_filename'          => 'package.zip',
					'artifact_identity'          => 'asset:2',
					'artifact_sha256'            => hash_file( 'sha256', $archivePath ),
					'artifact_size'              => filesize( $archivePath ),
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
			$facts = $descriptor->toArray();
			return array(
				'release_identity' => $facts['release_identity'],
				'tag'              => $facts['tag'],
				'version'          => $facts['version'],
			);
		}
		private function withReleaseIdentity( IdentityDescriptor $descriptor, string $releaseIdentity, string $tag ): IdentityDescriptor {
			$facts = $descriptor->toArray();
			unset( $facts['fingerprint'] );
			$facts['release_identity'] = $releaseIdentity;
			$facts['tag']              = $tag;
			return IdentityDescriptor::create( $facts );
		}
		private function token( IdentityDescriptor $descriptor, BindingRecord $binding ): string {
			$value = array(
				'binding_hash' => $binding->bindingHash(),
				'descriptor'   => $descriptor->toArray(),
				'schema'       => 1,
			);
			return 'ran-wp-release-updater:v1:' . rtrim( strtr( base64_encode( json_encode( $value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES ) ), '+/', '-_' ), '=' );
		}
		/** @return array<string,mixed> */
		private function claim( BindingState $state ): array {
			return array(
				'binding_generation' => $state->bindingGeneration(),
				'binding_hash'       => $state->binding()->bindingHash(),
				'lease_deadline'     => $state->leaseDeadline(),
				'owner_token'        => $state->ownerToken(),
			);
		}
		private function uri(): string {
			return 'https://updates.example.test/owner/package';
		}
	}
}
