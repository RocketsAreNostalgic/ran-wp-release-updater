<?php

declare(strict_types=1);

namespace RAN\WPReleaseUpdater\V1\Contract;

use InvalidArgumentException;
use RAN\WPReleaseUpdater\V1\Archive\PackageIdentityValidator;
use RAN\WPReleaseUpdater\V1\Archive\ValidatedPackage;
use RAN\WPReleaseUpdater\V1\WordPress\BindingState;
use WeakMap;

final readonly class AcquisitionReceipt {

	private const KEYS = array( 'archive_identity_verified', 'binding_generation', 'binding_hash', 'descriptor_fingerprint', 'local_sha256', 'opaque_artifact_identity', 'opaque_release_identity', 'package_identity_verified', 'provider_code', 'receipt_schema' );
	/** @param array<string, mixed> $facts */
	private function __construct( private array $facts ) {}

	public static function issue( BindingState $state, IdentityDescriptor $descriptor, PackageIdentityValidator $validator, ValidatedPackage $package, int $now ): self {
		if ( $now < 0 || $now > BindingState::MAX_SAFE_INTEGER || $now > $state->leaseDeadline() ) throw new InvalidArgumentException( 'The acquisition receipt is invalid.' );
		try { $binding = $state->binding(); BindingRecord::assertDescriptorBinding( $descriptor, $binding ); $proof = $validator->consumeReceiptProof( $package, $descriptor ); } catch ( InvalidArgumentException ) { throw new InvalidArgumentException( 'The acquisition receipt is invalid.' ); }
		$descriptorFacts = $descriptor->toArray();
		$facts = array( 'archive_identity_verified' => true, 'binding_generation' => $state->bindingGeneration(), 'binding_hash' => $binding->bindingHash(), 'descriptor_fingerprint' => $descriptor->fingerprintValue(), 'local_sha256' => $proof['sha256'], 'opaque_artifact_identity' => $descriptorFacts['artifact_identity'], 'opaque_release_identity' => $descriptor->releaseIdentity(), 'package_identity_verified' => true, 'provider_code' => $descriptorFacts['provider_code'], 'receipt_schema' => 1 );
		$receipt = new self( $facts );
		self::issued()[ $receipt ] = array( 'lease_deadline' => $state->leaseDeadline(), 'manifest_entry_count' => $proof['manifest_entry_count'], 'manifest_expanded_bytes' => $proof['manifest_expanded_bytes'], 'manifest_hash' => $proof['manifest_hash'], 'owner_token' => $state->ownerToken() );
		return $receipt;
	}

	public static function acceptFresh( mixed $receipt, BindingState $state, IdentityDescriptor $descriptor, int $now ): self {
		if ( ! $receipt instanceof self || ! isset( self::issued()[ $receipt ] ) ) throw new InvalidArgumentException( 'The acquisition receipt is stale.' );
		$incarnation = self::issued()[ $receipt ];
		if ( ! hash_equals( $incarnation['owner_token'], $state->ownerToken() ) || $incarnation['lease_deadline'] !== $state->leaseDeadline() ) throw new InvalidArgumentException( 'The acquisition receipt is stale.' );
		if ( ! self::valid( $receipt->facts, $state, $descriptor, $now ) ) throw new InvalidArgumentException( 'The acquisition receipt is invalid.' );
		unset( self::issued()[ $receipt ] );
		return $receipt;
	}

	/** Verify a live receipt without consuming the one-use completion proof. */
	public static function assertFresh( mixed $receipt, BindingState $state, IdentityDescriptor $descriptor, int $now ): self {
		if ( ! $receipt instanceof self || ! isset( self::issued()[ $receipt ] ) ) throw new InvalidArgumentException( 'The acquisition receipt is stale.' );
		$incarnation = self::issued()[ $receipt ];
		if ( ! hash_equals( $incarnation['owner_token'], $state->ownerToken() ) || $incarnation['lease_deadline'] !== $state->leaseDeadline() || ! self::valid( $receipt->facts, $state, $descriptor, $now ) ) throw new InvalidArgumentException( 'The acquisition receipt is invalid.' );
		return $receipt;
	}

	/** Verify that an extracted or installed tree is byte-identical to the inspected archive inventory. */
	public static function assertArchiveManifest( mixed $receipt, BindingState $state, IdentityDescriptor $descriptor, int $now, mixed $manifestHash, mixed $entryCount, mixed $expandedBytes ): self {
		self::assertFresh( $receipt, $state, $descriptor, $now ); $incarnation = self::issued()[ $receipt ];
		if ( ! self::sha256( $manifestHash ) || ! is_int( $entryCount ) || $entryCount < 1 || ! is_int( $expandedBytes ) || $expandedBytes < 0 || $entryCount !== $incarnation['manifest_entry_count'] || $expandedBytes !== $incarnation['manifest_expanded_bytes'] || ! hash_equals( $incarnation['manifest_hash'], $manifestHash ) ) throw new InvalidArgumentException( 'The acquisition receipt archive manifest is invalid.' );
		return $receipt;
	}

	private static function valid( mixed $value, BindingState $state, IdentityDescriptor $descriptor, int $now ): bool {
		if ( ! self::exactKeys( $value ) || $now < 0 || $now > BindingState::MAX_SAFE_INTEGER || $now > $state->leaseDeadline() || 1 !== $value['receipt_schema'] || true !== $value['archive_identity_verified'] || true !== $value['package_identity_verified'] || ! self::sha256( $value['local_sha256'] ) ) return false;
		try {
			$binding = $state->binding();
			BindingRecord::assertDescriptorBinding( $descriptor, $binding );
		} catch ( InvalidArgumentException ) { return false; }
		return $value['binding_generation'] === $state->bindingGeneration()
			&& self::equal( $value['binding_hash'], $binding->bindingHash() )
			&& self::equal( $value['descriptor_fingerprint'], $descriptor->fingerprintValue() )
			&& $value['provider_code'] === $descriptor->toArray()['provider_code']
			&& $value['opaque_release_identity'] === $descriptor->releaseIdentity()
			&& $value['opaque_artifact_identity'] === $descriptor->toArray()['artifact_identity']
			&& self::equal( $value['local_sha256'], $descriptor->toArray()['artifact_sha256'] );
	}

	private static function equal( mixed $left, mixed $right ): bool { return is_string( $left ) && is_string( $right ) && hash_equals( $left, $right ); }
	private static function sha256( mixed $value ): bool { return is_string( $value ) && 1 === preg_match( '/\A[a-f0-9]{64}\z/D', $value ); }
	private static function issued(): WeakMap { static $issued = null; return $issued ??= new WeakMap(); }
	private static function exactKeys( mixed $value ): bool { if ( ! is_array( $value ) || count( $value ) !== count( self::KEYS ) ) return false; foreach ( self::KEYS as $key ) if ( ! array_key_exists( $key, $value ) ) return false; return true; }
}
