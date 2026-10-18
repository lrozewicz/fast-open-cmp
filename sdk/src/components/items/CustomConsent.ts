import { CustomConsent } from '../../models/types';
import { t } from '../../i18n/uiTexts';
import { escapeHtml } from '../../utils/html';

/**
 * Renders a custom consent item
 * @param consent - Custom consent to render
 * @param isChecked - Whether the consent is checked
 * @param legitInterestChecked - Whether legitimate interest is checked
 * @param commonTexts - Common text strings
 * @returns HTML string for the custom consent
 */
export const renderCustomConsent = (
  consent: CustomConsent,
  isChecked: boolean,
  legitInterestChecked: boolean = false,
  commonTexts: { objectButtonText: string } | null = null,
): string => {
  const consentId = consent.id;

  let consentHtml = `
    <li>
      <div>
        <div class="open-cmp-screen2-constents-list-label"><span class="expand-icon">+</span> ${escapeHtml(consent.title)}</div>
        <div><input type="checkbox" data-consent-id="${escapeHtml(consentId)}" ${isChecked ? 'checked' : ''}></div>
      </div>
      <div class="open-cmp-screen2-constents-list-text">
        <p>${escapeHtml(consent.description)}</p>
  `;

  // Dodaj opcję zgłaszania sprzeciwu, jeśli uzasadniony interes jest dozwolony
  if (consent.legitimateInterestAllowed && commonTexts) {
    consentHtml += `
      <div class="legit-interest-objection">
        <label for="cli${escapeHtml(consentId)}">${escapeHtml(commonTexts.objectButtonText)}</label>
        <input id="cli${escapeHtml(consentId)}" type="checkbox" ${legitInterestChecked ? '' : 'checked'} data-consent-id="custom-purpose-legitimate-interests-${escapeHtml(consentId)}">
        <span class="objection-status">${escapeHtml(t('objectionFiled'))}</span>
      </div>
    `;
  }

  consentHtml += `
      </div>
    </li>
  `;

  return consentHtml;
};
