import { describe, it, expect } from 'vitest';
import { computeGcmConsentParams, allDenied } from './googleConsentMode';
import { ConsentState } from '../models/types';

/** Build a ConsentState with only the fields the GCM mapping reads; rest empty. */
function makeState(partial: Partial<ConsentState> = {}): ConsentState {
  return {
    purposeConsents: {},
    purposeLegitimateInterests: {},
    specialFeatureOptins: {},
    vendorConsents: {},
    vendorLegitimateInterests: {},
    customVendorConsents: {},
    customPurposeConsents: {},
    customPurposeLegitimateInterests: {},
    ...partial,
  };
}

// Realistic GCM v2 mapping: signal -> IAB purpose IDs that must all be granted.
const MAPPING = {
  ad_storage: [1],
  analytics_storage: [1, 8],
  ad_user_data: [1, 7],
  ad_personalization: [3, 4],
};

describe('computeGcmConsentParams', () => {
  describe('tcf_gcm mode (maps from IAB purposeConsents)', () => {
    it('grants a signal only when every mapped purpose is granted', () => {
      const state = makeState({ purposeConsents: { '1': true, '8': true } });
      const params = computeGcmConsentParams('tcf_gcm', MAPPING, state);

      expect(params.ad_storage).toBe('granted'); // [1] all granted
      expect(params.analytics_storage).toBe('granted'); // [1,8] all granted
      expect(params.ad_user_data).toBe('denied'); // [1,7] -> 7 missing
      expect(params.ad_personalization).toBe('denied'); // [3,4] missing
    });

    it('denies a signal if any mapped purpose is missing or false', () => {
      const state = makeState({ purposeConsents: { '1': true, '7': false } });
      const params = computeGcmConsentParams('tcf_gcm', MAPPING, state);
      expect(params.ad_storage).toBe('granted');
      expect(params.ad_user_data).toBe('denied'); // 7 explicitly false
    });

    it('returns all denied when no purposes are granted', () => {
      const params = computeGcmConsentParams('tcf_gcm', MAPPING, makeState());
      expect(params).toEqual(allDenied());
    });

    it('ignores custom consents in tcf_gcm mode', () => {
      const state = makeState({ customPurposeConsents: { 'custom-1': true } });
      const params = computeGcmConsentParams('tcf_gcm', MAPPING, state);
      expect(params.ad_storage).toBe('denied');
    });
  });

  describe('gcm_only / basic modes (map from custom-{id} consents)', () => {
    it('grants signals from custom consents when their mapped ids are granted', () => {
      const state = makeState({
        customPurposeConsents: { 'custom-1': true, 'custom-8': true },
      });
      const params = computeGcmConsentParams('gcm_only', MAPPING, state);
      expect(params.ad_storage).toBe('granted');
      expect(params.analytics_storage).toBe('granted');
      expect(params.ad_user_data).toBe('denied'); // needs custom-7
    });

    it('basic mode behaves like gcm_only', () => {
      const state = makeState({ customPurposeConsents: { 'custom-1': true } });
      const params = computeGcmConsentParams('basic', MAPPING, state);
      expect(params.ad_storage).toBe('granted');
    });

    it('returns all denied when no custom consent is granted at all', () => {
      // Quirk: the per-signal loop is skipped entirely unless ≥1 custom consent is true.
      const state = makeState({ customPurposeConsents: { 'custom-1': false } });
      const params = computeGcmConsentParams('gcm_only', MAPPING, state);
      expect(params).toEqual(allDenied());
    });

    it('ignores IAB purposeConsents in gcm_only mode', () => {
      const state = makeState({ purposeConsents: { '1': true } });
      const params = computeGcmConsentParams('gcm_only', MAPPING, state);
      expect(params).toEqual(allDenied());
    });
  });

  describe('mapping edge cases', () => {
    it('leaves signals with an empty mapping at denied', () => {
      const state = makeState({ purposeConsents: { '1': true } });
      const params = computeGcmConsentParams('tcf_gcm', { ad_storage: [] }, state);
      expect(params).toEqual(allDenied());
    });

    it('handles an undefined mapping', () => {
      const state = makeState({ purposeConsents: { '1': true } });
      const params = computeGcmConsentParams('tcf_gcm', undefined, state);
      expect(params).toEqual(allDenied());
    });

    it('never emits wait_for_update (that is only for the default call)', () => {
      const params = computeGcmConsentParams('tcf_gcm', MAPPING, makeState());
      expect(params.wait_for_update).toBeUndefined();
    });
  });
});
