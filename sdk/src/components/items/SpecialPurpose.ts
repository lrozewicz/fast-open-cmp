import { SpecialPurpose } from '../../models/types';
import { t } from '../../i18n/uiTexts';
import { escapeHtml } from '../../utils/html';

/**
 * Renders a special purpose item (always active)
 * @param purpose - Special purpose to render
 * @param alwaysActiveText - Text for "always active" label
 * @param exampleUseText - Text for "example of use" link
 * @returns HTML string for the special purpose
 */
export const renderSpecialPurpose = (
  purpose: SpecialPurpose,
  alwaysActiveText: string,
  exampleUseText: string,
): string => {
  const purposeId = purpose.id;

  const partnerCountHtml =
    purpose.vendorsCount && purpose.vendorsCount > 0
      ? `<div class="partner-count-link"><a href="#" data-type="purpose-partner-count" data-special-purpose-id="${purposeId}" data-vendor-count="${purpose.vendorsCount}">${escapeHtml(t('partnersSpecialPurpose', { count: purpose.vendorsCount }))}</a></div>`
      : '';

  const hasIllustrations = purpose.illustrations && purpose.illustrations.length > 0;

  let purposeHtml = `
    <li>
      <div>
        <div class="open-cmp-screen2-constents-list-label"><span class="expand-icon">+</span> <div class="label-content"><span class="label-name">${escapeHtml(purpose.name)}</span>${partnerCountHtml}</div></div>
        <div><span class="always-active-label">${escapeHtml(alwaysActiveText)}</span></div>
      </div>
      <div class="open-cmp-screen2-constents-list-text">
        <p>${escapeHtml(purpose.description)}</p>
  `;

  if (hasIllustrations) {
    const illustrationsJson = escapeHtml(JSON.stringify(purpose.illustrations));
    purposeHtml += `
      <p><a href="#" data-type="illustrations-link" data-illustrations="${illustrationsJson}" data-item-name="${escapeHtml(purpose.name)}">${escapeHtml(exampleUseText)}</a></p>
    `;
  }

  purposeHtml += `
      </div>
    </li>
  `;

  return purposeHtml;
};
