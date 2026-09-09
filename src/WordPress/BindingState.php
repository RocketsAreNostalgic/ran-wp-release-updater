<?php

declare(strict_types=1);

namespace RAN\WPReleaseUpdater\V1\WordPress;

use InvalidArgumentException;
use RAN\WPReleaseUpdater\V1\Contract\BindingRecord;

final readonly class BindingState {
	public const MAX_SAFE_INTEGER = 9007199254740991;
	private const KEYS            = array( 'binding', 'binding_generation', 'fence_epoch', 'lease_deadline', 'owner_token', 'state_schema' );
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
			BindingRecord::rehydrate( $value['binding'] ),
			$value['owner_token'],
			$value['lease_deadline'],
			$value['binding_generation'],
			$value['fence_epoch']
		);
	}
	public function binding(): BindingRecord {
		return $this->binding; }
	public function bindingGeneration(): int {
		return $this->generation; }
	public function fenceEpoch(): int {
		return $this->epoch; }
	public function leaseDeadline(): int {
		return $this->deadline; }
	public function ownerToken(): string {
		return $this->owner; }
	/** @return array<string,mixed> */ public function toArray(): array {
		return array(
			'binding'            => $this->binding->toArray(),
			'binding_generation' => $this->generation,
			'fence_epoch'        => $this->epoch,
			'lease_deadline'     => $this->deadline,
			'owner_token'        => $this->owner,
			'state_schema'       => 1,
		);
	}
	private static function number( mixed $value ): bool {
		return is_int( $value ) && $value >= 1 && $value <= self::MAX_SAFE_INTEGER; }
	private static function hash( mixed $value ): bool {
		return is_string( $value ) && 1 === preg_match( '/\A[a-f0-9]{64}\z/D', $value ); }
	/** @param array<string,mixed> $value */
	private static function closed( array $value ): bool {
		$keys = array_keys( $value );
		sort( $keys, SORT_STRING );
		$expected = self::KEYS;
		sort( $expected, SORT_STRING );
		return $keys === $expected;
	}
}
