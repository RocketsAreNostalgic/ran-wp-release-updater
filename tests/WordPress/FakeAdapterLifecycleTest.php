<?php

declare(strict_types=1);

namespace {
	if ( ! class_exists( 'WP_Error' ) ) {
		final class WP_Error {
			public function __construct( public string $code, public string $message ) {}
		}
	}
	if ( ! function_exists( 'add_filter' ) ) {
		function add_filter( string $hook, mixed $callback, int $priority, int $arguments ): void { $GLOBALS['ran_wp_release_updater_test_hooks'][] = array( 'filter', $hook, $callback, $priority, $arguments ); }
	}
	if ( ! function_exists( 'add_action' ) ) {
		function add_action( string $hook, mixed $callback, int $priority, int $arguments ): void { $GLOBALS['ran_wp_release_updater_test_hooks'][] = array( 'action', $hook, $callback, $priority, $arguments ); }
	}
	if ( ! function_exists( 'get_filesystem_method' ) ) {
		function get_filesystem_method(): string { return $GLOBALS['ran_wp_release_updater_test_filesystem_method'] ?? 'direct'; }
	}
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

/** Exercises updater seams only; Core backup, rollback, and activation remain out of scope. */
final class FakeAdapterLifecycleTest extends TestCase {

	/** @var list<string> */
	private array $paths = array();

	protected function setUp(): void {
		$GLOBALS['ran_wp_release_updater_test_hooks'] = array();
	}

	protected function tearDown(): void {
		foreach ( array_reverse( $this->paths ) as $path ) {
			$this->remove( $path );
		}
		parent::tearDown();
	}

	public function testPluginDiscoveryReachesVerifiedLifecycleCompletionAcrossTheFourBoundaries(): void {
		$this->assertCompletedLifecycle( 'plugin' );
	}

	public function testThemeDiscoveryReachesVerifiedLifecycleCompletionAcrossTheFourBoundaries(): void {
		$this->assertCompletedLifecycle( 'theme' );
	}

	private function assertCompletedLifecycle( string $targetType ): void {
		$uri = 'https://updates.example.test/owner/fake-release';
		$archive = $this->archive( $targetType, $uri );
		$descriptor = $this->descriptor( $targetType, $archive, $uri );
		$validator = new PackageIdentityValidator();
		$policy = $this->policy( $targetType, $uri );

		$redirectCandidate = $policy;
		$redirectCandidate['offer_or_cache_update_uri'] = 'https://updates.example.test/owner/fake-release-redirect';
		self::assertSame( 'archive_target_policy_invalid', $validator->validate( $descriptor, $redirectCandidate, $archive )->code() );

		$package = $validator->validate( $descriptor, $policy, $archive );
		self::assertTrue( $package->isValid() );

		$binding = $this->binding( $targetType, $uri );
		$database = new FakeOptionDatabase( 100 );
		$claimResult = ReleaseOperationCoordinator::claimPersistentBindingState( $database, $binding, str_repeat( 'a', 64 ), 20 );
		self::assertSame( 'claimed', $claimResult['result'] );
		$state = $claimResult['current'];
		$claim = $this->claim( $state );
		$destinationParent = sys_get_temp_dir() . '/ran-fake-adapter-destination-' . bin2hex( random_bytes( 8 ) );

		$configurationMismatch = $this->configuration( $targetType, $uri );
		$configurationMismatch['update_uri'] = 'https://updates.example.test/owner/other-path';
		$configurationPackage = $validator->validate( $descriptor, $policy, $archive );
		self::assertNull( NativePluginUpdater::fromConfiguration( $configurationMismatch, $descriptor, $binding, $database, $state, $claim, AcquisitionReceipt::issue( $state, $descriptor, $validator, $configurationPackage, 100 ) ) );
		self::assertDirectoryDoesNotExist( $destinationParent );

		$this->assertStagedHeaderMismatchDoesNotCreateDestination( $targetType, $uri, $descriptor, $binding, $database, $state, $claim, $validator, $policy, $archive, $destinationParent );
		$GLOBALS['ran_wp_release_updater_test_hooks'] = array();

		$inspection = AcquisitionReceipt::issue( $state, $descriptor, $validator, $package, 100 );
		$updater = NativePluginUpdater::fromConfiguration(
			$this->configuration( $targetType, $uri ),
			$descriptor,
			$binding,
			$database,
			$state,
			$claim,
			$inspection,
			function ( BindingState $current, int $now ) use ( $validator, $descriptor, $policy, $archive ): array {
				$package = $validator->validate( $descriptor, $policy, $archive );
				return array( 'path' => $archive, 'receipt' => AcquisitionReceipt::issue( $current, $descriptor, $validator, $package, $now ) );
			}
		);
		self::assertInstanceOf( NativePluginUpdater::class, $updater );
		$updater->register();
		$updater->register();
		self::assertSame( $this->expectedTargetHooks( $targetType ), array_column( $GLOBALS['ran_wp_release_updater_test_hooks'], 1 ) );

		$identity = 'plugin' === $targetType ? 'fake-release/fake-release.php' : 'fake-release';
		$offer = $updater->filterUpdate( false, array( 'Version' => '1.0.0', 'UpdateURI' => $uri ), $identity, array() );
		self::assertIsArray( $offer );
		self::assertSame( '', $offer['package'] );
		self::assertSame( false, $updater->filterUpdate( false, array( 'Version' => '1.0.0', 'UpdateURI' => 'https://updates.example.test/owner/other-path' ), $identity, array() ) );

		$extra = array( 'plugin' === $targetType ? 'plugin' : 'theme' => $identity );
		$owned = $updater->filterPreDownload( false, 'ignored', null, $extra );
		self::assertIsString( $owned );
		$shutdown = array_values( array_filter( $GLOBALS['ran_wp_release_updater_test_hooks'], static fn ( array $hook ): bool => 'shutdown' === $hook[1] ) );
		self::assertCount( 1, $shutdown );
		self::assertSame( array( 'action', 'shutdown', array( $updater, 'finalizePendingInstall' ), PHP_INT_MAX, 0 ), $shutdown[0] );
		self::assertNull( $updater->filterPreUnzipFile( null, $owned, sys_get_temp_dir(), array(), 0.0 ) );
		unlink( $owned ); // Core deletes the archive after successful extraction.
		self::assertTrue( $updater->filterPreInstall( true, $extra ) );

		$staged = $this->tree( $targetType, $uri, 'staged' );
		self::assertSame( $staged, $updater->filterSourceSelection( $staged, sys_get_temp_dir(), null, $extra ) );
		$destination = $this->tree( $targetType, $uri, 'destination' );
		self::assertSame( array( 'destination' => $destination ), $updater->captureInstallPackageResult( array( 'destination' => $destination ), $extra ) );
		$updater->observeCompletion( null, array( 'action' => 'update', 'type' => $targetType, 'plugin' === $targetType ? 'plugins' : 'themes' => array( $identity ) ) );
		$updater->finalizePendingInstall();

		$diagnostics = $updater->diagnostics();
		self::assertSame( 'update_completed', end( $diagnostics ) );
	}

	private function assertStagedHeaderMismatchDoesNotCreateDestination( string $targetType, string $uri, IdentityDescriptor $descriptor, BindingRecord $binding, FakeOptionDatabase $database, BindingState $state, array $claim, PackageIdentityValidator $validator, array $policy, string $archive, string $destinationParent ): void {
		$inspectionPackage = $validator->validate( $descriptor, $policy, $archive );
		$updater = NativePluginUpdater::fromConfiguration(
			$this->configuration( $targetType, $uri ),
			$descriptor,
			$binding,
			$database,
			$state,
			$claim,
			AcquisitionReceipt::issue( $state, $descriptor, $validator, $inspectionPackage, 100 ),
			function ( BindingState $current, int $now ) use ( $validator, $descriptor, $policy, $archive ): array {
				$package = $validator->validate( $descriptor, $policy, $archive );
				return array( 'path' => $archive, 'receipt' => AcquisitionReceipt::issue( $current, $descriptor, $validator, $package, $now ) );
			}
		);
		self::assertInstanceOf( NativePluginUpdater::class, $updater );
		$identity = 'plugin' === $targetType ? 'fake-release/fake-release.php' : 'fake-release';
		$extra = array( 'plugin' === $targetType ? 'plugin' : 'theme' => $identity );
		$owned = $updater->filterPreDownload( false, 'ignored', null, $extra );
		self::assertIsString( $owned );
		self::assertNull( $updater->filterPreUnzipFile( null, $owned, sys_get_temp_dir(), array(), 0.0 ) );
		unlink( $owned );
		self::assertTrue( $updater->filterPreInstall( true, $extra ) );
		$staged = $this->tree( $targetType, 'https://updates.example.test/owner/other-path', 'staged-header-mismatch' );
		self::assertInstanceOf( \WP_Error::class, $updater->filterSourceSelection( $staged, sys_get_temp_dir(), null, $extra ) );
		self::assertDirectoryDoesNotExist( $destinationParent );
	}

	/** @return list<string> */
	private function expectedTargetHooks( string $targetType ): array {
		$hooks = array( 'plugin' === $targetType ? 'update_plugins_updates.example.test' : 'update_themes_updates.example.test' );
		if ( 'plugin' === $targetType ) {
			$hooks[] = 'plugins_api';
		}
		return array_merge( $hooks, array( 'plugin' === $targetType ? 'auto_update_plugin' : 'auto_update_theme', 'upgrader_package_options', 'upgrader_pre_download', 'upgrader_pre_install', 'pre_unzip_file', 'upgrader_source_selection', 'upgrader_install_package_result', 'upgrader_process_complete' ) );
	}

	private function archive( string $targetType, string $uri ): string {
		$path = tempnam( sys_get_temp_dir(), 'ran-phase24-' );
		self::assertIsString( $path );
		$this->paths[] = $path;
		$zip = new \ZipArchive();
		self::assertTrue( $zip->open( $path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) );
		self::assertTrue( $zip->addFromString( 'fake-release/' . ( 'plugin' === $targetType ? 'fake-release.php' : 'style.css' ), $this->header( $targetType, $uri ) ) );
		self::assertTrue( $zip->addFromString( 'fake-release/payload.php', '<?php return true;' ) );
		$zip->close();
		return $path;
	}

	private function tree( string $targetType, string $uri, string $suffix ): string {
		$parent = sys_get_temp_dir() . '/ran-phase24-' . $suffix . '-' . bin2hex( random_bytes( 8 ) );
		$root = $parent . '/fake-release';
		self::assertTrue( mkdir( $root, 0700, true ) );
		file_put_contents( $root . '/' . ( 'plugin' === $targetType ? 'fake-release.php' : 'style.css' ), $this->header( $targetType, $uri ) );
		file_put_contents( $root . '/payload.php', '<?php return true;' );
		$this->paths[] = $parent;
		return $root;
	}

	private function header( string $targetType, string $uri ): string {
		$name = 'plugin' === $targetType ? 'Plugin Name: Fake Release' : 'Theme Name: Fake Release';
		return "<?php\n/*\n{$name}\nVersion: 2.0.0\nUpdate URI: {$uri}\n*/";
	}

	/** @return array<string,string> */
	private function policy( string $targetType, string $uri ): array {
		$header = 'plugin' === $targetType ? 'fake-release.php' : 'style.css';
		return array( 'archive_root' => 'fake-release', 'configuration_update_uri' => $uri, 'header_file' => $header, 'installed_package_identity' => 'plugin' === $targetType ? 'fake-release/fake-release.php' : 'fake-release', 'metadata_name' => 'Fake Release', 'offer_or_cache_update_uri' => $uri, 'php_runtime_version' => '8.2', 'provider_code' => 'fake', 'repository_identity' => 'fake:repository', 'repository_locator' => 'owner/fake-release', 'staged_package_update_uri' => $uri, 'target_type' => $targetType, 'wordpress_runtime_version' => '6.8' );
	}

	/** @return array<string,mixed> */
	private function configuration( string $targetType, string $uri ): array {
		return array( 'headers' => array( 'Author' => 'Test', 'Description' => 'Local fake adapter proof', 'Name' => 'Fake Release', 'PluginURI' => $uri, 'RequiresPHP' => '8.2', 'RequiresWP' => '6.8', 'UpdateURI' => $uri, 'Version' => '1.0.0' ), 'installed_package_identity' => 'plugin' === $targetType ? 'fake-release/fake-release.php' : 'fake-release', 'policy' => 'manual', 'target_type' => $targetType, 'update_uri' => $uri );
	}

	private function binding( string $targetType, string $uri ): BindingRecord {
		return BindingRecord::create( array( 'canonical_repository_locator' => 'owner/fake-release', 'canonical_update_uri' => $uri, 'installed_package_identity' => 'plugin' === $targetType ? 'fake-release/fake-release.php' : 'fake-release', 'php_runtime_version' => '8.2', 'provider_code' => 'fake', 'release_channel' => 'stable', 'stable_repository_identity' => 'fake:repository', 'target_type' => $targetType, 'update_policy' => 'manual', 'wordpress_runtime_version' => '6.8' ) );
	}

	private function descriptor( string $targetType, string $archive, string $uri ): IdentityDescriptor {
		return IdentityDescriptor::create( array( 'artifact_filename' => 'fake-release.zip', 'artifact_identity' => 'fake-artifact:2', 'artifact_sha256' => hash_file( 'sha256', $archive ), 'artifact_size' => filesize( $archive ), 'assurance_facts' => array( 'exact_artifact_identity' => true, 'exact_commit_identity' => true, 'exact_reacquisition_supported' => true, 'exact_release_identity' => true, 'provenance_verified' => true, 'publication_immutable' => true, 'repository_identity_stable' => true, 'trusted_digest_source' => true ), 'canonical_update_uri' => $uri, 'channel' => 'stable', 'commit_identity' => 'fake-commit:2', 'installed_package_identity' => 'plugin' === $targetType ? 'fake-release/fake-release.php' : 'fake-release', 'prerelease' => false, 'provider_code' => 'fake', 'release_identity' => 'fake-release:2', 'repository_identity' => 'fake:repository', 'repository_locator' => 'owner/fake-release', 'tag' => 'v2.0.0', 'target_type' => $targetType, 'version' => '2.0.0' ) );
	}

	/** @return array<string,mixed> */
	private function claim( BindingState $state ): array {
		return array( 'binding_generation' => $state->bindingGeneration(), 'binding_hash' => $state->binding()->bindingHash(), 'lease_deadline' => $state->leaseDeadline(), 'owner_token' => $state->ownerToken() );
	}

	private function remove( string $path ): void {
		if ( is_link( $path ) || is_file( $path ) ) {
			@unlink( $path );
			return;
		}
		if ( ! is_dir( $path ) ) {
			return;
		}
		foreach ( scandir( $path ) ?: array() as $entry ) {
			if ( '.' !== $entry && '..' !== $entry ) {
				$this->remove( $path . DIRECTORY_SEPARATOR . $entry );
			}
		}
		@rmdir( $path );
	}
}
}
