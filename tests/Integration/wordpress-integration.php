<?php

declare(strict_types=1);

// Run against pristine WordPress source and a new, socket-only MySQL instance.
const INTEGRATION_MARKER = 'ran-wp-release-updater-integration-v1';
$processes = array();
$ownedDirectories = array();
$redactions = array();
$environment = array();
$report = null;
$result = array('status' => 'failed', 'php_version' => PHP_VERSION, 'scenarios' => array());

try {
	$scenario = 'all';
	foreach (array_slice($argv, 1) as $argument) {
		if (!preg_match('/\A--scenario=(all|distribution|multisite)\z/', $argument, $match)) {
			throw new RuntimeException('Use --scenario=all|distribution|multisite.');
		}
		$scenario = $match[1];
	}
	if (PHP_VERSION_ID < 80200 || !extension_loaded('mysqli') || !extension_loaded('zip')) {
		throw new RuntimeException('PHP 8.2 with mysqli and zip is required.');
	}
	$source = dirname(__DIR__, 2);
	$runtime = verifyRuntime($source);
	$result['candidate_revision'] = $runtime['package_revision'];
	$result['candidate_manifest_hash'] = hash_file('sha256', $source . '/runtime-copy.json');
	$suiteBytes = '';
	foreach (array(__FILE__, __DIR__ . '/wordpress-integration/distribution.php', __DIR__ . '/wordpress-integration/multisite.php') as $file) {
		$suiteBytes .= basename($file) . "\0" . hash_file('sha256', $file) . "\n";
	}
	$result['suite_revision'] = hash('sha256', $suiteBytes);
	$wordpress = inputDirectory('RAN_UPDATER_WP_ROOT', false);
	if (!is_file($wordpress . '/wp-load.php')) {
		throw new RuntimeException('RAN_UPDATER_WP_ROOT must contain pristine WordPress source.');
	}
	$workspace = inputDirectory('RAN_UPDATER_INTEGRATION_ROOT');
	$socketParent = getenv('RAN_UPDATER_SOCKET_ROOT') ? inputDirectory('RAN_UPDATER_SOCKET_ROOT') : $workspace;
	$wpCli = inputExecutable('RAN_UPDATER_WP_CLI');
	$mysqld = inputExecutable('RAN_UPDATER_MYSQLD_BIN');
	$report = $workspace . '/ran-wp-release-updater-result-' . bin2hex(random_bytes(6)) . '.json';
	$run = ownDirectory($workspace, 'ran-wp-release-updater-run-');
	$socketDirectory = ownDirectory($socketParent, 's-');
	$socket = $socketDirectory . '/mysql.sock';
	$socketLimit = 'Darwin' === PHP_OS_FAMILY ? 103 : 107;
	if (strlen($socket) > $socketLimit || !str_starts_with($socket, '/')) {
		throw new RuntimeException('Set RAN_UPDATER_SOCKET_ROOT to a shorter durable absolute directory.');
	}
	$scratch = makeDirectory($run . '/scratch');
	$config = $run . '/wp-cli.yml';
	writeFile($config, "{}\n");
	$environment = array_merge(getenv(), array(
		'TMPDIR' => $scratch, 'TMP' => $scratch, 'TEMP' => $scratch,
		'WP_CLI_CONFIG_PATH' => $config, 'WP_CLI_CACHE_DIR' => makeDirectory($run . '/wp-cache'),
	));
	$mysql = makeDirectory($run . '/mysql');
	$data = makeDirectory($mysql . '/data');
	$pidFile = $mysql . '/mysqld.pid';
	command(array($mysqld, '--no-defaults', '--initialize-insecure', '--datadir=' . $data,
		'--socket=' . $socket, '--tmpdir=' . $scratch, '--skip-mysqlx'), $mysql, 'mysql-initialize', timeout: 120);
	$server = startProcess(array($mysqld, '--no-defaults', '--datadir=' . $data,
		'--socket=' . $socket, '--skip-networking', '--pid-file=' . $pidFile,
		'--tmpdir=' . $scratch, '--skip-mysqlx', '--log-error=' . $mysql . '/error.log'), $mysql, 'mysql-server');
	$database = attestDatabase($server, $socket, $data, $pidFile);
	$wpCommand = array(PHP_BINARY, '-d', 'sys_temp_dir=' . $scratch, '-d', 'zend.exception_ignore_args=1',
		$wpCli, '--allow-root', '--skip-packages');
	$password = bin2hex(random_bytes(24));
	$redactions[] = $password;
	$result['wordpress_version'] = wordpressVersion($wordpress);
	$result['runtime_protocol'] = $runtime['runtime_protocol'];

	if ('all' === $scenario || 'distribution' === $scenario) {
		$started = hrtime(true);
		$site = createSite($wordpress, $run . '/single', $database, $socket);
		installWordpress($wpCommand, $site, $password, false);
		$archives = consumerArchives($source, $run);
		$arguments = array_merge($wpCommand, array('--path=' . $site));
		$inputs = array('RAN_UPDATER_PLUGIN_ZIP' => $archives['plugin'],
			'RAN_UPDATER_THEME_ZIP' => $archives['theme'], 'RAN_UPDATER_OUTPUT' => $run . '/distribution.json');
		$observer = __DIR__ . '/wordpress-integration/distribution.php';
		command(array_merge($arguments, array('eval-file', $observer)), $site, 'distribution-install',
			array_merge($inputs, array('RAN_UPDATER_DISTRIBUTION_MODE' => 'install')));
		command(array_merge($arguments, array('plugin', 'activate', 'ran-neutral-plugin')), $site, 'activate-plugin');
		command(array_merge($arguments, array('theme', 'activate', 'ran-neutral-theme')), $site, 'activate-theme');
		command(array_merge($arguments, array('eval-file', $observer)), $site, 'distribution-observe',
			array_merge($inputs, array('RAN_UPDATER_DISTRIBUTION_MODE' => 'observe')));
		$proof = readJson($run . '/distribution.json');
		$result['scenarios']['distribution'] = $proof;
		requireFact(true === ($proof['pass'] ?? null), 'Installed distribution assertions failed.');
		$result['scenarios']['distribution']['duration_ms'] = (int) ((hrtime(true) - $started) / 1000000);
	}
	if ('all' === $scenario || 'multisite' === $scenario) {
		$started = hrtime(true);
		$site = createSite($wordpress, $run . '/network', $database, $socket);
		installWordpress($wpCommand, $site, $password, true);
		$arguments = array_merge($wpCommand, array('--path=' . $site));
		$subsite = command(array_merge($arguments, array('site', 'create', '--slug=subsite',
			'--title=Subsite', '--porcelain')), $site, 'create-subsite');
		requireFact(ctype_digit($subsite), 'WordPress did not return a subsite ID.');
		networkConsumer($source, $site);
		command(array_merge($arguments, array('plugin', 'activate', 'ran-network-target', '--network')), $site, 'activate-network-plugin');
		$observer = __DIR__ . '/wordpress-integration/multisite.php';
		$mainOutput = $run . '/main.json';
		$childOutput = $run . '/subsite.json';
		$release = $run . '/release-main';
		$winner = startProcess(array_merge($arguments, array('--url=http://example.test', 'eval-file', $observer)),
			$site, 'main-site-discovery', array('RAN_UPDATER_NETWORK_OUTPUT' => $mainOutput, 'RAN_UPDATER_NETWORK_RELEASE' => $release));
		waitForOutput($winner, $mainOutput);
		command(array_merge($arguments, array('--url=http://example.test/subsite/', 'eval-file', $observer)),
			$site, 'subsite-discovery', array('RAN_UPDATER_NETWORK_OUTPUT' => $childOutput));
		$main = readJson($mainOutput);
		$child = readJson($childOutput);
		$result['scenarios']['multisite'] = array('main' => $main, 'subsite' => $child);
		assertNetwork($main, $child);
		writeFile($release, "release\n");
		finishProcess($winner);
		// Positive control: the subsite must discover once the competing owner exits.
		$afterOutput = $run . '/subsite-after-release.json';
		command(array_merge($arguments, array('--url=http://example.test/subsite/', 'eval-file', $observer)),
			$site, 'subsite-after-release', array('RAN_UPDATER_NETWORK_OUTPUT' => $afterOutput));
		$after = readJson($afterOutput);
		$result['scenarios']['multisite']['after_release'] = $after;
		requireFact(1 === ($after['provider_callback_delta'] ?? null) && false === ($after['suppressed_provider'] ?? null)
			&& $after['blog_id'] === $child['blog_id'] && $after['option']['name'] === $main['option']['name']
			&& true === $after['option']['owner_token_present']
			&& $after['option']['owner_token_sha256'] !== $main['option']['owner_token_sha256']
			&& $after['option']['fence_epoch'] > $main['option']['fence_epoch'], 'Subsite did not acquire the released fence.');
		$result['scenarios']['multisite']['pass'] = true;
		$result['scenarios']['multisite']['duration_ms'] = (int) ((hrtime(true) - $started) / 1000000);
	}
	$database->close();
	$result['status'] = 'passed';
} catch (Throwable $error) {
	$result['error'] = redact($error->getMessage());
} finally {
	$cleanupErrors = array();
	foreach (array_reverse(array_keys($processes)) as $id) {
		try { stopProcess($id); } catch (Throwable $error) { $cleanupErrors[] = redact($error->getMessage()); }
	}
	// Never remove a server's files unless all owned processes have stopped.
	if (array() === $processes) {
		foreach (array_reverse($ownedDirectories) as $directory) {
			try { removeOwnedDirectory($directory); } catch (Throwable $error) { $cleanupErrors[] = redact($error->getMessage()); }
		}
	}
	$result['cleanup'] = array() === $cleanupErrors && array() === $processes ? 'complete' : 'failed';
	if ('complete' !== $result['cleanup']) {
		$result['status'] = 'failed';
		$result['cleanup_errors'] = $cleanupErrors;
	}
	$json = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
	if (is_string($report)) {
		writeFile($report, $json);
		chmod($report, 0600);
	}
	echo $json;
}
exit('passed' === $result['status'] ? 0 : 1);

function requireFact(bool $condition, string $message): void
{
	if (!$condition) throw new RuntimeException($message);
}

function inputDirectory(string $name, bool $writable = true): string
{
	$value = getenv($name);
	$path = is_string($value) ? realpath($value) : false;
	requireFact(is_string($path) && !is_link((string) $value) && is_dir($path)
		&& (!$writable || is_writable($path)), $name . ' must name an existing non-symlink directory.');
	return $path;
}

function inputExecutable(string $name): string
{
	$value = getenv($name);
	$path = is_string($value) ? realpath($value) : false;
	requireFact(is_string($path) && is_file($path) && is_executable($path), $name . ' must name an executable file.');
	return $path;
}

function makeDirectory(string $path): string
{
	requireFact(mkdir($path, 0700, true), 'Could not create a fixture directory.');
	return $path;
}

function writeFile(string $path, string $bytes): void
{
	requireFact(strlen($bytes) === file_put_contents($path, $bytes, LOCK_EX), 'Could not write a fixture file.');
}

function ownDirectory(string $parent, string $prefix): string
{
	global $ownedDirectories;
	$path = makeDirectory($parent . '/' . $prefix . bin2hex(random_bytes(6)));
	try { writeFile($path . '/.integration-owner', INTEGRATION_MARKER); }
	catch (Throwable $error) { rmdir($path); throw $error; }
	$ownedDirectories[] = $path;
	return $path;
}

function removeOwnedDirectory(string $path): void
{
	requireFact(!is_link($path) && is_dir($path)
		&& INTEGRATION_MARKER === file_get_contents($path . '/.integration-owner'), 'Refusing cleanup without the run ownership marker.');
	$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
		RecursiveIteratorIterator::CHILD_FIRST);
	foreach ($iterator as $file) {
		$removed = $file->isLink() || !$file->isDir() ? unlink($file->getPathname()) : rmdir($file->getPathname());
		requireFact($removed, 'Owned fixture cleanup failed.');
	}
	requireFact(rmdir($path), 'Owned run directory cleanup failed.');
}

function redact(string $value): string
{
	global $redactions;
	return str_replace($redactions, '[redacted]', $value);
}

function startProcess(array $command, string $cwd, string $label, array $extra = array(), string $stdin = ''): int
{
	global $processes, $environment;
	$process = proc_open($command, array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
		$pipes, $cwd, array_merge($environment, $extra));
	requireFact(is_resource($process), $label . ' could not start.');
	$id = (int) $process;
	$processes[$id] = array('process' => $process, 'pipes' => $pipes, 'label' => $label, 'out' => '', 'err' => '', 'exit' => null);
	if ('' !== $stdin) fwrite($pipes[0], $stdin);
	fclose($pipes[0]);
	stream_set_blocking($pipes[1], false);
	stream_set_blocking($pipes[2], false);
	return $id;
}

function pollProcess(int $id): array
{
	global $processes;
	$entry = &$processes[$id];
	foreach (array(1 => 'out', 2 => 'err') as $number => $key) {
		$chunk = stream_get_contents($entry['pipes'][$number], 65536);
		if (is_string($chunk)) $entry[$key] = substr($entry[$key] . $chunk, -65536);
	}
	$status = proc_get_status($entry['process']);
	if (!$status['running'] && null === $entry['exit']) $entry['exit'] = $status['exitcode'];
	return $status;
}

function finishProcess(int $id, int $timeout = 60): string
{
	global $processes;
	$deadline = microtime(true) + $timeout;
	while (pollProcess($id)['running']) {
		requireFact(microtime(true) < $deadline, $processes[$id]['label'] . ' timed out.');
		usleep(25000);
	}
	pollProcess($id);
	$entry = $processes[$id];
	foreach (array(1, 2) as $number) fclose($entry['pipes'][$number]);
	$closed = proc_close($entry['process']);
	unset($processes[$id]);
	$exit = $entry['exit'] >= 0 ? $entry['exit'] : $closed;
	requireFact(0 === $exit, $entry['label'] . ' failed (exit ' . $exit . '): ' . redact(substr($entry['err'], -2000)));
	return trim($entry['out']);
}

function command(array $command, string $cwd, string $label, array $extra = array(), string $stdin = '', int $timeout = 60): string
{
	return finishProcess(startProcess($command, $cwd, $label, $extra, $stdin), $timeout);
}

function stopProcess(int $id): void
{
	global $processes;
	if (!isset($processes[$id])) return;
	$process = $processes[$id]['process'];
	if (pollProcess($id)['running']) proc_terminate($process);
	$deadline = microtime(true) + 5;
	while (pollProcess($id)['running'] && microtime(true) < $deadline) usleep(25000);
	if (pollProcess($id)['running']) proc_terminate($process, 9);
	$deadline = microtime(true) + 5;
	while (pollProcess($id)['running'] && microtime(true) < $deadline) usleep(25000);
	requireFact(!pollProcess($id)['running'], 'Owned process did not stop: ' . $processes[$id]['label']);
	foreach (array(1, 2) as $number) fclose($processes[$id]['pipes'][$number]);
	proc_close($process);
	unset($processes[$id]);
}

function attestDatabase(int $server, string $socket, string $data, string $pidFile): mysqli
{
	mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
	$deadline = microtime(true) + 30;
	do {
		$status = pollProcess($server);
		requireFact($status['running'], 'Owned MySQL exited before socket attestation.');
		if (is_file($pidFile) && trim((string) file_get_contents($pidFile)) === (string) $status['pid']) {
			try {
				$db = mysqli_init();
				$db->real_connect('localhost', 'root', '', null, 0, $socket);
			} catch (mysqli_sql_exception) {
				if (isset($db)) $db->close();
				usleep(50000);
				continue;
			}
			$row = $db->query('SELECT @@datadir AS d, @@pid_file AS p, @@skip_networking AS n')->fetch_assoc();
			requireFact(realpath((string) $row['d']) === realpath($data)
				&& realpath((string) $row['p']) === realpath($pidFile) && 1 === (int) $row['n'], 'Owned MySQL attestation failed.');
			return $db;
		}
		usleep(50000);
	} while (microtime(true) < $deadline);
	throw new RuntimeException('Timed out attesting the owned MySQL socket.');
}

function runtimeFiles(string $source): array
{
	$files = array('bootstrap.php', 'runtime.php');
	$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source . '/src', FilesystemIterator::SKIP_DOTS));
	foreach ($iterator as $file) {
		requireFact(!$file->isLink(), 'Runtime source must not contain symlinks.');
		if ($file->isFile() && 'php' === $file->getExtension()) $files[] = substr($file->getPathname(), strlen($source) + 1);
	}
	sort($files, SORT_STRING);
	return $files;
}

function verifyRuntime(string $source): array
{
	$runtime = readJson($source . '/runtime-copy.json');
	$payload = '';
	foreach (runtimeFiles($source) as $file) $payload .= $file . "\0" . hash_file('sha256', $source . '/' . $file) . "\n";
	requireFact(4 === ($runtime['runtime_protocol'] ?? null)
		&& hash('sha256', $payload) === ($runtime['package_revision'] ?? null), 'Runtime manifest does not match the Protocol 4 source bytes.');
	return $runtime;
}

function copyRuntime(string $source, string $destination): void
{
	global $runtime;
	makeDirectory($destination);
	foreach (array_merge(runtimeFiles($source), array('LICENSE', 'NOTICE.md', 'composer.json', 'runtime-copy.json')) as $file) {
		$target = $destination . '/' . $file;
		if (!is_dir(dirname($target))) makeDirectory(dirname($target));
		requireFact(!is_link($source . '/' . $file) && copy($source . '/' . $file, $target), 'Runtime distribution copy failed.');
	}
	requireFact(verifyRuntime($destination) === $runtime, 'Copied runtime changed after candidate verification.');
}

function wordpressVersion(string $source): string
{
	$bytes = (string) file_get_contents($source . '/wp-includes/version.php');
	requireFact(1 === preg_match('/\$wp_version\s*=\s*[\'"]([^\'"]+)[\'"]/', $bytes, $matches), 'Could not read the WordPress fixture version.');
	return $matches[1];
}

function createSite(string $wordpress, string $site, mysqli $db, string $socket): string
{
	makeDirectory($site);
	$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($wordpress, FilesystemIterator::SKIP_DOTS),
		RecursiveIteratorIterator::SELF_FIRST);
	foreach ($iterator as $file) {
		$relative = substr($file->getPathname(), strlen($wordpress) + 1);
		$first = explode('/', $relative)[0];
		if (in_array($first, array('wp-content', 'wp-config.php', '.git', '.well-known'), true)) continue;
		requireFact(!$file->isLink(), 'Pristine WordPress fixture contains a symlink.');
		$target = $site . '/' . $relative;
		if ($file->isDir()) makeDirectory($target);
		else requireFact(copy($file->getPathname(), $target), 'WordPress fixture copy failed.');
	}
	foreach (array('plugins', 'themes', 'uploads', 'mu-plugins') as $directory) makeDirectory($site . '/wp-content/' . $directory);
	$name = 'ran_integration_' . bin2hex(random_bytes(6));
	$db->query('CREATE DATABASE ' . $name);
	$config = "<?php\n";
	foreach (array('DB_NAME' => $name, 'DB_USER' => 'root', 'DB_PASSWORD' => '', 'DB_HOST' => 'localhost:' . $socket,
		'DB_CHARSET' => 'utf8mb4', 'DB_COLLATE' => '', 'FS_METHOD' => 'direct', 'DISABLE_WP_CRON' => true, 'WP_ALLOW_MULTISITE' => true) as $key => $value) {
		$config .= 'define(' . var_export($key, true) . ', ' . var_export($value, true) . ");\n";
	}
	$config .= "\$table_prefix = 'wp_';\nif (!defined('ABSPATH')) define('ABSPATH', __DIR__ . '/');\nrequire_once ABSPATH . 'wp-settings.php';\n";
	writeFile($site . '/wp-config.php', $config);
	writeFile($site . '/wp-content/mu-plugins/integration-http.php', <<<'PHP'
<?php
$GLOBALS['ran_updater_http_calls'] = 0;
add_filter('pre_http_request', static function ($pre, $args, $url) {
	if ('api.github.com' === parse_url($url, PHP_URL_HOST)) {
		++$GLOBALS['ran_updater_http_calls'];
	}
	return new WP_Error('integration_http_blocked', 'Integration fixture: outbound HTTP disabled.');
}, PHP_INT_MAX, 3);
PHP);
	return $site;
}

function installWordpress(array $base, string $site, string $password, bool $network): void
{
	$arguments = array('--path=' . $site, 'core', $network ? 'multisite-install' : 'install',
		'--url=http://example.test', '--title=Integration', '--admin_user=admin',
		'--prompt=admin_password', '--admin_email=admin@example.test', '--skip-email');
	if ($network) $arguments[] = '--subdomains=0';
	command(array_merge($base, $arguments), $site, $network ? 'install-multisite' : 'install-wordpress', stdin: $password . "\n");
	if ($network) {
		// WP-CLI installs the network tables; subsequent requests also need its constants.
		$config = (string) file_get_contents($site . '/wp-config.php');
		$definitions = '';
		foreach (array('MULTISITE' => true, 'SUBDOMAIN_INSTALL' => false,
			'DOMAIN_CURRENT_SITE' => 'example.test', 'PATH_CURRENT_SITE' => '/',
			'SITE_ID_CURRENT_SITE' => 1, 'BLOG_ID_CURRENT_SITE' => 1) as $key => $value) {
			$definitions .= 'define(' . var_export($key, true) . ', ' . var_export($value, true) . ");\n";
		}
		writeFile($site . '/wp-config.php', str_replace("require_once ABSPATH . 'wp-settings.php';", $definitions . "require_once ABSPATH . 'wp-settings.php';", $config));
	}
}

function consumerArchives(string $source, string $run): array
{
	$archives = array();
	foreach (array('plugin', 'theme') as $type) {
		$slug = 'ran-neutral-' . $type;
		$root = makeDirectory($run . '/' . $slug);
		copyRuntime($source, $root . '/vendor/ran/wp-release-updater');
		$header = "/*\n" . ('plugin' === $type ? 'Plugin' : 'Theme') . " Name: Integration consumer\nVersion: 1.0.0\n"
			. 'Update URI: https://github.com/fixture/neutral-' . $type . "\nRequires PHP: 8.2\nRequires at least: 6.5\n*/\n";
		$entry = 'plugin' === $type ? '__FILE__' : "__DIR__ . '/style.css'";
		$php = "<?php\n" . ('plugin' === $type ? $header : '');
		$php .= "\$GLOBALS['ran_updater_credential_calls']['" . $type . "'] = 0;\n"
			. "\$registrar = require __DIR__ . '/vendor/ran/wp-release-updater/bootstrap.php';\n"
			. "\$credentials = static function (): ?string { ++\$GLOBALS['ran_updater_credential_calls']['" . $type . "']; return null; };\n"
			. "\$GLOBALS['ran_updater_" . $type . "_handle'] = \$registrar->" . $type . "('github', " . $entry
			. ", 'fixture/neutral-" . $type . "', '" . ('plugin' === $type ? '123456789' : '123456790') . "', 'stable', 'manual', \$credentials);\n"
			. "\$GLOBALS['ran_updater_" . $type . "_handle']->register();\n";
		if ('plugin' === $type) writeFile($root . '/' . $slug . '.php', $php);
		else {
			writeFile($root . '/style.css', $header);
			writeFile($root . '/functions.php', $php);
			writeFile($root . '/index.php', "<?php\n");
		}
		$archive = $run . '/' . $slug . '.zip';
		$zip = new ZipArchive();
		requireFact(true === $zip->open($archive, ZipArchive::CREATE | ZipArchive::EXCL), 'Could not create consumer ZIP.');
		$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
		foreach ($iterator as $file) {
			requireFact($zip->addFile($file->getPathname(), $slug . '/' . substr($file->getPathname(), strlen($root) + 1)), 'Could not add consumer ZIP entry.');
		}
		requireFact($zip->close(), 'Could not finish consumer ZIP.');
		$archives[$type] = $archive;
	}
	return $archives;
}

function networkConsumer(string $source, string $site): void
{
	$root = makeDirectory($site . '/wp-content/plugins/ran-network-target');
	copyRuntime($source, $root . '/vendor/ran/wp-release-updater');
	writeFile($root . '/main.php', <<<'PHP'
<?php
/*
Plugin Name: Integration network consumer
Version: 1.0.0
Update URI: https://github.com/fixture/network-target
*/
$registrar = require __DIR__ . '/vendor/ran/wp-release-updater/bootstrap.php';
$GLOBALS['ran_network_handle'] = $registrar->plugin('github', __FILE__, 'fixture/network-target', '123456791');
$GLOBALS['ran_network_duplicate_handle'] = $registrar->plugin('github', __FILE__, 'fixture/network-target', '123456791');
$GLOBALS['ran_network_handle']->register();
$GLOBALS['ran_network_duplicate_handle']->register();
PHP);
}

function waitForOutput(int $process, string $path): void
{
	$deadline = microtime(true) + 15;
	while (!is_file($path)) {
		requireFact(pollProcess($process)['running'], 'Main-site discovery exited before producing evidence.');
		requireFact(microtime(true) < $deadline, 'Main-site discovery did not reach the synchronization barrier.');
		usleep(25000);
	}
	requireFact(pollProcess($process)['running'], 'Main-site discovery must remain alive while the subsite runs.');
}

function assertNetwork(array $main, array $child): void
{
	requireFact(is_int($main['blog_id'] ?? null) && is_int($child['blog_id'] ?? null)
		&& $main['blog_id'] !== $child['blog_id'] && $main['network_id'] === $child['network_id'], 'Expected two sites in the same network.');
	foreach (array($main, $child) as $proof) {
		requireFact(true === ($proof['duplicate_registration_accepted'] ?? null)
			&& 1 === ($proof['logical_target_count'] ?? null) && 1 === ($proof['native_callback_count'] ?? null)
			&& 'target_active' === ($proof['status']['code'] ?? null)
			&& true === ($proof['option']['present'] ?? null) && true === ($proof['option']['owner_token_present'] ?? null)
			&& $proof['network_id'] === $proof['option']['network_id']
			&& ($proof['option']['lease_deadline'] ?? 0) > time(), 'Network target or live persisted fence is missing.');
	}
	requireFact(1 === $main['provider_callback_delta'] && false === $main['suppressed_provider']
		&& 0 === $child['provider_callback_delta'] && true === $child['suppressed_provider'], 'Competing site was not fenced before provider access.');
	requireFact($main['option'] === $child['option']
		&& 1 === preg_match('/\A[0-9a-f]{64}\z/', $main['option']['owner_token_sha256'] ?? ''), 'Sites did not observe the same unchanged fence owner.');
}

function readJson(string $path): array
{
	$record = json_decode((string) file_get_contents($path), true, 128, JSON_THROW_ON_ERROR);
	requireFact(is_array($record), 'Expected a JSON evidence object.');
	return $record;
}
