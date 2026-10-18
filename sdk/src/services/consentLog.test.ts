import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import {
  toLoggedAction,
  buildConsentRecord,
  sendConsentRecord,
  flushConsentQueue,
} from './consentLog';
import { CMPConfig, ConsentAction } from '../models/types';

describe('consent log', () => {
  it('maps actions, with the close button logged separately', () => {
    expect(toLoggedAction(ConsentAction.ACCEPT_ALL, 'accept-all')).toBe('accept-all');
    expect(toLoggedAction(ConsentAction.REJECT_ALL, 'close-reject')).toBe('close-reject');
    expect(toLoggedAction(ConsentAction.REJECT_ALL)).toBe('reject-all');
    expect(toLoggedAction(ConsentAction.SAVE_PREFERENCES)).toBe('save');
    expect(toLoggedAction(undefined)).toBeNull();
  });

  it('builds a record without the query string of the page', () => {
    window.history.pushState({}, '', '/artykul?email=jan@example.com#x');
    const config = {
      mode: 'tcf_gcm',
      tcfPolicyVersion: 5,
      vendorListVersion: 120,
      cmpVersion: 120,
      reconsentID: 0,
    } as unknown as CMPConfig;
    const record = buildConsentRecord(
      config,
      { baseUrl: 'https://cmp.test/wp-json/fast-open-cmp/v1', domainRef: '7', env: 'prod' },
      'accept-all',
      '0b0d5b6e-3e8a-4f7b-9c1d-2a3b4c5d6e7f',
      { tcString: 'CQ.IA', acString: '2~1~' },
    );
    expect(record).toMatchObject({
      consentId: '0b0d5b6e-3e8a-4f7b-9c1d-2a3b4c5d6e7f',
      domainId: 7,
      action: 'accept-all',
      mode: 'tcf_gcm',
      policyVersion: 5,
      tcString: 'CQ.IA',
      acString: '2~1~',
    });
    expect(record.pageUrl).toBe(`${window.location.origin}/artykul`);
    expect(record.reconsentId).toBeUndefined();
    expect(record.recordId).toMatch(/^[0-9a-f-]{36}$/);
  });

  it('sends the public key instead of an id for key snippets', () => {
    const record = buildConsentRecord(
      { mode: 'basic' } as unknown as CMPConfig,
      { baseUrl: 'https://cmp.test/api', domainRef: 'k3x9p2m7q4w8z6r5t2ya', env: 'prod' },
      'reject-all',
      '0b0d5b6e-3e8a-4f7b-9c1d-2a3b4c5d6e7f',
      {},
    );
    expect(record.domainKey).toBe('k3x9p2m7q4w8z6r5t2ya');
    expect(record).not.toHaveProperty('domainId');
  });
});

describe('consent log delivery queue', () => {
  const ctx = { baseUrl: 'https://cmp.test/api', domainRef: '1', env: 'prod' as const };
  const record = (id: string) => ({ consentId: 'c', recordId: id });
  const queued = () => JSON.parse(localStorage.getItem('focmp-consent-queue') || '[]');

  beforeEach(() => localStorage.clear());
  afterEach(() => vi.unstubAllGlobals());

  const respond = (...statuses: (number | 'offline')[]) => {
    const calls: string[] = [];
    vi.stubGlobal(
      'fetch',
      vi.fn((url: string, init: { body: string }) => {
        calls.push(JSON.parse(init.body).recordId);
        const status = statuses.shift() ?? 201;
        return status === 'offline'
          ? Promise.reject(new TypeError('offline'))
          : Promise.resolve({ ok: status < 300, status });
      }),
    );
    return calls;
  };

  it('clears a delivered record', async () => {
    respond(201);
    await sendConsentRecord(ctx, record('r1'));
    expect(queued()).toEqual([]);
  });

  it('keeps it after 503 and re-sends the same record later', async () => {
    const calls = respond(503, 200);
    await sendConsentRecord(ctx, record('r2'));
    expect(queued().map((q: { recordId: string }) => q.recordId)).toEqual(['r2']);
    await flushConsentQueue();
    expect(calls).toEqual(['r2', 'r2']);
    expect(queued()).toEqual([]);
  });

  it('drops records the server rejects for good, keeps them when offline', async () => {
    respond(400);
    await sendConsentRecord(ctx, record('bad'));
    expect(queued()).toEqual([]);
    respond('offline');
    await sendConsentRecord(ctx, record('later'));
    expect(queued().map((q: { recordId: string }) => q.recordId)).toEqual(['later']);
  });
});
