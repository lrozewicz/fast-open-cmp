import { test, expect, type Page } from '@playwright/test';
import { queryDb } from './db';

/**
 * Layer-1 behaviour driven by the template's compliance profile and appearance
 * (see e2e/seed.sql): 901 = defaults (certified), 904 = custom profile.
 */
const box = (page: Page) => page.locator('#open-cmp-container');
const l1Buttons = (page: Page) =>
  page.locator('#open-cmp-screen1 .open-cmp-screen1-footer button').evaluateAll((els) =>
    els.map((e) => `${e.getAttribute('data-type')}:${e.className.replace('cmp-btn ', '')}`),
  );

const tcData = (page: Page) =>
  page.evaluate(
    () =>
      new Promise<{ purposeConsents: number; eventStatus: string }>((resolve) => {
        (window as unknown as { __tcfapi: (...a: unknown[]) => void }).__tcfapi(
          'getTCData',
          2,
          (d: { purpose: { consents: Record<string, boolean> }; eventStatus: string }) =>
            resolve({
              purposeConsents: Object.values(d.purpose.consents).filter(Boolean).length,
              eventStatus: d.eventStatus,
            }),
        );
      }),
  );

test.describe('certified profile (default)', () => {
  test('layer 1 offers reject next to accept with equal weight and the TCF summary', async ({
    page,
  }) => {
    await page.goto('/examples/cmp-e2e.html?domain_id=901');
    await expect(box(page)).toBeVisible();
    await expect(box(page)).toHaveAttribute('data-profile', 'certified');
    expect(await l1Buttons(page)).toEqual([
      'settings:cmp-btn--secondary',
      'reject-all:cmp-btn--primary',
      'accept-all:cmp-btn--primary',
    ]);
    await expect(page.locator('#open-cmp-screen1')).toHaveAttribute('role', 'dialog');
    await expect(page.locator('.open-cmp-vendor-count')).toContainText('Partnerów IAB');
    expect(await page.locator('.open-cmp-purposes li').count()).toBeGreaterThan(0);
  });

  test('reject all on layer 1 stores a refusal and closes the box', async ({ page }) => {
    await page.goto('/examples/cmp-e2e.html?domain_id=901');
    await page.locator('#open-cmp-screen1 button[data-type="reject-all"]').click();
    await expect(box(page)).toBeHidden();
    await expect
      .poll(async () =>
        (await page.context().cookies()).some((c) => c.name === 'eupubconsent-v2'),
      )
      .toBe(true);
    expect((await tcData(page)).purposeConsents).toBe(0);
  });

  test('the footer link reopens the settings (layer 2)', async ({ page }) => {
    await page.goto('/examples/cmp-e2e.html?domain_id=901');
    await page.locator('#open-cmp-screen1 button[data-type="accept-all"]').click();
    await expect(box(page)).toBeHidden();
    await page.locator('#cmp-footer-link').click();
    await expect(page.locator('#open-cmp-screen2')).toBeVisible();
  });

  test('layer 2 decision buttons have equal weight', async ({ page }) => {
    await page.goto('/examples/cmp-e2e.html?domain_id=901');
    await page.locator('#open-cmp-screen1 button[data-type="settings"]').click();
    const bg = (type: string) =>
      page
        .locator(`#open-cmp-screen2 .open-cmp-screen2-footer button[data-type="${type}"]`)
        .evaluate((el) => getComputedStyle(el).backgroundColor);
    expect(await bg('accept-all')).toBe(await bg('not-accept'));
  });
});

test.describe('custom profile (publisher decides)', () => {
  test('renders the configured buttons without adding reject', async ({ page }) => {
    await page.goto('/examples/cmp-e2e.html?domain_id=904');
    await expect(box(page)).toBeVisible();
    await expect(box(page)).toHaveAttribute('data-profile', 'custom');
    await expect(box(page)).toHaveClass(/cmp-layout-bar/);
    expect(await l1Buttons(page)).toEqual(['settings:cmp-btn--link', 'accept-all:cmp-btn--primary']);
    await expect(page.locator('#open-cmp-screen1 button[data-type="settings"]')).toHaveText(
      'Więcej opcji',
    );
    await expect(page.locator('.open-cmp-purposes')).toHaveCount(0);
  });

  test('× rejects, then the floating button reopens the settings', async ({ page }) => {
    await page.goto('/examples/cmp-e2e.html?domain_id=904');
    await page.locator('#open-cmp-screen1 button[data-type="close-reject"]').click();
    await expect(box(page)).toBeHidden();
    expect((await tcData(page)).purposeConsents).toBe(0);

    const reopen = page.locator('#open-cmp-reopen');
    await expect(reopen).toBeVisible();
    await reopen.click();
    await expect(page.locator('#open-cmp-screen2')).toBeVisible();
    await expect(reopen).toBeHidden();
  });
});

test.describe('TCF API events', () => {
  test('listeners get cmpuishown, then useractioncomplete with the AC string', async ({ page }) => {
    await page.addInitScript(() => {
      const w = window as unknown as {
        __ev: { status: string; ac: string | null }[];
        __tcfapi: (...a: unknown[]) => void;
      };
      w.__ev = [];
      // A vendor registering before the CMP finished loading (queued by the stub).
      document.addEventListener('DOMContentLoaded', () =>
        w.__tcfapi('addEventListener', 2, (d: { eventStatus: string; addtlConsent?: string }) =>
          w.__ev.push({ status: d.eventStatus, ac: d.addtlConsent || null }),
        ),
      );
    });
    await page.goto('/examples/cmp-e2e.html?domain_id=901');
    await expect(box(page)).toBeVisible();
    // Switching layers must not repeat cmpuishown.
    await page.locator('#open-cmp-screen1 button[data-type="settings"]').click();
    await page.locator('#open-cmp-screen2 button[data-type="back"]').click();
    await page.locator('#open-cmp-screen1 button[data-type="accept-all"]').click();

    await expect
      .poll(() => page.evaluate(() => (window as unknown as { __ev: unknown[] }).__ev.length))
      .toBe(2);
    const events = await page.evaluate(
      () => (window as unknown as { __ev: { status: string; ac: string | null }[] }).__ev,
    );
    expect(events[0].status).toBe('cmpuishown');
    expect(events[1].status).toBe('useractioncomplete');
    expect(events[1].ac).toMatch(/^2~/);
  });
});

test.describe('renewal of stored decisions', () => {
  // gcm_only (902): the decision lives in cmp-custom-consents with its timestamp.
  const decide = (page: Page, daysAgo: number) =>
    page.context().addCookies([
      {
        name: 'cmp-custom-consents',
        value: `c:|l:|t:${Date.now() - daysAgo * 24 * 60 * 60 * 1000}`,
        domain: 'localhost',
        path: '/',
      },
    ]);

  test('a recent decision is kept', async ({ page }) => {
    await decide(page, 30);
    await page.goto('/examples/cmp-e2e.html?domain_id=902');
    await page.waitForTimeout(800);
    await expect(box(page)).toBeHidden();
  });

  test('a decision older than 13 months is collected again', async ({ page }) => {
    await decide(page, 400);
    await page.goto('/examples/cmp-e2e.html?domain_id=902');
    await expect(box(page)).toBeVisible();
  });
});

test.describe('consent log', () => {
  test('a decision is logged with the consent id, which layer 2 then shows', async ({ page }) => {
    await page.goto('/examples/cmp-e2e.html?domain_id=901');
    const logged = page.waitForResponse(
      (r) => r.url().includes('consent') && r.request().method() === 'POST',
    );
    await page.locator('#open-cmp-screen1 button[data-type="reject-all"]').click();
    const response = await logged;
    expect(response.status()).toBe(201);

    const consentId = (await page.context().cookies()).find((c) => c.name === 'cmp-consent-id')
      ?.value;
    expect(consentId).toMatch(/^[0-9a-f-]{36}$/);

    // The record reached the log (sendBeacon bodies are not visible to Playwright).
    const rows = queryDb(
      `SELECT l.domain_id, l.action, l.mode, l.template_id, LEFT(p.tc_string, 1), l.page_url
         FROM wp_cmp_consent_log l JOIN wp_cmp_consent_payloads p ON p.id = l.payload_id
        WHERE l.consent_id = '${consentId}'`,
    );
    expect(rows).toHaveLength(1);
    expect(rows[0]).toEqual(['901', 'reject-all', 'tcf_gcm', '901', 'C', expect.stringContaining('/examples/cmp-e2e.html')]);

    await page.locator('#cmp-footer-link').click();
    await expect(page.locator('.open-cmp-consent-id')).toContainText(consentId!);
  });
});

test.describe('keyboard and screen readers', () => {
  test('rows expand with Enter, Escape steps back, Tab stays in the dialog', async ({ page }) => {
    await page.goto('/examples/cmp-e2e.html?domain_id=901');
    await expect(box(page)).toBeVisible();

    // Focus is in the dialog; Tab never leaves it.
    for (let i = 0; i < 12; i++) await page.keyboard.press('Tab');
    expect(
      await page.evaluate(() => !!document.activeElement?.closest('#open-cmp-container')),
    ).toBe(true);

    await page.locator('#open-cmp-screen1 button[data-type="settings"]').focus();
    await page.keyboard.press('Enter');
    await expect(page.locator('#open-cmp-screen2')).toBeVisible();

    const row = page.locator('#open-cmp-screen2 [role="button"][aria-expanded]').first();
    await row.focus();
    await page.keyboard.press('Enter');
    await expect(row).toHaveAttribute('aria-expanded', 'true');
    expect(await page.locator('#open-cmp-screen2 input[type="checkbox"]:not([aria-label]):not([id])').count()).toBe(0);

    await page.keyboard.press('Escape');
    await expect(page.locator('#open-cmp-screen1')).toBeVisible();
  });
});

test.describe('legitimate interest objection', () => {
  test('checking "object" withdraws legitimate interest for that purpose', async ({ page }) => {
    await page.goto('/examples/cmp-e2e.html?domain_id=901');
    await page.locator('#open-cmp-screen1 button[data-type="settings"]').click();

    const objection = page.locator('input[data-consent-id="purpose-legitimate-interests-2"]').first();
    // Purpose 2 sits inside stack 42: expand the stack, then the purpose row.
    await page
      .locator('#open-cmp-screen2 li:has(input[data-consent-id="purpose-stack42"]) > div > [role="button"]')
      .click();
    await page
      .locator('#open-cmp-screen2 .stack-grouped li:has(input[data-consent-id="purpose-2"]) > div > [role="button"]')
      .click();
    await objection.scrollIntoViewIfNeeded();
    await expect(objection).not.toBeChecked();
    const status = objection.locator('xpath=following-sibling::span[@class="objection-status"]');
    await expect(status).toBeHidden();

    await objection.check();
    await expect(status).toBeVisible();
    await page.locator('#open-cmp-screen2 button[data-type="accept-only-checked"]').click();

    const li = await page.evaluate(
      () =>
        new Promise<boolean | undefined>((resolve) =>
          (window as unknown as { __tcfapi: (...a: unknown[]) => void }).__tcfapi(
            'getTCData',
            2,
            (d: { purpose: { legitimateInterests: Record<string, boolean> } }) =>
              resolve(d.purpose.legitimateInterests['2']),
          ),
        ),
    );
    expect(li).toBe(false);
  });
});

test.describe('consent-gated scripts', () => {
  const runs = (page: Page) =>
    page.evaluate(() => (window as unknown as { __gatedRuns?: number }).__gatedRuns ?? 0);

  test('run after consent, on later visits, and never after a refusal', async ({ page }) => {
    await page.goto('/examples/cmp-e2e.html?domain_id=901');
    await expect(box(page)).toBeVisible();
    expect(await runs(page)).toBe(0);

    await page.locator('#open-cmp-screen1 button[data-type="accept-all"]').click();
    await expect.poll(() => runs(page)).toBe(1);

    await page.reload();
    await expect.poll(() => runs(page)).toBe(1);
  });

  test('stay blocked after reject all', async ({ page }) => {
    await page.goto('/examples/cmp-e2e.html?domain_id=901');
    await page.locator('#open-cmp-screen1 button[data-type="reject-all"]').click();
    await page.reload();
    await page.waitForTimeout(800);
    expect(await runs(page)).toBe(0);
  });
});

test('GTM gets focmp_consent_update with the granted consents', async ({ page }) => {
  await page.goto('/examples/cmp-e2e.html?domain_id=901');
  await page.locator('#open-cmp-screen1 button[data-type="accept-all"]').click();
  const event = await page.evaluate(() =>
    ((window as unknown as { dataLayer: Record<string, unknown>[] }).dataLayer || []).find(
      (e) => e && e.event === 'focmp_consent_update',
    ),
  );
  expect(event).toMatchObject({ event: 'focmp_consent_update', focmp: { action: 'accept_all' } });
  expect((event as { focmp: { purposes: number[] } }).focmp.purposes).toContain(1);
});

test('a decision the server could not store is delivered on the next page view, once', async ({
  page,
}) => {
  let failed = false;
  await page.route(/consent(&|$)/, async (route) => {
    if (route.request().method() === 'POST' && !failed) {
      failed = true;
      return route.fulfill({ status: 503, body: 'busy' });
    }
    return route.continue();
  });

  await page.goto('/examples/cmp-e2e.html?domain_id=901');
  await page.locator('#open-cmp-screen1 button[data-type="accept-all"]').click();
  await expect.poll(() => failed).toBe(true);
  const consentId = (await page.context().cookies()).find((c) => c.name === 'cmp-consent-id')
    ?.value;
  const count = () =>
    Number(queryDb(`SELECT COUNT(*) FROM wp_cmp_consent_log WHERE consent_id = '${consentId}'`)[0][0]);
  expect(count()).toBe(0);

  await page.reload(); // queue flush on the next page view
  await expect.poll(count).toBe(1);
  await page.reload(); // nothing left to send, no duplicate
  await page.waitForTimeout(500);
  expect(count()).toBe(1);
  expect(await page.evaluate(() => localStorage.getItem('focmp-consent-queue'))).toBeNull();
});
