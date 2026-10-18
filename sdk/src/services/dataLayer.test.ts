import { describe, it, expect } from 'vitest';
import { summarizeConsent, pushConsentEvent } from './dataLayer';
import { ConsentState } from '../models/types';

describe('dataLayer consent events', () => {
  it('summarises granted consents only', () => {
    const state: ConsentState = {
      purposeConsents: { '1': true, '2': false },
      purposeLegitimateInterests: {},
      specialFeatureOptins: { '1': true },
      vendorConsents: { '755': true, '8': false },
      vendorLegitimateInterests: {},
      customVendorConsents: { 'google:55': true, 'other:3': true, 'google:9': false },
      customPurposeConsents: { 'custom-1': false, 'custom-2': true },
      customPurposeLegitimateInterests: {},
    };
    expect(summarizeConsent(state, 'save_preferences')).toEqual({
      action: 'save_preferences',
      customConsents: ['custom-2'],
      purposes: [1],
      specialFeatures: [1],
      vendors: [755],
      googleVendors: [55],
      otherVendors: [3],
    });
  });

  it('pushes a GTM event', () => {
    window.dataLayer = [];
    pushConsentEvent(
      'focmp_consent_update',
      summarizeConsent({
        purposeConsents: {},
        purposeLegitimateInterests: {},
        specialFeatureOptins: {},
        vendorConsents: {},
        vendorLegitimateInterests: {},
        customVendorConsents: {},
        customPurposeConsents: {},
        customPurposeLegitimateInterests: {},
      }),
    );
    expect(window.dataLayer[0]).toMatchObject({ event: 'focmp_consent_update' });
  });
});
