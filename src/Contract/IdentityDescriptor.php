<?php

declare(strict_types=1);

namespace RAN\WPReleaseUpdater\V1\Contract;

use InvalidArgumentException;

final readonly class IdentityDescriptor
{
    private const ASSURANCE_FACT_KEYS = array( 'exact_artifact_identity', 'exact_commit_identity', 'exact_reacquisition_supported', 'exact_release_identity', 'provenance_verified',
        'publication_immutable', 'repository_identity_stable', 'trusted_digest_source' );
    private const FACT_KEYS = array( 'artifact_filename', 'artifact_identity', 'artifact_sha256', 'artifact_size', 'assurance_facts', 'canonical_update_uri', 'channel',
        'commit_identity', 'installed_package_identity', 'prerelease', 'provider_code', 'release_identity', 'repository_identity',
        'repository_locator', 'tag', 'target_type', 'version' );
    private const DESCRIPTOR_KEYS = array( 'artifact_filename', 'artifact_identity', 'artifact_sha256', 'artifact_size', 'assurance_facts', 'canonical_update_uri', 'channel',
        'commit_identity', 'fingerprint', 'installed_package_identity', 'prerelease', 'provider_code', 'release_identity',
        'repository_identity', 'repository_locator', 'tag', 'target_type', 'version' );
    private const TARGET_KEYS = array( 'installed_package_identity', 'provider_code', 'repository_identity', 'repository_locator', 'target_type' );

    /** @param array<string, mixed> $facts */
    private function __construct(private array $facts, private string $descriptorFingerprint)
    {
    }

    public static function create(mixed $facts): self
    {
        if (! self::validFacts($facts)) {
            throw new InvalidArgumentException('The identity descriptor facts are invalid.');
        }
        $canonical = self::canonicalFacts($facts);
        return new self($canonical, self::fingerprintFacts($canonical));
    }

    public static function fingerprint(mixed $facts): string
    {
        if (! self::validFacts($facts)) {
            throw new InvalidArgumentException('The identity descriptor facts are invalid.');
        }
        return self::fingerprintFacts(self::canonicalFacts($facts));
    }

    public static function rehydrate(mixed $value, mixed $expectedTarget = null): self
    {
        $hasExpectedTarget = 2 === func_num_args();
        if (! self::exactKeys($value, self::DESCRIPTOR_KEYS) || ! self::validFactValues($value) || ! is_string($value['fingerprint'])) {
            throw new InvalidArgumentException('The identity descriptor is invalid.');
        }
        $canonical = self::canonicalFacts($value);
        $fingerprint = self::fingerprintFacts($canonical);
        if (! hash_equals($fingerprint, $value['fingerprint'])) {
            throw new InvalidArgumentException('The identity descriptor fingerprint is invalid.');
        }
        $descriptor = new self($canonical, $fingerprint);
        if ($hasExpectedTarget) {
            self::assertTargetBinding($descriptor, $expectedTarget);
        }
        return $descriptor;
    }

    public static function assertTargetBinding(self $descriptor, mixed $target): self
    {
        if (! self::validTarget($target)) {
            throw new InvalidArgumentException('The target binding is invalid.');
        }
        foreach (self::TARGET_KEYS as $key) {
            if ($descriptor->facts[ $key ] !== $target[ $key ]) {
                throw new InvalidArgumentException('The identity descriptor does not bind the requested target.');
            }
        }
        return $descriptor;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_merge(self::canonicalFacts($this->facts), array( 'fingerprint' => $this->descriptorFingerprint ));
    }

    public function fingerprintValue(): string
    {
        return $this->descriptorFingerprint;
    }
    public function releaseIdentity(): string
    {
        return $this->facts['release_identity'];
    }

    public static function isBoundedOpaqueIdentity(mixed $value, int $maximumBytes = 191): bool
    {
        return is_string($value) && $maximumBytes >= 1 && '' !== $value && strlen($value) <= $maximumBytes
            && 1 === preg_match('//u', $value) && 1 !== preg_match('/[\p{Cc}\p{Cf}\p{Z}\p{White_Space}]/u', $value);
    }

    public static function isProviderCode(mixed $value): bool
    {
        return is_string($value) && 1 === preg_match('/\A[a-z][a-z0-9_-]{0,31}\z/D', $value);
    }
    public static function isExactTag(mixed $value): bool
    {
        return self::isBoundedOpaqueIdentity($value);
    }

    private static function validFacts(mixed $value): bool
    {
        return self::exactKeys($value, self::FACT_KEYS) && self::validFactValues($value);
    }

    /** @param array<string, mixed> $value */
    private static function validFactValues(array $value): bool
    {
        return ( 'plugin' === $value['target_type'] || 'theme' === $value['target_type'] )
            && self::isBoundedOpaqueIdentity($value['installed_package_identity'], 255) && self::isProviderCode($value['provider_code'])
            && self::isBoundedOpaqueIdentity($value['repository_identity']) && self::isBoundedOpaqueIdentity($value['repository_locator'], 255)
            && self::isBoundedOpaqueIdentity($value['release_identity']) && self::isExactTag($value['tag']) && self::isBoundedOpaqueIdentity($value['commit_identity'])
            && self::isBoundedOpaqueIdentity($value['artifact_identity']) && is_string($value['canonical_update_uri'])
            && CanonicalUpdateUri::canonicalize($value['canonical_update_uri']) === $value['canonical_update_uri'] && is_bool($value['prerelease'])
            && self::validAssuranceFacts($value['assurance_facts']) && ( 'stable' === $value['channel'] || 'prerelease' === $value['channel'] )
            && ( 'stable' !== $value['channel'] || ! $value['prerelease'] ) && is_string($value['version']) && null !== ReleaseVersion::normalize($value['version'])
            && is_string($value['artifact_filename']) && 1 === preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,215}\.zip\z/Di', $value['artifact_filename'])
            && is_int($value['artifact_size']) && $value['artifact_size'] >= 1 && self::isSha256($value['artifact_sha256']);
    }

    private static function validAssuranceFacts(mixed $value): bool
    {
        if (! self::exactKeys($value, self::ASSURANCE_FACT_KEYS)) {
            return false;
        }
        foreach (self::ASSURANCE_FACT_KEYS as $key) {
            if (! is_bool($value[ $key ])) {
                return false;
            }
        }
        return true;
    }

    private static function validTarget(mixed $value): bool
    {
        return self::exactKeys($value, self::TARGET_KEYS) && ( 'plugin' === $value['target_type'] || 'theme' === $value['target_type'] )
            && self::isBoundedOpaqueIdentity($value['installed_package_identity'], 255) && self::isProviderCode($value['provider_code'])
            && self::isBoundedOpaqueIdentity($value['repository_identity']) && self::isBoundedOpaqueIdentity($value['repository_locator'], 255);
    }

    /** @param array<string, mixed> $facts @return array<string, mixed> */
    private static function canonicalFacts(array $facts): array
    {
        $canonical = array();
        foreach (self::FACT_KEYS as $key) {
            if ('assurance_facts' === $key) {
                $canonical[ $key ] = array();
                foreach (self::ASSURANCE_FACT_KEYS as $fact) {
                    $canonical[ $key ][ $fact ] = $facts[ $key ][ $fact ];
                }
            } else {
                $canonical[ $key ] = $facts[ $key ];
            }
        }
        return $canonical;
    }

    /** @param array<string, mixed> $facts */
    private static function fingerprintFacts(array $facts): string
    {
        return 'v1:' . hash('sha256', self::canonicalJson($facts));
    }
    private static function canonicalJson(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function exactKeys(mixed $value, array $keys): bool
    {
        if (! is_array($value) || count($value) !== count($keys)) {
            return false;
        }
        foreach ($keys as $key) {
            if (! array_key_exists($key, $value)) {
                return false;
            }
        }
        return true;
    }

    private static function isSha256(mixed $value): bool
    {
        return is_string($value) && 1 === preg_match('/\A[a-f0-9]{64}\z/D', $value);
    }
}
