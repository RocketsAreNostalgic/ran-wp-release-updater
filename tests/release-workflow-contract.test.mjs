import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const workflowUrl = new URL(
	'../.github/workflows/release-please.yml',
	import.meta.url
);
const workflow = readFileSync(workflowUrl, 'utf8');
const classificationWorkflow = readFileSync(
	new URL('../.github/workflows/release-classification.yml', import.meta.url),
	'utf8'
);

test('release job requires the canonical CI workflow path', () => {
	const jobStart = workflow.indexOf('jobs:\n  release:');
	const ifMarker = '    if: >-\n';
	const ifStart = workflow.indexOf(ifMarker, jobStart);
	const runsOn = workflow.indexOf('\n    runs-on:', ifStart);

	assert.ok(jobStart >= 0);
	assert.ok(ifStart > jobStart);
	assert.ok(runsOn > ifStart);

	const conditionLines = workflow
		.slice(ifStart + ifMarker.length, runsOn)
		.trimEnd()
		.split('\n');
	const allLinesActive = conditionLines.every((line) => {
		const isIndented = line.startsWith('      ');
		const isComment = line.trimStart().startsWith('#');
		return isIndented && !isComment;
	});
	assert.ok(allLinesActive);

	const condition = conditionLines.map((line) => line.trim()).join(' ');
	const terms = condition
		.replace('${{', '')
		.replace('}}', '')
		.split('&&')
		.map((term) => term.trim());
	const pathGuard =
		"github.event.workflow_run.path == '.github/workflows/ci.yml'";
	assert.ok(terms.includes(pathGuard));
});

test('trusted release classification workflow stays on protected base', () => {
	assert.match(classificationWorkflow, /^\s*pull_request_target:/m);
	assert.match(
		classificationWorkflow,
		/pull_request_target:\n\s+branches: \[main\]/
	);
	assert.match(classificationWorkflow, /test "\$base_ref" = main/);
	assert.match(
		classificationWorkflow,
		/ref: \$\{\{ steps\.pr\.outputs\.base_sha \}\}/
	);
	assert.match(
		classificationWorkflow,
		/git fetch --no-tags origin "\+refs\/pull\/\$\{RAN_PR_NUMBER\}\/head:refs\/remotes\/origin\/pr-head"/
	);
	assert.match(
		classificationWorkflow,
		/test "\$\(git rev-parse refs\/remotes\/origin\/pr-head\)" = "\$RAN_HEAD_SHA"/
	);
	assert.match(
		classificationWorkflow,
		/run: node scripts\/release-classification\.mjs/
	);
	assert.doesNotMatch(
		classificationWorkflow,
		/ref: \$\{\{ github\.event\.pull_request\.head\.sha \}\}/
	);
});
