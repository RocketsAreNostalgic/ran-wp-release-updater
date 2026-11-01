import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import test from 'node:test';

import { ESLint } from 'eslint';

const require = createRequire(import.meta.url);
const wordpressPlugin = require.resolve('@wordpress/eslint-plugin');
const importPlugin = require(
	require.resolve('eslint-plugin-import', { paths: [wordpressPlugin] })
);
const jsxA11yPlugin = require(
	require.resolve('eslint-plugin-jsx-a11y', { paths: [wordpressPlugin] })
);

function eslintFor(rules) {
	return new ESLint({
		overrideConfigFile: true,
		overrideConfig: {
			languageOptions: {
				parserOptions: {
					ecmaFeatures: { jsx: true },
				},
			},
			plugins: {
				import: importPlugin,
				'jsx-a11y': jsxA11yPlugin,
			},
			rules,
		},
	});
}

test('import dev-dependency glob keeps its configured positive and negative paths', async () => {
	const eslint = eslintFor({
		'import/no-extraneous-dependencies': [
			'error',
			{ devDependencies: ['tests/**'] },
		],
	});
	const permitted = await eslint.lintText(
		"import eslint from 'eslint';\nexport default eslint;\n",
		{ filePath: 'tests/consumer.mjs' }
	);
	const rejected = await eslint.lintText(
		"import eslint from 'eslint';\nexport default eslint;\n",
		{ filePath: 'src/consumer.mjs' }
	);

	assert.equal(permitted[0].errorCount, 0);
	assert.ok(
		rejected[0].messages.some(
			(message) => message.ruleId === 'import/no-extraneous-dependencies'
		)
	);
});

test('jsx accessibility label control keeps positive and negative paths', async () => {
	const eslint = eslintFor({
		'jsx-a11y/label-has-associated-control': 'error',
	});
	const permitted = await eslint.lintText(
		'const view = <label htmlFor="x">Name<input id="x" /></label>;\n',
		{ filePath: 'tests/label.mjs' }
	);
	const rejected = await eslint.lintText(
		'const view = <label><input /></label>;\n',
		{ filePath: 'tests/label.mjs' }
	);

	assert.equal(permitted[0].errorCount, 0);
	assert.ok(
		rejected[0].messages.some(
			(message) =>
				message.ruleId === 'jsx-a11y/label-has-associated-control'
		)
	);
});
