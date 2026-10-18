import { describe, it, expect } from 'vitest';
import { detectCookieDomain, CookieJar } from './cookie';

/** A jar that behaves like a browser for a small public-suffix list. */
const fakeJar = (publicSuffixes: string[]): CookieJar & { stored: Set<string> } => {
  const stored = new Set<string>();
  return {
    stored,
    get: () => [...stored].join('; '),
    set: (cookie) => {
      const [pair, ...attrs] = cookie.split(';').map((p) => p.trim());
      const domain = attrs
        .find((a) => a.startsWith('domain='))
        ?.slice(8)
        .replace(/^\./, '');
      if (domain && publicSuffixes.includes(domain)) return; // rejected by the browser
      if (attrs.some((a) => a.startsWith('expires=Thu, 01 Jan 1970'))) {
        stored.delete(pair.split('=')[0] + '=1');
        return;
      }
      stored.add(pair);
    },
  };
};

const PSL = ['uk', 'co.uk', 'pl', 'com.pl', 'com', 'io', 'github.io'];

describe('detectCookieDomain', () => {
  it('finds the registrable domain below multi-label public suffixes', () => {
    expect(detectCookieDomain('www.example.co.uk', fakeJar(PSL))).toBe('.example.co.uk');
    expect(detectCookieDomain('sklep.firma.com.pl', fakeJar(PSL))).toBe('.firma.com.pl');
    expect(detectCookieDomain('user.github.io', fakeJar(PSL))).toBe('.user.github.io');
  });

  it('uses the registrable domain for deep subdomains, not host minus one label', () => {
    expect(detectCookieDomain('a.b.wiadomosci.gazeta.pl', fakeJar(PSL))).toBe('.gazeta.pl');
    expect(detectCookieDomain('gazeta.pl', fakeJar(PSL))).toBe('.gazeta.pl');
  });

  it('uses host-only cookies for IPs and single-label hosts', () => {
    expect(detectCookieDomain('127.0.0.1', fakeJar(PSL))).toBeNull();
    expect(detectCookieDomain('localhost', fakeJar(PSL))).toBeNull();
    expect(detectCookieDomain('::1', fakeJar(PSL))).toBeNull();
  });

  it('cleans up the probe cookie', () => {
    const jar = fakeJar(PSL);
    detectCookieDomain('www.example.co.uk', jar);
    expect(jar.stored.size).toBe(0);
  });
});
