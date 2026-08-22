<?php

declare(strict_types=1);

namespace RAN\WPReleaseUpdater\V1\Provider\GitHub;

use InvalidArgumentException;
use RAN\WPReleaseUpdater\V1\Archive\PackageIdentityValidator;
use RAN\WPReleaseUpdater\V1\Archive\TemporaryArtifact;
use RAN\WPReleaseUpdater\V1\Contract\BindingRecord;
use RAN\WPReleaseUpdater\V1\Contract\IdentityDescriptor;
use RAN\WPReleaseUpdater\V1\Contract\ReleaseAdapter;
use RAN\WPReleaseUpdater\V1\WordPress\NativePluginUpdater;

/** Installed-package wrapper around the shared GitHub release service. */
final class GitHubReleaseAdapter implements ReleaseAdapter
{
	private GitHubReleaseService $service;

	/** @param array<string, mixed> $configuration @param array<string, mixed> $archivePolicy */
	public static function registerFromConfiguration(
		array $configuration,
		BindingRecord $binding,
		?GitHubCredentialResolver $credentials,
		object $wpdb,
		array $archivePolicy,
		?PackageIdentityValidator $validator = null
	): ?NativePluginUpdater {
		try {
			$adapter = new self($binding, $credentials);
		} catch (InvalidArgumentException) {
			return null;
		}
		$updater = NativePluginUpdater::fromConfiguration($configuration, $binding, $adapter, $wpdb, $archivePolicy, $validator);
		if ($updater instanceof NativePluginUpdater) {
			$updater->register();
		}
		return $updater;
	}

	public function __construct(
		private BindingRecord $bindingRecord,
		?GitHubCredentialResolver $credentials = null
	) {
		$facts = $bindingRecord->toArray();
		if ('github' !== $facts['provider_code']) {
			throw new InvalidArgumentException('The GitHub binding is invalid.');
		}
		$this->service = new GitHubReleaseService(
			array(
				'canonical_repository_locator' => $facts['canonical_repository_locator'],
				'canonical_update_uri' => $facts['canonical_update_uri'],
				'php_runtime_version' => $facts['php_runtime_version'],
				'release_channel' => $facts['release_channel'],
				'stable_repository_identity' => $facts['stable_repository_identity'],
				'target_type' => $facts['target_type'],
				'wordpress_runtime_version' => $facts['wordpress_runtime_version'],
			),
			$credentials
		);
	}

	/** @return array<string, mixed> */
	public function listReleases(array $conditional = array()): array
	{
		return $this->service->listReleases($conditional);
	}

	public function inspect(string $releaseIdentity, ?string $expectedTag = null): IdentityDescriptor
	{
		return $this->service->inspectInstalled(
			$this->bindingRecord->toArray()['installed_package_identity'],
			$releaseIdentity,
			$expectedTag
		);
	}

	public function acquire(IdentityDescriptor $descriptor): TemporaryArtifact
	{
		BindingRecord::assertDescriptorBinding($descriptor, $this->bindingRecord);
		return $this->service->acquireInstalled($descriptor);
	}
}
