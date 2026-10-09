<?php

declare(strict_types=1);

namespace RAN\WPReleaseUpdater\V1\Tests\WordPress;

require_once dirname( __DIR__ ) . '/Support/FakeOptionDatabase.php';

use PHPUnit\Framework\TestCase;
use RAN\WPReleaseUpdater\V1\Contract\BindingRecord;
use RAN\WPReleaseUpdater\V1\WordPress\BindingState;
use RAN\WPReleaseUpdater\V1\WordPress\BindingFenceCoordinator;
use RAN\WPReleaseUpdater\V1\Tests\Support\FakeOptionDatabase;

final class BindingFenceCoordinatorTest extends TestCase {

	public function test_magic_database_proxy_retains_the_complete_lease_lifecycle(): void {
		$inner    = new FakeOptionDatabase( 100 );
		$database = new class( $inner ) {
			public string $options = 'wp_options';
			public function __construct( private FakeOptionDatabase $inner ) {}
			/** @param list<mixed> $arguments */
			public function __call( string $name, array $arguments ): mixed {
				return match ( $name ) {
					'prepare' => $this->inner->prepare( ...$arguments ),
					'get_var' => $this->inner->get_var( ...$arguments ),
					'query' => $this->inner->query( ...$arguments ),
					default => throw new \BadMethodCallException( 'Unexpected database call.' ),
				};
			}
		};
		$binding  = $this->binding();
		$claimed  = BindingFenceCoordinator::claim_persistent_binding_state( $database, $binding, str_repeat( 'a', 64 ), 20 );
		self::assertInstanceOf( BindingState::class, $claimed['current'] );
		self::assertSame( 'claimed', $claimed['result'] );
		$verified = BindingFenceCoordinator::verify_persistent_binding_state( $database, $claimed['current'], $this->claim( $claimed['current'] ) );
		self::assertInstanceOf( BindingState::class, $verified['current'] );
		self::assertSame( 'verified', $verified['result'] );
		self::assertSame( 100, $verified['now'] );
		self::assertSame( $claimed['current']->to_array(), $verified['current']->to_array() );
		$renewed = BindingFenceCoordinator::renew_persistent_binding_state( $database, $verified['current'], $this->claim( $verified['current'] ), 30 );
		self::assertInstanceOf( BindingState::class, $renewed['current'] );
		self::assertSame( 'renewed', $renewed['result'] );
		self::assertSame( 130, $renewed['current']->lease_deadline() );
		$released = BindingFenceCoordinator::release_persistent_binding_state( $database, $renewed['current'], $this->claim( $renewed['current'] ) );
		self::assertInstanceOf( BindingState::class, $released['current'] );
		self::assertSame( 'released', $released['result'] );
		self::assertSame( 1, $released['current']->lease_deadline() );
		self::assertCount( 1, $inner->rows() );
	}
	public function test_incomplete_database_is_rejected_before_any_read(): void {
		$database = new class() {
			public string $options = 'wp_options';
			public int $reads      = 0;
			public function get_var( string $query ): int {
				++$this->reads;
				return 100;
			}
		};
		$result   = BindingFenceCoordinator::claim_persistent_binding_state( $database, $this->binding(), str_repeat( 'a', 64 ), 20 );
		self::assertSame(
			array(
				'current' => null,
				'result'  => 'binding_fence_lost',
			),
			$result
		);
		self::assertSame( 0, $database->reads );
	}
	public function test_one_self_contained_target_row_claims_and_does_not_read_legacy_rows(): void {
		$database = new FakeOptionDatabase( 100 );
		$database->seed_option( 'ran_wp_gh_op_v1_deadbeef', '{"hostile":true}', 'yes' );
		$binding = $this->binding();
		$claimed = BindingFenceCoordinator::claim_persistent_binding_state( $database, $binding, str_repeat( 'a', 64 ), 20 );
		self::assertInstanceOf( BindingState::class, $claimed['current'] );
		self::assertSame( 'claimed', $claimed['result'] );
		self::assertSame( 120, $claimed['current']->lease_deadline() );
		self::assertCount( 2, $database->rows() );
		$row    = array_values( array_filter( $database->rows(), static fn ( array $row ): bool => 'no' === $row['autoload'] ) )[0];
		$stored = json_decode( $row['option_value'], true, 64, JSON_THROW_ON_ERROR );
		self::assertSame( $claimed['current']->to_array(), $stored );
		self::assertSame( $binding->to_array(), $stored['binding'] );
		self::assertSame( array(), array_values( array_filter( $database->read_option_names(), static fn ( string $name ): bool => str_starts_with( $name, 'ran_wp_gh_' ) ) ) );
	}
	public function test_expired_claim_can_replace_binding_and_fences_the_stale_writer(): void {
		$database = new FakeOptionDatabase( 100 );
		$old      = $this->binding();
		$first    = BindingFenceCoordinator::claim_persistent_binding_state( $database, $old, str_repeat( 'a', 64 ), 20 );
		self::assertInstanceOf( BindingState::class, $first['current'] );
		$claim = $this->claim( $first['current'] );
		$next  = BindingRecord::create( array_merge( $this->facts(), array( 'provider_code' => 'gitlab' ) ) );
		$database->set_time( 121 );
		$replacement = BindingFenceCoordinator::claim_persistent_binding_state( $database, $next, str_repeat( 'b', 64 ), 20 );
		self::assertInstanceOf( BindingState::class, $replacement['current'] );
		self::assertSame( 'claimed', $replacement['result'] );
		self::assertSame( 2, $replacement['current']->binding_generation() );
		self::assertSame( 2, $replacement['current']->fence_epoch() );
		self::assertSame( 'binding_fence_lost', BindingFenceCoordinator::verify_persistent_binding_state( $database, $first['current'], $claim )['result'] );
		$database->set_time( 142 );
		$restart = BindingFenceCoordinator::claim_persistent_binding_state( $database, $next, str_repeat( 'c', 64 ), 20 );
		self::assertInstanceOf( BindingState::class, $restart['current'] );
		self::assertSame( 'claimed', $restart['result'] );
		self::assertSame( $next->to_array(), $restart['current']->binding()->to_array() );
		self::assertSame( 3, $restart['current']->binding_generation() );
		self::assertCount( 1, $database->rows() );
	}
	public function test_multisite_contexts_use_the_network_options_table_and_one_fence(): void {
		$database = new FakeOptionDatabase( 100 );
		$network  = new class( $database, 'wp_options' ) {
			public string $base_prefix = 'wp_';
			public string $options;
			public function __construct( private FakeOptionDatabase $database, string $options ) {
				$this->options = $options; }
			public function prepare( string $sql, mixed ...$args ): string {
				return $this->database->prepare( $sql, ...$args ); }
			public function get_var( string $query ): string|int|null {
				return $this->database->get_var( $query ); }
			public function query( string $query ): int {
				return $this->database->query( $query ); }
		};
		$subsite  = new class( $database, 'wp_2_options' ) {
			public string $base_prefix = 'wp_';
			public string $options;
			public function __construct( private FakeOptionDatabase $database, string $options ) {
				$this->options = $options; }
			public function prepare( string $sql, mixed ...$args ): string {
				return $this->database->prepare( $sql, ...$args ); }
			public function get_var( string $query ): string|int|null {
				return $this->database->get_var( $query ); }
			public function query( string $query ): int {
				return $this->database->query( $query ); }
		};
		$binding  = $this->binding();
		$claimed  = BindingFenceCoordinator::claim_persistent_binding_state( $network, $binding, str_repeat( 'a', 64 ), 20 );
		$blocked  = BindingFenceCoordinator::claim_persistent_binding_state( $subsite, $binding, str_repeat( 'b', 64 ), 20 );

		self::assertSame( 'claimed', $claimed['result'] );
		self::assertSame( 'binding_fence_lost', $blocked['result'] );
		self::assertNotEmpty( $database->prepared_sql() );
		self::assertSame( array(), array_values( array_filter( $database->prepared_sql(), static fn ( string $sql ): bool => str_contains( $sql, 'wp_2_options' ) ) ) );
		self::assertNotSame( array(), array_values( array_filter( $database->prepared_sql(), static fn ( string $sql ): bool => str_contains( $sql, 'wp_options' ) ) ) );
	}
	public function test_network_and_type_differences_use_independent_fences(): void {
		$database = new FakeOptionDatabase( 100 );
		$binding  = $this->binding();
		$claimed  = BindingFenceCoordinator::claim_persistent_binding_state( $database, $binding, str_repeat( 'a', 64 ), 20 );
		self::assertInstanceOf( BindingState::class, $claimed['current'] );
		foreach ( array( array( 'network_id' => 2 ), array( 'target_type' => 'theme' ) ) as $difference ) {
			$next = BindingRecord::create( array_merge( $this->facts(), $difference ) );
			self::assertSame( 'claimed', BindingFenceCoordinator::claim_persistent_binding_state( $database, $next, str_repeat( 'b', 64 ), 20 )['result'] );
			self::assertSame( 'verified', BindingFenceCoordinator::verify_persistent_binding_state( $database, $claimed['current'], $this->claim( $claimed['current'] ) )['result'] );
		}
	}
	public function test_exact_value_cas_and_live_lease_reject_races_and_takeover(): void {
		$database = new FakeOptionDatabase( 100 );
		$binding  = $this->binding();
		$first    = BindingFenceCoordinator::claim_persistent_binding_state( $database, $binding, str_repeat( 'a', 64 ), 20 );
		self::assertInstanceOf( BindingState::class, $first['current'] );
		$name = 'ran_wp_release_updater_target_v1_' . BindingRecord::target_fence_key(
			array(
				'network_id'                 => 1,
				'target_type'                => 'plugin',
				'installed_package_identity' => 'x/x.php',
			)
		);
		$database->mutate_on_next_write(
			$name,
			static function ( FakeOptionDatabase $database ) use ( $name ): void {
				$database->force_option_value( $name, '{}' );
			}
		);
		self::assertSame( 'binding_fence_lost', BindingFenceCoordinator::renew_persistent_binding_state( $database, $first['current'], $this->claim( $first['current'] ), 20 )['result'] );
		self::assertSame( 'binding_fence_lost', BindingFenceCoordinator::claim_persistent_binding_state( $database, $binding, str_repeat( 'b', 64 ), 20 )['result'] );
	}
	public function test_claim_rejects_equivalent_values_with_an_unexpected_key_order(): void {
		$database = new FakeOptionDatabase( 100 );
		$binding  = $this->binding();
		$claimed  = BindingFenceCoordinator::claim_persistent_binding_state( $database, $binding, str_repeat( 'a', 64 ), 20 );
		self::assertInstanceOf( BindingState::class, $claimed['current'] );
		$claim     = $this->claim( $claimed['current'] );
		$reordered = array(
			'owner_token'        => $claim['owner_token'],
			'binding_generation' => $claim['binding_generation'],
			'binding_hash'       => $claim['binding_hash'],
			'lease_deadline'     => $claim['lease_deadline'],
		);
		self::assertSame( 'binding_fence_lost', BindingFenceCoordinator::verify_persistent_binding_state( $database, $claimed['current'], $reordered )['result'] );
	}
	public function test_every_provider_repository_uri_and_policy_switch_fences_stale_state(): void {
		foreach ( array(
			'provider_code'                => 'gitlab',
			'canonical_repository_locator' => 'other/repo',
			'canonical_update_uri'         => 'https://example.com/other/repo',
			'update_policy'                => 'automatic',
		) as $key => $value ) {
			$database = new FakeOptionDatabase( 100 );
			$old      = $this->binding();
			$first    = BindingFenceCoordinator::claim_persistent_binding_state( $database, $old, str_repeat( 'a', 64 ), 20 );
			self::assertInstanceOf( BindingState::class, $first['current'] );
			$claim = $this->claim( $first['current'] );
			$next  = BindingRecord::create( array_merge( $this->facts(), array( $key => $value ) ) );
			$database->set_time( 121 );
			self::assertSame( 'claimed', BindingFenceCoordinator::claim_persistent_binding_state( $database, $next, str_repeat( 'b', 64 ), 20 )['result'], $key );
			self::assertSame( 'binding_fence_lost', BindingFenceCoordinator::verify_persistent_binding_state( $database, $first['current'], $claim )['result'], $key );
		}
	}
	public function test_renewal_fences_the_old_claim_and_uses_a_database_time_cas(): void {
		$database = new FakeOptionDatabase( 100 );
		$binding  = $this->binding();
		$first    = BindingFenceCoordinator::claim_persistent_binding_state( $database, $binding, str_repeat( 'a', 64 ), 20 );
		self::assertInstanceOf( BindingState::class, $first['current'] );
		$database->set_time( 101 );
		$renewed = BindingFenceCoordinator::renew_persistent_binding_state( $database, $first['current'], $this->claim( $first['current'] ), 1 );
		self::assertInstanceOf( BindingState::class, $renewed['current'] );
		self::assertSame( 'renewed', $renewed['result'] );
		self::assertSame( 1, $renewed['current']->binding_generation() );
		self::assertSame( 2, $renewed['current']->fence_epoch() );
		self::assertSame( 121, $renewed['current']->lease_deadline() );
		self::assertSame( 'binding_fence_lost', BindingFenceCoordinator::verify_persistent_binding_state( $database, $first['current'], $this->claim( $first['current'] ) )['result'] );
		self::assertSame( 'verified', BindingFenceCoordinator::verify_persistent_binding_state( $database, $renewed['current'], $this->claim( $renewed['current'] ) )['result'] );
		self::assertNotEmpty( array_filter( $database->prepared_sql(), static fn ( string $sql ): bool => str_contains( $sql, 'UNIX_TIMESTAMP() <= %d' ) ) );
	}
	public function test_release_preserves_the_binding_and_lets_a_later_provider_switch_claim(): void {
		$database = new FakeOptionDatabase( 100 );
		$old      = $this->binding();
		$first    = BindingFenceCoordinator::claim_persistent_binding_state( $database, $old, str_repeat( 'a', 64 ), 20 );
		self::assertInstanceOf( BindingState::class, $first['current'] );
		$released = BindingFenceCoordinator::release_persistent_binding_state( $database, $first['current'], $this->claim( $first['current'] ) );
		self::assertInstanceOf( BindingState::class, $released['current'] );
		self::assertSame( 'released', $released['result'] );
		self::assertSame( 1, $released['current']->lease_deadline() );
		self::assertSame( 1, $released['current']->binding_generation() );
		self::assertSame( 2, $released['current']->fence_epoch() );
		self::assertSame( $old->to_array(), $released['current']->binding()->to_array() );
		$database->set_time( 101 );
		$next    = BindingRecord::create( array_merge( $this->facts(), array( 'provider_code' => 'gitlab' ) ) );
		$claimed = BindingFenceCoordinator::claim_persistent_binding_state( $database, $next, str_repeat( 'b', 64 ), 20 );
		self::assertInstanceOf( BindingState::class, $claimed['current'] );
		self::assertSame( 'claimed', $claimed['result'] );
		self::assertSame( $next->to_array(), $claimed['current']->binding()->to_array() );
		self::assertSame( 2, $claimed['current']->binding_generation() );
		self::assertSame( 3, $claimed['current']->fence_epoch() );
	}
	public function test_release_rejects_expired_and_racing_owners(): void {
		$database = new FakeOptionDatabase( 100 );
		$binding  = $this->binding();
		$first    = BindingFenceCoordinator::claim_persistent_binding_state( $database, $binding, str_repeat( 'a', 64 ), 20 );
		self::assertInstanceOf( BindingState::class, $first['current'] );
		$claim = $this->claim( $first['current'] );
		$database->set_time( 121 );
		self::assertSame( 'binding_fence_lost', BindingFenceCoordinator::release_persistent_binding_state( $database, $first['current'], $claim )['result'] );
		$database = new FakeOptionDatabase( 100 );
		$first    = BindingFenceCoordinator::claim_persistent_binding_state( $database, $binding, str_repeat( 'a', 64 ), 20 );
		self::assertInstanceOf( BindingState::class, $first['current'] );
		$name = 'ran_wp_release_updater_target_v1_' . BindingRecord::target_fence_key(
			array(
				'network_id'                 => 1,
				'target_type'                => 'plugin',
				'installed_package_identity' => 'x/x.php',
			)
		);
		$database->mutate_on_next_write(
			$name,
			static function ( FakeOptionDatabase $database ) use ( $name ): void {
				$database->force_option_value( $name, '{}' );
			}
		);
		self::assertSame( 'binding_fence_lost', BindingFenceCoordinator::release_persistent_binding_state( $database, $first['current'], $this->claim( $first['current'] ) )['result'] );
	}
	/** @return array<string,mixed> */ private function claim( BindingState $state ): array {
		return array(
			'binding_generation' => $state->binding_generation(),
			'binding_hash'       => $state->binding()->binding_hash(),
			'lease_deadline'     => $state->lease_deadline(),
			'owner_token'        => $state->owner_token(),
		); }
	private function binding(): BindingRecord {
		return BindingRecord::create( $this->facts() ); }
	/** @return array<string,mixed> */ private function facts(): array {
		return array(
			'canonical_repository_locator' => 'owner/repo',
			'canonical_update_uri'         => 'https://example.com/owner/repo',
			'installed_package_identity'   => 'x/x.php',
			'maximum_artifact_bytes'       => 52428800,
			'network_id'                   => 1,
			'php_runtime_version'          => '8.2',
			'provider_code'                => 'github',
			'release_channel'              => 'stable',
			'stable_repository_identity'   => 'repo:1',
			'target_type'                  => 'plugin',
			'theme_template'               => '',
			'update_policy'                => 'manual',
			'wordpress_runtime_version'    => '6.8',
		); }
}
