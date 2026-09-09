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

/** Shared, request-local GitHub release protocol for installed and prospective packages. */
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
	private GitHubArtifactStore $artifactStore;
	/** @var null|callable():?string */
	private $livenessGuard;

	/** @param array<string, mixed> $configuration */
	public function __construct(
		array $configuration,
		?GitHubCredentialResolver $credentials = null,
		?callable $livenessGuard = null
	) {
		if (
			! self::exactKeys( $configuration, self::CONFIGURATION_KEYS )
			|| ! self::validLocator( $configuration['canonical_repository_locator'] )
			|| ! is_int( $configuration['maximum_artifact_bytes'] )
			|| 0 >= $configuration['maximum_artifact_bytes']
			|| ! is_string( $configuration['stable_repository_identity'] )
			|| self::canonicalDecimal( $configuration['stable_repository_identity'] ) !== $configuration['stable_repository_identity']
			|| ! self::validReleaseUri(
				$configuration['canonical_update_uri'],
				$configuration['canonical_repository_locator']
			)
			|| ! in_array( $configuration['target_type'], array( 'plugin', 'theme' ), true )
			|| ! in_array( $configuration['release_channel'], array( 'stable', 'prerelease' ), true )
			|| ! is_string( $configuration['php_runtime_version'] )
			|| null === ReleaseVersion::normalizeHeader( $configuration['php_runtime_version'] )
			|| ! is_string( $configuration['wordpress_runtime_version'] )
			|| null === ReleaseVersion::normalizeHeader( $configuration['wordpress_runtime_version'] )
		) {
			throw new InvalidArgumentException( 'The GitHub release configuration is invalid.' );
		}

		$this->binding       = self::ordered( $configuration, self::CONFIGURATION_KEYS );
		$this->credentials   = $credentials ?? new GitHubCredentialResolver();
		$this->livenessGuard = $livenessGuard;
		$this->client        = new GitHubApiClient(
			function (): void {
				$this->assertLive();
			}
		);
		$this->artifactStore = new GitHubArtifactStore();
	}

	/** @internal Sealed-catalog composition for a prospective source. */
	public static function fromReleaseDeclaration( array $declaration, SelectedRuntimeState $state ): object {
		$keys = array( 'provider_code', 'target_type', 'repository_locator', 'repository_identity', 'channel', 'credential_resolver', 'maximum_artifact_bytes' );
		if ( ! self::exactKeys( $declaration, $keys ) || 'github' !== $declaration['provider_code'] ) {
			throw new InvalidArgumentException( 'The release declaration is invalid.' );
		}
		$wordpress = SelectedRuntimeState::normalizeWordPressVersion( $GLOBALS['wp_version'] ?? null );
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
			static fn (): ?string => $state->releaseReadinessCode()
		);
	}

	/** @return array<string,mixed> @internal Release-source operation. */
	public function list( array $conditional = array() ): array {
		try {
			$this->assertLive();
			$result = $this->listReleases( $conditional );
			$this->assertLive();
			if ( $result['rate_limit']['limited'] ) {
				throw new ReleaseFailure( 'rate_limited', $result['rate_limit']['retry_after'] );
			}
			return $result;
		} catch ( ReleaseFailure $failure ) {
			throw $failure; } catch ( \InvalidArgumentException $exception ) {
			throw new ReleaseFailure( 'invalid_configuration', null, 'not_applicable', $exception ); } catch ( \Throwable $exception ) {
				throw $this->operationFailure( $exception ); }
	}

	/** @return array<string,mixed> @internal Release-source operation. */
	public function inspect( string $releaseIdentity, string $expectedTag ): array {
		try {
			$this->assertLive();
			$result = $this->inspectProspective( $releaseIdentity, $expectedTag );
			$this->assertLive();
			return $result->toArray();
		} catch ( ReleaseFailure $failure ) {
			throw $failure; } catch ( \InvalidArgumentException $exception ) {
			throw new ReleaseFailure( 'invalid_release', null, 'not_applicable', $exception ); } catch ( \Throwable $exception ) {
				throw $this->operationFailure( $exception ); }
	}

	/** @return array{inspection:array<string,mixed>,artifact:TemporaryArtifact} @internal Release-source operation. */
	public function acquire( string $releaseIdentity, string $expectedTag, string $expectedFingerprint ): array {
		if ( 1 !== preg_match( '/\Av2:[a-f0-9]{64}\z/D', $expectedFingerprint ) ) {
			throw new ReleaseFailure( 'invalid_release' );
		}
		try {
			$this->assertLive();
			list($releaseIdentity, $expectedTag) = $this->inspectInput( $releaseIdentity, $expectedTag );
			list($fresh, $artifact)              = $this->prospectiveProof( $releaseIdentity, $expectedTag, $this->resolveCredentials(), true );
			if ( ! $artifact instanceof TemporaryArtifact || ! hash_equals( $expectedFingerprint, $fresh->fingerprintValue() ) ) {
				$clean = ! $artifact instanceof TemporaryArtifact || ( $artifact->discard() || $artifact->discard() );
				throw new ReleaseFailure( 'release_changed', null, $clean ? 'complete' : 'failed' );
			}
			try {
				$this->assertLive();
			} catch ( ReleaseFailure $failure ) {
				$clean = $artifact->discard() || $artifact->discard();
				throw new ReleaseFailure( $failure->releaseCode, $failure->retryAfter, $clean ? 'complete' : 'failed', $failure );
			}
			return array(
				'inspection' => $fresh->toArray(),
				'artifact'   => $artifact,
			);
		} catch ( ReleaseFailure $failure ) {
			throw $failure; } catch ( \InvalidArgumentException $exception ) {
			throw new ReleaseFailure( 'invalid_release', null, 'not_applicable', $exception ); } catch ( \Throwable $exception ) {
				throw $this->operationFailure( $exception ); }
	}

	/**
	 * @param array{etag?:string,last_modified?:string} $conditional
	 * @return array{
	 *   candidates:list<array{
	 *     details_url:string,
	 *     expected_asset_names:list<string>,
	 *     prerelease:bool,
	 *     publication_immutable:bool,
	 *     published_at:string,
	 *     release_identity:string,
	 *     tag:string,
	 *     version:string
	 *   }>,
	 *   conditional:array{etag:?string,last_modified:?string},
	 *   not_modified:bool,
	 *   rate_limit:array{limited:bool,remaining:?int,reset_at:?int,retry_after:int},
	 *   search_exhausted:bool
	 * }
	 */
	public function listReleases( array $conditional = array() ): array {
		$conditional = self::conditional( $conditional );
		try {
			$token           = $this->resolveCredentials();
			$candidates      = array();
			$seen            = array();
			$responseBytes   = 0;
			$searchExhausted = false;
			$nextConditional = array(
				'etag'          => null,
				'last_modified' => null,
			);
			$rateLimit       = self::emptyRateLimit();

			for ( $page = 1; $page <= self::MAX_PAGES; ++$page ) {
				$headers        = 1 === $page ? self::conditionalHeaders( $conditional ) : array();
				$remainingBytes = self::RELEASE_LIST_BYTES_LIMIT - $responseBytes;
				$response       = $this->client->request(
					$this->repositoryApiUrl()
					. '/releases?per_page=' . self::PAGE_SIZE . '&page=' . $page,
					$token,
					$headers,
					min( self::RELEASE_RESPONSE_LIMIT, $remainingBytes )
				);

				$status = GitHubApiClient::responseCode( $response );
				if ( 1 === $page ) {
					$nextConditional = self::responseConditional( $response );
				}
				$rateLimit = self::rateLimit( $response );
				if ( 1 === $page && 304 === $status ) {
					return self::listingResult(
						array(),
						$nextConditional,
						true,
						$rateLimit,
						false
					);
				}
				if ( $rateLimit['limited'] ) {
					return self::listingResult(
						array(),
						$nextConditional,
						false,
						$rateLimit,
						false
					);
				}
				self::requireSuccess( $response );

				$body           = GitHubApiClient::responseBody( $response, self::RELEASE_RESPONSE_LIMIT );
				$responseBytes += strlen( $body );
				if ( $responseBytes > self::RELEASE_LIST_BYTES_LIMIT ) {
					throw new RuntimeException( 'The GitHub release listing is too large.' );
				}

				$decoded = self::decodeList( $body );
				foreach ( $decoded as $release ) {
					$candidate = $this->listedRelease( $release );
					if (
					null !== $candidate
					&& ! isset( $seen[ $candidate['release_identity'] ] )
					) {
						$seen[ $candidate['release_identity'] ] = true;
						$candidates[]                           = $candidate;
					}
				}

				$pageFull = self::PAGE_SIZE === count( $decoded );
				if ( count( $candidates ) >= self::MAX_CANDIDATES || ! $pageFull ) {
					$searchExhausted = count( $candidates ) > self::MAX_CANDIDATES || $pageFull;
					break;
				}
				if ( self::MAX_PAGES === $page ) {
					$searchExhausted = true;
				}
			}

			usort(
				$candidates,
				static function ( array $left, array $right ): int {
					$comparison = ReleaseVersion::compare( $right['version'], $left['version'] );
					return 0 !== $comparison ? $comparison : strcmp( $left['release_identity'], $right['release_identity'] );
				}
			);

			return self::listingResult(
				array_slice( $candidates, 0, self::MAX_CANDIDATES ),
				$nextConditional,
				false,
				$rateLimit,
				$searchExhausted
			);
		} catch ( ReleaseFailure $failure ) {
			throw $failure; } catch ( \Throwable $exception ) {
			throw $this->operationFailure( $exception ); }
	}

	public function inspectInstalled(
		string $installedPackageIdentity,
		string $releaseIdentity,
		?string $expectedTag = null
	): IdentityDescriptor {
		if ( ! IdentityDescriptor::isBoundedOpaqueIdentity( $installedPackageIdentity, 255 ) ) {
			throw new InvalidArgumentException( 'The installed package identity is invalid.' );
		}
		list( $releaseIdentity, $expectedTag ) = $this->inspectInput(
			$releaseIdentity,
			$expectedTag
		);
		try {
			return $this->inspectWithToken(
				$installedPackageIdentity,
				$releaseIdentity,
				$expectedTag,
				$this->resolveCredentials()
			);
		} catch ( ReleaseFailure $failure ) {
			throw $failure; } catch ( \Throwable $exception ) {
			throw $this->operationFailure( $exception ); }
	}

	private function inspectWithToken(
		string $installedPackageIdentity,
		string $releaseIdentity,
		?string $expectedTag,
		?string $token
	): IdentityDescriptor {
		return IdentityDescriptor::create(
			array_merge(
				$this->releaseFacts( $releaseIdentity, $expectedTag, $token ),
				array(
					'installed_package_identity' => $installedPackageIdentity,
					'provider_code'              => 'github',
				)
			)
		);
	}

	public function acquireInstalled( IdentityDescriptor $descriptor ): TemporaryArtifact {
		list( $facts, $artifactIdentity ) = $this->acquisitionInput( $descriptor );
		try {
			return $this->acquireWithToken(
				$facts,
				$artifactIdentity,
				$this->resolveCredentials()
			);
		} catch ( ReleaseFailure $failure ) {
			throw $failure; } catch ( \Throwable $exception ) {
			throw $this->operationFailure( $exception ); }
	}

	private function acquireWithToken(
		array $facts,
		string $artifactIdentity,
		?string $token
	): TemporaryArtifact {
		$this->repositoryIdentity( $token );
		$path            = null;
		$initialIdentity = null;

		try {
			list($path, $initialIdentity) = $this->artifactStore->allocate( $facts['artifact_filename'] );
			$response                     = $this->client->request(
				$this->repositoryApiUrl() . '/releases/assets/' . $artifactIdentity,
				$token,
				array( 'Accept' => 'application/octet-stream' ),
				$this->binding['maximum_artifact_bytes'],
				$path
			);
			$this->requireAssetSuccess( $response );
			$identity = $this->artifactStore->identity( $path );
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

			$this->repositoryIdentity( $token );
			$this->assertLive();
			return new TemporaryArtifact( $path, $sha256, $identity, $this->livenessGuard );
		} catch ( \Throwable $exception ) {
			if ( is_string( $path ) && is_array( $initialIdentity ) ) {
				$clean = $this->artifactStore->remove( $path, $initialIdentity );
			} elseif ( $exception instanceof GitHubArtifactCustodyFailure ) {
				$clean = $exception->cleanupComplete;
			} else {
				throw $exception;
			}
			throw $this->postAllocationFailure( $exception, $clean );
		}
	}

	public function inspectProspective(
		string $releaseIdentity,
		?string $expectedTag = null
	): ProspectiveReleaseInspection {
		list( $releaseIdentity, $expectedTag ) = $this->inspectInput(
			$releaseIdentity,
			$expectedTag
		);
		list($inspection, $artifact)           = $this->prospectiveProof(
			$releaseIdentity,
			$expectedTag,
			$this->resolveCredentials(),
			false
		);
		unset( $artifact );
		return $inspection;
	}

	public function acquireProspective(
		ProspectiveReleaseInspection $inspection,
		string $expectedFingerprint
	): ProspectiveReleaseArtifact {
		$this->assertProspectiveInput( $inspection, $expectedFingerprint );
		list($fresh, $artifact) = $this->prospectiveProof(
			$inspection->releaseIdentity(),
			$inspection->tag(),
			$this->resolveCredentials(),
			true
		);
		if (
			! $artifact instanceof TemporaryArtifact
			|| ! hash_equals( $expectedFingerprint, $fresh->fingerprintValue() )
			|| ! hash_equals( $inspection->fingerprintValue(), $fresh->fingerprintValue() )
		) {
			if ( $artifact instanceof TemporaryArtifact ) {
				$artifact->discard();
			}
			throw new RuntimeException( 'The prospective GitHub release changed before acquisition.' );
		}
		return new ProspectiveReleaseArtifact( $fresh, $artifact );
	}

	/** @return array{ProspectiveReleaseInspection,?TemporaryArtifact} */
	private function prospectiveProof(
		string $releaseIdentity,
		?string $expectedTag,
		?string $token,
		bool $retainArtifact
	): array {
		$release          = $this->releaseFacts( $releaseIdentity, $expectedTag, $token );
		$artifactIdentity = self::canonicalDecimal( $release['artifact_identity'] )
			?? throw new RuntimeException( 'The GitHub artifact identity is invalid.' );
		$artifact         = $this->acquireWithToken( $release, $artifactIdentity, $token );
		try {
			$validator = new PackageIdentityValidator();
			$package   = $artifact->inspect(
				fn ( string $path ): ?array => $validator->inspectProspective(
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
			throw $this->postAllocationFailure( $exception, $clean );
		}
		if ( ! $retainArtifact ) {
			if ( ! ( $artifact->discard() || $artifact->discard() ) ) {
				throw new ReleaseFailure( 'cleanup_failed', null, 'failed' );
			}
			return array( $inspection, null );
		}
		return array( $inspection, $artifact );
	}

	/** @return array{string,?string} */
	private function inspectInput( string $releaseIdentity, ?string $expectedTag ): array {
		$releaseIdentity = self::canonicalDecimal( $releaseIdentity )
			?? throw new InvalidArgumentException( 'The GitHub release identity is invalid.' );
		if ( null !== $expectedTag && null === self::versionFromTag( $expectedTag ) ) {
			throw new InvalidArgumentException( 'The expected GitHub release tag is invalid.' );
		}
		return array( $releaseIdentity, $expectedTag );
	}

	/** @return array{array<string,mixed>,string} */
	private function acquisitionInput( IdentityDescriptor $descriptor ): array {
		$facts            = $descriptor->toArray();
		$artifactIdentity = self::canonicalDecimal( $facts['artifact_identity'] ?? null );
		if (
			'github' !== $facts['provider_code']
			|| null === $artifactIdentity
			|| ! $this->matchesConfiguration( $facts )
		) {
			throw new InvalidArgumentException( 'The GitHub artifact identity is invalid.' );
		}
		return array( $facts, $artifactIdentity );
	}

	/** @return array<string, mixed> */
	private function releaseFacts(
		string $expectedRelease,
		?string $expectedTag,
		?string $token
	): array {
		$repositoryIdentity = $this->repositoryIdentity( $token );
		$release            = $this->jsonSuccess(
			$this->client->request(
				$this->repositoryApiUrl() . '/releases/' . $expectedRelease,
				$token,
				array(),
				self::RELEASE_RESPONSE_LIMIT
			),
			self::RELEASE_RESPONSE_LIMIT,
			'release'
		);
		$releaseIdentity    = self::providerIdentity( $release['id'] ?? null );
		if (
			null === $releaseIdentity
			|| ! hash_equals( $expectedRelease, $releaseIdentity )
			|| ! is_bool( $release['draft'] ?? null )
			|| ! is_bool( $release['prerelease'] ?? null )
			|| ! is_bool( $release['immutable'] ?? null )
			|| $release['draft']
			|| ! is_string( $release['tag_name'] ?? null )
		) {
			throw new ReleaseFailure( 'package_incompatible' );
		}

		$tag     = $release['tag_name'];
		$version = self::versionFromTag( $tag );
		if (
			null === $version
			|| ( null !== $expectedTag && ! hash_equals( $expectedTag, $tag ) )
			|| (
				'stable' === $this->binding['release_channel']
				&& ( $release['prerelease'] || ReleaseVersion::isPrerelease( $version ) )
			)
			|| ! self::validReleasePage(
				$release['html_url'] ?? null,
				$this->binding['canonical_repository_locator']
			)
		) {
			throw new ReleaseFailure( 'package_incompatible' );
		}

		$asset          = $this->zipAsset( $release['assets'] ?? null );
		$commit         = $this->jsonSuccess(
			$this->client->request(
				$this->repositoryApiUrl() . '/commits/' . rawurlencode( $tag ),
				$token,
				array(),
				self::COMMIT_RESPONSE_LIMIT
			),
			self::COMMIT_RESPONSE_LIMIT,
			'commit'
		);
		$commitIdentity = is_string( $commit['sha'] ?? null )
			? strtolower( $commit['sha'] )
			: '';
		if ( 1 !== preg_match( '/\A[a-f0-9]{40}\z/D', $commitIdentity ) ) {
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
			'commit_identity'      => $commitIdentity,
			'prerelease'           => $release['prerelease'],
			'release_identity'     => $releaseIdentity,
			'repository_identity'  => $repositoryIdentity,
			'repository_locator'   => $this->binding['canonical_repository_locator'],
			'tag'                  => $tag,
			'target_type'          => $this->binding['target_type'],
			'version'              => $version,
		);
	}

	private function assertProspectiveInput(
		ProspectiveReleaseInspection $inspection,
		string $expectedFingerprint
	): void {
		$facts = $inspection->toArray();
		if (
			1 !== preg_match( '/\Av2:[a-f0-9]{64}\z/D', $expectedFingerprint )
			|| ! hash_equals( $inspection->fingerprintValue(), $expectedFingerprint )
			|| ! $this->matchesConfiguration( $facts )
		) {
			throw new InvalidArgumentException( 'The prospective GitHub release acquisition is invalid.' );
		}
		$this->inspectInput( $inspection->releaseIdentity(), $inspection->tag() );
	}

	/** @param array<string, mixed> $facts */
	private function matchesConfiguration( array $facts ): bool {
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

	/**
	 * @return array{
	 *   details_url:string,
	 *   expected_asset_names:list<string>,
	 *   prerelease:bool,
	 *   publication_immutable:bool,
	 *   published_at:string,
	 *   release_identity:string,
	 *   tag:string,
	 *   version:string
	 * }|null
	 */
	private function listedRelease( mixed $release ): ?array {
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

		$releaseIdentity = self::providerIdentity( $release['id'] ?? null );
		$version         = self::versionFromTag( $release['tag_name'] );
		$prerelease      = $release['prerelease']
			|| ( is_string( $version ) && ReleaseVersion::isPrerelease( $version ) );
		if (
			null === $releaseIdentity
			|| null === $version
			|| ( 'stable' === $this->binding['release_channel'] && $prerelease )
			|| 1 !== preg_match(
				'/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?Z\z/D',
				$release['published_at']
			)
			|| ! self::validReleasePage(
				$release['html_url'] ?? null,
				$this->binding['canonical_repository_locator']
			)
		) {
			return null;
		}

		$assetNames = array();
		if ( is_array( $release['assets'] ?? null ) ) {
			foreach ( $release['assets'] as $asset ) {
				$name = is_array( $asset ) && is_string( $asset['name'] ?? null )
					? $asset['name']
					: '';
				if ( self::validZipName( $name ) ) {
					$assetNames[] = $name;
				}
			}
		}

		return array(
			'details_url'           => $release['html_url'],
			'expected_asset_names'  => array_slice( $assetNames, 0, 2 ),
			'prerelease'            => $prerelease,
			'publication_immutable' => $release['immutable'],
			'published_at'          => $release['published_at'],
			'release_identity'      => $releaseIdentity,
			'tag'                   => $release['tag_name'],
			'version'               => $version,
		);
	}

	private function repositoryIdentity( ?string $token ): string {
		$expected   = $this->binding['stable_repository_identity'];
		$repository = $this->jsonSuccess(
			$this->client->request(
				$this->repositoryApiUrl(),
				$token,
				array(),
				self::RELEASE_RESPONSE_LIMIT
			),
			self::RELEASE_RESPONSE_LIMIT,
			'repository'
		);
		$actual     = self::providerIdentity( $repository['id'] ?? null );
		if ( null === $actual || ! hash_equals( $expected, $actual ) ) {
			throw new ReleaseFailure( 'operation_failed' );
		}

		return $actual;
	}

	private function resolveCredentials(): ?string {
		$this->assertLive();
		$credential = $this->credentials->resolve();
		$this->assertLive();
		return $credential;
	}

	private function assertLive(): void {
		if ( null === $this->livenessGuard ) {
			return;
		}
		try {
			$code = ( $this->livenessGuard )();
		} catch ( \Throwable $exception ) {
			throw new ReleaseFailure( 'runtime_unavailable', null, 'not_applicable', $exception );
		}
		if ( null !== $code ) {
			throw new ReleaseFailure( 'runtime_unavailable' );
		}
	}

	private function operationFailure( \Throwable $exception ): ReleaseFailure {
		if ( $exception instanceof ReleaseFailure ) {
			return $exception;
		}
		if ( $exception instanceof GitHubReleaseReadUnavailable && 1001 === $exception->getCode() ) {
			return new ReleaseFailure( 'credential_unavailable', null, 'not_applicable', $exception );
		}
		return new ReleaseFailure( 'operation_failed', null, 'not_applicable', $exception );
	}

	private function postAllocationFailure( \Throwable $exception, bool $clean ): ReleaseFailure {
		$cleanup = $clean ? 'complete' : 'failed';
		if ( $exception instanceof ReleaseFailure ) {
			return new ReleaseFailure( $exception->releaseCode, $exception->retryAfter, $cleanup, $exception );
		}
		if ( $exception instanceof GitHubReleaseReadUnavailable ) {
			return new ReleaseFailure( 'operation_failed', null, $cleanup, $exception );
		}
		if ( 1002 === $exception->getCode() ) {
			return new ReleaseFailure( 'runtime_unavailable', null, $cleanup, $exception );
		}
		return new ReleaseFailure( 'operation_failed', null, $cleanup, $exception );
	}

	/** @return array<string, mixed> */
	private function jsonSuccess(
		array $response,
		int $limit,
		string $context
	): array {
		$rateLimit = self::rateLimit( $response );
		if ( $rateLimit['limited'] ) {
			throw new ReleaseFailure( 'rate_limited', $rateLimit['retry_after'] );
		}
		self::requireSuccess( $response, 'repository' === $context );
		try {
			return self::decodeObject( GitHubApiClient::responseBody( $response, $limit ) );
		} catch ( \Throwable $exception ) {
			throw new ReleaseFailure( 'operation_failed', null, 'not_applicable', $exception );
		}
	}

	/** @param array<string,mixed> $response */
	private function requireAssetSuccess( array $response ): void {
		$rateLimit = self::rateLimit( $response );
		if ( $rateLimit['limited'] ) {
			throw new ReleaseFailure( 'rate_limited', $rateLimit['retry_after'] );
		}
		self::requireSuccess( $response, false );
	}

	private function repositoryApiUrl(): string {
		list($owner, $repository) = explode(
			'/',
			$this->binding['canonical_repository_locator'],
			2
		);
		return $this->client->api( '/repos/' . rawurlencode( $owner ) . '/' . rawurlencode( $repository ) );
	}


	/** @param array<string, mixed> $response */
	private static function requireSuccess( array $response, bool $missingIsReadUnavailable = true ): void {
		$status = GitHubApiClient::responseCode( $response );
		if ( in_array( $status, array( 401, 403 ), true ) || ( $missingIsReadUnavailable && 404 === $status ) ) {
			throw new ReleaseFailure( 'repository_access_unavailable' );
		}
		if ( ! $missingIsReadUnavailable && 404 === $status ) {
			throw new ReleaseFailure( 'release_unavailable' );
		}
		if ( $status < 200 || $status > 299 ) {
			throw new ReleaseFailure( 'operation_failed' );
		}
	}

	/** @return list<array<string, mixed>> */
	private static function decodeList( string $body ): array {
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
	private static function decodeObject( string $body ): array {
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
		$etag         = $conditional['etag'] ?? null;
		$lastModified = $conditional['last_modified'] ?? null;
		if (
			( null !== $etag && ! self::validEtag( $etag ) )
			|| ( null !== $lastModified && ! self::validLastModified( $lastModified ) )
		) {
			throw new InvalidArgumentException( 'The GitHub conditional state is invalid.' );
		}

		return array(
			'etag'          => $etag,
			'last_modified' => $lastModified,
		);
	}

	/** @param array{etag:?string,last_modified:?string} $conditional
	 * @return array<string, string>
	 */
	private static function conditionalHeaders( array $conditional ): array {
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
	private static function responseConditional( array $response ): array {
		$etag         = GitHubApiClient::responseHeader( $response, 'etag' );
		$lastModified = GitHubApiClient::responseHeader( $response, 'last-modified' );
		return array(
			'etag'          => is_string( $etag ) && self::validEtag( $etag ) ? $etag : null,
			'last_modified' => is_string( $lastModified )
				&& self::validLastModified( $lastModified )
					? $lastModified
					: null,
		);
	}

	/** @param array<string, mixed> $response
	 * @return array{limited:bool,remaining:?int,reset_at:?int,retry_after:int}
	 */
	private static function rateLimit( array $response, ?int $now = null ): array {
		$now      ??= time();
		$status     = GitHubApiClient::responseCode( $response );
		$remaining  = self::nonNegativeHeader(
			GitHubApiClient::responseHeader( $response, 'x-ratelimit-remaining' )
		);
		$reset      = GitHubApiClient::responseHeader( $response, 'x-ratelimit-reset' );
		$resetAt    = self::nonNegativeHeader( $reset );
		$retryAfter = in_array( $status, array( 403, 429 ), true )
			? self::positiveDelayHeader( GitHubApiClient::responseHeader( $response, 'retry-after' ) )
			: null;
		$limited    = 429 === $status
			|| ( 403 === $status && ( null !== $retryAfter || 0 === $remaining ) );
		$cooldown   = 0;
		if ( $limited ) {
			$delays = array();
			if ( null !== $retryAfter ) {
				$delays[] = $retryAfter;
			}
			if ( 0 === $remaining && is_string( $reset ) && 1 === preg_match( '/\A\d+\z/D', $reset ) ) {
				if ( null === $resetAt || $resetAt > $now + 86400 ) {
					throw new RuntimeException( 'The GitHub rate-limit delay is invalid.' );
				}
				if ( $resetAt > $now ) {
					$delays[] = $resetAt - $now;
				}
			}
			$cooldown = array() === $delays ? 900 : max( $delays );
		}

		return array(
			'limited'     => $limited,
			'remaining'   => $remaining,
			'reset_at'    => $resetAt,
			'retry_after' => $cooldown,
		);
	}

	/** @param list<array<string, mixed>> $candidates
	 * @param array{etag:?string,last_modified:?string} $conditional
	 * @param array{limited:bool,remaining:?int,reset_at:?int,retry_after:int} $rateLimit
	 * @return array<string, mixed>
	 */
	private static function listingResult(
		array $candidates,
		array $conditional,
		bool $notModified,
		array $rateLimit,
		bool $searchExhausted
	): array {
		return array(
			'candidates'       => $candidates,
			'conditional'      => $conditional,
			'not_modified'     => $notModified,
			'rate_limit'       => $rateLimit,
			'search_exhausted' => $searchExhausted,
		);
	}

	/** @return array{identity:string,name:string,sha256:string,size:int} */
	private function zipAsset( mixed $assets ): array {
		if ( ! is_array( $assets ) || ! array_is_list( $assets ) ) {
			throw new ReleaseFailure( 'package_incompatible' );
		}
		$matches = array_values(
			array_filter(
				$assets,
				static function ( mixed $asset ): bool {
					return is_array( $asset )
						&& is_string( $asset['name'] ?? null )
						&& self::validZipName( $asset['name'] );
				}
			)
		);
		if ( 1 !== count( $matches ) ) {
			throw new ReleaseFailure( 'package_incompatible' );
		}

		$asset    = $matches[0];
		$identity = self::providerIdentity( $asset['id'] ?? null );
		$size     = self::providerPositiveInteger( $asset['size'] ?? null );
		$digest   = is_string( $asset['digest'] ?? null )
			? strtolower( $asset['digest'] )
			: '';
		if (
			null === $identity
			|| null === $size
			|| $size > $this->binding['maximum_artifact_bytes']
			|| 'uploaded' !== ( $asset['state'] ?? null )
			|| 1 !== preg_match( '/\Asha256:([a-f0-9]{64})\z/D', $digest, $digestMatch )
		) {
			throw new ReleaseFailure( 'package_incompatible' );
		}

		return array(
			'identity' => $identity,
			'name'     => $asset['name'],
			'sha256'   => $digestMatch[1],
			'size'     => $size,
		);
	}

	private static function validLocator( mixed $value ): bool {
		return is_string( $value )
			&& 1 === preg_match(
				'/\A[A-Za-z0-9](?:[A-Za-z0-9-]{0,38})\/[A-Za-z0-9_.-]{1,100}\z/D',
				$value
			);
	}

	private static function canonicalDecimal( mixed $value ): ?string {
		if ( is_int( $value ) && $value > 0 ) {
			return (string) $value;
		}
		return is_string( $value )
			&& 1 === preg_match( '/\A[1-9][0-9]{0,18}\z/D', $value )
				? $value
				: null;
	}

	private static function providerIdentity( mixed $value ): ?string {
		return is_int( $value ) && $value > 0 ? (string) $value : null;
	}

	private static function providerPositiveInteger( mixed $value ): ?int {
		return is_int( $value ) && $value > 0 ? $value : null;
	}

	private static function nonNegativeHeader( ?string $value ): ?int {
		if ( null === $value || 1 !== preg_match( '/\A\d+\z/D', $value ) ) {
			return null;
		}
		$integer = filter_var( $value, FILTER_VALIDATE_INT );
		return false === $integer ? null : $integer;
	}

	private static function positiveDelayHeader( ?string $value ): ?int {
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

	private static function versionFromTag( string $tag ): ?string {
		if ( strlen( $tag ) > ReleaseVersion::MAX_LENGTH + 1 ) {
			return null;
		}
		$version = str_starts_with( $tag, 'v' ) ? substr( $tag, 1 ) : $tag;
		return ReleaseVersion::normalize( $version );
	}

	private static function validReleaseUri( mixed $uri, string $locator ): bool {
		return is_string( $uri ) && hash_equals( 'https://github.com/' . $locator, $uri );
	}

	private static function validReleasePage( mixed $url, string $locator ): bool {
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

	private static function validZipName( string $name ): bool {
		return 1 === preg_match( '/\A[A-Za-z0-9][A-Za-z0-9._-]{0,215}\.zip\z/Di', $name );
	}

	private static function validEtag( mixed $value ): bool {
		return is_string( $value )
			&& strlen( $value ) <= 512
			&& 1 === preg_match( '/\A(?:W\/)?"[\x21\x23-\x7E]*"\z/D', $value );
	}

	private static function validLastModified( mixed $value ): bool {
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
	private static function exactKeys( mixed $value, array $keys ): bool {
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

	/** @param array<string, mixed> $value @param list<string> $keys @return array<string, mixed> */
	private static function ordered( array $value, array $keys ): array {
		$ordered = array();
		foreach ( $keys as $key ) {
			$ordered[ $key ] = $value[ $key ];
		}
		return $ordered;
	}

	/** @return array{limited:bool,remaining:?int,reset_at:?int,retry_after:int} */
	private static function emptyRateLimit(): array {
		return array(
			'limited'     => false,
			'remaining'   => null,
			'reset_at'    => null,
			'retry_after' => 0,
		);
	}
}
