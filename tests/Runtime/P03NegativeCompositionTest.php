<?php

declare(strict_types=1);

namespace Tests\Runtime;

use PHPUnit\Framework\TestCase;

final class P03NegativeCompositionTest extends TestCase
{
	private string $root;

	protected function setUp(): void
	{
		$this->root = dirname(__DIR__, 2) . '/.workspaces/p0.3/php-tmp/p03-negative-' . bin2hex(random_bytes(6));
		mkdir($this->root, 0700, true);
	}

	/** @dataProvider malformedInstalledPackageCases */
	public function testMalformedInstalledFactsFailBeforeSelectedGitHubComposition(
		string $type,
		string $headers,
		string $expectedCode
	): void {
		$file = 'plugin' === $type ? $this->plugin($headers) : $this->theme($headers);
		$result = $this->probe(<<<'PHP'
$credentials = 0;
$registrar = require $data['bootstrap'];
$resolver = static function () use (&$credentials): string { ++$credentials; return 'credential'; };
$handle = 'plugin' === $data['type']
	? $registrar->plugin('github', $data['installed'], 'acme/example', '123456789', 'stable', 'manual', $resolver)
	: $registrar->theme('github', $data['installed'], 'acme/example', '123456789', 'stable', 'manual', $resolver);
$registered = $handle->register();
$activation = $GLOBALS['ran_wp_release_updater_v1_broker']->activate(
	array('php_version' => PHP_VERSION, 'runtime_protocol' => 2, 'wordpress_version' => '6.8.0')
);
echo json_encode(array(
	'activation' => $activation['code'],
	'credentials' => $credentials,
	'database' => $GLOBALS['p03_database_calls'],
	'http' => $GLOBALS['p03_http_calls'],
	'hooks' => count($GLOBALS['p03_hooks']),
	'registered' => $registered,
	'status' => $handle->status(),
));
PHP, array('installed' => $file, 'type' => $type));

		self::assertSame('runtime_active', $result['activation']);
		self::assertTrue($result['registered']);
		self::assertSame($expectedCode, $result['status']['code']);
		self::assertFalse($result['status']['hooks_registered']);
		self::assertNull($result['status']['native']);
		self::assertSame(0, $result['hooks']);
		self::assertSame(0, $result['credentials']);
		self::assertSame(0, $result['http']);
		self::assertSame(0, $result['database']);
	}

	/** @return array<string,array{string,string,string}> */
	public static function malformedInstalledPackageCases(): array
	{
		return array(
			'plugin missing Update URI' => array('plugin', "Plugin Name: Broken\nVersion: 1.0.0\n", 'installed_header_missing'),
			'plugin ambiguous Update URI' => array(
				'plugin',
				"Plugin Name: Broken\nVersion: 1.0.0\nUpdate URI: https://github.com/acme/example\nUpdate URI: https://github.com/acme/example\n",
				'installed_header_ambiguous'
			),
			'theme malformed Template' => array(
				'theme',
				"Theme Name: Broken\nVersion: 1.0.0\nUpdate URI: https://github.com/acme/example\nTemplate: ../parent\n",
				'installed_header_invalid'
			),
			'theme GitHub URI mismatch' => array(
				'theme',
				"Theme Name: Broken\nVersion: 1.0.0\nUpdate URI: https://github.com/acme/other\n",
				'installed_update_uri_mismatch'
			),
		);
	}

	/** @dataProvider unsupportedFilesystemMethods */
	public function testEveryUnsupportedFilesystemMethodIsPassiveAcrossNativeCallbacks(string $method): void
	{
		$result = $this->probe(<<<'PHP'
$credentials = 0;
$registrar = require $data['bootstrap'];
$handle = $registrar->plugin(
	'github', $data['installed'], 'acme/example', '123456789', 'stable', 'manual',
	static function () use (&$credentials): string { ++$credentials; return 'credential'; }
);
$handle->register();
$GLOBALS['ran_wp_release_updater_v1_broker']->activate(
	array('php_version' => PHP_VERSION, 'runtime_protocol' => 2, 'wordpress_version' => '6.8.0')
);
$answers = array();
foreach ($GLOBALS['p03_hooks'] as $registered) {
	$hook = $registered['hook'];
	$callback = $registered['callback'];
	if ('update_plugins_github.com' === $hook) {
		$answers[$hook] = $callback(false, array('Version' => '1.0.0', 'UpdateURI' => 'https://github.com/acme/example'), 'plugin/main.php', array());
	} elseif ('plugins_api' === $hook) {
		$answers[$hook] = $callback('sentinel', 'plugin_information', (object) array('slug' => 'other'));
	} elseif ('auto_update_plugin' === $hook) {
		$answers[$hook] = $callback(true, (object) array('plugin' => 'plugin/main.php', 'package' => 'invalid'));
	} elseif ('upgrader_package_options' === $hook) {
		$answers[$hook] = $callback(array('hook_extra' => array('plugin' => 'plugin/main.php', 'action' => 'update', 'type' => 'plugin')));
	} elseif ('upgrader_pre_download' === $hook) {
		$answers[$hook] = $callback(false, 'invalid', null, array('plugin' => 'plugin/main.php', 'action' => 'update', 'type' => 'plugin'));
	} elseif ('upgrader_pre_install' === $hook) {
		$answers[$hook] = $callback('install', array('plugin' => 'plugin/main.php', 'action' => 'update', 'type' => 'plugin'));
	} elseif ('pre_unzip_file' === $hook) {
		$answers[$hook] = $callback('unzip', 'archive', 'destination', array(), 0.0);
	} elseif ('upgrader_source_selection' === $hook) {
		$answers[$hook] = $callback('source', 'remote', null, array('plugin' => 'plugin/main.php', 'action' => 'update', 'type' => 'plugin'));
	} elseif ('upgrader_install_package_result' === $hook) {
		$answers[$hook] = $callback('result', array('plugin' => 'plugin/main.php', 'action' => 'update', 'type' => 'plugin'));
	} elseif ('upgrader_process_complete' === $hook) {
		$callback(null, array('action' => 'update', 'type' => 'plugin', 'plugins' => array('plugin/main.php')));
	}
}
echo json_encode(array(
	'answers' => $answers,
	'credentials' => $credentials,
	'database' => $GLOBALS['p03_database_calls'],
	'http' => $GLOBALS['p03_http_calls'],
	'hooks' => count($GLOBALS['p03_hooks']),
	'status' => $handle->status(),
));
PHP, array('installed' => $this->plugin("Plugin Name: Example\nVersion: 1.0.0\nUpdate URI: https://github.com/acme/example\n"), 'method' => $method));

		self::assertSame(10, $result['hooks'], $method);
		self::assertSame(0, $result['credentials'], $method);
		self::assertSame(0, $result['http'], $method);
		self::assertSame(0, $result['database'], $method);
		self::assertSame('target_active', $result['status']['code'], $method);
		self::assertSame(false, $result['answers']['update_plugins_github.com'], $method);
		self::assertSame('sentinel', $result['answers']['plugins_api'], $method);
		self::assertFalse($result['answers']['auto_update_plugin'], $method);
		self::assertFalse($result['answers']['upgrader_pre_install'], $method);
		self::assertSame('unzip', $result['answers']['pre_unzip_file'], $method);
		self::assertFalse($result['answers']['upgrader_source_selection'], $method);
		self::assertFalse($result['answers']['upgrader_install_package_result'], $method);
	}

	/** @return array<string,array{string}> */
	public static function unsupportedFilesystemMethods(): array
	{
		return array('ftpext' => array('ftpext'), 'ftpsockets' => array('ftpsockets'), 'ssh2' => array('ssh2'));
	}

	private function plugin(string $headers): string
	{
		$directory = $this->root . '/plugin';
		mkdir($directory, 0700, true);
		$file = $directory . '/main.php';
		file_put_contents($file, "<?php\n/*\n{$headers}*/\n");
		return $file;
	}

	private function theme(string $headers): string
	{
		$directory = $this->root . '/theme';
		mkdir($directory, 0700, true);
		$file = $directory . '/style.css';
		file_put_contents($file, "/*\n{$headers}*/\n");
		return $file;
	}

	/** @param array<string,mixed> $data @return array<string,mixed> */
	private function probe(string $body, array $data): array
	{
		$runtime = $this->packageCopy();
		$probe = $this->root . '/probe-' . bin2hex(random_bytes(6)) . '.php';
		$data['bootstrap'] = $runtime . '/bootstrap.php';
		$prefix = '<?php define("WP_PLUGIN_DIR", ' . var_export($this->root, true) . '); '
			. 'function add_filter(string $hook,mixed $callback,int $priority,int $arguments):void{$GLOBALS["p03_hooks"][]=array("hook"=>$hook,"callback"=>$callback);} '
			. 'function add_action(string $hook,mixed $callback,int $priority,int $arguments):void{$GLOBALS["p03_hooks"][]=array("hook"=>$hook,"callback"=>$callback);} '
			. 'function get_filesystem_method():string{return $GLOBALS["p03_method"];} '
			. 'function wp_remote_get():mixed{++$GLOBALS["p03_http_calls"];return false;} '
			. '$GLOBALS["p03_hooks"]=array();$GLOBALS["p03_http_calls"]=0;$GLOBALS["p03_database_calls"]=0;'
			. '$GLOBALS["wpdb"]=new class { public function __call(string $name,array $arguments): mixed { ++$GLOBALS["p03_database_calls"]; return null; } };'
			. '$GLOBALS["wp_version"]="6.8.0";$GLOBALS["wp_theme_directories"]=array(' . var_export($this->root, true) . ');'
			. '$data=' . var_export($data, true) . ';$GLOBALS["p03_method"]=$data["method"]??"direct";';
		file_put_contents($probe, $prefix . $body);
		exec(
			escapeshellarg(PHP_BINARY) . ' -n -d sys_temp_dir=' . escapeshellarg($this->root) . ' ' . escapeshellarg($probe),
			$output,
			$status
		);
		self::assertSame(0, $status, implode("\n", $output));
		return json_decode(implode("\n", $output), true, 512, JSON_THROW_ON_ERROR);
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
		$iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($copy . '/src', \FilesystemIterator::SKIP_DOTS));
		foreach ($iterator as $file) if ($file->isFile() && 'php' === $file->getExtension()) $files[] = str_replace('\\', '/', substr($file->getPathname(), strlen($copy) + 1));
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
