<?php

declare(strict_types=1);

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
		foreach ( array( 'Archive/ArchiveScanResultTest.php', 'Contract/ReleaseVersionTest.php', 'Dependency/ArchiveSafetyDependencyTest.php', 'Provider/GitHubReleaseAdapterTest.php', 'Runtime/RequestBrokerTest.php', 'WordPress/NativePackageUpdaterTest.php', 'Support/FakeOptionDatabase.php', 'Integration/wordpress-integration.php', 'Integration/wordpress-integration/distribution.php', 'Archive/FutureTest.php', 'Contract/FutureTest.php', 'Dependency/FutureTest.php', 'Provider/FutureTest.php', 'Runtime/FutureTest.php', 'WordPress/FutureTest.php', 'Support/FutureTest.php', 'Integration/FutureTest.php', 'Integration/wordpress-integration/FutureTest.php', 'Performance/FutureTest.php' ) as $path ) {
			$in_scope = ! str_starts_with( $path, 'Performance/' );
			self::assertSame( array(), $this->naming_diagnostics( $path, $source ) );
			self::assertSame( $in_scope, in_array( $method_code, $this->naming_diagnostics( $path, str_replace( 'owned_method', 'ownedMethod', $source ) ), true ), $path );
			self::assertSame( $in_scope, in_array( $variable_code, $this->naming_diagnostics( $path, str_replace( 'owned_value', 'ownedValue', $source ) ), true ), $path );
		}
		foreach ( array( 'Support/MysqliOptionDatabase.php', 'Support/FutureTest.php' ) as $path ) {
			self::assertContains( 'WordPress.PHP.YodaConditions.NotYoda', $this->naming_diagnostics( $path, str_replace( 'return $owned_value;', 'return $owned_value === 1;', $source ) ), $path );
		}
	}

	/** @return list<string> */
	private function naming_diagnostics( string $path, string $source ): array {
		$root    = dirname( __DIR__, 2 );
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
		fwrite( $pipes[0], $source );
		fclose( $pipes[0] );
		$output = stream_get_contents( $pipes[1] );
		$error  = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$status = proc_close( $process );
		self::assertContains( $status, array( 0, 1, 2, 3 ), $error );
		$report = json_decode( $output, true, 512, JSON_THROW_ON_ERROR );
		self::assertArrayHasKey( 'files', $report );
		$messages = array_merge( ...array_column( array_values( $report['files'] ), 'messages' ) );
		return array_values( array_filter( array_column( $messages, 'source' ), static fn( string $code ): bool => str_starts_with( $code, 'RANOwnedMethods.' ) || str_starts_with( $code, 'WordPress.NamingConventions.ValidVariableName.' ) || 'WordPress.PHP.YodaConditions.NotYoda' === $code ) );
	}
}
