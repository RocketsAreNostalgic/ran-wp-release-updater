<?php

declare(strict_types=1);

namespace {
	if ( ! class_exists( 'WP_Error' ) ) { final class WP_Error { public function __construct( public string $code, public string $message ) {} } }
	if ( ! function_exists( 'add_filter' ) ) { function add_filter( string $hook, mixed $callback, int $priority, int $arguments ): void { $GLOBALS['ran_wp_release_updater_test_hooks'][] = array( 'filter', $hook, $callback, $priority, $arguments ); } }
	if ( ! function_exists( 'add_action' ) ) { function add_action( string $hook, mixed $callback, int $priority, int $arguments ): void { $GLOBALS['ran_wp_release_updater_test_hooks'][] = array( 'action', $hook, $callback, $priority, $arguments ); } }
	if ( ! function_exists( 'get_filesystem_method' ) ) { function get_filesystem_method(): string { return $GLOBALS['ran_wp_release_updater_test_filesystem_method'] ?? 'direct'; } }
}

namespace Tests\WordPress {

require_once dirname(__DIR__) . '/Support/FakeOptionDatabase.php';

use PHPUnit\Framework\TestCase;
use RAN\WPReleaseUpdater\V1\Archive\PackageIdentityValidator;
use RAN\WPReleaseUpdater\V1\Contract\AcquisitionReceipt;
use RAN\WPReleaseUpdater\V1\Contract\BindingRecord;
use RAN\WPReleaseUpdater\V1\Contract\IdentityDescriptor;
use RAN\WPReleaseUpdater\V1\WordPress\BindingState;
use RAN\WPReleaseUpdater\V1\WordPress\NativePluginUpdater;
use RAN\WPReleaseUpdater\V1\WordPress\ReleaseOperationCoordinator;
use Tests\Support\FakeOptionDatabase;

final class NativePluginUpdaterTest extends TestCase {
	/** @var list<string> */ private array $paths = array();
	private ?FakeOptionDatabase $database = null;
	protected function setUp(): void { $GLOBALS['ran_wp_release_updater_test_hooks'] = array(); $GLOBALS['ran_wp_release_updater_test_filesystem_method'] = 'direct'; }
	protected function tearDown(): void { foreach ( $this->paths as $path ) { if ( is_file( $path ) ) unlink( $path ); if ( is_dir( $path ) ) { foreach ( glob( $path . '/*' ) ?: array() as $child ) if ( is_file( $child ) ) unlink( $child ); rmdir( $path ); } } parent::tearDown(); }

	public function testConstructionConsumesCurrentInspectionBeforeOffersAndRegistersExactlyOnce(): void {
		$updater = $this->updater(); self::assertSame( array(), $GLOBALS['ran_wp_release_updater_test_hooks'] ); $updater->register(); $updater->register();
		self::assertSame( array( 'update_plugins_updates.example.test', 'plugins_api', 'auto_update_plugin', 'upgrader_package_options', 'upgrader_pre_download', 'upgrader_pre_install', 'pre_unzip_file', 'upgrader_source_selection', 'upgrader_install_package_result', 'upgrader_process_complete' ), array_column( $GLOBALS['ran_wp_release_updater_test_hooks'], 1 ) );
		self::assertCount( 10, $GLOBALS['ran_wp_release_updater_test_hooks'] );
		$preInstall = array_values( array_filter( $GLOBALS['ran_wp_release_updater_test_hooks'], static fn( array $hook ): bool => 'upgrader_pre_install' === $hook[1] ) )[0]; self::assertSame( 1, $preInstall[3] );
	}

	public function testRejectsUriAndBindingMismatchesBeforeAnyHookOrOffer(): void {
		list( $validator, $descriptor, $binding, $state, $archive ) = $this->ready(); $package = $validator->validate( $descriptor, $this->policy(), $archive );
		$config = $this->configuration(); $config['headers']['UpdateURI'] = $this->uri( 'other/package' );
		self::assertNull( NativePluginUpdater::fromConfiguration( $config, $descriptor, $binding, $this->database, $state, $this->claim( $state ), AcquisitionReceipt::issue( $state, $descriptor, $validator, $package, 100 ) ) );
		self::assertSame( array(), $GLOBALS['ran_wp_release_updater_test_hooks'] );
		$config = $this->configuration(); $config['policy'] = 'automatic';
		$package = $validator->validate( $descriptor, $this->policy(), $archive );
		self::assertNull( NativePluginUpdater::fromConfiguration( $config, $descriptor, $binding, $this->database, $state, $this->claim( $state ), AcquisitionReceipt::issue( $state, $descriptor, $validator, $package, 100 ) ) );
		$facts = $descriptor->toArray(); unset( $facts['fingerprint'] ); $facts['assurance_facts']['exact_commit_identity'] = false; $manualMissing = IdentityDescriptor::create( $facts ); $package = $validator->validate( $manualMissing, $this->policy(), $archive );
		$updater = NativePluginUpdater::fromConfiguration( $this->configuration(), $manualMissing, $binding, $this->database, $state, $this->claim( $state ), AcquisitionReceipt::issue( $state, $manualMissing, $validator, $package, 100 ) );
		self::assertSame( false, $updater->filterUpdate( false, array( 'Version' => '1.0.0', 'UpdateURI' => $this->uri( 'owner/package' ) ), 'package/package.php', array() ) );
	}

	public function testThemeIdentityUsesThemeHooksAndRejectsPluginStyleIdentity(): void {
		list( $validator, $descriptor, $binding, $state, $archive ) = $this->themeReady(); $package = $validator->validate( $descriptor, $this->themePolicy(), $archive );
		$updater = NativePluginUpdater::fromConfiguration( $this->themeConfiguration(), $descriptor, $binding, $this->database, $state, $this->claim( $state ), AcquisitionReceipt::issue( $state, $descriptor, $validator, $package, 100 ) ); $updater->register();
		self::assertSame( 'update_themes_updates.example.test', $GLOBALS['ran_wp_release_updater_test_hooks'][0][1] ); self::assertSame( 'auto_update_theme', $GLOBALS['ran_wp_release_updater_test_hooks'][1][1] );
	}

	public function testOfferRechecksRuntimeUriUsesUniqueInfoSlugAndDefaultDeniesAutomatic(): void {
		$updater = $this->updater(); $offer = $updater->filterUpdate( false, array( 'Version' => '1.0.0', 'UpdateURI' => $this->uri( 'owner/package' ) ), 'package/package.php', array() );
		self::assertSame( 'package/package.php', $offer['plugin']); self::assertSame( $this->binding()->bindingHash(), $offer['ran_wp_release_updater_binding_hash'] ); self::assertStringStartsWith( 'ran-wp-release-updater-', $offer['slug'] );
		self::assertSame( false, $updater->filterAutoUpdate( true, (object) array( 'plugin' => 'package/package.php' ) ) );
		self::assertSame( 'pass', $updater->filterPluginInformation( 'pass', 'plugin_information', (object) array( 'slug' => 'package' ) ) );
		self::assertSame( false, $updater->filterUpdate( false, array( 'Version' => '1.0.0', 'UpdateURI' => $this->uri( 'other/package' ) ), 'package/package.php', array() ) );
	}

	public function testOnlyDirectFilesystemCanOfferOrAcquire(): void {
		$calls = 0; $updater = $this->updater( static function () use ( &$calls ): array { ++$calls; return array(); } ); $GLOBALS['ran_wp_release_updater_test_filesystem_method'] = 'ssh2';
		self::assertFalse( $updater->filterUpdate( false, array( 'Version' => '1.0.0', 'UpdateURI' => $this->uri( 'owner/package' ) ), 'package/package.php', array() ) ); self::assertSame( 'keep', $updater->filterPluginInformation( 'keep', 'plugin_information', (object) array( 'slug' => 'ran-wp-release-updater-' . substr( hash( 'sha256', "plugin\0package/package.php" ), 0, 24 ) ) ) ); self::assertFalse( $updater->filterAutoUpdate( true, (object) array( 'plugin' => 'package/package.php' ) ) ); self::assertInstanceOf( \WP_Error::class, $updater->filterPreDownload( false, 'ignored', null, array( 'plugin' => 'package/package.php' ) ) ); self::assertSame( 0, $calls );
	}

	public function testPassiveSeamsDoNotAcquireAndDiagnosticsNeverExposeCallerInput(): void {
		$calls = 0; $secret = 'secret-token-should-never-appear'; $updater = $this->updater( static function () use ( &$calls ): array { ++$calls; throw new \RuntimeException( 'acquirer must remain passive' ); } );
		self::assertIsArray( $updater->filterUpdate( false, array( 'Version' => '1.0.0', 'UpdateURI' => $this->uri( 'owner/package' ) ), 'package/package.php', array() ) );
		self::assertInstanceOf( \stdClass::class, $updater->filterPluginInformation( null, 'plugin_information', (object) array( 'slug' => 'ran-wp-release-updater-' . substr( hash( 'sha256', "plugin\0package/package.php" ), 0, 24 ) ) ) );
		self::assertFalse( $updater->filterAutoUpdate( true, (object) array( 'plugin' => 'package/package.php' ) ) );
		$GLOBALS['ran_wp_release_updater_test_filesystem_method'] = 'ssh2'; self::assertInstanceOf( \WP_Error::class, $updater->filterPreDownload( false, $secret, null, array( 'plugin' => 'package/package.php' ) ) );
		self::assertSame( 0, $calls ); self::assertNotContains( $secret, $updater->diagnostics() ); self::assertNotContains( $this->uri( 'owner/package' ), $updater->diagnostics() ); self::assertSame( array( 'unverified_pre_download' ), $updater->diagnostics() );
	}

	public function testRefreshClearsDiagnosticsAndDestroysPendingOwnedArchive(): void {
		list( $validator, $descriptor, $binding, $state, $archive ) = $this->ready(); $updater = $this->configuredUpdater( $validator, $descriptor, $binding, $state, $archive, $this->acquirer( $validator, $descriptor, $archive ) ); $extra = array( 'plugin' => 'package/package.php' ); $owned = $updater->filterPreDownload( false, 'ignored', null, $extra ); self::assertIsString( $owned );
		self::assertFalse( $updater->filterUpdate( false, array( 'Version' => '1.0.0', 'UpdateURI' => $this->uri( 'other/package' ) ), 'package/package.php', array() ) ); self::assertNotSame( array(), $updater->diagnostics() );
		$updater->refresh();
		self::assertSame( array(), $updater->diagnostics() ); self::assertFileDoesNotExist( $owned ); self::assertDirectoryDoesNotExist( dirname( $owned ) ); self::assertInstanceOf( \WP_Error::class, $updater->filterPreInstall( true, $extra ) );
	}

	public function testOwnedArchiveSurvivesCallbackPathMutationAndCoreDeletionBeforePreInstall(): void {
		list( $validator, $descriptor, $binding, $state, $archive ) = $this->ready(); $updater = $this->configuredUpdater( $validator, $descriptor, $binding, $state, $archive, $this->acquirer( $validator, $descriptor, $archive ) ); $extra = array( 'plugin' => 'package/package.php' ); $owned = $updater->filterPreDownload( false, 'ignored', null, $extra ); self::assertIsString( $owned );
		file_put_contents( $archive, 'callback path changed' ); self::assertNull( $updater->filterPreUnzipFile( null, $owned, '/tmp', array(), 1.0 ) ); unlink( $owned ); self::assertTrue( $updater->filterPreInstall( true, $extra ) );
	}

	public function testOwnedArchiveMutationFailsBeforeExtractionAndLeavesNoOrphan(): void {
		list( $validator, $descriptor, $binding, $state, $archive ) = $this->ready(); $updater = $this->configuredUpdater( $validator, $descriptor, $binding, $state, $archive, $this->acquirer( $validator, $descriptor, $archive ) ); $extra = array( 'plugin' => 'package/package.php' ); $owned = $updater->filterPreDownload( false, 'ignored', null, $extra ); self::assertIsString( $owned ); @chmod( $owned, 0600 ); file_put_contents( $owned, 'owned path changed' ); self::assertInstanceOf( \WP_Error::class, $updater->filterPreUnzipFile( null, $owned, '/tmp', array(), 1.0 ) ); self::assertFileDoesNotExist( $owned ); self::assertDirectoryDoesNotExist( dirname( $owned ) );
	}

	public function testDanglingOwnedArchiveLinkFailsBeforeExtractionAndLeavesNoOrphan(): void {
		list( $validator, $descriptor, $binding, $state, $archive ) = $this->ready(); $updater = $this->configuredUpdater( $validator, $descriptor, $binding, $state, $archive, $this->acquirer( $validator, $descriptor, $archive ) ); $extra = array( 'plugin' => 'package/package.php' ); $owned = $updater->filterPreDownload( false, 'ignored', null, $extra ); self::assertIsString( $owned ); unlink( $owned ); self::assertTrue( symlink( $owned . '.missing', $owned ) ); self::assertTrue( is_link( $owned ) ); self::assertInstanceOf( \WP_Error::class, $updater->filterPreUnzipFile( null, $owned, '/tmp', array(), 1.0 ) ); self::assertFalse( is_link( $owned ) ); self::assertDirectoryDoesNotExist( dirname( $owned ) );
	}

	public function testNonDirectFenceCleansAnOwnedArchiveBeforePreInstall(): void {
		list( $validator, $descriptor, $binding, $state, $archive ) = $this->ready(); $updater = $this->configuredUpdater( $validator, $descriptor, $binding, $state, $archive, $this->acquirer( $validator, $descriptor, $archive ) ); $extra = array( 'plugin' => 'package/package.php' ); $owned = $updater->filterPreDownload( false, 'ignored', null, $extra ); self::assertIsString( $owned ); $GLOBALS['ran_wp_release_updater_test_filesystem_method'] = 'ftpext'; self::assertInstanceOf( \WP_Error::class, $updater->filterPreInstall( true, $extra ) ); self::assertFileDoesNotExist( $owned ); self::assertDirectoryDoesNotExist( dirname( $owned ) );
	}

	public function testNonDirectFenceIsRecheckedAtSourceSelection(): void {
		list( $validator, $descriptor, $binding, $state, $archive ) = $this->ready(); $updater = $this->configuredUpdater( $validator, $descriptor, $binding, $state, $archive, $this->acquirer( $validator, $descriptor, $archive ) ); $extra = array( 'plugin' => 'package/package.php' ); $owned = $updater->filterPreDownload( false, 'ignored', null, $extra ); self::assertIsString( $owned ); self::assertNull( $updater->filterPreUnzipFile( null, $owned, '/tmp', array(), 1.0 ) ); unlink( $owned ); self::assertTrue( $updater->filterPreInstall( true, $extra ) ); $GLOBALS['ran_wp_release_updater_test_filesystem_method'] = 'ftpext'; self::assertInstanceOf( \WP_Error::class, $updater->filterSourceSelection( $this->staged(), '/tmp', null, $extra ) ); self::assertFileDoesNotExist( $owned ); self::assertDirectoryDoesNotExist( dirname( $owned ) );
	}

	public function testStagedMutationIsRejectedAgainstTheArchiveManifestBeforeSourceSelection(): void {
		list( $validator, $descriptor, $binding, $state, $archive ) = $this->ready(); $updater = $this->configuredUpdater( $validator, $descriptor, $binding, $state, $archive, $this->acquirer( $validator, $descriptor, $archive ) ); $extra = array( 'plugin' => 'package/package.php' ); $owned = $updater->filterPreDownload( false, 'ignored', null, $extra ); self::assertIsString( $owned ); self::assertNull( $updater->filterPreUnzipFile( null, $owned, '/tmp', array(), 1.0 ) ); unlink( $owned ); self::assertTrue( $updater->filterPreInstall( true, $extra ) ); $staged = $this->staged(); file_put_contents( $staged . '/package.php', "\n// changed after extraction\n", FILE_APPEND ); self::assertInstanceOf( \WP_Error::class, $updater->filterSourceSelection( $staged, '/tmp', null, $extra ) ); self::assertNotContains( 'update_completed', $updater->diagnostics() );
	}

	public function testInstalledMutationCannotCompleteAgainstTheArchiveManifest(): void {
		list( $validator, $descriptor, $binding, $state, $archive ) = $this->ready(); $updater = $this->configuredUpdater( $validator, $descriptor, $binding, $state, $archive, $this->acquirer( $validator, $descriptor, $archive ) ); $extra = array( 'plugin' => 'package/package.php' ); $owned = $updater->filterPreDownload( false, 'ignored', null, $extra ); self::assertIsString( $owned ); self::assertNull( $updater->filterPreUnzipFile( null, $owned, '/tmp', array(), 1.0 ) ); unlink( $owned ); self::assertTrue( $updater->filterPreInstall( true, $extra ) ); $staged = $this->staged(); self::assertSame( $staged, $updater->filterSourceSelection( $staged, '/tmp', null, $extra ) ); file_put_contents( $staged . '/package.php', "\n// changed before completion\n", FILE_APPEND ); self::assertSame( array( 'destination' => $staged ), $updater->captureInstallPackageResult( array( 'destination' => $staged ), $extra ) ); $updater->observeCompletion( null, array( 'action' => 'update', 'type' => 'plugin', 'plugins' => array( 'package/package.php' ) ) ); $updater->finalizePendingInstall(); $diagnostics = $updater->diagnostics(); self::assertSame( 'outcome_uncertain', end( $diagnostics ) ); self::assertNotContains( 'update_completed', $diagnostics );
	}

	public function testBulkTargetAndExactAcquisitionAreAdmittedThenStagedMetadataIsRechecked(): void {
		list( $validator, $descriptor, $binding, $state, $archive ) = $this->ready();
		$updater = $this->updater( static function ( BindingState $current, int $now ) use ( $validator, $descriptor, $archive ): array { $package = $validator->validate( $descriptor, array( 'archive_root' => 'package', 'configuration_update_uri' => 'https' . '://' . 'updates.example.test/owner/package', 'header_file' => 'package.php', 'installed_package_identity' => 'package/package.php', 'metadata_name' => 'Package', 'offer_or_cache_update_uri' => 'https' . '://' . 'updates.example.test/owner/package', 'php_runtime_version' => '8.2', 'provider_code' => 'neutral', 'repository_identity' => 'repo:1', 'repository_locator' => 'owner/package', 'staged_package_update_uri' => 'https' . '://' . 'updates.example.test/owner/package', 'target_type' => 'plugin', 'wordpress_runtime_version' => '6.8' ), $archive ); return array( 'path' => $archive, 'receipt' => AcquisitionReceipt::issue( $current, $descriptor, $validator, $package, $now ) ); } );
		$bulk = array( 'plugin' => 'package/package.php' ); $updater->capturePackageOptions( array( 'is_multi' => true, 'hook_extra' => array( 'action' => 'update', 'type' => 'plugin', 'plugins' => array( 'package/package.php' ) ) ) ); $owned = $updater->filterPreDownload( false, 'ignored', null, $bulk ); self::assertIsString( $owned ); self::assertNotSame( $archive, $owned );
		$staged = $this->staged(); self::assertNull( $updater->filterPreUnzipFile( null, $owned, '/tmp', array(), 1.0 ) ); unlink( $owned ); self::assertTrue( $updater->filterPreInstall( true, $bulk ) ); self::assertSame( $staged, $updater->filterSourceSelection( $staged, '/tmp', null, $bulk ) ); self::assertSame( array( 'destination' => $staged ), $updater->captureInstallPackageResult( array( 'destination' => $staged ), $bulk ) ); $updater->finalizePendingInstall();
		self::assertInstanceOf( \WP_Error::class, $updater->filterPreInstall( true, $bulk ) );
		self::assertSame( 'pass', $updater->filterPreDownload( 'pass', 'ignored', null, array( 'plugin' => 'package/package.php', 'action' => 'install' ) ) );
	}

	public function testUnrelatedUnzipAndChainedPredownloadRepliesPassOrFailClosed(): void {
		$updater = $this->updater(); $extra = array( 'plugin' => 'package/package.php' ); $completion = array( 'action' => 'update', 'type' => 'plugin', 'plugins' => array( 'package/package.php' ) ); $error = new \WP_Error( 'upstream', 'upstream' ); $queued = new \ReflectionProperty( NativePluginUpdater::class, 'queuedMultiRun' );
		self::assertTrue( $updater->filterPreUnzipFile( true, '/other.zip', '/tmp', array(), 0.0 ) ); $updater->capturePackageOptions( array( 'is_multi' => true, 'hook_extra' => $completion ) ); self::assertSame( $error, $updater->filterPreDownload( $error, 'ignored', null, $extra ) ); self::assertFalse( $queued->getValue( $updater ) ); $updater->capturePackageOptions( array( 'is_multi' => true, 'hook_extra' => $completion ) ); self::assertInstanceOf( \WP_Error::class, $updater->filterPreDownload( '/earlier.zip', 'ignored', null, $extra ) ); self::assertFalse( $queued->getValue( $updater ) );
	}

	public function testExpiredAuthoritativeFenceAfterConstructionFailsEveryExposedSeam(): void {
		$updater = $this->updater(); $this->database->setTime( 121 );
		self::assertSame( false, $updater->filterUpdate( false, array( 'Version' => '1.0.0', 'UpdateURI' => $this->uri( 'owner/package' ) ), 'package/package.php', array() ) );
		self::assertSame( 'keep', $updater->filterPluginInformation( 'keep', 'plugin_information', (object) array( 'slug' => 'ran-wp-release-updater-' . substr( hash( 'sha256', "plugin\0package/package.php" ), 0, 24 ) ) ) );
		self::assertFalse( $updater->filterAutoUpdate( true, (object) array( 'plugin' => 'package/package.php' ) ) ); self::assertInstanceOf( \WP_Error::class, $updater->filterPreDownload( false, 'ignored', null, array( 'plugin' => 'package/package.php' ) ) );
	}

	public function testMoreThanTheArchiveEntryLimitRejectsTheEntireManifest(): void {
		$root = $this->staged(); for ( $index = 0; $index < 10000; ++$index ) file_put_contents( $root . '/f' . $index, 'x' );
		$method = new \ReflectionMethod( NativePluginUpdater::class, 'regularFileManifest' ); self::assertNull( $method->invoke( null, $root ) );
	}

	public function testFailedBulkAttemptDoesNotLeakModeAndShutdownRegistersOnlyOnceAcrossRetry(): void {
		list( $validator, $descriptor, $binding, $state, $archive ) = $this->ready(); $calls = 0;
		$updater = $this->configuredUpdater( $validator, $descriptor, $binding, $state, $archive, static function ( BindingState $current, int $now ) use ( $validator, $descriptor, $archive, &$calls ): array { if ( 1 === ++$calls ) throw new \RuntimeException( 'first acquisition fails' ); $package = $validator->validate( $descriptor, array( 'archive_root' => 'package', 'configuration_update_uri' => 'https' . '://' . 'updates.example.test/owner/package', 'header_file' => 'package.php', 'installed_package_identity' => 'package/package.php', 'metadata_name' => 'Package', 'offer_or_cache_update_uri' => 'https' . '://' . 'updates.example.test/owner/package', 'php_runtime_version' => '8.2', 'provider_code' => 'neutral', 'repository_identity' => 'repo:1', 'repository_locator' => 'owner/package', 'staged_package_update_uri' => 'https' . '://' . 'updates.example.test/owner/package', 'target_type' => 'plugin', 'wordpress_runtime_version' => '6.8' ), $archive ); return array( 'path' => $archive, 'receipt' => AcquisitionReceipt::issue( $current, $descriptor, $validator, $package, $now ) ); } );
		$extra = array( 'plugin' => 'package/package.php' ); $updater->capturePackageOptions( array( 'is_multi' => true, 'hook_extra' => array( 'action' => 'update', 'type' => 'plugin', 'plugins' => array( 'package/package.php' ) ) ) );
		self::assertInstanceOf( \WP_Error::class, $updater->filterPreDownload( false, 'ignored', null, $extra ) ); $owned = $updater->filterPreDownload( false, 'ignored', null, $extra ); self::assertIsString( $owned ); self::assertNotSame( $archive, $owned );
		$staged = $this->staged(); self::assertNull( $updater->filterPreUnzipFile( null, $owned, '/tmp', array(), 1.0 ) ); unlink( $owned ); self::assertTrue( $updater->filterPreInstall( true, $extra ) ); self::assertSame( $staged, $updater->filterSourceSelection( $staged, '/tmp', null, $extra ) ); self::assertSame( array( 'destination' => $staged ), $updater->captureInstallPackageResult( array( 'destination' => $staged ), $extra ) ); $updater->finalizePendingInstall();
		$diagnostics = $updater->diagnostics(); self::assertSame( 'outcome_uncertain', end( $diagnostics ) ); self::assertCount( 1, array_filter( $GLOBALS['ran_wp_release_updater_test_hooks'], static fn ( array $hook ): bool => 'shutdown' === $hook[1] ) );
		self::assertIsString( $updater->filterPreDownload( false, 'ignored', null, $extra ) ); self::assertCount( 1, array_filter( $GLOBALS['ran_wp_release_updater_test_hooks'], static fn ( array $hook ): bool => 'shutdown' === $hook[1] ) );
	}

	public function testAuthoritativeRebindBetweenUnzipAndSourceSelectionRejectsTheStaleReceipt(): void {
		list( $validator, $descriptor, $binding, $state, $archive ) = $this->ready();
		$updater = $this->configuredUpdater( $validator, $descriptor, $binding, $state, $archive, static function ( BindingState $current, int $now ) use ( $validator, $descriptor, $archive ): array { $package = $validator->validate( $descriptor, array( 'archive_root' => 'package', 'configuration_update_uri' => 'https' . '://' . 'updates.example.test/owner/package', 'header_file' => 'package.php', 'installed_package_identity' => 'package/package.php', 'metadata_name' => 'Package', 'offer_or_cache_update_uri' => 'https' . '://' . 'updates.example.test/owner/package', 'php_runtime_version' => '8.2', 'provider_code' => 'neutral', 'repository_identity' => 'repo:1', 'repository_locator' => 'owner/package', 'staged_package_update_uri' => 'https' . '://' . 'updates.example.test/owner/package', 'target_type' => 'plugin', 'wordpress_runtime_version' => '6.8' ), $archive ); return array( 'path' => $archive, 'receipt' => AcquisitionReceipt::issue( $current, $descriptor, $validator, $package, $now ) ); } );
		$extra = array( 'plugin' => 'package/package.php' ); $owned = $updater->filterPreDownload( false, 'ignored', null, $extra ); self::assertIsString( $owned ); self::assertNull( $updater->filterPreUnzipFile( null, $owned, '/tmp', array(), 1.0 ) ); unlink( $owned ); self::assertTrue( $updater->filterPreInstall( true, $extra ) );
		$nextFacts = $binding->toArray(); unset( $nextFacts['binding_hash'] ); $nextFacts['update_policy'] = 'automatic'; $next = BindingRecord::create( $nextFacts );
		self::assertSame( 'rebound', ReleaseOperationCoordinator::persistPersistentBindingState( $this->database, $state, $this->claim( $state ), $next )['result'] ); self::assertInstanceOf( \WP_Error::class, $updater->filterSourceSelection( $this->staged(), '/tmp', null, $extra ) ); self::assertNotContains( 'update_completed', $updater->diagnostics() );
	}

	public function testInstallSeamsFailClosedWithoutAcquisitionAndBadNativeIdentityIsRejected(): void {
		$updater = $this->updater(); $extra = array( 'plugin' => 'package/package.php' );
		self::assertInstanceOf( \WP_Error::class, $updater->filterPreDownload( false, 'ignored', null, $extra ) ); self::assertInstanceOf( \WP_Error::class, $updater->filterSourceSelection( '/missing', '/tmp', null, $extra ) );
		$config = $this->configuration(); $config['installed_package_identity'] = '../package.php';
		list( $validator, $descriptor, $binding, $state, $archive ) = $this->ready(); $package = $validator->validate( $descriptor, $this->policy(), $archive ); self::assertNull( NativePluginUpdater::fromConfiguration( $config, $descriptor, $binding, $this->database, $state, $this->claim( $state ), AcquisitionReceipt::issue( $state, $descriptor, $validator, $package, 100 ) ) );
	}

	private function updater( ?callable $acquire = null ): NativePluginUpdater { list( $validator, $descriptor, $binding, $state, $archive ) = $this->ready(); return $this->configuredUpdater( $validator, $descriptor, $binding, $state, $archive, $acquire ); }
	private function acquirer( PackageIdentityValidator $validator, IdentityDescriptor $descriptor, string $archive ): callable { return function ( BindingState $current, int $now ) use ( $validator, $descriptor, $archive ): array { $package = $validator->validate( $descriptor, $this->policy(), $archive ); return array( 'path' => $archive, 'receipt' => AcquisitionReceipt::issue( $current, $descriptor, $validator, $package, $now ) ); }; }
	private function configuredUpdater( PackageIdentityValidator $validator, IdentityDescriptor $descriptor, BindingRecord $binding, BindingState $state, string $archive, ?callable $acquire ): NativePluginUpdater { $package = $validator->validate( $descriptor, $this->policy(), $archive ); return NativePluginUpdater::fromConfiguration( $this->configuration(), $descriptor, $binding, $this->database, $state, $this->claim( $state ), AcquisitionReceipt::issue( $state, $descriptor, $validator, $package, 100 ), $acquire ); }
	/** @return array{PackageIdentityValidator,IdentityDescriptor,BindingRecord,BindingState,string} */ private function ready(): array { $archive = $this->archive(); $descriptor = $this->descriptor( $archive ); $binding = $this->binding(); $this->database = new FakeOptionDatabase( 100 ); $state = ReleaseOperationCoordinator::claimPersistentBindingState( $this->database, $binding, str_repeat( 'a', 64 ), 20 )['current']; return array( new PackageIdentityValidator(), $descriptor, $binding, $state, $archive ); }
	/** @return array<string,mixed> */ private function claim( BindingState $state ): array { return array( 'binding_generation' => $state->bindingGeneration(), 'binding_hash' => $state->binding()->bindingHash(), 'lease_deadline' => $state->leaseDeadline(), 'owner_token' => $state->ownerToken() ); }
	private function archive(): string { $path = tempnam( sys_get_temp_dir(), 'ran-native-' ); self::assertIsString( $path ); $this->paths[] = $path; $zip = new \ZipArchive(); self::assertTrue( $zip->open( $path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ); self::assertTrue( $zip->addFromString( 'package/package.php', "<?php\n/*\nPlugin Name: Package\nVersion: 2.0.0\nUpdate URI: " . $this->uri( 'owner/package' ) . "\n*/" ) ); $zip->close(); return $path; }
	/** @return array{PackageIdentityValidator,IdentityDescriptor,BindingRecord,BindingState,string} */ private function themeReady(): array { $archive = tempnam( sys_get_temp_dir(), 'ran-theme-' ); self::assertIsString( $archive ); $this->paths[] = $archive; $zip = new \ZipArchive(); $zip->open( $archive, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ); $zip->addFromString( 'package/style.css', "/*\nTheme Name: Package\nVersion: 2.0.0\nUpdate URI: " . $this->uri( 'owner/package' ) . "\n*/" ); $zip->close(); $facts = $this->descriptor( $archive )->toArray(); unset( $facts['fingerprint'] ); $facts['installed_package_identity'] = 'package'; $facts['target_type'] = 'theme'; $facts['artifact_sha256'] = hash_file( 'sha256', $archive ); $facts['artifact_size'] = filesize( $archive ); $descriptor = IdentityDescriptor::create( $facts ); $bindingFacts = $this->binding()->toArray(); unset( $bindingFacts['binding_hash'] ); $bindingFacts['installed_package_identity'] = 'package'; $bindingFacts['target_type'] = 'theme'; $binding = BindingRecord::create( $bindingFacts ); $this->database = new FakeOptionDatabase( 100 ); $state = ReleaseOperationCoordinator::claimPersistentBindingState( $this->database, $binding, str_repeat( 'a', 64 ), 20 )['current']; return array( new PackageIdentityValidator(), $descriptor, $binding, $state, $archive ); }
	private function staged(): string { $parent = sys_get_temp_dir() . '/ran-native-stage-' . bin2hex( random_bytes( 5 ) ); $path = $parent . '/package'; mkdir( $path, 0700, true ); $this->paths[] = $path; $this->paths[] = $parent; file_put_contents( $path . '/package.php', "<?php\n/*\nPlugin Name: Package\nVersion: 2.0.0\nUpdate URI: " . $this->uri( 'owner/package' ) . "\n*/" ); return $path; }
	/** @return array<string,mixed> */ private function configuration(): array { return array( 'headers' => array( 'Author' => 'Author', 'Description' => 'Description', 'Name' => 'Package', 'PluginURI' => $this->uri( 'owner/package' ), 'RequiresPHP' => '8.2', 'RequiresWP' => '6.8', 'UpdateURI' => $this->uri( 'owner/package' ), 'Version' => '1.0.0' ), 'installed_package_identity' => 'package/package.php', 'policy' => 'manual', 'target_type' => 'plugin', 'update_uri' => $this->uri( 'owner/package' ) ); }
	/** @return array<string,mixed> */ private function themeConfiguration(): array { $configuration = $this->configuration(); $configuration['installed_package_identity'] = 'package'; $configuration['target_type'] = 'theme'; return $configuration; }
	/** @return array<string,string> */ private function policy(): array { return array( 'archive_root' => 'package', 'configuration_update_uri' => $this->uri( 'owner/package' ), 'header_file' => 'package.php', 'installed_package_identity' => 'package/package.php', 'metadata_name' => 'Package', 'offer_or_cache_update_uri' => $this->uri( 'owner/package' ), 'php_runtime_version' => '8.2', 'provider_code' => 'neutral', 'repository_identity' => 'repo:1', 'repository_locator' => 'owner/package', 'staged_package_update_uri' => $this->uri( 'owner/package' ), 'target_type' => 'plugin', 'wordpress_runtime_version' => '6.8' ); }
	/** @return array<string,string> */ private function themePolicy(): array { $policy = $this->policy(); $policy['header_file'] = 'style.css'; $policy['installed_package_identity'] = 'package'; $policy['target_type'] = 'theme'; return $policy; }
	private function uri( string $path ): string { return 'https' . '://' . 'updates.example.test/' . $path; }
	private function binding(): BindingRecord { return BindingRecord::create( array( 'canonical_repository_locator' => 'owner/package', 'canonical_update_uri' => $this->uri( 'owner/package' ), 'installed_package_identity' => 'package/package.php', 'php_runtime_version' => '8.2', 'provider_code' => 'neutral', 'release_channel' => 'stable', 'stable_repository_identity' => 'repo:1', 'target_type' => 'plugin', 'update_policy' => 'manual', 'wordpress_runtime_version' => '6.8' ) ); }
	private function descriptor( string $path ): IdentityDescriptor { return IdentityDescriptor::create( array( 'artifact_filename' => 'package.zip', 'artifact_identity' => 'asset:2', 'artifact_sha256' => hash_file( 'sha256', $path ), 'artifact_size' => filesize( $path ), 'assurance_facts' => array( 'exact_artifact_identity' => true, 'exact_commit_identity' => true, 'exact_reacquisition_supported' => true, 'exact_release_identity' => true, 'provenance_verified' => true, 'publication_immutable' => true, 'repository_identity_stable' => true, 'trusted_digest_source' => true ), 'canonical_update_uri' => $this->uri( 'owner/package' ), 'channel' => 'stable', 'commit_identity' => 'commit:2', 'installed_package_identity' => 'package/package.php', 'prerelease' => false, 'provider_code' => 'neutral', 'release_identity' => 'release:2', 'repository_identity' => 'repo:1', 'repository_locator' => 'owner/package', 'tag' => 'v2.0.0', 'target_type' => 'plugin', 'version' => '2.0.0' ) ); }
}
}
