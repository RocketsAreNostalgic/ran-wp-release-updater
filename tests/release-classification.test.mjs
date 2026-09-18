import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { mkdtempSync, mkdirSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import test from 'node:test';

import {
	assertCanonicalReleasePull,
	assertReleaseClassification,
	classifyTitle,
	productionComposerMetadataChanged,
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
	name: 'ran/wp-release-updater',
	type: 'library',
	require: {
		php: '^8.2',
		'ext-hash': '*',
		'ext-json': '*',
		'ext-zip': '*',
	},
	autoload: {
		'psr-4': {
			'RAN\\WPReleaseUpdater\\V1\\': 'src/',
		},
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

const manifest = { '.': '0.1.0-beta.7' };

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

function initializeRepository() {
	const root = mkdtempSync(join(tmpdir(), 'release-updater-classification-'));
	git(root, ['init', '--initial-branch=main']);
	git(root, ['config', 'user.name', 'Release Test']);
	git(root, ['config', 'user.email', 'release@example.invalid']);
	writeJson(join(root, 'composer.json'), baseComposer);
	writeJson(join(root, 'runtime-copy.json'), baseRuntimeCopy);
	writeJson(join(root, 'release-please-config.json'), releaseConfig);
	writeJson(join(root, '.release-please-manifest.json'), manifest);
	git(root, ['add', '.']);
	git(root, ['commit', '-m', 'chore: base']);
	return { root, baseSha: git(root, ['rev-parse', 'HEAD']) };
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

test('production Composer comparison is recursive and ignores require-dev', () => {
	assert.equal(
		productionComposerMetadataChanged(baseComposer, {
			...baseComposer,
			require: {
				'ext-zip': '*',
				'ext-json': '*',
				'ext-hash': '*',
				php: '^8.2',
			},
			autoload: {
				'psr-4': {
					'RAN\\WPReleaseUpdater\\V1\\': 'src/',
				},
			},
			'require-dev': { 'phpstan/phpstan': '^3.0' },
		}),
		false
	);
	assert.equal(
		productionComposerMetadataChanged(baseComposer, {
			...baseComposer,
			autoload: {
				'psr-4': {
					'RAN\\WPReleaseUpdater\\V1\\': 'lib/',
				},
			},
		}),
		true
	);
});

test('Release Please package_version-only runtime metadata is not release-significant', () => {
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

test('shipped source, runtime metadata and production Composer metadata are release-significant', () => {
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
			headComposer: {
				...baseComposer,
				autoload: {
					'psr-4': {
						'RAN\\WPReleaseUpdater\\V1\\': 'lib/',
					},
				},
			},
			baseRuntimeCopy,
			headRuntimeCopy: baseRuntimeCopy,
			paths: ['composer.json'],
		}),
		true
	);
});

test('canonical Release Please pull title must exactly match manifest version', () => {
	assert.equal(
		assertCanonicalReleasePull({
			author: 'github-actions[bot]',
			headRef:
				'release-please--branches--main--components--ran/wp-release-updater',
			manifest,
			title: 'chore(main): release 0.1.0-beta.7',
		}),
		true
	);
	assert.throws(
		() =>
			assertCanonicalReleasePull({
				author: 'github-actions[bot]',
				headRef:
					'release-please--branches--main--components--ran/wp-release-updater',
				manifest,
				title: 'chore: release 0.1.0-beta.7',
			}),
		/must be exactly/
	);
});

test('release version metadata alone remains admissible with the exact generated title', () => {
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
			prAuthor: 'github-actions[bot]',
			prHeadRef:
				'release-please--branches--main--components--ran/wp-release-updater',
			manifest,
		}),
		{ required: true, classification: null, releasePull: true }
	);
});

test('release-significant changes reject non-driving classifications', () => {
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
				manifest,
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
			manifest,
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
			manifest,
		}).classification.breaking,
		true
	);
});

test('CLI executes from protected base and ignores head release-config weakening', () => {
	const { root, baseSha } = initializeRepository();
	try {
		mkdirSync(join(root, 'src'));
		writeFileSync(join(root, 'src', 'Runtime.php'), '<?php\n');
		writeJson(join(root, 'release-please-config.json'), {
			packages: {
				'.': {
					'changelog-sections': [
						{ type: 'refactor', section: 'Refactors' },
					],
				},
			},
		});
		git(root, ['add', '.']);
		git(root, ['commit', '-m', 'refactor: weaken release config']);
		const headSha = git(root, ['rev-parse', 'HEAD']);
		git(root, ['checkout', baseSha]);

		assert.throws(
			() =>
				runCli(root, {
					RAN_RELEASE_BASE_SHA: baseSha,
					RAN_RELEASE_HEAD_SHA: headSha,
					RAN_RELEASE_PR_TITLE: 'refactor: weaken release config',
					RAN_RELEASE_PR_HEAD_REF: 'feature',
					RAN_RELEASE_PR_AUTHOR: 'contributor',
				}),
			/release-significant release-updater changes require/
		);
	} finally {
		rmSync(root, { recursive: true, force: true });
	}
});

test('CLI treats newline-containing source paths as release-significant', () => {
	const { root, baseSha } = initializeRepository();
	try {
		mkdirSync(join(root, 'src'));
		writeFileSync(join(root, 'src', 'Line\nBreak.php'), '<?php\n');
		git(root, ['add', 'src']);
		git(root, ['commit', '-m', 'refactor: source path']);
		const headSha = git(root, ['rev-parse', 'HEAD']);
		git(root, ['checkout', baseSha]);

		assert.throws(
			() =>
				runCli(root, {
					RAN_RELEASE_BASE_SHA: baseSha,
					RAN_RELEASE_HEAD_SHA: headSha,
					RAN_RELEASE_PR_TITLE: 'refactor: source path',
					RAN_RELEASE_PR_HEAD_REF: 'feature',
					RAN_RELEASE_PR_AUTHOR: 'contributor',
				}),
			/release-significant release-updater changes require/
		);
	} finally {
		rmSync(root, { recursive: true, force: true });
	}
});
