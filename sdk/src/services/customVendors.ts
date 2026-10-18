import { CMPConfig } from '../models/types';
import cookieService from './cookie';

/**
 * Nazwa ciasteczka do przechowywania zgód dla customowych vendorów
 */
export const CUSTOM_VENDORS_COOKIE_NAME = 'custom-vendors-consent';

/**
 * Interfejs do przechowywania zgód dla customowych vendorów
 */
export interface CustomVendorsConsent {
  google: Record<string, boolean>;
  other: Record<string, boolean>;
  lastUpdated: number;
}

/**
 * Serwis do obsługi zgód dla customowych vendorów
 */
export class CustomVendorsService {
  private config: CMPConfig | null = null;
  private consents: CustomVendorsConsent = {
    google: {},
    other: {},
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
   * Format: g:43.46.55|o:1.2|t:1769720229228
   */
  private loadConsents(): void {
    const cookie = cookieService.getCookie(CUSTOM_VENDORS_COOKIE_NAME);

    if (cookie) {
      try {
        this.resetConsents();
        this.parseCompactCookie(cookie);
      } catch (error) {
        console.error('Error parsing custom vendors consent cookie:', error);
        this.resetConsents();
      }
    } else {
      this.resetConsents();
    }
  }

  /**
   * Parsuje kompaktowy format: g:43.46.55|o:1.2|t:1769720229228
   */
  private parseCompactCookie(cookie: string): void {
    const sections = cookie.split('|');

    for (const section of sections) {
      const colonIndex = section.indexOf(':');
      if (colonIndex === -1) continue;

      const prefix = section.substring(0, colonIndex);
      const value = section.substring(colonIndex + 1);

      if (prefix === 'g' && value) {
        const ids = value.split('.');
        for (const id of ids) {
          if (id) {
            this.consents.google[id] = true;
          }
        }
      } else if (prefix === 'o' && value) {
        const ids = value.split('.');
        for (const id of ids) {
          if (id) {
            this.consents.other[id] = true;
          }
        }
      } else if (prefix === 't' && value) {
        this.consents.lastUpdated = parseInt(value) || Date.now();
      }
    }
  }

  /**
   * Resetuje zgody do stanu domyślnego (wszystkie wyłączone)
   */
  resetConsents(): void {
    if (!this.config) {
      throw new Error('CustomVendorsService not initialized');
    }

    this.consents = {
      google: {},
      other: {},
      lastUpdated: Date.now(),
    };

    // Inicjalizuj wszystkie zgody dla Google vendorów jako false
    for (const vendorId in this.config.vendors.custom.google) {
      this.consents.google[vendorId] = false;
    }

    // Inicjalizuj wszystkie zgody dla innych vendorów jako false
    for (const vendorId in this.config.vendors.custom.other) {
      this.consents.other[vendorId] = false;
    }
  }

  /**
   * Ustawia wszystkie zgody na włączone
   */
  acceptAll(): void {
    if (!this.config) {
      throw new Error('CustomVendorsService not initialized');
    }

    // Ustaw wszystkie zgody dla Google vendorów jako true
    for (const vendorId in this.config.vendors.custom.google) {
      this.consents.google[vendorId] = true;
    }

    // Ustaw wszystkie zgody dla innych vendorów jako true
    for (const vendorId in this.config.vendors.custom.other) {
      this.consents.other[vendorId] = true;
    }

    this.consents.lastUpdated = Date.now();
  }

  /**
   * Ustawia wszystkie zgody na wyłączone
   */
  rejectAll(): void {
    if (!this.config) {
      throw new Error('CustomVendorsService not initialized');
    }

    // Ustaw wszystkie zgody dla Google vendorów jako false
    for (const vendorId in this.config.vendors.custom.google) {
      this.consents.google[vendorId] = false;
    }

    // Ustaw wszystkie zgody dla innych vendorów jako false
    for (const vendorId in this.config.vendors.custom.other) {
      this.consents.other[vendorId] = false;
    }

    this.consents.lastUpdated = Date.now();
  }

  /**
   * Ustawia zgody na podstawie podanego stanu
   * @param googleConsents - Stan zgód dla Google vendorów
   * @param otherConsents - Stan zgód dla innych vendorów
   */
  setConsents(
    googleConsents: Record<string, boolean>,
    otherConsents: Record<string, boolean>,
  ): void {
    console.log('[CMP v2] setConsents() google:', JSON.stringify(googleConsents));
    console.log('[CMP v2] setConsents() other:', JSON.stringify(otherConsents));
    this.consents.google = { ...googleConsents };
    this.consents.other = { ...otherConsents };
    this.consents.lastUpdated = Date.now();
  }

  /**
   * Pobiera aktualny stan zgód
   * @returns Aktualny stan zgód dla customowych vendorów
   */
  getConsents(): CustomVendorsConsent {
    return { ...this.consents };
  }

  /**
   * Zapisuje zgody w ciasteczku w kompaktowym formacie
   * Format: g:43.46.55|o:1.2|t:1769720229228
   * g: = Google vendor IDs z consent (oddzielone kropką)
   * o: = Other vendor IDs z consent (oddzielone kropką)
   * t: = timestamp
   */
  saveConsents(): void {
    if (!this.config) {
      throw new Error('CustomVendorsService not initialized');
    }

    // Zbierz ID vendorów z consent=true
    const googleIds: string[] = [];
    for (const vendorId in this.consents.google) {
      if (this.consents.google[vendorId] === true) {
        googleIds.push(vendorId);
      }
    }

    const otherIds: string[] = [];
    for (const vendorId in this.consents.other) {
      if (this.consents.other[vendorId] === true) {
        otherIds.push(vendorId);
      }
    }

    // Zbuduj kompaktowy string: g:43.46.55|o:1.2|t:1769720229228
    const parts: string[] = [];
    parts.push(`g:${googleIds.join('.')}`);
    parts.push(`o:${otherIds.join('.')}`);
    parts.push(`t:${this.consents.lastUpdated}`);

    const cookieValue = parts.join('|');

    console.log(
      '[CMP v2] saveConsents() google consented:',
      googleIds.length,
      'other consented:',
      otherIds.length,
    );
    console.log('[CMP v2] saveConsents() cookie value:', cookieValue);

    cookieService.setCookie(
      CUSTOM_VENDORS_COOKIE_NAME,
      cookieValue,
      this.config.cookieExpirationDays,
    );
  }
}

export default new CustomVendorsService();
