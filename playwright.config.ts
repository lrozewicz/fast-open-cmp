import { defineConfig, devices } from '@playwright/test';

/**
 * E2E config. Targets the running dev stack (docker compose, WordPress on :8089).
 * The global setup seeds one domain per CMP mode (see e2e/seed.sql). Each test
 * runs in its own browser context, so cookies are isolated per test — no manual
 * cookie clearing needed despite all three modes sharing the localhost origin.
 */
export default defineConfig({
  testDir: './e2e',
  globalSetup: './e2e/global-setup.ts',
  timeout: 30_000,
  expect: { timeout: 10_000 },
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  reporter: 'list',
  use: {
    baseURL: process.env.CMP_BASE_URL || 'http://localhost:8089',
    trace: 'on-first-retry',
  },
  projects: [
    { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
  ],
});
