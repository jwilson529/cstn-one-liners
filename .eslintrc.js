module.exports = {
    root: true,
    env: {
        browser: true,
        es2021: true,
    },
    globals: {
        jQuery: 'readonly', // Add this line to allow jQuery usage
    },
    extends: [
        'eslint:recommended',
        'plugin:@wordpress/eslint-plugin/recommended'
    ],
    parserOptions: {
        ecmaVersion: 12,
        sourceType: 'module',
    },
    rules: {
        'no-console': 'warn',
        'eqeqeq': 'error',
        'no-eval': 'error',
    },
};