<?php

declare(strict_types=1);

namespace RAN\WPReleaseUpdater\V1\Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class NeutralKernelBoundaryTest extends TestCase {

	public function test_every_production_file_excludes_provider_protocol_and_identity_assumptions(): void {
		$root  = dirname( __DIR__, 2 );
		$files = glob( $root . '/src/{Archive,Contract,Runtime,WordPress}/*.php', GLOB_BRACE );
		$files = $files ? $files : array();
		self::assertNotEmpty( $files );
		foreach ( $files as $file ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read exact local bytes for repository architecture assertions without requiring WordPress filesystem initialization.
			$source = file_get_contents( $file );
			self::assertIsString( $source, $file );
			self::assertDoesNotMatchRegularExpression(
				'/api\.github|github\.com|bitbucket|gitlab|downloads|authorization|bearer|private-token|job-token|wp_remote_|curl_|Requests::|sha-?1/i',
				$source,
				$file
			);
			self::assertDoesNotMatchRegularExpression( "/['\"](?:github|bitbucket|gitlab)['\"]/i", $source, $file );
		}
	}

	public function test_neutral_kernel_contains_no_provider_protocol_or_word_press_transport(): void {
		$root           = dirname( __DIR__, 2 );
		$contract_files = glob( $root . '/src/Contract/*.php' );
		$runtime_files  = glob( $root . '/src/Runtime/*.php' );
		$files          = array_merge(
			$contract_files ? $contract_files : array(),
			$runtime_files ? $runtime_files : array()
		);

		self::assertNotEmpty( $files );
		foreach ( $files as $file ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read exact local bytes for repository architecture assertions without requiring WordPress filesystem initialization.
			$source = file_get_contents( $file );
			self::assertIsString( $source, $file );
			self::assertDoesNotMatchRegularExpression(
				'/api\.github|bitbucket|gitlab|downloads|authorization|bearer|private-token|job-token|wp_remote_|curl_|Requests::|sha-?1/i',
				$source,
				$file
			);
			self::assertDoesNotMatchRegularExpression( "/['\"]github['\"]/i", $source, $file );
			self::assertDoesNotMatchRegularExpression(
				'/\b(?:add|do)_action\s*\(|\b(?:add|apply)_filter\s*\(/',
				$source,
				$file
			);
		}
	}

	public function test_runtime_has_no_provider_catalogue_or_composition_activation(): void {
		$root = dirname( __DIR__, 2 );
		self::assertFileDoesNotExist( $root . '/runtime-catalogue.json' );
		self::assertFileDoesNotExist( $root . '/src/Runtime/Composition/Github.php' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read exact local bytes for repository architecture assertions without requiring WordPress filesystem initialization.
		$broker = (string) file_get_contents( $root . '/src/Runtime/RequestBroker.php' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read exact local bytes for repository architecture assertions without requiring WordPress filesystem initialization.
		$runtime = (string) file_get_contents( $root . '/runtime.php' );
		self::assertStringContainsString( 'register_target', $broker );
		self::assertStringContainsString( 'public function boot', $runtime );
		self::assertStringNotContainsString( 'bitbucket', $runtime );
		self::assertStringNotContainsString( 'gitlab', $runtime );
	}

	public function test_runtime_catalog_only_dispatches_to_the_sealed_git_hub_adapter(): void {
		$root = dirname( __DIR__, 2 );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read exact local bytes for repository architecture assertions without requiring WordPress filesystem initialization.
		$runtime = (string) file_get_contents( $root . '/runtime.php' );
		$catalog = strstr( $runtime, '/* The sealed catalog is deliberately local to this selected runtime. */' );

		self::assertIsString( $catalog );
		self::assertStringContainsString( "'github'", $catalog );
		self::assertStringContainsString( 'GitHubReleaseAdapter::', $catalog );
		self::assertStringNotContainsString( 'github.com', $catalog );
		self::assertDoesNotMatchRegularExpression(
			'/repository_(?:locator|identity).*preg_match|preg_match.*repository_(?:locator|identity)/s',
			$catalog
		);
		self::assertStringNotContainsString( 'new GitHubCredentialResolver', $catalog );
		self::assertStringNotContainsString( 'BindingRecord::create', $catalog );
		self::assertStringNotContainsString( "'archive_root'", $catalog );
		self::assertStringNotContainsString( "'configuration_update_uri'", $catalog );
	}

	public function test_selected_runtime_entrypoint_owns_every_lifecycle_class(): void {
		$root = dirname( __DIR__, 2 );
		require $root . '/runtime.php';
		foreach ( array(
			'RAN\\WPReleaseUpdater\\V1\\Contract\\CanonicalUpdateUri',
			'RAN\\WPReleaseUpdater\\V1\\Contract\\IdentityDescriptor',
			'RAN\\WPReleaseUpdater\\V1\\Contract\\ReleaseVersion',
			'RAN\\WPReleaseUpdater\\V1\\Contract\\BindingRecord',
			'RAN\\WPReleaseUpdater\\V1\\Contract\\ReleaseAdapter',
			'RAN\\WPReleaseUpdater\\V1\\Archive\\ValidatedPackage',
			'RAN\\WPReleaseUpdater\\V1\\Archive\\TemporaryArtifact',
			'RAN\\WPReleaseUpdater\\V1\\Archive\\PackageIdentityValidator',
			'RAN\\WPReleaseUpdater\\V1\\WordPress\\BindingFenceCoordinator',
			'RAN\\WPReleaseUpdater\\V1\\Contract\\AcquisitionReceipt',
			'RAN\\WPReleaseUpdater\\V1\\WordPress\\NativePackageUpdater',
			'RAN\\WPReleaseUpdater\\V1\\Provider\\GitHub\\GitHubCredentialResolver',
			'RAN\\WPReleaseUpdater\\V1\\Provider\\GitHub\\ProspectiveReleaseInspection',
			'RAN\\WPReleaseUpdater\\V1\\Provider\\GitHub\\ProspectiveReleaseArtifact',
			'RAN\\WPReleaseUpdater\\V1\\Provider\\GitHub\\GitHubReleaseService',
			'RAN\\WPReleaseUpdater\\V1\\Provider\\GitHub\\GitHubReleaseAdapter',
		) as $class ) {
			$levels     = str_starts_with( $class, 'RAN\\WPReleaseUpdater\\V1\\Provider\\GitHub\\' ) ? 4 : 3;
			$class_file = ( new \ReflectionClass( $class ) )->getFileName();
			self::assertIsString( $class_file );
			self::assertSame( $root, dirname( $class_file, $levels ), $class );
		}
	}

	public function test_git_hub_protocol_is_confined_to_the_selected_provider_directory(): void {
		$root     = dirname( __DIR__, 2 );
		$provider = $root . '/src/Provider/GitHub';
		self::assertFileExists( $provider . '/GitHubCredentialResolver.php' );
		self::assertFileExists( $provider . '/GitHubReleaseService.php' );
		self::assertFileExists( $provider . '/GitHubReleaseAdapter.php' );
		self::assertFileExists( $provider . '/ProspectiveReleaseInspection.php' );
		self::assertFileExists( $provider . '/ProspectiveReleaseArtifact.php' );
		self::assertFileDoesNotExist( $provider . '/GitHubTemporaryArtifact.php' );
		self::assertFileExists( $root . '/src/Archive/TemporaryArtifact.php' );
		$fixture_entries = glob( $root . '/src/{Archive,Contract,Runtime,WordPress}/*.php', GLOB_BRACE );
		foreach ( $fixture_entries ? $fixture_entries : array() as $file ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read exact local bytes for repository architecture assertions without requiring WordPress filesystem initialization.
			self::assertStringNotContainsString( 'GitHub', (string) file_get_contents( $file ), $file );
		}
	}

	public function test_native_operation_exceptions_do_not_hide_unrelated_calls(): void {
		foreach ( $this->native_exception_paths() as $path ) {
			$root = dirname( __DIR__, 2 );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read exact local bytes for repository architecture assertions without requiring WordPress filesystem initialization.
			$source = is_file( $root . '/' . $path ) ? (string) file_get_contents( $root . '/' . $path ) : "<?php\n";
			self::assertSame( array(), $this->native_diagnostics( $path, $source ), $path );
			$probe       = $source . "\nfile_get_contents( '/native-operation-probe' );\nexec( 'fixture-probe' );\nproc_open( array( 'fixture-probe' ), array(), \$pipes );\nshell_exec( 'fixture-probe' );\nputenv( 'FIXTURE_PROBE=1' );\n";
			$diagnostics = $this->native_diagnostics( $path, $probe );
			self::assertContains( 'WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents', $diagnostics, $path );
			if ( str_starts_with( $path, 'tests/' ) ) {
				foreach ( array( 'system_calls_exec', 'system_calls_proc_open', 'system_calls_shell_exec', 'runtime_configuration_putenv' ) as $code ) {
					self::assertContains( 'WordPress.PHP.DiscouragedPHPFunctions.' . $code, $diagnostics, $path );
				}
			}
		}
	}

	public function test_native_operation_exceptions_do_not_hide_unrelated_silencing(): void {
		foreach ( $this->native_exception_paths() as $path ) {
			$root = dirname( __DIR__, 2 );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read exact local bytes for repository architecture assertions without requiring WordPress filesystem initialization.
			$source = is_file( $root . '/' . $path ) ? (string) file_get_contents( $root . '/' . $path ) : "<?php\n";
			self::assertContains( 'WordPress.PHP.NoSilencedErrors.Discouraged', $this->native_diagnostics( $path, $source . "\n@is_file( '/native-operation-probe' );\n" ), $path );
		}
	}

	/** @return list<string> */
	private function native_exception_paths(): array {
		$paths = array(
			'src/Archive/PackageIdentityValidator.php',
			'src/Archive/TemporaryArtifact.php',
			'src/Provider/GitHub/GitHubArtifactStore.php',
			'src/WordPress/InstalledPackageResolver.php',
			'src/WordPress/OwnedArchiveStore.php',
			'src/WordPress/StagedPackageManifest.php',
			'src/WordPress/NativePackageUpdater.php',
			'scripts/lint-php.php',
			'scripts/sync-updater-support.php',
			'src/Archive/FutureNativeProbe.php',
			'scripts/future-native-probe.php',
			'tests/FutureNativeProbe.php',
			'tests/Integration/FutureNativeProbe.php',
			'tests/FutureRoot/Nested/FutureNativeProbe.php',
			'tests/FutureRoot/bootstrap.php',
		);
		$root  = dirname( __DIR__, 2 );
		foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . '/tests', \FilesystemIterator::SKIP_DOTS ) ) as $file ) {
			if ( $file->isFile() && 'php' === $file->getExtension() ) {
				$paths[] = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $root ) + 1 ) );
			}
		}
		return $paths;
	}

	/** @return list<string> */
	private function native_diagnostics( string $path, string $source ): array {
		$root = dirname( __DIR__, 2 );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Run the locked checker with an argument vector and stdin source; capture its diagnostics without executing the probe.
		$process = proc_open(
			array( PHP_BINARY, $root . '/vendor/bin/phpcs', '--standard=' . $root . '/.phpcs.xml', '--report=json', '-q', '--no-colors', '--stdin-path=' . $root . '/' . $path, '-' ),
			array(
				0 => array( 'pipe', 'r' ),
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes,
			$root
		);
		self::assertIsResource( $process );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Write fixture bytes to the native stream while preserving its existing partial-write or subprocess protocol.
		fwrite( $pipes[0], $source );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the native stream owned by this fixture; WordPress filesystem abstractions do not own process or file handles.
		fclose( $pipes[0] );
		$output = stream_get_contents( $pipes[1] );
		$error  = stream_get_contents( $pipes[2] );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the native stream owned by this fixture; WordPress filesystem abstractions do not own process or file handles.
		fclose( $pipes[1] );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the native stream owned by this fixture; WordPress filesystem abstractions do not own process or file handles.
		fclose( $pipes[2] );
		$status = proc_close( $process );
		self::assertContains( $status, array( 0, 1, 2, 3 ), $error );
		$report = json_decode( $output, true, 512, JSON_THROW_ON_ERROR );
		self::assertArrayHasKey( 'files', $report );
		$messages = array_merge( ...array_column( array_values( $report['files'] ), 'messages' ) );
		return array_values( array_filter( array_column( $messages, 'source' ), static fn( string $code ): bool => str_starts_with( $code, 'WordPress.WP.AlternativeFunctions.' ) || 'WordPress.PHP.NoSilencedErrors.Discouraged' === $code || str_starts_with( $code, 'WordPress.PHP.DiscouragedPHPFunctions.system_calls_' ) || 'WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv' === $code ) );
	}
}
