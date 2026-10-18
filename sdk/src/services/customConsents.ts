import { CMPConfig } from '../models/types';
import cookieService from './cookie';

/**
 * Nazwa ciasteczka do przechowywania customowych zgód
 */
const CUSTOM_CONSENTS_COOKIE_NAME = 'custom-purpose-consents';

/**
 * Interfejs do przechowywania customowych zgód
 */
export interface CustomConsentsData {
  consents: Record<string, boolean>;
  legitimateInterests: Record<string, boolean>;
  lastUpdated: number;
}

/**
 * Serwis do obsługi customowych zgód
 */
export class CustomConsentsService {
  private config: CMPConfig | null = null;
  private consentsData: CustomConsentsData = {
    consents: {},
    legitimateInterests: {},
    lastUpdated: Date.now(),
  };

  /**
   * Inicjalizuje serwis z konfiguracją CMP
   * @param config - Konfiguracja CMP
   */
  init(config: CMPConfig): void {
    this.config = config;
    this.loadConsents();
  }

  /**
   * Ładuje zgody z ciasteczka
   */
  private loadConsents(): void {
    const cookie = cookieService.getCookie(CUSTOM_CONSENTS_COOKIE_NAME);

    if (cookie) {
      try {
        this.consentsData = JSON.parse(cookie);
        console.log('Loaded custom consents from cookie:', this.consentsData);
      } catch (error) {
        console.error('Error parsing custom consents cookie:', error);
        this.resetConsents();
      }
    } else {
      this.resetConsents();
    }
  }

  /**
   * Resetuje zgody do stanu domyślnego (wszystkie wyłączone)
   */
  resetConsents(): void {
    if (!this.config) {
      throw new Error('CustomConsentsService not initialized');
    }

    this.consentsData = {
      consents: {},
      legitimateInterests: {},
      lastUpdated: Date.now(),
    };

    // Inicjalizuj wszystkie customowe zgody jako false
    for (const consent of this.config.customConsents) {
      this.consentsData.consents[consent.id] = false;

      if (consent.legitimateInterestAllowed) {
        this.consentsData.legitimateInterests[consent.id] = false;
      }
    }
  }

  /**
   * Ustawia wszystkie zgody na włączone
   */
  acceptAll(): void {
    if (!this.config) {
      throw new Error('CustomConsentsService not initialized');
    }

    // Włącz wszystkie customowe zgody
    for (const consent of this.config.customConsents) {
      this.consentsData.consents[consent.id] = true;

      if (consent.legitimateInterestAllowed) {
        this.consentsData.legitimateInterests[consent.id] = true;
      }
    }

    this.consentsData.lastUpdated = Date.now();
    this.saveConsents();
  }

  /**
   * Ustawia wszystkie zgody na wyłączone
   */
  rejectAll(): void {
    if (!this.config) {
      throw new Error('CustomConsentsService not initialized');
    }

    // Wyłącz wszystkie customowe zgody
    for (const consent of this.config.customConsents) {
      this.consentsData.consents[consent.id] = false;

      if (consent.legitimateInterestAllowed) {
        this.consentsData.legitimateInterests[consent.id] = false;
      }
    }

    this.consentsData.lastUpdated = Date.now();
    this.saveConsents();
  }

  /**
   * Ustawia zgody na podstawie podanego stanu
   * @param consents - Stan zgód
   * @param legitimateInterests - Stan uzasadnionych interesów
   */
  setConsents(
    consents: Record<string, boolean>,
    legitimateInterests: Record<string, boolean>,
  ): void {
    this.consentsData.consents = { ...consents };
    this.consentsData.legitimateInterests = { ...legitimateInterests };
    this.consentsData.lastUpdated = Date.now();
    this.saveConsents();
  }

  /**
   * Pobiera aktualny stan zgód
   * @returns Aktualny stan customowych zgód
   */
  getConsents(): CustomConsentsData {
    return { ...this.consentsData };
  }

  /**
   * Zapisuje zgody w ciasteczku
   */
  saveConsents(): void {
    if (!this.config) {
      throw new Error('CustomConsentsService not initialized');
    }

    console.log('Saving custom consents to cookie:', this.consentsData);

    cookieService.setCookie(
      CUSTOM_CONSENTS_COOKIE_NAME,
      JSON.stringify(this.consentsData),
      this.config.cookieExpirationDays,
    );
  }
}

export default new CustomConsentsService();
