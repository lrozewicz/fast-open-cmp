import { SpecialFeature, Feature as StandardFeature } from '../../models/types';
import { t } from '../../i18n/uiTexts';
import { escapeHtml } from '../../utils/html';

/**
 * Renders a special feature item
 * @param feature - Special feature to render
 * @param isChecked - Whether the feature is checked
 * @returns HTML string for the special feature
 */
export const renderSpecialFeature = (feature: SpecialFeature, isChecked: boolean): string => {
  const featureId = feature.id;

  const partnerCountHtml =
    feature.vendorsCount && feature.vendorsCount > 0
      ? `<div class="partner-count-link"><a href="#" data-type="purpose-partner-count" data-special-feature-id="${featureId}" data-vendor-count="${feature.vendorsCount}">${escapeHtml(t('partnersSpecialFeature', { count: feature.vendorsCount }))}</a></div>`
      : '';

  const featureHtml = `
    <li>
      <div>
        <div class="open-cmp-screen2-constents-list-label"><span class="expand-icon">+</span> <div class="label-content"><span class="label-name">${escapeHtml(feature.name)}</span>${partnerCountHtml}</div></div>
        <div><input type="checkbox" data-consent-id="special-feature-${featureId}" ${isChecked ? 'checked' : ''}></div>
      </div>
      <div class="open-cmp-screen2-constents-list-text">
        <p>${escapeHtml(feature.description)}</p>
      </div>
    </li>
  `;

  return featureHtml;
};

/**
 * Renders a standard feature item (always active)
 * @param feature - Standard feature to render
 * @param alwaysActiveText - Text for "always active" label
 * @returns HTML string for the standard feature
 */
export const renderFeature = (feature: StandardFeature, alwaysActiveText: string): string => {
  const featureId = feature.id;

  const partnerCountHtml =
    feature.vendorsCount && feature.vendorsCount > 0
      ? `<div class="partner-count-link"><a href="#" data-type="purpose-partner-count" data-feature-id="${featureId}" data-vendor-count="${feature.vendorsCount}">${escapeHtml(t('partnersFeature', { count: feature.vendorsCount }))}</a></div>`
      : '';

  return `
    <li>
      <div>
        <div class="open-cmp-screen2-constents-list-label"><span class="expand-icon">+</span> <div class="label-content"><span class="label-name">${escapeHtml(feature.name)}</span>${partnerCountHtml}</div></div>
        <div><span class="always-active-label">${escapeHtml(alwaysActiveText)}</span></div>
      </div>
      <div class="open-cmp-screen2-constents-list-text">
        <p>${escapeHtml(feature.description)}</p>
      </div>
    </li>
  `;
};
