<?php

declare(strict_types=1);

namespace RAN\WPReleaseUpdater\V1\Tests\Architecture;

use PHPStan\DependencyInjection\ContainerFactory;
use PHPStan\File\FileExcluder;
use PHPStan\File\FileHelper;
use PHPUnit\Framework\TestCase;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class ProductionAnalysisCoverageTest extends TestCase {
	/** Root-relative development/dependency/state boundaries; never production subdirectory names. */
	private const NON_PRODUCTION_ROOTS = array( 'tests', 'scripts', 'vendor', 'node_modules', '.git', '.phpunit.cache', '.workspaces', 'coverage' );

	private string $fixture;

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit lifecycle override.
	protected function setUp(): void {
		$this->fixture = sys_get_temp_dir() . '/ran-release-analysis-' . bin2hex( random_bytes( 8 ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Private disposable analyzer fixture root.
		mkdir( $this->fixture, 0700 );
	}

	// phpcs:ignore RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase -- PHPUnit lifecycle override.
	protected function tearDown(): void {
		$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $this->fixture, RecursiveDirectoryIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $files as $file ) {
			if ( $file->isDir() ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove only directories inside this test's private fixture root, children first.
				rmdir( $file->getPathname() );
			} else {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only files created inside this test's private fixture root.
				unlink( $file->getPathname() );
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove the now-empty private fixture root.
		rmdir( $this->fixture );
	}

	public function test_every_maintained_production_file_is_directly_analyzed(): void {
		$root = dirname( __DIR__, 2 );
		self::assertSame( $this->maintained_files( $root ), $this->analyzed_files( $root, $root . '/phpstan.neon' ) );
	}

	public function test_new_root_nested_and_relocated_sources_are_covered(): void {
		$this->write_fixture( 'bootstrap.php', '<?php' );
		$this->write_fixture( 'src/Original.php', '<?php' );
		$this->write_fixture( 'new-entrypoint.php', '<?php' );
		$this->write_fixture( 'new-production/Deep/Split.php', '<?php' );
		$this->write_fixture( 'src/NewArea/Nested.php', '<?php' );
		$this->write_fixture( 'src/tests/Production.php', '<?php' );
		$this->write_fixture( 'src/Dependency/ArchiveSafety.php', '<?php' );
		foreach ( self::NON_PRODUCTION_ROOTS as $directory ) {
			$this->write_fixture( $directory . '/Excluded.php', '<?php' );
		}
		$config = $this->fixture_config();
		self::assertSame( $this->maintained_files( $this->fixture ), $this->analyzed_files( $this->fixture, $config ) );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Simulate a production split/relocation inside the private fixture without editing real sources.
		rename( $this->fixture . '/src/Original.php', $this->fixture . '/new-production/Deep/Relocated.php' );
		self::assertSame( $this->maintained_files( $this->fixture ), $this->analyzed_files( $this->fixture, $config ) );
		self::assertContains( 'new-production/Deep/Relocated.php', $this->analyzed_files( $this->fixture, $config ) );
	}

	public function test_a_file_list_cannot_silently_omit_new_production(): void {
		$this->write_fixture( 'bootstrap.php', '<?php' );
		$this->write_fixture( 'src/NewArea/Split.php', '<?php' );
		$config = $this->fixture_config( false );
		self::assertSame( array( 'src/NewArea/Split.php' ), array_values( array_diff( $this->maintained_files( $this->fixture ), $this->analyzed_files( $this->fixture, $config ) ) ) );
	}

	public function test_extensionless_php_entrypoint_requires_analysis_coverage(): void {
		$this->write_fixture( 'src/Existing.php', '<?php' );
		$this->write_fixture( 'bin/ran-entrypoint', "#!/usr/bin/env php\n<?php\necho 'fixture';\n" );
		$this->write_fixture( 'bin/uppercase-entrypoint', '<?PHP echo 1;' );
		$this->write_fixture( 'bin/echo-entrypoint', '<?= 1;' );
		$this->write_fixture( 'src/fixture.inc', '<?php echo 1;' );
		$this->write_fixture( 'LICENSE', 'Ordinary non-PHP extensionless documentation.' );
		$config = $this->fixture_config();
		self::assertSame( array( 'bin/echo-entrypoint', 'bin/ran-entrypoint', 'bin/uppercase-entrypoint', 'src/fixture.inc' ), array_values( array_diff( $this->maintained_files( $this->fixture ), $this->analyzed_files( $this->fixture, $config ) ) ) );
	}

	public function test_effective_exclusions_cannot_hide_maintained_production(): void {
		$this->write_fixture( 'bootstrap.php', '<?php' );
		$this->write_fixture( 'src/Dependency/ArchiveSafety.php', '<?php' );
		$config = $this->fixture_config( true, "\t\t\t- src/Dependency/ArchiveSafety.php\n" );
		self::assertSame( array( 'src/Dependency/ArchiveSafety.php' ), array_values( array_diff( $this->maintained_files( $this->fixture ), $this->analyzed_files( $this->fixture, $config ) ) ) );
	}

	public function test_uppercase_php_cannot_escape_independent_discovery(): void {
		$this->write_fixture( 'new-production/Split.PHP', '<?php' );
		$config = $this->fixture_config();
		self::assertSame( array( 'new-production/Split.PHP' ), $this->maintained_files( $this->fixture ) );
		self::assertSame( array( 'new-production/Split.PHP' ), array_values( array_diff( $this->maintained_files( $this->fixture ), $this->analyzed_files( $this->fixture, $config ) ) ) );
	}

	public function test_a_production_stub_is_not_counted_as_direct_analysis(): void {
		$this->write_fixture( 'src/Production.php', '<?php' );
		$config = $this->fixture_config( true, "\tstubFiles:\n\t\t- src/Production.php\n" );
		self::assertSame( array( 'src/Production.php' ), array_values( array_diff( $this->maintained_files( $this->fixture ), $this->analyzed_files( $this->fixture, $config ) ) ) );
	}

	public function test_development_constants_cannot_change_production_inference(): void {
		$this->write_fixture( 'src/Production.php', '<?php function ran_release_coverage_probe(): int { return RAN_RELEASE_COVERAGE_FIXTURE; }' );
		$this->write_fixture( 'tests/Fixture.php', '<?php const RAN_RELEASE_COVERAGE_FIXTURE = 42;' );
		$config   = $this->fixture_config();
		$isolated = $this->analyze_fixture( $config );
		self::assertSame( 1, $isolated['exit'] );
		self::assertStringContainsString( 'RAN_RELEASE_COVERAGE_FIXTURE', $isolated['output'] );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read the isolated configuration to reproduce the analysis-only exclusion regression.
		$contents = file_get_contents( $config );
		self::assertIsString( $contents );
		$this->write_fixture( 'analysis-only.neon', str_replace( 'analyseAndScan:', 'analyse:', $contents, $replacements ) );
		self::assertSame( 1, $replacements );
		$leaked = $this->analyze_fixture( $this->fixture . '/analysis-only.neon' );
		self::assertSame( 0, $leaked['exit'], $leaked['output'] );
	}

	/** @return array{exit: int, output: string} */
	private function analyze_fixture( string $config ): array {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Exercise the locked analyzer on disposable source bytes to prove reflection isolation, without loading fixture code in this process.
		$process = proc_open(
			array( PHP_BINARY, dirname( __DIR__, 2 ) . '/vendor/bin/phpstan', 'analyse', '--configuration=' . $config, '--no-progress', '--error-format=json' ),
			array(
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes,
			$this->fixture
		);
		self::assertIsResource( $process );
		$output = stream_get_contents( $pipes[1] ) . stream_get_contents( $pipes[2] );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the analyzer subprocess output pipe owned by this test.
		fclose( $pipes[1] );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the analyzer subprocess error pipe owned by this test.
		fclose( $pipes[2] );
		return array(
			'exit'   => proc_close( $process ),
			'output' => $output,
		);
	}

	/** @return list<string> */
	private function maintained_files( string $root ): array {
		$files    = array();
		$iterator = new RecursiveIteratorIterator(
			new RecursiveCallbackFilterIterator(
				new RecursiveDirectoryIterator( $root, RecursiveDirectoryIterator::SKIP_DOTS ),
				static function ( SplFileInfo $file ) use ( $root ): bool {
					$relative = substr( $file->getPathname(), strlen( $root ) + 1 );
					return ! in_array( explode( DIRECTORY_SEPARATOR, $relative )[0], self::NON_PRODUCTION_ROOTS, true );
				}
			)
		);
		foreach ( $iterator as $file ) {
			if ( $file->isFile() && $this->is_php_source( $file ) ) {
				$files[] = str_replace( DIRECTORY_SEPARATOR, '/', substr( $file->getPathname(), strlen( $root ) + 1 ) );
			}
		}
		sort( $files );
		return $files;
	}

	private function is_php_source( SplFileInfo $file ): bool {
		if ( 'php' === strtolower( $file->getExtension() ) ) {
			return true;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read only a bounded header to account for extensionless PHP/shebang entrypoints without executing them.
		$header = file_get_contents( $file->getPathname(), false, null, 0, 256 );
		self::assertIsString( $header );
		return 1 === preg_match( '/\\A(?:#![^\\r\\n]*\\r?\\n)?[ \\t\\r\\n]*<\\?(?:php(?:\\s|$)|=)/i', $header );
	}

	/** @return list<string> */
	private function analyzed_files( string $root, string $config ): array {
		$container = ( new ContainerFactory( $root ) )->create( $this->fixture . '/.phpunit.cache/container', array( $config ), array() );
		// Use the same effective finder, extensions and exclusions as PHPStan's command.
		$files = $container->getService( 'fileFinderAnalyse' )->findFiles( $container->getParameter( 'paths' ) )->getFiles();
		// PHPStan's command removes configured stubs after discovery; their declarations are not directly analyzed bodies.
		$stub_excluder = new FileExcluder( new FileHelper( $root ), $container->getParameter( 'stubFiles' ) );
		$files         = array_values( array_filter( $files, static fn ( string $file ): bool => ! $stub_excluder->isExcludedFromAnalysing( $file ) ) );
		$files         = array_map( static fn ( string $file ): string => str_replace( DIRECTORY_SEPARATOR, '/', substr( $file, strlen( $root ) + 1 ) ), $files );
		sort( $files );
		return $files;
	}

	private function fixture_config( bool $inclusive = true, string $additional_exclusion = '' ): string {
		$root = dirname( __DIR__, 2 );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read the real analyzer configuration for isolated positive/negative discovery controls.
		$config = file_get_contents( $root . '/phpstan.neon' );
		self::assertIsString( $config );
		$config = str_replace( 'vendor/szepeviktor/phpstan-wordpress/extension.neon', str_replace( '\\', '/', $root ) . '/vendor/szepeviktor/phpstan-wordpress/extension.neon', $config );
		if ( ! $inclusive ) {
			$config = str_replace( "\t\t- .\n", "\t\t- bootstrap.php\n", $config, $replacements );
			self::assertSame( 1, $replacements );
		}
		$this->write_fixture( 'phpstan.neon', $config . $additional_exclusion );
		return $this->fixture . '/phpstan.neon';
	}

	private function write_fixture( string $path, string $contents ): void {
		$path = $this->fixture . '/' . $path;
		if ( ! is_dir( dirname( $path ) ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create only this isolated discovery fixture's nested directories.
			mkdir( dirname( $path ), 0700, true );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Write controlled fixture bytes without changing the real analyzed tree.
		file_put_contents( $path, $contents );
	}
}
