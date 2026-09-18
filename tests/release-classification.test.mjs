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
	runtimeMetadataWithoutPackageVersion,
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
				{
					type: 'chore',
					section: 'Miscellaneous Chores',
					hidden: true,
				},
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

const baseManifest = { '.': '0.1.0-beta.6' };
const manifest = { '.': '0.1.0-beta.7' };
const headRuntimeCopy = {
	...baseRuntimeCopy,
	package_version: '0.1.0-beta.7',
};
const baseChangelog =
	'# Changelog\n\n' +
	'## [0.1.0-beta.6](https://github.com/RocketsAreNostalgic/ran-wp-release-updater/compare/v0.1.0-beta.5...v0.1.0-beta.6) (2026-09-17)\n\n' +
	'### Bug Fixes\n\n' +
	'- previous fix\n';
const headChangelog =
	'# Changelog\n\n' +
	'## [0.1.0-beta.7](https://github.com/RocketsAreNostalgic/ran-wp-release-updater/compare/v0.1.0-beta.6...v0.1.0-beta.7) (2026-09-18)\n\n' +
	'### Bug Fixes\n\n' +
	'- next fix\n\n' +
	baseChangelog.slice('# Changelog\n\n'.length);
const baseReleaseContents = {
	manifest: `${JSON.stringify(baseManifest, null, 2)}\n`,
	runtimeCopy: `${JSON.stringify(baseRuntimeCopy, null, 2)}\n`,
	changelog: baseChangelog,
};
const headReleaseContents = {
	manifest: `${JSON.stringify(manifest, null, 2)}\n`,
	runtimeCopy: `${JSON.stringify(headRuntimeCopy, null, 2)}\n`,
	changelog: headChangelog,
};

const repository = 'RocketsAreNostalgic/ran-wp-release-updater';
const repositoryId = '1342292184';
const releaseBaseSha = 'a'.repeat(40);
const releaseHeadSha = 'b'.repeat(40);
const releaseTreeEntries = Object.fromEntries(
	[
		'.release-please-manifest.json',
		'CHANGELOG.md',
		'runtime-copy.json',
	].map((path, index) => [
		path,
		{
			mode: '100644',
			type: 'blob',
			sha: String(index + 1).repeat(40),
		},
	])
);

function canonicalReleaseInput(overrides = {}) {
	return {
		author: 'github-actions[bot]',
		baseContents: baseReleaseContents,
		baseManifest,
		baseRuntimeCopy,
		baseSha: releaseBaseSha,
		baseTreeEntries: releaseTreeEntries,
		headContents: headReleaseContents,
		headRef:
			'release-please--branches--main--components--ran/wp-release-updater',
		headRepository: repository,
		headRepositoryId: repositoryId,
		headRuntimeCopy,
		headTreeEntries: releaseTreeEntries,
		manifest,
		mergeBaseSha: releaseBaseSha,
		repository,
		repositoryId,
		paths: [
			'.release-please-manifest.json',
			'CHANGELOG.md',
			'runtime-copy.json',
		],
		title: 'chore(main): release 0.1.0-beta.7',
		...overrides,
	};
}

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
	assert.deepEqual(
		[...visibleReleaseTypes(releaseConfig)],
		['feat', 'fix', 'perf', 'revert']
	);
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

test('production Composer comparison includes public metadata and ignores dev-only keys', () => {
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
	assert.equal(
		productionComposerMetadataChanged(baseComposer, {
			...baseComposer,
			license: 'MIT',
			support: {
				security: 'https://example.invalid/security',
			},
		}),
		true
	);
});

test('ordinary package_version changes remain release-significant', () => {
	assert.equal(
		runtimeMetadataChanged(baseRuntimeCopy, {
			...baseRuntimeCopy,
			package_version: '0.1.0-beta.7',
		}),
		true
	);
	assert.deepEqual(
		runtimeMetadataWithoutPackageVersion(baseRuntimeCopy),
		runtimeMetadataWithoutPackageVersion({
			...baseRuntimeCopy,
			package_version: '0.1.0-beta.7',
		})
	);
});

test('shipped source, runtime metadata and production Composer metadata are release-significant', () => {
	for (const path of [
		'src/Runtime/RequestBroker.php',
		'bootstrap.php',
		'runtime.php',
		'.gitattributes',
		'LICENSE',
		'.release-please-manifest.json',
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
			headRuntimeCopy: {
				...baseRuntimeCopy,
				wordpress_floor: '6.6.0',
			},
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
	assert.equal(assertCanonicalReleasePull(canonicalReleaseInput()), true);
	assert.throws(
		() =>
			assertCanonicalReleasePull(
				canonicalReleaseInput({
					title: 'chore: release 0.1.0-beta.7',
				})
			),
		/must be exactly/
	);
});

test('canonical Release Please bypass requires the exact branch', () => {
	assert.equal(
		assertCanonicalReleasePull(
			canonicalReleaseInput({
				headRef:
					'release-please--branches--main--components--unexpected',
			})
		),
		false
	);
});

test('canonical Release Please bypass requires the upstream repository identity', () => {
	assert.equal(
		assertCanonicalReleasePull(
			canonicalReleaseInput({
				headRepository: 'example/fork',
			})
		),
		false
	);
	assert.equal(
		assertCanonicalReleasePull(
			canonicalReleaseInput({
				headRepositoryId: '999999',
			})
		),
		false
	);
});

test('canonical Release Please bypass requires the live base as merge base', () => {
	assert.throws(
		() =>
			assertCanonicalReleasePull(
				canonicalReleaseInput({
					mergeBaseSha: 'c'.repeat(40),
				})
			),
		/must contain the live protected base/
	);
});

test('canonical Release Please bypass requires ordinary generated-file blobs', () => {
	assert.throws(
		() =>
			assertCanonicalReleasePull(
				canonicalReleaseInput({
					headTreeEntries: {
						...releaseTreeEntries,
						'CHANGELOG.md': {
							...releaseTreeEntries['CHANGELOG.md'],
							mode: '100755',
						},
					},
				})
			),
		/ordinary non-executable Git blob/
	);
});

test('canonical Release Please version stays on and advances the beta line', () => {
	for (const [headVersion, expected] of [
		['1.0.0', /independent beta version line/],
		['0.1.0-beta.6', /version must advance/],
		['0.1.0-beta.5', /version must advance/],
	]) {
		assert.throws(
			() =>
				assertCanonicalReleasePull(
					canonicalReleaseInput({
						headRuntimeCopy: {
							...baseRuntimeCopy,
							package_version: headVersion,
						},
						manifest: { '.': headVersion },
						title: `chore(main): release ${headVersion}`,
					})
				),
			expected
		);
	}
});

test('canonical Release Please bypass rejects byte drift in prior changelog history', () => {
	assert.throws(
		() =>
			assertCanonicalReleasePull(
				canonicalReleaseInput({
					headContents: {
						...headReleaseContents,
						changelog: headChangelog.replace(
							'- previous fix',
							'- edited previous fix'
						),
					},
				})
			),
		/release_content_drift/
	);
});

test('release version metadata is bypassed only for an exact generated Release Please delta', () => {
	assert.deepEqual(
		assertReleaseClassification({
			baseComposer,
			baseContents: baseReleaseContents,
			baseManifest,
			baseRuntimeCopy,
			baseSha: releaseBaseSha,
			baseTreeEntries: releaseTreeEntries,
			headComposer: baseComposer,
			headContents: headReleaseContents,
			headRefRepository: repository,
			headRefRepositoryId: repositoryId,
			headRuntimeCopy,
			headTreeEntries: releaseTreeEntries,
			mergeBaseSha: releaseBaseSha,
			repository,
			repositoryId,
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

	assert.throws(
		() =>
			assertReleaseClassification({
				baseComposer,
				baseContents: baseReleaseContents,
				baseManifest,
				baseRuntimeCopy,
				baseSha: releaseBaseSha,
				baseTreeEntries: releaseTreeEntries,
				headComposer: baseComposer,
				headContents: headReleaseContents,
				headRefRepository: repository,
				headRefRepositoryId: repositoryId,
				headRuntimeCopy,
				headTreeEntries: releaseTreeEntries,
				mergeBaseSha: releaseBaseSha,
				repository,
				repositoryId,
				releaseConfig,
				paths: [
					'.release-please-manifest.json',
					'CHANGELOG.md',
					'runtime-copy.json',
					'src/Runtime/Injected.php',
				],
				title: 'chore(main): release 0.1.0-beta.7',
				prAuthor: 'github-actions[bot]',
				prHeadRef:
					'release-please--branches--main--components--ran/wp-release-updater',
				manifest,
			}),
		/non-generated files/
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

test('CLI treats source renamed out of src as release-significant', () => {
	const { root } = initializeRepository();
	try {
		mkdirSync(join(root, 'src'));
		writeFileSync(join(root, 'src', 'Api.php'), '<?php\n');
		git(root, ['add', 'src']);
		git(root, ['commit', '-m', 'feat: add api']);
		const sourceBase = git(root, ['rev-parse', 'HEAD']);

		git(root, ['mv', 'src/Api.php', 'Api.php']);
		git(root, ['commit', '-m', 'refactor: move api']);
		const headSha = git(root, ['rev-parse', 'HEAD']);
		git(root, ['checkout', sourceBase]);

		assert.throws(
			() =>
				runCli(root, {
					RAN_RELEASE_BASE_SHA: sourceBase,
					RAN_RELEASE_HEAD_SHA: headSha,
					RAN_RELEASE_PR_TITLE: 'refactor: move api',
					RAN_RELEASE_PR_HEAD_REF: 'feature',
					RAN_RELEASE_PR_AUTHOR: 'contributor',
				}),
			/release-significant release-updater changes require/
		);
	} finally {
		rmSync(root, { recursive: true, force: true });
	}
});
