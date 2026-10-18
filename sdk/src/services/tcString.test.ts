import { describe, it, expect, vi, beforeEach } from 'vitest';
import { GVLFactory } from '@iabtechlabtcf/testing';
import { TCString } from '@iabtechlabtcf/core';
import { CMPConfig, ConsentState } from '../models/types';
import TCStringService from './tcString';

// tcString.ts lazily `import('./api')` inside the GVL init. We never configure a
// gvlUrl here, so that branch returns early — but stub the module anyway so the
// dynamic import resolves without pulling in real network/cmpApi side effects.
vi.mock('./api', () => ({
  default: { getCachedVendorList: () => null, vendorListCache: new Map() },
}));

/**
 * Minimal but type-complete config. Purposes 1/2 differ in legInt-allowed, two
 * IAB vendors (8 has no legInt purposes, 10 does), two custom consents.
 */
function makeConfig(): CMPConfig {
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
    texts: {} as never,
    purposes: {
      '1': {
        id: 1,
        name: 'p1',
        description: '',
        legitimateInterestAllowed: false,
        showVendors: true,
      },
      '2': {
        id: 2,
        name: 'p2',
        description: '',
        legitimateInterestAllowed: true,
        showVendors: true,
      },
    },
    specialFeatures: { '1': { id: 1, name: 'sf1', description: '' } },
    specialPurposes: {},
    features: {},
    stacks: {},
    customConsents: [
      { id: 'custom-1', title: 'c1', description: '', legitimateInterestAllowed: true },
      { id: 'custom-2', title: 'c2', description: '', legitimateInterestAllowed: false },
    ],
    vendors: {
      iab: {
        '8': { id: 8, name: 'v8', purposes: [1], legIntPurposes: [], urls: [] },
        '10': { id: 10, name: 'v10', purposes: [1], legIntPurposes: [2], urls: [] },
      },
      custom: { google: {}, other: {} },
    },
  } as unknown as CMPConfig;
}

// Let the constructor's async GVL/TCModel init settle before asserting, so a
// late resetConsents() can't race with the action under test.
const flush = () => new Promise((resolve) => setTimeout(resolve, 0));

describe('TCStringService — consent vector logic', () => {
  let svc: TCStringService;

  beforeEach(async () => {
    svc = new TCStringService(makeConfig());
    await flush();
  });

  it('acceptAll() sets every purpose, special feature, IAB vendor and custom consent', () => {
    svc.acceptAll();
    const s = svc.getCurrentConsentState();
    expect(s.purposeConsents).toEqual({ '1': true, '2': true });
    expect(s.specialFeatureOptins).toEqual({ '1': true });
    expect(s.vendorConsents).toEqual({ '8': true, '10': true });
    expect(s.customPurposeConsents).toEqual({ 'custom-1': true, 'custom-2': true });
  });

  it('rejectAll() clears every consent', () => {
    svc.acceptAll();
    svc.rejectAll();
    const s = svc.getCurrentConsentState();
    expect(s.purposeConsents).toEqual({ '1': false, '2': false });
    expect(s.specialFeatureOptins).toEqual({ '1': false });
    expect(s.vendorConsents).toEqual({ '8': false, '10': false });
    expect(s.customPurposeConsents).toEqual({ 'custom-1': false, 'custom-2': false });
  });

  it('resetConsents() clears consents but keeps legitimate interest where allowed', () => {
    svc.acceptAll();
    svc.resetConsents();
    const s = svc.getCurrentConsentState();
    // Consents off...
    expect(s.purposeConsents).toEqual({ '1': false, '2': false });
    expect(s.vendorConsents).toEqual({ '8': false, '10': false });
    // ...but legitimate interest stays on for purpose 2 (allowed) and vendor 10
    // (declares a legInt purpose), off for purpose 1 / vendor 8.
    expect(s.purposeLegitimateInterests).toEqual({ '1': false, '2': true });
    expect(s.vendorLegitimateInterests).toEqual({ '8': false, '10': true });
  });

  it('setConsentsFromState() round-trips an explicit partial state', () => {
    const desired: ConsentState = {
      purposeConsents: { '1': true, '2': false },
      purposeLegitimateInterests: { '1': false, '2': true },
      specialFeatureOptins: { '1': true },
      vendorConsents: { '8': true, '10': false },
      vendorLegitimateInterests: {},
      customVendorConsents: {},
      customPurposeConsents: { 'custom-1': true, 'custom-2': false },
      customPurposeLegitimateInterests: {},
    };
    svc.setConsentsFromState(desired);
    const s = svc.getCurrentConsentState();
    expect(s.purposeConsents).toEqual({ '1': true, '2': false });
    expect(s.specialFeatureOptins).toEqual({ '1': true });
    expect(s.vendorConsents).toEqual({ '8': true, '10': false });
    expect(s.customPurposeConsents).toEqual({ 'custom-1': true, 'custom-2': false });
  });
});

describe('TCStringService — TC string encode/decode (GVL fixture)', () => {
  it('encodes a consented model and decodes it back', async () => {
    const gvl = GVLFactory.getLatest();
    await gvl.readyPromise;

    const svc = new TCStringService(makeConfig());
    await flush();

    // Inject the fixture GVL (the network init path is skipped without a gvlUrl).
    (svc as unknown as { gvl: unknown }).gvl = gvl;
    svc.getTCModel().gvl = gvl;

    svc.acceptAll();
    const tcString = svc.generateTCString();

    expect(typeof tcString).toBe('string');
    expect(tcString.length).toBeGreaterThan(0);

    // The library can read what we produced...
    const decoded = TCString.decode(tcString);
    expect(decoded.cmpId).toBe(28);
    expect(decoded.purposeConsents.has(1)).toBe(true);
    expect(decoded.purposeConsents.has(2)).toBe(true);

    // ...and so can our own decode path, which then reflects state via config.
    // TCF 2.3: the Disclosed Vendors segment lists the vendors shown to the user.
    expect(decoded.vendorsDisclosed.has(8)).toBe(true);
    expect(decoded.vendorsDisclosed.has(10)).toBe(true);
    expect(decoded.vendorsDisclosed.size).toBe(2);

    expect(svc.decodeTCString(tcString)).toBe(true);
    const state = svc.getCurrentConsentState();
    expect(state.purposeConsents['1']).toBe(true);
    expect(state.vendorConsents['8']).toBe(true);
  });
});

describe('TCStringService — stable custom consent ids', () => {
  it('encodes publisher custom purposes with gaps in their ids (custom-1, custom-4)', async () => {
    const gvl = GVLFactory.getLatest();
    await gvl.readyPromise;

    const config = makeConfig();
    config.customConsents = [
      { id: 'custom-1', title: 'c1', description: '', legitimateInterestAllowed: false },
      { id: 'custom-4', title: 'c4', description: '', legitimateInterestAllowed: false },
    ];
    const svc = new TCStringService(config);
    await flush();
    (svc as unknown as { gvl: unknown }).gvl = gvl;
    svc.getTCModel().gvl = gvl;

    svc.acceptAll();
    expect(svc.getTCModel().numCustomPurposes).toBe(4);

    const decoded = TCString.decode(svc.generateTCString());
    expect(decoded.publisherCustomConsents.has(1)).toBe(true);
    expect(decoded.publisherCustomConsents.has(4)).toBe(true);
    expect(decoded.publisherCustomConsents.has(2)).toBe(false);
  });
});

describe('TCStringService — decoded consent survives the async model init', () => {
  it('keeps created date and consents of a stored TC String', async () => {
    const gvl = GVLFactory.getLatest();
    await gvl.readyPromise;

    // A string created months ago with purpose 1 + vendor 8 consented.
    const origin = new TCStringService(makeConfig());
    await flush();
    (origin as unknown as { gvl: unknown }).gvl = gvl;
    origin.getTCModel().gvl = gvl;
    origin.setConsentsFromState({
      purposeConsents: { '1': true, '2': false },
      purposeLegitimateInterests: {},
      specialFeatureOptins: {},
      vendorConsents: { '8': true, '10': false },
      vendorLegitimateInterests: {},
      customVendorConsents: {},
      customPurposeConsents: {},
      customPurposeLegitimateInterests: {},
    });
    vi.useFakeTimers({ toFake: ['Date'] });
    vi.setSystemTime(new Date('2026-01-15T10:30:00Z'));
    const stored = origin.generateTCString();
    vi.useRealTimers();

    // Page load: the service is constructed and the cookie decoded right away,
    // while the GVL/model init finishes asynchronously afterwards.
    const svc = new TCStringService(makeConfig());
    expect(svc.decodeTCString(stored)).toBe(true);
    await flush();

    const model = svc.getTCModel();
    expect(model.created?.toISOString().slice(0, 10)).toBe('2026-01-15');
    expect(model.purposeConsents.has(1)).toBe(true);
    expect(model.vendorConsents.has(8)).toBe(true);
  });
});

describe('TCStringService — TC Strings written elsewhere keep decoding', () => {
  // Real string from another CMP (OneTrust on a Polish news site, policy 5, Oct 2026):
  // a regression fixture for library upgrades (see docs/tcf-upgrade.md, step 6).
  const FOREIGN =
    'CQsE7wAQsE7wAAcABBPLC0FgAAAAAEPgAAYgAAAXAABMNDogBLIgUCBQEAIEACgjCACgQBAAAkBRAQAkAAByBgAOsIgAAAQAAAQAgABBgACAAACABCIAIACAQAgQCBQABgAQBAQAMCAAGAChAAgABANAxCAggEAwASIwoDBAhAAQCAksqAEgCBAGAFIscAAgQEQQAACAABQAAAB4SABIAAAAAABUQTQAAAAACQYAIAAQEwBBQGQJAVgScBkaYAgeYJEEOgiAJAhIyDIBJEAAYURQAAByAkIAgQACAkUcABASKSAAgJFKAAQEigAA.YAAACHwAAAAA.ILpNR3G7_bWlv-bb2qbpkeYxf57hr5sQxBgbIsm4FyIPG7JwGH2EQJAyYtiAKmRIAsyDBIQNlHAhUQUAhCIAVLzDsaEyEoDtKJ-BkiHMRYwJQCEhmm4hjWQCYYurv50dwmR-E6Nr6WdxSwxgmHQKseuSFUJCMIQWtAfioZBCQ-ZAE8agQEwl47IZoEiUcSRN8oEkI4jcwwvP6chuwJeTyffyfv_fqmfS7T1vc__32t8iUGsLLqasu5xyMAAE';

  it('decodes a third-party TC String with the current library', () => {
    const decoded = TCString.decode(FOREIGN);
    expect(Number(decoded.policyVersion)).toBe(5);
    expect(decoded.cmpId).toBe(28);
    expect(decoded.vendorsDisclosed.size).toBeGreaterThan(0);

    const svc = new TCStringService(makeConfig());
    expect(svc.decodeTCString(FOREIGN)).toBe(true);
  });
});

describe('TCStringService — day-level timestamps (TC String spec)', () => {
  it('writes Created = LastUpdated = midnight UTC, identical strings within a day', async () => {
    const gvl = GVLFactory.getLatest();
    await gvl.readyPromise;
    const encodeAt = async (iso: string) => {
      vi.useFakeTimers({ toFake: ['Date'] });
      vi.setSystemTime(new Date(iso));
      const svc = new TCStringService(makeConfig());
      await flush();
      (svc as unknown as { gvl: unknown }).gvl = gvl;
      svc.getTCModel().gvl = gvl;
      svc.acceptAll();
      const tc = svc.generateTCString();
      vi.useRealTimers();
      return tc;
    };
    const morning = await encodeAt('2026-10-14T06:10:00Z');
    const evening = await encodeAt('2026-10-14T21:59:59Z');
    const nextDay = await encodeAt('2026-10-15T00:00:01Z');

    expect(morning).toBe(evening);
    expect(morning).not.toBe(nextDay);
    const decoded = TCString.decode(evening);
    expect(decoded.created?.toISOString()).toBe('2026-10-14T00:00:00.000Z');
    expect(decoded.lastUpdated?.toISOString()).toBe('2026-10-14T00:00:00.000Z');
  });
});
