import { test, expect, type Page } from '@playwright/test';

/**
 * One domain per CMP mode (seeded by e2e/seed.sql). The Consent Mode `update`
 * signal cleanly separates the three modes — it is the real per-mode contract:
 *   - tcf_gcm : GCM active, signals mapped from IAB purposes → accept ⇒ granted
 *   - gcm_only: GCM active, signals mapped from custom consents (none seeded)
 *               → accept ⇒ an update fires but stays denied
 *   - basic   : GCM not used at all → neither a default nor an update
 *               (a lone "denied" default would block Google tags forever)
 */
type Mode = {
  name: string;
  domainId: number;
  update: 'granted' | 'denied' | 'none';
};

const MODES: Mode[] = [
  { name: 'tcf_gcm', domainId: 901, update: 'granted' },
  { name: 'gcm_only', domainId: 902, update: 'denied' },
  { name: 'basic', domainId: 903, update: 'none' },
];

// Consent Mode signals are pushed into window.dataLayer as gtag arguments.
const consentEntries = (page: Page, kind: 'default' | 'update') =>
  page.evaluate((k) => {
    const dl = (window as unknown as { dataLayer?: unknown[] }).dataLayer || [];
    return dl
      .map((a) => Array.from(a as ArrayLike<unknown>))
      .filter((e) => e[0] === 'consent' && e[1] === k)
      .map((e) => e[2] as Record<string, string>);
  }, kind);

for (const m of MODES) {
  test.describe(`mode ${m.name} (domain ${m.domainId})`, () => {
    test('shows box, accepts, writes cookie and emits the right GCM signals', async ({ page }) => {
      await page.goto(`/examples/cmp-e2e.html?domain_id=${m.domainId}`);

      const box = page.locator('#open-cmp-container');
      await expect(box).toBeVisible();

      if (m.update === 'none') {
        // basic: no Consent Mode default either.
        await page.waitForTimeout(300);
        expect(await consentEntries(page, 'default')).toHaveLength(0);
      } else {
        // A `consent default` (all denied, with wait_for_update) is emitted on load.
        await expect.poll(async () => (await consentEntries(page, 'default')).length).toBeGreaterThan(0);
        const def = (await consentEntries(page, 'default'))[0];
        expect(def.ad_storage).toBe('denied');
        expect(Number(def.wait_for_update)).toBeGreaterThan(0);
      }

      // Accept all on the first layer.
      await page.locator('#open-cmp-container button[data-type="accept-all"]').click();

      // Box closes and the custom-vendors cookie is written in every mode.
      await expect(box).toBeHidden();
      const cookies = await page.context().cookies();
      expect(cookies.some((c) => c.name === 'custom-vendors-consent')).toBe(true);

      // Per-mode Consent Mode `update` contract.
      if (m.update === 'none') {
        // basic never wires the consent-changed → update listener.
        await page.waitForTimeout(500);
        expect(await consentEntries(page, 'update')).toHaveLength(0);
      } else {
        await expect.poll(async () => (await consentEntries(page, 'update')).length).toBe(1);
        expect((await consentEntries(page, 'update'))[0].ad_storage).toBe(m.update);
      }
    });
  });
}

for (const m of MODES.filter((x) => x.name !== 'tcf_gcm')) {
  test.describe(`mode ${m.name} — no TCF artifacts`, () => {
    test('no TC String and no __tcfapi; custom consents persist across reloads', async ({
      page,
    }) => {
      await page.goto(`/examples/cmp-e2e.html?domain_id=${m.domainId}`);
      await expect(page.locator('#open-cmp-container')).toBeVisible();
      expect(await page.evaluate(() => typeof (window as { __tcfapi?: unknown }).__tcfapi)).toBe(
        'undefined',
      );
      expect(await page.locator('iframe[name="__tcfapiLocator"]').count()).toBe(0);

      await page.locator('#open-cmp-container button[data-type="accept-all"]').click();
      const cookies = await page.context().cookies();
      expect(cookies.some((c) => c.name === 'eupubconsent-v2')).toBe(false);
      expect(cookies.some((c) => c.name === 'cmp-custom-consents')).toBe(true);
      if (m.name === 'basic') {
        // No vendor list is shown in basic mode, so no vendor gets consent.
        const cv = cookies.find((c) => c.name === 'custom-vendors-consent')?.value || '';
        expect(decodeURIComponent(cv)).toMatch(/^g:\|o:\|t:\d+$/);
      }

      await page.reload();
      await page.waitForTimeout(800);
      await expect(page.locator('#open-cmp-container')).toBeHidden();
    });
  });
}

test.describe('mode tcf_gcm — TCF artifacts', () => {
  test('writes a TC String cookie after accept', async ({ page }) => {
    await page.goto('/examples/cmp-e2e.html?domain_id=901');
    await expect(page.locator('#open-cmp-container')).toBeVisible();
    await page.locator('#open-cmp-container button[data-type="accept-all"]').click();
    await expect.poll(async () =>
      (await page.context().cookies()).some((c) => c.name === 'eupubconsent-v2' && c.value.startsWith('C'))
    ).toBe(true);

    // TCF 2.3: a non-empty Disclosed Vendors segment (segment type 1 → starts with "I").
    const tc = (await page.context().cookies()).find((c) => c.name === 'eupubconsent-v2')!.value;
    const disclosed = tc.split('.').find((seg) => seg.startsWith('I'));
    expect(disclosed && disclosed.length).toBeGreaterThan(4);
  });
});

test.describe('environments', () => {
  test('env=test reaches /cmp-config (TEST template)', async ({ page, baseURL }) => {
    const sdk = `${baseURL}/?rest_route=/fast-open-cmp/v1/sdk&domain_id=901&env=test`;
    const body = await (await page.request.get(sdk)).text();
    expect(body).toContain('"env":"test"');

    const configRequest = page.waitForRequest((r) => r.url().includes('cmp-config'));
    await page.goto('/examples/cmp-e2e.html?domain_id=901&env=test');
    expect((await configRequest).url()).toContain('env=test');
  });
});
