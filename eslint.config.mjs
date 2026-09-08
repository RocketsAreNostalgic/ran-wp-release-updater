import wordpress from '@wordpress/eslint-plugin';
import globals from 'globals';

export default [
	{
		ignores: ['node_modules/**', 'vendor/**', '.workspaces/**'],
	},
	...wordpress.configs.recommended,
	{
		files: ['scripts/**/*.mjs', 'tests/**/*.mjs'],
		languageOptions: {
			globals: globals.node,
		},
		settings: {
			react: {
				version: 'latest',
			},
		},
	},
];
