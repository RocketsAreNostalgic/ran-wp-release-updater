#!/usr/bin/env node

import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

import { verifyReleaseDelta } from './release-publisher-content.mjs';

const FULL_SHA = /^[a-f0-9]{40}$/;
const TITLE = /^([a-z][a-z0-9-]*)(?:\([^)]+\))?(!)?:\s+\S/;
const BETA = /^0\.1\.0-beta\.(0|[1-9][0-9]*)$/;
const UNRELEASED = '0.0.0';
const RELEASE_BRANCH =
	'release-please--branches--main--components--ran/wp-release-updater';
const DEVELOPMENT_ONLY_COMPOSER_KEYS = new Set([
	'require-dev',
	'autoload-dev',
	'scripts',
	'scripts-descriptions',
	'repositories',
	'config',
]);
const RELEASE_PULL_PATHS = [
	'.release-please-manifest.json',
	'CHANGELOG.md',
	'runtime-copy.json',
];

function objectRecord(value, label) {
	if (value === null || typeof value !== 'object' || Array.isArray(value)) {
		throw new Error(`${label} must be a JSON object`);
	}
	return value;
}

function canonicalValue(value) {
	if (Array.isArray(value)) {
		return value.map(canonicalValue);
	}
	if (value !== null && typeof value === 'object') {
		return Object.fromEntries(
			Object.entries(value)
				.sort(([left], [right]) => left.localeCompare(right))
				.map(([key, entry]) => [key, canonicalValue(entry)])
		);
	}
	return value;
}

export function productionComposerMetadata(composer) {
	const document = objectRecord(composer, 'composer.json');
	return canonicalValue(
		Object.fromEntries(
			Object.entries(document).filter(
				([key]) => !DEVELOPMENT_ONLY_COMPOSER_KEYS.has(key)
			)
		)
	);
}

export function runtimeMetadata(runtimeCopy) {
	return canonicalValue(objectRecord(runtimeCopy, 'runtime-copy.json'));
}

export function runtimeMetadataWithoutPackageVersion(runtimeCopy) {
	const document = objectRecord(runtimeCopy, 'runtime-copy.json');
	const { package_version: _packageVersion, ...metadata } = document;
	return canonicalValue(metadata);
}

export function visibleReleaseTypes(config) {
	const document = objectRecord(config, 'release-please-config.json');
	const packages = objectRecord(
		document.packages,
		'release-please-config.json packages'
	);
	const root = objectRecord(
		packages['.'],
		'release-please-config.json root package'
	);
	const sections = root['changelog-sections'];
	if (!Array.isArray(sections) || sections.length === 0) {
		throw new Error(
			'release-please-config.json must declare changelog-sections'
		);
	}

	const types = new Set();
	for (const section of sections) {
		const entry = objectRecord(section, 'release-please changelog section');
		if (entry.hidden === true) {
			continue;
		}
		if (typeof entry.type !== 'string' || entry.type.length === 0) {
			throw new Error(
				'visible release-please changelog sections must declare a type'
			);
		}
		types.add(entry.type);
	}
	if (types.size === 0) {
		throw new Error(
			'release-please-config.json declares no visible release-driving types'
		);
	}
	return types;
}

export function classifyTitle(title) {
	if (typeof title !== 'string') {
		throw new Error('pull request title is required');
	}
	const match = title.match(TITLE);
	if (!match) {
		throw new Error(
			'pull request title must use Conventional Commit syntax'
		);
	}
	return { type: match[1], breaking: match[2] === '!' };
}

export function productionComposerMetadataChanged(baseComposer, headComposer) {
	return (
		JSON.stringify(productionComposerMetadata(baseComposer)) !==
		JSON.stringify(productionComposerMetadata(headComposer))
	);
}

export function runtimeMetadataChanged(baseRuntimeCopy, headRuntimeCopy) {
	return (
		JSON.stringify(runtimeMetadata(baseRuntimeCopy)) !==
		JSON.stringify(runtimeMetadata(headRuntimeCopy))
	);
}

export function releaseSignificantChange({
	baseComposer,
	headComposer,
	baseRuntimeCopy,
	headRuntimeCopy,
	paths,
}) {
	if (!Array.isArray(paths)) {
		throw new Error('changed paths must be a list');
	}
	return (
		productionComposerMetadataChanged(baseComposer, headComposer) ||
		runtimeMetadataChanged(baseRuntimeCopy, headRuntimeCopy) ||
		paths.some(
			(path) =>
				typeof path === 'string' &&
				(path.startsWith('src/') ||
					path === 'bootstrap.php' ||
					path === 'runtime.php' ||
					path === '.gitattributes' ||
					path === 'LICENSE' ||
					path === '.release-please-manifest.json')
		)
	);
}

export function assertCanonicalReleasePull({
	author,
	baseContents,
	baseManifest,
	baseRuntimeCopy,
	headContents,
	headRef,
	headRuntimeCopy,
	manifest,
	paths,
	title,
}) {
	const isCanonical =
		author === 'github-actions[bot]' && headRef === RELEASE_BRANCH;
	if (!isCanonical) {
		return false;
	}

	const normalizedPaths = [...paths].sort();
	if (
		JSON.stringify(normalizedPaths) !== JSON.stringify(RELEASE_PULL_PATHS)
	) {
		throw new Error(
			'canonical Release Please pull request changed non-generated files'
		);
	}

	if (
		JSON.stringify(
			runtimeMetadataWithoutPackageVersion(baseRuntimeCopy)
		) !==
		JSON.stringify(runtimeMetadataWithoutPackageVersion(headRuntimeCopy))
	) {
		throw new Error(
			'canonical Release Please pull request changed runtime metadata beyond package_version'
		);
	}

	const baseDocument = objectRecord(
		baseManifest,
		'base .release-please-manifest.json'
	);
	const headDocument = objectRecord(
		manifest,
		'.release-please-manifest.json'
	);
	const baseVersion = baseDocument['.'];
	const version = headDocument['.'];
	if (
		Object.keys(baseDocument).length !== 1 ||
		Object.keys(headDocument).length !== 1 ||
		typeof baseVersion !== 'string' ||
		(baseVersion !== UNRELEASED && !BETA.test(baseVersion)) ||
		typeof version !== 'string' ||
		!BETA.test(version)
	) {
		throw new Error(
			'canonical Release Please pull request must use the independent beta version line'
		);
	}
	if (baseVersion !== UNRELEASED) {
		const baseNumber = Number(baseVersion.match(BETA)[1]);
		const headNumber = Number(version.match(BETA)[1]);
		if (headNumber <= baseNumber) {
			throw new Error(
				'canonical Release Please pull request version must advance'
			);
		}
	}
	const baseRuntime = objectRecord(baseRuntimeCopy, 'base runtime-copy.json');
	const headRuntime = objectRecord(headRuntimeCopy, 'runtime-copy.json');
	if (
		baseRuntime.package_version !== baseVersion ||
		headRuntime.package_version !== version
	) {
		throw new Error(
			'canonical Release Please pull request package_version must match manifest'
		);
	}

	const delta = verifyReleaseDelta(baseContents, headContents);
	if (
		delta.parentVersion !== baseVersion ||
		delta.candidateVersion !== version
	) {
		throw new Error(
			'canonical Release Please pull request raw release delta does not match parsed release identity'
		);
	}

	const expected = `chore(main): release ${version}`;
	if (title !== expected) {
		throw new Error(
			`canonical Release Please pull request title must be exactly "${expected}"`
		);
	}
	return true;
}

export function assertReleaseClassification({
	baseComposer,
	baseContents,
	baseManifest,
	headComposer,
	baseRuntimeCopy,
	headContents,
	headRuntimeCopy,
	releaseConfig,
	paths,
	title,
	prAuthor = '',
	prHeadRef = '',
	manifest = {},
}) {
	if (
		assertCanonicalReleasePull({
			author: prAuthor,
			baseContents,
			baseManifest,
			baseRuntimeCopy,
			headContents,
			headRef: prHeadRef,
			headRuntimeCopy,
			manifest,
			paths,
			title,
		})
	) {
		return { required: true, classification: null, releasePull: true };
	}

	if (
		!releaseSignificantChange({
			baseComposer,
			headComposer,
			baseRuntimeCopy,
			headRuntimeCopy,
			paths,
		})
	) {
		return { required: false, classification: null, releasePull: false };
	}

	const classification = classifyTitle(title);
	const visible = visibleReleaseTypes(releaseConfig);
	if (!classification.breaking && !visible.has(classification.type)) {
		throw new Error(
			`release-significant release-updater changes require one of ${[...visible].join(', ')} or an explicit breaking ! classification; classification "${classification.type}" is not release-driving`
		);
	}
	return { required: true, classification, releasePull: false };
}

function git(root, args) {
	return execFileSync('git', args, {
		cwd: root,
		encoding: 'utf8',
		stdio: ['ignore', 'pipe', 'pipe'],
	});
}

function readJsonAt(root, sha, path) {
	return JSON.parse(git(root, ['show', `${sha}:${path}`]).trim());
}

function readTextAt(root, sha, path) {
	return git(root, ['show', `${sha}:${path}`]);
}

function mergeBase(root, baseSha, headSha) {
	const sha = git(root, ['merge-base', baseSha, headSha]).trim();
	if (!FULL_SHA.test(sha)) {
		throw new Error(
			'pull request base and head do not have a canonical merge base'
		);
	}
	return sha;
}

function changedPaths(root, baseSha, headSha) {
	return git(root, [
		'diff',
		'--name-only',
		'--no-renames',
		'-z',
		baseSha,
		headSha,
	])
		.split('\0')
		.filter(Boolean)
		.sort();
}

export function runCli(root = process.cwd(), env = process.env) {
	const baseSha = env.RAN_RELEASE_BASE_SHA;
	const headSha = env.RAN_RELEASE_HEAD_SHA;
	const title = env.RAN_RELEASE_PR_TITLE;
	const prHeadRef = env.RAN_RELEASE_PR_HEAD_REF;
	const prAuthor = env.RAN_RELEASE_PR_AUTHOR;

	if (!FULL_SHA.test(baseSha ?? '') || !FULL_SHA.test(headSha ?? '')) {
		throw new Error(
			'exact live pull request base and head SHAs are required'
		);
	}
	if (
		typeof title !== 'string' ||
		typeof prHeadRef !== 'string' ||
		typeof prAuthor !== 'string' ||
		prHeadRef.length === 0 ||
		prAuthor.length === 0
	) {
		throw new Error(
			'live pull request title, head ref, and author are required'
		);
	}

	const checkoutSha = git(root, ['rev-parse', 'HEAD']).trim();
	if (checkoutSha !== baseSha) {
		throw new Error(
			`trusted classifier checkout ${checkoutSha} does not match live protected base ${baseSha}`
		);
	}

	const classificationBaseSha = mergeBase(root, baseSha, headSha);
	let baseContents;
	let headContents;
	if (prAuthor === 'github-actions[bot]' && prHeadRef === RELEASE_BRANCH) {
		baseContents = {
			manifest: readTextAt(
				root,
				classificationBaseSha,
				'.release-please-manifest.json'
			),
			runtimeCopy: readTextAt(
				root,
				classificationBaseSha,
				'runtime-copy.json'
			),
			changelog: readTextAt(root, classificationBaseSha, 'CHANGELOG.md'),
		};
		headContents = {
			manifest: readTextAt(root, headSha, '.release-please-manifest.json'),
			runtimeCopy: readTextAt(root, headSha, 'runtime-copy.json'),
			changelog: readTextAt(root, headSha, 'CHANGELOG.md'),
		};
	}
	const result = assertReleaseClassification({
		baseComposer: readJsonAt(root, classificationBaseSha, 'composer.json'),
		baseContents,
		baseManifest: readJsonAt(
			root,
			classificationBaseSha,
			'.release-please-manifest.json'
		),
		headComposer: readJsonAt(root, headSha, 'composer.json'),
		baseRuntimeCopy: readJsonAt(
			root,
			classificationBaseSha,
			'runtime-copy.json'
		),
		headRuntimeCopy: readJsonAt(root, headSha, 'runtime-copy.json'),
		headContents,
		releaseConfig: readJsonAt(root, baseSha, 'release-please-config.json'),
		paths: changedPaths(root, classificationBaseSha, headSha),
		title,
		prAuthor,
		prHeadRef,
		manifest: readJsonAt(root, headSha, '.release-please-manifest.json'),
	});

	if (result.releasePull) {
		process.stdout.write(
			'canonical Release Please pull request title is exact\n'
		);
	} else if (result.required) {
		process.stdout.write(
			`release-significant release-updater change; classification ${result.classification.type}${result.classification.breaking ? '!' : ''} is release-driving\n`
		);
	} else {
		process.stdout.write(
			'no release-significant release-updater source, runtime metadata, or production Composer metadata change\n'
		);
	}
	return result;
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
	try {
		runCli();
	} catch (error) {
		process.stderr.write(
			`${error instanceof Error ? error.message : error}\n`
		);
		process.exitCode = 1;
	}
}
