import { CMPConfig, CMPEventType, ConsentState } from '../models/types';
import consentService from './consent';

/**
 * Google Consent Mode v2 service.
 *
 * Wysyła sygnały `gtag('consent', 'default'|'update', ...)` na podstawie zgód użytkownika.
 * Sygnały GCM v2: ad_storage, analytics_storage, ad_user_data, ad_personalization.
 *
 * Tryby:
 *  - tcf_gcm: mapowanie z celów IAB (purposeConsents)
 *  - gcm_only / basic: mapowanie ze zgód niestandardowych (customPurposeConsents, klucze `custom-{id}`)
 */

export type GCMConsentValue = 'granted' | 'denied';

export type GCMSignal = 'ad_storage' | 'analytics_storage' | 'ad_user_data' | 'ad_personalization';

export interface GCMParams {
  ad_storage: GCMConsentValue;
  analytics_storage: GCMConsentValue;
  ad_user_data: GCMConsentValue;
  ad_personalization: GCMConsentValue;
  wait_for_update?: number;
}

const GCM_SIGNALS: GCMSignal[] = [
  'ad_storage',
  'analytics_storage',
  'ad_user_data',
  'ad_personalization',
];

export const allDenied = (): GCMParams => ({
  ad_storage: 'denied',
  analytics_storage: 'denied',
  ad_user_data: 'denied',
  ad_personalization: 'denied',
});

/**
 * Pure mapping of consent state → GCM v2 signals. No DOM, no singletons.
 *
 * A signal is `granted` only when every IAB/custom purpose mapped to it is granted:
 *  - tcf_gcm: resolved against IAB purpose consents (`state.purposeConsents`)
 *  - gcm_only / basic: resolved against custom consents (`custom-{id}` keys),
 *    but only once at least one custom consent is granted (otherwise all denied).
 * Signals with an empty mapping are left at their default (denied).
 */
export function computeGcmConsentParams(
  mode: 'tcf_gcm' | 'gcm_only' | 'basic',
  mapping: Record<string, number[]> | undefined,
  state: ConsentState,
): GCMParams {
  const params: GCMParams = allDenied();

  for (const signal of GCM_SIGNALS) {
    const purposeIds = mapping?.[signal] || [];
    if (purposeIds.length === 0) continue;

    if (mode === 'tcf_gcm') {
      const allGranted = purposeIds.every((id) => state.purposeConsents[String(id)] === true);
      params[signal] = allGranted ? 'granted' : 'denied';
    } else if (
      (mode === 'gcm_only' || mode === 'basic') &&
      Object.values(state.customPurposeConsents).some((v) => v === true)
    ) {
      const allGranted = purposeIds.every(
        (id) => state.customPurposeConsents[`custom-${id}`] === true,
      );
      params[signal] = allGranted ? 'granted' : 'denied';
    }
  }

  return params;
}

class GoogleConsentModeService {
  private config: CMPConfig | null = null;
  private initialized = false;

  /**
   * Ustawia domyślny stan zgód (wszystko denied) zanim CMP odpowie.
   * Wywoływane bardzo wcześnie, niezależnie od trybu, aby tagi Google znały stan startowy.
   */
  initDefaults(
    waitForUpdate?: number,
    options: { urlPassthrough?: boolean; adsDataRedaction?: boolean } = {},
  ): void {
    if (this.initialized) return;
    this.ensureGtag();

    // Must be set before Google tags run, like the default itself.
    if (options.urlPassthrough) window.gtag!('set', 'url_passthrough', true);
    if (options.adsDataRedaction) window.gtag!('set', 'ads_data_redaction', true);

    const params: GCMParams = allDenied();
    if (waitForUpdate && waitForUpdate > 0) {
      params.wait_for_update = waitForUpdate;
    }

    window.gtag!('consent', 'default', params);
    this.initialized = true;
    console.log(
      '[GCM v2] consent default set (all denied)',
      waitForUpdate ? `wait_for_update: ${waitForUpdate}ms` : '',
    );
  }

  /**
   * Inicjalizuje serwis z konfiguracją i podpina nasłuch zmian zgód.
   */
  init(config: CMPConfig): void {
    this.config = config;

    const gcm = config.googleConsentMode;
    if (!gcm?.enabled) {
      console.log('[GCM v2] Google Consent Mode disabled in config');
      return;
    }

    this.initDefaults(gcm.waitForUpdate, gcm);

    // Jeśli użytkownik już wcześniej wyraził zgodę, od razu wyślij update
    try {
      if (consentService.hasUserConsent()) {
        this.sendConsentUpdate();
      }
    } catch {
      // ignoruj — brak stanu zgód na tym etapie
    }

    document.addEventListener(CMPEventType.CONSENT_CHANGED, () => {
      this.sendConsentUpdate();
    });

    console.log('[GCM v2] Initialized with mapping:', gcm.mapping);
  }

  /**
   * Wysyła aktualny stan zgód do gtag.
   */
  sendConsentUpdate(): void {
    if (!this.config?.googleConsentMode?.enabled) return;

    this.ensureGtag();
    const params = this.buildConsentParams();
    window.gtag!('consent', 'update', params);
    console.log('[GCM v2] consent update sent:', params);
  }

  /**
   * Buduje parametry sygnałów GCM na podstawie zgód i mapowania celów.
   */
  private buildConsentParams(): GCMParams {
    const gcm = this.config!.googleConsentMode!;
    const mode = this.config!.mode || 'tcf_gcm';

    let state: ConsentState;
    try {
      state = consentService.getConsentState();
    } catch {
      return allDenied();
    }

    return computeGcmConsentParams(mode, gcm.mapping, state);
  }

  /**
   * Gwarantuje obecność window.dataLayer i window.gtag (stub, jeśli strona ich nie wstrzyknęła).
   */
  private ensureGtag(): void {
    if (!window.dataLayer) {
      window.dataLayer = [];
    }
    if (typeof window.gtag !== 'function') {
      window.gtag = function () {
        // eslint-disable-next-line prefer-rest-params
        window.dataLayer!.push(arguments);
      };
    }
  }
}

const googleConsentModeService = new GoogleConsentModeService();
export default googleConsentModeService;
