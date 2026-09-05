<?php

declare(strict_types=1);

namespace RAN\WPReleaseUpdater\V1\Provider\GitHub;

use InvalidArgumentException;
use RAN\WPReleaseUpdater\V1\Archive\PackageIdentityValidator;
use RAN\WPReleaseUpdater\V1\Archive\TemporaryArtifact;
use RAN\WPReleaseUpdater\V1\Contract\BindingRecord;
use RAN\WPReleaseUpdater\V1\Contract\IdentityDescriptor;
use RAN\WPReleaseUpdater\V1\Contract\ReleaseAdapter;
use RAN\WPReleaseUpdater\V1\Runtime\SelectedRuntimeState;
use RAN\WPReleaseUpdater\V1\WordPress\NativePluginUpdater;

/** Installed-package wrapper around the shared GitHub release service. */
final class GitHubReleaseAdapter implements ReleaseAdapter
{
	private GitHubReleaseService $service;

	/**
	 * @internal Selected-runtime GitHub declaration composition.
	 * @param array<string,mixed> $declaration
	 * @param array<string,mixed> $resolved
	 * @param array<string,mixed> $headers
	 * @return array{native:?NativePluginUpdater,code:string}
	 */
	public static function composeFromDeclaration(
		array $declaration,
		array $resolved,
		array $headers,
		string $identity,
		int $networkId,
		mixed $selectedRuntimeState
	): array
	{
		$locator = $declaration['repository_locator'];
		$repositoryId = $declaration['repository_identity'];
		if ( 1 !== preg_match( '/\\A[A-Za-z0-9](?:[A-Za-z0-9-]{0,38})\\/(?!\\.{1,2}\\z)[A-Za-z0-9_.-]{1,100}\\z/D', $locator ) ) {
			return array( 'native' => null, 'code' => 'repository_locator_invalid' );
		}
		if ( 1 !== preg_match( '/\\A[1-9][0-9]{0,18}\\z/D', $repositoryId ) ) {
			return array( 'native' => null, 'code' => 'repository_identity_invalid' );
		}
		try {
			$uri = \RAN\WPReleaseUpdater\V1\Contract\CanonicalUpdateUri::canonicalize( 'https://github.com/' . $locator );
		} catch ( \Throwable ) {
			return array( 'native' => null, 'code' => 'repository_locator_invalid' );
		}
		try {
			$installedUri = \RAN\WPReleaseUpdater\V1\Contract\CanonicalUpdateUri::canonicalize( $headers['UpdateURI'] );
		} catch ( \Throwable ) {
			return array( 'native' => null, 'code' => 'installed_update_uri_mismatch' );
		}
		if ( $uri !== $installedUri ) {
			return array( 'native' => null, 'code' => 'installed_update_uri_mismatch' );
		}
		if ( ! is_object( $GLOBALS['wpdb'] ?? null ) || ! function_exists( 'add_filter' ) ) {
			return array( 'native' => null, 'code' => 'target_composition_failed' );
		}
		$binding = BindingRecord::create(
			array(
				'canonical_repository_locator' => $locator,
				'canonical_update_uri' => $uri,
				'installed_package_identity' => $identity,
				'maximum_artifact_bytes' => $declaration['maximum_artifact_bytes'],
				'network_id' => $networkId,
				'php_runtime_version' => PHP_VERSION,
				'provider_code' => 'github',
				'release_channel' => $declaration['channel'],
				'stable_repository_identity' => $repositoryId,
				'target_type' => $declaration['target_type'],
				'theme_template' => $headers['Template'],
				'update_policy' => $declaration['update_policy'],
				'wordpress_runtime_version' => is_string( $GLOBALS['wp_version'] ?? null ) ? $GLOBALS['wp_version'] : '6.5.0',
			)
		);
		$resolver = new GitHubCredentialResolver( $declaration['credential_resolver'] );
		$nativeHeaders = $headers;
		$nativeHeaders['PluginURI'] = $nativeHeaders['PackageURI'];
		unset( $nativeHeaders['PackageURI'], $nativeHeaders['Template'] );
		$configuration = array(
			'headers' => $nativeHeaders,
			'installed_package_identity' => $identity,
			'policy' => $declaration['update_policy'],
			'target_type' => $declaration['target_type'],
			'update_uri' => $uri,
		);
		$archivePolicy = array(
			'archive_root' => $resolved['archive_root'],
			'configuration_update_uri' => $uri,
			'header_file' => $resolved['header_file'],
			'installed_package_identity' => $identity,
			'maximum_artifact_bytes' => $declaration['maximum_artifact_bytes'],
			'metadata_name' => $headers['Name'],
			'offer_update_uri' => $uri,
			'php_runtime_version' => PHP_VERSION,
			'provider_code' => 'github',
			'repository_identity' => $repositoryId,
			'repository_locator' => $locator,
			'staged_package_update_uri' => $uri,
			'target_type' => $declaration['target_type'],
			'theme_template' => $headers['Template'],
			'wordpress_runtime_version' => $binding->toArray()['wordpress_runtime_version'],
		);
		return array(
			'native' => self::registerFromConfiguration(
				$configuration,
				$binding,
				$resolver,
				$GLOBALS['wpdb'],
				$archivePolicy,
				null,
				$selectedRuntimeState,
				null === $declaration['credential_resolver'],
			),
			'code' => 'target_composition_failed',
		);
	}

	/** @param array<string, mixed> $configuration @param array<string, mixed> $archivePolicy */
	public static function registerFromConfiguration(
		array $configuration,
		BindingRecord $binding,
		?GitHubCredentialResolver $credentials,
		object $wpdb,
		array $archivePolicy,
		?PackageIdentityValidator $validator = null,
		?SelectedRuntimeState $selectedRuntimeState = null,
		bool $nativeDiscoveryReuse = false
	): ?NativePluginUpdater {
		try {
			$adapter = new self($binding, $credentials);
		} catch (InvalidArgumentException) {
			return null;
		}
		$updater = NativePluginUpdater::fromConfiguration($configuration, $binding, $adapter, $wpdb, $archivePolicy, $validator, $selectedRuntimeState, $nativeDiscoveryReuse);
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
				'maximum_artifact_bytes' => $facts['maximum_artifact_bytes'],
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
