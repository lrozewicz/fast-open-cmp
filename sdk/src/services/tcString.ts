import { TCModel, TCString, GVL } from '@iabtechlabtcf/core';
import { CMPConfig, ConsentState, toIABVendorList } from '../models/types';

/** Midnight UTC of the given date (day-level timestamp required by the TC String spec). */
export const utcDayStart = (date: Date): Date =>
  new Date(Date.UTC(date.getUTCFullYear(), date.getUTCMonth(), date.getUTCDate()));

export class TCStringService {
  private config: CMPConfig;
  private tcModel: TCModel;
  private gvl: GVL | null = null;
  // True once a stored TC String was decoded into the model; the async model init
  // must then keep its consents and creation date instead of starting from scratch.
  private decoded = false;

  constructor(config: CMPConfig) {
    this.config = config;
    this.tcModel = new TCModel();

    this.initGVL()
      .then(() => {
        this.initTCModel();
      })
      .catch((error) => {
        console.error('Failed to initialize GVL:', error);
      });
  }

  /**
   * Inicjalizuje Global Vendor List
   */
  private async initGVL(): Promise<void> {
    try {
      // Import apiService here to avoid circular dependency issues
      const apiService = (await import('./api')).default;

      // Skip GVL initialization if gvlUrl is not configured yet
      // (happens during first init with default empty config)
      if (!this.config.gvlUrl) {
        console.log('GVL URL not configured yet, skipping GVL initialization');
        return;
      }

      console.log('Using GVL URL:', this.config.gvlUrl);

      // Set GVL.baseUrl from config (needed by @iabtcf/core for language files)
      if (this.config.gvlBaseUrl) {
        GVL.baseUrl = this.config.gvlBaseUrl;
      }

      // Always try to use cached data first to avoid double network requests
      const cachedData = apiService.getCachedVendorList();

      if (cachedData) {
        console.log('Using cached vendor list data in GVL initialization');
        // We already have the data, create GVL instance with it
        this.gvl = new GVL(toIABVendorList(cachedData));

        // Since we're providing the data directly, readyPromise should resolve immediately
        await this.gvl.readyPromise;
      } else {
        console.log('No cached data available, GVL will load from network');

        const gvlUrl = this.config.gvlUrl;

        // Fetch the data manually using ApiService to ensure caching
        const response = await fetch(gvlUrl);
        if (!response.ok) {
          throw new Error(`Failed to fetch vendor list: ${response.status} ${response.statusText}`);
        }

        const vendorListData = await response.json();

        // Cache the data for future use
        apiService['vendorListCache'].set(gvlUrl, vendorListData);
        window.__vendorListData = vendorListData;

        // Create GVL instance with the fetched data
        this.gvl = new GVL(toIABVendorList(vendorListData));
        await this.gvl.readyPromise;
      }

      // NOTE: We do NOT call gvl.changeLanguage() here.
      // The vendor list data from the API is already returned in the correct language
      // (the PHP endpoint build_vendor_list($lang) translates purposes, features, etc.).
      // Calling changeLanguage() would try to fetch a separate translation file,
      // which is unnecessary and fails with non-pretty WordPress permalinks.
      // The consentLanguage in TCModel is set explicitly from config.language in initTCModel().

      // Przypisujemy GVL do TCModel
      this.tcModel.gvl = this.gvl;

      console.log('GVL initialized successfully');
    } catch (error) {
      console.error('Error initializing GVL:', error);
      throw error;
    }
  }

  /**
   * Highest publisher custom purpose number in use. Ids are stable and may have
   * gaps (custom-1, custom-4), so the count of consents is not enough.
   */
  private maxCustomPurposeId(): number {
    let max = 0;
    for (const consent of this.config.customConsents) {
      const m = consent.id.match(/^custom-(\d+)$/);
      if (m) max = Math.max(max, parseInt(m[1], 10));
    }
    return max;
  }

  /**
   * Inicjalizuje TCModel z wartościami domyślnymi z konfiguracji CMP
   */
  private initTCModel(): void {
    // Ustawienie metadata
    this.tcModel.cmpId = this.config.cmpId;
    this.tcModel.cmpVersion = this.config.cmpVersion;
    this.tcModel.consentScreen = this.config.consentScreen;
    console.log('Setting consentLanguage from config.language:', this.config.language);
    this.tcModel.consentLanguage = this.config.language;
    this.tcModel.vendorListVersion = this.config.vendorListVersion;
    this.tcModel.policyVersion = this.config.tcfPolicyVersion;
    this.tcModel.isServiceSpecific = this.config.isServiceSpecific;
    // TCF 2.2: pole TCModel zostało przemianowane na useNonStandardTexts
    // (klucz konfiguracji pozostaje useNonStandardStacks dla zgodności z API/PHP)
    this.tcModel.useNonStandardTexts = this.config.useNonStandardStacks;
    this.tcModel.publisherCountryCode = this.config.publisherCountryCode;

    // Initialize custom purposes count
    this.tcModel.numCustomPurposes = this.maxCustomPurposeId();

    if (this.decoded) {
      // Stored decision: keep its consents and creation date.
      return;
    }

    // Ustawienie czasu (created / lastUpdated)
    const now = new Date();
    this.tcModel.created = now;
    this.tcModel.lastUpdated = now;

    // Domyślnie, wszystkie zgody są wyłączone z wyjątkiem legitInterests
    this.resetConsents();
  }

  /**
   * Resetuje wszystkie zgody do stanu domyślnego (wyłączone,
   * z wyjątkiem purposeLegitimateInterests i vendorLegitimateInterests)
   */
  resetConsents(): void {
    // Wyłącz wszystkie zgody
    for (const purposeId in this.config.purposes) {
      const purpose = parseInt(purposeId);
      this.tcModel.purposeConsents.unset(purpose);

      // Włącz uzasadniony interes, jeśli jest dozwolony dla tego celu
      if (this.config.purposes[purposeId].legitimateInterestAllowed) {
        this.tcModel.purposeLegitimateInterests.set(purpose);
      } else {
        this.tcModel.purposeLegitimateInterests.unset(purpose);
      }
    }

    // Wyłącz wszystkie funkcje specjalne
    for (const featureId in this.config.specialFeatures) {
      const feature = parseInt(featureId);
      this.tcModel.specialFeatureOptins.unset(feature);
    }

    // Wyłącz wszystkie zgody vendorów IAB
    for (const vendorId in this.config.vendors.iab) {
      const vendor = parseInt(vendorId);
      this.tcModel.vendorConsents.unset(vendor);

      // Włącz uzasadnione interesy vendorów
      const vendorObj = this.config.vendors.iab[vendorId];
      if (vendorObj.legIntPurposes.length > 0) {
        this.tcModel.vendorLegitimateInterests.set(vendor);
      } else {
        this.tcModel.vendorLegitimateInterests.unset(vendor);
      }
    }

    // Resetowanie customowych zgód - używamy publisherCustomConsents dla customowych zgód
    for (const consent of this.config.customConsents) {
      const matches = consent.id.match(/custom-(\d+)/);
      if (matches && matches[1]) {
        const purposeIdNum = parseInt(matches[1]);
        this.tcModel.publisherCustomConsents.unset(purposeIdNum);

        // Włącz uzasadniony interes, jeśli jest dozwolony dla tego celu (domyślnie zaznaczony)
        if (consent.legitimateInterestAllowed) {
          this.tcModel.publisherCustomLegitimateInterests.set(purposeIdNum);
        }
      }
    }
  }

  /**
   * Ustawia wszystkie zgody na włączone (accept all)
   */
  acceptAll(): void {
    console.log('Accepting all consents');

    // Włącz wszystkie zgody celów
    for (const purposeId in this.config.purposes) {
      const purpose = parseInt(purposeId);
      this.tcModel.purposeConsents.set(purpose);

      if (this.config.purposes[purposeId].legitimateInterestAllowed) {
        this.tcModel.purposeLegitimateInterests.set(purpose);
      }
    }

    // Włącz wszystkie funkcje specjalne
    for (const featureId in this.config.specialFeatures) {
      const feature = parseInt(featureId);
      this.tcModel.specialFeatureOptins.set(feature);
    }

    // Włącz wszystkie zgody vendorów
    for (const vendorId in this.config.vendors.iab) {
      const vendor = parseInt(vendorId);
      this.tcModel.vendorConsents.set(vendor);

      const vendorObj = this.config.vendors.iab[vendorId];
      if (vendorObj.legIntPurposes.length > 0) {
        this.tcModel.vendorLegitimateInterests.set(vendor);
      }
    }

    // Włącz wszystkie customowe zgody - używamy publisherCustomConsents dla customowych zgód
    for (const consent of this.config.customConsents) {
      console.log('Processing custom consent:', consent.id);

      // Parsuj numer z ID (np. z 'custom-1' bierzemy '1')
      const matches = consent.id.match(/custom-(\d+)/);
      if (matches && matches[1]) {
        const purposeIdNum = parseInt(matches[1]);
        console.log('Setting custom purpose consent:', purposeIdNum, 'to true');

        // Ustaw wartość w Vector dla customowych celów
        this.tcModel.publisherCustomConsents.set(purposeIdNum);

        if (consent.legitimateInterestAllowed) {
          this.tcModel.publisherCustomLegitimateInterests.set(purposeIdNum);
        }
      }
    }

    // Ustaw liczbę customowych celów
    this.tcModel.numCustomPurposes = this.maxCustomPurposeId();
    console.log('Set numCustomPurposes to:', this.tcModel.numCustomPurposes);

    // Aktualizacja czasu ostatniej zmiany
    this.tcModel.lastUpdated = new Date();
  }

  /**
   * Odrzuca wszystkie zgody (reject all)
   */
  rejectAll(): void {
    // Wyłącz wszystkie zgody
    for (const purposeId in this.config.purposes) {
      const purpose = parseInt(purposeId);
      this.tcModel.purposeConsents.unset(purpose);
      this.tcModel.purposeLegitimateInterests.unset(purpose);
    }

    // Wyłącz wszystkie funkcje specjalne
    for (const featureId in this.config.specialFeatures) {
      const feature = parseInt(featureId);
      this.tcModel.specialFeatureOptins.unset(feature);
    }

    // Wyłącz wszystkie zgody vendorów
    for (const vendorId in this.config.vendors.iab) {
      const vendor = parseInt(vendorId);
      this.tcModel.vendorConsents.unset(vendor);
      this.tcModel.vendorLegitimateInterests.unset(vendor);
    }

    // Wyłącz wszystkie customowe zgody - używamy publisherCustomConsents dla customowych zgód
    for (const consent of this.config.customConsents) {
      // Parsuj numer z ID (np. z 'custom-1' bierzemy '1')
      const matches = consent.id.match(/custom-(\d+)/);
      if (matches && matches[1]) {
        const purposeIdNum = parseInt(matches[1]);
        // Wyłącz zgodę dla customowego celu
        this.tcModel.publisherCustomConsents.unset(purposeIdNum);

        // Wyłącz legitInterest - przy rejectAll wyłączamy wszystko
        if (consent.legitimateInterestAllowed) {
          this.tcModel.publisherCustomLegitimateInterests.unset(purposeIdNum);
        }
      }
    }

    // Ustaw liczbę customowych celów
    this.tcModel.numCustomPurposes = this.maxCustomPurposeId();

    // Aktualizacja czasu ostatniej zmiany
    this.tcModel.lastUpdated = new Date();
  }

  /**
   * Ustawia zgody na podstawie aktualnego stanu ConsentState
   * @param consentState - Stan zgód
   */
  setConsentsFromState(consentState: ConsentState): void {
    // Ustawienie zgód celów
    for (const purposeId in consentState.purposeConsents) {
      const purpose = parseInt(purposeId);
      if (consentState.purposeConsents[purposeId]) {
        this.tcModel.purposeConsents.set(purpose);
      } else {
        this.tcModel.purposeConsents.unset(purpose);
      }
    }

    // Ustawienie uzasadnionych interesów celów
    for (const purposeId in consentState.purposeLegitimateInterests) {
      const purpose = parseInt(purposeId);
      if (consentState.purposeLegitimateInterests[purposeId]) {
        this.tcModel.purposeLegitimateInterests.set(purpose);
      } else {
        this.tcModel.purposeLegitimateInterests.unset(purpose);
      }
    }

    // Ustawienie funkcji specjalnych
    for (const featureId in consentState.specialFeatureOptins) {
      const feature = parseInt(featureId);
      if (consentState.specialFeatureOptins[featureId]) {
        this.tcModel.specialFeatureOptins.set(feature);
      } else {
        this.tcModel.specialFeatureOptins.unset(feature);
      }
    }

    // Ustawienie zgód vendorów IAB
    for (const vendorId in consentState.vendorConsents) {
      const vendor = parseInt(vendorId);
      if (consentState.vendorConsents[vendorId]) {
        this.tcModel.vendorConsents.set(vendor);
      } else {
        this.tcModel.vendorConsents.unset(vendor);
      }
    }

    // Ustawienie uzasadnionych interesów vendorów IAB
    for (const vendorId in consentState.vendorLegitimateInterests) {
      const vendor = parseInt(vendorId);
      if (consentState.vendorLegitimateInterests[vendorId]) {
        this.tcModel.vendorLegitimateInterests.set(vendor);
      } else {
        this.tcModel.vendorLegitimateInterests.unset(vendor);
      }
    }

    // Ustawienie customowych zgód - używamy publisherCustomConsents dla customowych zgód
    for (const customPurposeId in consentState.customPurposeConsents) {
      // Parsuj numer z ID (np. z 'custom-1' bierzemy '1')
      const matches = customPurposeId.match(/custom-(\d+)/);
      if (matches && matches[1]) {
        const purposeIdNum = parseInt(matches[1]);

        if (consentState.customPurposeConsents[customPurposeId]) {
          this.tcModel.publisherCustomConsents.set(purposeIdNum);
        } else {
          this.tcModel.publisherCustomConsents.unset(purposeIdNum);
        }

        // Sprawdź, czy ten customowy cel ma dozwolony uzasadniony interes
        const customConsent = this.config.customConsents.find((c) => c.id === customPurposeId);
        if (customConsent && customConsent.legitimateInterestAllowed) {
          // Pobierz wartość legitimate interest z state lub ustaw na false
          const hasLegitInterest =
            (consentState.customPurposeLegitimateInterests &&
              consentState.customPurposeLegitimateInterests[customPurposeId]) ||
            consentState.purposeLegitimateInterests[customPurposeId] ||
            false;

          if (hasLegitInterest) {
            this.tcModel.publisherCustomLegitimateInterests.set(purposeIdNum);
          } else {
            this.tcModel.publisherCustomLegitimateInterests.unset(purposeIdNum);
          }
        }
      }
    }

    // Ustaw liczbę customowych celów
    this.tcModel.numCustomPurposes = this.maxCustomPurposeId();

    // Aktualizacja czasu ostatniej zmiany
    this.tcModel.lastUpdated = new Date();
  }

  /** Marks every IAB vendor presented in the consent box as disclosed. */
  private setDisclosedVendors(): void {
    const disclosed = this.tcModel.vendorsDisclosed;
    disclosed.empty();
    for (const vendorId in this.config.vendors?.iab || {}) {
      const vendor = this.config.vendors.iab[vendorId];
      if (!vendor.deletedDate) {
        disclosed.set(parseInt(vendorId, 10));
      }
    }
  }

  /**
   * Generuje TC string na podstawie aktualnego stanu TCModel
   * @returns TC string
   * @throws Error - Jeśli GVL nie jest zainicjowana
   */
  generateTCString(): string {
    // Sprawdź czy GVL został załadowany
    if (!this.gvl || !this.tcModel.gvl) {
      throw new Error('Cannot generate TCString: GVL not initialized');
    }

    // Debug: Sprawdź stan modelu przed kodowaniem
    console.log('numCustomPurposes:', this.tcModel.numCustomPurposes);
    console.log('publisherCustomConsents:', this.tcModel.publisherCustomConsents);
    console.log('vendorConsents (dla IAB vendorów):', this.tcModel.vendorConsents);
    console.log(
      'publisherCustomConsents (dla customowych zgód):',
      this.tcModel.publisherCustomConsents,
    );

    // Upewnij się, że numCustomPurposes jest ustawione
    if (this.config.customConsents.length > 0) {
      this.tcModel.numCustomPurposes = this.maxCustomPurposeId();
      console.log('Set numCustomPurposes to:', this.tcModel.numCustomPurposes);
    }

    // TC String spec (v2.x, "Created / LastUpdated"): both fields carry the SAME
    // day-level UTC timestamp of the last update — no time of day (fingerprinting).
    const today = utcDayStart(new Date());
    this.tcModel.created = today;
    this.tcModel.lastUpdated = today;

    // TCF 2.3: the Disclosed Vendors segment is mandatory and lists every IAB
    // vendor the user was shown (the vendor list after the template's filter).
    this.setDisclosedVendors();

    // LAST CHANCE: Force set consentLanguage just before encoding as final override
    this.tcModel.consentLanguage = this.config.language;
    this.tcModel.publisherCountryCode = this.config.publisherCountryCode;

    // Debug: sprawdź consentLanguage przed kodowaniem
    console.log('Before encoding - consentLanguage:', this.tcModel.consentLanguage);
    console.log('Before encoding - publisherCountryCode:', this.tcModel.publisherCountryCode);

    const tcString = TCString.encode(this.tcModel);

    // Debug: Dekoduj TCString i sprawdź, czy język został zakodowany poprawnie
    try {
      const decoded = TCString.decode(tcString);
      console.log('Decoded TCString - consentLanguage:', decoded.consentLanguage);
      console.log('Decoded TCString - publisherCountryCode:', decoded.publisherCountryCode);
      console.log('Decoded TCString numCustomPurposes:', decoded.numCustomPurposes);
      console.log('Decoded TCString publisherCustomConsents:', decoded.publisherCustomConsents);
    } catch (error) {
      console.error('Error decoding TCString:', error);
    }

    return tcString;
  }

  /**
   * Dekoduje TC string i aktualizuje TCModel
   * @param tcString - TC string do zdekodowania
   * @returns true, jeśli dekodowanie się powiodło, false w przeciwnym razie
   */
  decodeTCString(tcString: string): boolean {
    try {
      const decodedModel = TCString.decode(tcString);
      if (decodedModel) {
        this.tcModel = decodedModel;
        this.decoded = true;

        // Ensure GVL is assigned to the model
        if (this.gvl) {
          this.tcModel.gvl = this.gvl;
        }

        // Update language settings to current config values
        // This ensures that even old TC Strings get updated with current language
        console.log('Updating decoded TC model with config.language:', this.config.language);
        this.tcModel.consentLanguage = this.config.language;
        this.tcModel.publisherCountryCode = this.config.publisherCountryCode;

        return true;
      }
      return false;
    } catch (error) {
      console.error('Error decoding TC string:', error);
      return false;
    }
  }

  /**
   * Pobiera aktualny model TC
   * @returns Aktualny model TC
   */
  getTCModel(): TCModel {
    return this.tcModel;
  }

  /**
   * Pobiera aktualny stan zgód na podstawie TCModel
   * @returns Stan zgód
   */
  getCurrentConsentState(): ConsentState {
    const state: ConsentState = {
      purposeConsents: {},
      purposeLegitimateInterests: {},
      specialFeatureOptins: {},
      vendorConsents: {},
      vendorLegitimateInterests: {},
      customVendorConsents: {},
      customPurposeConsents: {},
      customPurposeLegitimateInterests: {},
    };

    // Pobieranie zgód celów
    for (const purposeId in this.config.purposes) {
      const purpose = parseInt(purposeId);
      state.purposeConsents[purposeId] = this.tcModel.purposeConsents.has(purpose);
      state.purposeLegitimateInterests[purposeId] =
        this.tcModel.purposeLegitimateInterests.has(purpose);
    }

    // Pobieranie funkcji specjalnych
    for (const featureId in this.config.specialFeatures) {
      const feature = parseInt(featureId);
      state.specialFeatureOptins[featureId] = this.tcModel.specialFeatureOptins.has(feature);
    }

    // Pobieranie zgód vendorów IAB
    for (const vendorId in this.config.vendors.iab) {
      const vendor = parseInt(vendorId);
      state.vendorConsents[vendorId] = this.tcModel.vendorConsents.has(vendor);
      state.vendorLegitimateInterests[vendorId] =
        this.tcModel.vendorLegitimateInterests.has(vendor);
    }

    // Pobieranie customowych zgód - używamy publisherCustomConsents dla customowych zgód
    for (const consent of this.config.customConsents) {
      // Parsuj numer z ID (np. z 'custom-1' bierzemy '1')
      const matches = consent.id.match(/custom-(\d+)/);
      if (matches && matches[1]) {
        const purposeIdNum = parseInt(matches[1]);

        // Pobierz wartość zgody z publisherCustomConsents
        state.customPurposeConsents[consent.id] =
          this.tcModel.publisherCustomConsents.has(purposeIdNum);

        // Jeśli jest dozwolony uzasadniony interes, pobierz jego wartość
        if (consent.legitimateInterestAllowed) {
          state.customPurposeLegitimateInterests[consent.id] =
            this.tcModel.publisherCustomLegitimateInterests.has(purposeIdNum);
        }
      }
    }

    return state;
  }
}

export default TCStringService;
