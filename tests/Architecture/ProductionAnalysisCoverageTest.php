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

	public function test_maintained_php_cannot_override_sniff_properties_inline(): void {
		$root     = dirname( __DIR__, 2 );
		$excluded = array_values( array_diff( self::NON_PRODUCTION_ROOTS, array( 'tests', 'scripts' ) ) );
		foreach ( $this->maintained_files( $root, $excluded ) as $file ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Inspect maintained source comments without loading fixtures or changing runtime state.
			$source = file_get_contents( $root . '/' . $file );
			self::assertIsString( $source );
			self::assertFalse( $this->has_inline_property_override( $source ), $file );
		}
	}

	public function test_property_override_guard_checks_comment_forms_and_case(): void {
		foreach ( array( 'phpcs:set', '@phpcs:set', 'PHPCS:SET', '@codingStandardsChangeSetting', '@CODINGSTANDARDSCHANGESETTING' ) as $directive ) {
			foreach ( array( '// ', '# ', '/* ', '/** ' ) as $opening ) {
				$source = '<?php ' . $opening . $directive . ' WordPress.NamingConventions.PrefixAllGlobals prefixes rogue */';
				self::assertTrue( $this->has_inline_property_override( $source ), $source );
			}
			self::assertFalse( $this->has_inline_property_override( '<?php $fixture = "// ' . $directive . ' WordPress.NamingConventions.PrefixAllGlobals prefixes rogue";' ) );
		}
		self::assertFalse( $this->has_inline_property_override( '<?php // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Reviewed foreign identity.' ) );
	}

	private function has_inline_property_override( string $source ): bool {
		foreach ( token_get_all( $source ) as $token ) {
			if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) && 1 === preg_match( '/(?:@?phpcs:set|@codingStandardsChangeSetting)\\b/i', $token[1] ) ) {
				return true;
			}
		}
		return false;
	}

	public function test_every_maintained_test_is_selected_by_the_isolated_runner(): void {
		$root     = dirname( __DIR__, 2 );
		$expected = array_map( static fn ( string $file ): string => 'tests/' . $file, $this->maintained_files( $root . '/tests', array() ) );
		self::assertSame( $expected, $this->analyzed_files( $root, $root . '/phpstan-tests.neon', 5, array( $root . '/tests' ) ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Verify the canonical aggregate runs analysis, rather than only exposing the discovered file list.
		$manifest = file_get_contents( $root . '/composer.json' );
		self::assertIsString( $manifest );
		$composer = json_decode( $manifest, true, 512, JSON_THROW_ON_ERROR );
		self::assertContains( '@analyze', $composer['scripts']['check'] );
		self::assertContains( 'php scripts/analyze-tests.php', $composer['scripts']['analyze'] );

		$result = $this->run_command( array( PHP_BINARY, $root . '/scripts/analyze-tests.php', '--list' ), $root );
		self::assertSame( 0, $result['exit'], $result['output'] );
		$selected = json_decode( $result['output'], true, 512, JSON_THROW_ON_ERROR );
		self::assertSame( $expected, array_map( static fn ( string $file ): string => str_replace( DIRECTORY_SEPARATOR, '/', substr( $file, strlen( $root ) + 1 ) ), $selected ) );
	}

	public function test_future_test_files_are_selected_and_analyzed_in_separate_symbol_worlds(): void {
		$root = dirname( __DIR__, 2 );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Exercise the actual test profile on isolated synthetic declarations without changing repository files.
		$config = file_get_contents( $root . '/phpstan-tests.neon' );
		self::assertIsString( $config );
		$config = str_replace( 'vendor/szepeviktor/phpstan-wordpress/extension.neon', str_replace( '\\', '/', $root ) . '/vendor/szepeviktor/phpstan-wordpress/extension.neon', $config );
		$this->write_fixture( 'src/Existing.php', '<?php' );
		$this->write_fixture( 'tests/NewRoot/First.php', '<?php function add_filter(string $hook, mixed $callback, int $priority, int $accepted): void {}' );
		$this->write_fixture( 'tests/NewRoot/Nested/Second.php', '<?php add_filter("ran_test", static fn(): bool => true);' );
		$this->write_fixture( 'tests.neon', $config );
		$expected = array( 'tests/NewRoot/First.php', 'tests/NewRoot/Nested/Second.php' );
		self::assertSame( $expected, $this->analyzed_files( $this->fixture, $this->fixture . '/tests.neon', 5, array( $this->fixture . '/tests' ) ) );
		foreach ( $expected as $file ) {
			$result = $this->analyze_fixture( $this->fixture . '/tests.neon', array( $this->fixture . '/' . $file ) );
			self::assertSame( 0, $result['exit'], $result['output'] );
		}

		$this->write_fixture( 'combined.neon', $config . "\tpaths:\n\t\t- tests\n" );
		$combined = '';
		foreach ( $expected as $file ) {
			$result    = $this->analyze_fixture( $this->fixture . '/combined.neon', array( $this->fixture . '/' . $file ) );
			$combined .= $result['output'];
		}
		self::assertStringContainsString( 'arguments.count', $combined );
		$this->write_fixture( 'tests/NewRoot/Nested/Second.php', '<?php function ran_isolated_probe(string $value): int { return $value; }' );
		$result = $this->analyze_fixture( $this->fixture . '/tests.neon', array( $this->fixture . '/tests/NewRoot/Nested/Second.php' ) );
		self::assertSame( 1, $result['exit'] );
		self::assertStringContainsString( 'return.type', $result['output'] );
		$this->write_fixture( 'excluded.neon', $config . "\texcludePaths:\n\t\tanalyseAndScan:\n\t\t\t- tests/NewRoot/Nested/*\n" );
		self::assertSame( array( 'tests/NewRoot/Nested/Second.php' ), array_values( array_diff( $expected, $this->analyzed_files( $this->fixture, $this->fixture . '/excluded.neon', 5, array( $this->fixture . '/tests' ) ) ) ) );
	}

	public function test_exact_negative_contract_annotations_do_not_hide_the_next_occurrence(): void {
		$cases = array(
			array( 'Contract/AcquisitionReceiptTest.php', 'argument.type', '<?php /** @param array<mixed> $d */ function ran_negative_probe(int $a, string $b, bool $c, array $d, object $e): void {}', 'ran_negative_probe(array(), array(), array(), 1, "wrong", 6);' ),
			array( 'Archive/PackageIdentityValidatorTest.php', 'expr.resultUnused', '<?php $object = new stdClass();', 'clone $object;' ),
			array( 'Integration/real-mysql-cas-proof.php', 'function.alreadyNarrowedType', '<?php', 'if (is_array(array())) { echo 1; }' ),
			array( 'Architecture/ProductionAnalysisCoverageTest.php', 'phpstanApi.constructor', '<?php', 'new \\PHPStan\\File\\FileExcluder(new \\PHPStan\\File\\FileHelper(__DIR__), array());' ),
		);
		foreach ( $cases as [$file, $identifier, $prefix, $statement] ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reuse the actual occurrence-local annotation so the negative control protects its current scope.
			$source = file_get_contents( dirname( __DIR__ ) . '/' . $file );
			self::assertIsString( $source );
			self::assertSame( 1, preg_match( '/\/\/ @phpstan-ignore ' . preg_quote( $identifier, '/' ) . '[^\r\n]*/', $source, $matches ) );
			$this->write_fixture( 'src/Probe.php', $prefix . "\n" . $statement . ' ' . $matches[0] . "\n" );
			$config = $this->fixture_config();
			$result = $this->analyze_fixture( $config );
			self::assertSame( 0, $result['exit'], $result['output'] );
			$this->write_fixture( 'src/Probe.php', $prefix . "\n" . $statement . ' ' . $matches[0] . "\n" . $statement . "\n" );
			$result = $this->analyze_fixture( $config );
			self::assertSame( 1, $result['exit'] );
			self::assertStringContainsString( $identifier, $result['output'] );
			self::assertStringNotContainsString( 'ignore.unmatchedIdentifier', $result['output'] );
		}
	}

	public function test_every_maintained_script_is_directly_analyzed(): void {
		$root     = dirname( __DIR__, 2 );
		$expected = array_map( static fn ( string $file ): string => 'scripts/' . $file, $this->maintained_files( $root . '/scripts', array() ) );
		self::assertSame( $expected, $this->analyzed_files( $root, $root . '/phpstan-tools.neon' ) );
	}

	public function test_new_and_split_scripts_are_covered_and_exclusions_are_detected(): void {
		$this->write_fixture( 'scripts/NewTool.php', '<?php' );
		$this->write_fixture( 'scripts/Nested/Split.php', '<?php' );
		$this->write_fixture( 'scripts/tests/Tool.php', '<?php' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read the real tool profile to exercise its effective discovery without editing repository sources.
		$config = file_get_contents( dirname( __DIR__, 2 ) . '/phpstan-tools.neon' );
		self::assertIsString( $config );
		$this->write_fixture( 'tools.neon', $config );
		$expected = array_map( static fn ( string $file ): string => 'scripts/' . $file, $this->maintained_files( $this->fixture . '/scripts', array() ) );
		self::assertSame( $expected, $this->analyzed_files( $this->fixture, $this->fixture . '/tools.neon' ) );
		$this->write_fixture( 'tools-excluded.neon', $config . "\texcludePaths:\n\t\tanalyseAndScan:\n\t\t\t- scripts/Nested/*\n" );
		self::assertSame( array( 'scripts/Nested/Split.php' ), array_values( array_diff( $expected, $this->analyzed_files( $this->fixture, $this->fixture . '/tools-excluded.neon' ) ) ) );
	}

	public function test_new_script_body_is_checked_by_the_tool_profile(): void {
		$this->write_fixture( 'scripts/NewTool.php', '<?php function ran_release_tool_probe(): int { return "invalid"; }' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Use the repository profile in an isolated fixture to prove body analysis at the configured level.
		$config = file_get_contents( dirname( __DIR__, 2 ) . '/phpstan-tools.neon' );
		self::assertIsString( $config );
		$this->write_fixture( 'tools.neon', $config );
		$result = $this->analyze_fixture( $this->fixture . '/tools.neon' );
		self::assertSame( 1, $result['exit'] );
		self::assertStringContainsString( 'return.type', $result['output'] );
		$this->write_fixture( 'scripts/NewTool.php', '<?php function ran_release_tool_probe(): int { return 1; }' );
		$result = $this->analyze_fixture( $this->fixture . '/tools.neon' );
		self::assertSame( 0, $result['exit'], $result['output'] );
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

	/**
	 * @param list<string> $paths Explicit per-file worlds, matching the test runner.
	 * @return array{exit: int, output: string}
	 */
	private function analyze_fixture( string $config, array $paths = array() ): array {
		return $this->run_command( array_merge( array( PHP_BINARY, dirname( __DIR__, 2 ) . '/vendor/bin/phpstan', 'analyse', '--configuration=' . $config, '--no-progress', '--error-format=json' ), $paths ), $this->fixture );
	}

	/**
	 * @param list<string> $command Locked tool invocation.
	 * @return array{exit: int, output: string}
	 */
	private function run_command( array $command, string $directory ): array {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Exercise the locked analyzer on disposable source bytes to prove reflection isolation, without loading fixture code in this process.
		$process = proc_open(
			$command,
			array(
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes,
			$directory
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

	/**
	 * @param list<string> $excluded_roots Root-relative non-maintained boundaries.
	 * @return list<string>
	 */
	private function maintained_files( string $root, array $excluded_roots = self::NON_PRODUCTION_ROOTS ): array {
		$files    = array();
		$iterator = new RecursiveIteratorIterator(
			new RecursiveCallbackFilterIterator(
				new RecursiveDirectoryIterator( $root, RecursiveDirectoryIterator::SKIP_DOTS ),
				static function ( SplFileInfo $file ) use ( $root, $excluded_roots ): bool {
					$relative = substr( $file->getPathname(), strlen( $root ) + 1 );
					return ! in_array( explode( DIRECTORY_SEPARATOR, $relative )[0], $excluded_roots, true );
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

	/**
	 * @param list<string> $paths Explicit selection for the isolated test runner.
	 * @return list<string>
	 */
	private function analyzed_files( string $root, string $config, int $level = 8, array $paths = array() ): array {
		$container = ( new ContainerFactory( $root ) )->create( $this->fixture . '/.phpunit.cache/container', array( $config ), $paths );
		self::assertSame( $level, $container->getParameter( 'level' ) );
		if ( 5 === $level ) {
			self::assertSame( array(), $container->getParameter( 'ignoreErrors' ) );
			self::assertTrue( $container->getParameter( 'reportUnmatchedIgnoredErrors' ) );
		}

		// Use the same effective finder, extensions and exclusions as PHPStan's command.
		$files = $container->getService( 'fileFinderAnalyse' )->findFiles( array() === $paths ? $container->getParameter( 'paths' ) : $paths )->getFiles();
		// PHPStan's command removes configured stubs after discovery; their declarations are not directly analyzed bodies.
		$stub_excluder = new FileExcluder( new FileHelper( $root ), $container->getParameter( 'stubFiles' ) ); // @phpstan-ignore phpstanApi.constructor, phpstanApi.constructor (Match the locked PHPStan CLI stub filter; effective-selection regression tests protect this internal API dependency.)
		$files         = array_values( array_filter( $files, static fn ( string $file ): bool => ! $stub_excluder->isExcludedFromAnalysing( $file ) ) ); // @phpstan-ignore phpstanApi.method (Match the locked PHPStan CLI stub filter; effective-selection regression tests protect this internal API dependency.)
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
