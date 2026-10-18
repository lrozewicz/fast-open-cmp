import js from '@eslint/js';
import tseslint from 'typescript-eslint';
import prettier from 'eslint-config-prettier';

// Pragmatic gate, not a rewrite. Recommended rules catch real breakage as errors;
// known-noisy stylistic rules are downgraded to warnings so the codebase passes
// today and the gate stays meaningful. Tighten over time (todo §3/§4).
export default tseslint.config(
  {
    // Lint source + tests; skip build output and Node build infra (own globals).
    ignores: ['dist/**', 'node_modules/**', '*.config.js', 'build-and-serve.js', 'vitePlugins/**'],
  },
  js.configs.recommended,
  ...tseslint.configs.recommended,
  {
    rules: {
      // SDK logs intentionally; the production build strips console.* (vite drop).
      'no-console': 'off',
      '@typescript-eslint/no-explicit-any': 'warn',
      '@typescript-eslint/no-unused-vars': [
        'warn',
        { argsIgnorePattern: '^_', varsIgnorePattern: '^_' },
      ],
      'no-empty': ['warn', { allowEmptyCatch: true }],
      // `const self = this` appears in a few closures; flag, don't block.
      '@typescript-eslint/no-this-alias': 'warn',
    },
  },
  {
    // Tests lean on casts/any to reach private state and build fixtures.
    files: ['**/*.test.ts'],
    rules: {
      '@typescript-eslint/no-explicit-any': 'off',
    },
  },
  prettier,
);
