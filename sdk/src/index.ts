import './styles/main.scss';
import tcfStub from '@iabtechlabtcf/stub';
import apiService from './services/api';
import consentService from './services/consent';
import cmpApiService from './services/cmpApi';
import googleConsentModeService from './services/googleConsentMode';
import ConsentManager from './components/ConsentManager';
import { CMPEventType, CMPConfig } from './models/types';
import { normalizeMode } from './services/mode';
import { cmpApiInstance } from './services/cmpApiExtensions';
import { flushConsentQueue, logConsentDecision, toLoggedAction } from './services/consentLog';
import { DEV_CMP_ID, TCF_POLICY_VERSION, TC_COOKIE_NAME } from './tcf/spec';
import { activateGatedElements } from './services/scriptGate';
import { pushConsentEvent, summarizeConsent } from './services/dataLayer';

// Add global type definitions
declare global {
  interface Window {
    __autocmp?: boolean;
    OpenCMP?: CMPSDk;
  }
}

// IAB TCF stub: creates the __tcfapiLocator iframe, answers __tcfapiCall postMessages
// from (cross-origin) frames and queues __tcfapi calls until CmpApi takes over the queue.
// The package only exports the function when bundled as CommonJS, so it must be called.
tcfStub();

/**
 * Main entry point for the CMP SDK
 */
class CMPSDk {
  private initialized: boolean = false;
  private consentManager: ConsentManager | null = null;

  /**
   * Initializes the CMP SDK with the given configuration or fetches it from API
   * @param domain - Domain to fetch configuration for
   * @param domainId - Public key of the domain, or its numeric id (older snippets)
   * @param configApiUrl - API URL
   */
  async init(
    domain: string = window.location.hostname,
    domainId?: string,
    configApiUrl?: string,
    env?: 'prod' | 'test',
  ): Promise<void> {
    console.log('Initializing CMP SDK...');
    if (this.initialized) {
      console.warn('CMP SDK already initialized');
      return;
    }

    try {
      // Set custom API URL if provided
      if (configApiUrl) {
        apiService['baseUrl'] = configApiUrl;
      }

      // Ensure window global for vendor list data is defined
      if (typeof window.__vendorListData === 'undefined') {
        window.__vendorListData = undefined;
      }

      // Google Consent Mode v2 default (all denied) as early as possible, before any
      // config is fetched, so Google tags get a known starting state. The plugin's
      // /sdk endpoint says whether this template uses GCM at all (basic mode and a
      // disabled GCM must not leave Google tags denied forever); without that boot
      // info (standalone use) the conservative default is kept.
      const boot = window.__focmpBoot;
      if (!boot?.gcm) {
        googleConsentModeService.initDefaults(500);
      } else if (boot.gcm.enabled) {
        googleConsentModeService.initDefaults(boot.gcm.waitForUpdate, boot.gcm);
      }
      if (env || boot?.env) {
        apiService.setEnvironment(env || boot?.env || 'prod');
      }

      // Initialize with complete default config EARLY to make __tcfapi available for GPT
      const defaultConfig: Partial<CMPConfig> = {
        mode: 'tcf_gcm',
        cmpId: boot?.cmpId || DEV_CMP_ID,
        cmpVersion: boot?.cmpVersion || 1,
        tcfPolicyVersion: TCF_POLICY_VERSION,
        language: 'pl', // Add missing language field (ISO 639-1 format)
        isServiceSpecific: true,
        useNonStandardStacks: false,
        publisherCountryCode: 'PL',
        vendors: { iab: {}, custom: { google: {}, other: {} } },
        cookieName: TC_COOKIE_NAME,
        cookieExpirationDays: 365,
        gvlUrl: '',
        gvlBaseUrl: '',
        customConsents: [], // Add missing customConsents array
        consentScreen: 1, // Add missing consentScreen
        reconsentID: 1, // Add missing reconsentID
        stacks: {}, // Add missing stacks
        purposes: {}, // Add missing purposes
        features: {}, // Add missing features
        specialFeatures: {}, // Add missing specialFeatures
        specialPurposes: {}, // Add missing specialPurposes
        dataCategories: {}, // Add missing dataCategories
      };

      // Initialize consent service with default config
      consentService.init(defaultConfig as CMPConfig);

      // Initialize CMP API with default config (creates __tcfapi immediately for GPT)
      cmpApiService.init(defaultConfig as CMPConfig);

      // Fetch real configuration from API
      const config = await apiService.fetchCMPConfig(domain, domainId);

      // Validate working mode; unknown/missing falls back to tcf_gcm with a warning.
      const mode = normalizeMode(config.mode);
      config.mode = mode;
      console.log(`CMP mode: ${mode}`);

      // Ensure GVL URL is set — only relevant in full TCF mode
      if (mode === 'tcf_gcm' && !config.gvlUrl) {
        console.warn('GVL URL not provided by API, using default');
        const { GVL_BASE_URL } = await import('./data/cmpConfig');
        config.gvlUrl = `${GVL_BASE_URL}/vendor-list.json`;
      }

      // Update consent service with real config (needed for customVendorsService)
      consentService.init(config);

      // Reconsent check first: stale consents must be cleared before Consent Mode
      // and the TCF API read them (otherwise old "granted" signals leak out).
      const reconsentRequired = consentService.checkAndClearConsentsIfNeeded();

      // Initialize Google Consent Mode v2 (full and gcm_only modes only)
      if (config.googleConsentMode?.enabled && (mode === 'tcf_gcm' || mode === 'gcm_only')) {
        googleConsentModeService.init(config);
      }

      // Update CMP API (TCF) with real config — only in full TCF mode; the other
      // modes must not expose __tcfapi at all.
      if (mode === 'tcf_gcm') {
        cmpApiService.updateConfig(config);
      } else {
        cmpApiService.deactivate();
      }

      // Create consent manager
      this.consentManager = new ConsentManager(config);

      // Connect consent manager to CMP API
      cmpApiInstance.setConsentManager(this.consentManager);

      // Mark as initialized after consent manager is created
      this.initialized = true;

      // Add event listener for footer link
      this.setupConsentLink();

      // Proof of consent: first re-send what earlier page views could not deliver,
      // then log every decision (after the TCF API and the AC string were updated
      // by their own CONSENT_CHANGED listeners, registered earlier).
      if (config.consentLog !== false) {
        void flushConsentQueue();
      }
      let lastButton = '';
      document.addEventListener(CMPEventType.CONSENT_BUTTON_CLICKED, (event: Event) => {
        lastButton = (event as CustomEvent).detail?.buttonId || '';
      });
      document.addEventListener(CMPEventType.CONSENT_CHANGED, (event: Event) => {
        const action = toLoggedAction((event as CustomEvent).detail?.action, lastButton);
        lastButton = '';
        if (action) {
          logConsentDecision(
            config,
            {
              baseUrl: apiService.getBaseUrl(),
              domainRef: domainId != null ? String(domainId) : undefined,
              env: apiService.getEnvironment(),
            },
            action,
            {
              tcString: consentService.getTCString(),
              acString: cmpApiService.getAdditionalConsentString(),
            },
          );
        }
      });

      // Prior blocking: run consent-gated scripts/embeds once their consents exist —
      // now (returning visitor), after the page is parsed (elements below the SDK)
      // and after every decision.
      const runGate = () => {
        if (consentService.hasUserConsent()) {
          activateGatedElements(consentService.getConsentState(), consentService.isTcfMode());
        }
      };
      runGate();

      // GTM: consent events in the dataLayer
      if (consentService.hasUserConsent()) {
        pushConsentEvent('focmp_consent_ready', summarizeConsent(consentService.getConsentState()));
      }
      document.addEventListener(CMPEventType.CONSENT_CHANGED, (event: Event) => {
        const action = String((event as CustomEvent).detail?.action || '').toLowerCase();
        pushConsentEvent(
          'focmp_consent_update',
          summarizeConsent(consentService.getConsentState(), action || undefined),
        );
      });

      if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', runGate);
      }
      document.addEventListener(CMPEventType.CONSENT_CHANGED, runGate);

      // Add event listener for consent errors
      document.addEventListener(CMPEventType.CONSENT_ERROR, (event: Event) => {
        const customEvent = event as CustomEvent;
        console.error('CMP consent error:', customEvent.detail.error);
      });

      // Check if consent already exists
      const hasConsent = consentService.hasUserConsent();

      // If consent doesn't exist or reconsent is required, show consent box
      if (!hasConsent || reconsentRequired) {
        console.log('Showing consent box. Reconsent required:', reconsentRequired);

        // Opóźnij wyświetlenie okna konsentowego, aby dać czas na inicjalizację GVL
        setTimeout(() => {
          this.showConsentBox();
        }, 100);
      } else {
        this.consentManager.setReopenButtonVisible(true);
      }

      console.log('CMP SDK initialized successfully.');
    } catch (error) {
      console.error('Failed to initialize CMP SDK:', error);
      throw error;
    }
  }

  /**
   * Admin preview: renders a template's (possibly unsaved) configuration without
   * TCF API, Consent Mode, cookies or logging. Called by the template editor.
   */
  async preview(basicConfig: CMPConfig, layer: number = 1): Promise<void> {
    const config = await apiService.completeConfig(basicConfig);
    config.mode = normalizeMode(config.mode);
    consentService.init(config);
    this.consentManager = new ConsentManager(config, { preview: true });
    this.initialized = true;
    this.consentManager.show('open-cmp-container', layer);
  }

  /**
   * Shows the consent box
   * @param layer - Initial layer to show (1-3)
   */
  showConsentBox(layer: number = 1): void {
    if (!this.initialized || !this.consentManager) {
      console.error('CMP SDK not initialized');
      return;
    }

    this.consentManager.show('open-cmp-container', layer);
  }

  /**
   * Sets up the consent link in the footer
   */
  private setupConsentLink(): void {
    const consentLink = document.getElementById('cmp-footer-link');
    if (consentLink) {
      consentLink.removeAttribute('onclick');
    }

    // Delegated, so links added after init (menus, footers rendered later) work too:
    // #cmp-footer-link, any [data-cmp-open] element or a link to #cmp-settings.
    document.addEventListener('click', (e) => {
      const target = (e.target as Element | null)?.closest?.(
        '#cmp-footer-link, [data-cmp-open], a[href="#cmp-settings"]',
      );
      if (!target) return;
      e.preventDefault();
      const layer = parseInt(target.getAttribute('data-cmp-open') || '2', 10);
      this.showConsentBox([1, 2, 3].includes(layer) ? layer : 2);
    });
  }
}

// Create and export CMP SDK instance
const cmpSdk = new CMPSDk();
export default cmpSdk;

// Make SDK globally available BEFORE init (so WordPress can call init with params)
window.OpenCMP = cmpSdk;

// Make CMP API globally available
window.__cmp = cmpApiInstance;

// Auto-initialize only if not configured externally (WordPress adds its own init call)
// Use window.__autocmp = false to disable auto-init
if (window.__autocmp !== false) {
  cmpSdk.init();
}
