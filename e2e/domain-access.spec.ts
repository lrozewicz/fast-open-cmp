import { test, expect } from '@playwright/test';
import { queryDb } from './db';

/**
 * Public domain keys, the origin check and compressed/revalidated JSON. Domain
 * 901 = e2e-tcf.local with the fixed key from e2e/seed.sql.
 */
const KEY = 'e2etcfkey00000000901';
const API = '/index.php?rest_route=/fast-open-cmp/v1';
const uuid = () => crypto.randomUUID();

test('the consent box works with a key snippet and logs to the right domain', async ({ page }) => {
  await page.goto(`/examples/cmp-e2e.html?key=${KEY}`);
  const sdk = await page.evaluate(() =>
    [...document.scripts].map((s) => s.src).find((src) => src.includes('/sdk')),
  );
  expect(sdk).toContain(`key=${KEY}`);
  await page.locator('#open-cmp-screen1 button[data-type="accept-all"]').click();
  await page.waitForTimeout(800);
  const consentId = (await page.context().cookies()).find((c) => c.name === 'cmp-consent-id')!.value;
  expect(queryDb(`SELECT domain_id FROM wp_cmp_consent_log WHERE consent_id='${consentId}'`)).toEqual([
    ['901'],
  ]);
});

test('the SDK called with a key never reveals the numeric domain id', async ({ request }) => {
  const body = await (await request.get(`${API}/sdk&key=${KEY}`)).text();
  expect(body).toContain(`OpenCMP.init('e2e-tcf.local', '${KEY}'`);
  expect(body).not.toContain(", '901'");
});

test('other sites may not use the domain', async ({ request }) => {
  const evil = { Referer: 'https://evil.example/', Origin: 'https://evil.example' };
  const own = { Referer: 'https://news.e2e-tcf.local/a', Origin: 'https://www.e2e-tcf.local' };

  expect((await request.get(`${API}/sdk&key=${KEY}`, { headers: own })).status()).toBe(200);
  const refused = await request.get(`${API}/sdk&key=${KEY}`, { headers: { Referer: evil.Referer } });
  expect(refused.status()).toBe(403);
  expect(refused.headers()['content-type']).toContain('javascript');
  expect(refused.headers()['cache-control']).toBe('no-store');

  expect((await request.get(`${API}/cmp-config&key=${KEY}`, { headers: own })).status()).toBe(200);
  expect((await request.get(`${API}/cmp-config&key=${KEY}`, { headers: evil })).status()).toBe(403);
  // look-alike hosts are not subdomains
  const lookAlike = { Origin: 'https://e2e-tcf.local.evil.example' };
  expect((await request.get(`${API}/cmp-config&key=${KEY}`, { headers: lookAlike })).status()).toBe(403);

  const record = (consentId: string) => ({
    consentId,
    recordId: uuid(),
    domainKey: KEY,
    action: 'accept-all',
    mode: 'tcf_gcm',
    env: 'test',
  });
  const foreign = uuid();
  const res = await request.post(`${API}/consent`, {
    headers: { ...evil, 'Content-Type': 'text/plain' },
    data: JSON.stringify(record(foreign)),
  });
  expect(res.status()).toBe(403);
  expect(queryDb(`SELECT COUNT(*) FROM wp_cmp_consent_log WHERE consent_id='${foreign}'`)).toEqual([['0']]);

  const mine = uuid();
  const ok = await request.post(`${API}/consent`, {
    headers: { ...own, 'Content-Type': 'text/plain' },
    data: JSON.stringify(record(mine)),
  });
  expect(ok.status()).toBe(201);
});

test('unknown keys are refused', async ({ request }) => {
  expect((await request.get(`${API}/sdk&key=zzzzzzzzzzzzzzzzzzzz`)).status()).toBe(404);
  expect((await request.get(`${API}/cmp-config&key=zzzzzzzzzzzzzzzzzzzz`)).status()).toBe(404);
});

test('JSON responses are gzip-compressed and revalidate with an ETag', async ({ request }) => {
  const gzip = await request.get(`${API}/vendor-list&lang=pl`, {
    headers: { 'Accept-Encoding': 'gzip' },
  });
  expect(gzip.status()).toBe(200);
  expect(gzip.headers()['content-encoding']).toBe('gzip');
  expect(gzip.headers()['vary']).toContain('Accept-Encoding');
  const json = await gzip.json(); // Playwright decodes the body
  expect(Object.keys(json.vendors).length).toBeGreaterThan(0);
  const compressed = Number(gzip.headers()['content-length']);
  expect(compressed).toBeLessThan(JSON.stringify(json).length / 4);

  const etag = gzip.headers()['etag'];
  expect(etag).toMatch(/^"[0-9a-f]+"$/);
  const revalidated = await request.get(`${API}/vendor-list&lang=pl`, {
    headers: { 'If-None-Match': etag },
  });
  expect(revalidated.status()).toBe(304);

  const config = await request.get(`${API}/cmp-config&key=${KEY}`, {
    headers: { 'Accept-Encoding': 'gzip' },
  });
  expect(config.headers()['content-encoding']).toBe('gzip');
  expect(config.headers()['vary']).toContain('Origin');
});
