import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const workflow = readFileSync(
	new URL('../.github/workflows/release-please.yml', import.meta.url),
	'utf8'
);
const ci = readFileSync(
	new URL('../.github/workflows/ci.yml', import.meta.url),
	'utf8'
);
const config = JSON.parse(
	readFileSync(
		new URL('../release-please-config.json', import.meta.url),
		'utf8'
	)
);
const packageConfig = config.packages['.'];

test('source releases use the pinned thin Profile A caller', () => {
	assert.match(
		workflow,
		/^on:\n  workflow_run:\n    workflows: \[CI\]\n    types: \[completed\]\n    branches: \[main\]$/m
	);
	assert.match(workflow, /^permissions: \{\}$/m);
	assert.match(
		workflow,
		/^jobs:\n  release:\n    permissions:\n      actions: write\n      contents: write\n      issues: write\n      pull-requests: write\n    uses: /m
	);
	assert.match(
		workflow,
		/^    uses: RocketsAreNostalgic\/\.github\/\.github\/workflows\/release-profile-a\.yml@289352e08cdf10b15d07c4e1c890f385afc3d3f5$/m
	);
	assert.match(
		workflow,
		/^      expected-workflow-path: \.github\/workflows\/ci\.yml$/m
	);
	assert.match(
		workflow,
		/^      release-pr-head: release-please--branches--main--components--ran\/wp-release-updater$/m
	);
	assert.doesNotMatch(workflow, /^\s+(?:steps|runs-on):/m);
});

test('Release Please owns native tag and GitHub Release publication', () => {
	assert.equal(config['release-type'], 'php');
	assert.equal(Object.hasOwn(config, 'skip-github-release'), false);
	assert.equal(Object.hasOwn(packageConfig, 'skip-github-release'), false);
});

test('runtime-copy version remains a narrow Release Please extra-file', () => {
	assert.deepEqual(packageConfig['extra-files'], [
		{
			type: 'json',
			path: 'runtime-copy.json',
			jsonpath: '$.package_version',
		},
	]);
});

test('candidate dispatch runs the normal input-free read-only CI', () => {
	assert.match(
		ci,
		/^on:\n  workflow_dispatch:\n  pull_request:\n  push:\n    branches: \[main\]$/m
	);
	assert.match(ci, /^permissions:\n  contents: read$/m);
	assert.doesNotMatch(ci, /^\s+inputs:/m);
	assert.doesNotMatch(
		ci,
		/^\s+(?:actions|contents|issues|pull-requests): write$/m
	);
});

test('automatic example qualification is pinned and required by Quality', () => {
	const automaticJob = ci.match(
		/^  wordpress-automatic-example:[\s\S]*?(?=^  quality:)/m
	)?.[0];
	assert.ok(automaticJob, 'Automatic example job is missing.');
	assert.match(
		ci,
		/^  wordpress-automatic-example:\n    name: Automatic example PHP \$\{\{ matrix\.php \}\} \/ WordPress \$\{\{ matrix\.wordpress \}\}$/m
	);
	assert.match(
		ci,
		/^          repository: RocketsAreNostalgic\/ran-wp-release-updater-example\n          ref: [a-f0-9]{40}$/m
	);
	assert.doesNotMatch(ci, /^          ref: 0{40}$/m);
	assert.match(
		ci,
		/^          - php: '8\.4'\n            wordpress: '7\.0'\n          - php: '8\.5'\n            wordpress: '7\.1'$/m
	);
	assert.match(ci, /^      - wordpress-automatic-example$/m);
	assert.match(
		ci,
		/^          AUTOMATIC_EXAMPLE_RESULT: \$\{\{ needs\.wordpress-automatic-example\.result \}\}$/m
	);
	assert.match(
		ci,
		/^          test "\$AUTOMATIC_EXAMPLE_RESULT" = success$/m
	);
	assert.match(
		automaticJob,
		/^          export PHP_INI_SCAN_DIR="\$\{PHP_INI_SCAN_DIR:-\}:\$php_ini"$/m
	);
	assert.doesNotMatch(automaticJob, /php_scan_dir=/);
	assert.match(automaticJob, /^          php -m \| grep -Fx Phar$/m);
	assert.match(automaticJob, /^          php -m \| grep -Fx zip$/m);
	assert.match(automaticJob, /^          php -m \| grep -Fx mysqli$/m);
});
