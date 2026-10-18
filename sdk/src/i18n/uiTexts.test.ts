import { describe, it, expect, afterEach } from 'vitest';
import { uiText, uiDictionary, setUiLanguage, t } from './uiTexts';

describe('consent box microcopy', () => {
  afterEach(() => setUiLanguage(undefined));

  it('fills placeholders in the requested language', () => {
    expect(uiText('pl', 'partnersPurpose', { count: 12 })).toBe(
      'Partnerzy mogący użyć tego celu: 12',
    );
    expect(uiText('en-GB', 'partnersPurpose', { count: 12 })).toBe(
      'Partners that may use this purpose: 12',
    );
  });

  it('falls back to English for unknown languages', () => {
    expect(uiDictionary('cs').objectionFiled).toBe('Objection filed');
  });

  it('every language defines every key', () => {
    const keys = Object.keys(uiDictionary('en')).sort();
    for (const lang of ['pl', 'de', 'fr']) {
      expect(Object.keys(uiDictionary(lang)).sort()).toEqual(keys);
    }
  });

  it('template overrides win and t() uses the active language', () => {
    setUiLanguage('de', { searchVendors: 'Anbieter finden' });
    expect(t('searchVendors')).toBe('Anbieter finden');
    expect(t('objectionFiled')).toBe('Widerspruch eingelegt');
  });
});
