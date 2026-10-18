import { CmpApi, TCData } from '@iabtechlabtcf/cmpapi';
import { CMPConfig, CMPEventType, CustomCommands, ExtendableTCData } from '../models/types';
import consentService from './consent';
import customVendorsService from './customVendors';

// Google Additional Consent types
interface GoogleATPProvider {
  id: number;
  name: string;
  privacyPolicyUrl: string;
}

interface ExtendedTCData extends TCData {
  addtlConsent?: string;
}

/**
 * CMP API Service implementing IAB TCF 2.2 standard
 */
export class CMPApiService {
  private cmpApi: CmpApi | null = null;
  private config: CMPConfig | null = null;
  private googleATPProviders: GoogleATPProvider[] = [];
  private additionalConsentString: string = '';
  // False once the template turns out not to use TCF (gcm_only / basic).
  private active = true;

  /**
   * Removes the TCF API for templates without TCF (gcm_only / basic): no
   * __tcfapi, no __tcfapiLocator frame and no Additional Consent string, so
   * vendors do not read TCF signals that the user was never asked about.
   */
  deactivate(): void {
    this.active = false;
    this.cmpApi = null;
    try {
      delete (window as { __tcfapi?: unknown }).__tcfapi;
    } catch {
      (window as { __tcfapi?: unknown }).__tcfapi = undefined;
    }
    document.querySelectorAll('iframe[name="__tcfapiLocator"]').forEach((f) => f.remove());
    this.additionalConsentString = '';
    this.saveAdditionalConsentString();
  }

  isActive(): boolean {
    return this.active;
  }

  /**
   * Early initialization with minimal config to make __tcfapi available ASAP
   */
  initEarly(earlyConfig: { cmpId: number; cmpVersion: number; isServiceSpecific: boolean }): void {
    console.log('Early CMP API initialization for GPT compatibility');

    // Create minimal CMP API instance to respond to ping immediately
    this.cmpApi = new CmpApi(
      earlyConfig.cmpId,
      earlyConfig.cmpVersion,
      true, // gdprApplies
      undefined, // no custom commands yet
    );

    // Call update with empty string to process any queued commands from GPT
    this.cmpApi.update('', true);
    console.log('Early CMP API ready - __tcfapi now responds to ping and queues other commands');
  }

  /**
   * Initialize CMP API with configuration
   */
  init(config: CMPConfig): void {
    console.log('CMP API initialization');
    this.config = config;

    // Load Google ATP providers from config
    this.loadGoogleATPProvidersFromConfig();

    // Load existing Additional Consent string
    this.loadAdditionalConsentString();

    // Create CmpApi instance with custom commands
    const cmpApiConfig: CustomCommands = {
      getTCData: (
        next: (tcData: unknown, status: boolean) => void,
        tcData: unknown,
        status: boolean,
      ) => {
        // Add addtlConsent to TCData response if it's not a boolean
        if (typeof tcData !== 'boolean' && tcData && typeof tcData === 'object') {
          const extendedData = tcData as ExtendableTCData;
          extendedData.addtlConsent = this.additionalConsentString || undefined;
          console.log('Added addtlConsent to TCData:', this.additionalConsentString);
        }

        // Pass data and status along
        next(tcData, status);
      },
    };

    // Create CMP API instance
    this.cmpApi = new CmpApi(
      config.cmpId,
      config.cmpVersion,
      true, // gdprApplies
      cmpApiConfig,
    );

    // Use setTimeout like in the OLD working version
    setTimeout(() => {
      // Check for existing TC String and update if available
      const tcString = consentService.getTCString();

      if (tcString && this.cmpApi && this.active) {
        // Update CMP API with existing TC String
        this.cmpApi.update(tcString, false);
        console.log('Updated CMP API with existing TC String');

        // Check if GPT is available and refresh ads after setting consents
        // Add delay to allow GPT to load
        setTimeout(() => {
          this.refreshGptAdsIfPresent();
        }, 500);
      }
    }, 0);

    // Listen for consent changes like in OLD version
    document.addEventListener(CMPEventType.CONSENT_CHANGED, () => {
      const updatedTcString = consentService.getTCString();
      if (updatedTcString && this.cmpApi && this.active) {
        // Compute the AC string synchronously so the useractioncomplete event
        // that update() fires already carries addtlConsent.
        this.refreshAdditionalConsent();
        console.log('Aktualizacja CMP API po zmianie zgód');
        this.cmpApi.update(updatedTcString, false);

        // Execute refresh ads after consent update
        this.refreshGptAdsIfPresent();
      }
    });

    // TCF: tell listeners the UI is visible (eventStatus "cmpuishown",
    // displayStatus "visible"); the decision itself arrives via CONSENT_CHANGED.
    // Only the hidden → visible transition counts (switching layers does not).
    let uiVisible = false;
    document.addEventListener(CMPEventType.CONSENT_UI_SHOWN, () => {
      if (this.cmpApi && this.active && !uiVisible) {
        uiVisible = true;
        this.cmpApi.update(consentService.getTCString() || '', true);
      }
    });
    document.addEventListener(CMPEventType.CONSENT_UI_CLOSED, () => {
      uiVisible = false;
    });

    // Initialize Additional Consent if there are existing Google vendor consents
    this.initializeAdditionalConsentFromExistingConsents();

    console.log('CMP API initialized successfully');
  }

  /**
   * Update configuration without recreating CMP API instance
   */
  updateConfig(config: CMPConfig): void {
    console.log('Updating CMP API configuration with real config');
    this.config = config;

    // Load Google ATP providers from new config
    this.loadGoogleATPProvidersFromConfig();
    console.log('After updateConfig - Google ATP providers count:', this.googleATPProviders.length);

    // Reload Additional Consent string
    this.loadAdditionalConsentString();
    console.log('After updateConfig - Additional Consent string:', this.additionalConsentString);

    // Initialize Additional Consent if there are existing Google vendor consents
    this.initializeAdditionalConsentFromExistingConsents();
    console.log(
      'After initializeAdditionalConsentFromExistingConsents - Additional Consent string:',
      this.additionalConsentString,
    );
  }

  /**
   * Load Google ATP providers from CMP config
   */
  private loadGoogleATPProvidersFromConfig(): void {
    if (!this.config?.vendors?.custom?.google) {
      console.warn('No Google ATP providers found in config');
      return;
    }

    this.googleATPProviders = [];
    const googleVendors = this.config.vendors.custom.google;

    for (const vendorId in googleVendors) {
      const vendor = googleVendors[vendorId];
      const privacyUrl = vendor.urls?.find((url) => url.langId === 'pl')?.privacy || '';

      this.googleATPProviders.push({
        id: vendor.id,
        name: vendor.name,
        privacyPolicyUrl: privacyUrl,
      });
    }

    console.log(`Loaded ${this.googleATPProviders.length} Google ATP providers from config`);
  }

  /**
   * Load existing Additional Consent string from storage
   */
  private loadAdditionalConsentString(): void {
    try {
      const stored = localStorage.getItem('IABTCF_AddtlConsent');
      if (stored) {
        this.additionalConsentString = stored;
        console.log('Loaded existing Additional Consent string:', stored);
      }
    } catch (error) {
      console.warn('Error loading Additional Consent string:', error);
    }
  }

  /**
   * Save Additional Consent string to storage
   */
  private saveAdditionalConsentString(): void {
    try {
      if (this.additionalConsentString) {
        localStorage.setItem('IABTCF_AddtlConsent', this.additionalConsentString);
      } else {
        localStorage.removeItem('IABTCF_AddtlConsent');
      }
    } catch (error) {
      console.warn('Error saving Additional Consent string:', error);
    }
  }

  /**
   * Create Additional Consent string in v2 format
   */
  public createAdditionalConsentString(
    consentedProviderIds: number[],
    disclosedProviderIds: number[] = [],
  ): string {
    if (consentedProviderIds.length === 0 && disclosedProviderIds.length === 0) {
      return '';
    }

    // Filter to only include valid Google ATP provider IDs
    const validConsentedIds = consentedProviderIds.filter((id) =>
      this.googleATPProviders.some((provider) => provider.id === id),
    );

    const validDisclosedIds = disclosedProviderIds.filter(
      (id) =>
        this.googleATPProviders.some((provider) => provider.id === id) &&
        !validConsentedIds.includes(id), // Don't duplicate consented providers
    );

    let acString = '2~'; // Version 2

    // Part 3: Consented providers
    if (validConsentedIds.length > 0) {
      acString += validConsentedIds.sort((a, b) => a - b).join('.');
    }

    acString += '~'; // Separator

    // Part 5: Disclosed providers
    if (validDisclosedIds.length > 0) {
      acString += 'dv.' + validDisclosedIds.sort((a, b) => a - b).join('.');
    }

    return acString;
  }

  /**
   * Update Additional Consent string and save it
   */
  public updateAdditionalConsent(
    consentedProviderIds: number[],
    disclosedProviderIds: number[] = [],
  ): void {
    if (!this.active) return;
    this.additionalConsentString = this.createAdditionalConsentString(
      consentedProviderIds,
      disclosedProviderIds,
    );
    this.saveAdditionalConsentString();
    console.log('Updated Additional Consent string:', this.additionalConsentString);
  }

  /**
   * Rebuilds the Additional Consent string from the stored Google vendor choices.
   * Only Google providers actually listed in the consent box count as disclosed;
   * when the Google group is hidden, nobody is disclosed or consented.
   */
  private refreshAdditionalConsent(): void {
    const shown = this.config?.visibleVendorGroups?.google !== false;
    const configured = Object.keys(this.config?.vendors?.custom?.google || {}).map((id) =>
      parseInt(id, 10),
    );
    const choices = customVendorsService.getConsents().google || {};
    const consented = shown ? configured.filter((id) => choices[id]) : [];
    const disclosed = shown ? configured.filter((id) => !choices[id]) : [];
    this.updateAdditionalConsent(consented, disclosed);
  }

  /**
   * Get current Additional Consent string
   */
  public getAdditionalConsentString(): string {
    return this.additionalConsentString;
  }

  /**
   * Get available Google ATP providers
   */
  public getGoogleATPProviders(): GoogleATPProvider[] {
    return [...this.googleATPProviders];
  }

  /**
   * Initialize Additional Consent from existing Google vendor consents
   * This method only loads existing data without overwriting saved Additional Consent string
   */
  private initializeAdditionalConsentFromExistingConsents(): void {
    // This method is now primarily for logging/debugging purposes
    // The actual Additional Consent string initialization happens in updateConfig()
    // where we have access to the full Google ATP providers configuration

    // Import customVendorsService dynamically to avoid circular dependency
    import('./customVendors')
      .then(({ default: customVendorsService }) => {
        const customConsents = customVendorsService.getConsents();
        const consentedProviderIds: number[] = [];
        const disclosedProviderIds: number[] = [];

        // Collect consented and disclosed Google vendor IDs for logging
        for (const vendorId in customConsents.google) {
          const providerId = parseInt(vendorId);
          if (customConsents.google[vendorId]) {
            consentedProviderIds.push(providerId);
          } else {
            disclosedProviderIds.push(providerId);
          }
        }

        // Don't update Additional Consent string here - let updateConfig() handle it
        // This prevents overwriting valid saved Additional Consent strings
        console.log('Found existing Google vendor consents:', {
          consentedProviderIds,
          disclosedProviderIds,
        });
      })
      .catch((error) => {
        console.warn('Failed to initialize Additional Consent from existing consents:', error);
      });
  }

  /**
   * Sprawdza czy GPT jest dostępny na stronie i wykonuje refresh reklam
   */
  private refreshGptAdsIfPresent(): void {
    console.log('refreshGPTAds called');
    console.log('window.googletag exists:', !!window.googletag);
    console.log('window.googletag.pubads exists:', !!window.googletag?.pubads);

    // Only for publishers that hold the first ad request until consent
    // (googletag.pubads().disableInitialLoad()); otherwise GPT has already
    // requested ads with the TCF signal and a refresh would duplicate them.
    const pubads = window.googletag?.pubads?.();
    if (
      pubads &&
      typeof pubads.isInitialLoadDisabled === 'function' &&
      !pubads.isInitialLoadDisabled()
    ) {
      console.log('GPT initial load not disabled — no refresh needed');
      return;
    }

    if (window.googletag && window.googletag.pubads) {
      console.log('Refreshing GPT ads after consent update');

      window.googletag.cmd.push(() => {
        console.log('Inside googletag.cmd.push callback');

        // After disableInitialLoad(), we need to explicitly refresh slots
        // Get all defined slots and refresh them
        if (window.googletag) {
          const slots = window.googletag.pubads().getSlots();
          console.log('Found GPT slots to refresh:', slots.length);

          if (slots.length > 0) {
            console.log(
              'Refreshing specific slots:',
              slots.map((s) => s.getSlotElementId()),
            );
            window.googletag.pubads().refresh(slots);
          } else {
            console.log('No slots found, calling refresh() without arguments');
            window.googletag.pubads().refresh();
          }
        }
      });
    } else {
      console.log('GPT not available for refresh - googletag or pubads missing');
    }
  }

  /**
   * Update CMP API with new TC String
   */
  update(tcString: string, gdprApplies: boolean = true): void {
    if (this.cmpApi) {
      this.cmpApi.update(tcString, gdprApplies);
      console.log('CMP API updated with new TC String');
    }
  }

  /**
   * Get current TCData using __tcfapi
   */
  getTCData(): Promise<ExtendedTCData | null> {
    return new Promise((resolve) => {
      if (window.__tcfapi) {
        window.__tcfapi('getTCData', 2, (success, data) => {
          if (success) {
            resolve(data as ExtendedTCData);
          } else {
            resolve(null);
          }
        });
      } else {
        resolve(null);
      }
    });
  }
}

export default new CMPApiService();
