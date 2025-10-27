import js from "@eslint/js";

export default [
	js.configs.recommended,
	{
		files: ["resources/js/**/*.js"],
		languageOptions: {
			ecmaVersion: 2021,
			sourceType: "module",
			globals: {
				window: "readonly",
				document: "readonly",
			},
		},
		rules: {
			"brace-style": ["error", "allman"],
			indent: ["error", "tab"],
			semi: ["error", "always"],
		},
	},
];
