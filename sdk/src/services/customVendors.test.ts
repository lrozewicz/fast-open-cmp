import { describe, it, expect, vi, beforeEach } from 'vitest';
import { CMPConfig } from '../models/types';

// Mock the cookie layer: we control what getCookie returns and capture setCookie.
const cookieStore = {
  value: null as string | null,
  lastSet: null as { name: string; value: string; days: number } | null,
};
vi.mock('./cookie', () => ({
  default: {
    getCookie: () => cookieStore.value,
    setCookie: (name: string, value: string, days: number) => {
      cookieStore.lastSet = { name, value, days };
    },
  },
}));

// Keep cmpApi out of these unit tests (it is not imported by customVendors any more,
// the mock just guards against side effects if that changes).
vi.mock('./cmpApi', () => ({ default: { updateAdditionalConsent: vi.fn() } }));

import customVendorsService, { CUSTOM_VENDORS_COOKIE_NAME } from './customVendors';

/** Minimal config — the service only reads vendors.custom.* and cookieExpirationDays. */
function makeConfig(google: string[], other: string[]): CMPConfig {
  const toMap = (ids: string[]) => Object.fromEntries(ids.map((id) => [id, {} as never]));
  return {
    cookieExpirationDays: 180,
    vendors: { iab: {}, custom: { google: toMap(google), other: toMap(other) } },
  } as unknown as CMPConfig;
}

describe('CustomVendorsService — compact cookie format g:..|o:..|t:..', () => {
  beforeEach(() => {
    cookieStore.value = null;
    cookieStore.lastSet = null;
  });

  it('defaults every known vendor to false when no cookie is present', () => {
    customVendorsService.init(makeConfig(['43', '46'], ['1']));
    const consents = customVendorsService.getConsents();
    expect(consents.google).toEqual({ '43': false, '46': false });
    expect(consents.other).toEqual({ '1': false });
  });

  it('parses a compact cookie into granted consents', () => {
    cookieStore.value = 'g:43.55|o:1.2|t:1769720229228';
    customVendorsService.init(makeConfig(['43', '46', '55'], ['1', '2', '3']));
    const consents = customVendorsService.getConsents();

    expect(consents.google['43']).toBe(true);
    expect(consents.google['55']).toBe(true);
    expect(consents.google['46']).toBe(false); // not in cookie
    expect(consents.other['1']).toBe(true);
    expect(consents.other['2']).toBe(true);
    expect(consents.other['3']).toBe(false);
    expect(consents.lastUpdated).toBe(1769720229228);
  });

  it('handles empty sections (no consents granted)', () => {
    cookieStore.value = 'g:|o:|t:123';
    customVendorsService.init(makeConfig(['43'], ['1']));
    const consents = customVendorsService.getConsents();
    expect(consents.google['43']).toBe(false);
    expect(consents.other['1']).toBe(false);
    expect(consents.lastUpdated).toBe(123);
  });

  it('saveConsents writes only granted ids in compact format', () => {
    customVendorsService.init(makeConfig(['43', '46', '55'], ['1', '2']));
    customVendorsService.setConsents(
      { '43': true, '46': false, '55': true },
      { '1': false, '2': true },
    );
    customVendorsService.saveConsents();

    expect(cookieStore.lastSet?.name).toBe(CUSTOM_VENDORS_COOKIE_NAME);
    expect(cookieStore.lastSet?.days).toBe(180);
    const [g, o, t] = cookieStore.lastSet!.value.split('|');
    expect(g).toBe('g:43.55');
    expect(o).toBe('o:2');
    expect(t).toMatch(/^t:\d+$/);
  });

  it('round-trips: saved cookie re-parses to the same granted set', () => {
    customVendorsService.init(makeConfig(['43', '46'], ['1', '2']));
    customVendorsService.setConsents({ '43': true, '46': false }, { '2': true });
    customVendorsService.saveConsents();

    // Feed the written cookie back into a fresh init.
    cookieStore.value = cookieStore.lastSet!.value;
    customVendorsService.init(makeConfig(['43', '46'], ['1', '2']));
    const consents = customVendorsService.getConsents();
    expect(consents.google).toEqual({ '43': true, '46': false });
    expect(consents.other).toEqual({ '1': false, '2': true });
  });
});
