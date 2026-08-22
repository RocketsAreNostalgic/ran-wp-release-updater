<?php

declare(strict_types=1);

/*
 * Test-only semantic proof between the frozen GitHub implementation and the
 * selected neutral runtime. It never contacts GitHub or WordPress.
 */

const PAIRED_LEGACY_REVISION = '4586ffe4105565cf19f8b2e842fb78bd2e96d304';

final class WP_Error
{
	public function __construct(private string $code, private string $message)
	{
	}

	public function get_error_code(): string
	{
		return $this->code;
	}

	public function get_error_message(): string
	{
		return $this->message;
	}
}

function is_wp_error(mixed $value): bool
{
	return $value instanceof WP_Error;
}

/** @return array<string,mixed>|WP_Error */
function wp_safe_remote_get(string $url, array $arguments): array|WP_Error
{
	$GLOBALS['paired_neutral_requests'][] = array('url' => $url, 'arguments' => $arguments);
	$requestIndex = array_key_last($GLOBALS['paired_neutral_requests']);
	$response = array_shift($GLOBALS['paired_neutral_responses']);
	if (! is_array($response) && ! $response instanceof WP_Error) {
		throw new RuntimeException('The neutral fixture queue was exhausted.');
	}
	if (is_array($response) && isset($arguments['filename'], $response['file'])) {
		if (false === file_put_contents($arguments['filename'], $response['file'])) {
			throw new RuntimeException('Could not write the neutral fixture artifact.');
		}
		chmod($arguments['filename'], 0600);
	}
	if (is_array($response) && is_int($requestIndex)) {
		$GLOBALS['paired_neutral_requests'][$requestIndex]['status'] =
			$response['response']['code'] ?? null;
	}
	return $response;
}

function wp_remote_retrieve_response_code(array $response): int|string
{
	return $response['response']['code'] ?? 0;
}

function wp_remote_retrieve_header(array $response, string $name): mixed
{
	return $response['headers'][strtolower($name)] ?? null;
}

function wp_remote_retrieve_body(array $response): string
{
	return $response['body'] ?? '';
}

function wp_http_validate_url(string $url): string|false
{
	return $url;
}

function wp_tempnam(string $filename): string|false
{
	unset($filename);
	$root = $GLOBALS['paired_temp_root'] ?? null;
	if (! is_string($root) || ! is_dir($root)) {
		return false;
	}
	$path = tempnam($root, 'neutral-');
	if (is_string($path)) {
		chmod($path, 0600);
		$GLOBALS['paired_neutral_paths'][] = $path;
	}
	return $path;
}

/** @return never */
function paired_fail(string $message): never
{
	fwrite(STDERR, "paired-github-provider-proof: {$message}\n");
	exit(1);
}

function paired_assert(bool $condition, string $message): void
{
	if (! $condition) {
		paired_fail($message);
	}
}

function paired_root(string $root, string $path): bool
{
	$resolvedRoot = realpath($root);
	$resolvedPath = realpath($path);
	if (is_string($resolvedRoot) && is_string($resolvedPath)) {
		$root = $resolvedRoot;
		$path = $resolvedPath;
	}
	$root = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
	return str_starts_with($path, $root) && ! str_contains(substr($path, strlen($root)), '..');
}

function paired_remove_tree(string $root): void
{
	paired_assert(is_dir($root), 'Temporary root disappeared before cleanup.');
	paired_assert(0700 === (fileperms($root) & 0777), 'Temporary root permissions changed before cleanup.');
	$canonical = realpath($root);
	$tempRoot = realpath(sys_get_temp_dir());
	paired_assert(is_string($canonical) && is_string($tempRoot) && paired_root($tempRoot, $canonical), 'Temporary root escaped the system temp directory.');
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($canonical, FilesystemIterator::SKIP_DOTS),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ($iterator as $entry) {
		$path = $entry->getPathname();
		paired_assert(paired_root($canonical, $path), 'Refusing to clean an out-of-root path.');
		if ($entry->isLink() || $entry->isFile()) {
			paired_assert(@unlink($path), 'Could not remove proof file.');
		} else {
			paired_assert(@rmdir($path), 'Could not remove proof directory.');
		}
	}
	paired_assert(@rmdir($canonical), 'Could not remove proof root.');
}

/** @return string */
function paired_archive(string $legacyRepository, string $destination): string
{
	$process = proc_open(
		array('git', '-C', $legacyRepository, 'archive', '--format=tar', PAIRED_LEGACY_REVISION),
		array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
		$pipes
	);
	paired_assert(is_resource($process), 'Could not start git archive.');
	$tar = stream_get_contents($pipes[1]);
	$errors = stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);
	paired_assert(0 === proc_close($process) && is_string($tar) && '' !== $tar, 'Could not archive the pinned legacy revision: ' . trim((string) $errors));
	$archive = $destination . DIRECTORY_SEPARATOR . 'legacy.tar';
	paired_assert(false !== file_put_contents($archive, $tar), 'Could not save the legacy archive.');
	$source = $destination . DIRECTORY_SEPARATOR . 'legacy';
	paired_assert(mkdir($source, 0700), 'Could not create the legacy extraction root.');
	try {
		(new PharData($archive))->extractTo($source, null, true);
	} catch (Throwable $exception) {
		paired_fail('Could not extract the legacy archive: ' . $exception->getMessage());
	}
	paired_assert(is_file($source . '/src/Artifact/GitHubReleaseArtifactService.php'), 'Pinned legacy service was not extracted.');
	return $source;
}

/** @return array<string,mixed> */
function paired_response(int $status, mixed $body = '', array $headers = array(), ?string $file = null): array
{
	return array(
		'response' => array('code' => $status),
		'headers' => array_change_key_case($headers, CASE_LOWER),
		'body' => is_string($body) ? $body : json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
	) + (null === $file ? array() : array('file' => $file));
}

/** @return array<string,mixed> */
function paired_release(
	string $zip,
	string $tag = 'v1.2.3',
	bool $prerelease = false,
	bool $immutable = true
): array
{
	return array(
		'id' => 77,
		'tag_name' => $tag,
		'draft' => false,
		'prerelease' => $prerelease,
		'immutable' => $immutable,
		'published_at' => '2026-07-24T12:00:00Z',
		'html_url' => 'https://github.com/owner/repository/releases/tag/' . $tag,
		'assets' => array(array(
			'id' => 101,
			'name' => 'repository.zip',
			'size' => strlen($zip),
			'state' => 'uploaded',
			'digest' => 'sha256:' . hash('sha256', $zip),
		)),
	);
}

/** @return array<string,mixed> */
function paired_binding(
	string $channel = 'stable',
	string $targetType = 'plugin'
): array
{
	return array(
		'target_type' => $targetType,
		'installed_package_identity' => 'plugin' === $targetType
			? 'repository/repository.php'
			: 'repository',
		'provider_code' => 'github',
		'canonical_repository_locator' => 'owner/repository',
		'stable_repository_identity' => '123456789',
		'canonical_update_uri' => 'https://github.com/owner/repository',
		'release_channel' => $channel,
		'update_policy' => 'manual',
		'php_runtime_version' => '8.2',
		'wordpress_runtime_version' => '6.8',
	);
}

/** @return list<array<string,mixed>> */
function paired_requests(array $requests): array
{
	return array_map(
		static function (array $request): array {
			$headers = $request['headers'] ?? $request['arguments']['headers'] ?? array();
			return array(
				'url' => $request['url'],
				'status' => $request['status'] ?? null,
				'limit' => $request['limit'] ?? $request['arguments']['limit_response_size'] ?? null,
				'redirects' => $request['redirects'] ?? $request['arguments']['redirection'] ?? null,
				'authorization' => $headers['Authorization'] ?? null,
			);
		},
		$requests
	);
}

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ran-paired-github-' . bin2hex(random_bytes(12));
paired_assert(mkdir($root, 0700), 'Could not create the proof root.');
$GLOBALS['paired_temp_root'] = $root;
$GLOBALS['paired_neutral_requests'] = array();
$GLOBALS['paired_neutral_responses'] = array();
$GLOBALS['paired_neutral_paths'] = array();

try {
	$currentRoot = dirname(__DIR__, 2);
	$legacyRepository = getenv('RAN_WP_GITHUB_LEGACY_REPOSITORY');
	if (! is_string($legacyRepository) || '' === $legacyRepository) {
		$legacyRepository = dirname($currentRoot) . '/ran-wp-github-release-updater';
	}
	paired_assert(is_dir($legacyRepository . '/.git'), 'The legacy repository is unavailable.');
	$legacyRoot = paired_archive($legacyRepository, $root);

	spl_autoload_register(
		static function (string $class) use ($legacyRoot): void {
			$prefix = 'RAN\\WPGitHubReleaseUpdater\\V1\\';
			if (str_starts_with($class, $prefix)) {
				$file = $legacyRoot . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
				if (is_file($file)) {
					require_once $file;
				}
			}
		}
	);

	$revisionProcess = proc_open(
		array('git', '-C', $currentRoot, 'rev-parse', 'HEAD'),
		array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
		$revisionPipes
	);
	paired_assert(is_resource($revisionProcess), 'Could not resolve the neutral revision.');
	$neutralRevision = trim((string) stream_get_contents($revisionPipes[1]));
	$revisionErrors = trim((string) stream_get_contents($revisionPipes[2]));
	fclose($revisionPipes[1]);
	fclose($revisionPipes[2]);
	paired_assert(
		0 === proc_close($revisionProcess)
			&& 1 === preg_match('/\A[a-f0-9]{40}\z/D', $neutralRevision),
		'Could not resolve the neutral revision: ' . $revisionErrors
	);

	$headProcess = proc_open(
		array(
			'git',
			'-C',
			$currentRoot,
			'diff',
			'--quiet',
			$neutralRevision,
			'--',
			'src',
			'bootstrap.php',
			'runtime.php',
		),
		array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
		$headPipes
	);
	paired_assert(is_resource($headProcess), 'Could not compare the neutral production tree.');
	stream_get_contents($headPipes[1]);
	$headErrors = trim((string) stream_get_contents($headPipes[2]));
	fclose($headPipes[1]);
	fclose($headPipes[2]);
	paired_assert(
		0 === proc_close($headProcess),
		'Neutral production differs from the pinned revision: ' . $headErrors
	);
	require_once $currentRoot . '/runtime.php';

	final class PairedOldTransport implements \RAN\WPGitHubReleaseUpdater\V1\Http\Transport
	{
		/** @var list<array{response:\RAN\WPGitHubReleaseUpdater\V1\Http\Response,file:?string}> */
		private array $queue = array();
		/** @var list<array<string,mixed>> */
		public array $requests = array();
		public function queue(int $status, mixed $body = '', array $headers = array(), ?string $file = null): void
		{
			$this->queue[] = array('response' => new \RAN\WPGitHubReleaseUpdater\V1\Http\Response($status, $headers, is_string($body) ? $body : json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)), 'file' => $file);
		}
		public function get(\RAN\WPGitHubReleaseUpdater\V1\Http\Request $request)
		{
			$this->requests[] = array('url' => $request->url(), 'headers' => $request->headers(), 'limit' => $request->responseSizeLimit(), 'redirects' => $request->redirection());
			$requestIndex = array_key_last($this->requests);
			$next = array_shift($this->queue);
			if (! is_array($next)) {
				throw new RuntimeException('The legacy fixture queue was exhausted.');
			}
			if (is_string($next['file']) && is_string($request->streamTo())) {
				file_put_contents($request->streamTo(), $next['file']);
				chmod($request->streamTo(), 0600);
			}
			if (is_int($requestIndex)) {
				$this->requests[$requestIndex]['status'] = $next['response']->statusCode();
			}
			return $next['response'];
		}
	}

	final class PairedOldTemporaryFiles implements \RAN\WPGitHubReleaseUpdater\V1\Http\TemporaryFileFactory
	{
		/** @var list<string> */
		public array $paths = array();
		public function __construct(private string $root)
		{
		}
		public function create(string $filename)
		{
			unset($filename);
			$path = tempnam($this->root, 'legacy-');
			if (! is_string($path)) {
				return new WP_Error('temp', 'Could not create fixture.');
			}
			chmod($path, 0600);
			$this->paths[] = $path;
			return $path;
		}
		public function delete(string $path): void
		{
			paired_assert(paired_root($this->root, $path), 'Legacy cleanup escaped its proof root.');
			@unlink($path);
		}
	}

	$zip = "paired github fixture\n";
	$release = paired_release($zip);
	$repository = array('id' => 123456789, 'full_name' => 'owner/repository');
	$commit = array('sha' => str_repeat('a', 40));
	$legacyRepositoryObject = \RAN\WPGitHubReleaseUpdater\V1\Artifact\Repository::fromString('owner/repository', '123456789');
	paired_assert(! is_wp_error($legacyRepositoryObject), 'Could not configure legacy repository.');
	$oldToken = \RAN\WPGitHubReleaseUpdater\V1\Artifact\AccessToken::fromValue('private-token');
	paired_assert(! is_wp_error($oldToken), 'Could not configure legacy credential.');
	$oldPublicQuery = new \RAN\WPGitHubReleaseUpdater\V1\Artifact\ReleaseQuery($legacyRepositoryObject, 'stable', '8.2', '6.8', 8);
	$oldQuery = \RAN\WPGitHubReleaseUpdater\V1\Artifact\ReleaseQuery::prospective($legacyRepositoryObject, 'stable', '8.2', '6.8', 8, $oldToken);
	$newBinding = \RAN\WPReleaseUpdater\V1\Contract\BindingRecord::create(paired_binding());
	$newCredentials = new \RAN\WPReleaseUpdater\V1\Provider\GitHub\GitHubCredentialResolver(static fn (): string => 'private-token');

	/* Identical stable public listing, normalized to the shared release tuple. */
	$oldListTransport = new PairedOldTransport();
	$oldListTransport->queue(200, array($release));
	$oldList = (new \RAN\WPGitHubReleaseUpdater\V1\Artifact\GitHubReleaseArtifactService($oldListTransport))->listReleases($oldPublicQuery);
	paired_assert(! is_wp_error($oldList), 'Legacy stable listing failed.');
	$GLOBALS['paired_neutral_responses'] = array(paired_response(200, array($release)));
	$GLOBALS['paired_neutral_requests'] = array();
	$newList = (new \RAN\WPReleaseUpdater\V1\Provider\GitHub\GitHubReleaseAdapter($newBinding))->listReleases();
	$oldListTuple = array_map(static fn ($item): array => array((string) $item->releaseId(), $item->tag(), $item->version(), $item->expectedAssetNames(), $item->isPrerelease()), $oldList->releases());
	$newListTuple = array_map(static fn (array $item): array => array($item['release_identity'], $item['tag'], $item['version'], $item['expected_asset_names'], $item['prerelease']), $newList['candidates']);
	paired_assert($oldListTuple === $newListTuple, 'Stable listing tuples diverged.');
	paired_assert(paired_requests($oldListTransport->requests) === paired_requests($GLOBALS['paired_neutral_requests']), 'Stable listing request contract diverged.');

	/* Identical public prerelease-theme inspection, including normalized assurance truth. */
	$betaRelease = paired_release($zip, 'v2.0.0-beta.1', true, false);
	$oldBetaTransport = new PairedOldTransport();
	$oldBetaTransport->queue(200, $betaRelease);
	$oldBetaTransport->queue(200, $commit);
	$oldBetaQuery = new \RAN\WPGitHubReleaseUpdater\V1\Artifact\ReleaseQuery(
		$legacyRepositoryObject,
		'prerelease',
		'8.2',
		'6.8',
		8
	);
	$oldBetaDescriptor = (new \RAN\WPGitHubReleaseUpdater\V1\Artifact\GitHubReleaseArtifactService(
		$oldBetaTransport
	))->describeExact(
		new \RAN\WPGitHubReleaseUpdater\V1\Artifact\ExactReleaseRequest(
			$oldBetaQuery,
			77,
			'v2.0.0-beta.1'
		)
	);
	paired_assert(! is_wp_error($oldBetaDescriptor), 'Legacy prerelease-theme inspection failed.');
	$GLOBALS['paired_neutral_responses'] = array(
		paired_response(200, $repository),
		paired_response(200, $betaRelease),
		paired_response(200, $commit),
	);
	$GLOBALS['paired_neutral_requests'] = array();
	$newBetaDescriptor = (new \RAN\WPReleaseUpdater\V1\Provider\GitHub\GitHubReleaseAdapter(
		\RAN\WPReleaseUpdater\V1\Contract\BindingRecord::create(
			paired_binding('prerelease', 'theme')
		)
	))->inspect('77', 'v2.0.0-beta.1');
	$newBetaFacts = $newBetaDescriptor->toArray();
	$oldBetaAssurance = array(
		'exact_artifact_identity' => true,
		'exact_commit_identity' => true,
		'exact_reacquisition_supported' => true,
		'exact_release_identity' => true,
		'provenance_verified' => $oldBetaDescriptor->isImmutable(),
		'publication_immutable' => $oldBetaDescriptor->isImmutable(),
		'repository_identity_stable' => true,
		'trusted_digest_source' => true,
	);
	$oldBetaTuple = array(
		'theme',
		$oldBetaQuery->channel(),
		(string) $oldBetaDescriptor->releaseId(),
		$oldBetaDescriptor->tag(),
		$oldBetaDescriptor->version(),
		$oldBetaDescriptor->isPrerelease(),
		$oldBetaAssurance,
	);
	$newBetaTuple = array(
		$newBetaFacts['target_type'],
		$newBetaFacts['channel'],
		$newBetaFacts['release_identity'],
		$newBetaFacts['tag'],
		$newBetaFacts['version'],
		$newBetaFacts['prerelease'],
		$newBetaFacts['assurance_facts'],
	);
	paired_assert($oldBetaTuple === $newBetaTuple, 'Prerelease-theme descriptor tuples diverged.');
	paired_assert(
		2 === count($oldBetaTransport->requests)
		&& 3 === count($GLOBALS['paired_neutral_requests']),
		'Prerelease-theme request ceilings diverged from the pinned two/three contracts.'
	);

	/* Both rate-limit without treating an ordinary 403 as a valid release response. */
	$rateHeaders = array('x-ratelimit-remaining' => '0', 'x-ratelimit-reset' => '1500', 'retry-after' => '30');
	$oldRateTransport = new PairedOldTransport();
	$oldRateTransport->queue(403, '', $rateHeaders);
	$oldRate = (new \RAN\WPGitHubReleaseUpdater\V1\Artifact\GitHubReleaseArtifactService($oldRateTransport, null, static fn (): int => 1000))->listReleases($oldPublicQuery);
	paired_assert(! is_wp_error($oldRate) && $oldRate->rateLimit()->isLimited(), 'Legacy rate-limit classification failed.');
	$GLOBALS['paired_neutral_responses'] = array(paired_response(403, '', $rateHeaders));
	$GLOBALS['paired_neutral_requests'] = array();
	$newRate = (new \RAN\WPReleaseUpdater\V1\Provider\GitHub\GitHubReleaseAdapter($newBinding))->listReleases();
	$oldRateTuple = array(
		$oldRate->rateLimit()->isLimited(),
		$oldRate->rateLimit()->remaining(),
		$oldRate->rateLimit()->resetAt(),
		$oldRate->rateLimit()->cooldownSeconds(),
	);
	$newRateTuple = array(
		$newRate['rate_limit']['limited'],
		$newRate['rate_limit']['remaining'],
		$newRate['rate_limit']['reset_at'],
		$newRate['rate_limit']['retry_after'],
	);
	paired_assert($oldRateTuple === $newRateTuple, 'Exact rate-limit tuples diverged.');

	/* Ordinary forbidden responses normalize to failure and never to rate limiting. */
	$oldForbiddenTransport = new PairedOldTransport();
	$oldForbiddenTransport->queue(403);
	$oldForbidden = (new \RAN\WPGitHubReleaseUpdater\V1\Artifact\GitHubReleaseArtifactService(
		$oldForbiddenTransport
	))->listReleases($oldPublicQuery);
	paired_assert(is_wp_error($oldForbidden), 'Legacy ordinary forbidden response did not fail.');
	$oldForbiddenTuple = array(
		'github_updater_github_forbidden' === $oldForbidden->get_error_code()
			? 'forbidden'
			: 'other',
		$oldForbiddenTransport->requests[0]['status'] ?? null,
		false,
	);
	$GLOBALS['paired_neutral_responses'] = array(paired_response(403));
	$GLOBALS['paired_neutral_requests'] = array();
	$newForbiddenTuple = null;
	try {
		(new \RAN\WPReleaseUpdater\V1\Provider\GitHub\GitHubReleaseAdapter(
			$newBinding
		))->listReleases();
	} catch (RuntimeException $exception) {
		$newForbiddenTuple = array(
			'GitHub returned an unexpected response.' === $exception->getMessage()
				? 'forbidden'
				: 'other',
			$GLOBALS['paired_neutral_requests'][0]['status'] ?? null,
			false,
		);
	}
	paired_assert($oldForbiddenTuple === $newForbiddenTuple, 'Ordinary forbidden tuples diverged.');

	/* Private acquisition: same bytes/digest, request count, limits, redirect auth stripping, and one cleanup owner. */
	$oldTransport = new PairedOldTransport();
	$oldFiles = new PairedOldTemporaryFiles($root);
	$oldService = new \RAN\WPGitHubReleaseUpdater\V1\Artifact\GitHubReleaseArtifactService($oldTransport, $oldFiles, static fn (): int => 1000);
	$oldTransport->queue(200, $release);
	$oldTransport->queue(200, $commit);
	$oldDescriptor = $oldService->describeExact(new \RAN\WPGitHubReleaseUpdater\V1\Artifact\ExactReleaseRequest($oldQuery, 77, 'v1.2.3'));
	paired_assert(! is_wp_error($oldDescriptor), 'Legacy inspection failed.');
	$oldTransport->queue(302, '', array('location' => 'https://release-assets.githubusercontent.com/pair'));
	$oldTransport->queue(200, '', array(), $zip);
	$oldTransport->queue(200, $repository);
	$oldArtifact = $oldService->acquireDescribed($oldDescriptor);
	paired_assert(! is_wp_error($oldArtifact), 'Legacy acquisition failed.');
	$oldDigest = $oldArtifact->sha256();
	$oldClaim = $oldArtifact->claim();
	paired_assert(! is_wp_error($oldClaim), 'Legacy custody claim failed.');
	$oldPath = $oldClaim->path();
	paired_assert(is_file($oldPath) && $oldDigest === hash_file('sha256', $oldPath), 'Legacy custody bytes failed.');
	paired_assert($oldArtifact->discard() === false, 'Legacy artifact retained cleanup after claim.');
	paired_assert($oldClaim->discard(), 'Could not clean claimed legacy artifact.');

	$GLOBALS['paired_neutral_responses'] = array(
		paired_response(200, $repository), paired_response(200, $release), paired_response(200, $commit),
		paired_response(200, $repository),
		paired_response(302, '', array('location' => 'https://release-assets.githubusercontent.com/pair')),
		paired_response(200, '', array(), $zip),
		paired_response(200, $repository),
	);
	$GLOBALS['paired_neutral_requests'] = array();
	$newAdapter = new \RAN\WPReleaseUpdater\V1\Provider\GitHub\GitHubReleaseAdapter($newBinding, $newCredentials);
	$newDescriptor = $newAdapter->inspect('77', 'v1.2.3');
	$newArtifact = $newAdapter->acquire($newDescriptor);
	list($newDigest, $newPath) = $newArtifact->inspect(
		static fn (string $path): array => array((string) hash_file('sha256', $path), $path)
	);
	paired_assert(is_file($newPath) && $newDigest === hash_file('sha256', $newPath), 'Neutral custody bytes failed.');
	paired_assert($newArtifact->discard(), 'Neutral artifact cleanup failed.');
	paired_assert(! file_exists($newPath), 'Neutral artifact cleanup retained the temporary file.');

	$oldDescriptorTuple = array($oldDescriptor->releaseId(), $oldDescriptor->tag(), $oldDescriptor->version(), $oldDescriptor->commit(), $oldDescriptor->zipAsset()->id(), $oldDescriptor->zipAsset()->name(), $oldDescriptor->zipAsset()->size(), $oldDescriptor->zipAsset()->sha256(), $oldDescriptor->isImmutable());
	$newFacts = $newDescriptor->toArray();
	$newDescriptorTuple = array((int) $newFacts['release_identity'], $newFacts['tag'], $newFacts['version'], $newFacts['commit_identity'], (int) $newFacts['artifact_identity'], $newFacts['artifact_filename'], $newFacts['artifact_size'], $newFacts['artifact_sha256'], $newFacts['assurance_facts']['publication_immutable']);
	paired_assert($oldDescriptorTuple === $newDescriptorTuple, 'Inspection descriptor tuples diverged.');
	paired_assert($oldDigest === $newDigest, 'Acquisition digests diverged.');
	$oldRequests = paired_requests($oldTransport->requests);
	$newRequests = paired_requests($GLOBALS['paired_neutral_requests']);
	paired_assert(5 === count($oldRequests) && 7 === count($newRequests), 'Private acquisition request count departed from the pinned five/seven-request contracts.');
	paired_assert(262145 === $oldRequests[0]['limit'] && 262145 === $newRequests[0]['limit'] && 52428801 === $oldRequests[2]['limit'] && 52428801 === $newRequests[4]['limit'], 'Response-size limits diverged.');
	paired_assert('Bearer private-token' === $oldRequests[2]['authorization'] && 'Bearer private-token' === $newRequests[4]['authorization'] && null === $oldRequests[3]['authorization'] && null === $newRequests[5]['authorization'], 'Private redirect authorization stripping diverged.');

	echo json_encode(
		array(
			'legacy_revision' => PAIRED_LEGACY_REVISION,
			'neutral_revision' => $neutralRevision,
			'scenarios' => array(
				'ordinary_forbidden',
				'prerelease_theme_inspection',
				'private_redirect_acquisition',
				'rate_limit',
				'stable_listing',
			),
			'request_counts' => array(
				'legacy_prerelease_theme' => 2,
				'neutral_prerelease_theme' => 3,
				'legacy_private_redirect_acquisition' => 5,
				'neutral_private_redirect_acquisition' => 7,
			),
			'rate_limit' => $newRateTuple,
			'digest' => $newDigest,
		),
		JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
	) . "\n";
} finally {
	paired_remove_tree($root);
}
