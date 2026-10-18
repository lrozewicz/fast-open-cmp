import { test, expect } from '@playwright/test';
import { ADMIN_STATE, hasAdmin } from './admin';

/**
 * Template editor live preview. Needs an admin login: set CMP_ADMIN_USER and
 * CMP_ADMIN_PASS (the dev stack's credentials are not stored in the repo).
 */
test.skip(!hasAdmin(), 'CMP_ADMIN_USER / CMP_ADMIN_PASS not set');
test.use({ storageState: ADMIN_STATE });

test('preview reflects unsaved changes and stores nothing', async ({ page, context }) => {
  await page.goto('/wp-admin/admin.php?page=focmp-templates&action=edit&id=901');
  await page.click('#focmp-preview-toggle');
  const frame = page.frameLocator('#focmp-preview-frame');
  const buttons = frame.locator('#open-cmp-screen1 .open-cmp-screen1-footer button');
  await expect(buttons).toHaveCount(3);

  await page.selectOption('#compliance_profile', 'custom');
  await page.uncheck('input[name="fl_buttons[reject-all][show]"]');
  await expect(buttons).toHaveCount(2);

  await frame.locator('#open-cmp-screen1 button[data-type="accept-all"]').click();
  await expect(frame.locator('.open-cmp-preview-note')).toBeVisible();
  expect((await context.cookies()).some((c) => c.name === 'eupubconsent-v2')).toBe(false);
});
