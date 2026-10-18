// Vitest config — unit tests for the SDK's pure/service logic.
// jsdom environment so modules that touch document/window can be imported safely.
import { defineConfig } from 'vitest/config';
import { resolve } from 'path';

export default defineConfig({
  resolve: {
    alias: {
      '@': resolve(__dirname, './src'),
    },
  },
  test: {
    environment: 'jsdom',
    include: ['src/**/*.{test,spec}.ts'],
    globals: true,
    restoreMocks: true,
  },
});
