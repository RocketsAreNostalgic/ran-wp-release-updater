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
	use RAN\WPReleaseUpdater\V1\Contract\BindingRecord;
	use RAN\WPReleaseUpdater\V1\Contract\IdentityDescriptor;
	use RAN\WPReleaseUpdater\V1\WordPress\BindingState;
	use RAN\WPReleaseUpdater\V1\WordPress\NativePluginUpdater;
	use RAN\WPReleaseUpdater\V1\WordPress\ReleaseOperationCoordinator;
	use Tests\Support\ControllableReleaseAdapter;
	use Tests\Support\FakeOptionDatabase;
	final class NativePluginUpdaterTest extends TestCase {
		/** @var list<string> */
		private array $paths = array();
		protected function setUp(): void {
			$GLOBALS['ran_wp_release_updater_test_hooks'] = array();
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
			$manualOffer = $this->offer( $manualUpdater );
			self::assertFalse( $manualOffer['autoupdate'] );
			self::assertFalse( $manualUpdater->filterAutoUpdate( true, (object) array( 'plugin' => 'package/package.php', 'package' => $manualOffer['package'] ) ) );
			list( $automaticUpdater ) = $this->subject( 'automatic' );
			$automaticOffer = $this->offer( $automaticUpdater );
			self::assertTrue( $automaticOffer['autoupdate'] );
			self::assertTrue( $automaticUpdater->filterAutoUpdate( false, (object) array( 'plugin' => 'package/package.php', 'package' => $automaticOffer['package'] ) ) );
		}
		public function testPrereleaseChannelOfferIsManualAndAutomaticIsDenied(): void {
			list( $updater ) = $this->subject( 'manual', null, 'prerelease', true );
			$offer = $this->offer( $updater );
			self::assertFalse( $offer['autoupdate'] );
			self::assertFalse( $updater->filterAutoUpdate( true, (object) array( 'plugin' => 'package/package.php', 'package' => $offer['package'] ) ) );
		}
		public function testCanonicalStaleAndOlderTokensAreRejectedAtAutomaticAndDownloadAdmission(): void {
			list( $updater, $adapter, , $descriptor, $binding ) = $this->subject( 'automatic' );
			foreach ( array( '1.0.0', '0.9.0' ) as $version ) {
				$facts = $descriptor->toArray();
				unset( $facts['fingerprint'] );
				$facts['version'] = $version;
				$facts['tag'] = 'v' . $version;
				$token = $this->token( IdentityDescriptor::create( $facts ), $binding );
				self::assertFalse( $updater->filterAutoUpdate( true, (object) array( 'plugin' => 'package/package.php', 'package' => $token ) ) );
				self::assertInstanceOf( \WP_Error::class, $updater->filterPreDownload( false, $token, null, $this->extra() ) );
			}
			self::assertSame( array( 0, 0, 0 ), array( $adapter->listCalls, $adapter->inspectCalls, $adapter->acquireCalls ) );
		}
		public function testNoncanonicalAndTamperedTokensAreRejectedWithoutInstallCalls(): void {
			list( $updater, $adapter ) = $this->subject();
			$offer = $this->offer( $updater );
			$token = $offer['package'];
			$encodedToken = substr( $token, strrpos( $token, ':' ) + 1 );
			$decodedToken = base64_decode( strtr( $encodedToken, '-_', '+/' ) . str_repeat( '=', ( 4 - strlen( $encodedToken ) % 4 ) % 4 ), true );
			self::assertIsString( $decodedToken );
			$bindingFacts = json_decode( $decodedToken, true, 32, JSON_THROW_ON_ERROR );
			$bindingFacts['binding_hash'] = str_repeat( 'b', 64 );
			$tamperedBindingToken = 'ran-wp-release-updater:v1:' . rtrim(
				strtr( base64_encode( json_encode( $bindingFacts, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES ) ), '+/', '-_' ),
				'='
			);
			$fingerprintFacts = json_decode( $decodedToken, true, 32, JSON_THROW_ON_ERROR );
			$fingerprintFacts['descriptor']['version'] = '2.0.1';
			$tamperedFingerprintToken = 'ran-wp-release-updater:v1:' . rtrim(
				strtr( base64_encode( json_encode( $fingerprintFacts, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES ) ), '+/', '-_' ),
				'='
			);
			$invalidTokens = array(
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
			$offer = $this->offer( $updater );
			self::assertSame( array( 1, 1, 1 ), array( $adapter->listCalls, $adapter->inspectCalls, $adapter->acquireCalls ) );
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
			$offer = $this->offer( $updater );
			$descriptorFacts = $descriptor->toArray();
			unset( $descriptorFacts['fingerprint'] );
			$descriptorFacts['commit_identity'] = 'changed';
			$adapter->inspectDescriptor = IdentityDescriptor::create( $descriptorFacts );
			self::assertInstanceOf( \WP_Error::class, $updater->filterPreDownload( false, $offer['package'], null, $this->extra() ) );
			self::assertSame( array( 1, 2, 1 ), array( $adapter->listCalls, $adapter->inspectCalls, $adapter->acquireCalls ) );
			self::assertContains( 'remote_release_changed', $updater->diagnostics() );
		}
		public function testFreshInspectionMustRemainNewerThanTheInstalledHeader(): void {
			list( $updater, $adapter, , $descriptor ) = $this->subject();
			$offer = $this->offer( $updater );
			$facts = $descriptor->toArray();
			unset( $facts['fingerprint'] );
			$facts['version'] = '1.0.0';
			$facts['tag'] = 'v1.0.0';
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
			$offer = $this->offer( $updater );
			$database->setTime( 650 );
			self::assertIsString( $updater->filterPreDownload( false, $offer['package'], null, $this->extra() ) );
			$database->setTime( 701 );
			self::assertSame( 'binding_fence_lost', ReleaseOperationCoordinator::claimPersistentBindingState( $database, $binding, str_repeat( 'c', 64 ), 1 )['result'] );
			$updater->refresh();
		}
		public function testAuthoritativeRebindAfterUnzipRejectsStaleReceipt(): void {
			list( $updater, , $database, , $binding ) = $this->subject();
			$offer = $this->offer( $updater );
			$ownedArchive = $updater->filterPreDownload( false, $offer['package'], null, $this->extra() );
			self::assertIsString( $ownedArchive );
			self::assertNull( $updater->filterPreUnzipFile( null, $ownedArchive, '/tmp', array(), 0.0 ) );
			$state = BindingState::rehydrate( json_decode( array_values( $database->rows() )[0]['option_value'], true, 32, JSON_THROW_ON_ERROR ) );
			$bindingFacts = $binding->toArray();
			unset( $bindingFacts['binding_hash'] );
			$bindingFacts['update_policy'] = 'automatic';
			$reboundBinding = BindingRecord::create( $bindingFacts );
			self::assertSame( 'rebound', ReleaseOperationCoordinator::persistPersistentBindingState( $database, $state, $this->claim( $state ), $reboundBinding )['result'] );
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
			self::assertFalse( $updater->filterAutoUpdate( false, (object) array( 'plugin' => 'other', 'package' => 'secret' ) ) );
			self::assertInstanceOf( \WP_Error::class, $updater->filterPreDownload( false, 'secret', null, $this->extra() ) );
			self::assertSame( array( 0, 0, 0 ), array( $adapter->listCalls, $adapter->inspectCalls, $adapter->acquireCalls ) );
			self::assertNotContains( 'secret', $updater->diagnostics() );
		}
		public function testRefreshClearsDiagnosticsAndDestroysPendingOwnedArchive(): void {
			list( $updater ) = $this->subject();
			$offer = $this->offer( $updater );
			$ownedArchive = $updater->filterPreDownload( false, $offer['package'], null, $this->extra() );
			self::assertIsString( $ownedArchive );
			$updater->refresh();
			self::assertFileDoesNotExist( $ownedArchive );
			self::assertSame( array(), $updater->diagnostics() );
		}
		public function testInstalledMutationCannotCompleteAgainstTheArchiveManifest(): void {
			list( $updater ) = $this->subject();
			$offer = $this->offer( $updater );
			$ownedArchive = $updater->filterPreDownload( false, $offer['package'], null, $this->extra() );
			self::assertIsString( $ownedArchive );
			self::assertNull( $updater->filterPreUnzipFile( null, $ownedArchive, '/tmp', array(), 0.0 ) );
			@unlink( $ownedArchive );
			self::assertTrue( $updater->filterPreInstall( true, $this->extra() ) );
			$stagedPackage = $this->staged();
			self::assertSame( $stagedPackage, $updater->filterSourceSelection( $stagedPackage, '/tmp', null, $this->extra() ) );
			file_put_contents( $stagedPackage . '/package.php', 'changed' );
			$updater->captureInstallPackageResult( array( 'destination' => $stagedPackage ), $this->extra() );
			$updater->observeCompletion( null, array( 'action' => 'update', 'type' => 'plugin', 'plugins' => array( 'package/package.php' ) ) );
			$updater->finalizePendingInstall();
			self::assertContains( 'outcome_uncertain', $updater->diagnostics() );
		}
		public function testThemeIdentityUsesThemeHooksAndRejectsPluginStyleIdentity(): void {
			list( $updater, $adapter, $database, , $binding ) = $this->subject();
			$configuration = $this->config( 'manual' );
			$configuration['target_type'] = 'theme';
			self::assertNull( NativePluginUpdater::fromConfiguration( $configuration, $binding, $adapter, $database, $this->policy() ) );
		}
		public function testCoreArtifactHandoffUsesExactCoreHookOrderAndToleratesCoreUnlink(): void {
			list( $updater, $adapter, $database ) = $this->subject();
			$path = tempnam( sys_get_temp_dir(), 'ran-core-handoff-' ); self::assertIsString( $path ); $this->paths[] = $path; chmod( $path, 0600 ); file_put_contents( $path, 'core archive' );
			$GLOBALS['ran_wp_release_updater_test_filter_callbacks']['ran_wp_release_updater_v1_core_artifact_handoff'] = static fn() => \RAN\WPReleaseUpdater\V1\Archive\TemporaryArtifact::forCoreUpdate( $path, hash_file( 'sha256', $path ), 'plugin', 'package/package.php', '2.0.0' );
			$extra = array( 'action' => 'update', 'type' => 'plugin', 'plugin' => 'package/package.php' );
			self::assertSame( $path, $updater->filterPreDownload( $path, $path, null, $extra ) ); self::assertSame( array( 0, 0, 0 ), array( $adapter->listCalls, $adapter->inspectCalls, $adapter->acquireCalls ) ); self::assertSame( array(), $database->preparedSql() );
			self::assertNull( $updater->filterPreUnzipFile( null, $path, '/tmp', array(), 0.0 ) ); @unlink( $path ); self::assertTrue( $updater->filterPreInstall( true, $extra ) ); $staged = $this->staged(); self::assertSame( $staged, $updater->filterSourceSelection( $staged, '/tmp', null, $extra ) );
			$updater->captureInstallPackageResult( array( 'destination' => $staged ), $extra ); $updater->observeCompletion( null, array( 'action' => 'update', 'type' => 'plugin', 'plugins' => array( 'package/package.php' ) ) ); $updater->finalizePendingInstall(); $this->assertCorePassive( $adapter, $database );
		}
		public function testCoreArtifactHandoffRejectsNormalPendingWithoutConsumingCapability(): void {
			list( $updater, , $database ) = $this->subject();
			$offer = $this->offer( $updater );
			$normal = $updater->filterPreDownload( false, $offer['package'], null, $this->extra() );
			self::assertIsString( $normal );
			$path = tempnam( sys_get_temp_dir(), 'ran-core-pending-' ); self::assertIsString( $path ); $this->paths[] = $path; chmod( $path, 0600 ); file_put_contents( $path, 'core archive' );
			$calls = 0;
			$GLOBALS['ran_wp_release_updater_test_filter_callbacks']['ran_wp_release_updater_v1_core_artifact_handoff'] = static function () use ( &$calls ): mixed { ++$calls; return null; };
			$result = $updater->filterPreDownload( $path, $path, null, array( 'action' => 'update', 'type' => 'plugin', 'plugin' => 'package/package.php' ) );
			self::assertInstanceOf( \WP_Error::class, $result );
			self::assertSame( 0, $calls );
			self::assertFileExists( $path );
			self::assertNotEmpty( $database->rows() );
			$updater->refresh();
		}

		public function testCoreArtifactHandoffReleasesOnlyExistingDiscoveryLease(): void {
			list( $updater, $adapter, $database, , $binding ) = $this->subject();
			$this->offer( $updater );
			$prepared = count( $database->preparedSql() );
			$reads = count( $database->readOptionNames() );
			$path = tempnam( sys_get_temp_dir(), 'ran-core-discovery-' ); self::assertIsString( $path ); $this->paths[] = $path; chmod( $path, 0600 ); file_put_contents( $path, 'core archive' );
			$GLOBALS['ran_wp_release_updater_test_filter_callbacks']['ran_wp_release_updater_v1_core_artifact_handoff'] = static fn() => \RAN\WPReleaseUpdater\V1\Archive\TemporaryArtifact::forCoreUpdate( $path, hash_file( 'sha256', $path ), 'plugin', 'package/package.php', '2.0.0' );
			$extra = array( 'action' => 'update', 'type' => 'plugin', 'plugin' => 'package/package.php' );
			self::assertSame( $path, $updater->filterPreDownload( $path, $path, null, $extra ) );
			self::assertCount( $prepared + 3, $database->preparedSql() );
			self::assertCount( $reads + 2, $database->readOptionNames() );
			self::assertSame( array( 1, 1, 1 ), array( $adapter->listCalls, $adapter->inspectCalls, $adapter->acquireCalls ) );
			$rows = $database->rows(); self::assertCount( 1, $rows ); $state = json_decode( array_values( $rows )[0]['option_value'], true, 32, JSON_THROW_ON_ERROR ); self::assertSame( 1, $state['lease_deadline'] );
			$updater->finalizePendingInstall();
			self::assertCount( $prepared + 3, $database->preparedSql() );
			self::assertSame( 'claimed', ReleaseOperationCoordinator::claimPersistentBindingState( $database, $binding, str_repeat( 'f', 64 ), 1 )['result'] );
		}

		public function testCoreArtifactHandoffDiscardsPreConsumedCapability(): void {
			list( $updater, $adapter, $database ) = $this->subject();
			$path = tempnam( sys_get_temp_dir(), 'ran-core-consumed-' ); self::assertIsString( $path ); $this->paths[] = $path; chmod( $path, 0600 ); file_put_contents( $path, 'core archive' );
			$artifact = \RAN\WPReleaseUpdater\V1\Archive\TemporaryArtifact::forCoreUpdate( $path, hash_file( 'sha256', $path ), 'plugin', 'package/package.php', '2.0.0' );
			$artifact->acceptCoreUpdate( 'plugin', 'package/package.php', 'update', $path );
			$GLOBALS['ran_wp_release_updater_test_filter_callbacks']['ran_wp_release_updater_v1_core_artifact_handoff'] = static fn() => $artifact;
			$extra = array( 'action' => 'update', 'type' => 'plugin', 'plugin' => 'package/package.php' );
			self::assertInstanceOf( \WP_Error::class, $updater->filterPreDownload( $path, $path, null, $extra ) );
			self::assertFileDoesNotExist( $path );
			$this->assertCorePassive( $adapter, $database );
		}
		public function testCoreArtifactHandoffCompletesThemeWithoutCoordinatorState(): void {
			list( $updater, $adapter, $database, $path, $extra ) = $this->coreHandoffSubject( 'theme' );
			self::assertSame( $path, $updater->filterPreDownload( $path, $path, null, $extra ) );
			self::assertNull( $updater->filterPreUnzipFile( null, $path, '/tmp', array(), 0.0 ) );
			@unlink( $path ); self::assertTrue( $updater->filterPreInstall( true, $extra ) ); $staged = $this->coreStaged( 'theme' ); self::assertSame( $staged, $updater->filterSourceSelection( $staged, '/tmp', null, $extra ) );
			$updater->captureInstallPackageResult( array( 'destination' => $staged ), $extra ); $updater->observeCompletion( null, array( 'action' => 'update', 'type' => 'theme', 'themes' => array( 'package' ) ) ); $updater->finalizePendingInstall();
			self::assertFileDoesNotExist( $path ); $this->assertCorePassive( $adapter, $database );
		}
		/** @dataProvider coreStagedMismatchProvider */
		public function testCoreArtifactHandoffRejectsStagedMetadataBeforeDestinationMutation( string $mismatch ): void {
			list( $updater, $adapter, $database, $path, $extra ) = $this->coreHandoffSubject(); self::assertSame( $path, $updater->filterPreDownload( $path, $path, null, $extra ) ); self::assertNull( $updater->filterPreUnzipFile( null, $path, '/tmp', array(), 0.0 ) ); @unlink( $path ); self::assertTrue( $updater->filterPreInstall( true, $extra ) );
			$staged = $this->coreStaged( 'plugin', $mismatch ); $header = $staged . '/package.php'; $before = is_file( $header ) ? hash_file( 'sha256', $header ) : null;
			self::assertInstanceOf( \WP_Error::class, $updater->filterSourceSelection( $staged, '/tmp', null, $extra ) ); self::assertSame( $before, is_file( $header ) ? hash_file( 'sha256', $header ) : null ); self::assertFileDoesNotExist( $path ); $this->assertCorePassive( $adapter, $database );
		}
		public static function coreStagedMismatchProvider(): array { return array( 'root' => array( 'root' ), 'header' => array( 'header' ), 'version' => array( 'version' ), 'uri' => array( 'uri' ) ); }
		public function testCoreArtifactHandoffRejectsArchiveMutationBeforeUnzipWithoutCoordinatorState(): void {
			list( $updater, $adapter, $database, $path, $extra ) = $this->coreHandoffSubject(); self::assertSame( $path, $updater->filterPreDownload( $path, $path, null, $extra ) ); file_put_contents( $path, 'changed' );
			self::assertInstanceOf( \WP_Error::class, $updater->filterPreUnzipFile( null, $path, '/tmp', array(), 0.0 ) ); self::assertFileExists( $path ); $this->assertCorePassive( $adapter, $database );
		}
		public function testCoreArtifactHandoffFailedInstallAndRollbackReadbackCleanUpWithoutCoordinatorState(): void {
			list( $failed, $adapter, $database, $path, $extra ) = $this->coreHandoffSubject(); self::assertSame( $path, $failed->filterPreDownload( $path, $path, null, $extra ) ); self::assertNull( $failed->filterPreUnzipFile( null, $path, '/tmp', array(), 0.0 ) ); @unlink( $path ); self::assertTrue( $failed->filterPreInstall( true, $extra ) ); $staged = $this->coreStaged(); self::assertSame( $staged, $failed->filterSourceSelection( $staged, '/tmp', null, $extra ) ); self::assertInstanceOf( \WP_Error::class, $failed->captureInstallPackageResult( new \WP_Error( 'failed', 'failed' ), $extra ) ); self::assertFileDoesNotExist( $path ); $this->assertCorePassive( $adapter, $database );
			list( $rollback, $adapter, $database, $path, $extra ) = $this->coreHandoffSubject(); self::assertSame( $path, $rollback->filterPreDownload( $path, $path, null, $extra ) ); self::assertNull( $rollback->filterPreUnzipFile( null, $path, '/tmp', array(), 0.0 ) ); @unlink( $path ); self::assertTrue( $rollback->filterPreInstall( true, $extra ) ); $staged = $this->coreStaged(); self::assertSame( $staged, $rollback->filterSourceSelection( $staged, '/tmp', null, $extra ) ); $rollback->captureInstallPackageResult( array( 'destination' => $staged ), $extra ); $rollback->observeCompletion( null, array( 'action' => 'update', 'type' => 'plugin', 'plugins' => array( 'package/package.php' ) ) ); file_put_contents( $staged . '/package.php', "<?php\n/*\nPlugin Name: Package\nVersion: 1.0.0\nUpdate URI: {$this->uri()}\n*/" ); $rollback->finalizePendingInstall();
			self::assertContains( 'outcome_uncertain', $rollback->diagnostics() ); self::assertFileDoesNotExist( $path ); $this->assertCorePassive( $adapter, $database );
		}
		/** @dataProvider coreHandoffMismatchProvider */
		public function testCoreArtifactHandoffRejectsMismatches( mixed $reply, string $package, array $extra, mixed $expected, bool $diagnosed ): void {
			list( $updater, $adapter, $database ) = $this->subject();
			$path = tempnam( sys_get_temp_dir(), 'ran-core-mismatch-' ); self::assertIsString( $path ); $this->paths[] = $path; chmod( $path, 0600 ); file_put_contents( $path, 'core archive' );
			$GLOBALS['ran_wp_release_updater_test_filter_callbacks']['ran_wp_release_updater_v1_core_artifact_handoff'] = static fn() => \RAN\WPReleaseUpdater\V1\Archive\TemporaryArtifact::forCoreUpdate( $path, hash_file( 'sha256', $path ), 'plugin', 'package/package.php', '2.0.0' );
			$result = $updater->filterPreDownload( $reply === '__path__' ? $path : $reply, $package === '__path__' ? $path : $package, null, $extra ); if ( 'error' === $expected ) self::assertInstanceOf( \WP_Error::class, $result ); else self::assertSame( $expected === '__path__' ? $path : $expected, $result ); if ( $diagnosed ) self::assertContains( 'unverified_pre_download_result', $updater->diagnostics() ); else self::assertSame( array(), $updater->diagnostics() ); $this->assertCorePassive( $adapter, $database );
		}
		public static function coreHandoffMismatchProvider(): array { return array( 'reply' => array( 'other', '__path__', array( 'action' => 'update', 'type' => 'plugin', 'plugin' => 'package/package.php' ), 'error', true ), 'package' => array( '__path__', 'other', array( 'action' => 'update', 'type' => 'plugin', 'plugin' => 'package/package.php' ), 'error', true ), 'unrelated' => array( '__path__', '__path__', array( 'action' => 'update', 'type' => 'plugin', 'plugin' => 'other/other.php' ), '__path__', false ), 'action rejects nonfalse' => array( '__path__', '__path__', array( 'action' => 'install', 'type' => 'plugin', 'plugin' => 'package/package.php' ), 'error', true ), 'type rejects nonfalse' => array( '__path__', '__path__', array( 'action' => 'update', 'type' => 'theme', 'plugin' => 'package/package.php' ), 'error', true ), 'action false inert' => array( false, '__path__', array( 'action' => 'install', 'type' => 'plugin', 'plugin' => 'package/package.php' ), false, false ), 'type false inert' => array( false, '__path__', array( 'action' => 'update', 'type' => 'theme', 'plugin' => 'package/package.php' ), false, false ) ); }
		/** @return array{NativePluginUpdater,ControllableReleaseAdapter,FakeOptionDatabase,string,array<string,string>} */
		private function coreHandoffSubject( string $type = 'plugin' ): array {
			if ( 'plugin' === $type ) list( $updater, $adapter, $database ) = $this->subject(); else {
				$archive = $this->archive(); $descriptor = $this->descriptor( $archive ); $adapter = new ControllableReleaseAdapter( $descriptor, $archive ); $database = new FakeOptionDatabase( 100 ); $uri = $this->uri();
				$binding = BindingRecord::create( array( 'canonical_repository_locator' => 'owner/package', 'canonical_update_uri' => $uri, 'installed_package_identity' => 'package', 'php_runtime_version' => '8.2', 'provider_code' => 'neutral', 'release_channel' => 'stable', 'stable_repository_identity' => 'repo:1', 'target_type' => 'theme', 'update_policy' => 'manual', 'wordpress_runtime_version' => '6.8' ) );
				$configuration = array( 'headers' => array( 'Author' => 'A', 'Description' => 'D', 'Name' => 'Package', 'PluginURI' => $uri, 'RequiresPHP' => '8.2', 'RequiresWP' => '6.8', 'UpdateURI' => $uri, 'Version' => '1.0.0' ), 'installed_package_identity' => 'package', 'policy' => 'manual', 'target_type' => 'theme', 'update_uri' => $uri );
				$policy = array( 'archive_root' => 'package', 'configuration_update_uri' => $uri, 'header_file' => 'style.css', 'installed_package_identity' => 'package', 'metadata_name' => 'Package', 'offer_update_uri' => $uri, 'php_runtime_version' => '8.2', 'provider_code' => 'neutral', 'repository_identity' => 'repo:1', 'repository_locator' => 'owner/package', 'staged_package_update_uri' => $uri, 'target_type' => 'theme', 'wordpress_runtime_version' => '6.8' ); $updater = NativePluginUpdater::fromConfiguration( $configuration, $binding, $adapter, $database, $policy ); self::assertInstanceOf( NativePluginUpdater::class, $updater );
			}
			$path = tempnam( sys_get_temp_dir(), 'ran-core-handoff-' ); self::assertIsString( $path ); $this->paths[] = $path; chmod( $path, 0600 ); file_put_contents( $path, 'core archive' ); $identity = 'theme' === $type ? 'package' : 'package/package.php';
			$GLOBALS['ran_wp_release_updater_test_filter_callbacks']['ran_wp_release_updater_v1_core_artifact_handoff'] = static fn() => \RAN\WPReleaseUpdater\V1\Archive\TemporaryArtifact::forCoreUpdate( $path, hash_file( 'sha256', $path ), $type, $identity, '2.0.0' );
			return array( $updater, $adapter, $database, $path, array( 'action' => 'update', 'type' => $type, 'plugin' === $type ? 'plugin' : 'theme' => $identity ) );
		}
		private function coreStaged( string $type = 'plugin', string $mismatch = '' ): string {
			$root = 'root' === $mismatch ? 'other' : 'package'; $parent = sys_get_temp_dir() . '/ran-core-stage-' . bin2hex( random_bytes( 5 ) ); $path = $parent . '/' . $root; mkdir( $path, 0700, true ); $this->paths[] = $parent; $header = 'theme' === $type ? 'style.css' : 'package.php'; if ( 'header' === $mismatch ) $header = 'other.php'; $name = 'theme' === $type ? 'Theme Name' : 'Plugin Name'; $version = 'version' === $mismatch ? '1.0.0' : '2.0.0'; $uri = 'uri' === $mismatch ? 'https://updates.example.test/other' : $this->uri(); file_put_contents( $path . '/' . $header, "<?php\n/*\n{$name}: Package\nVersion: {$version}\nUpdate URI: {$uri}\n*/" ); return $path;
		}
		private function assertCorePassive( ControllableReleaseAdapter $adapter, FakeOptionDatabase $database ): void { self::assertSame( array( 0, 0, 0 ), array( $adapter->listCalls, $adapter->inspectCalls, $adapter->acquireCalls ) ); self::assertSame( array(), $database->preparedSql() ); self::assertSame( array(), $database->readOptionNames() ); self::assertSame( array(), $database->rows() ); }
		/** @return array{NativePluginUpdater,ControllableReleaseAdapter,FakeOptionDatabase,IdentityDescriptor,BindingRecord} */
		private function subject( string $mode = 'manual', ?FakeOptionDatabase $database = null, string $channel = 'stable', bool $prerelease = false ): array {
			$archivePath = $this->archive();
			$descriptor = $this->descriptor( $archivePath, $channel, $prerelease );
			$binding = $this->binding( $mode, $channel );
			$adapter = new ControllableReleaseAdapter( $descriptor, $archivePath );
			$database ??= new FakeOptionDatabase( 100 );
			$updater = NativePluginUpdater::fromConfiguration( $this->config( $mode ), $binding, $adapter, $database, $this->policy() );
			self::assertInstanceOf( NativePluginUpdater::class, $updater );
			return array( $updater, $adapter, $database, $descriptor, $binding );
		}
		/** @return array<string,mixed> */
		private function offer( NativePluginUpdater $updater ): array {
			$offer = $updater->filterUpdate( false, array( 'Version' => '1.0.0', 'UpdateURI' => $this->uri() ), 'package/package.php', array() );
			self::assertIsArray( $offer );
			return $offer;
		}
		private function complete( NativePluginUpdater $updater ): void {
			$offer = $this->offer( $updater );
			$ownedArchive = $updater->filterPreDownload( false, $offer['package'], null, $this->extra() );
			self::assertIsString( $ownedArchive );
			self::assertNull( $updater->filterPreUnzipFile( null, $ownedArchive, '/tmp', array(), 0.0 ) );
			@unlink( $ownedArchive );
			self::assertTrue( $updater->filterPreInstall( true, $this->extra() ) );
			$stagedPackage = $this->staged();
			self::assertSame( $stagedPackage, $updater->filterSourceSelection( $stagedPackage, '/tmp', null, $this->extra() ) );
			$updater->captureInstallPackageResult( array( 'destination' => $stagedPackage ), $this->extra() );
			$updater->observeCompletion( null, array( 'action' => 'update', 'type' => 'plugin', 'plugins' => array( 'package/package.php' ) ) );
			$updater->finalizePendingInstall();
			self::assertContains( 'update_completed', $updater->diagnostics() );
		}
		/** @return array<string,string> */
		private function extra(): array {
			return array( 'plugin' => 'package/package.php' );
		}
		private function archive(): string {
			$archivePath = tempnam( sys_get_temp_dir(), 'ran-native-' );
			self::assertIsString( $archivePath );
			$this->paths[] = $archivePath;
			$archive = new \ZipArchive();
			self::assertTrue( $archive->open( $archivePath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) );
			$archive->addFromString( 'package/package.php', "<?php\n/*\nPlugin Name: Package\nVersion: 2.0.0\nUpdate URI: {$this->uri()}\n*/" );
			$archive->close();
			return $archivePath;
		}
		private function staged(): string {
			$parentPath = sys_get_temp_dir() . '/ran-native-stage-' . bin2hex( random_bytes( 5 ) );
			$stagedPath = $parentPath . '/package';
			mkdir( $stagedPath, 0700, true );
			$this->paths[] = $parentPath;
			file_put_contents( $stagedPath . '/package.php', "<?php\n/*\nPlugin Name: Package\nVersion: 2.0.0\nUpdate URI: {$this->uri()}\n*/" );
			return $stagedPath;
		}
		/** @return array<string,mixed> */
		private function config( string $mode ): array {
			return array( 'headers' => array( 'Author' => 'A', 'Description' => 'D', 'Name' => 'Package', 'PluginURI' => $this->uri(), 'RequiresPHP' => '8.2', 'RequiresWP' => '6.8', 'UpdateURI' => $this->uri(), 'Version' => '1.0.0' ),
				'installed_package_identity' => 'package/package.php', 'policy' => $mode, 'target_type' => 'plugin', 'update_uri' => $this->uri() );
		}
		/** @return array<string,string> */
		private function policy(): array {
			return array( 'archive_root' => 'package', 'configuration_update_uri' => $this->uri(), 'header_file' => 'package.php',
				'installed_package_identity' => 'package/package.php', 'metadata_name' => 'Package', 'offer_update_uri' => $this->uri(), 'php_runtime_version' => '8.2', 'provider_code' => 'neutral', 'repository_identity' => 'repo:1', 'repository_locator' => 'owner/package', 'staged_package_update_uri' => $this->uri(), 'target_type' => 'plugin', 'wordpress_runtime_version' => '6.8' );
		}
		private function binding( string $mode, string $channel = 'stable' ): BindingRecord {
			return BindingRecord::create( array( 'canonical_repository_locator' => 'owner/package', 'canonical_update_uri' => $this->uri(),
				'installed_package_identity' => 'package/package.php', 'php_runtime_version' => '8.2', 'provider_code' => 'neutral', 'release_channel' => $channel, 'stable_repository_identity' => 'repo:1', 'target_type' => 'plugin', 'update_policy' => $mode, 'wordpress_runtime_version' => '6.8' ) );
		}
		private function descriptor( string $archivePath, string $channel = 'stable', bool $prerelease = false ): IdentityDescriptor {
			return IdentityDescriptor::create( array( 'artifact_filename' => 'package.zip', 'artifact_identity' => 'asset:2',
				'artifact_sha256' => hash_file( 'sha256', $archivePath ), 'artifact_size' => filesize( $archivePath ),
				'assurance_facts' => array( 'exact_artifact_identity' => true, 'exact_commit_identity' => true,
					'exact_reacquisition_supported' => true, 'exact_release_identity' => true, 'provenance_verified' => true,
					'publication_immutable' => true, 'repository_identity_stable' => true, 'trusted_digest_source' => true ),
				'canonical_update_uri' => $this->uri(), 'channel' => $channel, 'commit_identity' => 'commit:2',
				'installed_package_identity' => 'package/package.php', 'prerelease' => $prerelease, 'provider_code' => 'neutral',
				'release_identity' => 'release:2', 'repository_identity' => 'repo:1', 'repository_locator' => 'owner/package',
				'tag' => 'v2.0.0', 'target_type' => 'plugin', 'version' => '2.0.0' ) );
		}
		private function token( IdentityDescriptor $descriptor, BindingRecord $binding ): string {
			$value = array( 'binding_hash' => $binding->bindingHash(), 'descriptor' => $descriptor->toArray(), 'schema' => 1 );
			return 'ran-wp-release-updater:v1:' . rtrim( strtr( base64_encode( json_encode( $value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES ) ), '+/', '-_' ), '=' );
		}
		/** @return array<string,mixed> */
		private function claim( BindingState $state ): array {
			return array( 'binding_generation' => $state->bindingGeneration(), 'binding_hash' => $state->binding()->bindingHash(),
				'lease_deadline' => $state->leaseDeadline(), 'owner_token' => $state->ownerToken() );
		}
		private function uri(): string {
			return 'https://updates.example.test/owner/package';
		}
	}
}
