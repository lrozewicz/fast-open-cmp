import { escapeHtml, safeUrl } from '../../utils/html';
import { CMPConfig, FirstLayerAction } from '../../models/types';
import { handleButtonClick } from '../events/EventHandlers';
import { resolveAppearance, resolveFirstLayer, formatText } from '../../services/appearance';

/** data-type attribute per action (accept-all / settings kept for existing integrations and tests). */
const BUTTON_DATA_TYPE: Record<FirstLayerAction, string> = {
  'accept-all': 'accept-all',
  'reject-all': 'reject-all',
  settings: 'settings',
  partners: 'partners-button',
};

/** Fallbacks for texts that older servers do not send yet. */
const FALLBACK_TEXTS = {
  pl: {
    vendorCountText:
      'Korzystamy z usług {iab} Zaufanych Partnerów IAB oraz {other} pozostałych partnerów.',
    purposesIntroText:
      'My i nasi Zaufani Partnerzy możemy przetwarzać dane osobowe w następujących celach:',
    privacyPolicyLinkText: 'Polityka prywatności',
    closeButtonLabel: 'Zamknij i odrzuć',
  },
  en: {
    vendorCountText: 'We work with {iab} IAB Trusted Partners and {other} other partners.',
    purposesIntroText: 'We and our Trusted Partners may process personal data for these purposes:',
    privacyPolicyLinkText: 'Privacy policy',
    closeButtonLabel: 'Close and reject',
  },
};

const fallbackTexts = (config: CMPConfig) =>
  (config.language || 'pl').startsWith('pl') ? FALLBACK_TEXTS.pl : FALLBACK_TEXTS.en;

/**
 * Builds the TCF information required on the initial layer: number of partners
 * and the purposes, stacks and special features they rely on.
 */
const renderTcfSummary = (config: CMPConfig): string => {
  const t = { ...fallbackTexts(config), ...stripEmpty(config.texts.firstLayer) };
  const iab = Object.values(config.vendors?.iab || {}).filter((v) => !v.deletedDate).length;
  const other =
    Object.keys(config.vendors?.custom?.google || {}).length +
    Object.keys(config.vendors?.custom?.other || {}).length;

  const stacks = Object.values(config.stacks || {});
  const stacked = new Set<number>();
  stacks.forEach((s) => s.purposes.forEach((p) => stacked.add(p)));

  const names: string[] = [
    ...Object.values(config.purposes || {})
      .filter((p) => !stacked.has(p.id))
      .map((p) => p.name),
    ...stacks.map((s) => s.name),
    ...Object.values(config.specialFeatures || {}).map((f) => f.name),
  ];

  let html = '';
  if (iab + other > 0) {
    html += `<p class="open-cmp-vendor-count">${escapeHtml(formatText(t.vendorCountText, { iab, other }))}</p>`;
  }
  if (names.length > 0) {
    html += `<p class="open-cmp-purposes-intro">${escapeHtml(t.purposesIntroText)}</p>`;
    html += `<ul class="open-cmp-purposes">${names.map((n) => `<li>${escapeHtml(n)}</li>`).join('')}</ul>`;
  }
  return html;
};

const stripEmpty = <T extends object>(obj: T): Partial<T> =>
  Object.fromEntries(
    Object.entries(obj || {}).filter(([, v]) => v !== '' && v != null),
  ) as Partial<T>;

/**
 * Renders the first layer of the consent box
 */
export const renderFirstLayer = (
  config: CMPConfig,
  container: HTMLElement,
  onShowLayer: (layer: number) => void,
  onAcceptAll: () => void,
  onRejectAll: () => void,
): void => {
  const { texts } = config;
  const mode = config.mode || 'tcf_gcm';
  const layer = resolveFirstLayer(config);
  const appearance = resolveAppearance(config.appearance);
  const t = { ...fallbackTexts(config), ...stripEmpty(texts.firstLayer) };

  // Czy istnieją jacyś vendorzy niestandardowi (Google / inni)?
  const hasCustomVendors =
    Object.keys(config.vendors?.custom?.google || {}).length > 0 ||
    Object.keys(config.vendors?.custom?.other || {}).length > 0;

  // Link do listy partnerów (warstwa 3): w trybie pełnym zawsze; w gcm_only tylko gdy są vendorzy niestandardowi; w basic nigdy
  const partnersAvailable = mode === 'tcf_gcm' || (mode === 'gcm_only' && hasCustomVendors);
  const links: string[] = [];
  if (partnersAvailable && !layer.buttons.some((b) => b.action === 'partners')) {
    links.push(
      `<a href="#" data-type="partners-link">${escapeHtml(texts.firstLayer.partnersLinkText)}</a>`,
    );
  }
  if (appearance.privacyPolicyUrl) {
    links.push(
      `<a href="${safeUrl(appearance.privacyPolicyUrl)}" target="_blank" rel="noopener noreferrer" data-type="privacy-policy">${escapeHtml(t.privacyPolicyLinkText)}</a>`,
    );
  }

  const buttons = layer.buttons
    .filter((b) => b.action !== 'partners' || partnersAvailable)
    .map(
      (b) =>
        `<button type="button" class="cmp-btn cmp-btn--${b.variant}" data-type="${BUTTON_DATA_TYPE[b.action]}">${escapeHtml(b.label)}</button>`,
    )
    .join('');

  const closeHtml =
    layer.closeButton === 'reject-all'
      ? `<button type="button" class="open-cmp-close" data-type="close-reject" aria-label="${escapeHtml(t.closeButtonLabel)}">&times;</button>`
      : '';

  const logoHtml = appearance.logoUrl
    ? `<div class="open-cmp-logo"><img src="${safeUrl(appearance.logoUrl)}" alt="${escapeHtml(appearance.logoAlt)}"></div>`
    : '';

  const layerHtml = `
    <div id="open-cmp-screen1" role="dialog" aria-modal="true" aria-labelledby="open-cmp-title" tabindex="-1" data-profile="${layer.profile}">
      ${closeHtml}
      <div class="open-cmp-screen1-header">
        ${logoHtml}
        <h2 id="open-cmp-title">${escapeHtml(texts.firstLayer.title)}</h2>
      </div>
      <div class="open-cmp-screen1-content">
        <div class="open-cmp-description">${texts.firstLayer.description}</div>
        ${layer.showTcfSummary ? renderTcfSummary(config) : ''}
        ${links.length ? `<p class="open-cmp-links">${links.join('')}</p>` : ''}
      </div>
      <div class="open-cmp-screen1-footer">${buttons}</div>
    </div>
  `;

  container.innerHTML = layerHtml;

  const on = (selector: string, handler: (e: Event) => void) => {
    container.querySelectorAll(selector).forEach((el) => el.addEventListener('click', handler));
  };

  on('button[data-type="settings"]', () => {
    handleButtonClick('settings');
    onShowLayer(2);
  });
  on('button[data-type="accept-all"]', () => {
    handleButtonClick('accept-all');
    onAcceptAll();
  });
  on('button[data-type="reject-all"], button[data-type="close-reject"]', (e) => {
    handleButtonClick((e.currentTarget as HTMLElement).getAttribute('data-type') || 'reject-all');
    onRejectAll();
  });
  on('button[data-type="partners-button"], a[data-type="partners-link"]', (e) => {
    e.preventDefault();
    handleButtonClick('partners-link');
    onShowLayer(3);
  });
};
