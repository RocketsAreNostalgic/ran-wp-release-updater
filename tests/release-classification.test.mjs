import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { mkdtempSync, mkdirSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import test from 'node:test';

import {
	assertReleaseClassification,
	classifyTitle,
	productionRequirementsChanged,
	releaseSignificantChange,
	runCli,
	runtimeMetadataChanged,
	visibleReleaseTypes,
} from '../scripts/release-classification.mjs';

const releaseConfig = {
	packages: {
		'.': {
			'changelog-sections': [
				{ type: 'feat', section: 'Features' },
				{ type: 'fix', section: 'Bug Fixes' },
				{ type: 'perf', section: 'Performance' },
				{ type: 'revert', section: 'Reverts' },
				{ type: 'refactor', section: 'Code Refactoring', hidden: true },
				{ type: 'chore', section: 'Miscellaneous Chores', hidden: true },
			],
		},
	},
};

const baseComposer = {
	require: {
		php: '^8.2',
		'ext-hash': '*',
		'ext-json': '*',
		'ext-zip': '*',
	},
	'require-dev': { 'phpstan/phpstan': '^2.1' },
};

const baseRuntimeCopy = {
	package_revision: 'a'.repeat(64),
	package_version: '0.1.0-beta.6',
	php_floor: '8.2.0',
	runtime_file: 'runtime.php',
	runtime_protocol: 4,
	wordpress_floor: '6.5.0',
};

function git(root, args) {
	return execFileSync('git', args, {
		cwd: root,
		encoding: 'utf8',
		stdio: ['ignore', 'pipe', 'pipe'],
	}).trim();
}

function writeJson(path, value) {
	writeFileSync(path, `${JSON.stringify(value, null, 2)}\n`, 'utf8');
}

test('derives release-updater visible release-driving types', () => {
	assert.deepEqual([...visibleReleaseTypes(releaseConfig)], [
		'feat',
		'fix',
		'perf',
		'revert',
	]);
});

test('parses scoped and breaking Conventional Commit titles', () => {
	assert.deepEqual(classifyTitle('fix(runtime): preserve update lifecycle'), {
		type: 'fix',
		breaking: false,
	});
	assert.deepEqual(classifyTitle('refactor!: replace runtime protocol'), {
		type: 'refactor',
		breaking: true,
	});
});

test('production requirement comparison ignores require-dev', () => {
	assert.equal(
		productionRequirementsChanged(baseComposer, {
			...baseComposer,
			'require-dev': { 'phpstan/phpstan': '^3.0' },
		}),
		false
	);
	assert.equal(
		productionRequirementsChanged(baseComposer, {
			...baseComposer,
			require: { ...baseComposer.require, php: '^8.3' },
		}),
		true
	);
});

test('Release Please package_version-only metadata changes are not release-significant', () => {
	assert.equal(
		runtimeMetadataChanged(baseRuntimeCopy, {
			...baseRuntimeCopy,
			package_version: '0.1.0-beta.7',
		}),
		false
	);
	assert.equal(
		runtimeMetadataChanged(baseRuntimeCopy, {
			...baseRuntimeCopy,
			runtime_protocol: 5,
		}),
		true
	);
});

test('shipped source, bootstrap, runtime, metadata and production requirements are release-significant', () => {
	for (const path of [
		'src/Runtime/RequestBroker.php',
		'bootstrap.php',
		'runtime.php',
	]) {
		assert.equal(
			releaseSignificantChange({
				baseComposer,
				headComposer: baseComposer,
				baseRuntimeCopy,
				headRuntimeCopy: baseRuntimeCopy,
				paths: [path],
			}),
			true
		);
	}
	assert.equal(
		releaseSignificantChange({
			baseComposer,
			headComposer: baseComposer,
			baseRuntimeCopy,
			headRuntimeCopy: { ...baseRuntimeCopy, wordpress_floor: '6.6.0' },
			paths: ['runtime-copy.json'],
		}),
		true
	);
	assert.equal(
		releaseSignificantChange({
			baseComposer,
			headComposer: { ...baseComposer, require: { ...baseComposer.require, php: '^8.3' } },
			baseRuntimeCopy,
			headRuntimeCopy: baseRuntimeCopy,
			paths: ['composer.json'],
		}),
		true
	);
});

test('release version PR metadata alone does not force a release-driving title', () => {
	assert.deepEqual(
		assertReleaseClassification({
			baseComposer,
			headComposer: baseComposer,
			baseRuntimeCopy,
			headRuntimeCopy: {
				...baseRuntimeCopy,
				package_version: '0.1.0-beta.7',
			},
			releaseConfig,
			paths: [
				'.release-please-manifest.json',
				'CHANGELOG.md',
				'runtime-copy.json',
			],
			title: 'chore(main): release 0.1.0-beta.7',
		}),
		{ required: false, classification: null }
	);
});

test('release-significant changes reject hidden squash classifications', () => {
	assert.throws(
		() =>
			assertReleaseClassification({
				baseComposer,
				headComposer: baseComposer,
				baseRuntimeCopy,
				headRuntimeCopy: baseRuntimeCopy,
				releaseConfig,
				paths: ['runtime.php'],
				title: 'refactor: reorganize runtime',
			}),
		/release-significant release-updater changes require/
	);
});

test('visible and explicit breaking classifications admit release-significant changes', () => {
	assert.equal(
		assertReleaseClassification({
			baseComposer,
			headComposer: baseComposer,
			baseRuntimeCopy,
			headRuntimeCopy: baseRuntimeCopy,
			releaseConfig,
			paths: ['src/Runtime/RequestBroker.php'],
			title: 'fix(runtime): preserve broker selection',
		}).classification.type,
		'fix'
	);
	assert.equal(
		assertReleaseClassification({
			baseComposer,
			headComposer: baseComposer,
			baseRuntimeCopy,
			headRuntimeCopy: baseRuntimeCopy,
			releaseConfig,
			paths: ['bootstrap.php'],
			title: 'refactor!: replace bootstrap contract',
		}).classification.breaking,
		true
	);
});

test('CLI classifies only merge-base-to-head changes and verifies exact checkout', () => {
	const root = mkdtempSync(join(tmpdir(), 'release-updater-classification-'));
	try {
		git(root, ['init', '--initial-branch=main']);
		git(root, ['config', 'user.name', 'Release Test']);
		git(root, ['config', 'user.email', 'release@example.invalid']);
		writeJson(join(root, 'composer.json'), baseComposer);
		writeJson(join(root, 'runtime-copy.json'), baseRuntimeCopy);
		writeJson(join(root, 'release-please-config.json'), releaseConfig);
		writeFileSync(join(root, 'README.md'), 'base\n');
		git(root, ['add', '.']);
		git(root, ['commit', '-m', 'chore: base']);
		const baseSha = git(root, ['rev-parse', 'HEAD']);
		git(root, ['branch', 'feature']);

		mkdirSync(join(root, 'src'));
		writeFileSync(join(root, 'src', 'BaseOnly.php'), '<?php\n');
		git(root, ['add', 'src/BaseOnly.php']);
		git(root, ['commit', '-m', 'feat: advance main']);
		const advancedBaseSha = git(root, ['rev-parse', 'HEAD']);

		git(root, ['checkout', 'feature']);
		writeFileSync(join(root, 'README.md'), 'feature docs\n');
		git(root, ['add', 'README.md']);
		git(root, ['commit', '-m', 'docs: update docs']);
		const headSha = git(root, ['rev-parse', 'HEAD']);

		assert.deepEqual(
			runCli(root, {
				RAN_RELEASE_BASE_SHA: advancedBaseSha,
				RAN_RELEASE_HEAD_SHA: headSha,
				RAN_RELEASE_PR_TITLE: 'Update docs',
			}),
			{ required: false, classification: null }
		);

		assert.throws(
			() =>
				runCli(root, {
					RAN_RELEASE_BASE_SHA: baseSha,
					RAN_RELEASE_HEAD_SHA: baseSha,
					RAN_RELEASE_PR_TITLE: 'fix: wrong checkout',
				}),
			/does not match pull request head/
		);
	} finally {
		rmSync(root, { recursive: true, force: true });
	}
});

test('CLI treats newline-containing source paths as release-significant', () => {
	const root = mkdtempSync(
		join(tmpdir(), 'release-updater-classification-newline-')
	);
	try {
		git(root, ['init', '--initial-branch=main']);
		git(root, ['config', 'user.name', 'Release Test']);
		git(root, ['config', 'user.email', 'release@example.invalid']);
		writeJson(join(root, 'composer.json'), baseComposer);
		writeJson(join(root, 'runtime-copy.json'), baseRuntimeCopy);
		writeJson(join(root, 'release-please-config.json'), releaseConfig);
		git(root, ['add', '.']);
		git(root, ['commit', '-m', 'chore: base']);
		const baseSha = git(root, ['rev-parse', 'HEAD']);

		mkdirSync(join(root, 'src'));
		const path = join(root, 'src', 'Line\nBreak.php');
		writeFileSync(path, '<?php\n');
		git(root, ['add', 'src']);
		git(root, ['commit', '-m', 'refactor: source path']);
		const headSha = git(root, ['rev-parse', 'HEAD']);

		assert.throws(
			() =>
				runCli(root, {
					RAN_RELEASE_BASE_SHA: baseSha,
					RAN_RELEASE_HEAD_SHA: headSha,
					RAN_RELEASE_PR_TITLE: 'refactor: source path',
				}),
			/release-significant release-updater changes require/
		);
	} finally {
		rmSync(root, { recursive: true, force: true });
	}
});
