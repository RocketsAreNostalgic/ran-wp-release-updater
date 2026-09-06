<?php

// wp eval-file wraps this fixture before execution, so strict_types cannot lead.

require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
require_once ABSPATH . 'wp-admin/includes/class-plugin-upgrader.php';
require_once ABSPATH . 'wp-admin/includes/class-theme-upgrader.php';

final class RAN_Updater_Integration_Skin extends WP_Upgrader_Skin
{
	public function feedback($string, ...$args): void {}
	public function header(): void {}
	public function footer(): void {}
}

$pluginZip = requiredInput('RAN_UPDATER_PLUGIN_ZIP');
$themeZip = requiredInput('RAN_UPDATER_THEME_ZIP');
$output = requiredInput('RAN_UPDATER_OUTPUT');
$mode = requiredInput('RAN_UPDATER_DISTRIBUTION_MODE');

if ('install' === $mode) {
	$skin = new RAN_Updater_Integration_Skin();
	if (!(new Plugin_Upgrader($skin))->install($pluginZip)) throw new RuntimeException('Plugin_Upgrader could not install the exact consumer ZIP.');
	if (!(new Theme_Upgrader($skin))->install($themeZip)) throw new RuntimeException('Theme_Upgrader could not install the exact consumer ZIP.');
	return;
}
if ('observe' !== $mode) throw new RuntimeException('Distribution mode is invalid.');

$plugin = $GLOBALS['ran_updater_plugin_handle'] ?? null;
$theme = $GLOBALS['ran_updater_theme_handle'] ?? null;
$broker = $GLOBALS['ran_wp_release_updater_v1_broker'] ?? null;
if (!is_object($plugin) || !is_object($theme) || !is_object($broker)) throw new RuntimeException('Installed consumer boot did not expose public handles.');

$credentialsAtBoot = $GLOBALS['ran_updater_credential_calls'] ?? null;
$httpAtBoot = (int) ($GLOBALS['ran_updater_http_calls'] ?? 0);
$pluginCallbacks = nativeCallbacks('update_plugins_github.com');
$themeCallbacks = nativeCallbacks('update_themes_github.com');
$runtimeRoot = WP_PLUGIN_DIR . '/ran-neutral-plugin/vendor/ran/wp-release-updater';

$pluginData = get_plugin_data(WP_PLUGIN_DIR . '/ran-neutral-plugin/ran-neutral-plugin.php', false, false);
$themeObject = wp_get_theme('ran-neutral-theme');
apply_filters('update_plugins_github.com', false, $pluginData, 'ran-neutral-plugin/ran-neutral-plugin.php', array());
apply_filters('update_themes_github.com', false, array(
	'Name' => $themeObject->get('Name'), 'Version' => $themeObject->get('Version'),
	'UpdateURI' => $themeObject->get('UpdateURI'), 'RequiresWP' => $themeObject->get('RequiresWP'), 'RequiresPHP' => $themeObject->get('RequiresPHP'),
), 'ran-neutral-theme', array());

$credentialsAfter = $GLOBALS['ran_updater_credential_calls'] ?? null;
$httpAfter = (int) ($GLOBALS['ran_updater_http_calls'] ?? 0);
$diagnostics = $broker->diagnostics();
$proof = array(
	'boot' => array('candidate_count' => $diagnostics['candidate_count'] ?? null, 'logical_target_count' => $diagnostics['logical_target_count'] ?? null, 'credential_callbacks' => $credentialsAtBoot, 'http_callbacks' => $httpAtBoot),
	'callbacks' => array('plugin_native_callbacks' => count($pluginCallbacks), 'theme_native_callbacks' => count($themeCallbacks), 'origins' => callbackOrigins(array_merge($pluginCallbacks, $themeCallbacks)), 'credential_callbacks' => $credentialsAfter, 'http_callback_delta' => $httpAfter - $httpAtBoot),
	'plugin_manifest_hash' => hash('sha256', json_encode(archiveManifest($pluginZip, 'ran-neutral-plugin'), JSON_THROW_ON_ERROR)),
	'theme_manifest_hash' => hash('sha256', json_encode(archiveManifest($themeZip, 'ran-neutral-theme'), JSON_THROW_ON_ERROR)),
	'plugin_status' => $plugin->status(), 'theme_status' => $theme->status(),
	'pass' => archiveManifest($pluginZip, 'ran-neutral-plugin') === directoryManifest(WP_PLUGIN_DIR . '/ran-neutral-plugin')
		&& archiveManifest($themeZip, 'ran-neutral-theme') === directoryManifest(get_theme_root() . '/ran-neutral-theme')
		&& is_plugin_active('ran-neutral-plugin/ran-neutral-plugin.php') && 'ran-neutral-theme' === get_stylesheet()
		&& 2 === ($diagnostics['candidate_count'] ?? null) && 2 === ($diagnostics['logical_target_count'] ?? null)
		&& 3 === ($diagnostics['protocol_version'] ?? null)
		&& array('plugin' => 0, 'theme' => 0) === $credentialsAtBoot && 0 === $httpAtBoot
		&& 1 === count($pluginCallbacks) && 1 === count($themeCallbacks)
		&& originsAreInstalled(array_merge($pluginCallbacks, $themeCallbacks), $runtimeRoot)
		&& array('plugin' => 1, 'theme' => 1) === $credentialsAfter && 2 === ($httpAfter - $httpAtBoot)
		&& 'target_active' === ($plugin->status()['code'] ?? null) && 'target_active' === ($theme->status()['code'] ?? null)
		&& true === ($plugin->status()['hooks_registered'] ?? null) && true === ($theme->status()['hooks_registered'] ?? null),
);
file_put_contents($output, json_encode($proof, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");

function requiredInput(string $name): string
{
	$value = getenv($name);
	if (!is_string($value) || '' === $value) throw new RuntimeException($name . ' is required.');
	return $value;
}
/** @return list<object> */
function nativeCallbacks(string $hook): array
{
	$registered = $GLOBALS['wp_filter'][$hook] ?? null;
	if (!$registered instanceof WP_Hook) return array();
	$found = array();
	foreach ($registered->callbacks as $priority) foreach ($priority as $entry) {
		$callback = $entry['function'] ?? null;
		if (is_array($callback) && is_object($callback[0]) && 'filterUpdate' === ($callback[1] ?? null)) $found[] = $callback[0];
	}
	return $found;
}
/** @param list<object> $callbacks @return list<string> */
function callbackOrigins(array $callbacks): array
{
	return array_map(static fn(object $callback): string => (string) (new ReflectionClass($callback))->getFileName(), $callbacks);
}
/** @param list<object> $callbacks */
function originsAreInstalled(array $callbacks, string $runtimeRoot): bool
{
	foreach (callbackOrigins($callbacks) as $origin) if (!str_starts_with($origin, $runtimeRoot . '/')) return false;
	return array() !== $callbacks;
}
function archiveManifest(string $zip, string $root): array
{
	$archive = new ZipArchive();
	if (true !== $archive->open($zip)) throw new RuntimeException('Could not read exact ZIP.');
	$manifest = array();
	for ($index = 0; $index < $archive->numFiles; ++$index) {
		$name = $archive->getNameIndex($index);
		$stat = $archive->statIndex($index);
		if (!is_string($name) || !is_array($stat) || !str_starts_with($name, $root . '/')) throw new RuntimeException('Consumer ZIP contains an unexpected entry.');
		if (str_ends_with($name, '/')) continue;
		$relative = substr($name, strlen($root) + 1);
		if ('' === $relative || str_contains($relative, '../') || isset($manifest[$relative])) throw new RuntimeException('Consumer ZIP entry is ambiguous.');
		$mode = (int) (($stat['external_attributes'] ?? 0) >> 16) & 0170000;
		if (0 !== $mode && 0100000 !== $mode) throw new RuntimeException('Consumer ZIP contains a non-regular entry.');
		$bytes = $archive->getFromIndex($index);
		if (!is_string($bytes)) throw new RuntimeException('Could not read ZIP entry.');
		$manifest[$relative] = array('sha256' => hash('sha256', $bytes), 'size' => strlen($bytes));
	}
	$archive->close();
	ksort($manifest);
	return $manifest;
}

function directoryManifest(string $root): array
{
	$manifest = array();
	$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
	foreach ($iterator as $file) {
		if ($file->isLink() || !$file->isFile()) throw new RuntimeException('Installed consumer has a non-regular entry.');
		$relative = substr($file->getPathname(), strlen($root) + 1);
		if (isset($manifest[$relative])) throw new RuntimeException('Installed consumer path is ambiguous.');
		$manifest[$relative] = array('sha256' => hash_file('sha256', $file->getPathname()), 'size' => $file->getSize());
	}
	ksort($manifest);
	return $manifest;
}
