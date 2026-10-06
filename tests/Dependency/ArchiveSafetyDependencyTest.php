<?php

declare(strict_types=1);

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Existing Composer development namespace; this allowance does not apply to production declarations.
namespace Tests\Dependency;

use PHPUnit\Framework\TestCase;
use RAN\WPReleaseUpdater\V1\Dependency\ArchiveSafety;

final class ArchiveSafetyDependencyTest extends TestCase {

	public function test_generated_scoped_dependency_matches_the_canonical_fixture_corpus(): void {
		$fixture = require dirname( __DIR__, 2 ) . '/vendor/ran/updater-support/tests/fixtures/archive-safety.php';
		foreach ( $fixture['paths'] as $name => $case ) {
			[$input, $expected] = $case;
			self::assertSame( $expected, ArchiveSafety::normalize_path( $input ), $name );
		}
		foreach ( $fixture['metadata'] as $name => $case ) {
			[$origin, $attributes, $directory, $expected] = $case;
			self::assertSame( $expected, ArchiveSafety::entry_type_failure( $origin, $attributes, $directory ), $name );
		}
		foreach ( $fixture['collisions'] as $name => $case ) {
			[$entries, $expected] = $case;
			self::assertSame( $expected, ArchiveSafety::collision_failure( $entries ), $name );
		}
	}

	public function test_owned_naming_scope_covers_current_and_future_cohort_files(): void {
		$method_code   = 'RANOwnedMethods.NamingConventions.ValidMethodName.NotSnakeCase';
		$variable_code = 'WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase';
		$source        = "<?php\nnamespace Tests;\nclass NamingProbe extends \\PHPUnit\\Framework\\TestCase {\npublic function owned_method(): int { \$owned_value = 1; return \$owned_value; }\n}\n";
		foreach ( array( 'Archive/ArchiveScanResultTest.php', 'Contract/ReleaseVersionTest.php', 'Dependency/ArchiveSafetyDependencyTest.php', 'Provider/GitHubReleaseAdapterTest.php', 'Runtime/RequestBrokerTest.php', 'WordPress/NativePackageUpdaterTest.php', 'Support/FakeOptionDatabase.php', 'Integration/wordpress-integration.php', 'Integration/wordpress-integration/distribution.php', 'Archive/FutureTest.php', 'Contract/FutureTest.php', 'Dependency/FutureTest.php', 'Provider/FutureTest.php', 'Runtime/FutureTest.php', 'WordPress/FutureTest.php', 'Support/FutureTest.php', 'Integration/FutureTest.php', 'Integration/wordpress-integration/FutureTest.php', 'Performance/FutureTest.php', 'Performance/KernelPerformanceTest.php', 'Architecture/NeutralKernelBoundaryTest.php', 'Architecture/FutureTest.php', 'Documentation/ReadmeExamplesTest.php', 'Documentation/FutureTest.php', 'bootstrap.php', 'FutureTest.php', 'FutureRoot/NestedTest.php' ) as $path ) {
			self::assertSame( array(), $this->profile_diagnostics( $path, $source ) );
			self::assertSame( true, in_array( $method_code, $this->profile_diagnostics( $path, str_replace( 'owned_method', 'ownedMethod', $source ) ), true ), $path );
			self::assertSame( true, in_array( $variable_code, $this->profile_diagnostics( $path, str_replace( 'owned_value', 'ownedValue', $source ) ), true ), $path );
		}
		foreach ( array( 'Support/MysqliOptionDatabase.php', 'Support/FutureTest.php' ) as $path ) {
			self::assertContains( 'WordPress.PHP.YodaConditions.NotYoda', $this->profile_diagnostics( $path, str_replace( 'return $owned_value;', 'return $owned_value === 1;', $source ) ), $path );
		}
		foreach ( array( 'Integration/release-source-consumer-proof.php', 'Performance/native-discovery-measure.php', 'FutureRoot/NestedTest.php' ) as $path ) {
			self::assertContains( 'WordPress.PHP.YodaConditions.NotYoda', $this->profile_diagnostics( $path, str_replace( 'return $owned_value;', 'return $owned_value === 1;', $source ) ), $path );
			self::assertContains( 'Generic.CodeAnalysis.UnusedFunctionParameter.Found', $this->profile_diagnostics( $path, '<?php function profile_probe( $unused ) { return 1; }' ), $path );
		}
		foreach ( array( 'bootstrap.php', 'Documentation/ReadmeExamplesTest.php', 'Integration/wordpress-integration/distribution.php', 'FutureRoot/NestedTest.php' ) as $path ) {
			self::assertContains( 'Universal.NamingConventions.NoReservedKeywordParameterNames.classFound', $this->profile_diagnostics( $path, '<?php function profile_probe( $class ) { return $class; }' ), $path );
		}
	}


	public function test_fixture_profile_checks_current_and_future_paths(): void {
		$cases = array(
			'function ownCamelCase() {}'       => array( 'WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid', 'WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound' ),
			'$unprefixed = 1; class ForeignProbe {} const FOREIGN_PROBE = 1;' => array( 'WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound', 'WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound', 'WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound' ),
			'namespace UnownedFixture;'        => array( 'WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound' ),
			'$value = $input ?: 1;'            => array( 'Universal.Operators.DisallowShortTernary.Found' ),
			'$GLOBALS["wp_version"] = "6.8";'  => array( 'WordPress.WP.GlobalVariablesOverride.Prohibited' ),
			'var_export(array()); base64_encode("x"); base64_decode("eA=="); serialize(array()); unserialize("a:0:{}");' => array( 'WordPress.PHP.DevelopmentFunctions.error_log_var_export', 'WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode', 'WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode', 'WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize', 'WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize' ),
			'mysqli_init(); mysqli_real_connect($db); mysqli_report(MYSQLI_REPORT_STRICT);' => array( 'WordPress.DB.RestrictedFunctions.mysql_mysqli_init', 'WordPress.DB.RestrictedFunctions.mysql_mysqli_real_connect', 'WordPress.DB.RestrictedFunctions.mysql_mysqli_report' ),
			'echo $unescaped; eval($fixture);' => array( 'WordPress.Security.EscapeOutput.OutputNotEscaped', 'Squiz.PHP.Eval.Discouraged' ),
			'namespace { class FixtureOne {} function fixture_helper() {} } namespace Tests\Other { class FixtureTwo {} }' => array( 'Universal.Namespaces.DisallowCurlyBraceSyntax.Forbidden', 'Universal.Namespaces.DisallowDeclarationWithoutName.Forbidden', 'Universal.Namespaces.OneDeclarationPerFile.MultipleFound', 'Universal.Files.SeparateFunctionsFromOO.Mixed', 'Generic.Files.OneObjectStructurePerFile.MultipleFound' ),
			'class DuplicateFixture {} class DuplicateFixture {}' => array( 'Generic.Classes.DuplicateClassName.Found' ),
			'try { fixture_probe(); } catch (Exception $failure) {} for ($i=0; $i<count($items); ++$i) {}' => array( 'Generic.CodeAnalysis.EmptyStatement.DetectedCatch', 'Generic.CodeAnalysis.ForLoopWithTestFunctionCall.NotAllowed' ),
			'$value = "one" . "two"; $token = "id" . ++$sequence;' => array( 'Generic.Strings.UnnecessaryStringConcat.Found', 'Squiz.Operators.IncrementDecrementUsage.NoBrackets' ),
			'apply_filters("update_plugins_github.com", false); apply_filters($hook, false);' => array( 'WordPress.NamingConventions.ValidHookName.UseUnderscores', 'WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound', 'WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound' ),
		);
		foreach ( array( 'Integration/wordpress-integration.php', 'FutureRoot/Nested/FixtureProbe.php' ) as $path ) {
			foreach ( $cases as $source => $codes ) {
				$diagnostics = $this->profile_diagnostics( $path, '<?php ' . $source, true );
				foreach ( $codes as $code ) {
					self::assertContains( $code, $diagnostics, $path . ': ' . $code );
				}
			}
		}
		foreach ( array( 'Integration/wordpress-integration.php', 'FutureRoot/FixtureProbe.php' ) as $path ) {
			$diagnostics = $this->profile_diagnostics( $path, '<?php namespace RAN\\WPReleaseUpdater\\V1\\Probe; function ran_wp_release_updater_test_helper() { return 1; }', true );
			self::assertNotContains( 'WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound', $diagnostics );
			self::assertNotContains( 'WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound', $diagnostics );
		}
		foreach ( array( '../src/FutureProbe.php', '../runtime.php', 'FutureRoot/FixtureProbe.php' ) as $path ) {
			$diagnostics = array_merge( $this->profile_diagnostics( $path, '<?php namespace Tests;', true ), $this->profile_diagnostics( $path, '<?php function Tests_probe() {} class Tests_Probe {} const Tests_PROBE = 1;', true ) );
			foreach ( array( 'NonPrefixedNamespaceFound', 'NonPrefixedFunctionFound', 'NonPrefixedClassFound', 'NonPrefixedConstantFound' ) as $suffix ) {
				self::assertContains( 'WordPress.NamingConventions.PrefixAllGlobals.' . $suffix, $diagnostics, $path );
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read the maintained harness annotations for a checker-only control; never execute the mutated fixture.
		$source = file_get_contents( dirname( __DIR__ ) . '/Integration/wordpress-integration.php' );
		self::assertIsString( $source );
		$enable = '// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound';
		self::assertStringContainsString( $enable, $source );
		$source      = str_replace( $enable, '$unprefixed_probe = 1; function ownedProbe() {} class UnprefixedProbe {} const UNPREFIXED_PROBE = 1;' . "\n" . $enable, $source );
		$diagnostics = $this->profile_diagnostics( 'Integration/wordpress-integration.php', $source, true );
		self::assertNotContains( 'WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound', $diagnostics );
		foreach ( array( 'NonPrefixedFunctionFound', 'NonPrefixedClassFound', 'NonPrefixedConstantFound' ) as $suffix ) {
			self::assertContains( 'WordPress.NamingConventions.PrefixAllGlobals.' . $suffix, $diagnostics );
		}
		self::assertContains( 'WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid', $diagnostics );
	}

	/** @return list<string> */
	private function profile_diagnostics( string $path, string $source, bool $all = false ): array {
		$root = dirname( __DIR__, 2 );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Run the locked checker with an argument vector and stdin source; capture its diagnostics without executing the probe.
		$process = proc_open(
			array( PHP_BINARY, $root . '/vendor/bin/phpcs', '--standard=' . $root . '/.phpcs.xml', '--report=json', '-q', '--no-colors', '--stdin-path=' . $root . '/tests/' . $path, '-' ),
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
		if ( $all ) {
			return array_column( $messages, 'source' );
		}
		return array_values( array_filter( array_column( $messages, 'source' ), static fn( string $code ): bool => str_starts_with( $code, 'RANOwnedMethods.' ) || str_starts_with( $code, 'WordPress.NamingConventions.ValidVariableName.' ) || 'WordPress.PHP.YodaConditions.NotYoda' === $code || str_starts_with( $code, 'Generic.CodeAnalysis.UnusedFunctionParameter.' ) || str_starts_with( $code, 'Universal.NamingConventions.NoReservedKeywordParameterNames.' ) ) );
	}
}
