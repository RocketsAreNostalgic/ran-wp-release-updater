<?php

declare(strict_types=1);

namespace RAN\WPReleaseUpdater\V1\Provider\GitHub;

use InvalidArgumentException;
use RuntimeException;
use RAN\WPReleaseUpdater\V1\Archive\TemporaryArtifact;
use RAN\WPReleaseUpdater\V1\Archive\PackageIdentityValidator;
use RAN\WPReleaseUpdater\V1\Contract\CanonicalUpdateUri;
use RAN\WPReleaseUpdater\V1\Contract\IdentityDescriptor;
use RAN\WPReleaseUpdater\V1\Contract\ReleaseVersion;
use RAN\WPReleaseUpdater\V1\Runtime\ReleaseFailure;
use RAN\WPReleaseUpdater\V1\Runtime\SelectedRuntimeState;

/**
 * Shared, request-local GitHub release protocol for installed and prospective packages.
 *
 * @phpstan-type ListedRelease array{
 *   details_url:string,
 *   expected_asset_names:list<string>,
 *   prerelease:bool,
 *   publication_immutable:bool,
 *   published_at:string,
 *   release_identity:string,
 *   tag:string,
 *   version:string
 * }
 * @phpstan-type ReleaseListing array{
 *   candidates:list<ListedRelease>,
 *   conditional:array{etag:?string,last_modified:?string},
 *   not_modified:bool,
 *   rate_limit:array{limited:bool,remaining:?int,reset_at:?int,retry_after:int},
 *   search_exhausted:bool
 * }
 */
final class GitHubReleaseService {

	private const MAX_CANDIDATES           = 8;
	private const MAX_PAGES                = 2;
	private const PAGE_SIZE                = 20;
	private const RELEASE_LIST_BYTES_LIMIT = 524288;
	private const RELEASE_RESPONSE_LIMIT   = 262144;
	private const COMMIT_RESPONSE_LIMIT    = 16384;
	private const CONFIGURATION_KEYS       = array(
		'canonical_repository_locator',
		'canonical_update_uri',
		'php_runtime_version',
		'maximum_artifact_bytes',
		'release_channel',
		'stable_repository_identity',
		'target_type',
		'wordpress_runtime_version',
	);

	/** @var array<string, mixed> */
	private array $binding;
	private GitHubCredentialResolver $credentials;
	private GitHubApiClient $client;
	private GitHubArtifactStore $artifact_store;
	/** @var null|callable():?string */
	private $liveness_guard;

	/** @param array<string, mixed> $configuration */
	public function __construct(
		array $configuration,
		?GitHubCredentialResolver $credentials = null,
		?callable $liveness_guard = null
	) {
		if (
			! self::exact_keys( $configuration, self::CONFIGURATION_KEYS )
			|| ! self::valid_locator( $configuration['canonical_repository_locator'] )
			|| ! is_int( $configuration['maximum_artifact_bytes'] )
			|| 0 >= $configuration['maximum_artifact_bytes']
			|| ! is_string( $configuration['stable_repository_identity'] )
			|| self::canonical_decimal( $configuration['stable_repository_identity'] ) !== $configuration['stable_repository_identity']
			|| ! self::valid_release_uri(
				$configuration['canonical_update_uri'],
				$configuration['canonical_repository_locator']
			)
			|| ! in_array( $configuration['target_type'], array( 'plugin', 'theme' ), true )
			|| ! in_array( $configuration['release_channel'], array( 'stable', 'prerelease' ), true )
			|| ! is_string( $configuration['php_runtime_version'] )
			|| null === ReleaseVersion::normalize_header( $configuration['php_runtime_version'] )
			|| ! is_string( $configuration['wordpress_runtime_version'] )
			|| null === ReleaseVersion::normalize_header( $configuration['wordpress_runtime_version'] )
		) {
			throw new InvalidArgumentException( 'The GitHub release configuration is invalid.' );
		}

		$this->binding        = self::ordered( $configuration, self::CONFIGURATION_KEYS );
		$this->credentials    = $credentials ?? new GitHubCredentialResolver();
		$this->liveness_guard = $liveness_guard;
		$this->client         = new GitHubApiClient(
			function (): void {
				$this->assert_live();
			}
		);
		$this->artifact_store = new GitHubArtifactStore();
	}

	/**
	 * @internal Sealed-catalog composition for a prospective source.
	 * @param array<string, mixed> $declaration
	 */
	public static function from_release_declaration( array $declaration, SelectedRuntimeState $state ): object {
		$keys = array( 'provider_code', 'target_type', 'repository_locator', 'repository_identity', 'channel', 'credential_resolver', 'maximum_artifact_bytes' );
		if ( ! self::exact_keys( $declaration, $keys ) || 'github' !== $declaration['provider_code'] ) {
			throw new InvalidArgumentException( 'The release declaration is invalid.' );
		}
		$wordpress = SelectedRuntimeState::normalize_word_press_version( $GLOBALS['wp_version'] ?? null );
		if ( ! is_string( $wordpress ) ) {
			throw new \RuntimeException( 'The release runtime is unavailable.' );
		}
		return new self(
			array(
				'canonical_repository_locator' => $declaration['repository_locator'],
				'canonical_update_uri'         => CanonicalUpdateUri::canonicalize( 'https://github.com/' . $declaration['repository_locator'] ),
				'maximum_artifact_bytes'       => $declaration['maximum_artifact_bytes'],
				'php_runtime_version'          => PHP_VERSION,
				'release_channel'              => $declaration['channel'],
				'stable_repository_identity'   => $declaration['repository_identity'],
				'target_type'                  => $declaration['target_type'],
				'wordpress_runtime_version'    => $wordpress,
			),
			new GitHubCredentialResolver( $declaration['credential_resolver'] ),
			static fn (): ?string => $state->release_readiness_code()
		);
	}

	/**
	 * @internal Release-source operation.
	 * @param array{etag?:string,last_modified?:string} $conditional
	 * @return ReleaseListing
	 */
	public function list( array $conditional = array() ): array {
		try {
			$this->assert_live();
			$result = $this->list_releases( $conditional );
			$this->assert_live();
			if ( $result['rate_limit']['limited'] ) {
				throw new ReleaseFailure( 'rate_limited', $result['rate_limit']['retry_after'] );
			}
			return $result;
		} catch ( ReleaseFailure $failure ) {
			throw $failure; } catch ( \InvalidArgumentException $exception ) {
			throw new ReleaseFailure( 'invalid_configuration', null, 'not_applicable', $exception ); } catch ( \Throwable $exception ) {
				throw $this->operation_failure( $exception ); }
	}

	/** @return array<string,mixed> @internal Release-source operation. */
	public function inspect( string $release_identity, string $expected_tag ): array {
		try {
			$this->assert_live();
			$result = $this->inspect_prospective( $release_identity, $expected_tag );
			$this->assert_live();
			return $result->to_array();
		} catch ( ReleaseFailure $failure ) {
			throw $failure; } catch ( \InvalidArgumentException $exception ) {
			throw new ReleaseFailure( 'invalid_release', null, 'not_applicable', $exception ); } catch ( \Throwable $exception ) {
				throw $this->operation_failure( $exception ); }
	}

	/** @return array{inspection:array<string,mixed>,artifact:TemporaryArtifact} @internal Release-source operation. */
	public function acquire( string $release_identity, string $expected_tag, string $expected_fingerprint ): array {
		if ( 1 !== preg_match( '/\Av2:[a-f0-9]{64}\z/D', $expected_fingerprint ) ) {
			throw new ReleaseFailure( 'invalid_release' );
		}
		try {
			$this->assert_live();
			list($release_identity, $expected_tag) = $this->inspect_input( $release_identity, $expected_tag );
			list($fresh, $artifact)                = $this->prospective_proof( $release_identity, $expected_tag, $this->resolve_credentials(), true );
			if ( ! $artifact instanceof TemporaryArtifact || ! hash_equals( $expected_fingerprint, $fresh->fingerprint_value() ) ) {
				$clean = ! $artifact instanceof TemporaryArtifact || ( $artifact->discard() || $artifact->discard() );
				throw new ReleaseFailure( 'release_changed', null, $clean ? 'complete' : 'failed' );
			}
			try {
				$this->assert_live();
			} catch ( ReleaseFailure $failure ) {
				$clean = $artifact->discard() || $artifact->discard();
				throw new ReleaseFailure( $failure->release_code, $failure->retry_after, $clean ? 'complete' : 'failed', $failure );
			}
			return array(
				'inspection' => $fresh->to_array(),
				'artifact'   => $artifact,
			);
		} catch ( ReleaseFailure $failure ) {
			throw $failure; } catch ( \InvalidArgumentException $exception ) {
			throw new ReleaseFailure( 'invalid_release', null, 'not_applicable', $exception ); } catch ( \Throwable $exception ) {
				throw $this->operation_failure( $exception ); }
	}

	/**
	 * @param array{etag?:string,last_modified?:string} $conditional
	 * @return ReleaseListing
	 */
	public function list_releases( array $conditional = array() ): array {
		$conditional = self::conditional( $conditional );
		try {
			$token            = $this->resolve_credentials();
			$candidates       = array();
			$seen             = array();
			$response_bytes   = 0;
			$search_exhausted = false;
			$next_conditional = array(
				'etag'          => null,
				'last_modified' => null,
			);
			$rate_limit       = self::empty_rate_limit();

			for ( $page = 1; $page <= self::MAX_PAGES; ++$page ) {
				$headers         = 1 === $page ? self::conditional_headers( $conditional ) : array();
				$remaining_bytes = self::RELEASE_LIST_BYTES_LIMIT - $response_bytes;
				$response        = $this->client->request(
					$this->repository_api_url()
					. '/releases?per_page=' . self::PAGE_SIZE . '&page=' . $page,
					$token,
					$headers,
					min( self::RELEASE_RESPONSE_LIMIT, $remaining_bytes )
				);

				$status = GitHubApiClient::response_code( $response );
				if ( 1 === $page ) {
					$next_conditional = self::response_conditional( $response );
				}
				$rate_limit = self::rate_limit( $response );
				if ( 1 === $page && 304 === $status ) {
					return self::listing_result(
						array(),
						$next_conditional,
						true,
						$rate_limit,
						false
					);
				}
				if ( $rate_limit['limited'] ) {
					return self::listing_result(
						array(),
						$next_conditional,
						false,
						$rate_limit,
						false
					);
				}
				self::require_success( $response );

				$body            = GitHubApiClient::response_body( $response, self::RELEASE_RESPONSE_LIMIT );
				$response_bytes += strlen( $body );
				if ( $response_bytes > self::RELEASE_LIST_BYTES_LIMIT ) {
					throw new RuntimeException( 'The GitHub release listing is too large.' );
				}

				$decoded = self::decode_list( $body );
				foreach ( $decoded as $release ) {
					$candidate = $this->listed_release( $release );
					if (
					null !== $candidate
					&& ! isset( $seen[ $candidate['release_identity'] ] )
					) {
						$seen[ $candidate['release_identity'] ] = true;
						$candidates[]                           = $candidate;
					}
				}

				$page_full = self::PAGE_SIZE === count( $decoded );
				if ( count( $candidates ) >= self::MAX_CANDIDATES || ! $page_full ) {
					$search_exhausted = count( $candidates ) > self::MAX_CANDIDATES || $page_full;
					break;
				}
				if ( self::MAX_PAGES === $page ) {
					$search_exhausted = true;
				}
			}

			usort(
				$candidates,
				static function ( array $left, array $right ): int {
					$comparison = ReleaseVersion::compare( $right['version'], $left['version'] );
					if ( null === $comparison ) {
						throw new RuntimeException( 'The GitHub release version is invalid.' );
					}
					return 0 !== $comparison ? $comparison : strcmp( $left['release_identity'], $right['release_identity'] );
				}
			);

			return self::listing_result(
				array_slice( $candidates, 0, self::MAX_CANDIDATES ),
				$next_conditional,
				false,
				$rate_limit,
				$search_exhausted
			);
		} catch ( ReleaseFailure $failure ) {
			throw $failure; } catch ( \Throwable $exception ) {
			throw $this->operation_failure( $exception ); }
	}

	public function inspect_installed(
		string $installed_package_identity,
		string $release_identity,
		?string $expected_tag = null
	): IdentityDescriptor {
		if ( ! IdentityDescriptor::is_bounded_opaque_identity( $installed_package_identity, 255 ) ) {
			throw new InvalidArgumentException( 'The installed package identity is invalid.' );
		}
		list( $release_identity, $expected_tag ) = $this->inspect_input(
			$release_identity,
			$expected_tag
		);
		try {
			return $this->inspect_with_token(
				$installed_package_identity,
				$release_identity,
				$expected_tag,
				$this->resolve_credentials()
			);
		} catch ( ReleaseFailure $failure ) {
			throw $failure; } catch ( \Throwable $exception ) {
			throw $this->operation_failure( $exception ); }
	}

	private function inspect_with_token(
		string $installed_package_identity,
		string $release_identity,
		?string $expected_tag,
		?string $token
	): IdentityDescriptor {
		return IdentityDescriptor::create(
			array_merge(
				$this->release_facts( $release_identity, $expected_tag, $token ),
				array(
					'installed_package_identity' => $installed_package_identity,
					'provider_code'              => 'github',
				)
			)
		);
	}

	public function acquire_installed( IdentityDescriptor $descriptor ): TemporaryArtifact {
		list( $facts, $artifact_identity ) = $this->acquisition_input( $descriptor );
		try {
			return $this->acquire_with_token(
				$facts,
				$artifact_identity,
				$this->resolve_credentials()
			);
		} catch ( ReleaseFailure $failure ) {
			throw $failure; } catch ( \Throwable $exception ) {
			throw $this->operation_failure( $exception ); }
	}

	/** @param array<string, mixed> $facts */
	private function acquire_with_token(
		array $facts,
		string $artifact_identity,
		?string $token
	): TemporaryArtifact {
		$this->repository_identity( $token );
		$path             = null;
		$initial_identity = null;

		try {
			list($path, $initial_identity) = $this->artifact_store->allocate( $facts['artifact_filename'] );
			$response                      = $this->client->request(
				$this->repository_api_url() . '/releases/assets/' . $artifact_identity,
				$token,
				array( 'Accept' => 'application/octet-stream' ),
				$this->binding['maximum_artifact_bytes'],
				$path
			);
			$this->require_asset_success( $response );
			$identity = $this->artifact_store->identity( $path );
			if (
				null === $identity
				|| 1 !== $identity['nlink']
				|| 0600 !== ( $identity['mode'] & 0777 )
				|| $identity['size'] !== $facts['artifact_size']
				|| $identity['size'] > $this->binding['maximum_artifact_bytes']
			) {
				throw new ReleaseFailure( 'package_incompatible' );
			}
			$sha256 = hash_file( 'sha256', $path );
			if ( ! is_string( $sha256 ) || ! hash_equals( $facts['artifact_sha256'], $sha256 ) ) {
				throw new ReleaseFailure( 'package_incompatible' );
			}

			$this->repository_identity( $token );
			$this->assert_live();
			return new TemporaryArtifact( $path, $sha256, $identity, $this->liveness_guard );
		} catch ( \Throwable $exception ) {
			if ( is_string( $path ) && is_array( $initial_identity ) ) {
				$clean = $this->artifact_store->remove( $path, $initial_identity );
			} elseif ( $exception instanceof GitHubArtifactCustodyFailure ) {
				$clean = $exception->cleanup_complete;
			} else {
				throw $exception;
			}
			throw $this->post_allocation_failure( $exception, $clean );
		}
	}

	public function inspect_prospective(
		string $release_identity,
		?string $expected_tag = null
	): ProspectiveReleaseInspection {
		list( $release_identity, $expected_tag ) = $this->inspect_input(
			$release_identity,
			$expected_tag
		);
		list($inspection, $artifact)             = $this->prospective_proof(
			$release_identity,
			$expected_tag,
			$this->resolve_credentials(),
			false
		);
		unset( $artifact );
		return $inspection;
	}

	public function acquire_prospective(
		ProspectiveReleaseInspection $inspection,
		string $expected_fingerprint
	): ProspectiveReleaseArtifact {
		$this->assert_prospective_input( $inspection, $expected_fingerprint );
		list($fresh, $artifact) = $this->prospective_proof(
			$inspection->release_identity(),
			$inspection->tag(),
			$this->resolve_credentials(),
			true
		);
		if (
			! $artifact instanceof TemporaryArtifact
			|| ! hash_equals( $expected_fingerprint, $fresh->fingerprint_value() )
			|| ! hash_equals( $inspection->fingerprint_value(), $fresh->fingerprint_value() )
		) {
			if ( $artifact instanceof TemporaryArtifact ) {
				$artifact->discard();
			}
			throw new RuntimeException( 'The prospective GitHub release changed before acquisition.' );
		}
		return new ProspectiveReleaseArtifact( $fresh, $artifact );
	}

	/** @return array{ProspectiveReleaseInspection,?TemporaryArtifact} */
	private function prospective_proof(
		string $release_identity,
		?string $expected_tag,
		?string $token,
		bool $retain_artifact
	): array {
		$release           = $this->release_facts( $release_identity, $expected_tag, $token );
		$artifact_identity = self::canonical_decimal( $release['artifact_identity'] )
			?? throw new RuntimeException( 'The GitHub artifact identity is invalid.' );
		$artifact          = $this->acquire_with_token( $release, $artifact_identity, $token );
		try {
			$validator = new PackageIdentityValidator();
			$package   = $artifact->inspect(
				fn ( string $path ): ?array => $validator->inspect_prospective(
					array(
						'artifact_sha256'           => $release['artifact_sha256'],
						'artifact_size'             => $release['artifact_size'],
						'canonical_update_uri'      => $release['canonical_update_uri'],
						'maximum_artifact_bytes'    => $this->binding['maximum_artifact_bytes'],
						'php_runtime_version'       => $this->binding['php_runtime_version'],
						'target_type'               => $release['target_type'],
						'version'                   => $release['version'],
						'wordpress_runtime_version' => $this->binding['wordpress_runtime_version'],
					),
					$path
				)
			);
			if ( ! is_array( $package ) ) {
				throw new ReleaseFailure( 'package_incompatible' );
			}
			$inspection = ProspectiveReleaseInspection::create(
				array(
					'artifact_filename'         => $release['artifact_filename'],
					'artifact_identity'         => $release['artifact_identity'],
					'artifact_sha256'           => $release['artifact_sha256'],
					'artifact_size'             => $release['artifact_size'],
					'assurance_facts'           => $release['assurance_facts'],
					'canonical_update_uri'      => $release['canonical_update_uri'],
					'channel'                   => $release['channel'],
					'commit_identity'           => $release['commit_identity'],
					'main_file'                 => $package['main_file'],
					'maximum_artifact_bytes'    => $this->binding['maximum_artifact_bytes'],
					'package_root'              => $package['package_root'],
					'php_runtime_version'       => $this->binding['php_runtime_version'],
					'provider_code'             => 'github',
					'release_identity'          => $release['release_identity'],
					'repository_identity'       => $release['repository_identity'],
					'repository_locator'        => $release['repository_locator'],
					'tag'                       => $release['tag'],
					'target_type'               => $release['target_type'],
					'version'                   => $release['version'],
					'wordpress_runtime_version' => $this->binding['wordpress_runtime_version'],
				)
			);
		} catch ( \Throwable $exception ) {
			$clean = false;
			try {
				$clean = $artifact->discard() || $artifact->discard();
			} catch ( \Throwable ) {
				// Preserve the primary failure and report the synchronous cleanup result.
				$clean = false;
			}
			throw $this->post_allocation_failure( $exception, $clean );
		}
		if ( ! $retain_artifact ) {
			if ( ! ( $artifact->discard() || $artifact->discard() ) ) {
				throw new ReleaseFailure( 'cleanup_failed', null, 'failed' );
			}
			return array( $inspection, null );
		}
		return array( $inspection, $artifact );
	}

	/** @return array{string,?string} */
	private function inspect_input( string $release_identity, ?string $expected_tag ): array {
		$release_identity = self::canonical_decimal( $release_identity )
			?? throw new InvalidArgumentException( 'The GitHub release identity is invalid.' );
		if ( null !== $expected_tag && null === self::version_from_tag( $expected_tag ) ) {
			throw new InvalidArgumentException( 'The expected GitHub release tag is invalid.' );
		}
		return array( $release_identity, $expected_tag );
	}

	/** @return array{array<string,mixed>,string} */
	private function acquisition_input( IdentityDescriptor $descriptor ): array {
		$facts             = $descriptor->to_array();
		$artifact_identity = self::canonical_decimal( $facts['artifact_identity'] ?? null );
		if (
			'github' !== $facts['provider_code']
			|| null === $artifact_identity
			|| ! $this->matches_configuration( $facts )
		) {
			throw new InvalidArgumentException( 'The GitHub artifact identity is invalid.' );
		}
		return array( $facts, $artifact_identity );
	}

	/** @return array<string, mixed> */
	private function release_facts(
		string $expected_release,
		?string $expected_tag,
		?string $token
	): array {
		$repository_identity = $this->repository_identity( $token );
		$release             = $this->json_success(
			$this->client->request(
				$this->repository_api_url() . '/releases/' . $expected_release,
				$token,
				array(),
				self::RELEASE_RESPONSE_LIMIT
			),
			self::RELEASE_RESPONSE_LIMIT,
			'release'
		);
		$release_identity    = self::provider_identity( $release['id'] ?? null );
		if (
			null === $release_identity
			|| ! hash_equals( $expected_release, $release_identity )
			|| ! is_bool( $release['draft'] ?? null )
			|| ! is_bool( $release['prerelease'] ?? null )
			|| ! is_bool( $release['immutable'] ?? null )
			|| $release['draft']
			|| ! is_string( $release['tag_name'] ?? null )
		) {
			throw new ReleaseFailure( 'package_incompatible' );
		}

		$tag     = $release['tag_name'];
		$version = self::version_from_tag( $tag );
		if (
			null === $version
			|| ( null !== $expected_tag && ! hash_equals( $expected_tag, $tag ) )
			|| (
				'stable' === $this->binding['release_channel']
				&& ( $release['prerelease'] || ReleaseVersion::is_prerelease( $version ) )
			)
			|| ! self::valid_release_page(
				$release['html_url'] ?? null,
				$this->binding['canonical_repository_locator']
			)
		) {
			throw new ReleaseFailure( 'package_incompatible' );
		}

		$asset           = $this->zip_asset( $release['assets'] ?? null );
		$commit          = $this->json_success(
			$this->client->request(
				$this->repository_api_url() . '/commits/' . rawurlencode( $tag ),
				$token,
				array(),
				self::COMMIT_RESPONSE_LIMIT
			),
			self::COMMIT_RESPONSE_LIMIT,
			'commit'
		);
		$commit_identity = is_string( $commit['sha'] ?? null )
			? strtolower( $commit['sha'] )
			: '';
		if ( 1 !== preg_match( '/\A[a-f0-9]{40}\z/D', $commit_identity ) ) {
			throw new ReleaseFailure( 'operation_failed' );
		}

		$immutable = $release['immutable'];

		return array(
			'artifact_filename'    => $asset['name'],
			'artifact_identity'    => $asset['identity'],
			'artifact_sha256'      => $asset['sha256'],
			'artifact_size'        => $asset['size'],
			'assurance_facts'      => array(
				'exact_artifact_identity'       => true,
				'exact_commit_identity'         => true,
				'exact_reacquisition_supported' => true,
				'exact_release_identity'        => true,
				'provenance_verified'           => $immutable,
				'publication_immutable'         => $immutable,
				'repository_identity_stable'    => true,
				'trusted_digest_source'         => true,
			),
			'canonical_update_uri' => $this->binding['canonical_update_uri'],
			'channel'              => $this->binding['release_channel'],
			'commit_identity'      => $commit_identity,
			'prerelease'           => $release['prerelease'],
			'release_identity'     => $release_identity,
			'repository_identity'  => $repository_identity,
			'repository_locator'   => $this->binding['canonical_repository_locator'],
			'tag'                  => $tag,
			'target_type'          => $this->binding['target_type'],
			'version'              => $version,
		);
	}

	private function assert_prospective_input(
		ProspectiveReleaseInspection $inspection,
		string $expected_fingerprint
	): void {
		$facts = $inspection->to_array();
		if (
			1 !== preg_match( '/\Av2:[a-f0-9]{64}\z/D', $expected_fingerprint )
			|| ! hash_equals( $inspection->fingerprint_value(), $expected_fingerprint )
			|| ! $this->matches_configuration( $facts )
		) {
			throw new InvalidArgumentException( 'The prospective GitHub release acquisition is invalid.' );
		}
		$this->inspect_input( $inspection->release_identity(), $inspection->tag() );
	}

	/** @param array<string, mixed> $facts */
	private function matches_configuration( array $facts ): bool {
		$pairs = array(
			'canonical_update_uri' => 'canonical_update_uri',
			'channel'              => 'release_channel',
			'repository_identity'  => 'stable_repository_identity',
			'repository_locator'   => 'canonical_repository_locator',
			'target_type'          => 'target_type',
		);
		foreach ( $pairs as $fact => $configuration ) {
			if ( ! is_string( $facts[ $fact ] ?? null )
				|| ! hash_equals( $this->binding[ $configuration ], $facts[ $fact ] ) ) {
				return false;
			}
		}
		if ( ! is_int( $facts['artifact_size'] ?? null ) || $facts['artifact_size'] > $this->binding['maximum_artifact_bytes'] ) {
			return false;
		}
		if ( array_key_exists( 'maximum_artifact_bytes', $facts ) && $facts['maximum_artifact_bytes'] !== $this->binding['maximum_artifact_bytes'] ) {
			return false;
		}
		if ( array_key_exists( 'provider_code', $facts ) && 'github' !== $facts['provider_code'] ) {
			return false;
		}
		foreach ( array( 'php_runtime_version', 'wordpress_runtime_version' ) as $runtime ) {
			if ( array_key_exists( $runtime, $facts )
				&& ( ! is_string( $facts[ $runtime ] ) || ! hash_equals( $this->binding[ $runtime ], $facts[ $runtime ] ) ) ) {
				return false;
			}
		}
		return true;
	}

	/** @return ListedRelease|null */
	private function listed_release( mixed $release ): ?array {
		if (
			! is_array( $release )
			|| ! is_bool( $release['draft'] ?? null )
			|| ! is_bool( $release['prerelease'] ?? null )
			|| ! is_bool( $release['immutable'] ?? null )
			|| $release['draft']
			|| ! is_string( $release['tag_name'] ?? null )
			|| ! is_string( $release['published_at'] ?? null )
		) {
			return null;
		}

		$release_identity = self::provider_identity( $release['id'] ?? null );
		$version          = self::version_from_tag( $release['tag_name'] );
		$prerelease       = $release['prerelease']
			|| ( is_string( $version ) && ReleaseVersion::is_prerelease( $version ) );
		if (
			null === $release_identity
			|| null === $version
			|| ( 'stable' === $this->binding['release_channel'] && $prerelease )
			|| 1 !== preg_match(
				'/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?Z\z/D',
				$release['published_at']
			)
			|| ! self::valid_release_page(
				$release['html_url'] ?? null,
				$this->binding['canonical_repository_locator']
			)
		) {
			return null;
		}

		$asset_names = array();
		if ( is_array( $release['assets'] ?? null ) ) {
			foreach ( $release['assets'] as $asset ) {
				$name = is_array( $asset ) && is_string( $asset['name'] ?? null )
					? $asset['name']
					: '';
				if ( self::valid_zip_name( $name ) ) {
					$asset_names[] = $name;
				}
			}
		}

		return array(
			'details_url'           => $release['html_url'],
			'expected_asset_names'  => array_slice( $asset_names, 0, 2 ),
			'prerelease'            => $prerelease,
			'publication_immutable' => $release['immutable'],
			'published_at'          => $release['published_at'],
			'release_identity'      => $release_identity,
			'tag'                   => $release['tag_name'],
			'version'               => $version,
		);
	}

	private function repository_identity( ?string $token ): string {
		$expected   = $this->binding['stable_repository_identity'];
		$repository = $this->json_success(
			$this->client->request(
				$this->repository_api_url(),
				$token,
				array(),
				self::RELEASE_RESPONSE_LIMIT
			),
			self::RELEASE_RESPONSE_LIMIT,
			'repository'
		);
		$actual     = self::provider_identity( $repository['id'] ?? null );
		if ( null === $actual || ! hash_equals( $expected, $actual ) ) {
			throw new ReleaseFailure( 'operation_failed' );
		}

		return $actual;
	}

	private function resolve_credentials(): ?string {
		$this->assert_live();
		$credential = $this->credentials->resolve();
		$this->assert_live();
		return $credential;
	}

	private function assert_live(): void {
		if ( null === $this->liveness_guard ) {
			return;
		}
		try {
			$code = ( $this->liveness_guard )();
		} catch ( \Throwable $exception ) {
			throw new ReleaseFailure( 'runtime_unavailable', null, 'not_applicable', $exception );
		}
		if ( null !== $code ) {
			throw new ReleaseFailure( 'runtime_unavailable' );
		}
	}

	private function operation_failure( \Throwable $exception ): ReleaseFailure {
		if ( $exception instanceof ReleaseFailure ) {
			return $exception;
		}
		if ( $exception instanceof GitHubReleaseReadUnavailable && 1001 === $exception->getCode() ) {
			return new ReleaseFailure( 'credential_unavailable', null, 'not_applicable', $exception );
		}
		return new ReleaseFailure( 'operation_failed', null, 'not_applicable', $exception );
	}

	private function post_allocation_failure( \Throwable $exception, bool $clean ): ReleaseFailure {
		$cleanup = $clean ? 'complete' : 'failed';
		if ( $exception instanceof ReleaseFailure ) {
			return new ReleaseFailure( $exception->release_code, $exception->retry_after, $cleanup, $exception );
		}
		if ( $exception instanceof GitHubReleaseReadUnavailable ) {
			return new ReleaseFailure( 'operation_failed', null, $cleanup, $exception );
		}
		if ( 1002 === $exception->getCode() ) {
			return new ReleaseFailure( 'runtime_unavailable', null, $cleanup, $exception );
		}
		return new ReleaseFailure( 'operation_failed', null, $cleanup, $exception );
	}

	/**
	 * @param array<string, mixed> $response
	 * @return array<string, mixed>
	 */
	private function json_success(
		array $response,
		int $limit,
		string $context
	): array {
		$rate_limit = self::rate_limit( $response );
		if ( $rate_limit['limited'] ) {
			throw new ReleaseFailure( 'rate_limited', $rate_limit['retry_after'] );
		}
		self::require_success( $response, 'repository' === $context );
		try {
			return self::decode_object( GitHubApiClient::response_body( $response, $limit ) );
		} catch ( \Throwable $exception ) {
			throw new ReleaseFailure( 'operation_failed', null, 'not_applicable', $exception );
		}
	}

	/** @param array<string,mixed> $response */
	private function require_asset_success( array $response ): void {
		$rate_limit = self::rate_limit( $response );
		if ( $rate_limit['limited'] ) {
			throw new ReleaseFailure( 'rate_limited', $rate_limit['retry_after'] );
		}
		self::require_success( $response, false );
	}

	private function repository_api_url(): string {
		list($owner, $repository) = explode(
			'/',
			$this->binding['canonical_repository_locator'],
			2
		);
		return $this->client->api( '/repos/' . rawurlencode( $owner ) . '/' . rawurlencode( $repository ) );
	}


	/** @param array<string, mixed> $response */
	private static function require_success( array $response, bool $missing_is_read_unavailable = true ): void {
		$status = GitHubApiClient::response_code( $response );
		if ( in_array( $status, array( 401, 403 ), true ) || ( $missing_is_read_unavailable && 404 === $status ) ) {
			throw new ReleaseFailure( 'repository_access_unavailable' );
		}
		if ( ! $missing_is_read_unavailable && 404 === $status ) {
			throw new ReleaseFailure( 'release_unavailable' );
		}
		if ( $status < 200 || $status > 299 ) {
			throw new ReleaseFailure( 'operation_failed' );
		}
	}

	/** @return list<array<string, mixed>> */
	private static function decode_list( string $body ): array {
		$trimmed = ltrim( $body );
		if ( '' === $trimmed || '[' !== $trimmed[0] ) {
			throw new RuntimeException( 'The GitHub response is invalid.' );
		}
		try {
			$decoded = json_decode( $body, true, 32, JSON_THROW_ON_ERROR );
		} catch ( \JsonException $exception ) {
			throw new RuntimeException( 'The GitHub response is invalid.', 0, $exception );
		}
		if ( ! is_array( $decoded ) || ! array_is_list( $decoded ) ) {
			throw new RuntimeException( 'The GitHub response is invalid.' );
		}

		foreach ( $decoded as $item ) {
			if ( ! is_array( $item ) || array_is_list( $item ) ) {
				throw new RuntimeException( 'The GitHub response is invalid.' );
			}
		}

		return $decoded;
	}

	/** @return array<string, mixed> */
	private static function decode_object( string $body ): array {
		$trimmed = ltrim( $body );
		if ( '' === $trimmed || '{' !== $trimmed[0] ) {
			throw new RuntimeException( 'The GitHub response is invalid.' );
		}
		try {
			$decoded = json_decode( $body, true, 32, JSON_THROW_ON_ERROR );
		} catch ( \JsonException $exception ) {
			throw new RuntimeException( 'The GitHub response is invalid.', 0, $exception );
		}
		if ( ! is_array( $decoded ) ) {
			throw new RuntimeException( 'The GitHub response is invalid.' );
		}

		return $decoded;
	}

	/** @param array{etag?:string,last_modified?:string} $conditional
	 * @return array{etag:?string,last_modified:?string}
	 */
	private static function conditional( array $conditional ): array {
		if ( array_diff( array_keys( $conditional ), array( 'etag', 'last_modified' ) ) ) {
			throw new InvalidArgumentException( 'The GitHub conditional state is invalid.' );
		}
		$etag          = $conditional['etag'] ?? null;
		$last_modified = $conditional['last_modified'] ?? null;
		if (
			( null !== $etag && ! self::valid_etag( $etag ) )
			|| ( null !== $last_modified && ! self::valid_last_modified( $last_modified ) )
		) {
			throw new InvalidArgumentException( 'The GitHub conditional state is invalid.' );
		}

		return array(
			'etag'          => $etag,
			'last_modified' => $last_modified,
		);
	}

	/** @param array{etag:?string,last_modified:?string} $conditional
	 * @return array<string, string>
	 */
	private static function conditional_headers( array $conditional ): array {
		$headers = array();
		if ( null !== $conditional['etag'] ) {
			$headers['If-None-Match'] = $conditional['etag'];
		}
		if ( null !== $conditional['last_modified'] ) {
			$headers['If-Modified-Since'] = $conditional['last_modified'];
		}

		return $headers;
	}

	/** @param array<string, mixed> $response
	 * @return array{etag:?string,last_modified:?string}
	 */
	private static function response_conditional( array $response ): array {
		$etag          = GitHubApiClient::response_header( $response, 'etag' );
		$last_modified = GitHubApiClient::response_header( $response, 'last-modified' );
		return array(
			'etag'          => is_string( $etag ) && self::valid_etag( $etag ) ? $etag : null,
			'last_modified' => is_string( $last_modified )
				&& self::valid_last_modified( $last_modified )
					? $last_modified
					: null,
		);
	}

	/** @param array<string, mixed> $response
	 * @return array{limited:bool,remaining:?int,reset_at:?int,retry_after:int}
	 */
	private static function rate_limit( array $response, ?int $now = null ): array {
		$now       ??= time();
		$status      = GitHubApiClient::response_code( $response );
		$remaining   = self::non_negative_header(
			GitHubApiClient::response_header( $response, 'x-ratelimit-remaining' )
		);
		$reset       = GitHubApiClient::response_header( $response, 'x-ratelimit-reset' );
		$reset_at    = self::non_negative_header( $reset );
		$retry_after = in_array( $status, array( 403, 429 ), true )
			? self::positive_delay_header( GitHubApiClient::response_header( $response, 'retry-after' ) )
			: null;
		$limited     = 429 === $status
			|| ( 403 === $status && ( null !== $retry_after || 0 === $remaining ) );
		$cooldown    = 0;
		if ( $limited ) {
			$delays = array();
			if ( null !== $retry_after ) {
				$delays[] = $retry_after;
			}
			if ( 0 === $remaining && is_string( $reset ) && 1 === preg_match( '/\A\d+\z/D', $reset ) ) {
				if ( null === $reset_at || $reset_at > $now + 86400 ) {
					throw new RuntimeException( 'The GitHub rate-limit delay is invalid.' );
				}
				if ( $reset_at > $now ) {
					$delays[] = $reset_at - $now;
				}
			}
			$cooldown = array() === $delays ? 900 : max( $delays );
		}

		return array(
			'limited'     => $limited,
			'remaining'   => $remaining,
			'reset_at'    => $reset_at,
			'retry_after' => $cooldown,
		);
	}

	/**
	 * @param list<ListedRelease> $candidates
	 * @param array{etag:?string,last_modified:?string} $conditional
	 * @param array{limited:bool,remaining:?int,reset_at:?int,retry_after:int} $rate_limit
	 * @return ReleaseListing
	 */
	private static function listing_result(
		array $candidates,
		array $conditional,
		bool $not_modified,
		array $rate_limit,
		bool $search_exhausted
	): array {
		return array(
			'candidates'       => $candidates,
			'conditional'      => $conditional,
			'not_modified'     => $not_modified,
			'rate_limit'       => $rate_limit,
			'search_exhausted' => $search_exhausted,
		);
	}

	/** @return array{identity:string,name:string,sha256:string,size:int} */
	private function zip_asset( mixed $assets ): array {
		if ( ! is_array( $assets ) || ! array_is_list( $assets ) ) {
			throw new ReleaseFailure( 'package_incompatible' );
		}
		$matches = array_values(
			array_filter(
				$assets,
				static function ( mixed $asset ): bool {
					return is_array( $asset )
						&& is_string( $asset['name'] ?? null )
						&& self::valid_zip_name( $asset['name'] );
				}
			)
		);
		if ( 1 !== count( $matches ) ) {
			throw new ReleaseFailure( 'package_incompatible' );
		}

		$asset    = $matches[0];
		$identity = self::provider_identity( $asset['id'] ?? null );
		$size     = self::provider_positive_integer( $asset['size'] ?? null );
		$digest   = is_string( $asset['digest'] ?? null )
			? strtolower( $asset['digest'] )
			: '';
		if (
			null === $identity
			|| null === $size
			|| $size > $this->binding['maximum_artifact_bytes']
			|| 'uploaded' !== ( $asset['state'] ?? null )
			|| 1 !== preg_match( '/\Asha256:([a-f0-9]{64})\z/D', $digest, $digest_match )
		) {
			throw new ReleaseFailure( 'package_incompatible' );
		}

		return array(
			'identity' => $identity,
			'name'     => $asset['name'],
			'sha256'   => $digest_match[1],
			'size'     => $size,
		);
	}

	private static function valid_locator( mixed $value ): bool {
		return is_string( $value )
			&& 1 === preg_match(
				'/\A[A-Za-z0-9](?:[A-Za-z0-9-]{0,38})\/[A-Za-z0-9_.-]{1,100}\z/D',
				$value
			);
	}

	private static function canonical_decimal( mixed $value ): ?string {
		if ( is_int( $value ) && $value > 0 ) {
			return (string) $value;
		}
		return is_string( $value )
			&& 1 === preg_match( '/\A[1-9][0-9]{0,18}\z/D', $value )
				? $value
				: null;
	}

	private static function provider_identity( mixed $value ): ?string {
		return is_int( $value ) && $value > 0 ? (string) $value : null;
	}

	private static function provider_positive_integer( mixed $value ): ?int {
		return is_int( $value ) && $value > 0 ? $value : null;
	}

	private static function non_negative_header( ?string $value ): ?int {
		if ( null === $value || 1 !== preg_match( '/\A\d+\z/D', $value ) ) {
			return null;
		}
		$integer = filter_var( $value, FILTER_VALIDATE_INT );
		return false === $integer ? null : $integer;
	}

	private static function positive_delay_header( ?string $value ): ?int {
		if (
			null === $value
			|| 1 !== preg_match( '/\A[1-9]\d*\z/D', $value )
		) {
			return null;
		}
		$whole = $value;
		if (
			strlen( $whole ) > strlen( '86400' )
			|| ( strlen( $whole ) === strlen( '86400' ) && strcmp( $whole, '86400' ) > 0 )
		) {
			throw new RuntimeException( 'The GitHub rate-limit delay is invalid.' );
		}
		return (int) $whole;
	}

	private static function version_from_tag( string $tag ): ?string {
		if ( strlen( $tag ) > ReleaseVersion::MAX_LENGTH + 1 ) {
			return null;
		}
		$version = str_starts_with( $tag, 'v' ) ? substr( $tag, 1 ) : $tag;
		return ReleaseVersion::normalize( $version );
	}

	private static function valid_release_uri( mixed $uri, string $locator ): bool {
		return is_string( $uri ) && hash_equals( 'https://github.com/' . $locator, $uri );
	}

	private static function valid_release_page( mixed $url, string $locator ): bool {
		if (
			! is_string( $url )
			|| strlen( $url ) > 2048
			|| 1 === preg_match( '/[\x00-\x20\x7f]/', $url )
		) {
			return false;
		}
		$parts = parse_url( $url );
		if (
			! is_array( $parts )
			|| 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) )
			|| 'github.com' !== strtolower( (string) ( $parts['host'] ?? '' ) )
			|| isset( $parts['user'] )
			|| isset( $parts['pass'] )
			|| isset( $parts['port'] )
			|| isset( $parts['query'] )
			|| isset( $parts['fragment'] )
			|| ! is_string( $parts['path'] ?? null )
		) {
			return false;
		}
		$path       = explode( '/', ltrim( $parts['path'], '/' ), 4 );
		$repository = explode( '/', $locator, 2 );
		return 4 === count( $path )
			&& 0 === strcasecmp( rawurldecode( $path[0] ), $repository[0] )
			&& 0 === strcasecmp( rawurldecode( $path[1] ), $repository[1] )
			&& 'releases' === $path[2]
			&& '' !== $path[3];
	}

	private static function valid_zip_name( string $name ): bool {
		return 1 === preg_match( '/\A[A-Za-z0-9][A-Za-z0-9._-]{0,215}\.zip\z/Di', $name );
	}

	private static function valid_etag( mixed $value ): bool {
		return is_string( $value )
			&& strlen( $value ) <= 512
			&& 1 === preg_match( '/\A(?:W\/)?"[\x21\x23-\x7E]*"\z/D', $value );
	}

	private static function valid_last_modified( mixed $value ): bool {
		return is_string( $value )
			&& strlen( $value ) <= 128
			&& 1 === preg_match(
				'/\A(?:Mon|Tue|Wed|Thu|Fri|Sat|Sun), [0-9]{2} '
				. '(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec) [0-9]{4} '
				. '[0-9]{2}:[0-9]{2}:[0-9]{2} GMT\z/D',
				$value
			);
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

	/**
	 * @param array<string, mixed> $value
	 * @param list<string> $keys
	 * @return array<string, mixed>
	 */
	private static function ordered( array $value, array $keys ): array {
		$ordered = array();
		foreach ( $keys as $key ) {
			$ordered[ $key ] = $value[ $key ];
		}
		return $ordered;
	}

	/** @return array{limited:bool,remaining:?int,reset_at:?int,retry_after:int} */
	private static function empty_rate_limit(): array {
		return array(
			'limited'     => false,
			'remaining'   => null,
			'reset_at'    => null,
			'retry_after' => 0,
		);
	}
}
