<?php

declare(strict_types=1);

namespace RAN\WPReleaseUpdater\V1\Contract;

use InvalidArgumentException;

final readonly class BindingRecord
{
    private const FACT_KEYS = array( 'canonical_repository_locator', 'canonical_update_uri', 'installed_package_identity', 'php_runtime_version', 'provider_code',
        'release_channel', 'stable_repository_identity', 'target_type', 'update_policy', 'wordpress_runtime_version' );
    private const KEYS = array( 'canonical_repository_locator', 'canonical_update_uri', 'installed_package_identity', 'php_runtime_version', 'provider_code',
        'release_channel', 'stable_repository_identity', 'target_type', 'update_policy', 'wordpress_runtime_version', 'binding_hash' );
    private const DESCRIPTOR_PAIRS = array( 'installed_package_identity' => 'installed_package_identity', 'target_type' => 'target_type', 'provider_code' => 'provider_code',
        'channel' => 'release_channel', 'canonical_update_uri' => 'canonical_update_uri', 'repository_locator' => 'canonical_repository_locator',
        'repository_identity' => 'stable_repository_identity' );
    /** @param array<string,mixed> $facts */
    private function __construct(private array $facts, private string $bindingHash)
    {
    }
    public static function targetFenceKey(mixed $value): string
    {
        if (! self::exactKeys($value, array( 'installed_package_identity' )) || ! IdentityDescriptor::isBoundedOpaqueIdentity($value['installed_package_identity'], 255)) {
            throw new InvalidArgumentException('The target fence is invalid.');
        } return hash('sha256', self::canonicalJson($value));
    }
    public static function create(mixed $facts): self
    {
        if (! self::validFacts($facts)) {
            throw new InvalidArgumentException('The binding facts are invalid.');
        } $facts = self::ordered($facts, self::FACT_KEYS);
        return new self($facts, hash('sha256', self::canonicalJson($facts)));
    }
    public static function rehydrate(mixed $value): self
    {
        $facts = self::exactKeys($value, self::KEYS) ? self::ordered($value, self::FACT_KEYS) : null;
        if (! is_array($facts) || ! self::validFacts($facts) || ! self::isSha256($value['binding_hash'])) {
            throw new InvalidArgumentException('The binding record is invalid.');
        } $record = self::create($facts);
        if (! hash_equals($record->bindingHash, $value['binding_hash'])) {
            throw new InvalidArgumentException('The binding hash is invalid.');
        } return $record;
    }
    public static function assertDescriptorBinding(IdentityDescriptor $descriptor, self $binding): IdentityDescriptor
    {
        $facts = $descriptor->toArray();
        foreach (self::DESCRIPTOR_PAIRS as $descriptorKey => $bindingKey) {
            if (! hash_equals((string) $facts[ $descriptorKey ], (string) $binding->facts[ $bindingKey ])) {
                throw new InvalidArgumentException('The descriptor binding is invalid.');
            }
        } return $descriptor;
    }
    /** @return array<string,mixed> */ public function toArray(): array
    {
        return array_merge($this->facts, array( 'binding_hash' => $this->bindingHash ));
    }
    public function bindingHash(): string
    {
        return $this->bindingHash;
    }
    private static function validFacts(mixed $value): bool
    {
        return self::exactKeys($value, self::FACT_KEYS) && ( 'plugin' === $value['target_type'] || 'theme' === $value['target_type'] )
            && IdentityDescriptor::isBoundedOpaqueIdentity($value['installed_package_identity'], 255) && IdentityDescriptor::isProviderCode($value['provider_code'])
            && IdentityDescriptor::isBoundedOpaqueIdentity($value['canonical_repository_locator'], 255)
            && IdentityDescriptor::isBoundedOpaqueIdentity($value['stable_repository_identity']) && is_string($value['canonical_update_uri'])
            && CanonicalUpdateUri::canonicalize($value['canonical_update_uri']) === $value['canonical_update_uri']
            && in_array($value['release_channel'], array( 'stable', 'prerelease' ), true)
            && in_array($value['update_policy'], array( 'disabled', 'forced-off', 'manual', 'automatic' ), true)
            && IdentityDescriptor::isBoundedOpaqueIdentity($value['php_runtime_version'], 64)
            && IdentityDescriptor::isBoundedOpaqueIdentity($value['wordpress_runtime_version'], 64);
    }
    /** @param array<string,mixed> $value @param list<string> $keys @return array<string,mixed> */ private static function ordered(array $value, array $keys): array
    {
        $ordered = array();
        foreach ($keys as $key) {
            $ordered[ $key ] = $value[ $key ];
        } return $ordered;
    }
    private static function canonicalJson(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    private static function exactKeys(mixed $value, array $keys): bool
    {
        if (! is_array($value) || count($value) !== count($keys)) {
            return false;
        } foreach ($keys as $key) {
            if (! array_key_exists($key, $value)) {
                    return false;
            }
        } return true;
    }
    private static function isSha256(mixed $value): bool
    {
        return is_string($value) && 1 === preg_match('/\A[a-f0-9]{64}\z/D', $value);
    }
}
