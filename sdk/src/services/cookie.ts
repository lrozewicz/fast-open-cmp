/** Minimal cookie jar interface (document.cookie), injectable for tests. */
export interface CookieJar {
  get(): string;
  set(cookie: string): void;
}

const documentJar: CookieJar = {
  get: () => document.cookie,
  set: (cookie) => {
    document.cookie = cookie;
  },
};

const PROBE = '__cmp_domain_probe';

/**
 * Finds the registrable domain to scope consent cookies to (so they are shared
 * by www/subdomains), e.g. www.news.example.co.uk → .example.co.uk.
 *
 * Tries a probe cookie on ever longer suffixes of the host: the browser refuses
 * cookies for public suffixes (co.uk, com.pl, github.io, …), so the first suffix
 * that accepts it is the registrable domain. IP addresses and single-label hosts
 * (localhost) get host-only cookies (null).
 */
export const detectCookieDomain = (
  hostname: string,
  jar: CookieJar = documentJar,
  secure = false,
): string | null => {
  if (!hostname || !hostname.includes('.') || hostname.includes(':')) return null;
  if (/^\d{1,3}(\.\d{1,3}){3}$/.test(hostname)) return null;

  const parts = hostname.split('.');
  const attrs = `; path=/; SameSite=Lax${secure ? '; secure' : ''}`;
  for (let i = parts.length - 2; i >= 0; i--) {
    const domain = '.' + parts.slice(i).join('.');
    jar.set(`${PROBE}=1; domain=${domain}${attrs}`);
    if (
      jar
        .get()
        .split(';')
        .some((c) => c.trim() === `${PROBE}=1`)
    ) {
      jar.set(`${PROBE}=; expires=Thu, 01 Jan 1970 00:00:00 GMT; domain=${domain}${attrs}`);
      return domain;
    }
  }
  return null;
};

/**
 * Klasa do zarządzania ciasteczkami
 */
export class CookieService {
  private cookieDomain: string | null | undefined;

  /** Registrable domain of the current page (cached), or null for host-only cookies. */
  private getCookieDomain(): string | null {
    if (this.cookieDomain === undefined) {
      this.cookieDomain = detectCookieDomain(
        window.location.hostname,
        documentJar,
        window.location.protocol === 'https:',
      );
    }
    return this.cookieDomain;
  }

  /**
   * Pobiera wartość ciasteczka o podanej nazwie
   * @param name - Nazwa ciasteczka
   * @returns Wartość ciasteczka lub null jeśli nie znaleziono
   */
  getCookie(name: string): string | null {
    const cookies = document.cookie.split(';');
    const cookieName = `${name}=`;

    for (let i = 0; i < cookies.length; i++) {
      const cookie = cookies[i].trim();
      if (cookie.indexOf(cookieName) === 0) {
        return cookie.substring(cookieName.length, cookie.length);
      }
    }

    return null;
  }

  /**
   * Ustawia ciasteczko
   * @param name - Nazwa ciasteczka
   * @param value - Wartość ciasteczka
   * @param expirationDays - Liczba dni, po których ciasteczko wygaśnie
   * @param path - Ścieżka ciasteczka
   * @param domain - Domena ciasteczka. Jeśli nie podana, zostanie użyta domena główna (usuwa subdomenę)
   * @param secure - Czy ciasteczko ma być wysyłane tylko przez HTTPS
   */
  setCookie(
    name: string,
    value: string,
    expirationDays: number = 180,
    path: string = '/',
    domain?: string,
    secure?: boolean,
  ): void {
    const date = new Date();
    date.setTime(date.getTime() + expirationDays * 24 * 60 * 60 * 1000);

    let cookieStr = `${name}=${value}; expires=${date.toUTCString()}; path=${path}`;

    // Jeśli domena nie jest podana, użyj domeny rejestrowalnej (wspólnej dla subdomen)
    if (!domain) {
      domain = this.getCookieDomain() || undefined;
    }

    // Dodajemy domenę do ciasteczka, aby było widoczne dla wszystkich subdomen
    if (domain) {
      cookieStr += `; domain=${domain}`;
    }

    // Auto-detect secure flag based on protocol (HTTPS = secure, HTTP = not secure)
    const isSecure = secure !== undefined ? secure : window.location.protocol === 'https:';
    if (isSecure) {
      cookieStr += '; secure';
    }

    cookieStr += '; SameSite=Lax';

    document.cookie = cookieStr;
  }

  /**
   * Usuwa ciasteczko
   * @param name - Nazwa ciasteczka
   * @param path - Ścieżka ciasteczka
   * @param domain - Domena ciasteczka. Jeśli nie podana, zostanie użyta domena główna
   */
  deleteCookie(name: string, path: string = '/', domain?: string): void {
    // Używamy tych samych zasad dla domeny jak przy ustawianiu ciasteczka
    this.setCookie(name, '', -1, path, domain, false);

    if (!domain) {
      // Also remove host-only copies and ones written by older versions, which
      // scoped cookies to the host minus its first label (www.a.b.pl → .a.b.pl).
      const expired = `${name}=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=${path}`;
      document.cookie = expired;
      const parts = window.location.hostname.split('.');
      if (parts.length > 2) {
        document.cookie = `${expired}; domain=.${parts.slice(1).join('.')}`;
      }
    }
  }
}

export default new CookieService();
