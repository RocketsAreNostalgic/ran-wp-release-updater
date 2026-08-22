<?php

declare(strict_types=1);

namespace Tests\Performance;

require_once dirname(__DIR__) . '/Support/FakeOptionDatabase.php';

use PHPUnit\Framework\TestCase;
use RAN\WPReleaseUpdater\V1\Contract\BindingRecord;
use RAN\WPReleaseUpdater\V1\Contract\CanonicalUpdateUri;
use RAN\WPReleaseUpdater\V1\Contract\IdentityDescriptor;
use RAN\WPReleaseUpdater\V1\WordPress\BindingState;
use RAN\WPReleaseUpdater\V1\WordPress\ReleaseOperationCoordinator;
use Tests\Support\FakeOptionDatabase;

/**
 * Local deterministic throughput budgets, not provider or network benchmarks.
 * Generous limits detect accidental unbounded work without becoming host goals.
 */
final class KernelPerformanceTest extends TestCase {

	public function testWarmNeutralKernelTupleHasGenerousLocalResourceBudgets(): void {
		$binding = BindingRecord::create( $this->bindingFacts() );
		$database = new FakeOptionDatabase( 100 );
		$claimed = ReleaseOperationCoordinator::claimPersistentBindingState( $database, $binding, str_repeat( 'a', 64 ), 600 );
		self::assertSame( 'claimed', $claimed['result'] );
		$state = $claimed['current'];
		$claim = $this->claim( $state );
		for ( $index = 0; $index < 100; ++$index ) {
			CanonicalUpdateUri::canonicalizeBoundaries( $this->boundaries() );
			ReleaseOperationCoordinator::verifyPersistentBindingState( $database, $state, $claim );
		}
		$memoryBefore = memory_get_usage( true );
		$cpuBefore = $this->cpuNanoseconds();
		$started = hrtime( true );
		for ( $index = 0; $index < 1000; ++$index ) {
			$uri = CanonicalUpdateUri::canonicalizeBoundaries( $this->boundaries() );
			$verifyResult = ReleaseOperationCoordinator::verifyPersistentBindingState( $database, $state, $claim );
		}
		$elapsedNanoseconds = hrtime( true ) - $started;
		$cpuElapsedNanoseconds = $this->cpuNanoseconds() - $cpuBefore;

		self::assertSame( 'https://updates.example.test/owner/package', $uri );
		self::assertSame( 'verified', $verifyResult['result'] );
		self::assertLessThan( 5_000_000_000, $elapsedNanoseconds, 'One thousand warm local neutral-kernel tuples exceeded the deliberately generous five-second wall budget.' );
		self::assertLessThan( 4_000_000_000, $cpuElapsedNanoseconds, 'One thousand warm local neutral-kernel tuples exceeded the deliberately generous four-second CPU budget.' );
		self::assertLessThan( 16 * 1024 * 1024, memory_get_usage( true ) - $memoryBefore, 'The warm neutral-kernel tuple retained more than the deliberately generous sixteen-megabyte memory budget.' );
	}

	/** @return array<string,string> */
	private function boundaries(): array { return array( 'archive_preflight' => 'https://UPDATES.example.test/owner/package/', 'configuration' => 'https://updates.example.test/owner/package', 'offer_or_cache' => 'https://updates.example.test/owner/package', 'staged_package' => 'https://updates.example.test/owner/package' ); }
	private function cpuNanoseconds(): int { $usage = getrusage(); return ( (int) $usage['ru_utime.tv_sec'] + (int) $usage['ru_stime.tv_sec'] ) * 1_000_000_000 + ( (int) $usage['ru_utime.tv_usec'] + (int) $usage['ru_stime.tv_usec'] ) * 1000; }

	private function descriptor( string $version, string $release ): IdentityDescriptor {
		return IdentityDescriptor::create( array( 'artifact_filename' => 'package.zip', 'artifact_identity' => 'asset:' . $release, 'artifact_sha256' => str_repeat( 'a', 64 ), 'artifact_size' => 1, 'assurance_facts' => array( 'exact_artifact_identity' => true, 'exact_commit_identity' => true, 'exact_reacquisition_supported' => true, 'exact_release_identity' => true, 'provenance_verified' => true, 'publication_immutable' => true, 'repository_identity_stable' => true, 'trusted_digest_source' => true ), 'canonical_update_uri' => 'https://updates.example.test/owner/package', 'channel' => 'stable', 'commit_identity' => 'commit:' . $release, 'installed_package_identity' => 'package/package.php', 'prerelease' => false, 'provider_code' => 'neutral', 'release_identity' => $release, 'repository_identity' => 'repo:1', 'repository_locator' => 'owner/package', 'tag' => 'v' . $version, 'target_type' => 'plugin', 'version' => $version ) );
	}

	/** @return array<string,mixed> */
	private function bindingFacts(): array {
		return array( 'canonical_repository_locator' => 'owner/package', 'canonical_update_uri' => 'https://updates.example.test/owner/package', 'installed_package_identity' => 'package/package.php', 'php_runtime_version' => '8.2', 'provider_code' => 'neutral', 'release_channel' => 'stable', 'stable_repository_identity' => 'repo:1', 'target_type' => 'plugin', 'update_policy' => 'manual', 'wordpress_runtime_version' => '6.8' );
	}

	/** @return array<string,mixed> */
	private function claim( BindingState $state ): array {
		return array( 'binding_generation' => $state->bindingGeneration(), 'binding_hash' => $state->binding()->bindingHash(), 'lease_deadline' => $state->leaseDeadline(), 'owner_token' => $state->ownerToken() );
	}
}
