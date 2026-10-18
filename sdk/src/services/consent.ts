import {
  CMPConfig,
  ConsentState,
  ConsentAction,
  CMPEventType,
  CMPEventDetail,
} from '../models/types';
import cookieService from './cookie';
import TCStringService from './tcString';
import customVendorsService, { CUSTOM_VENDORS_COOKIE_NAME } from './customVendors';
import { readCustomPurposes, writeCustomPurposes, clearCustomPurposes } from './customPurposeStore';
import { renewalReason, RenewalReason } from './renewal';

// Nazwa ciasteczka do przechowywania reconsentID
export const RECONSENT_ID_COOKIE_NAME = 'cmp-reconsent-id';

export class ConsentService {
  private config: CMPConfig | null = null;
  private tcStringService: TCStringService | null = null;
  private consentState: ConsentState | null = null;

  /**
   * Inicjalizuje ConsetnService z konfiguracją CMP
   * @param config - Konfiguracja CMP
   */
  init(config: CMPConfig): void {
    this.config = config;
    this.tcStringService = new TCStringService(config);

    // Inicjalizacja serwisu customowych vendorów
    customVendorsService.init(config);

    // Próba odczytania istniejącego TC String z ciasteczka
    const existingTCString = cookieService.getCookie(config.cookieName);

    if (existingTCString) {
      // Jeśli istnieje, dekoduj i zaktualizuj stan
      const success = this.tcStringService.decodeTCString(existingTCString);

      if (success) {
        // Pobierz aktualny stan zgód bezpośrednio z TCModel
        this.consentState = this.tcStringService.getCurrentConsentState();
      } else {
        // Jeśli dekodowanie się nie powiodło, zainicjuj domyślny stan
        this.tcStringService.resetConsents();
        this.consentState = this.tcStringService.getCurrentConsentState();
      }
    } else {
      // Jeśli nie istnieje, zainicjuj domyślny stan
      this.tcStringService.resetConsents();
      this.consentState = this.tcStringService.getCurrentConsentState();
    }

    // Uzupełnij consentState o customowe vendory - pobierz z osobnego serwisu
    this.loadCustomVendorConsents();

    if (!this.isTcfMode()) {
      // Without TCF there is no TC String: IAB consents stay empty, custom consents
      // come from their own cookie. A TC cookie left over from a TCF template would
      // only confuse vendors reading the cookie directly, so drop it.
      if (existingTCString) {
        cookieService.deleteCookie(config.cookieName);
      }
      this.tcStringService.resetConsents();
      this.consentState = {
        ...this.tcStringService.getCurrentConsentState(),
        customVendorConsents: this.consentState?.customVendorConsents || {},
      };
      this.loadCustomPurposeConsents();
    }
  }

  /** True when the template runs the full TCF mode (the only mode with a TC String). */
  isTcfMode(): boolean {
    return (this.config?.mode || 'tcf_gcm') === 'tcf_gcm';
  }

  /** Non-TCF modes: loads custom consents (and LI) from their cookie into the state. */
  private loadCustomPurposeConsents(): void {
    if (!this.consentState || !this.config) return;
    const stored = readCustomPurposes();
    for (const consent of this.config.customConsents) {
      this.consentState.customPurposeConsents[consent.id] = !!stored?.consents[consent.id];
      this.consentState.customPurposeLegitimateInterests[consent.id] = stored
        ? !!stored.legitimateInterests[consent.id]
        : consent.legitimateInterestAllowed;
    }
  }

  /**
   * Ładuje zgody customowych vendorów do stanu zgód
   */
  private loadCustomVendorConsents(): void {
    if (!this.consentState) {
      return;
    }

    // Pobierz zgody customowych vendorów z serwisu
    const customVendorConsents = customVendorsService.getConsents();

    // Przekształć zgody Google vendorów do mapy z prefiksem "google:ID"
    for (const vendorId in customVendorConsents.google) {
      const prefixedId = `google:${vendorId}`;
      this.consentState.customVendorConsents[prefixedId] = customVendorConsents.google[vendorId];
    }

    // Przekształć zgody innych vendorów do mapy z prefiksem "other:ID"
    for (const vendorId in customVendorConsents.other) {
      const prefixedId = `other:${vendorId}`;
      this.consentState.customVendorConsents[prefixedId] = customVendorConsents.other[vendorId];
    }
  }

  /**
   * Zapisuje zgody customowych vendorów z consentState do serwisu
   */
  private saveCustomVendorConsents(): void {
    if (!this.consentState || !this.config) {
      return;
    }

    const googleConsents: Record<string, boolean> = {};
    const otherConsents: Record<string, boolean> = {};

    // Rozdziel customowe vendory (prefiksowane "google:ID" / "other:ID") na Google i innych
    for (const prefixedId in this.consentState.customVendorConsents) {
      if (prefixedId.startsWith('google:')) {
        const vendorId = prefixedId.substring(7);
        googleConsents[vendorId] = this.consentState.customVendorConsents[prefixedId];
      } else if (prefixedId.startsWith('other:')) {
        const vendorId = prefixedId.substring(6);
        otherConsents[vendorId] = this.consentState.customVendorConsents[prefixedId];
      }
    }

    // Ustaw zgody w serwisie
    customVendorsService.setConsents(googleConsents, otherConsents);

    // Zapisz zgody w ciasteczku
    customVendorsService.saveConsents();
  }

  /**
   * Sprawdza, czy użytkownik udzielił już zgód (ma zapisane ciasteczko)
   * @returns True, jeśli użytkownik udzielił już zgód, false w przeciwnym razie
   */
  hasUserConsent(): boolean {
    if (!this.config) {
      throw new Error('ConsentService not initialized');
    }

    if (!this.isTcfMode()) {
      return readCustomPurposes() !== null;
    }

    const cookie = cookieService.getCookie(this.config.cookieName);
    console.log('Checking for cookie:', this.config.cookieName, 'Result:', cookie);
    return !!cookie;
  }

  /**
   * Przetwarza akcję zgody użytkownika
   * @param action - Akcja zgody (ACCEPT_ALL, REJECT_ALL, SAVE_PREFERENCES)
   * @param state - Stan zgód (wymagany tylko dla SAVE_PREFERENCES)
   * @returns TC String wygenerowany po przetworzeniu akcji lub null w przypadku błędu
   */
  processConsentAction(action: ConsentAction, state?: ConsentState): string | null {
    if (!this.config || !this.tcStringService) {
      throw new Error('ConsentService not initialized');
    }

    console.log('ConsentService.processConsentAction:', action);

    if (state) {
      console.log('State provided:', state);
      console.log('Custom purpose consents:', state.customPurposeConsents);
      console.log('Custom vendor consents:', state.customVendorConsents);
    }

    if (!this.isTcfMode()) {
      return this.processNonTcfAction(action, state);
    }

    // Obsługa customowych vendorów w osobnym serwisie
    // WAŻNE: Zapisujemy ciasteczko custom vendorów PRZED operacjami na TCString,
    // aby błąd w generowaniu TC String nie blokował zapisu zgód custom vendorów.
    switch (action) {
      case ConsentAction.ACCEPT_ALL:
        // Akceptuj wszystkie customowe vendory i zapisz ciasteczko
        customVendorsService.acceptAll();
        customVendorsService.saveConsents();

        // Upewnij się, że customowe zgody są ustawione w stanie
        if (this.consentState) {
          // Akceptuj wszystkie cele customowe
          for (const consent of this.config.customConsents) {
            this.consentState.customPurposeConsents[consent.id] = true;
          }

          // Zaktualizuj również tcModel tylko dla customowych celów (nie vendorów)
          this.tcStringService.setConsentsFromState(this.consentState);
        }

        // Akceptuj wszystkie zgody IAB - teraz tylko w TCString
        this.tcStringService.acceptAll();
        break;

      case ConsentAction.REJECT_ALL:
        // Odrzuć wszystkie customowe vendory i zapisz ciasteczko
        customVendorsService.rejectAll();
        customVendorsService.saveConsents();

        // Odrzuć wszystkie zgody IAB - teraz tylko w TCString
        this.tcStringService.rejectAll();
        break;

      case ConsentAction.SAVE_PREFERENCES:
        if (!state) {
          throw new Error('Consent state is required for SAVE_PREFERENCES action');
        }

        // Podziel customVendorConsents (prefiksowane "google:ID" / "other:ID") na Google i innych
        console.log(
          '[CMP v2] SAVE_PREFERENCES customVendorConsents:',
          JSON.stringify(state.customVendorConsents),
        );
        if (state.customVendorConsents) {
          const googleConsents: Record<string, boolean> = {};
          const otherConsents: Record<string, boolean> = {};

          for (const prefixedId in state.customVendorConsents) {
            if (prefixedId.startsWith('google:')) {
              const vendorId = prefixedId.substring(7); // "google:".length === 7
              googleConsents[vendorId] = state.customVendorConsents[prefixedId];
            } else if (prefixedId.startsWith('other:')) {
              const vendorId = prefixedId.substring(6); // "other:".length === 6
              otherConsents[vendorId] = state.customVendorConsents[prefixedId];
            }
          }

          // Ustaw zgody w serwisie customowych vendorów i zapisz ciasteczko PRZED operacjami na TCString
          customVendorsService.setConsents(googleConsents, otherConsents);
          customVendorsService.saveConsents();
        }

        // Ustawienie zgód w tcModel (bez customowych vendorów)
        this.tcStringService.setConsentsFromState(state);
        break;
    }

    try {
      // Aktualizacja stanu zgód z TCModel
      const tcModelState = this.tcStringService.getCurrentConsentState();

      // Połącz z customowymi vendorami
      this.consentState = {
        ...tcModelState,
        customVendorConsents: {},
      };

      // Dodaj zgody customowych vendorów do stanu
      this.loadCustomVendorConsents();

      // Generowanie i zapisanie TC String
      const tcString = this.tcStringService.generateTCString();
      this.saveTCString(tcString);

      // Emitowanie zdarzenia zmiany zgody
      this.dispatchConsentEvent(CMPEventType.CONSENT_CHANGED, { action });

      return tcString;
    } catch (error) {
      console.error('Failed to generate TC String:', error);
      // Emitowanie zdarzenia błędu
      this.dispatchConsentEvent(CMPEventType.CONSENT_ERROR, { error, action });
      return null;
    }
  }

  /**
   * gcm_only / basic: stores custom consents and custom vendors without any TC
   * String, IAB purpose or IAB vendor consent. In basic mode no vendor list is
   * shown, so "accept all" does not consent to vendors the user never saw.
   */
  private processNonTcfAction(action: ConsentAction, state?: ConsentState): null {
    const config = this.config as CMPConfig;
    const mode = config.mode;
    const consents: Record<string, boolean> = {};
    const legitimateInterests: Record<string, boolean> = {};

    switch (action) {
      case ConsentAction.ACCEPT_ALL:
        if (mode === 'gcm_only') {
          customVendorsService.acceptAll();
        } else {
          customVendorsService.rejectAll();
        }
        for (const c of config.customConsents) {
          consents[c.id] = true;
          legitimateInterests[c.id] = c.legitimateInterestAllowed;
        }
        break;

      case ConsentAction.REJECT_ALL:
        customVendorsService.rejectAll();
        for (const c of config.customConsents) {
          consents[c.id] = false;
          legitimateInterests[c.id] = false;
        }
        break;

      case ConsentAction.SAVE_PREFERENCES: {
        if (!state) {
          throw new Error('Consent state is required for SAVE_PREFERENCES action');
        }
        const google: Record<string, boolean> = {};
        const other: Record<string, boolean> = {};
        for (const prefixedId in state.customVendorConsents || {}) {
          const [type, id] = prefixedId.split(':');
          if (type === 'google') google[id] = state.customVendorConsents[prefixedId];
          else if (type === 'other') other[id] = state.customVendorConsents[prefixedId];
        }
        customVendorsService.setConsents(google, other);
        for (const c of config.customConsents) {
          consents[c.id] = !!state.customPurposeConsents[c.id];
          legitimateInterests[c.id] =
            c.legitimateInterestAllowed && !!state.customPurposeLegitimateInterests?.[c.id];
        }
        break;
      }
    }

    customVendorsService.saveConsents();
    writeCustomPurposes(consents, legitimateInterests, config.cookieExpirationDays);
    if (config.reconsentID) {
      this.saveReconsentId(config.reconsentID);
    }

    this.tcStringService?.resetConsents();
    this.consentState = {
      ...(this.tcStringService as TCStringService).getCurrentConsentState(),
      customVendorConsents: {},
      customPurposeConsents: consents,
      customPurposeLegitimateInterests: legitimateInterests,
    };
    this.loadCustomVendorConsents();

    this.dispatchConsentEvent(CMPEventType.CONSENT_CHANGED, { action });
    return null;
  }

  /**
   * Zapisuje TC String w ciasteczku
   * @param tcString - TC String do zapisania
   */
  private saveTCString(tcString: string): void {
    if (!this.config) {
      throw new Error('ConsentService not initialized');
    }

    cookieService.setCookie(this.config.cookieName, tcString, this.config.cookieExpirationDays);

    // Zapisz reconsentID w ciasteczku
    if (this.config.reconsentID) {
      this.saveReconsentId(this.config.reconsentID);
    }
  }

  /**
   * Zapisuje reconsentID w ciasteczku
   * @param reconsentId - ID reconsentu do zapisania
   */
  private saveReconsentId(reconsentId: number): void {
    if (!this.config) {
      throw new Error('ConsentService not initialized');
    }

    cookieService.setCookie(
      RECONSENT_ID_COOKIE_NAME,
      reconsentId.toString(),
      this.config.cookieExpirationDays,
    );
  }

  /**
   * Pobiera zapisany reconsentID z ciasteczka
   * @returns Zapisany reconsentID lub null, jeśli nie istnieje
   */
  private getReconsentId(): number | null {
    const reconsentId = cookieService.getCookie(RECONSENT_ID_COOKIE_NAME);
    return reconsentId ? parseInt(reconsentId) : null;
  }

  /**
   * Sprawdza, czy wymagany jest reconsent
   * @returns True, jeśli reconsent jest wymagany, false w przeciwnym razie
   */
  isReconsentRequired(): boolean {
    if (!this.config) {
      return false;
    }

    const reason = this.getRenewalReason();
    if (reason) {
      console.log('Stored consent needs renewal:', reason);
      return true;
    }

    if (!this.config.reconsentID) {
      return false;
    }

    const savedReconsentId = this.getReconsentId();

    // Jeśli nie ma zapisanego reconsentID, a istnieje zgoda, to wymagany jest reconsent
    if (savedReconsentId === null && this.hasUserConsent()) {
      return true;
    }

    // Jeśli zapisany reconsentID jest starszy niż aktualny, to wymagany jest reconsent
    return savedReconsentId !== null && savedReconsentId < this.config.reconsentID;
  }

  /**
   * Why the stored decision has to be collected again (policy version, age, new
   * vendors) — see renewal.ts — or null when it is still valid / there is none.
   */
  getRenewalReason(): RenewalReason | null {
    if (!this.config || !this.hasUserConsent()) {
      return null;
    }
    const tcf = this.isTcfMode();
    const currentVendors = Object.values(this.config.vendors?.iab || {})
      .filter((v) => !v.deletedDate)
      .map((v) => v.id);

    if (tcf && this.tcStringService) {
      const model = this.tcStringService.getTCModel();
      const disclosed: number[] = [];
      model.vendorsDisclosed.forEach((value, id) => {
        if (value) disclosed.push(id);
      });
      return renewalReason({
        tcf,
        storedPolicyVersion: Number(model.policyVersion) || undefined,
        currentPolicyVersion: this.config.tcfPolicyVersion,
        lastDecision: model.lastUpdated ? model.lastUpdated.getTime() : undefined,
        maxAgeDays: this.config.consentMaxAgeDays,
        disclosedVendors: disclosed,
        currentVendors,
        renewOnNewVendors: this.config.renewOnNewVendors,
        now: Date.now(),
      });
    }

    return renewalReason({
      tcf: false,
      lastDecision: readCustomPurposes()?.timestamp,
      maxAgeDays: this.config.consentMaxAgeDays,
      now: Date.now(),
    });
  }

  /**
   * Usuwa ciasteczka zgód i wymusza ponowne wyrażenie zgody
   */
  clearConsents(): void {
    if (!this.config) {
      throw new Error('ConsentService not initialized');
    }

    // Usuń ciasteczko TC String
    cookieService.deleteCookie(this.config.cookieName);

    // Usuń ciasteczko customowych vendorów
    cookieService.deleteCookie(CUSTOM_VENDORS_COOKIE_NAME);

    // Usuń ciasteczko reconsentID
    cookieService.deleteCookie(RECONSENT_ID_COOKIE_NAME);

    // Usuń zgody niestandardowe trybów bez TCF
    clearCustomPurposes();

    // Additional Consent string belongs to the cleared decision too
    try {
      localStorage.removeItem('IABTCF_AddtlConsent');
    } catch {
      // storage unavailable — nothing to clear
    }

    // Resetuj stan zgód
    this.tcStringService?.resetConsents();
    this.consentState = this.tcStringService?.getCurrentConsentState() || null;

    console.log('Ciasteczka zgód zostały usunięte - wymagany reconsent');
  }

  /**
   * Sprawdza, czy wymagany jest reconsent i w razie potrzeby usuwa ciasteczka zgód
   * @returns True, jeśli reconsent jest wymagany i ciasteczka zostały usunięte
   */
  checkAndClearConsentsIfNeeded(): boolean {
    if (this.isReconsentRequired()) {
      this.clearConsents();
      return true;
    }
    return false;
  }

  /**
   * Pobiera aktualny stan zgód
   * @returns Aktualny stan zgód
   */
  getConsentState(): ConsentState {
    if (!this.consentState) {
      throw new Error('ConsentService not initialized');
    }

    // Głęboka kopia - zapobiega współdzieleniu referencji między ConsentManager a ConsentService
    return {
      purposeConsents: { ...this.consentState.purposeConsents },
      purposeLegitimateInterests: { ...this.consentState.purposeLegitimateInterests },
      specialFeatureOptins: { ...this.consentState.specialFeatureOptins },
      vendorConsents: { ...this.consentState.vendorConsents },
      vendorLegitimateInterests: { ...this.consentState.vendorLegitimateInterests },
      customVendorConsents: { ...this.consentState.customVendorConsents },
      customPurposeConsents: { ...this.consentState.customPurposeConsents },
      customPurposeLegitimateInterests: { ...this.consentState.customPurposeLegitimateInterests },
    };
  }

  /**
   * Pobiera TC String
   * @returns TC String lub null, jeśli nie ma zapisanego TC String
   */
  getTCString(): string | null {
    if (!this.config) {
      throw new Error('ConsentService not initialized');
    }

    if (!this.isTcfMode()) {
      return null;
    }

    return cookieService.getCookie(this.config.cookieName);
  }

  /**
   * Emituje zdarzenie związane z CMP
   * @param type - Typ zdarzenia
   * @param detail - Szczegóły zdarzenia
   */
  dispatchConsentEvent(type: CMPEventType, detail?: Partial<CMPEventDetail>): void {
    const eventDetail: CMPEventDetail = {
      type,
      ...detail,
    };

    const event = new CustomEvent(type, {
      detail: eventDetail,
      bubbles: true,
      cancelable: true,
    });

    document.dispatchEvent(event);
  }
}

export default new ConsentService();
