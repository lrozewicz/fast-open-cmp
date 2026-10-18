import { test, expect } from '@playwright/test';
import { readFileSync, writeFileSync } from 'node:fs';
import { gunzipSync, gzipSync } from 'node:zlib';
import { queryDb } from './db';
import { adminPage, hasAdmin } from './admin';

/** Backup & migration screen. Needs CMP_ADMIN_USER / CMP_ADMIN_PASS. */
test.skip(!hasAdmin(), 'CMP_ADMIN_USER / CMP_ADMIN_PASS not set');

test('a configuration backup restores what was changed after it', async ({ browser }, testInfo) => {
  const admin = await adminPage(browser, { acceptDownloads: true });
  await admin.goto('/wp-admin/admin.php?page=focmp-backup');
  const download = admin.locator('form:has(input[name=download])');
  await expect(download.locator('input[name=consents]')).not.toBeChecked();
  const [file] = await Promise.all([admin.waitForEvent('download'), download.locator('button[type=submit]').click()]);
  expect(file.suggestedFilename()).toMatch(/^fast-open-cmp-backup-.*\.jsonl\.gz$/);
  const backup = (await file.path())!;
  const lines = gunzipSync(readFileSync(backup)).toString().trim().split('\n');
  expect(JSON.parse(lines[0])).toMatchObject({ format: 'fast-open-cmp-backup', consents: false });
  expect(lines.some((l) => l.includes('"table":"cmp_consent_log"'))).toBe(false);
  expect(JSON.parse(lines[lines.length - 1]).end).toBe(true);

  const name = queryDb('SELECT name FROM wp_cmp_templates WHERE id = 903')[0][0];
  const restore = async (path: string) => {
    await admin.goto('/wp-admin/admin.php?page=focmp-backup');
    const form = admin.locator('form:has(input[name=backup_file])');
    await form.locator('input[name=backup_file]').setInputFiles(path);
    await form.locator('input[name=confirm_replace]').check();
    admin.once('dialog', (dialog) => dialog.accept());
    await form.locator('button[name=focmp_restore]').click();
  };

  // A cut-off file is refused and changes nothing.
  queryDb("UPDATE wp_cmp_templates SET name = 'changed after backup' WHERE id = 903");
  const truncated = testInfo.outputPath('truncated.jsonl.gz');
  writeFileSync(truncated, gzipSync(lines.slice(0, 50).join('\n') + '\n'));
  await restore(truncated);
  await expect(admin.locator('.notice-error')).toBeVisible();
  expect(queryDb('SELECT name FROM wp_cmp_templates WHERE id = 903')).toEqual([['changed after backup']]);

  await restore(backup);
  await expect(admin.locator('.notice-success')).toContainText('cmp_templates');
  expect(queryDb('SELECT name FROM wp_cmp_templates WHERE id = 903')).toEqual([[name]]);
});
