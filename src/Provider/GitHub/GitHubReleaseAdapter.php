<?php

declare(strict_types=1);

namespace RAN\WPReleaseUpdater\V1\Provider\GitHub;

use InvalidArgumentException;
use RAN\WPReleaseUpdater\V1\Archive\TemporaryArtifact;
use RAN\WPReleaseUpdater\V1\Contract\BindingRecord;
use RAN\WPReleaseUpdater\V1\Contract\IdentityDescriptor;
use RAN\WPReleaseUpdater\V1\Contract\ReleaseVersion;
use RAN\WPReleaseUpdater\V1\Contract\ReleaseAdapter;
use RuntimeException;

/** Bounded, target-bound GitHub release reader. It owns no WordPress hooks or state. */
final class GitHubReleaseAdapter implements ReleaseAdapter
{
	private const API_HOST = 'api.github.com';
	private const API_ORIGIN = 'https://' . self::API_HOST;
	private const MAX_CANDIDATES = 8;
	private const MAX_PAGES = 2;
	private const PAGE_SIZE = 20;
	private const RELEASE_LIST_BYTES_LIMIT = 524288;
	private const RELEASE_RESPONSE_LIMIT = 262144;
	private const COMMIT_RESPONSE_LIMIT = 16384;
	private const HTTP_TIMEOUT = 10;
	private const RELEASE_ASSET_HOSTS = array(
		'github-releases.githubusercontent.com',
		'objects.githubusercontent.com',
		'release-assets.githubusercontent.com',
	);

	/** @var array<string, mixed> */
	private array $binding;

	public function __construct(
		private BindingRecord $bindingRecord,
		private ?GitHubCredentialResolver $credentials = null
	) {
		$facts = $bindingRecord->toArray();
		if (
			'github' !== $facts['provider_code']
			|| ! self::validLocator($facts['canonical_repository_locator'])
			|| null === self::canonicalDecimal($facts['stable_repository_identity'])
			|| ! self::validReleaseUri(
				$facts['canonical_update_uri'],
				$facts['canonical_repository_locator']
			)
		) {
			throw new InvalidArgumentException('The GitHub binding is invalid.');
		}

		$this->binding = $facts;
		$this->credentials ??= new GitHubCredentialResolver();
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
	public function listReleases(array $conditional = array()): array
	{
		$conditional = self::conditional($conditional);
		$token = $this->credentials->resolve();
		$candidates = array();
		$seen = array();
		$responseBytes = 0;
		$searchExhausted = false;
		$nextConditional = array('etag' => null, 'last_modified' => null);
		$rateLimit = self::emptyRateLimit();

		for ($page = 1; $page <= self::MAX_PAGES; ++$page) {
			$headers = 1 === $page ? self::conditionalHeaders($conditional) : array();
			$remainingBytes = self::RELEASE_LIST_BYTES_LIMIT - $responseBytes;
			$response = $this->request(
				$this->repositoryApiUrl()
					. '/releases?per_page=' . self::PAGE_SIZE . '&page=' . $page,
				$token,
				$headers,
				min(self::RELEASE_RESPONSE_LIMIT, $remainingBytes)
			);

			$status = self::responseCode($response);
			if (1 === $page) {
				$nextConditional = self::responseConditional($response);
			}
			$rateLimit = self::rateLimit($response);
			if (1 === $page && 304 === $status) {
				return self::listingResult(
					array(),
					$nextConditional,
					true,
					$rateLimit,
					false
				);
			}
			if ($rateLimit['limited']) {
				return self::listingResult(
					array(),
					$nextConditional,
					false,
					$rateLimit,
					false
				);
			}
			self::requireSuccess($response);

			$body = self::responseBody($response, self::RELEASE_RESPONSE_LIMIT);
			$responseBytes += strlen($body);
			if ($responseBytes > self::RELEASE_LIST_BYTES_LIMIT) {
				throw new RuntimeException('The GitHub release listing is too large.');
			}

			$decoded = self::decodeList($body);
			foreach ($decoded as $release) {
				$candidate = $this->listedRelease($release);
				if (
					null !== $candidate
					&& ! isset($seen[$candidate['release_identity']])
				) {
					$seen[$candidate['release_identity']] = true;
					$candidates[] = $candidate;
				}
			}

			$pageFull = self::PAGE_SIZE === count($decoded);
			if (count($candidates) >= self::MAX_CANDIDATES || ! $pageFull) {
				$searchExhausted = count($candidates) > self::MAX_CANDIDATES || $pageFull;
				break;
			}
			if (self::MAX_PAGES === $page) {
				$searchExhausted = true;
			}
		}

		usort(
			$candidates,
			static function (array $left, array $right): int {
				return ReleaseVersion::compare($right['version'], $left['version'])
					?: strcmp($left['release_identity'], $right['release_identity']);
			}
		);

		return self::listingResult(
			array_slice($candidates, 0, self::MAX_CANDIDATES),
			$nextConditional,
			false,
			$rateLimit,
			$searchExhausted
		);
	}

	public function inspect(
		string $releaseIdentity,
		?string $expectedTag = null
	): IdentityDescriptor {
		$releaseIdentity = self::canonicalDecimal($releaseIdentity)
			?? throw new InvalidArgumentException('The GitHub release identity is invalid.');
		if (null !== $expectedTag && null === self::versionFromTag($expectedTag)) {
			throw new InvalidArgumentException('The expected GitHub release tag is invalid.');
		}

		$token = $this->credentials->resolve();
		$repositoryIdentity = $this->repositoryIdentity($token);
		$release = $this->jsonSuccess(
			$this->request(
				$this->repositoryApiUrl() . '/releases/' . $releaseIdentity,
				$token,
				array(),
				self::RELEASE_RESPONSE_LIMIT
			),
			self::RELEASE_RESPONSE_LIMIT
		);

		return $this->descriptor(
			$repositoryIdentity,
			$release,
			$releaseIdentity,
			$expectedTag,
			$token
		);
	}

	public function acquire(IdentityDescriptor $descriptor): TemporaryArtifact
	{
		BindingRecord::assertDescriptorBinding($descriptor, $this->bindingRecord);
		$facts = $descriptor->toArray();
		$artifactIdentity = self::canonicalDecimal($facts['artifact_identity'] ?? null);
		if ('github' !== $facts['provider_code'] || null === $artifactIdentity) {
			throw new InvalidArgumentException('The GitHub artifact identity is invalid.');
		}

		$token = $this->credentials->resolve();
		$this->repositoryIdentity($token);
		list($path, $initialIdentity) = $this->temporaryFile($facts['artifact_filename']);

		try {
			$response = $this->request(
				$this->repositoryApiUrl() . '/releases/assets/' . $artifactIdentity,
				$token,
				array('Accept' => 'application/octet-stream'),
				IdentityDescriptor::MAX_ARTIFACT_BYTES,
				$path
			);
			if (self::rateLimit($response)['limited']) {
				throw new RuntimeException('GitHub rate limited the artifact request.');
			}
			self::requireSuccess($response);
			$identity = self::fileIdentity($path);
			if (
				null === $identity
				|| 1 !== $identity['nlink']
				|| 0600 !== ($identity['mode'] & 0777)
				|| $identity['size'] !== $facts['artifact_size']
				|| $identity['size'] > IdentityDescriptor::MAX_ARTIFACT_BYTES
			) {
				throw new RuntimeException('The downloaded GitHub artifact is invalid.');
			}
			$sha256 = hash_file('sha256', $path);
			if (! is_string($sha256) || ! hash_equals($facts['artifact_sha256'], $sha256)) {
				throw new RuntimeException('The downloaded GitHub artifact is invalid.');
			}

			$this->repositoryIdentity($token);
			return new TemporaryArtifact($path, $sha256, $identity);
		} catch (\Throwable $exception) {
			self::removeOwnedFile($path, $initialIdentity);
			throw $exception;
		}
	}

	/** @param array<string, mixed> $release */
	private function descriptor(
		string $repositoryIdentity,
		array $release,
		string $expectedRelease,
		?string $expectedTag,
		?string $token
	): IdentityDescriptor {
		$releaseIdentity = self::providerIdentity($release['id'] ?? null);
		if (
			null === $releaseIdentity
			|| ! hash_equals($expectedRelease, $releaseIdentity)
			|| ! is_bool($release['draft'] ?? null)
			|| ! is_bool($release['prerelease'] ?? null)
			|| ! is_bool($release['immutable'] ?? null)
			|| $release['draft']
			|| ! is_string($release['tag_name'] ?? null)
		) {
			throw new RuntimeException('The GitHub release identity is invalid.');
		}

		$tag = $release['tag_name'];
		$version = self::versionFromTag($tag);
		if (
			null === $version
			|| (null !== $expectedTag && ! hash_equals($expectedTag, $tag))
			|| (
				'stable' === $this->binding['release_channel']
				&& ($release['prerelease'] || ReleaseVersion::isPrerelease($version))
			)
			|| ! self::validReleasePage(
				$release['html_url'] ?? null,
				$this->binding['canonical_repository_locator']
			)
		) {
			throw new RuntimeException('The GitHub release contract is invalid.');
		}

		$commit = $this->jsonSuccess(
			$this->request(
				$this->repositoryApiUrl() . '/commits/' . rawurlencode($tag),
				$token,
				array(),
				self::COMMIT_RESPONSE_LIMIT
			),
			self::COMMIT_RESPONSE_LIMIT
		);
		$commitIdentity = is_string($commit['sha'] ?? null)
			? strtolower($commit['sha'])
			: '';
		if (1 !== preg_match('/\A[a-f0-9]{40}\z/D', $commitIdentity)) {
			throw new RuntimeException('The GitHub commit identity is invalid.');
		}

		$asset = self::zipAsset($release['assets'] ?? null);
		$immutable = $release['immutable'];

		return IdentityDescriptor::create(
			array(
				'artifact_filename' => $asset['name'],
				'artifact_identity' => $asset['identity'],
				'artifact_sha256' => $asset['sha256'],
				'artifact_size' => $asset['size'],
				'assurance_facts' => array(
					'exact_artifact_identity' => true,
					'exact_commit_identity' => true,
					'exact_reacquisition_supported' => true,
					'exact_release_identity' => true,
					'provenance_verified' => $immutable,
					'publication_immutable' => $immutable,
					'repository_identity_stable' => true,
					'trusted_digest_source' => true,
				),
				'canonical_update_uri' => $this->binding['canonical_update_uri'],
				'channel' => $this->binding['release_channel'],
				'commit_identity' => $commitIdentity,
				'installed_package_identity' => $this->binding['installed_package_identity'],
				'prerelease' => $release['prerelease'],
				'provider_code' => 'github',
				'release_identity' => $releaseIdentity,
				'repository_identity' => $repositoryIdentity,
				'repository_locator' => $this->binding['canonical_repository_locator'],
				'tag' => $tag,
				'target_type' => $this->binding['target_type'],
				'version' => $version,
			)
		);
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
	private function listedRelease(mixed $release): ?array
	{
		if (
			! is_array($release)
			|| ! is_bool($release['draft'] ?? null)
			|| ! is_bool($release['prerelease'] ?? null)
			|| ! is_bool($release['immutable'] ?? null)
			|| $release['draft']
			|| ! is_string($release['tag_name'] ?? null)
			|| ! is_string($release['published_at'] ?? null)
		) {
			return null;
		}

		$releaseIdentity = self::providerIdentity($release['id'] ?? null);
		$version = self::versionFromTag($release['tag_name']);
		$prerelease = $release['prerelease']
			|| (is_string($version) && ReleaseVersion::isPrerelease($version));
		if (
			null === $releaseIdentity
			|| null === $version
			|| ('stable' === $this->binding['release_channel'] && $prerelease)
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
		if (is_array($release['assets'] ?? null)) {
			foreach ($release['assets'] as $asset) {
				$name = is_array($asset) && is_string($asset['name'] ?? null)
					? $asset['name']
					: '';
				if (self::validZipName($name)) {
					$assetNames[] = $name;
				}
			}
		}

		return array(
			'details_url' => $release['html_url'],
			'expected_asset_names' => array_slice($assetNames, 0, 2),
			'prerelease' => $prerelease,
			'publication_immutable' => $release['immutable'],
			'published_at' => $release['published_at'],
			'release_identity' => $releaseIdentity,
			'tag' => $release['tag_name'],
			'version' => $version,
		);
	}

	private function repositoryIdentity(?string $token): string
	{
		$expected = $this->binding['stable_repository_identity'];
		$repository = $this->jsonSuccess(
			$this->request(
				$this->api('/repositories/' . rawurlencode($expected)),
				$token,
				array(),
				self::RELEASE_RESPONSE_LIMIT
			),
			self::RELEASE_RESPONSE_LIMIT
		);
		$actual = self::providerIdentity($repository['id'] ?? null);
		if (null === $actual || ! hash_equals($expected, $actual)) {
			throw new RuntimeException('The GitHub repository identity changed.');
		}

		return $actual;
	}

	/** @return array<string, mixed> */
	private function request(
		string $url,
		?string $token,
		array $headers,
		int $limit,
		?string $filename = null
	): array {
		$headers = array_merge(
			array(
				'Accept' => 'application/vnd.github+json',
				'User-Agent' => 'ran-wp-release-updater/0.1.0-beta.1',
				'X-GitHub-Api-Version' => '2022-11-28',
			),
			$headers
		);
		if (null !== $token) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}

		$currentUrl = $url;
		$credentialsBound = true;
		for ($redirects = 0; ; ++$redirects) {
			$response = self::send($currentUrl, $headers, $limit, $filename);
			$status = self::responseCode($response);
			if (! in_array($status, array(301, 302, 303, 307, 308), true)) {
				return $response;
			}
			if ($redirects >= 1) {
				throw new RuntimeException('The GitHub redirect limit was exceeded.');
			}

			$nextUrl = self::validatedRedirectUrl(
				self::responseHeader($response, 'location')
			);
			if (null === $nextUrl) {
				throw new RuntimeException('The GitHub redirect is unsafe.');
			}
			$nextHost = strtolower((string) parse_url($nextUrl, PHP_URL_HOST));
			if (self::API_HOST !== $nextHost) {
				$credentialsBound = false;
			}
			if (! $credentialsBound) {
				unset($headers['Authorization']);
			}
			$currentUrl = $nextUrl;
		}
	}

	/** @return array<string, mixed> */
	private static function send(
		string $url,
		array $headers,
		int $limit,
		?string $filename
	): array {
		if (! function_exists('wp_safe_remote_get') || ! function_exists('is_wp_error')) {
			throw new RuntimeException('WordPress safe HTTP is unavailable.');
		}

		$args = array(
			'headers' => $headers,
			'limit_response_size' => $limit + 1,
			'redirection' => 0,
			'timeout' => self::HTTP_TIMEOUT,
		);
		if (null !== $filename) {
			$args['filename'] = $filename;
			$args['stream'] = true;
		}

		$response = wp_safe_remote_get($url, $args);
		if (is_wp_error($response) || ! is_array($response)) {
			throw new RuntimeException('The GitHub request failed.');
		}
		self::responseCode($response);
		if (null === $filename) {
			self::responseBody($response, $limit);
		}

		return $response;
	}

	/** @return array<string, mixed> */
	private function jsonSuccess(array $response, int $limit): array
	{
		$rateLimit = self::rateLimit($response);
		if ($rateLimit['limited']) {
			throw new RuntimeException('GitHub rate limited the release request.');
		}
		self::requireSuccess($response);
		return self::decodeObject(self::responseBody($response, $limit));
	}

	private function repositoryApiUrl(): string
	{
		list($owner, $repository) = explode(
			'/',
			$this->binding['canonical_repository_locator'],
			2
		);
		return $this->api('/repos/' . rawurlencode($owner) . '/' . rawurlencode($repository));
	}

	private function api(string $path): string
	{
		return self::API_ORIGIN . $path;
	}

	/** @param array<string, mixed> $response */
	private static function requireSuccess(array $response): void
	{
		$status = self::responseCode($response);
		if ($status < 200 || $status > 299) {
			throw new RuntimeException('GitHub returned an unexpected response.');
		}
	}

	/** @param array<string, mixed> $response */
	private static function responseCode(array $response): int
	{
		if (! function_exists('wp_remote_retrieve_response_code')) {
			throw new RuntimeException('The WordPress HTTP response API is unavailable.');
		}
		$status = wp_remote_retrieve_response_code($response);
		if (is_string($status) && 1 === preg_match('/\A[1-5]\d{2}\z/D', $status)) {
			$status = (int) $status;
		}
		if (! is_int($status) || $status < 100 || $status > 599) {
			throw new RuntimeException('The GitHub response status is invalid.');
		}

		return $status;
	}

	/** @param array<string, mixed> $response */
	private static function responseHeader(array $response, string $name): ?string
	{
		if (! function_exists('wp_remote_retrieve_header')) {
			throw new RuntimeException('The WordPress HTTP response API is unavailable.');
		}
		$value = wp_remote_retrieve_header($response, $name);
		return is_string($value) || is_numeric($value) ? (string) $value : null;
	}

	/** @param array<string, mixed> $response */
	private static function responseBody(array $response, int $limit): string
	{
		if (! function_exists('wp_remote_retrieve_body')) {
			throw new RuntimeException('The WordPress HTTP response API is unavailable.');
		}
		$body = wp_remote_retrieve_body($response);
		if (! is_string($body) || strlen($body) > $limit) {
			throw new RuntimeException('The GitHub response body is invalid.');
		}

		return $body;
	}

	/** @return list<array<string, mixed>> */
	private static function decodeList(string $body): array
	{
		try {
			$decoded = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
		} catch (\JsonException $exception) {
			throw new RuntimeException('The GitHub response is invalid.', 0, $exception);
		}
		if (! is_array($decoded) || ! array_is_list($decoded)) {
			throw new RuntimeException('The GitHub response is invalid.');
		}

		foreach ($decoded as $item) {
			if (! is_array($item) || array_is_list($item)) {
				throw new RuntimeException('The GitHub response is invalid.');
			}
		}

		return $decoded;
	}

	/** @return array<string, mixed> */
	private static function decodeObject(string $body): array
	{
		try {
			$decoded = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
		} catch (\JsonException $exception) {
			throw new RuntimeException('The GitHub response is invalid.', 0, $exception);
		}
		if (! is_array($decoded) || array_is_list($decoded)) {
			throw new RuntimeException('The GitHub response is invalid.');
		}

		return $decoded;
	}

	/** @param array{etag?:string,last_modified?:string} $conditional
	 * @return array{etag:?string,last_modified:?string}
	 */
	private static function conditional(array $conditional): array
	{
		if (array_diff(array_keys($conditional), array('etag', 'last_modified'))) {
			throw new InvalidArgumentException('The GitHub conditional state is invalid.');
		}
		$etag = $conditional['etag'] ?? null;
		$lastModified = $conditional['last_modified'] ?? null;
		if (
			(null !== $etag && ! self::validEtag($etag))
			|| (null !== $lastModified && ! self::validLastModified($lastModified))
		) {
			throw new InvalidArgumentException('The GitHub conditional state is invalid.');
		}

		return array('etag' => $etag, 'last_modified' => $lastModified);
	}

	/** @param array{etag:?string,last_modified:?string} $conditional
	 * @return array<string, string>
	 */
	private static function conditionalHeaders(array $conditional): array
	{
		$headers = array();
		if (null !== $conditional['etag']) {
			$headers['If-None-Match'] = $conditional['etag'];
		}
		if (null !== $conditional['last_modified']) {
			$headers['If-Modified-Since'] = $conditional['last_modified'];
		}

		return $headers;
	}

	/** @param array<string, mixed> $response
	 * @return array{etag:?string,last_modified:?string}
	 */
	private static function responseConditional(array $response): array
	{
		$etag = self::responseHeader($response, 'etag');
		$lastModified = self::responseHeader($response, 'last-modified');
		return array(
			'etag' => is_string($etag) && self::validEtag($etag) ? $etag : null,
			'last_modified' => is_string($lastModified)
				&& self::validLastModified($lastModified)
					? $lastModified
					: null,
		);
	}

	/** @param array<string, mixed> $response
	 * @return array{limited:bool,remaining:?int,reset_at:?int,retry_after:int}
	 */
	private static function rateLimit(array $response): array
	{
		$status = self::responseCode($response);
		$remaining = self::nonNegativeHeader(
			self::responseHeader($response, 'x-ratelimit-remaining')
		);
		$resetAt = self::nonNegativeHeader(
			self::responseHeader($response, 'x-ratelimit-reset')
		);
		$retryAfter = self::positiveHeader(
			self::responseHeader($response, 'retry-after')
		);
		$limited = 429 === $status
			|| (403 === $status && (null !== $retryAfter || 0 === $remaining));
		$cooldown = 0;
		if ($limited) {
			$cooldown = $retryAfter ?? 900;
			if (null === $retryAfter && null !== $resetAt && $resetAt > time()) {
				$cooldown = $resetAt - time();
			}
			$cooldown = max(1, min(86400, $cooldown));
		}

		return array(
			'limited' => $limited,
			'remaining' => $remaining,
			'reset_at' => $resetAt,
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
			'candidates' => $candidates,
			'conditional' => $conditional,
			'not_modified' => $notModified,
			'rate_limit' => $rateLimit,
			'search_exhausted' => $searchExhausted,
		);
	}

	/** @return array{identity:string,name:string,sha256:string,size:int} */
	private static function zipAsset(mixed $assets): array
	{
		if (! is_array($assets) || ! array_is_list($assets)) {
			throw new RuntimeException('The GitHub release artifacts are invalid.');
		}
		$matches = array_values(
			array_filter(
				$assets,
				static function (mixed $asset): bool {
					return is_array($asset)
						&& is_string($asset['name'] ?? null)
						&& self::validZipName($asset['name']);
				}
			)
		);
		if (1 !== count($matches)) {
			throw new RuntimeException(
				'The GitHub release must contain exactly one uploaded ZIP artifact.'
			);
		}

		$asset = $matches[0];
		$identity = self::providerIdentity($asset['id'] ?? null);
		$size = self::providerPositiveInteger($asset['size'] ?? null);
		$digest = is_string($asset['digest'] ?? null)
			? strtolower($asset['digest'])
			: '';
		if (
			null === $identity
			|| null === $size
			|| $size > IdentityDescriptor::MAX_ARTIFACT_BYTES
			|| 'uploaded' !== ($asset['state'] ?? null)
			|| 1 !== preg_match('/\Asha256:([a-f0-9]{64})\z/D', $digest, $digestMatch)
		) {
			throw new RuntimeException('The GitHub release ZIP artifact is invalid.');
		}

		return array(
			'identity' => $identity,
			'name' => $asset['name'],
			'sha256' => $digestMatch[1],
			'size' => $size,
		);
	}

	/** @return array{0:string,1:array<string,int>} */
	private function temporaryFile(string $filename): array
	{
		if (! function_exists('wp_tempnam')) {
			throw new RuntimeException('WordPress temporary-file custody is unavailable.');
		}
		$path = wp_tempnam($filename);
		if (! is_string($path) || '' === $path) {
			throw new RuntimeException('A private temporary file could not be created.');
		}
		$createdIdentity = self::fileIdentity($path);
		if (null === $createdIdentity || ! @chmod($path, 0600)) {
			if (is_array($createdIdentity)) {
				self::removeOwnedFile($path, $createdIdentity);
			}
			throw new RuntimeException('A private temporary file could not be created.');
		}
		$identity = self::fileIdentity($path);
		if (null === $identity || 1 !== $identity['nlink']) {
			self::removeOwnedFile($path, $createdIdentity);
			throw new RuntimeException('The private temporary file is invalid.');
		}

		return array($path, $identity);
	}

	/** @return array<string, int>|null */
	private static function fileIdentity(string $path): ?array
	{
		clearstatcache(true, $path);
		$stat = @lstat($path);
		if (
			! is_array($stat)
			|| is_link($path)
			|| 0100000 !== ((int) $stat['mode'] & 0170000)
		) {
			return null;
		}

		return array(
			'dev' => (int) $stat['dev'],
			'ino' => (int) $stat['ino'],
			'mode' => (int) $stat['mode'],
			'nlink' => (int) $stat['nlink'],
			'uid' => (int) $stat['uid'],
			'gid' => (int) $stat['gid'],
			'size' => (int) $stat['size'],
			'mtime' => (int) $stat['mtime'],
			'ctime' => (int) $stat['ctime'],
		);
	}

	/** @param array<string, int> $identity */
	private static function removeOwnedFile(string $path, array $identity): void
	{
		$current = self::fileIdentity($path);
		if (
			is_array($current)
			&& $current['dev'] === $identity['dev']
			&& $current['ino'] === $identity['ino']
		) {
			@unlink($path);
		}
	}

	private static function validatedRedirectUrl(?string $url): ?string
	{
		if (
			null === $url
			|| '' === $url
			|| strlen($url) > 4096
			|| 1 === preg_match('/[\x00-\x1f\x7f]/', $url)
			|| ! function_exists('wp_http_validate_url')
			|| false === wp_http_validate_url($url)
		) {
			return null;
		}
		$parts = parse_url($url);
		if (
			! is_array($parts)
			|| 'https' !== strtolower((string) ($parts['scheme'] ?? ''))
			|| ! is_string($parts['host'] ?? null)
			|| isset($parts['user'])
			|| isset($parts['pass'])
			|| (isset($parts['port']) && 443 !== $parts['port'])
			|| isset($parts['fragment'])
		) {
			return null;
		}

		$host = strtolower($parts['host']);
		if (
			false !== filter_var($host, FILTER_VALIDATE_IP)
			|| (
				self::API_HOST !== $host
				&& ! in_array($host, self::RELEASE_ASSET_HOSTS, true)
			)
			|| self::signedUrlExpired((string) ($parts['query'] ?? ''))
		) {
			return null;
		}

		return $url;
	}

	private static function signedUrlExpired(string $query): bool
	{
		if ('' === $query) {
			return false;
		}
		$values = array();
		foreach (explode('&', $query) as $pair) {
			list($rawKey, $rawValue) = array_pad(explode('=', $pair, 2), 2, '');
			$key = strtolower(rawurldecode($rawKey));
			if (! in_array($key, array('se', 'expires', 'x-amz-date', 'x-amz-expires'), true)) {
				continue;
			}
			if (array_key_exists($key, $values)) {
				return true;
			}
			$values[$key] = rawurldecode($rawValue);
		}
		if (array() === $values) {
			return false;
		}

		$families = (array_key_exists('se', $values) ? 1 : 0)
			+ (array_key_exists('expires', $values) ? 1 : 0)
			+ (
				array_key_exists('x-amz-date', $values)
				|| array_key_exists('x-amz-expires', $values)
					? 1
					: 0
			);
		if (1 !== $families) {
			return true;
		}

		if (array_key_exists('se', $values)) {
			if (
				1 !== preg_match(
					'/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,7})?Z\z/D',
					$values['se']
				)
			) {
				return true;
			}
			$base = substr($values['se'], 0, 19) . 'Z';
			$expiresAt = self::exactUtcDate('!Y-m-d\TH:i:s\Z', $base);
			return null === $expiresAt || $expiresAt <= time();
		}

		if (array_key_exists('expires', $values)) {
			return 1 !== preg_match('/\A\d{1,12}\z/D', $values['expires'])
				|| (int) $values['expires'] <= time();
		}

		if (
			! isset($values['x-amz-date'], $values['x-amz-expires'])
			|| 1 !== preg_match('/\A\d{8}T\d{6}Z\z/D', $values['x-amz-date'])
			|| 1 !== preg_match('/\A\d{1,7}\z/D', $values['x-amz-expires'])
		) {
			return true;
		}
		$issuedAt = self::exactUtcDate('!Ymd\THis\Z', $values['x-amz-date']);
		return null === $issuedAt
			|| $issuedAt + (int) $values['x-amz-expires'] <= time();
	}

	private static function validLocator(mixed $value): bool
	{
		return is_string($value)
			&& 1 === preg_match(
				'/\A[A-Za-z0-9](?:[A-Za-z0-9-]{0,38})\/[A-Za-z0-9_.-]{1,100}\z/D',
				$value
			);
	}

	private static function canonicalDecimal(mixed $value): ?string
	{
		if (is_int($value) && $value > 0) {
			return (string) $value;
		}
		return is_string($value)
			&& 1 === preg_match('/\A[1-9][0-9]{0,18}\z/D', $value)
				? $value
				: null;
	}

	private static function providerIdentity(mixed $value): ?string
	{
		return is_int($value) && $value > 0 ? (string) $value : null;
	}

	private static function providerPositiveInteger(mixed $value): ?int
	{
		return is_int($value) && $value > 0 ? $value : null;
	}

	private static function exactUtcDate(string $format, string $value): ?int
	{
		$date = \DateTimeImmutable::createFromFormat(
			$format,
			$value,
			new \DateTimeZone('UTC')
		);
		$errors = \DateTimeImmutable::getLastErrors();
		if (
			false === $date
			|| (is_array($errors) && (0 !== $errors['warning_count'] || 0 !== $errors['error_count']))
			|| $date->format(substr($format, 1)) !== $value
		) {
			return null;
		}

		return $date->getTimestamp();
	}

	private static function nonNegativeHeader(?string $value): ?int
	{
		if (null === $value || 1 !== preg_match('/\A\d+\z/D', $value)) {
			return null;
		}
		$integer = filter_var($value, FILTER_VALIDATE_INT);
		return false === $integer ? null : $integer;
	}

	private static function positiveHeader(?string $value): ?int
	{
		$integer = self::nonNegativeHeader($value);
		return null !== $integer && $integer > 0 ? $integer : null;
	}

	private static function versionFromTag(string $tag): ?string
	{
		if (strlen($tag) > ReleaseVersion::MAX_LENGTH + 1) {
			return null;
		}
		$version = str_starts_with($tag, 'v') ? substr($tag, 1) : $tag;
		return ReleaseVersion::normalize($version);
	}

	private static function validReleaseUri(mixed $uri, string $locator): bool
	{
		return is_string($uri) && hash_equals('https://github.com/' . $locator, $uri);
	}

	private static function validReleasePage(mixed $url, string $locator): bool
	{
		if (
			! is_string($url)
			|| strlen($url) > 2048
			|| 1 === preg_match('/[\x00-\x20\x7f]/', $url)
		) {
			return false;
		}
		$parts = parse_url($url);
		if (
			! is_array($parts)
			|| 'https' !== strtolower((string) ($parts['scheme'] ?? ''))
			|| 'github.com' !== strtolower((string) ($parts['host'] ?? ''))
			|| isset($parts['user'])
			|| isset($parts['pass'])
			|| isset($parts['port'])
			|| isset($parts['query'])
			|| isset($parts['fragment'])
			|| ! is_string($parts['path'] ?? null)
		) {
			return false;
		}
		$path = explode('/', ltrim($parts['path'], '/'), 4);
		$repository = explode('/', $locator, 2);
		return 4 === count($path)
			&& 0 === strcasecmp(rawurldecode($path[0]), $repository[0])
			&& 0 === strcasecmp(rawurldecode($path[1]), $repository[1])
			&& 'releases' === $path[2]
			&& '' !== $path[3];
	}

	private static function validZipName(string $name): bool
	{
		return 1 === preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,215}\.zip\z/Di', $name);
	}

	private static function validEtag(mixed $value): bool
	{
		return is_string($value)
			&& strlen($value) <= 512
			&& 1 === preg_match('/\A(?:W\/)?"[\x21\x23-\x7E]*"\z/D', $value);
	}

	private static function validLastModified(mixed $value): bool
	{
		return is_string($value)
			&& strlen($value) <= 128
			&& 1 === preg_match(
				'/\A(?:Mon|Tue|Wed|Thu|Fri|Sat|Sun), [0-9]{2} '
				. '(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec) [0-9]{4} '
				. '[0-9]{2}:[0-9]{2}:[0-9]{2} GMT\z/D',
				$value
			);
	}

	/** @return array{limited:bool,remaining:?int,reset_at:?int,retry_after:int} */
	private static function emptyRateLimit(): array
	{
		return array(
			'limited' => false,
			'remaining' => null,
			'reset_at' => null,
			'retry_after' => 0,
		);
	}
}
