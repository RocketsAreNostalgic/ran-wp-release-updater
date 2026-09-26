<?php

declare(strict_types=1);

namespace RAN\WPReleaseUpdater\V1\WordPress;

use InvalidArgumentException;
use RAN\WPReleaseUpdater\V1\Contract\AcquisitionReceipt;
use RAN\WPReleaseUpdater\V1\Contract\BindingRecord;
use RAN\WPReleaseUpdater\V1\Contract\IdentityDescriptor;

final class BindingFenceCoordinator {
	private const PREFIX   = 'ran_wp_release_updater_target_v1_';
	private const CLAIM    = array( 'binding_generation', 'binding_hash', 'lease_deadline', 'owner_token' );
	private const MAX_JSON = 16384;
	/** @return array{current:BindingState|null,result:string} */
	public static function claim_persistent_binding_state( object $wpdb, BindingRecord $binding, mixed $owner, mixed $seconds ): array {
		if ( ! self::database( $wpdb ) || ! self::hash( $owner ) || ! is_int( $seconds ) || $seconds < 1 ) {
			return self::lost();
		}
		$now = self::time( $wpdb );
		if ( null === $now || $seconds > BindingState::MAX_SAFE_INTEGER - $now ) {
			return self::lost();
		}
		$name = self::name( $binding );
		$raw  = self::read( $wpdb, $name );
		if ( null === $raw ) {
			return self::insert_claim( $wpdb, $name, $binding, $owner, $now + $seconds );
		}
		$current = self::state( $raw );
		if ( null === $current || ! self::same_target( $binding, $current->binding() ) || $now <= $current->lease_deadline() || self::at_limit( $current ) ) {
			return self::lost( $current );
		}
		try {
			$next = BindingState::create( $binding, $owner, $now + $seconds, $current->binding_generation() + 1, $current->fence_epoch() + 1 );
		} catch ( InvalidArgumentException ) {
			return self::lost( $current );
		}
		$json = self::json( $next->to_array() );
		if ( null === $json || ! self::cas( $wpdb, $name, $raw, $json, $current->lease_deadline(), true ) || ! self::same_raw( $wpdb, $name, $json ) ) {
			return self::lost( $current );
		}
		return array(
			'current' => $next,
			'result'  => 'claimed',
		);
	}
	/** @return array{current:BindingState|null,result:string} */
	public static function renew_persistent_binding_state( object $wpdb, BindingState $expected, mixed $claim, int $seconds ): array {
		return self::transition_persistent_binding_state( $wpdb, $expected, $claim, $seconds, 'renewed' );
	}
	/** @return array{current:BindingState|null,result:string} */
	public static function release_persistent_binding_state( object $wpdb, BindingState $expected, mixed $claim ): array {
		return self::transition_persistent_binding_state( $wpdb, $expected, $claim, null, 'released' );
	}
	/** @return array{current:BindingState,now:int,result:'verified'}|array{current:BindingState|null,result:'binding_fence_lost'} */
	public static function verify_persistent_binding_state( object $wpdb, BindingState $expected, mixed $claim ): array {
		if ( ! self::database( $wpdb ) ) {
			return self::lost();
		}
		$now     = self::time( $wpdb );
		$name    = self::name( $expected->binding() );
		$raw     = self::read( $wpdb, $name );
		$current = null === $raw ? null : self::state( $raw );
		if ( null === $now || null === $current || ! self::same( $current, $expected )
			|| ! self::claim( $current, $claim ) || $now > $current->lease_deadline()
			|| ! self::same_raw( $wpdb, $name, $raw ) ) {
			return self::lost( $current );
		}
		return array(
			'current' => $current,
			'now'     => $now,
			'result'  => 'verified',
		);
	}
	/** @return array{current:BindingState|null,now?:int,receipt?:AcquisitionReceipt,result:string} */
	public static function complete_persistent_install( object $wpdb, BindingState $expected, mixed $claim, mixed $receipt, IdentityDescriptor $descriptor ): array {
		$first = self::verify_persistent_binding_state( $wpdb, $expected, $claim );
		if ( 'verified' !== $first['result'] ) {
			return $first;
		}
		try {
			$accepted = AcquisitionReceipt::accept_fresh( $receipt, $first['current'], $descriptor, $first['now'] );
		} catch ( InvalidArgumentException ) {
			return self::lost( $first['current'] );
		}
		$last = self::verify_persistent_binding_state( $wpdb, $expected, $claim );
		if ( 'verified' !== $last['result'] ) {
			return self::lost( $last['current'] );
		}
		$released = self::release_persistent_binding_state( $wpdb, $expected, $claim );
		if ( 'released' !== $released['result'] ) {
			return self::lost( $released['current'] );
		}
		return array(
			'current' => $released['current'],
			'now'     => $last['now'],
			'receipt' => $accepted,
			'result'  => 'completed',
		);
	}
	private static function name( BindingRecord $binding ): string {
		$facts = $binding->to_array();
		return self::PREFIX . BindingRecord::target_fence_key(
			array(
				'network_id'                 => $facts['network_id'],
				'target_type'                => $facts['target_type'],
				'installed_package_identity' => $facts['installed_package_identity'],
			)
		);
	}
	/** @return array{current:BindingState|null,result:string} */
	private static function insert_claim( object $wpdb, string $name, BindingRecord $binding, string $owner, int $deadline ): array {
		try {
			$state = BindingState::create( $binding, $owner, $deadline );
		} catch ( InvalidArgumentException ) {
			return self::lost(); }
		$json = self::json( $state->to_array() );
		if ( null === $json || ! self::insert( $wpdb, $name, $json ) || ! self::same_raw( $wpdb, $name, $json ) ) {
			return self::lost();
		}
		return array(
			'current' => $state,
			'result'  => 'claimed',
		);
	}
	/** @return array{current:BindingState|null,result:string} */
	private static function transition_persistent_binding_state( object $wpdb, BindingState $expected, mixed $claim, ?int $seconds, string $result ): array {
		if ( ! self::database( $wpdb ) || ( null !== $seconds && $seconds < 1 ) ) {
			return self::lost();
		}
		$now = self::time( $wpdb );
		if ( null === $now || ( null !== $seconds && $seconds > BindingState::MAX_SAFE_INTEGER - $now ) ) {
			return self::lost();
		}
		$name    = self::name( $expected->binding() );
		$raw     = self::read( $wpdb, $name );
		$current = null === $raw ? null : self::state( $raw );
		if ( null === $current || ! self::same( $current, $expected ) || ! self::claim( $current, $claim )
			|| $now > $current->lease_deadline() || BindingState::MAX_SAFE_INTEGER === $current->fence_epoch() ) {
			return self::lost( $current );
		}
		if ( null !== $seconds && BindingState::MAX_SAFE_INTEGER === $current->lease_deadline() ) {
			return self::lost( $current );
		}
		$deadline = null === $seconds ? 1 : max( $current->lease_deadline() + 1, $now + $seconds );
		try {
			$next = BindingState::create( $current->binding(), $current->owner_token(), $deadline, $current->binding_generation(), $current->fence_epoch() + 1 );
		} catch ( InvalidArgumentException ) {
			return self::lost( $current );
		}
		$json = self::json( $next->to_array() );
		if ( null === $json || ! self::cas( $wpdb, $name, $raw, $json, $current->lease_deadline() ) || ! self::same_raw( $wpdb, $name, $json ) ) {
			return self::lost( $current );
		}
		return array(
			'current' => $next,
			'result'  => $result,
		);
	}
	private static function state( string $raw ): ?BindingState {
		try {
			return BindingState::rehydrate( json_decode( $raw, true, 64, JSON_THROW_ON_ERROR ) );
		} catch ( \JsonException | InvalidArgumentException ) {
			return null; }
	}
	private static function same( BindingState $left, BindingState $right ): bool {
		return hash_equals( self::json( $left->to_array() ) ?? '', self::json( $right->to_array() ) ?? '' );
	}
	private static function same_target( BindingRecord $left, BindingRecord $right ): bool {
		$left_facts  = $left->to_array();
		$right_facts = $right->to_array();
		return $left_facts['network_id'] === $right_facts['network_id']
			&& hash_equals( $left_facts['target_type'], $right_facts['target_type'] )
			&& hash_equals( $left_facts['installed_package_identity'], $right_facts['installed_package_identity'] );
	}
	private static function at_limit( BindingState $state ): bool {
		return BindingState::MAX_SAFE_INTEGER === $state->binding_generation()
			|| BindingState::MAX_SAFE_INTEGER === $state->fence_epoch();
	}
	private static function claim( BindingState $state, mixed $claim ): bool {
		return is_array( $claim ) && array_keys( $claim ) === self::CLAIM
			&& is_int( $claim['binding_generation'] ) && is_string( $claim['binding_hash'] )
			&& is_int( $claim['lease_deadline'] ) && is_string( $claim['owner_token'] )
			&& $claim['binding_generation'] === $state->binding_generation()
			&& $claim['lease_deadline'] === $state->lease_deadline()
			&& hash_equals( $claim['binding_hash'], $state->binding()->binding_hash() )
			&& hash_equals( $claim['owner_token'], $state->owner_token() );
	}
	private static function time( object $wpdb ): ?int {
		if ( ! is_callable( array( $wpdb, 'get_var' ) ) ) {
			return null;
		}
		$value = $wpdb->get_var( 'SELECT UNIX_TIMESTAMP()' );
		if ( is_int( $value ) && $value >= 0 && $value <= BindingState::MAX_SAFE_INTEGER ) {
			return $value;
		}
		return is_string( $value ) && 1 === preg_match( '/\A[0-9]+\z/D', $value ) && (int) $value <= BindingState::MAX_SAFE_INTEGER ? (int) $value : null;
	}
	private static function read( object $wpdb, string $name ): ?string {
		if ( ! is_callable( array( $wpdb, 'prepare' ) ) || ! is_callable( array( $wpdb, 'get_var' ) ) ) {
			return null;
		}
		$table = self::options_table( $wpdb );
		if ( null === $table ) {
			return null;
		}
		$sql   = $wpdb->prepare( "SELECT option_value FROM {$table} WHERE option_name = %s LIMIT 1", $name );
		$value = $wpdb->get_var( $sql );
		return is_string( $value ) && strlen( $value ) <= self::MAX_JSON ? $value : null;
	}
	private static function same_raw( object $wpdb, string $name, string $expected ): bool {
		$actual = self::read( $wpdb, $name );
		return is_string( $actual ) && hash_equals( $expected, $actual );
	}
	private static function insert( object $wpdb, string $name, string $value ): bool {
		if ( ! is_callable( array( $wpdb, 'prepare' ) ) || ! is_callable( array( $wpdb, 'query' ) ) ) {
			return false;
		}
		$table = self::options_table( $wpdb );
		if ( null === $table ) {
			return false;
		} $sql = "INSERT INTO {$table} (option_name,option_value,autoload) VALUES (%s,%s,'no')";
		return 1 === $wpdb->query( $wpdb->prepare( $sql, $name, $value ) );
	}
	private static function cas( object $wpdb, string $name, string $old, string $replacement, int $deadline, bool $expired = false ): bool {
		if ( ! is_callable( array( $wpdb, 'prepare' ) ) || ! is_callable( array( $wpdb, 'query' ) ) ) {
			return false;
		}
		$table = self::options_table( $wpdb );
		if ( null === $table ) {
			return false;
		} $operator = $expired ? '>' : '<=';
		$sql        = "UPDATE {$table} SET option_value = %s WHERE option_name = %s AND BINARY option_value = BINARY %s AND UNIX_TIMESTAMP() {$operator} %d";
		return 1 === $wpdb->query( $wpdb->prepare( $sql, $replacement, $name, $old, $deadline ) );
	}
	/** @param array<string,mixed> $value */
	private static function json( array $value ): ?string {
		try {
			$json = json_encode( $value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); } catch ( \JsonException ) {
			return null; }
			return strlen( $json ) <= self::MAX_JSON ? $json : null;
	}
	private static function database( object $wpdb ): bool {
		return null !== self::options_table( $wpdb )
			&& is_callable( array( $wpdb, 'prepare' ) ) && is_callable( array( $wpdb, 'query' ) )
			&& is_callable( array( $wpdb, 'get_var' ) );
	}
	private static function options_table( object $wpdb ): ?string {
		if ( property_exists( $wpdb, 'base_prefix' ) ) {
			return is_string( $wpdb->base_prefix ) && 1 === preg_match( '/\A[A-Za-z0-9_]*\z/D', $wpdb->base_prefix ) ? $wpdb->base_prefix . 'options' : null;
		}
		return isset( $wpdb->options ) && is_string( $wpdb->options ) && 1 === preg_match( '/\A[A-Za-z0-9_]+\z/D', $wpdb->options ) ? $wpdb->options : null;
	}
	private static function hash( mixed $value ): bool {
		return is_string( $value ) && 1 === preg_match( '/\A[a-f0-9]{64}\z/D', $value ); }
	/** @return array{current:BindingState|null,result:'binding_fence_lost'} */
	private static function lost( ?BindingState $current = null ): array {
		return array(
			'current' => $current,
			'result'  => 'binding_fence_lost',
		);
	}
}
