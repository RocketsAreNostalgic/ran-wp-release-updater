<?php

declare(strict_types=1);

namespace Tests\Runtime;

use PHPUnit\Framework\TestCase;

final class P03PairedCompositionTest extends TestCase
{
	private string $root;

	protected function setUp(): void
	{
		$this->root = dirname(__DIR__, 2) . '/.workspaces/p0.3/php-tmp/p03-paired-' . bin2hex(random_bytes(6));
		mkdir($this->root, 0700, true);
	}

	/** @dataProvider targetTypes */
	public function testConciseGithubCompositionMatchesTheExplicitNativePath(string $type): void
	{
		$result = $this->probe($type);

		self::assertSame($result['concise']['binding'], $result['explicit']['binding']);
		self::assertSame($result['concise']['binding']['binding_hash'], $result['explicit']['binding']['binding_hash']);
		self::assertSame($result['concise']['archive_policy'], $result['explicit']['archive_policy']);
		self::assertSame($result['concise']['receipt'], $result['explicit']['receipt']);
		$conciseStatus = $result['concise']['status'];
		$explicitStatus = $result['explicit']['status'];
		self::assertIsInt($conciseStatus['last_check']);
		self::assertIsInt($explicitStatus['last_check']);
		unset($conciseStatus['last_check'], $explicitStatus['last_check']);
		self::assertSame($conciseStatus, $explicitStatus);
		self::assertSame($result['concise']['offer'], $result['explicit']['offer']);
		self::assertSame($result['concise']['hook_names'], $result['explicit']['hook_names']);
		self::assertSame($result['concise']['hook_count'], $result['explicit']['hook_count']);
		self::assertSame('RAN\\WPReleaseUpdater\\V1\\Provider\\GitHub\\GitHubReleaseAdapter', $result['concise']['adapter']);
		self::assertSame($result['concise']['adapter'], $result['explicit']['adapter']);
		self::assertSame('target_active', $result['handle']['code']);
		self::assertTrue($result['handle']['hooks_registered']);
		self::assertSame('archive_identity_verified', $result['concise']['status']['candidate_validation_code']);
		self::assertTrue($result['concise']['receipt']['archive_identity_verified']);
		self::assertTrue($result['concise']['receipt']['package_identity_verified']);
		self::assertSame('2.0.0', $result['concise']['offer']['version']);
		self::assertSame('plugin' === $type ? 10 : 9, $result['concise']['hook_count']);
	}

	/** @return array<string,array{string}> */
	public static function targetTypes(): array
	{
		return array('plugin' => array('plugin'), 'theme' => array('theme'));
	}

	/** @return array<string,mixed> */
	private function probe(string $type): array
	{
		$runtime = $this->packageCopy();
		$installed = $this->installed($type);
		$probe = $this->root . '/probe-' . bin2hex(random_bytes(6)) . '.php';
		$data = array('bootstrap' => $runtime . '/bootstrap.php', 'installed' => $installed, 'type' => $type);
		$prefix = <<<'PHP'
<?php
define('WP_PLUGIN_DIR', __ROOT__);
require_once __DATABASE__;
function add_filter(string $hook, mixed $callback, int $priority, int $arguments): void {
	$GLOBALS['p03_paired_hooks'][] = array('hook' => $hook, 'callback' => $callback);
}
function add_action(string $hook, mixed $callback, int $priority, int $arguments): void {
	$GLOBALS['p03_paired_hooks'][] = array('hook' => $hook, 'callback' => $callback);
}
function get_filesystem_method(): string { return 'direct'; }
$GLOBALS['p03_paired_hooks'] = array();
$GLOBALS['wpdb'] = new \Tests\Support\FakeOptionDatabase(100);
$GLOBALS['wp_version'] = '6.8.0';
$GLOBALS['wp_theme_directories'] = array(__ROOT__);
$data = __DATA__;
PHP;
		$prefix = str_replace('__ROOT__', var_export($this->root, true), $prefix, $count);
		self::assertSame(2, $count);
		$prefix = str_replace('__DATABASE__', var_export(dirname(__DIR__) . '/Support/FakeOptionDatabase.php', true), $prefix, $count);
		self::assertSame(1, $count);
		$prefix = str_replace('__DATA__', var_export($data, true), $prefix, $count);
		self::assertSame(1, $count);
		$body = <<<'PHP'
$registrar = require $data['bootstrap'];
$resolver = static fn (): string => 'test-only-credential';
$handle = 'plugin' === $data['type']
	? $registrar->plugin('github', $data['installed'], 'acme/example', '123456789', 'stable', 'manual', $resolver)
	: $registrar->theme('github', $data['installed'], 'acme/example', '123456789', 'stable', 'manual', $resolver);
$handle->register();
$broker = $GLOBALS['ran_wp_release_updater_v1_broker'];
$broker->activate(array('php_version' => PHP_VERSION, 'runtime_protocol' => 2, 'wordpress_version' => '6.8.0'));
$handoff = (new ReflectionProperty($broker, 'handoff'))->getValue($broker);
$targets = (new ReflectionProperty($handoff, 'targets'))->getValue($handoff);
$conciseHandle = array_values($targets)[0]['handle'];
$concise = (new ReflectionProperty($conciseHandle, 'native'))->getValue($conciseHandle);
$identity = 'plugin' === $data['type'] ? 'plugin/main.php' : 'theme';
$headerFile = 'plugin' === $data['type'] ? 'main.php' : 'style.css';
$headers = array(
	'Author' => '', 'Description' => '', 'Name' => 'Example', 'RequiresPHP' => '',
	'RequiresWP' => '', 'UpdateURI' => 'https://github.com/acme/example', 'Version' => '1.0.0', 'PluginURI' => '',
);
$binding = \RAN\WPReleaseUpdater\V1\Contract\BindingRecord::create(array(
	'canonical_repository_locator' => 'acme/example', 'canonical_update_uri' => 'https://github.com/acme/example',
	'installed_package_identity' => $identity, 'maximum_artifact_bytes' => 52428800, 'network_id' => 1, 'php_runtime_version' => PHP_VERSION,
	'provider_code' => 'github', 'release_channel' => 'stable', 'stable_repository_identity' => '123456789',
	'target_type' => $data['type'], 'theme_template' => '', 'update_policy' => 'manual', 'wordpress_runtime_version' => '6.8.0',
));
$configuration = array(
	'headers' => $headers,
	'installed_package_identity' => $identity,
	'policy' => 'manual',
	'target_type' => $data['type'],
	'update_uri' => 'https://github.com/acme/example',
);
$policy = array(
	'archive_root' => $data['type'], 'configuration_update_uri' => 'https://github.com/acme/example',
	'header_file' => $headerFile, 'installed_package_identity' => $identity, 'maximum_artifact_bytes' => 52428800, 'metadata_name' => 'Example',
	'offer_update_uri' => 'https://github.com/acme/example', 'php_runtime_version' => PHP_VERSION,
	'provider_code' => 'github', 'repository_identity' => '123456789', 'repository_locator' => 'acme/example',
	'staged_package_update_uri' => 'https://github.com/acme/example', 'target_type' => $data['type'], 'theme_template' => '',
	'wordpress_runtime_version' => '6.8.0',
);
$explicitDatabase = clone $GLOBALS['wpdb'];
$explicit = \RAN\WPReleaseUpdater\V1\Provider\GitHub\GitHubReleaseAdapter::registerFromConfiguration(
	$configuration,
	$binding,
	new \RAN\WPReleaseUpdater\V1\Provider\GitHub\GitHubCredentialResolver($resolver),
	$explicitDatabase,
	$policy,
);
if (! $explicit instanceof \RAN\WPReleaseUpdater\V1\WordPress\NativePluginUpdater) {
	throw new RuntimeException('Explicit GitHub composition failed.');
}
$conciseBinding = (new ReflectionProperty($concise, 'binding'))->getValue($concise)->toArray();
$conciseHeaders = (new ReflectionProperty($concise, 'headers'))->getValue($concise);
$concisePolicy = (new ReflectionProperty($concise, 'archivePolicy'))->getValue($concise);
$explicitBinding = (new ReflectionProperty($explicit, 'binding'))->getValue($explicit)->toArray();
$explicitHeaders = (new ReflectionProperty($explicit, 'headers'))->getValue($explicit);
$explicitPolicy = (new ReflectionProperty($explicit, 'archivePolicy'))->getValue($explicit);
if ($conciseBinding !== $explicitBinding || $conciseHeaders !== $explicitHeaders || $concisePolicy !== $explicitPolicy) {
	throw new RuntimeException('Real GitHub compositions diverged.');
}
$conciseAdapter = get_class((new ReflectionProperty($concise, 'adapter'))->getValue($concise));
$explicitAdapter = get_class((new ReflectionProperty($explicit, 'adapter'))->getValue($explicit));
$hooks = $GLOBALS['p03_paired_hooks'];
$archive = dirname($data['installed']) . '/repository.zip';
$zip = new ZipArchive();
if (true !== $zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE)) throw new RuntimeException('Archive fixture failed.');
$entry = 'plugin' === $data['type'] ? 'plugin/main.php' : 'theme/style.css';
$zip->addFromString($entry, "/*\n" . ucfirst($data['type']) . " Name: Example\nVersion: 2.0.0\nUpdate URI: https://github.com/acme/example\n*/\n");
$zip->close();
$archiveHash = hash_file('sha256', $archive);
$archiveSize = filesize($archive);
$descriptor = \RAN\WPReleaseUpdater\V1\Contract\IdentityDescriptor::create(array(
	'artifact_filename' => 'repository.zip', 'artifact_identity' => 'asset:8',
	'artifact_sha256' => $archiveHash, 'artifact_size' => $archiveSize,
	'assurance_facts' => array('exact_artifact_identity' => true, 'exact_commit_identity' => true,
		'exact_reacquisition_supported' => true, 'exact_release_identity' => true, 'provenance_verified' => true,
		'publication_immutable' => true, 'repository_identity_stable' => true, 'trusted_digest_source' => true),
	'canonical_update_uri' => 'https://github.com/acme/example', 'channel' => 'stable',
	'commit_identity' => str_repeat('a', 40), 'installed_package_identity' => $identity, 'prerelease' => false,
	'provider_code' => 'github', 'release_identity' => '7', 'repository_identity' => '123456789',
	'repository_locator' => 'acme/example', 'tag' => 'v2.0.0', 'target_type' => $data['type'], 'version' => '2.0.0',
));
$adapter = new class($descriptor, $archive) implements \RAN\WPReleaseUpdater\V1\Contract\ReleaseAdapter {
	public function __construct(private \RAN\WPReleaseUpdater\V1\Contract\IdentityDescriptor $descriptor, private string $archive) {}
	public function listReleases(array $conditional = array()): array {
		return array('candidates' => array(array('release_identity' => '7', 'tag' => 'v2.0.0', 'version' => '2.0.0')));
	}
	public function inspect(string $releaseIdentity, ?string $expectedTag = null): \RAN\WPReleaseUpdater\V1\Contract\IdentityDescriptor {
		return $this->descriptor;
	}
	public function acquire(\RAN\WPReleaseUpdater\V1\Contract\IdentityDescriptor $descriptor): \RAN\WPReleaseUpdater\V1\Archive\TemporaryArtifact {
		$path = $this->archive . '.' . bin2hex(random_bytes(4));
		copy($this->archive, $path);
		chmod($path, 0600);
		$stat = lstat($path);
		return new \RAN\WPReleaseUpdater\V1\Archive\TemporaryArtifact($path, hash_file('sha256', $path), array(
			'dev' => $stat['dev'], 'ino' => $stat['ino'], 'mode' => $stat['mode'], 'nlink' => $stat['nlink'],
			'uid' => $stat['uid'], 'gid' => $stat['gid'], 'size' => $stat['size'], 'mtime' => $stat['mtime'], 'ctime' => $stat['ctime'],
		));
	}
};
// Both real GitHub compositions have now been captured; fake only acquisition behaviour below.
foreach (array($concise, $explicit) as $native) {
	(new ReflectionProperty($native, 'adapter'))->setValue($native, $adapter);
}
$first = array_slice($hooks, 0, count($hooks) / 2);
$second = array_slice($hooks, count($hooks) / 2);
$invoke = static function (array $registered, object $native) use ($data, $identity, $headers): mixed {
	foreach ($registered as $entry) {
		if (is_array($entry['callback']) && $entry['callback'][0] === $native && str_starts_with($entry['hook'], 'update_')) {
			return $entry['callback'](false, array('Version' => '1.0.0', 'UpdateURI' => $headers['UpdateURI']), $identity, array());
		}
	}
	throw new RuntimeException('Update hook was not registered.');
};
$normalise = static fn (array $registered): array => array_values(array_map(static fn (array $entry): string => $entry['hook'], $registered));
$conciseOffer = $invoke($first, $concise);
$explicitOffer = $invoke($second, $explicit);
$extra = array($data['type'] => $identity, 'action' => 'update', 'type' => $data['type']);
$conciseArchive = $concise->filterPreDownload(false, $conciseOffer['package'], null, $extra);
$explicitArchive = $explicit->filterPreDownload(false, $explicitOffer['package'], null, $extra);
if (! is_string($conciseArchive) || ! is_string($explicitArchive)) throw new RuntimeException('Receipt fixture failed.');
$receiptFacts = static function (object $native): array {
	$receipt = (new ReflectionProperty($native, 'pendingReceipt'))->getValue($native);
	return (new ReflectionProperty($receipt, 'facts'))->getValue($receipt);
};
echo json_encode(array(
	'handle' => $handle->status(),
	'concise' => array(
		'adapter' => $conciseAdapter,
		'archive_policy' => $concisePolicy, 'binding' => $conciseBinding, 'hook_count' => count($first),
		'hook_names' => $normalise($first), 'offer' => $conciseOffer, 'receipt' => $receiptFacts($concise), 'status' => $concise->status(),
	),
	'explicit' => array(
		'adapter' => $explicitAdapter,
		'archive_policy' => $explicitPolicy, 'binding' => $explicitBinding, 'hook_count' => count($second),
		'hook_names' => $normalise($second), 'offer' => $explicitOffer, 'receipt' => $receiptFacts($explicit), 'status' => $explicit->status(),
	),
), JSON_THROW_ON_ERROR);
PHP;
		file_put_contents($probe, $prefix . "\n" . $body);
		$temporary = dirname(__DIR__, 2) . '/.workspaces/p0.3/php-tmp';
		$command = 'TMPDIR=' . escapeshellarg($temporary) . ' TMP=' . escapeshellarg($temporary)
			. ' TEMP=' . escapeshellarg($temporary) . ' '
			. escapeshellarg(PHP_BINARY) . ' -d sys_temp_dir=' . escapeshellarg($temporary) . ' ' . escapeshellarg($probe);
		exec($command, $output, $status);
		self::assertSame(0, $status, implode("\n", $output));
		return json_decode(implode("\n", $output), true, 512, JSON_THROW_ON_ERROR);
	}

	private function installed(string $type): string
	{
		$directory = $this->root . '/' . $type;
		mkdir($directory, 0700, true);
		$headers = "{$type} Name: Example\nVersion: 1.0.0\nUpdate URI: https://github.com/acme/example\n";
		if ('plugin' === $type) {
			$file = $directory . '/main.php';
			file_put_contents($file, "<?php\n/*\nPlugin Name: Example\nVersion: 1.0.0\nUpdate URI: https://github.com/acme/example\n*/\n");
			return $file;
		}
		$file = $directory . '/style.css';
		file_put_contents($file, "/*\nTheme Name: Example\nVersion: 1.0.0\nUpdate URI: https://github.com/acme/example\n*/\n");
		return $file;
	}

	private function packageCopy(): string
	{
		$source = dirname(__DIR__, 2);
		$copy = $this->root . '/runtime-' . bin2hex(random_bytes(6));
		mkdir($copy, 0700, true);
		copy($source . '/bootstrap.php', $copy . '/bootstrap.php');
		copy($source . '/runtime.php', $copy . '/runtime.php');
		$this->copyDirectory($source . '/src', $copy . '/src');
		$files = array('bootstrap.php', 'runtime.php');
		foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($copy . '/src', \FilesystemIterator::SKIP_DOTS)) as $file) {
			if ($file->isFile() && 'php' === $file->getExtension()) $files[] = str_replace('\\', '/', substr($file->getPathname(), strlen($copy) + 1));
		}
		sort($files, SORT_STRING);
		$payload = '';
		foreach ($files as $file) $payload .= $file . "\0" . hash_file('sha256', $copy . '/' . $file) . "\n";
		file_put_contents($copy . '/runtime-copy.json', json_encode(array(
			'package_revision' => hash('sha256', $payload), 'package_version' => '0.1.0-beta.3', 'php_floor' => '8.2.0',
			'runtime_file' => 'runtime.php', 'runtime_protocol' => 2, 'wordpress_floor' => '6.5.0',
		), JSON_THROW_ON_ERROR));
		return $copy;
	}

	private function copyDirectory(string $source, string $destination): void
	{
		mkdir($destination, 0700, true);
		foreach (scandir($source) ?: array() as $name) {
			if ('.' === $name || '..' === $name) continue;
			$from = $source . '/' . $name;
			is_dir($from) ? $this->copyDirectory($from, $destination . '/' . $name) : copy($from, $destination . '/' . $name);
		}
	}
}
