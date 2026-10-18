import { describe, it, expect } from 'vitest';
import { resolveFirstLayer, resolveAppearance, resolveFontFamily, formatText } from './appearance';
import { CMPConfig } from '../models/types';

const texts = {
  firstLayer: {
    title: 'T',
    description: 'D',
    partnersLinkText: 'Partnerzy',
    settingsButtonText: 'Ustawienia',
    acceptAllButtonText: 'Akceptuję',
    rejectAllButtonText: 'Odrzucam',
  },
  secondLayer: { rejectButtonText: 'Nie akceptuję' },
};

const cfg = (over: Partial<CMPConfig> = {}): CMPConfig =>
  ({ mode: 'tcf_gcm', texts, ...over }) as unknown as CMPConfig;

const actions = (c: CMPConfig) => resolveFirstLayer(c).buttons.map((b) => b.action);

describe('resolveFirstLayer — certified profile (default)', () => {
  it('defaults to settings + reject + accept when nothing is configured', () => {
    const r = resolveFirstLayer(cfg());
    expect(r.profile).toBe('certified');
    expect(r.buttons.map((b) => [b.action, b.variant, b.label])).toEqual([
      ['settings', 'secondary', 'Ustawienia'],
      ['reject-all', 'primary', 'Odrzucam'],
      ['accept-all', 'primary', 'Akceptuję'],
    ]);
  });

  it('adds a missing reject button next to accept and equalises its weight', () => {
    const r = resolveFirstLayer(
      cfg({
        firstLayer: {
          buttons: [
            { action: 'settings', variant: 'secondary' },
            { action: 'accept-all', variant: 'primary' },
          ],
        },
      }),
    );
    expect(r.buttons.map((b) => b.action)).toEqual(['settings', 'reject-all', 'accept-all']);
    expect(r.buttons.find((b) => b.action === 'reject-all')?.variant).toBe('primary');
    expect(r.adjustments).toContain('added reject-all');
  });

  it('does not allow reject as a link while accept is a button', () => {
    const r = resolveFirstLayer(
      cfg({
        firstLayer: {
          buttons: [
            { action: 'reject-all', variant: 'link' },
            { action: 'accept-all', variant: 'secondary' },
          ],
        },
      }),
    );
    const v = Object.fromEntries(r.buttons.map((b) => [b.action, b.variant]));
    expect(v['reject-all']).toBe('secondary');
    expect(v['accept-all']).toBe('secondary');
    expect(r.buttons.some((b) => b.action === 'settings')).toBe(true);
  });

  it('forces the TCF summary on in tcf_gcm', () => {
    expect(resolveFirstLayer(cfg({ firstLayer: { showTcfSummary: false } })).showTcfSummary).toBe(
      true,
    );
    expect(resolveFirstLayer(cfg({ mode: 'basic' })).showTcfSummary).toBe(false);
  });
});

describe('resolveFirstLayer — custom profile', () => {
  it('renders the publisher setup as configured (no reject added)', () => {
    const c = cfg({
      complianceProfile: 'custom',
      firstLayer: {
        buttons: [
          { action: 'settings', variant: 'link', label: 'Więcej opcji' },
          { action: 'accept-all', variant: 'primary' },
        ],
        showTcfSummary: false,
      },
    });
    const r = resolveFirstLayer(c);
    expect(r.buttons.map((b) => [b.action, b.variant, b.label])).toEqual([
      ['settings', 'link', 'Więcej opcji'],
      ['accept-all', 'primary', 'Akceptuję'],
    ]);
    expect(r.showTcfSummary).toBe(false);
    expect(r.adjustments).toEqual([]);
  });

  it('drops duplicates, unknown actions, and partners in basic mode', () => {
    const c = cfg({
      mode: 'basic',
      complianceProfile: 'custom',
      firstLayer: {
        buttons: [
          { action: 'accept-all' },
          { action: 'accept-all' },
          { action: 'partners' },
          { action: 'nope' as never },
        ],
      },
    });
    expect(actions(c)).toEqual(['accept-all']);
  });

  it('never leaves the box without buttons', () => {
    const c = cfg({ complianceProfile: 'custom', firstLayer: { buttons: [] } });
    expect(actions(c)).toEqual(['settings', 'reject-all', 'accept-all']);
  });

  it('supports a close (×) button that rejects', () => {
    expect(resolveFirstLayer(cfg({ firstLayer: { closeButton: 'reject-all' } })).closeButton).toBe(
      'reject-all',
    );
    expect(resolveFirstLayer(cfg()).closeButton).toBe('none');
  });
});

describe('resolveAppearance', () => {
  it('fills defaults', () => {
    const a = resolveAppearance(undefined);
    expect(a.layout).toBe('modal');
    expect(a.overlay).toBe(true);
    expect(a.fontFamily).toBe('inherit');
  });

  it('rejects values that could inject CSS or scripts', () => {
    const a = resolveAppearance({
      colors: { primary: 'red;} body{display:none', text: '#0a0' },
      logoUrl: 'javascript:alert(1)',
      fontFamily: 'x;}*{color:red',
      customCss: '</style><script>alert(1)</script>',
    });
    expect(a.colors.primary).toBe('#1a1a1a');
    expect(a.colors.text).toBe('#0a0');
    expect(a.logoUrl).toBe('');
    expect(a.fontFamily).toBe('inherit');
    expect(a.customCss).not.toContain('<');
  });

  it('bar and corner layouts have no overlay unless asked', () => {
    expect(resolveAppearance({ layout: 'bar' }).overlay).toBe(false);
    expect(resolveAppearance({ layout: 'corner', overlay: true }).overlay).toBe(true);
  });

  it('clamps radii', () => {
    expect(resolveAppearance({ borderRadius: 999, buttonRadius: -4 }).borderRadius).toBe(32);
    expect(resolveAppearance({ buttonRadius: -4 }).buttonRadius).toBe(0);
  });
});

describe('helpers', () => {
  it('maps font keywords', () => {
    expect(resolveFontFamily('system')).toContain('system-ui');
    expect(resolveFontFamily("'Open Sans', sans-serif")).toBe("'Open Sans', sans-serif");
  });

  it('formats placeholders', () => {
    expect(formatText('{iab} IAB i {other} innych', { iab: 3, other: 2 })).toBe('3 IAB i 2 innych');
    expect(formatText('{missing}', {})).toBe('{missing}');
  });
});
