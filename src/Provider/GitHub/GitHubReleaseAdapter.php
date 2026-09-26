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
use RAN\WPReleaseUpdater\V1\WordPress\NativePackageUpdater;

/** Installed-package wrapper around the shared GitHub release service. */
final class GitHubReleaseAdapter implements ReleaseAdapter {

	private GitHubReleaseService $service;

	/**
	 * @internal Selected-runtime GitHub declaration composition.
	 * @param array<string,mixed> $declaration
	 * @param array<string,mixed> $resolved
	 * @param array<string,mixed> $headers
	 * @return array{native:?NativePackageUpdater,code:string}
	 */
	public static function compose_from_declaration(
		array $declaration,
		array $resolved,
		array $headers,
		string $identity,
		int $network_id,
		mixed $selected_runtime_state
	): array {
		$locator       = $declaration['repository_locator'];
		$repository_id = $declaration['repository_identity'];
		if ( 1 !== preg_match( '/\\A[A-Za-z0-9](?:[A-Za-z0-9-]{0,38})\\/(?!\\.{1,2}\\z)[A-Za-z0-9_.-]{1,100}\\z/D', $locator ) ) {
			return array(
				'native' => null,
				'code'   => 'repository_locator_invalid',
			);
		}
		if ( 1 !== preg_match( '/\\A[1-9][0-9]{0,18}\\z/D', $repository_id ) ) {
			return array(
				'native' => null,
				'code'   => 'repository_identity_invalid',
			);
		}
		try {
			$uri = \RAN\WPReleaseUpdater\V1\Contract\CanonicalUpdateUri::canonicalize( 'https://github.com/' . $locator );
		} catch ( \Throwable ) {
			return array(
				'native' => null,
				'code'   => 'repository_locator_invalid',
			);
		}
		try {
			$installed_uri = \RAN\WPReleaseUpdater\V1\Contract\CanonicalUpdateUri::canonicalize( $headers['UpdateURI'] );
		} catch ( \Throwable ) {
			return array(
				'native' => null,
				'code'   => 'installed_update_uri_mismatch',
			);
		}
		if ( $uri !== $installed_uri ) {
			return array(
				'native' => null,
				'code'   => 'installed_update_uri_mismatch',
			);
		}
		if ( ! is_object( $GLOBALS['wpdb'] ?? null ) || ! function_exists( 'add_filter' ) ) {
			return array(
				'native' => null,
				'code'   => 'target_composition_failed',
			);
		}
		$wordpress_version = SelectedRuntimeState::normalizeWordPressVersion( $GLOBALS['wp_version'] ?? null );
		if ( ! is_string( $wordpress_version ) ) {
			return array(
				'native' => null,
				'code'   => 'target_composition_failed',
			);
		}
		$binding                     = BindingRecord::create(
			array(
				'canonical_repository_locator' => $locator,
				'canonical_update_uri'         => $uri,
				'installed_package_identity'   => $identity,
				'maximum_artifact_bytes'       => $declaration['maximum_artifact_bytes'],
				'network_id'                   => $network_id,
				'php_runtime_version'          => PHP_VERSION,
				'provider_code'                => 'github',
				'release_channel'              => $declaration['channel'],
				'stable_repository_identity'   => $repository_id,
				'target_type'                  => $declaration['target_type'],
				'theme_template'               => $headers['Template'],
				'update_policy'                => $declaration['update_policy'],
				'wordpress_runtime_version'    => $wordpress_version,
			)
		);
		$resolver                    = new GitHubCredentialResolver( $declaration['credential_resolver'] );
		$native_headers              = $headers;
		$native_headers['PluginURI'] = $native_headers['PackageURI'];
		unset( $native_headers['PackageURI'], $native_headers['Template'] );
		$configuration  = array(
			'headers'                    => $native_headers,
			'installed_package_identity' => $identity,
			'policy'                     => $declaration['update_policy'],
			'target_type'                => $declaration['target_type'],
			'update_uri'                 => $uri,
		);
		$archive_policy = array(
			'archive_root'               => $resolved['archive_root'],
			'configuration_update_uri'   => $uri,
			'header_file'                => $resolved['header_file'],
			'installed_package_identity' => $identity,
			'maximum_artifact_bytes'     => $declaration['maximum_artifact_bytes'],
			'metadata_name'              => $headers['Name'],
			'offer_update_uri'           => $uri,
			'php_runtime_version'        => PHP_VERSION,
			'provider_code'              => 'github',
			'repository_identity'        => $repository_id,
			'repository_locator'         => $locator,
			'staged_package_update_uri'  => $uri,
			'target_type'                => $declaration['target_type'],
			'theme_template'             => $headers['Template'],
			'wordpress_runtime_version'  => $binding->to_array()['wordpress_runtime_version'],
		);
		return array(
			'native' => self::register_from_configuration(
				$configuration,
				$binding,
				$resolver,
				$GLOBALS['wpdb'],
				$archive_policy,
				null,
				$selected_runtime_state,
				null === $declaration['credential_resolver'],
			),
			'code'   => 'target_composition_failed',
		);
	}

	/**
	 * @param array<string, mixed> $configuration
	 * @param array<string, mixed> $archive_policy
	 */
	public static function register_from_configuration(
		array $configuration,
		BindingRecord $binding,
		?GitHubCredentialResolver $credentials,
		object $wpdb,
		array $archive_policy,
		?PackageIdentityValidator $validator = null,
		?SelectedRuntimeState $selected_runtime_state = null,
		bool $native_discovery_reuse = false
	): ?NativePackageUpdater {
		try {
			$adapter = new self( $binding, $credentials );
		} catch ( InvalidArgumentException ) {
			return null;
		}
		$updater = NativePackageUpdater::fromConfiguration( $configuration, $binding, $adapter, $wpdb, $archive_policy, $validator, $selected_runtime_state, $native_discovery_reuse );
		if ( $updater instanceof NativePackageUpdater ) {
			$updater->register();
		}
		return $updater;
	}

	public function __construct(
		private BindingRecord $binding_record,
		?GitHubCredentialResolver $credentials = null
	) {
		$facts = $binding_record->to_array();
		if ( 'github' !== $facts['provider_code'] ) {
			throw new InvalidArgumentException( 'The GitHub binding is invalid.' );
		}
		$this->service = new GitHubReleaseService(
			array(
				'canonical_repository_locator' => $facts['canonical_repository_locator'],
				'canonical_update_uri'         => $facts['canonical_update_uri'],
				'maximum_artifact_bytes'       => $facts['maximum_artifact_bytes'],
				'php_runtime_version'          => $facts['php_runtime_version'],
				'release_channel'              => $facts['release_channel'],
				'stable_repository_identity'   => $facts['stable_repository_identity'],
				'target_type'                  => $facts['target_type'],
				'wordpress_runtime_version'    => $facts['wordpress_runtime_version'],
			),
			$credentials
		);
	}

	/** @return array<string, mixed> */
	public function list_releases( array $conditional = array() ): array {
		return $this->service->list_releases( $conditional );
	}

	public function inspect( string $release_identity, ?string $expected_tag = null ): IdentityDescriptor {
		return $this->service->inspect_installed(
			$this->binding_record->to_array()['installed_package_identity'],
			$release_identity,
			$expected_tag
		);
	}

	public function acquire( IdentityDescriptor $descriptor ): TemporaryArtifact {
		BindingRecord::assert_descriptor_binding( $descriptor, $this->binding_record );
		return $this->service->acquire_installed( $descriptor );
	}
}
