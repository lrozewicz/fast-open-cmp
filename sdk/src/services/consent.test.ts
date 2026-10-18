import { describe, it, expect, vi, beforeEach } from 'vitest';
import { CMPConfig } from '../models/types';

// In-memory cookie jar so we can drive getCookie and observe set/delete without
// touching jsdom's document.cookie (and its path/expiry quirks).
const jar = new Map<string, string>();
const deleted: string[] = [];
vi.mock('./cookie', () => ({
  default: {
    getCookie: (name: string) => (jar.has(name) ? jar.get(name)! : null),
    setCookie: (name: string, value: string) => {
      jar.set(name, value);
    },
    deleteCookie: (name: string) => {
      jar.delete(name);
      deleted.push(name);
    },
  },
}));

// Keep the custom-vendor + TC-string init paths free of network/cmpApi.
vi.mock('./cmpApi', () => ({ default: { updateAdditionalConsent: vi.fn() } }));
vi.mock('./api', () => ({
  default: { getCachedVendorList: () => null, vendorListCache: new Map() },
}));

import { ConsentService, RECONSENT_ID_COOKIE_NAME } from './consent';
import { CUSTOM_VENDORS_COOKIE_NAME } from './customVendors';

function makeConfig(reconsentID?: number): CMPConfig {
  return {
    language: 'pl',
    cmpId: 28,
    cmpVersion: 1,
    tcfPolicyVersion: 5,
    vendorListVersion: 100,
    publisherCountryCode: 'PL',
    isServiceSpecific: true,
    useNonStandardStacks: false,
    consentScreen: 1,
    cookieName: 'eupubconsent-v2',
    cookieExpirationDays: 180,
    reconsentID,
    texts: {} as never,
    purposes: {
      '1': {
        id: 1,
        name: 'p1',
        description: '',
        legitimateInterestAllowed: false,
        showVendors: true,
      },
    },
    specialFeatures: {},
    specialPurposes: {},
    features: {},
    stacks: {},
    customConsents: [],
    vendors: { iab: {}, custom: { google: {}, other: {} } },
  } as unknown as CMPConfig;
}

describe('ConsentService — reconsent & cookie logic', () => {
  let svc: ConsentService;

  beforeEach(() => {
    jar.clear();
    deleted.length = 0;
    svc = new ConsentService();
  });

  it('hasUserConsent reflects presence of the consent cookie', () => {
    svc.init(makeConfig());
    expect(svc.hasUserConsent()).toBe(false);

    jar.set('eupubconsent-v2', 'SOME_TC_STRING');
    expect(svc.hasUserConsent()).toBe(true);
  });

  it('isReconsentRequired is false when no reconsentID is configured', () => {
    svc.init(makeConfig(undefined));
    jar.set('eupubconsent-v2', 'TC'); // even with consent present
    expect(svc.isReconsentRequired()).toBe(false);
  });

  it('requires reconsent when user has consent but no stored reconsent id', () => {
    svc.init(makeConfig(100));
    jar.set('eupubconsent-v2', 'TC');
    // no RECONSENT_ID_COOKIE_NAME in the jar
    expect(svc.isReconsentRequired()).toBe(true);
  });

  it('requires reconsent when the stored reconsent id is older than the configured one', () => {
    svc.init(makeConfig(100));
    jar.set(RECONSENT_ID_COOKIE_NAME, '50');
    expect(svc.isReconsentRequired()).toBe(true);
  });

  it('does NOT require reconsent when the stored id is equal or newer', () => {
    svc.init(makeConfig(100));

    jar.set(RECONSENT_ID_COOKIE_NAME, '100');
    expect(svc.isReconsentRequired()).toBe(false);

    jar.set(RECONSENT_ID_COOKIE_NAME, '150');
    expect(svc.isReconsentRequired()).toBe(false);
  });

  it('does NOT require reconsent when there is neither consent nor a stored id', () => {
    svc.init(makeConfig(100));
    expect(svc.isReconsentRequired()).toBe(false);
  });

  it('clearConsents deletes the TC, custom-vendor and reconsent cookies', () => {
    svc.init(makeConfig(100));
    jar.set('eupubconsent-v2', 'TC');
    jar.set(CUSTOM_VENDORS_COOKIE_NAME, 'g:1|o:|t:1');
    jar.set(RECONSENT_ID_COOKIE_NAME, '100');

    svc.clearConsents();

    expect(deleted).toContain('eupubconsent-v2');
    expect(deleted).toContain(CUSTOM_VENDORS_COOKIE_NAME);
    expect(deleted).toContain(RECONSENT_ID_COOKIE_NAME);
    expect(jar.size).toBe(0);
  });

  it('checkAndClearConsentsIfNeeded clears only when reconsent is required', () => {
    svc.init(makeConfig(100));
    jar.set('eupubconsent-v2', 'TC'); // consent but no stored id → reconsent required
    expect(svc.checkAndClearConsentsIfNeeded()).toBe(true);
    expect(jar.has('eupubconsent-v2')).toBe(false);
  });

  it('getConsentState returns an independent deep copy', () => {
    svc.init(makeConfig());
    const a = svc.getConsentState();
    a.purposeConsents['1'] = true; // mutate the returned copy
    const b = svc.getConsentState();
    expect(b.purposeConsents['1']).toBe(false); // internal state untouched
  });
});
