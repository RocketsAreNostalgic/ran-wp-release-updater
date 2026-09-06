<?php

declare(strict_types=1);

namespace RAN\WPReleaseUpdater\V1\Provider\GitHub;

use InvalidArgumentException;
use RAN\WPReleaseUpdater\V1\Contract\CanonicalUpdateUri;
use RAN\WPReleaseUpdater\V1\Contract\IdentityDescriptor;
use RAN\WPReleaseUpdater\V1\Contract\ReleaseVersion;

/** Immutable, path-free proof of one prospective GitHub release package. */
final readonly class ProspectiveReleaseInspection
{
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
	private const FACT_KEYS = array(
		'artifact_filename', 'artifact_identity', 'artifact_sha256', 'artifact_size',
		'assurance_facts', 'canonical_update_uri', 'channel', 'commit_identity',
		'main_file', 'package_root', 'php_runtime_version', 'release_identity',
		'repository_identity', 'repository_locator', 'tag', 'target_type', 'version',
		'wordpress_runtime_version',
	);
	private const KEYS = array(
		'artifact_filename', 'artifact_identity', 'artifact_sha256', 'artifact_size',
		'assurance_facts', 'canonical_update_uri', 'channel', 'commit_identity',
		'fingerprint', 'main_file', 'package_root', 'php_runtime_version',
		'release_identity', 'repository_identity', 'repository_locator', 'tag',
		'target_type', 'version', 'wordpress_runtime_version',
	);

	/** @param array<string, mixed> $facts */
	private function __construct(private array $facts, private string $fingerprint)
	{
	}

	public static function create(mixed $facts): self
	{
		if (! self::validFacts($facts)) {
			throw new InvalidArgumentException('The prospective GitHub release facts are invalid.');
		}
		$canonical = self::orderedFacts($facts);
		return new self($canonical, self::fingerprintFacts($canonical));
	}

	public static function rehydrate(mixed $value): self
	{
		if (! self::exactKeys($value, self::KEYS) || ! is_string($value['fingerprint'])) {
			throw new InvalidArgumentException('The prospective GitHub release inspection is invalid.');
		}
		$facts = array();
		foreach (self::FACT_KEYS as $key) {
			$facts[$key] = $value[$key];
		}
		$inspection = self::create($facts);
		if (! hash_equals($inspection->fingerprint, $value['fingerprint'])) {
			throw new InvalidArgumentException('The prospective GitHub release fingerprint is invalid.');
		}
		return $inspection;
	}

	/** @return array<string, mixed> */
	public function toArray(): array
	{
		$facts = $this->facts;
		$facts['fingerprint'] = $this->fingerprint;
		return self::ordered($facts, self::KEYS);
	}

	public function fingerprintValue(): string
	{
		return $this->fingerprint;
	}

	public function releaseIdentity(): string
	{
		return $this->facts['release_identity'];
	}

	public function tag(): string
	{
		return $this->facts['tag'];
	}

	/** @param array<string, mixed> $value */
	private static function validFacts(mixed $value): bool
	{
		if (! self::exactKeys($value, self::FACT_KEYS)) {
			return false;
		}
		$type = $value['target_type'];
		$root = $value['package_root'];
		$main = $value['main_file'];
		return ('plugin' === $type || 'theme' === $type)
			&& IdentityDescriptor::isBoundedOpaqueIdentity($value['repository_locator'], 255)
			&& IdentityDescriptor::isBoundedOpaqueIdentity($value['repository_identity'])
			&& IdentityDescriptor::isBoundedOpaqueIdentity($value['release_identity'])
			&& IdentityDescriptor::isExactTag($value['tag'])
			&& IdentityDescriptor::isBoundedOpaqueIdentity($value['commit_identity'])
			&& IdentityDescriptor::isBoundedOpaqueIdentity($value['artifact_identity'])
			&& is_string($value['artifact_filename'])
			&& 1 === preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,215}\.zip\z/Di', $value['artifact_filename'])
			&& is_int($value['artifact_size'])
			&& $value['artifact_size'] >= 1
			&& is_string($value['artifact_sha256'])
			&& 1 === preg_match('/\A[a-f0-9]{64}\z/D', $value['artifact_sha256'])
			&& is_string($value['canonical_update_uri'])
			&& CanonicalUpdateUri::canonicalize($value['canonical_update_uri']) === $value['canonical_update_uri']
			&& ('stable' === $value['channel'] || 'prerelease' === $value['channel'])
			&& is_string($value['version'])
			&& null !== ReleaseVersion::normalize($value['version'])
			&& is_string($value['php_runtime_version'])
			&& null !== ReleaseVersion::normalizeHeader($value['php_runtime_version'])
			&& is_string($value['wordpress_runtime_version'])
			&& null !== ReleaseVersion::normalizeHeader($value['wordpress_runtime_version'])
			&& is_string($root)
			&& 1 === preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,99}\z/D', $root)
			&& is_string($main)
			&& self::validMainFile($type, $main)
			&& self::validAssuranceFacts($value['assurance_facts']);
	}

	private static function validMainFile(string $type, string $main): bool
	{
		if ('theme' === $type) {
			return 'style.css' === $main;
		}
		return 1 === preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,99}\.php\z/D', $main);
	}

	private static function validAssuranceFacts(mixed $value): bool
	{
		if (! self::exactKeys($value, self::ASSURANCE_FACT_KEYS)) {
			return false;
		}
		foreach (self::ASSURANCE_FACT_KEYS as $key) {
			if (! is_bool($value[$key])) {
				return false;
			}
		}
		return true;
	}

	/** @param array<string, mixed> $facts @return array<string, mixed> */
	private static function orderedFacts(array $facts): array
	{
		$ordered = self::ordered($facts, self::FACT_KEYS);
		$ordered['assurance_facts'] = self::ordered($facts['assurance_facts'], self::ASSURANCE_FACT_KEYS);
		return $ordered;
	}

	/** @param array<string, mixed> $facts */
	private static function fingerprintFacts(array $facts): string
	{
		return 'v1:' . hash('sha256', json_encode($facts, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
	}

	/** @param array<string, mixed> $value @param list<string> $keys @return array<string, mixed> */
	private static function ordered(array $value, array $keys): array
	{
		$ordered = array();
		foreach ($keys as $key) {
			$ordered[$key] = $value[$key];
		}
		return $ordered;
	}

	/** @param list<string> $keys */
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
}
