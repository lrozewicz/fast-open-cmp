import { ConsentState } from '../models/types';

/**
 * Google Tag Manager integration: consent events in window.dataLayer, so tags
 * and triggers can depend on the visitor's choices (e.g. a trigger
 * "focmp_consent_update" with condition "focmp.customConsents contains custom-2").
 *
 *   focmp_consent_ready   on page load when a decision is already stored
 *   focmp_consent_update  after every decision
 */
export interface ConsentSummary {
  action?: string;
  customConsents: string[];
  purposes: number[];
  specialFeatures: number[];
  vendors: number[];
  googleVendors: number[];
  otherVendors: number[];
}

const granted = (map: Record<string, boolean>): string[] =>
  Object.keys(map || {}).filter((k) => map[k] === true);

export const summarizeConsent = (state: ConsentState, action?: string): ConsentSummary => {
  const vendorsOf = (prefix: string) =>
    granted(state.customVendorConsents)
      .filter((k) => k.startsWith(prefix))
      .map((k) => Number(k.slice(prefix.length)));
  return {
    ...(action ? { action } : {}),
    customConsents: granted(state.customPurposeConsents),
    purposes: granted(state.purposeConsents).map(Number),
    specialFeatures: granted(state.specialFeatureOptins).map(Number),
    vendors: granted(state.vendorConsents).map(Number),
    googleVendors: vendorsOf('google:'),
    otherVendors: vendorsOf('other:'),
  };
};

export const pushConsentEvent = (
  event: 'focmp_consent_ready' | 'focmp_consent_update',
  summary: ConsentSummary,
): void => {
  window.dataLayer = window.dataLayer || [];
  window.dataLayer.push({ event, focmp: summary });
};
