import { test, expect } from '@playwright/test';
import { queryDb } from './db';
import { adminPage, hasAdmin } from './admin';

/** Consent log screen. Needs CMP_ADMIN_USER / CMP_ADMIN_PASS (see admin-preview.spec.ts). */
test.skip(!hasAdmin(), 'CMP_ADMIN_USER / CMP_ADMIN_PASS not set');

test('filter by decision and export one person\'s consents as JSON', async ({ browser }) => {
  // A visitor accepts, then withdraws.
  const visitor = await (await browser.newContext()).newPage();
  await visitor.goto('/examples/cmp-e2e.html?domain_id=901');
  await visitor.locator('#open-cmp-screen1 button[data-type="accept-all"]').click();
  await visitor.waitForTimeout(500);
  await visitor.locator('#cmp-footer-link').click();
  await visitor.locator('#open-cmp-screen2 button[data-type="not-accept"]').click();
  await visitor.waitForTimeout(500);
  const consentId = (await visitor.context().cookies()).find((c) => c.name === 'cmp-consent-id')!.value;

  const admin = await adminPage(browser, { acceptDownloads: true });

  await admin.goto('/wp-admin/admin.php?page=focmp-consent-log&decision=reject-all');
  const decisions = await admin.locator('tbody tr td:nth-child(4)').allTextContents();
  expect(new Set(decisions).size).toBe(1);

  await admin.goto(`/wp-admin/admin.php?page=focmp-consent-log&consent_id=${consentId}`);
  const [download] = await Promise.all([
    admin.waitForEvent('download'),
    admin.locator('tbody tr').first().locator('a.button').click(),
  ]);
  expect(download.suggestedFilename()).toBe(`consent-${consentId}.json`);
  const data = JSON.parse(require('fs').readFileSync(await download.path(), 'utf8'));
  expect(data.consentId).toBe(consentId);
  expect(data.decisions.map((d: { decision: string }) => d.decision)).toEqual(['accept-all', 'reject-all']);
  expect(data.decisions[0].tcf.purposesConsent[0]).toMatchObject({ id: 1, name: expect.any(String) });
  expect(data.decisions[1].tcf.purposesConsent).toEqual([]);
});

test('delete entries recorded before a chosen date', async ({ browser }, testInfo) => {
  const run = String(testInfo.repeatEachIndex).padStart(4, '0');
  const id = (n: number) => `eeeeeeee-${run}-4000-8000-00000000000${n}`;
  queryDb(`DELETE FROM wp_cmp_consent_log WHERE consent_id LIKE 'eeeeeeee-${run}-4000-8000-%';
    INSERT INTO wp_cmp_consent_log (consent_id, domain_id, action, mode, env, created_at) VALUES
    ('${id(1)}', 901, 'accept-all', 'tcf_gcm', 'test', '2018-05-31 23:59:59'),
    ('${id(2)}', 901, 'accept-all', 'tcf_gcm', 'test', '2018-06-01 00:00:00');`);

  const admin = await adminPage(browser);
  await admin.goto('/wp-admin/admin.php?page=focmp-consent-log');
  await admin.locator('#focmp-consent-tools summary').click();
  const form = admin.locator('form:has(input[name=delete_before])');
  await form.locator('input[name=delete_before]').fill('2018-06-01');
  await form.locator('input[name=confirm_delete]').check();
  admin.once('dialog', (dialog) => dialog.accept());
  await form.locator('button[type=submit]').click();
  await expect(admin.locator('#focmp-consent-tools')).toHaveAttribute('open', '');
  await expect(admin.locator('.notice-success')).toContainText('2018-06-01');

  const left = queryDb(`SELECT consent_id FROM wp_cmp_consent_log WHERE consent_id LIKE 'eeeeeeee-${run}-4000-8000-%'`);
  expect(left).toEqual([[id(2)]]);
  queryDb(`DELETE FROM wp_cmp_consent_log WHERE consent_id LIKE 'eeeeeeee-${run}-4000-8000-%'`);
});

test('export a date range and import it back without duplicates', async ({ browser }, testInfo) => {
  // Own ids and year per repeated run (--repeat-each runs copies in parallel).
  const run = String(testInfo.repeatEachIndex).padStart(4, '0');
  const year = 2019 + testInfo.repeatEachIndex;
  const id = (n: number) => `dddddddd-${run}-4000-8000-00000000000${n}`;
  const where = `consent_id LIKE 'dddddddd-${run}-4000-8000-%'`;
  queryDb(`DELETE FROM wp_cmp_consent_log WHERE ${where};
    INSERT INTO wp_cmp_consent_log (consent_id, record_id, domain_id, action, mode, env, created_at, ip_anon) VALUES
    ('${id(1)}', 'dddddddd-${run}-4111-8000-000000000001', 901, 'accept-all', 'tcf_gcm', 'test', '${year}-03-01 00:00:00', '203.0.113.0'),
    ('${id(2)}', NULL, 901, 'reject-all', 'tcf_gcm', 'test', '${year}-03-31 23:59:59', NULL),
    ('${id(3)}', NULL, 901, 'save', 'tcf_gcm', 'test', '${year}-04-01 00:00:00', NULL);`);

  const admin = await adminPage(browser, { acceptDownloads: true });
  const openTools = async () => {
    await admin.goto('/wp-admin/admin.php?page=focmp-consent-log');
    await admin.locator('#focmp-consent-tools summary').click();
  };

  await openTools();
  const exportForm = admin.locator('form:has(input[value=jsonl])');
  await exportForm.locator('input[name=from]').fill(`${year}-03-01`);
  await exportForm.locator('input[name=to]').fill(`${year}-03-31`);
  await exportForm.locator('select[name=domain_id]').selectOption('901');
  await exportForm.locator('input[value=jsonl]').check();
  await exportForm.locator('input[name=gzip]').check();
  const [download] = await Promise.all([
    admin.waitForEvent('download'),
    exportForm.locator('button[type=submit]').click(),
  ]);
  expect(download.suggestedFilename()).toBe(`consent-log-${year}-03-01_${year}-03-31.jsonl.gz`);
  const file = await download.path();
  const lines = require('zlib').gunzipSync(require('fs').readFileSync(file)).toString().trim().split('\n');
  expect(JSON.parse(lines[0]).format).toBe('fast-open-cmp-consents');
  expect(lines.slice(1).map((l: string) => JSON.parse(l).consent_id)).toEqual([id(1), id(2)]);

  queryDb(`DELETE FROM wp_cmp_consent_log WHERE ${where}`);
  const importFile = async () => {
    await openTools();
    const form = admin.locator('form:has(input[name=consent_file])');
    await form.locator('input[name=consent_file]').setInputFiles(file!);
    await form.locator('button[type=submit]').click();
  };
  await importFile();
  await expect(admin.locator('.notice-success')).toContainText(/\b2\b.*\b0\b/);
  expect(queryDb(`SELECT consent_id, action, created_at, domain_id FROM wp_cmp_consent_log WHERE ${where} ORDER BY created_at`)).toEqual([
    [id(1), 'accept-all', `${year}-03-01 00:00:00`, '901'],
    [id(2), 'reject-all', `${year}-03-31 23:59:59`, '901'],
  ]);

  await importFile(); // same file again: nothing new
  expect(queryDb(`SELECT COUNT(*) FROM wp_cmp_consent_log WHERE ${where}`)).toEqual([['2']]);
  queryDb(`DELETE FROM wp_cmp_consent_log WHERE ${where};
    DELETE FROM wp_cmp_consent_stats WHERE domain_id = 901 AND day BETWEEN '${year}-03-01' AND '${year}-03-31';`);
});
