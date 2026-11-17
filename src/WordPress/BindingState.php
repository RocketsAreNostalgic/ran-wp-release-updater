<?php

declare(strict_types=1);

namespace RAN\WPReleaseUpdater\V1\WordPress;

use InvalidArgumentException;
use RAN\WPReleaseUpdater\V1\Contract\BindingRecord;
use RAN\WPReleaseUpdater\V1\Runtime\RequestProtocolValidator;

final readonly class BindingState {
	public const MAX_SAFE_INTEGER = 9007199254740991;
	private const KEYS            = array( 'binding', 'binding_generation', 'fence_epoch', 'lease_deadline', 'owner_token', 'state_schema' );
	/** @param array<string,mixed>|null $last_native_outcome */
	private function __construct( private BindingRecord $binding, private int $generation, private int $epoch, private int $deadline, private string $owner, private ?array $last_native_outcome ) {}
	/** @param array<string,mixed>|null $last_native_outcome */
	public static function create( BindingRecord $binding, mixed $owner, mixed $deadline, int $generation = 1, int $epoch = 1, ?array $last_native_outcome = null ): self {
		if ( ! self::hash( $owner ) || ! self::number( $deadline ) || ! self::number( $generation ) || ! self::number( $epoch ) || ! RequestProtocolValidator::valid_native_outcome( $last_native_outcome ) ) {
			throw new InvalidArgumentException( 'The binding state is invalid.' );
		}
		return new self( BindingRecord::rehydrate( $binding->to_array() ), $generation, $epoch, $deadline, $owner, $last_native_outcome );
	}
	public static function rehydrate( mixed $value ): self {
		if ( ! is_array( $value ) || ! self::closed( $value ) || ! in_array( $value['state_schema'], array( 1, 2 ), true ) || ( null !== ( $value['last_native_outcome'] ?? null ) && ! is_array( $value['last_native_outcome'] ) ) ) {
			throw new InvalidArgumentException( 'The binding state is invalid.' );
		}
		return self::create(
			BindingRecord::rehydrate( $value['binding'] ),
			$value['owner_token'],
			$value['lease_deadline'],
			$value['binding_generation'],
			$value['fence_epoch'],
			$value['last_native_outcome'] ?? null
		);
	}
	public function binding(): BindingRecord {
		return $this->binding; }
	public function binding_generation(): int {
		return $this->generation; }
	public function fence_epoch(): int {
		return $this->epoch; }
	public function lease_deadline(): int {
		return $this->deadline; }
	public function owner_token(): string {
		return $this->owner; }
	/** @return array<string,mixed>|null */
	public function last_native_outcome(): ?array {
		return $this->last_native_outcome; }
	/** @return array<string,mixed> */ public function to_array(): array {
		return array(
			'binding'             => $this->binding->to_array(),
			'binding_generation'  => $this->generation,
			'fence_epoch'         => $this->epoch,
			'lease_deadline'      => $this->deadline,
			'owner_token'         => $this->owner,
			'state_schema'        => 2,
			'last_native_outcome' => $this->last_native_outcome,
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
		if ( 2 === ( $value['state_schema'] ?? null ) ) {
			$expected[] = 'last_native_outcome';
		}
		sort( $expected, SORT_STRING );
		return $keys === $expected;
	}
}
