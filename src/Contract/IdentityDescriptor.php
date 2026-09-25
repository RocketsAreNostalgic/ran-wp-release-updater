<?php

declare(strict_types=1);

namespace RAN\WPReleaseUpdater\V1\Contract;

use InvalidArgumentException;

final readonly class IdentityDescriptor {

	private const ASSURANCE_FACT_KEYS = array(
		'exact_artifact_identity',
		'exact_commit_identity',
		'exact_reacquisition_supported',
		'exact_release_identity',
		'provenance_verified',
		'publication_immutable',
		'repository_identity_stable',
		'trusted_digest_source',
	);
	private const FACT_KEYS           = array(
		'artifact_filename',
		'artifact_identity',
		'artifact_sha256',
		'artifact_size',
		'assurance_facts',
		'canonical_update_uri',
		'channel',
		'commit_identity',
		'installed_package_identity',
		'prerelease',
		'provider_code',
		'release_identity',
		'repository_identity',
		'repository_locator',
		'tag',
		'target_type',
		'version',
	);
	private const DESCRIPTOR_KEYS     = array(
		'artifact_filename',
		'artifact_identity',
		'artifact_sha256',
		'artifact_size',
		'assurance_facts',
		'canonical_update_uri',
		'channel',
		'commit_identity',
		'fingerprint',
		'installed_package_identity',
		'prerelease',
		'provider_code',
		'release_identity',
		'repository_identity',
		'repository_locator',
		'tag',
		'target_type',
		'version',
	);
	private const TARGET_KEYS         = array( 'installed_package_identity', 'provider_code', 'repository_identity', 'repository_locator', 'target_type' );

	/** @param array<string, mixed> $facts */
	private function __construct( private array $facts, private string $descriptor_fingerprint ) {
	}

	public static function create( mixed $facts ): self {
		if ( ! self::valid_facts( $facts ) ) {
			throw new InvalidArgumentException( 'The identity descriptor facts are invalid.' );
		}
		$canonical = self::canonical_facts( $facts );
		return new self( $canonical, self::fingerprint_facts( $canonical ) );
	}

	public static function fingerprint( mixed $facts ): string {
		if ( ! self::valid_facts( $facts ) ) {
			throw new InvalidArgumentException( 'The identity descriptor facts are invalid.' );
		}
		return self::fingerprint_facts( self::canonical_facts( $facts ) );
	}

	public static function rehydrate( mixed $value, mixed $expected_target = null ): self {
		$has_expected_target = 2 === func_num_args();
		if ( ! self::exact_keys( $value, self::DESCRIPTOR_KEYS ) || ! self::valid_fact_values( $value ) || ! is_string( $value['fingerprint'] ) ) {
			throw new InvalidArgumentException( 'The identity descriptor is invalid.' );
		}
		$canonical   = self::canonical_facts( $value );
		$fingerprint = self::fingerprint_facts( $canonical );
		if ( ! hash_equals( $fingerprint, $value['fingerprint'] ) ) {
			throw new InvalidArgumentException( 'The identity descriptor fingerprint is invalid.' );
		}
		$descriptor = new self( $canonical, $fingerprint );
		if ( $has_expected_target ) {
			self::assert_target_binding( $descriptor, $expected_target );
		}
		return $descriptor;
	}

	public static function assert_target_binding( self $descriptor, mixed $target ): self {
		if ( ! self::valid_target( $target ) ) {
			throw new InvalidArgumentException( 'The target binding is invalid.' );
		}
		foreach ( self::TARGET_KEYS as $key ) {
			if ( $descriptor->facts[ $key ] !== $target[ $key ] ) {
				throw new InvalidArgumentException( 'The identity descriptor does not bind the requested target.' );
			}
		}
		return $descriptor;
	}

	/** @return array<string, mixed> */
	public function to_array(): array {
		return array_merge( self::canonical_facts( $this->facts ), array( 'fingerprint' => $this->descriptor_fingerprint ) );
	}

	public function fingerprint_value(): string {
		return $this->descriptor_fingerprint;
	}
	public function release_identity(): string {
		return $this->facts['release_identity'];
	}

	public static function is_bounded_opaque_identity( mixed $value, int $maximum_bytes = 191 ): bool {
		return is_string( $value ) && $maximum_bytes >= 1 && '' !== $value && strlen( $value ) <= $maximum_bytes
			&& 1 === preg_match( '//u', $value ) && 1 !== preg_match( '/[\p{Cc}\p{Cf}\p{Z}\p{White_Space}]/u', $value );
	}

	public static function is_provider_code( mixed $value ): bool {
		return is_string( $value ) && 1 === preg_match( '/\A[a-z][a-z0-9_-]{0,31}\z/D', $value );
	}
	public static function is_exact_tag( mixed $value ): bool {
		return self::is_bounded_opaque_identity( $value );
	}

	private static function valid_facts( mixed $value ): bool {
		return self::exact_keys( $value, self::FACT_KEYS ) && self::valid_fact_values( $value );
	}

	/** @param array<string, mixed> $value */
	private static function valid_fact_values( array $value ): bool {
		return ( 'plugin' === $value['target_type'] || 'theme' === $value['target_type'] )
			&& self::is_bounded_opaque_identity( $value['installed_package_identity'], 255 ) && self::is_provider_code( $value['provider_code'] )
			&& self::is_bounded_opaque_identity( $value['repository_identity'] ) && self::is_bounded_opaque_identity( $value['repository_locator'], 255 )
			&& self::is_bounded_opaque_identity( $value['release_identity'] ) && self::is_exact_tag( $value['tag'] ) && self::is_bounded_opaque_identity( $value['commit_identity'] )
			&& self::is_bounded_opaque_identity( $value['artifact_identity'] ) && is_string( $value['canonical_update_uri'] )
			&& CanonicalUpdateUri::canonicalize( $value['canonical_update_uri'] ) === $value['canonical_update_uri'] && is_bool( $value['prerelease'] )
			&& self::valid_assurance_facts( $value['assurance_facts'] ) && ( 'stable' === $value['channel'] || 'prerelease' === $value['channel'] )
			&& ( 'stable' !== $value['channel'] || ! $value['prerelease'] ) && is_string( $value['version'] ) && null !== ReleaseVersion::normalize( $value['version'] )
			&& is_string( $value['artifact_filename'] ) && 1 === preg_match( '/\A[A-Za-z0-9][A-Za-z0-9._-]{0,215}\.zip\z/Di', $value['artifact_filename'] )
			&& is_int( $value['artifact_size'] ) && $value['artifact_size'] >= 1 && self::is_sha256( $value['artifact_sha256'] );
	}

	private static function valid_assurance_facts( mixed $value ): bool {
		if ( ! self::exact_keys( $value, self::ASSURANCE_FACT_KEYS ) ) {
			return false;
		}
		foreach ( self::ASSURANCE_FACT_KEYS as $key ) {
			if ( ! is_bool( $value[ $key ] ) ) {
				return false;
			}
		}
		return true;
	}

	private static function valid_target( mixed $value ): bool {
		return self::exact_keys( $value, self::TARGET_KEYS ) && ( 'plugin' === $value['target_type'] || 'theme' === $value['target_type'] )
			&& self::is_bounded_opaque_identity( $value['installed_package_identity'], 255 ) && self::is_provider_code( $value['provider_code'] )
			&& self::is_bounded_opaque_identity( $value['repository_identity'] ) && self::is_bounded_opaque_identity( $value['repository_locator'], 255 );
	}

	/**
	 * @param array<string,mixed> $facts
	 * @return array<string,mixed>
	 */
	private static function canonical_facts( array $facts ): array {
		$canonical = array();
		foreach ( self::FACT_KEYS as $key ) {
			if ( 'assurance_facts' === $key ) {
				$canonical[ $key ] = array();
				foreach ( self::ASSURANCE_FACT_KEYS as $fact ) {
					$canonical[ $key ][ $fact ] = $facts[ $key ][ $fact ];
				}
			} else {
				$canonical[ $key ] = $facts[ $key ];
			}
		}
		return $canonical;
	}

	/** @param array<string, mixed> $facts */
	private static function fingerprint_facts( array $facts ): string {
		return 'v1:' . hash( 'sha256', self::canonical_json( $facts ) );
	}
	/** @param array<string,mixed> $value */
	private static function canonical_json( array $value ): string {
		return json_encode( $value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	/** @param list<string> $keys */
	private static function exact_keys( mixed $value, array $keys ): bool {
		if ( ! is_array( $value ) || count( $value ) !== count( $keys ) ) {
			return false;
		}
		foreach ( $keys as $key ) {
			if ( ! array_key_exists( $key, $value ) ) {
				return false;
			}
		}
		return true;
	}

	private static function is_sha256( mixed $value ): bool {
		return is_string( $value ) && 1 === preg_match( '/\A[a-f0-9]{64}\z/D', $value );
	}
}
