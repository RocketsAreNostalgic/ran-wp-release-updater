<?php

declare(strict_types=1);

namespace RAN\WPReleaseUpdater\V1\WordPress;

use InvalidArgumentException;
use RAN\WPReleaseUpdater\V1\Contract\AcquisitionReceipt;
use RAN\WPReleaseUpdater\V1\Contract\BindingRecord;
use RAN\WPReleaseUpdater\V1\Contract\IdentityDescriptor;

final readonly class BindingState {
	public const MAX_SAFE_INTEGER = 9007199254740991;
	private const KEYS = array( 'binding', 'binding_generation', 'fence_epoch', 'lease_deadline', 'owner_token', 'state_schema' );
	private function __construct( private BindingRecord $binding, private int $generation, private int $epoch, private int $deadline, private string $owner ) {}
	public static function create( BindingRecord $binding, mixed $owner, mixed $deadline, int $generation = 1, int $epoch = 1 ): self {
		if ( ! self::hash( $owner ) || ! self::number( $deadline ) || ! self::number( $generation ) || ! self::number( $epoch ) ) {
			throw new InvalidArgumentException( 'The binding state is invalid.' );
		}
		return new self( BindingRecord::rehydrate( $binding->toArray() ), $generation, $epoch, $deadline, $owner );
	}
	public static function rehydrate( mixed $value ): self {
		if ( ! is_array( $value ) || ! self::closed( $value ) || 1 !== $value['state_schema'] ) {
			throw new InvalidArgumentException( 'The binding state is invalid.' );
		}
		return self::create(
			BindingRecord::rehydrate( $value['binding'] ), $value['owner_token'], $value['lease_deadline'], $value['binding_generation'], $value['fence_epoch']
		);
	}
	public function binding(): BindingRecord { return $this->binding; }
	public function bindingGeneration(): int { return $this->generation; }
	public function fenceEpoch(): int { return $this->epoch; }
	public function leaseDeadline(): int { return $this->deadline; }
	public function ownerToken(): string { return $this->owner; }
	/** @return array<string,mixed> */ public function toArray(): array {
		return array(
			'binding' => $this->binding->toArray(), 'binding_generation' => $this->generation,
			'fence_epoch' => $this->epoch, 'lease_deadline' => $this->deadline,
			'owner_token' => $this->owner, 'state_schema' => 1,
		);
	}
	private static function number( mixed $value ): bool { return is_int( $value ) && $value >= 1 && $value <= self::MAX_SAFE_INTEGER; }
	private static function hash( mixed $value ): bool { return is_string( $value ) && 1 === preg_match( '/\A[a-f0-9]{64}\z/D', $value ); }
	private static function closed( array $value ): bool {
		$keys = array_keys( $value ); sort( $keys, SORT_STRING );
		$expected = self::KEYS; sort( $expected, SORT_STRING );
		return $keys === $expected;
	}
}
final class ReleaseOperationCoordinator {
	private const PREFIX = 'ran_wp_release_updater_target_v1_';
	private const CLAIM = array( 'binding_generation', 'binding_hash', 'lease_deadline', 'owner_token' );
	private const MAX_JSON = 16384;
	/** @return array{current:BindingState|null,result:string} */
	public static function claimPersistentBindingState( object $wpdb, BindingRecord $binding, mixed $owner, mixed $seconds ): array {
		if ( ! self::database( $wpdb ) || ! self::hash( $owner ) || ! is_int( $seconds ) || $seconds < 1 ) return self::lost();
		$now = self::time( $wpdb );
		if ( null === $now || $seconds > BindingState::MAX_SAFE_INTEGER - $now ) return self::lost();
		$name = self::name( $binding ); $raw = self::read( $wpdb, $name );
		if ( null === $raw ) return self::insertClaim( $wpdb, $name, $binding, $owner, $now + $seconds );
		$current = self::state( $raw );
		if ( null === $current || ! self::sameTarget( $binding, $current->binding() ) || $now <= $current->leaseDeadline() || self::atLimit( $current ) ) {
			return self::lost( $current );
		}
		try {
			$next = BindingState::create( $binding, $owner, $now + $seconds, $current->bindingGeneration() + 1, $current->fenceEpoch() + 1 );
		} catch ( InvalidArgumentException ) {
			return self::lost( $current );
		}
		$json = self::json( $next->toArray() );
		if ( null === $json || ! self::cas( $wpdb, $name, $raw, $json, $current->leaseDeadline(), true ) || ! self::sameRaw( $wpdb, $name, $json ) ) return self::lost( $current );
		return array( 'current' => $next, 'result' => 'claimed' );
	}
	/** @return array{current:BindingState|null,result:string} */
	public static function renewPersistentBindingState( object $wpdb, BindingState $expected, mixed $claim, int $seconds ): array {
		return self::transitionPersistentBindingState( $wpdb, $expected, $claim, $seconds, 'renewed' );
	}
	/** @return array{current:BindingState|null,result:string} */
	public static function releasePersistentBindingState( object $wpdb, BindingState $expected, mixed $claim ): array {
		return self::transitionPersistentBindingState( $wpdb, $expected, $claim, null, 'released' );
	}
	/** @return array{current:BindingState|null,now?:int,result:string} */
	public static function verifyPersistentBindingState( object $wpdb, BindingState $expected, mixed $claim ): array {
		if ( ! self::database( $wpdb ) ) return self::lost();
		$now = self::time( $wpdb ); $name = self::name( $expected->binding() ); $raw = self::read( $wpdb, $name ); $current = null === $raw ? null : self::state( $raw );
		if ( null === $now || null === $current || ! self::same( $current, $expected )
			|| ! self::claim( $current, $claim ) || $now > $current->leaseDeadline()
			|| ! self::sameRaw( $wpdb, $name, $raw ) ) return self::lost( $current );
		return array( 'current' => $current, 'now' => $now, 'result' => 'verified' );
	}
	/** @return array{current:BindingState|null,now?:int,receipt?:AcquisitionReceipt,result:string} */
	public static function completePersistentInstall( object $wpdb, BindingState $expected, mixed $claim, mixed $receipt, IdentityDescriptor $descriptor ): array {
		$first = self::verifyPersistentBindingState( $wpdb, $expected, $claim );
		if ( 'verified' !== $first['result'] ) return $first;
		try {
			$accepted = AcquisitionReceipt::acceptFresh( $receipt, $first['current'], $descriptor, $first['now'] );
		} catch ( InvalidArgumentException ) {
			return self::lost( $first['current'] );
		}
		$last = self::verifyPersistentBindingState( $wpdb, $expected, $claim );
		if ( 'verified' !== $last['result'] ) return self::lost( $last['current'] );
		$released = self::releasePersistentBindingState( $wpdb, $expected, $claim );
		if ( 'released' !== $released['result'] ) return self::lost( $released['current'] );
		return array( 'current' => $released['current'], 'now' => $last['now'], 'receipt' => $accepted, 'result' => 'completed' );
	}
	private static function name( BindingRecord $binding ): string {
		$facts = $binding->toArray();
		return self::PREFIX . BindingRecord::targetFenceKey( array(
			'network_id' => $facts['network_id'],
			'target_type' => $facts['target_type'],
			'installed_package_identity' => $facts['installed_package_identity'],
		) );
	}
	/** @return array{current:BindingState|null,result:string} */
	private static function insertClaim( object $wpdb, string $name, BindingRecord $binding, string $owner, int $deadline ): array {
		try { $state = BindingState::create( $binding, $owner, $deadline ); } catch ( InvalidArgumentException ) { return self::lost(); }
		$json = self::json( $state->toArray() );
		if ( null === $json || ! self::insert( $wpdb, $name, $json ) || ! self::sameRaw( $wpdb, $name, $json ) ) return self::lost();
		return array( 'current' => $state, 'result' => 'claimed' );
	}
	/** @return array{current:BindingState|null,result:string} */
	private static function transitionPersistentBindingState( object $wpdb, BindingState $expected, mixed $claim, ?int $seconds, string $result ): array {
		if ( ! self::database( $wpdb ) || ( null !== $seconds && $seconds < 1 ) ) return self::lost();
		$now = self::time( $wpdb );
		if ( null === $now || ( null !== $seconds && $seconds > BindingState::MAX_SAFE_INTEGER - $now ) ) return self::lost();
		$name = self::name( $expected->binding() );
		$raw = self::read( $wpdb, $name );
		$current = null === $raw ? null : self::state( $raw );
		if ( null === $current || ! self::same( $current, $expected ) || ! self::claim( $current, $claim )
			|| $now > $current->leaseDeadline() || BindingState::MAX_SAFE_INTEGER === $current->fenceEpoch() ) return self::lost( $current );
		if ( null !== $seconds && BindingState::MAX_SAFE_INTEGER === $current->leaseDeadline() ) return self::lost( $current );
		$deadline = null === $seconds ? 1 : max( $current->leaseDeadline() + 1, $now + $seconds );
		try {
			$next = BindingState::create( $current->binding(), $current->ownerToken(), $deadline, $current->bindingGeneration(), $current->fenceEpoch() + 1 );
		} catch ( InvalidArgumentException ) {
			return self::lost( $current );
		}
		$json = self::json( $next->toArray() );
		if ( null === $json || ! self::cas( $wpdb, $name, $raw, $json, $current->leaseDeadline() ) || ! self::sameRaw( $wpdb, $name, $json ) ) return self::lost( $current );
		return array( 'current' => $next, 'result' => $result );
	}
	private static function state( string $raw ): ?BindingState {
		try { return BindingState::rehydrate( json_decode( $raw, true, 64, JSON_THROW_ON_ERROR ) ); } catch ( \JsonException|InvalidArgumentException ) { return null; }
	}
	private static function same( BindingState $left, BindingState $right ): bool {
		return hash_equals( self::json( $left->toArray() ) ?? '', self::json( $right->toArray() ) ?? '' );
	}
	private static function sameTarget( BindingRecord $left, BindingRecord $right ): bool {
		$leftFacts = $left->toArray();
		$rightFacts = $right->toArray();
		return $leftFacts['network_id'] === $rightFacts['network_id']
			&& hash_equals( $leftFacts['target_type'], $rightFacts['target_type'] )
			&& hash_equals( $leftFacts['installed_package_identity'], $rightFacts['installed_package_identity'] );
	}
	private static function atLimit( BindingState $state ): bool { return BindingState::MAX_SAFE_INTEGER === $state->bindingGeneration()
			|| BindingState::MAX_SAFE_INTEGER === $state->fenceEpoch();
	}
	private static function claim( BindingState $state, mixed $claim ): bool {
		return is_array( $claim ) && array_keys( $claim ) === self::CLAIM
			&& is_int( $claim['binding_generation'] ) && is_string( $claim['binding_hash'] )
			&& is_int( $claim['lease_deadline'] ) && is_string( $claim['owner_token'] )
			&& $claim['binding_generation'] === $state->bindingGeneration()
			&& $claim['lease_deadline'] === $state->leaseDeadline()
			&& hash_equals( $claim['binding_hash'], $state->binding()->bindingHash() )
			&& hash_equals( $claim['owner_token'], $state->ownerToken() );
	}
	private static function time( object $wpdb ): ?int { $value = $wpdb->get_var( 'SELECT UNIX_TIMESTAMP()' );
		if ( is_int( $value ) && $value >= 0 && $value <= BindingState::MAX_SAFE_INTEGER ) return $value;
		return is_string( $value ) && 1 === preg_match( '/\A[0-9]+\z/D', $value ) && (int) $value <= BindingState::MAX_SAFE_INTEGER ? (int) $value : null;
	}
	private static function read( object $wpdb, string $name ): ?string {
		$table = self::optionsTable( $wpdb ); if ( null === $table ) return null;
		$sql = $wpdb->prepare( "SELECT option_value FROM {$table} WHERE option_name = %s LIMIT 1", $name ); $value = $wpdb->get_var( $sql );
		return is_string( $value ) && strlen( $value ) <= self::MAX_JSON ? $value : null;
	}
	private static function sameRaw( object $wpdb, string $name, string $expected ): bool { $actual = self::read( $wpdb, $name );
		return is_string( $actual ) && hash_equals( $expected, $actual );
	}
	private static function insert( object $wpdb, string $name, string $value ): bool { $table = self::optionsTable( $wpdb ); if ( null === $table ) return false; $sql = "INSERT INTO {$table} (option_name,option_value,autoload) VALUES (%s,%s,'no')";
		return 1 === $wpdb->query( $wpdb->prepare( $sql, $name, $value ) );
	}
	private static function cas( object $wpdb, string $name, string $old, string $new, int $deadline, bool $expired = false ): bool { $table = self::optionsTable( $wpdb ); if ( null === $table ) return false; $operator = $expired ? '>' : '<=';
		$sql = "UPDATE {$table} SET option_value = %s WHERE option_name = %s AND BINARY option_value = BINARY %s AND UNIX_TIMESTAMP() {$operator} %d";
		return 1 === $wpdb->query( $wpdb->prepare( $sql, $new, $name, $old, $deadline ) );
	}
	private static function json( array $value ): ?string { try { $json = json_encode( $value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); }
		catch ( \JsonException ) { return null; }
		return strlen( $json ) <= self::MAX_JSON ? $json : null;
	}
	private static function database( object $wpdb ): bool { return null !== self::optionsTable( $wpdb )
			&& is_callable( array( $wpdb, 'prepare' ) ) && is_callable( array( $wpdb, 'query' ) )
			&& is_callable( array( $wpdb, 'get_var' ) );
	}
	private static function optionsTable( object $wpdb ): ?string {
		if ( property_exists( $wpdb, 'base_prefix' ) ) {
			return is_string( $wpdb->base_prefix ) && 1 === preg_match( '/\A[A-Za-z0-9_]*\z/D', $wpdb->base_prefix ) ? $wpdb->base_prefix . 'options' : null;
		}
		return isset( $wpdb->options ) && is_string( $wpdb->options ) && 1 === preg_match( '/\A[A-Za-z0-9_]+\z/D', $wpdb->options ) ? $wpdb->options : null;
	}
	private static function hash( mixed $value ): bool { return is_string( $value ) && 1 === preg_match( '/\A[a-f0-9]{64}\z/D', $value ); }
	/** @return array{current:BindingState|null,result:string} */
	private static function lost( ?BindingState $current = null ): array { return array( 'current' => $current, 'result' => 'binding_fence_lost' );
	}
}
