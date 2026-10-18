import { Purpose } from '../../models/types';
import { t } from '../../i18n/uiTexts';
import { escapeHtml } from '../../utils/html';

/**
 * Renders a single purpose item
 * @param purpose - Purpose to render
 * @param isChecked - Whether the purpose is checked
 * @param legitInterestChecked - Whether legitimate interest is checked
 * @param commonTexts - Common text strings
 * @param inStack - Whether the purpose is part of a stack
 * @returns HTML string for the purpose
 */
export const renderPurpose = (
  purpose: Purpose,
  isChecked: boolean,
  legitInterestChecked: boolean,
  commonTexts: { exampleUseText: string; objectButtonText: string },
  inStack: boolean = false,
): string => {
  const purposeId = purpose.id;

  const inStackAttr = inStack ? 'data-in-stack="true"' : '';

  const partnerCountHtml =
    purpose.vendorsCount && purpose.vendorsCount > 0
      ? `<div class="partner-count-link"><a href="#" data-type="purpose-partner-count" data-purpose-id="${purposeId}" data-purpose-name="${escapeHtml(purpose.name)}" data-vendor-count="${purpose.vendorsCount}">${escapeHtml(t('partnersPurpose', { count: purpose.vendorsCount }))}</a></div>`
      : '';

  // Use illustrations from GVL translations (array of strings)
  const hasIllustrations = purpose.illustrations && purpose.illustrations.length > 0;

  let purposeHtml = `
    <li>
      <div>
        <div class="open-cmp-screen2-constents-list-label"><span class="expand-icon">+</span> <div class="label-content"><span class="label-name">${escapeHtml(purpose.name)}</span>${partnerCountHtml}</div></div>
        <div><input type="checkbox" data-consent-id="purpose-${purposeId}" ${inStackAttr} ${isChecked ? 'checked' : ''}></div>
      </div>
      <div class="open-cmp-screen2-constents-list-text">
        <p>${escapeHtml(purpose.description)}</p>
  `;

  if (hasIllustrations) {
    const illustrationsJson = escapeHtml(JSON.stringify(purpose.illustrations));
    purposeHtml += `
      <p><a href="#" data-type="illustrations-link" data-illustrations="${illustrationsJson}" data-item-name="${escapeHtml(purpose.name)}">${escapeHtml(commonTexts.exampleUseText)}</a></p>
    `;
  }

  if (purpose.legitimateInterestAllowed) {
    purposeHtml += `
      <div class="legit-interest-objection">
        <label for="pli${purposeId}">${escapeHtml(commonTexts.objectButtonText)}</label>
        <input id="pli${purposeId}" type="checkbox" ${legitInterestChecked ? '' : 'checked'} data-consent-id="purpose-legitimate-interests-${purposeId}">
        <span class="objection-status">${escapeHtml(t('objectionFiled'))}</span>
      </div>
    `;
  }

  purposeHtml += `
      </div>
    </li>
  `;

  return purposeHtml;
};
