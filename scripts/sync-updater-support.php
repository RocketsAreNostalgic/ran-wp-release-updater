<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$source = $root . '/vendor/ran/updater-support/src/ArchiveSafety.php';
$target = $root . '/src/Dependency/ArchiveSafety.php';
$check = array_slice($argv, 1) === array('--check');
if (! $check && 0 !== count(array_slice($argv, 1))) {
	fwrite(STDERR, "Usage: sync-updater-support.php [--check]\n");
	exit(2);
}
if (is_link($source) || ! is_file($source) || ! is_readable($source)) {
	fwrite(STDERR, "Installed updater-support source is unavailable or unsafe.\n");
	exit(1);
}
$canonical = file_get_contents($source);
if (! is_string($canonical)) {
	fwrite(STDERR, "Installed updater-support source could not be read.\n");
	exit(1);
}
$from = 'RAN\\UpdaterSupport\\V1';
$to = 'RAN\\WPReleaseUpdater\\V1\\Dependency';
if (1 !== substr_count($canonical, $from) || 1 !== substr_count($canonical, 'namespace ' . $from . ';')) {
	fwrite(STDERR, "Canonical updater-support namespace shape is unexpected.\n");
	exit(1);
}
$generated = str_replace($from, $to, $canonical);
if (1 !== substr_count($generated, $to) || str_contains($generated, $from)) {
	fwrite(STDERR, "Updater-support namespace rewrite is unexpected.\n");
	exit(1);
}
if ($check) {
	if (is_link($target) || ! is_file($target) || ! hash_equals($generated, (string) file_get_contents($target))) {
		fwrite(STDERR, "Generated updater-support runtime copy is stale.\n");
		exit(1);
	}
	exit(0);
}
if (is_link($target)) {
	fwrite(STDERR, "Generated updater-support runtime copy is unsafe.\n");
	exit(1);
}
if (is_link(dirname($target))) {
	fwrite(STDERR, "Generated dependency directory is unsafe.\n");
	exit(1);
}
if (! is_dir(dirname($target)) && ! mkdir(dirname($target), 0755, true)) {
	fwrite(STDERR, "Generated dependency directory could not be created.\n");
	exit(1);
}
if (false === file_put_contents($target, $generated)) {
	fwrite(STDERR, "Generated updater-support runtime copy could not be written.\n");
	exit(1);
}
