import {
  AppearanceConfig,
  ButtonVariant,
  CMPConfig,
  ComplianceProfile,
  FirstLayerAction,
  FirstLayerButton,
} from '../models/types';

/** A first-layer button after defaults and the compliance profile are applied. */
export interface ResolvedButton {
  action: FirstLayerAction;
  variant: ButtonVariant;
  label: string;
}

export interface ResolvedFirstLayer {
  profile: ComplianceProfile;
  buttons: ResolvedButton[];
  closeButton: 'none' | 'reject-all';
  showTcfSummary: boolean;
  /** Human-readable notes about what the certified profile changed (for debugging/admin). */
  adjustments: string[];
}

export interface ResolvedAppearance {
  layout: 'modal' | 'bar' | 'corner';
  logoUrl: string;
  logoAlt: string;
  colors: {
    primary: string;
    primaryText: string;
    background: string;
    text: string;
    overlay: string;
  };
  borderRadius: number;
  buttonRadius: number;
  fontFamily: string;
  uppercaseButtons: boolean;
  overlay: boolean;
  blockScroll: boolean;
  reopenButton: { enabled: boolean; position: 'bottom-left' | 'bottom-right' };
  privacyPolicyUrl: string;
  customCss: string;
}

const ACTIONS: FirstLayerAction[] = ['accept-all', 'reject-all', 'settings', 'partners'];
const VARIANTS: ButtonVariant[] = ['primary', 'secondary', 'link'];

/** Layout used when a template does not configure buttons (Gazeta-like). */
export const DEFAULT_FIRST_LAYER_BUTTONS: FirstLayerButton[] = [
  { action: 'settings', variant: 'secondary' },
  { action: 'reject-all', variant: 'primary' },
  { action: 'accept-all', variant: 'primary' },
];

export const DEFAULT_APPEARANCE: ResolvedAppearance = {
  layout: 'modal',
  logoUrl: '',
  logoAlt: '',
  colors: {
    primary: '#1a1a1a',
    primaryText: '#ffffff',
    background: '#ffffff',
    text: '#333333',
    overlay: 'rgba(0, 0, 0, 0.5)',
  },
  borderRadius: 4,
  buttonRadius: 0,
  fontFamily: 'inherit',
  uppercaseButtons: true,
  overlay: true,
  blockScroll: false,
  reopenButton: { enabled: false, position: 'bottom-left' },
  privacyPolicyUrl: '',
  customCss: '',
};

const SYSTEM_FONT =
  "system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif";

const COLOR_RE = /^(#[0-9a-f]{3,8}|(rgb|rgba|hsl|hsla)\([\d\s.,%deg/]+\)|[a-z]{3,20})$/i;
const URL_RE = /^(https?:\/\/|\/)/i;

const color = (value: unknown, fallback: string): string =>
  typeof value === 'string' && COLOR_RE.test(value.trim()) ? value.trim() : fallback;

const int = (value: unknown, fallback: number, min: number, max: number): number => {
  const n = typeof value === 'number' ? value : parseInt(String(value), 10);
  return Number.isFinite(n) ? Math.min(max, Math.max(min, Math.round(n))) : fallback;
};

const url = (value: unknown): string =>
  typeof value === 'string' && URL_RE.test(value.trim()) ? value.trim() : '';

/** Maps the configured font setting to a CSS font-family value. */
export const resolveFontFamily = (value: unknown): string => {
  if (typeof value !== 'string' || value.trim() === '' || value === 'inherit') return 'inherit';
  if (value === 'system') return SYSTEM_FONT;
  // Font stacks only: names, quotes, commas, spaces and hyphens.
  return /^[\w\s,'"-]{1,200}$/.test(value) ? value : 'inherit';
};

/** Normalizes the template's appearance settings, falling back to defaults per field. */
export const resolveAppearance = (input?: AppearanceConfig | null): ResolvedAppearance => {
  const a = input || {};
  const d = DEFAULT_APPEARANCE;
  const layout = a.layout === 'bar' || a.layout === 'corner' ? a.layout : 'modal';
  return {
    layout,
    logoUrl: url(a.logoUrl),
    logoAlt: typeof a.logoAlt === 'string' ? a.logoAlt : '',
    colors: {
      primary: color(a.colors?.primary, d.colors.primary),
      primaryText: color(a.colors?.primaryText, d.colors.primaryText),
      background: color(a.colors?.background, d.colors.background),
      text: color(a.colors?.text, d.colors.text),
      overlay: color(a.colors?.overlay, d.colors.overlay),
    },
    borderRadius: int(a.borderRadius, d.borderRadius, 0, 32),
    buttonRadius: int(a.buttonRadius, d.buttonRadius, 0, 32),
    fontFamily: resolveFontFamily(a.fontFamily),
    uppercaseButtons: a.uppercaseButtons !== false,
    overlay: layout === 'modal' ? a.overlay !== false : a.overlay === true,
    blockScroll: a.blockScroll === true,
    reopenButton: {
      enabled: a.reopenButton?.enabled === true,
      position: a.reopenButton?.position === 'bottom-right' ? 'bottom-right' : 'bottom-left',
    },
    privacyPolicyUrl: url(a.privacyPolicyUrl),
    // A style element cannot be closed from inside; cap the size defensively.
    customCss: typeof a.customCss === 'string' ? a.customCss.replace(/</g, '').slice(0, 20000) : '',
  };
};

const defaultLabel = (action: FirstLayerAction, config: CMPConfig): string => {
  const t = config.texts?.firstLayer;
  switch (action) {
    case 'accept-all':
      return t?.acceptAllButtonText || 'Accept';
    case 'reject-all':
      return t?.rejectAllButtonText || config.texts?.secondLayer?.rejectButtonText || 'Reject';
    case 'settings':
      return t?.settingsButtonText || 'Settings';
    case 'partners':
      return t?.partnersLinkText || 'Partners';
  }
};

/**
 * Decides which buttons layer 1 shows. In the certified profile the publisher's
 * setup is corrected so that "reject all" is offered next to "accept all" with
 * the same visual weight and the settings stay one click away. In the custom
 * profile the configuration is rendered as-is (only made usable: deduplicated and
 * never empty).
 */
export const resolveFirstLayer = (config: CMPConfig): ResolvedFirstLayer => {
  const profile: ComplianceProfile = config.complianceProfile === 'custom' ? 'custom' : 'certified';
  const fl = config.firstLayer || {};
  const mode = config.mode || 'tcf_gcm';
  const adjustments: string[] = [];

  const configured = Array.isArray(fl.buttons) && fl.buttons.length > 0;
  const source = configured ? (fl.buttons as FirstLayerButton[]) : DEFAULT_FIRST_LAYER_BUTTONS;

  const seen = new Set<FirstLayerAction>();
  let buttons: ResolvedButton[] = [];
  for (const b of source) {
    if (!b || !ACTIONS.includes(b.action) || seen.has(b.action)) continue;
    // The partner list does not exist in basic mode.
    if (b.action === 'partners' && mode === 'basic') continue;
    seen.add(b.action);
    buttons.push({
      action: b.action,
      variant: VARIANTS.includes(b.variant as ButtonVariant)
        ? (b.variant as ButtonVariant)
        : 'secondary',
      label: (typeof b.label === 'string' && b.label.trim()) || defaultLabel(b.action, config),
    });
  }

  if (buttons.length === 0) {
    buttons = DEFAULT_FIRST_LAYER_BUTTONS.map((b) => ({
      action: b.action,
      variant: b.variant as ButtonVariant,
      label: defaultLabel(b.action, config),
    }));
  }

  if (profile === 'certified') {
    const ensure = (action: FirstLayerAction, variant: ButtonVariant, index: number) => {
      if (!buttons.some((b) => b.action === action)) {
        buttons.splice(Math.min(index, buttons.length), 0, {
          action,
          variant,
          label: defaultLabel(action, config),
        });
        adjustments.push(`added ${action}`);
      }
    };
    ensure('settings', 'secondary', 0);
    const acceptIndex = buttons.findIndex((b) => b.action === 'accept-all');
    ensure('reject-all', 'primary', acceptIndex === -1 ? buttons.length : acceptIndex);
    ensure('accept-all', 'primary', buttons.length);

    // Equal prominence: reject gets the accept button's weight; a "link" weight is
    // not acceptable for either of them.
    const accept = buttons.find((b) => b.action === 'accept-all') as ResolvedButton;
    const reject = buttons.find((b) => b.action === 'reject-all') as ResolvedButton;
    const weight: ButtonVariant = accept.variant === 'secondary' ? 'secondary' : 'primary';
    for (const b of [accept, reject]) {
      if (b.variant !== weight) {
        adjustments.push(`${b.action} variant ${b.variant} -> ${weight}`);
        b.variant = weight;
      }
    }
  }

  const showTcfSummary =
    mode === 'tcf_gcm' && (profile === 'certified' || fl.showTcfSummary !== false);
  if (profile === 'certified' && mode === 'tcf_gcm' && fl.showTcfSummary === false) {
    adjustments.push('TCF summary forced on');
  }

  return {
    profile,
    buttons,
    closeButton: fl.closeButton === 'reject-all' ? 'reject-all' : 'none',
    showTcfSummary,
    adjustments,
  };
};

/** Applies the resolved appearance to the consent box container. */
export const applyAppearance = (container: HTMLElement, a: ResolvedAppearance): void => {
  const s = container.style;
  s.setProperty('--cmp-primary', a.colors.primary);
  s.setProperty('--cmp-primary-text', a.colors.primaryText);
  s.setProperty('--cmp-bg', a.colors.background);
  s.setProperty('--cmp-text', a.colors.text);
  s.setProperty('--cmp-overlay', a.colors.overlay);
  s.setProperty('--cmp-radius', `${a.borderRadius}px`);
  s.setProperty('--cmp-btn-radius', `${a.buttonRadius}px`);
  s.setProperty('--cmp-font', a.fontFamily);
  s.setProperty('--cmp-btn-transform', a.uppercaseButtons ? 'uppercase' : 'none');

  container.classList.remove('cmp-layout-modal', 'cmp-layout-bar', 'cmp-layout-corner');
  container.classList.add(`cmp-layout-${a.layout}`);
  container.classList.toggle('cmp-no-overlay', !a.overlay);

  const styleId = 'open-cmp-custom-css';
  let style = document.getElementById(styleId) as HTMLStyleElement | null;
  if (a.customCss) {
    if (!style) {
      style = document.createElement('style');
      style.id = styleId;
      document.head.appendChild(style);
    }
    style.textContent = a.customCss;
  } else if (style) {
    style.remove();
  }
};

/** Fills {placeholders} in a text template. */
export const formatText = (template: string, values: Record<string, string | number>): string =>
  template.replace(/\{(\w+)\}/g, (m, key) => (key in values ? String(values[key]) : m));
