import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const workflow = readFileSync(
	new URL('../.github/workflows/release-please.yml', import.meta.url),
	'utf8'
);

test('release workflow authenticates the canonical CI path before mutation', () => {
	const jobStart = workflow.indexOf('jobs:\n  release:');
	const ifMarker = '    if: >-\n';
	const ifStart = workflow.indexOf(ifMarker, jobStart);
	const runsOn = workflow.indexOf('\n    runs-on:', ifStart);

	assert.ok(jobStart >= 0 && ifStart > jobStart && runsOn > ifStart);
	const conditionLines = workflow
		.slice(ifStart + ifMarker.length, runsOn)
		.trimEnd()
		.split('\n');
	assert.ok(
		conditionLines.every(
			(line) => line.startsWith('      ') && !line.trimStart().startsWith('#')
		)
	);
	const condition = conditionLines.map((line) => line.trim()).join(' ');
	assert.match(
		condition,
		/&& github\.event\.workflow_run\.path == '\.github\/workflows\/ci\.yml' &&/
	);
});
