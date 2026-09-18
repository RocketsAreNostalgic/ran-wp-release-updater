import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const workflowUrl = new URL(
	'../.github/workflows/release-please.yml',
	import.meta.url
);
const workflow = readFileSync(workflowUrl, 'utf8');
const ciWorkflow = readFileSync(
	new URL('../.github/workflows/ci.yml', import.meta.url),
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

test('required quality cannot be manufactured without PR classification', () => {
	assert.doesNotMatch(ciWorkflow, /^\s*workflow_dispatch:/m);
	assert.match(
		ciWorkflow,
		/pull_request:\n\s+types: \[opened, synchronize, reopened, edited\]/
	);
	assert.match(
		ciWorkflow,
		/RAN_RELEASE_PR_TITLE: \$\{\{ github\.event\.pull_request\.title \}\}/
	);
	assert.match(
		ciWorkflow,
		/quality:\n\s+if: \$\{\{ always\(\) \}\}[\s\S]*needs:[\s\S]*- release-classification/
	);
});
