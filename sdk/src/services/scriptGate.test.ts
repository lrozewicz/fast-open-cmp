import { describe, it, expect, beforeEach } from 'vitest';
import { activateGatedElements, isGranted, parseRequirements } from './scriptGate';
import { ConsentState } from '../models/types';

const state = (over: Partial<ConsentState> = {}): ConsentState => ({
  purposeConsents: { '1': true, '3': false },
  purposeLegitimateInterests: {},
  specialFeatureOptins: {},
  vendorConsents: { '755': true },
  vendorLegitimateInterests: {},
  customVendorConsents: { 'google:55': true },
  customPurposeConsents: { 'custom-2': true },
  customPurposeLegitimateInterests: {},
  ...over,
});

describe('isGranted', () => {
  it('needs every token', () => {
    expect(isGranted(parseRequirements('purpose-1 custom-2'), state(), true)).toBe(true);
    expect(isGranted(parseRequirements('purpose-1, purpose-3'), state(), true)).toBe(false);
    expect(isGranted(parseRequirements('vendor-755 google-55'), state(), true)).toBe(true);
  });

  it('IAB tokens never pass without TCF, unknown tokens never pass', () => {
    expect(isGranted(['purpose-1'], state(), false)).toBe(false);
    expect(isGranted(['custom-2'], state(), false)).toBe(true);
    expect(isGranted(['analytics'], state(), true)).toBe(false);
    expect(isGranted([], state(), true)).toBe(false);
  });
});

describe('activateGatedElements', () => {
  beforeEach(() => {
    document.body.innerHTML = `
      <script type="text/plain" data-cmp-requires="custom-2" id="a" async>window.__a = 1</script>
      <script type="text/plain" data-cmp-requires="purpose-3" id="b">window.__b = 1</script>
      <script type="text/plain" data-cmp-requires="google-55" data-src="https://ads.test/x.js"></script>
      <iframe data-cmp-src="https://video.test/embed" data-cmp-requires="custom-2"></iframe>`;
  });

  it('activates granted scripts and embeds once', () => {
    expect(activateGatedElements(state(), true)).toBe(3);
    const scripts = Array.from(document.querySelectorAll('script:not([type="text/plain"])'));
    expect(scripts.map((s) => (s as HTMLScriptElement).src || s.textContent)).toEqual([
      'window.__a = 1',
      'https://ads.test/x.js',
    ]);
    expect(scripts[0].hasAttribute('async')).toBe(true);
    expect(scripts[0].getAttribute('id')).toBe('a');
    expect(document.querySelector('iframe')!.getAttribute('src')).toBe('https://video.test/embed');
    // second run: nothing new
    expect(activateGatedElements(state(), true)).toBe(0);
  });
});
