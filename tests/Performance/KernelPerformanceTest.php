<?php

declare(strict_types=1);

namespace RAN\WPReleaseUpdater\V1\Tests\Performance;

require_once dirname( __DIR__ ) . '/Support/FakeOptionDatabase.php';

use PHPUnit\Framework\TestCase;
use RAN\WPReleaseUpdater\V1\Contract\CanonicalUpdateUri;
use RAN\WPReleaseUpdater\V1\Tests\Support\FakeOptionDatabase;
use RAN\WPReleaseUpdater\V1\WordPress\WordPressUpdaterLock;

/**
 * Local deterministic throughput budgets, not provider or network benchmarks.
 * Generous limits detect accidental unbounded work without becoming host goals.
 */
final class KernelPerformanceTest extends TestCase {

	public function test_warm_neutral_kernel_tuple_has_generous_local_resource_budgets(): void {
		$database = new FakeOptionDatabase();
		$lock     = new WordPressUpdaterLock( $database, static fn (): int => 1_700_000_000 );
		$token    = $lock->acquire();
		self::assertIsString( $token );
		for ( $index = 0; $index < 100; ++$index ) {
			CanonicalUpdateUri::canonicalize_boundaries( $this->boundaries() );
			$lock->verify( $token );
		}
		$memory_before = memory_get_usage( true );
		$cpu_before    = $this->cpu_nanoseconds();
		$started       = hrtime( true );
		for ( $index = 0; $index < 1000; ++$index ) {
			$uri      = CanonicalUpdateUri::canonicalize_boundaries( $this->boundaries() );
			$verified = $lock->verify( $token );
		}
		$elapsed_nanoseconds     = hrtime( true ) - $started;
		$cpu_elapsed_nanoseconds = $this->cpu_nanoseconds() - $cpu_before;

		self::assertSame( 'https://updates.example.test/owner/package', $uri );
		self::assertTrue( $verified );
		self::assertTrue( $lock->release( $token ) );
		self::assertLessThan( 5_000_000_000, $elapsed_nanoseconds, 'One thousand warm local neutral-kernel tuples exceeded the deliberately generous five-second wall budget.' );
		self::assertLessThan( 4_000_000_000, $cpu_elapsed_nanoseconds, 'One thousand warm local neutral-kernel tuples exceeded the deliberately generous four-second CPU budget.' );
		self::assertLessThan( 16 * 1024 * 1024, memory_get_usage( true ) - $memory_before, 'The warm neutral-kernel tuple retained more than the deliberately generous sixteen-megabyte memory budget.' );
	}

	/** @return array<string,string> */
	private function boundaries(): array {
		return array(
			'archive_preflight' => 'https://UPDATES.example.test/owner/package/',
			'configuration'     => 'https://updates.example.test/owner/package',
			'offer'             => 'https://updates.example.test/owner/package',
			'staged_package'    => 'https://updates.example.test/owner/package',
		);
	}

	private function cpu_nanoseconds(): int {
		$usage = getrusage();
		self::assertIsArray( $usage );
		return ( (int) $usage['ru_utime.tv_sec'] + (int) $usage['ru_stime.tv_sec'] ) * 1_000_000_000 + ( (int) $usage['ru_utime.tv_usec'] + (int) $usage['ru_stime.tv_usec'] ) * 1000;
	}
}
