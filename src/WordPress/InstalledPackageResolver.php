<?php

declare(strict_types=1);

namespace RAN\WPReleaseUpdater\V1\WordPress;

use RAN\WPReleaseUpdater\V1\Archive\PackageIdentityValidator;
use RAN\WPReleaseUpdater\V1\Contract\ReleaseVersion;

/** @internal Resolves one stable, WordPress-registered installed target. */
final class InstalledPackageResolver
{
	private const MAX_HEADER_BYTES = 8192;

	/** @var null|\Closure(string):void Tests inject a deterministic race by Reflection. */
	private ?\Closure $beforeFirstStat = null;

	/** @var null|\Closure(string):void Tests inject a deterministic race by Reflection. */
	private ?\Closure $afterFirstRead = null;

	/** @var null|\Closure(resource,int):string|false Tests inject bounded read failures by Reflection. */
	private ?\Closure $read = null;

	/** @var null|\Closure(resource):bool Tests inject lock and rewind failures by Reflection. */
	private ?\Closure $lock = null;
	private ?\Closure $rewind = null;

	/** @param array<string,mixed> $pluginPaths @param list<mixed> $themeDirectories */
	public function __construct(
		private readonly string $pluginDirectory,
		private readonly array $pluginPaths,
		private readonly array $themeDirectories,
	) {
	}

	/** @param array<string,mixed> $declaration @return array<string,mixed> */
	public function resolve(array $declaration): array
	{
		$file = $declaration['installed_file'] ?? null;
		$type = $declaration['target_type'] ?? null;
		if (!is_string($file) || !in_array($type, array('plugin', 'theme'), true) || !$this->validPath($file)) {
			return array('code' => 'installed_file_invalid');
		}

		$file = str_replace('\\', '/', $file);
		clearstatcache(true, $file);
		$initial = @lstat($file);
		if (!is_array($initial)) {
			return array('code' => 'installed_file_missing');
		}
		if (is_link($file)) {
			return array('code' => 'installed_file_symlink');
		}
		if (($initial['mode'] & 0170000) !== 0100000) {
			return array('code' => 'installed_file_not_regular');
		}

		$real = @realpath($file);
		if (!is_string($real)) {
			return array('code' => 'installed_file_unreadable');
		}
		$root = $this->rootFor($type, $file, $real);
		if (null === $root) {
			return array('code' => 'installed_file_outside_root');
		}
		if (false === $root) {
			return array('code' => 'installed_file_root_ambiguous');
		}
		$linkRoot = $this->inside($file, $root['logical']) ? $root['logical'] : $root['real'];
		if ($this->hasInternalLink($file, $linkRoot)) {
			return array('code' => 'installed_file_outside_root');
		}

		$identityRoot = 'plugin' === $type ? rtrim(str_replace('\\', '/', $this->pluginDirectory), '/') : $root['logical'];
		$identity = $this->identity($type, $root['file'], $identityRoot);
		if (is_string($identity)) {
			return array('code' => $identity);
		}
		$captured = $this->capture($file, $initial);
		if (is_string($captured)) {
			return array('code' => $captured);
		}

		clearstatcache(true, $file);
		$last = @lstat($file);
		$lastReal = @realpath($file);
		$lastRoot = is_string($lastReal) ? $this->rootFor($type, $file, $lastReal) : null;
		if (!$this->sameStat($initial, $last) || $real !== $lastReal || !is_array($lastRoot) || $root !== $lastRoot) {
			return array('code' => 'installed_file_changed');
		}

		$headerResult = PackageIdentityValidator::parseHeader($captured[0], $type);
		if ('installed_header_verified' !== $headerResult['code'] || !isset($headerResult['headers'])) {
			return array('code' => $headerResult['code']);
		}
		$headers = $headerResult['headers'];
		if ($this->requirementsIncompatible($headers)) {
			return array('code' => 'installed_requirement_incompatible');
		}

		return array(
			'archive_root' => $identity['root'],
			'code' => 'installed_identity_verified',
			'header_file' => $identity['file'],
			'headers' => $headers,
			'installed_package_identity' => $identity['identity'],
		);
	}

	private function validPath(string $path): bool
	{
		$path = str_replace('\\', '/', $path);
		$drive = 1 === preg_match('/\A[A-Za-z]:\//', $path);
		$unc = str_starts_with($path, '//');
		if ('' === $path || strlen($path) > 4096 || (!$drive && !str_starts_with($path, '/')) || 1 === preg_match('/[\x00-\x1f\x7f]/', $path)) {
			return false;
		}
		$parts = explode('/', substr($path, $drive ? 3 : ($unc ? 2 : 1)));
		if ($unc && count($parts) < 2) {
			return false;
		}
		foreach ($parts as $part) {
			if ('' === $part || '.' === $part || '..' === $part) {
				return false;
			}
		}
		return true;
	}

	/** @return array{logical:string,real:string,file:string}|false|null */
	private function rootFor(string $type, string $file, string $real): array|false|null
	{
		$roots = array();
		if ('plugin' === $type) {
			$this->addRoot($roots, $this->pluginDirectory, $this->pluginDirectory);
			foreach ($this->pluginPaths as $logical => $actual) {
				if (!is_string($logical) || !is_string($actual)) {
					continue;
				}
				if ($this->inside(rtrim(str_replace('\\', '/', $logical), '/'), rtrim(str_replace('\\', '/', $this->pluginDirectory), '/'))) {
					$this->addRoot($roots, $logical, $actual);
				}
			}
		} else {
			foreach ($this->themeDirectories as $directory) {
				if (is_string($directory)) {
					$this->addRoot($roots, $directory, $directory);
				}
			}
		}

		$matches = array();
		foreach ($roots as $root) {
			$logicalFile = null;
			if ($this->inside($file, $root['logical']) && $this->inside($real, $root['real'])) {
				$logicalFile = $file;
			}
			if ($this->inside($file, $root['real']) && $this->inside($real, $root['real'])) {
				$logicalFile = $root['logical'] . substr($file, strlen($root['real']));
			}
			if (is_string($logicalFile)) {
				$matches[$logicalFile] = array('logical' => $root['logical'], 'real' => $root['real'], 'file' => $logicalFile);
			}
		}
		if (0 === count($matches)) {
			return null;
		}
		if (1 !== count($matches)) {
			return false;
		}
		return array_values($matches)[0];
	}

	/** @param array<string,array{logical:string,real:string}> $roots */
	private function addRoot(array &$roots, string $logical, string $actual): void
	{
		$logical = rtrim(str_replace('\\', '/', $logical), '/');
		$actual = rtrim(str_replace('\\', '/', $actual), '/');
		if (!$this->validPath($logical) || !$this->validPath($actual)) return;
		$real = @realpath($actual);
		if (!is_string($real) || !is_dir($real)) {
			return;
		}
		$real = str_replace('\\', '/', $real);
		$roots[$logical . "\0" . $real] = array('logical' => $logical, 'real' => $real);
	}

	private function inside(string $path, string $root): bool
	{
		$path = str_replace('\\', '/', $path);
		$root = str_replace('\\', '/', $root);
		if ('Windows' === PHP_OS_FAMILY) {
			$path = strtolower($path);
			$root = strtolower($root);
		}
		return str_starts_with($path, $root . '/');
	}

	private function hasInternalLink(string $file, string $root): bool
	{
		$relative = substr($file, strlen($root) + 1);
		if (false === $relative) {
			return true;
		}
		$path = $root;
		foreach (explode('/', $relative) as $part) {
			$path .= '/' . $part;
			if ($path !== $file && is_link($path)) {
				return true;
			}
		}
		return false;
	}

	/** @return array{identity:string,root:string,file:string}|string */
	private function identity(string $type, string $file, string $root): array|string
	{
		$parts = explode('/', (string) substr($file, strlen($root) + 1));
		if ('plugin' === $type) {
			if (2 !== count($parts)) {
				return 1 === count($parts) ? 'plugin_root_level_unsupported' : 'installed_file_outside_root';
			}
			$base = substr($parts[1], 0, -4);
			if (1 !== preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,99}\z/D', $parts[0]) || !str_ends_with($parts[1], '.php') || 1 !== preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,99}\z/D', $base)) {
				return 'installed_file_invalid';
			}
			return array('identity' => $parts[0] . '/' . $parts[1], 'root' => $parts[0], 'file' => $parts[1]);
		}

		if ('style.css' !== basename($file)) {
			return 'theme_header_file_invalid';
		}
		if (2 !== count($parts)) {
			return 'theme_nested_identity_unsupported';
		}
		if (1 !== preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,99}\z/D', $parts[0])) {
			return 'theme_nested_identity_unsupported';
		}
		return array('identity' => $parts[0], 'root' => $parts[0], 'file' => 'style.css');
	}

	/** @return string|array{0:string,1:array<string,int>} */
	private function capture(string $file, array $initial): string|array
	{
		$stream = @fopen($file, 'rb');
		if (!is_resource($stream)) {
			return 'installed_file_unreadable';
		}

		try {
			if (null !== $this->beforeFirstStat) {
				($this->beforeFirstStat)($file);
			}
			$first = @fstat($stream);
			if (!$this->sameStat($initial, $first)) {
				return 'installed_file_changed';
			}
			$locked = null === $this->lock ? @flock($stream, LOCK_SH | LOCK_NB) : ($this->lock)($stream);
			if (!$locked) {
				return 'installed_file_unreadable';
			}
			$one = null === $this->read ? fread($stream, self::MAX_HEADER_BYTES) : ($this->read)($stream, 1);
			$rewound = null === $this->rewind ? -1 !== @fseek($stream, 0) : ($this->rewind)($stream);
			if (!is_string($one) || !$rewound) {
				return 'installed_file_unreadable';
			}
			if (null !== $this->afterFirstRead) {
				($this->afterFirstRead)($file);
			}
			$two = null === $this->read ? fread($stream, self::MAX_HEADER_BYTES) : ($this->read)($stream, 2);
			$last = @fstat($stream);
			if (!is_string($two)) {
				return 'installed_file_unreadable';
			}
			if ($one !== $two || !$this->sameStat($initial, $last)) {
				return 'installed_file_changed';
			}
			return array($one, $last);
		} finally {
			fclose($stream);
		}
	}

	/** @param array<string,string> $headers */
	private function requirementsIncompatible(array $headers): bool
	{
		if (null === ReleaseVersion::normalizeHeader($headers['Version'])) {
			return true;
		}
		if ('' !== $headers['RequiresPHP'] && (null === ReleaseVersion::normalizeHeader($headers['RequiresPHP']) || ReleaseVersion::compare(PHP_VERSION, $headers['RequiresPHP']) < 0)) {
			return true;
		}
		$wordpress = \RAN\WPReleaseUpdater\V1\Runtime\SelectedRuntimeState::normalizeWordPressVersion($GLOBALS['wp_version'] ?? null);
		$comparison = is_string($wordpress) ? ReleaseVersion::compare($wordpress, $headers['RequiresWP']) : null;
		return '' !== $headers['RequiresWP'] && (null === ReleaseVersion::normalizeHeader($headers['RequiresWP']) || null === $comparison || $comparison < 0);
	}

	private function sameStat(array $one, mixed $two): bool
	{
		if (!is_array($two)) {
			return false;
		}
		foreach (array('dev', 'ino', 'mode', 'size', 'mtime', 'ctime') as $key) {
			if (($one[$key] ?? null) !== ($two[$key] ?? null)) {
				return false;
			}
		}
		return true;
	}
}
