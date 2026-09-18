#!/usr/bin/env node

import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const FULL_SHA = /^[a-f0-9]{40}$/;
const TITLE = /^([a-z][a-z0-9-]*)(?:\([^)]+\))?(!)?:\s+\S/;
const RELEASE_BRANCH_PREFIX = 'release-please--branches--main--components--';
const PRODUCTION_COMPOSER_KEYS = [
	'name',
	'type',
	'require',
	'autoload',
	'conflict',
	'replace',
	'provide',
	'bin',
	'extra',
	'include-path',
	'target-dir',
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
	const metadata = {};
	for (const key of PRODUCTION_COMPOSER_KEYS) {
		if (Object.hasOwn(document, key)) {
			metadata[key] = document[key];
		}
	}
	return canonicalValue(metadata);
}

export function runtimeMetadata(runtimeCopy) {
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
		throw new Error('release-please-config.json must declare changelog-sections');
	}

	const types = new Set();
	for (const section of sections) {
		const entry = objectRecord(section, 'release-please changelog section');
		if (entry.hidden === true) continue;
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
		throw new Error('pull request title must use Conventional Commit syntax');
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
					path === 'runtime.php')
		)
	);
}

export function assertCanonicalReleasePull({
	author,
	headRef,
	manifest,
	title,
}) {
	const isCanonical =
		author === 'github-actions[bot]' &&
		typeof headRef === 'string' &&
		headRef.startsWith(RELEASE_BRANCH_PREFIX);
	if (!isCanonical) {
		return false;
	}
	const document = objectRecord(manifest, '.release-please-manifest.json');
	const version = document['.'];
	if (typeof version !== 'string' || version.length === 0) {
		throw new Error('release manifest root version is required');
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
	headComposer,
	baseRuntimeCopy,
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
			headRef: prHeadRef,
			manifest,
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
	return git(root, ['diff', '--name-only', '-z', baseSha, headSha])
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
		throw new Error('exact live pull request base and head SHAs are required');
	}
	if (
		typeof title !== 'string' ||
		typeof prHeadRef !== 'string' ||
		typeof prAuthor !== 'string' ||
		prHeadRef.length === 0 ||
		prAuthor.length === 0
	) {
		throw new Error('live pull request title, head ref, and author are required');
	}

	const checkoutSha = git(root, ['rev-parse', 'HEAD']).trim();
	if (checkoutSha !== baseSha) {
		throw new Error(
			`trusted classifier checkout ${checkoutSha} does not match live protected base ${baseSha}`
		);
	}

	const classificationBaseSha = mergeBase(root, baseSha, headSha);
	const result = assertReleaseClassification({
		baseComposer: readJsonAt(root, classificationBaseSha, 'composer.json'),
		headComposer: readJsonAt(root, headSha, 'composer.json'),
		baseRuntimeCopy: readJsonAt(
			root,
			classificationBaseSha,
			'runtime-copy.json'
		),
		headRuntimeCopy: readJsonAt(root, headSha, 'runtime-copy.json'),
		releaseConfig: readJsonAt(root, baseSha, 'release-please-config.json'),
		paths: changedPaths(root, classificationBaseSha, headSha),
		title,
		prAuthor,
		prHeadRef,
		manifest: readJsonAt(root, headSha, '.release-please-manifest.json'),
	});

	if (result.releasePull) {
		console.log('canonical Release Please pull request title is exact');
	} else if (result.required) {
		console.log(
			`release-significant release-updater change; classification ${result.classification.type}${result.classification.breaking ? '!' : ''} is release-driving`
		);
	} else {
		console.log(
			'no release-significant release-updater source, runtime metadata, or production Composer metadata change'
		);
	}
	return result;
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
	try {
		runCli();
	} catch (error) {
		console.error(error instanceof Error ? error.message : error);
		process.exitCode = 1;
	}
}
