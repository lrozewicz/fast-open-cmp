import type { Browser, BrowserContextOptions } from '@playwright/test';

/** Admin session saved by global-setup.ts (needs CMP_ADMIN_USER / CMP_ADMIN_PASS). */
export const ADMIN_STATE = 'e2e/.auth/admin.json';

export const hasAdmin = (): boolean => !!process.env.CMP_ADMIN_USER && !!process.env.CMP_ADMIN_PASS;

/** A logged-in admin page. */
export const adminPage = async (browser: Browser, options: BrowserContextOptions = {}) =>
  (await browser.newContext({ ...options, storageState: ADMIN_STATE })).newPage();
