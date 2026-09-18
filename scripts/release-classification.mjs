#!/usr/bin/env node

import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const FULL_SHA = /^[a-f0-9]{40}$/;
const TITLE = /^([a-z][a-z0-9-]*)(?:\([^)]+\))?(!)?:\s+\S/;

function objectRecord(value, label) {
	if (value === null || typeof value !== 'object' || Array.isArray(value)) {
		throw new Error(`${label} must be a JSON object`);
	}
	return value;
}

function canonicalRecord(value) {
	return Object.fromEntries(
		Object.entries(value).sort(([left], [right]) => left.localeCompare(right))
	);
}

export function productionRequirements(composer) {
	const document = objectRecord(composer, 'composer.json');
	return canonicalRecord(
		objectRecord(document.require ?? {}, 'composer.json require')
	);
}

export function runtimeMetadata(runtimeCopy) {
	const document = objectRecord(runtimeCopy, 'runtime-copy.json');
	const { package_version: _packageVersion, ...metadata } = document;
	return canonicalRecord(metadata);
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

export function productionRequirementsChanged(baseComposer, headComposer) {
	return (
		JSON.stringify(productionRequirements(baseComposer)) !==
		JSON.stringify(productionRequirements(headComposer))
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
		productionRequirementsChanged(baseComposer, headComposer) ||
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

export function assertReleaseClassification({
	baseComposer,
	headComposer,
	baseRuntimeCopy,
	headRuntimeCopy,
	releaseConfig,
	paths,
	title,
}) {
	if (
		!releaseSignificantChange({
			baseComposer,
			headComposer,
			baseRuntimeCopy,
			headRuntimeCopy,
			paths,
		})
	) {
		return { required: false, classification: null };
	}

	const classification = classifyTitle(title);
	const visible = visibleReleaseTypes(releaseConfig);
	if (!classification.breaking && !visible.has(classification.type)) {
		throw new Error(
			`release-significant release-updater changes require one of ${[...visible].join(', ')} or an explicit breaking ! classification; classification \"${classification.type}\" is hidden`
		);
	}
	return { required: true, classification };
}

function git(root, args) {
	return execFileSync('git', args, {
		cwd: root,
		encoding: 'utf8',
		stdio: ['ignore', 'pipe', 'pipe'],
	}).trim();
}

function readJson(path) {
	return JSON.parse(readFileSync(path, 'utf8'));
}

function mergeBase(root, baseSha, headSha) {
	const sha = git(root, ['merge-base', baseSha, headSha]);
	if (!FULL_SHA.test(sha)) {
		throw new Error(
			'pull request base and head do not have a canonical merge base'
		);
	}
	return sha;
}

function readJsonAt(root, sha, path) {
	return JSON.parse(git(root, ['show', `${sha}:${path}`]));
}

function changedPaths(root, baseSha, headSha) {
	return execFileSync(
		'git',
		['diff', '--name-only', '-z', baseSha, headSha],
		{
			cwd: root,
			encoding: 'utf8',
			stdio: ['ignore', 'pipe', 'pipe'],
		}
	)
		.split('\0')
		.filter(Boolean)
		.sort();
}

export function runCli(root = process.cwd(), env = process.env) {
	const baseSha = env.RAN_RELEASE_BASE_SHA;
	const headSha = env.RAN_RELEASE_HEAD_SHA;
	const title = env.RAN_RELEASE_PR_TITLE;
	if (!FULL_SHA.test(baseSha ?? '') || !FULL_SHA.test(headSha ?? '')) {
		throw new Error('exact pull request base and head SHAs are required');
	}

	const checkoutSha = git(root, ['rev-parse', 'HEAD']);
	if (checkoutSha !== headSha) {
		throw new Error(
			`checked out revision ${checkoutSha} does not match pull request head ${headSha}`
		);
	}

	const classificationBaseSha = mergeBase(root, baseSha, headSha);
	const result = assertReleaseClassification({
		baseComposer: readJsonAt(root, classificationBaseSha, 'composer.json'),
		headComposer: readJson(`${root}/composer.json`),
		baseRuntimeCopy: readJsonAt(
			root,
			classificationBaseSha,
			'runtime-copy.json'
		),
		headRuntimeCopy: readJson(`${root}/runtime-copy.json`),
		releaseConfig: readJson(`${root}/release-please-config.json`),
		paths: changedPaths(root, classificationBaseSha, headSha),
		title,
	});

	if (result.required) {
		console.log(
			`release-significant release-updater change; classification ${result.classification.type}${result.classification.breaking ? '!' : ''} is release-driving`
		);
	} else {
		console.log(
			'no release-significant release-updater source, runtime metadata, or production requirement change'
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
