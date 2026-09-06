<?php
declare(strict_types=1);

/* Executable public-consumer proof. run-prototype supplies a durable PHP temp directory. */
$type = $argv[1] ?? 'plugin';
$scenario = $argv[2] ?? 'happy';
if (! in_array($type, array('plugin', 'theme'), true) || ! in_array($scenario, array('happy', 'liveness', 'discard', 'fence-list', 'fence-inspect', 'fence-acquire'), true)) {
	throw new RuntimeException('Pass a supported package type and consumer scenario.');
}
$fixtureRepository = 'acme/consumer';
$fixtureRepositoryId = '99';
$fixtureReleaseId = 7;
if ('fence-list' === $scenario) {
	$fixtureRepository = 'acme/example-plugin';
	$fixtureRepositoryId = '123456789';
}
if ('fence-inspect' === $scenario) {
	$fixtureRepository = 'acme/example-theme';
	$fixtureRepositoryId = '987654321';
	$fixtureReleaseId = 42;
}
$root = sys_get_temp_dir() . '/release-source-consumer-' . $type . '-' . bin2hex(random_bytes(8));
if (! is_dir($root) && ! mkdir($root, 0700, true)) { throw new RuntimeException('Could not create fixture root.'); }
$GLOBALS['rs_root'] = $root;
$GLOBALS['rs_hooks'] = array();
$GLOBALS['rs_requests'] = array();
$GLOBALS['rs_responses'] = array();
$GLOBALS['rs_paths'] = array();
$GLOBALS['wp_version'] = '6.8.0';
$GLOBALS['wpdb'] = new stdClass();
register_shutdown_function(static function () use ($root): void { foreach (glob($root . '/*') ?: array() as $path) { @unlink($path); } @rmdir($root); });
define('WP_PLUGIN_DIR', $root . '/plugins');
final class WP_Error {}
function is_wp_error(mixed $value): bool { return $value instanceof WP_Error; }
function wp_http_validate_url(string $url): string { return $url; }
define('FS_METHOD', 'direct');
function add_action(string $hook, callable $callback, int $priority = 10, int $arguments = 1): void { $GLOBALS['rs_hooks'][] = compact('hook', 'callback', 'priority'); }
function add_filter(string $hook, callable $callback, int $priority = 10, int $arguments = 1): void { $GLOBALS['rs_hooks'][] = compact('hook', 'callback', 'priority'); }
function doing_action(string $hook): bool { return false; }
function did_action(string $hook): int { return 0; }
function wp_tempnam(string $name): string|false { $path = tempnam($GLOBALS['rs_root'], 'rs-'); if (is_string($path)) { chmod($path, 0600); $GLOBALS['rs_paths'][] = $path; } return $path; }
function wp_safe_remote_get(string $url, array $args): array|WP_Error { $GLOBALS['rs_requests'][] = array('url' => $url, 'stream' => (bool) ($args['stream'] ?? false)); $response = array_shift($GLOBALS['rs_responses']); if (! is_array($response)) { throw new RuntimeException('Unexpected mock request.'); } if (isset($args['filename'], $response['file'])) { file_put_contents($args['filename'], $response['file']); chmod($args['filename'], 0600); } return $response; }
function wp_remote_retrieve_response_code(array $response): int|string { return $response['response']['code']; }
function wp_remote_retrieve_header(array $response, string $name): mixed { return $response['headers'][strtolower($name)] ?? null; }
function wp_remote_retrieve_body(array $response): string { return $response['body'] ?? ''; }
function rs_response(mixed $body, ?string $file = null): array { return array('body' => json_encode($body, JSON_THROW_ON_ERROR), 'headers' => array(), 'response' => array('code' => 200), 'file' => $file); }
function rs_assert(bool $condition, string $message): void { if (! $condition) { throw new RuntimeException($message); } }
function rs_activate(): void { foreach ($GLOBALS['rs_hooks'] as $hook) { if ('after_setup_theme' === $hook['hook']) { ($hook['callback'])(); } } }
/** @return array{requests:int,zips:int} */
function rs_delta(int $start): array { $requests = array_slice($GLOBALS['rs_requests'], $start); return array('requests' => count($requests), 'zips' => count(array_filter($requests, static fn(array $request): bool => $request['stream']))); }
function rs_assert_delta(string $operation, array $delta, int $zips): void { rs_assert(0 < $delta['requests'], $operation . ' made no HTTP requests.'); rs_assert($zips === $delta['zips'], $operation . ' ZIP request count changed.'); }
function rs_copy_verified(string $source, string $destination, array $facts): void {
	$size = $facts['artifact_size'] ?? null; $limit = $facts['maximum_artifact_bytes'] ?? null;
	rs_assert(is_int($size) && $size > 0 && is_int($limit) && $size <= $limit, 'Artifact facts are not bounded.');
	$input = fopen($source, 'rb'); $output = fopen($destination, 'xb');
	if (false === $input || false === $output) { throw new RuntimeException('Could not open prepared-copy stream.'); }
	try {
		$remaining = $size;
		while (0 < $remaining) {
			$chunk = fread($input, min(8192, $remaining));
			if (false === $chunk || '' === $chunk) { throw new RuntimeException('Prepared-copy read was incomplete.'); }
			for ($offset = 0, $length = strlen($chunk); $offset < $length;) { $written = fwrite($output, substr($chunk, $offset)); if (false === $written || 0 === $written) { throw new RuntimeException('Prepared-copy write was incomplete.'); } $offset += $written; }
			$remaining -= $length;
		}
		if (false === feof($input) && '' !== fread($input, 1)) { throw new RuntimeException('Artifact exceeded its inspected size.'); }
	} finally { fclose($input); fclose($output); }
	rs_assert($size === filesize($destination), 'Prepared copy size changed.');
	rs_assert(hash_equals($facts['artifact_sha256'], (string) hash_file('sha256', $destination)), 'Prepared copy digest changed.');
}

$zipPath = $root . '/fixture.zip'; $zip = new ZipArchive();
rs_assert(true === $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE), 'ZIP creation failed.');
$entry = 'plugin' === $type ? 'consumer/consumer.php' : 'consumer/style.css';
$zip->addFromString($entry, 'plugin' === $type ? "<?php\n/*\nPlugin Name: Consumer\nVersion: 1.2.3\nUpdate URI: https://github.com/{$fixtureRepository}\nRequires at least: 6.5\nRequires PHP: 8.2\n*/" : "/*\nTheme Name: Consumer\nVersion: 1.2.3\nUpdate URI: https://github.com/{$fixtureRepository}\nRequires at least: 6.5\nRequires PHP: 8.2\n*/");
$zip->close(); chmod($zipPath, 0600); $bytes = file_get_contents($zipPath); rs_assert(is_string($bytes), 'ZIP read failed.');
$release = array('id' => $fixtureReleaseId, 'draft' => false, 'prerelease' => false, 'immutable' => true, 'tag_name' => 'v1.2.3', 'html_url' => 'https://github.com/' . $fixtureRepository . '/releases/tag/v1.2.3', 'assets' => array(array('id' => 8, 'name' => 'consumer.zip', 'state' => 'uploaded', 'size' => strlen($bytes), 'digest' => 'sha256:' . hash('sha256', $bytes))));
$queueProof = static function () use ($release, $bytes, $fixtureRepositoryId): void { $GLOBALS['rs_responses'] = array(rs_response(array('id' => (int) $fixtureRepositoryId)), rs_response($release), rs_response(array('sha' => str_repeat('a', 40))), rs_response(array('id' => (int) $fixtureRepositoryId)), rs_response(null, $bytes), rs_response(array('id' => (int) $fixtureRepositoryId))); };
$credentials = 0; $registrar = require dirname(__DIR__, 2) . '/bootstrap.php';
$source = $registrar->releases('github', $type, 'acme/consumer', '99', 'stable', static function () use (&$credentials): string { ++$credentials; return 'consumer-token'; });
rs_assert(array(array('hook' => 'after_setup_theme', 'callback' => $GLOBALS['rs_hooks'][0]['callback'], 'priority' => PHP_INT_MAX)) === $GLOBALS['rs_hooks'], 'Release source registered native hooks.');
$brokerFacts = $GLOBALS['ran_wp_release_updater_v1_broker']->diagnostics();
rs_assert(0 === $brokerFacts['submission_count'] && 0 === $brokerFacts['logical_target_count'], 'Release source bound a native target.');
$before = $source->list(); rs_assert('runtime_not_ready' === $before['code'], 'Source did not fail before readiness.'); rs_activate();
rs_assert(1 === count($GLOBALS['rs_hooks']) && 'after_setup_theme' === $GLOBALS['rs_hooks'][0]['hook'], 'Release source added hooks during activation.');
$fence = $argv[3] ?? null;
if (str_starts_with($scenario, 'fence-')) {
	rs_assert(is_string($fence) && '' !== $fence && false !== base64_decode($fence, true), 'Fence source is required.');
	$fence = base64_decode($fence, true);
	if ('fence-acquire' === $scenario) {
		$queueProof(); $inspection = $source->inspect('7', 'v1.2.3'); rs_assert($inspection['ok'], 'Fence prerequisite inspection failed.');
		$releaseId = '7'; $tag = 'v1.2.3'; $fingerprint = $inspection['value']['fingerprint']; $applicationOwnedPath = $root . '/fence-prepared.zip'; $queueProof();
	} elseif ('fence-list' === $scenario) { $GLOBALS['rs_responses'] = array(rs_response(array($release))); }
	else { $queueProof(); }
	$fenceRequestStart = count($GLOBALS['rs_requests']);
	eval($fence);
	foreach ($GLOBALS['rs_hooks'] as $hook) {
		if ('init' === $hook['hook']) {
			($hook['callback'])();
		}
	}
	$fenceDelta = rs_delta($fenceRequestStart);
	if ('fence-list' === $scenario) {
		rs_assert(array('requests' => 1, 'zips' => 0) === $fenceDelta, 'README listing fence did not complete its public operation.');
	} elseif ('fence-inspect' === $scenario) {
		rs_assert(array('requests' => 6, 'zips' => 1) === $fenceDelta, 'README inspection fence did not complete its public operation.');
	} else {
		rs_assert(isset($acquisition) && is_array($acquisition) && true === ($acquisition['ok'] ?? false), 'Acquisition fence returned a failure.');
		rs_assert(is_file($applicationOwnedPath), 'Acquisition fence did not create its prepared copy.');
		rs_assert(hash_equals($acquisition['value']['inspection']['artifact_sha256'], (string) hash_file('sha256', $applicationOwnedPath)), 'Acquisition fence prepared-copy digest changed.');
		rs_assert(array('requests' => 6, 'zips' => 1) === $fenceDelta, 'Acquisition fence did not complete its public operation.');
		rs_assert(! file_exists($GLOBALS['rs_paths'][count($GLOBALS['rs_paths']) - 1]), 'Acquisition fence retained its owned artifact.');
	}
	rs_assert(array() === $GLOBALS['rs_responses'], 'Fence mock queue was not drained.');
	@unlink($root . '/fence-prepared.zip');
	rs_assert(! file_exists($root . '/fence-prepared.zip'), 'Fixture owner could not remove its scenario file.');
	echo json_encode(array('type' => $type, 'scenario' => $scenario, 'before' => $before['code']), JSON_THROW_ON_ERROR) . PHP_EOL;
	exit(0);
}
$listStart = count($GLOBALS['rs_requests']); $GLOBALS['rs_responses'] = array(rs_response(array($release))); $list = $source->list(); rs_assert(true === $list['ok'], 'Public list failed.'); $listDelta = rs_delta($listStart); rs_assert_delta('list', $listDelta, 0);
$queueProof(); $inspectStart = count($GLOBALS['rs_requests']); $inspection = $source->inspect('7', 'v1.2.3'); rs_assert(true === $inspection['ok'] && 'complete' === $inspection['cleanup_status'], 'Public inspect failed.'); $inspectDelta = rs_delta($inspectStart); rs_assert_delta('inspect', $inspectDelta, 1);
$queueProof(); $acquireStart = count($GLOBALS['rs_requests']); $acquisition = $source->acquire('7', 'v1.2.3', $inspection['value']['fingerprint']); rs_assert(true === $acquisition['ok'] && 'retained' === $acquisition['cleanup_status'], 'Public acquire failed.'); $acquireDelta = rs_delta($acquireStart); rs_assert_delta('acquire', $acquireDelta, 1);
rs_assert(array() === $GLOBALS['rs_responses'], 'Mock queue was not drained.'); rs_assert(2 === count($GLOBALS['rs_paths']), 'Inspection and acquisition must allocate exactly one artifact each.'); rs_assert(! file_exists($GLOBALS['rs_paths'][0]), 'Inspection artifact was not removed.'); rs_assert(is_file($GLOBALS['rs_paths'][1]), 'Acquisition artifact was not retained.');
$artifact = $acquisition['value']['artifact']; $caller = new DomainException('consumer callback');
try { $artifact->inspect(static function () use ($caller): never { throw $caller; }); throw new RuntimeException('Callback escaped.'); } catch (DomainException $actual) { rs_assert($caller === $actual, 'Callback identity changed.'); }
$provisional = $root . '/prepared.zip';
try {
	if ('happy' === $scenario) { $artifact->inspect(static function (string $path) use ($provisional, $acquisition): void { rs_copy_verified($path, $provisional, $acquisition['value']['inspection']); }); rs_assert($artifact->discard(), 'Artifact cleanup failed.'); rs_assert(is_file($provisional), 'Prepared copy missing.'); }
	elseif ('liveness' === $scenario) { try { $artifact->inspect(static function (string $path) use ($provisional, $acquisition): void { rs_copy_verified($path, $provisional, $acquisition['value']['inspection']); $GLOBALS['ran_wp_release_updater_v1_broker'] = new stdClass(); }); throw new RuntimeException('Expected liveness loss.'); } catch (RuntimeException $failure) { rs_assert(1002 === $failure->getCode(), 'Liveness loss did not use code 1002.'); @unlink($provisional); } rs_assert(! file_exists($provisional), 'Liveness failure retained provisional copy.'); rs_assert($artifact->discard(), 'Cleanup after liveness loss failed.'); }
	else { try { $artifact->inspect(static function (string $path) use ($provisional, $acquisition): void { rs_copy_verified($path, $provisional, $acquisition['value']['inspection']); file_put_contents($path, 'changed'); }); throw new RuntimeException('Expected changed artifact.'); } catch (RuntimeException $failure) { rs_assert(1001 === $failure->getCode(), 'Changed artifact did not use code 1001.'); @unlink($provisional); } rs_assert(! file_exists($provisional), 'Changed-artifact failure retained provisional copy.'); rs_assert(! $artifact->discard(), 'Changed artifact cleanup must fail safely.'); }
} catch (Throwable $failure) { @unlink($provisional); throw $failure; }
if ('happy' !== $scenario) { @unlink($provisional); }
rs_assert('happy' === $scenario ? is_file($provisional) : ! file_exists($provisional), 'Consumer provisional-copy retention changed.'); rs_assert(3 === $credentials, 'Resolver was not called once per operation.');
echo json_encode(array('type' => $type, 'scenario' => $scenario, 'before' => $before['code'], 'credential_operations' => $credentials, 'http' => array('list' => $listDelta, 'inspect' => $inspectDelta, 'acquire' => $acquireDelta)), JSON_THROW_ON_ERROR) . PHP_EOL;
