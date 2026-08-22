<?php

declare(strict_types=1);

namespace Tests\WordPress;

require_once dirname(__DIR__) . '/Support/FakeOptionDatabase.php';

use PHPUnit\Framework\TestCase;
use RAN\WPReleaseUpdater\V1\Contract\BindingRecord;
use RAN\WPReleaseUpdater\V1\WordPress\BindingState;
use RAN\WPReleaseUpdater\V1\WordPress\ReleaseOperationCoordinator;
use Tests\Support\FakeOptionDatabase;

final class ReleaseOperationCoordinatorTest extends TestCase
{
	public function testOneSelfContainedTargetRowClaimsAndDoesNotReadLegacyRows(): void
	{
		$database = new FakeOptionDatabase( 100 ); $database->seedOption( 'ran_wp_gh_op_v1_deadbeef', '{"hostile":true}', 'yes' ); $binding = $this->binding(); $claimed = ReleaseOperationCoordinator::claimPersistentBindingState( $database, $binding, str_repeat( 'a', 64 ), 20 );
		self::assertSame( 'claimed', $claimed['result'] ); self::assertSame( 120, $claimed['current']->leaseDeadline() ); self::assertCount( 2, $database->rows() ); $row = array_values( array_filter( $database->rows(), static fn ( array $row ): bool => 'no' === $row['autoload'] ) )[0]; $stored = json_decode( $row['option_value'], true, 64, JSON_THROW_ON_ERROR );
		self::assertSame( $claimed['current']->toArray(), $stored ); self::assertSame( $binding->toArray(), $stored['binding'] ); self::assertSame( array(), array_values( array_filter( $database->readOptionNames(), static fn ( string $name ): bool => str_starts_with( $name, 'ran_wp_gh_' ) ) ) );
	}
	public function testLiveRebindSurvivesRestartAndFencesTheStaleWriter(): void
	{
		$database = new FakeOptionDatabase( 100 ); $old = $this->binding(); $first = ReleaseOperationCoordinator::claimPersistentBindingState( $database, $old, str_repeat( 'a', 64 ), 20 ); $claim = $this->claim( $first['current'] ); $next = BindingRecord::create( array_merge( $this->facts(), array( 'provider_code' => 'gitlab' ) ) );
		$database->setTime( 101 ); $rebound = ReleaseOperationCoordinator::persistPersistentBindingState( $database, $first['current'], $claim, $next ); self::assertSame( 'rebound', $rebound['result'] ); self::assertSame( 2, $rebound['current']->bindingGeneration() ); self::assertSame( 2, $rebound['current']->fenceEpoch() ); self::assertSame( 'binding_fence_lost', ReleaseOperationCoordinator::verifyPersistentBindingState( $database, $first['current'], $claim )['result'] );
		$database->setTime( 121 ); $restart = ReleaseOperationCoordinator::claimPersistentBindingState( $database, $next, str_repeat( 'b', 64 ), 20 ); self::assertSame( 'claimed', $restart['result'] ); self::assertSame( $next->toArray(), $restart['current']->binding()->toArray() ); self::assertSame( 3, $restart['current']->bindingGeneration() ); self::assertCount( 1, $database->rows() );
	}
	public function testExactValueCasAndLiveLeaseRejectRacesAndTakeover(): void
	{
		$database = new FakeOptionDatabase( 100 ); $binding = $this->binding(); $first = ReleaseOperationCoordinator::claimPersistentBindingState( $database, $binding, str_repeat( 'a', 64 ), 20 ); $name = 'ran_wp_release_updater_target_v1_' . BindingRecord::targetFenceKey( array( 'installed_package_identity' => 'x/x.php' ) ); $database->mutateOnNextWrite( $name, static function ( FakeOptionDatabase $database ) use ( $name ): void { $database->forceOptionValue( $name, '{}' ); } ); $next = BindingRecord::create( array_merge( $this->facts(), array( 'update_policy' => 'automatic' ) ) );
		self::assertSame( 'binding_fence_lost', ReleaseOperationCoordinator::persistPersistentBindingState( $database, $first['current'], $this->claim( $first['current'] ), $next )['result'] ); self::assertSame( 'binding_fence_lost', ReleaseOperationCoordinator::claimPersistentBindingState( $database, $binding, str_repeat( 'b', 64 ), 20 )['result'] );
	}
	public function testEveryProviderRepositoryUriAndPolicySwitchFencesStaleState(): void
	{
		foreach ( array( 'provider_code' => 'gitlab', 'canonical_repository_locator' => 'other/repo', 'canonical_update_uri' => 'https://example.com/other/repo', 'update_policy' => 'automatic' ) as $key => $value ) {
			$database = new FakeOptionDatabase( 100 ); $old = $this->binding(); $first = ReleaseOperationCoordinator::claimPersistentBindingState( $database, $old, str_repeat( 'a', 64 ), 20 ); $claim = $this->claim( $first['current'] ); $next = BindingRecord::create( array_merge( $this->facts(), array( $key => $value ) ) );
			$database->setTime( 101 ); self::assertSame( 'rebound', ReleaseOperationCoordinator::persistPersistentBindingState( $database, $first['current'], $claim, $next )['result'], $key ); self::assertSame( 'binding_fence_lost', ReleaseOperationCoordinator::verifyPersistentBindingState( $database, $first['current'], $claim )['result'], $key );
		}
	}
	public function testRenewalFencesTheOldClaimAndUsesADatabaseTimeCas(): void
	{
		$database = new FakeOptionDatabase( 100 ); $binding = $this->binding(); $first = ReleaseOperationCoordinator::claimPersistentBindingState( $database, $binding, str_repeat( 'a', 64 ), 20 ); $database->setTime( 101 ); $renewed = ReleaseOperationCoordinator::renewPersistentBindingState( $database, $first['current'], $this->claim( $first['current'] ), 1 );
		self::assertSame( 'renewed', $renewed['result'] ); self::assertSame( 1, $renewed['current']->bindingGeneration() ); self::assertSame( 2, $renewed['current']->fenceEpoch() ); self::assertSame( 121, $renewed['current']->leaseDeadline() ); self::assertSame( 'binding_fence_lost', ReleaseOperationCoordinator::verifyPersistentBindingState( $database, $first['current'], $this->claim( $first['current'] ) )['result'] ); self::assertSame( 'verified', ReleaseOperationCoordinator::verifyPersistentBindingState( $database, $renewed['current'], $this->claim( $renewed['current'] ) )['result'] ); self::assertNotEmpty( array_filter( $database->preparedSql(), static fn ( string $sql ): bool => str_contains( $sql, 'UNIX_TIMESTAMP() <= %d' ) ) );
	}
	public function testReleasePreservesTheBindingAndLetsALaterProviderSwitchClaim(): void
	{
		$database = new FakeOptionDatabase( 100 ); $old = $this->binding(); $first = ReleaseOperationCoordinator::claimPersistentBindingState( $database, $old, str_repeat( 'a', 64 ), 20 ); $released = ReleaseOperationCoordinator::releasePersistentBindingState( $database, $first['current'], $this->claim( $first['current'] ) );
		self::assertSame( 'released', $released['result'] ); self::assertSame( 1, $released['current']->leaseDeadline() ); self::assertSame( 1, $released['current']->bindingGeneration() ); self::assertSame( 2, $released['current']->fenceEpoch() ); self::assertSame( $old->toArray(), $released['current']->binding()->toArray() ); $database->setTime( 101 ); $next = BindingRecord::create( array_merge( $this->facts(), array( 'provider_code' => 'gitlab' ) ) ); $claimed = ReleaseOperationCoordinator::claimPersistentBindingState( $database, $next, str_repeat( 'b', 64 ), 20 ); self::assertSame( 'claimed', $claimed['result'] ); self::assertSame( $next->toArray(), $claimed['current']->binding()->toArray() ); self::assertSame( 2, $claimed['current']->bindingGeneration() ); self::assertSame( 3, $claimed['current']->fenceEpoch() );
	}
	public function testReleaseRejectsExpiredAndRacingOwners(): void
	{
		$database = new FakeOptionDatabase( 100 ); $binding = $this->binding(); $first = ReleaseOperationCoordinator::claimPersistentBindingState( $database, $binding, str_repeat( 'a', 64 ), 20 ); $claim = $this->claim( $first['current'] ); $database->setTime( 121 ); self::assertSame( 'binding_fence_lost', ReleaseOperationCoordinator::releasePersistentBindingState( $database, $first['current'], $claim )['result'] );
		$database = new FakeOptionDatabase( 100 ); $first = ReleaseOperationCoordinator::claimPersistentBindingState( $database, $binding, str_repeat( 'a', 64 ), 20 ); $name = 'ran_wp_release_updater_target_v1_' . BindingRecord::targetFenceKey( array( 'installed_package_identity' => 'x/x.php' ) ); $database->mutateOnNextWrite( $name, static function ( FakeOptionDatabase $database ) use ( $name ): void { $database->forceOptionValue( $name, '{}' ); } ); self::assertSame( 'binding_fence_lost', ReleaseOperationCoordinator::releasePersistentBindingState( $database, $first['current'], $this->claim( $first['current'] ) )['result'] );
	}
	/** @return array<string,mixed> */ private function claim( BindingState $state ): array { return array( 'binding_generation' => $state->bindingGeneration(), 'binding_hash' => $state->binding()->bindingHash(), 'lease_deadline' => $state->leaseDeadline(), 'owner_token' => $state->ownerToken() ); }
	private function binding(): BindingRecord { return BindingRecord::create( $this->facts() ); }
	/** @return array<string,mixed> */ private function facts(): array { return array( 'canonical_repository_locator' => 'owner/repo', 'canonical_update_uri' => 'https://example.com/owner/repo', 'installed_package_identity' => 'x/x.php', 'php_runtime_version' => '8.2', 'provider_code' => 'github', 'release_channel' => 'stable', 'stable_repository_identity' => 'repo:1', 'target_type' => 'plugin', 'update_policy' => 'manual', 'wordpress_runtime_version' => '6.8' ); }
}
