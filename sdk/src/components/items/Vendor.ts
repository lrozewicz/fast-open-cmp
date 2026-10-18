import { t } from '../../i18n/uiTexts';
import {
  Vendor,
  CustomVendor,
  Purpose,
  SpecialPurpose,
  Feature,
  SpecialFeature,
  Text,
} from '../../models/types';
import { escapeHtml, safeUrl } from '../../utils/html';

// Interface for data categories
interface DataCategory {
  id: number;
  name: string;
  description: string;
}

/**
 * Renders an IAB vendor
 */
export const renderIABVendor = (
  vendor: Vendor,
  isChecked: boolean,
  legitInterestChecked: boolean,
  purposes: Record<string, Purpose>,
  specialPurposes: Record<string, SpecialPurpose>,
  features: Record<string, Feature>,
  specialFeatures: Record<string, SpecialFeature>,
  texts: Text['thirdLayer'],
  dataCategories?: Record<string, DataCategory>,
): string => {
  const vendorId = vendor.id;

  // Sprawdź, czy vendor wymaga zgody
  const requiresConsent = vendor.purposes && vendor.purposes.length > 0;

  // Sprawdź, czy vendor został usunięty (ma wartość deletedDate)
  const isDeleted = !!vendor.deletedDate;

  const url = vendor.urls && vendor.urls.length > 0 ? vendor.urls[0] : null;

  // Dodaj klasę 'deleted-vendor' jeśli vendor został usunięty
  let vendorHtml = `
    <li ${isDeleted ? 'class="deleted-vendor" style="opacity: 0.5;"' : ''}>
      <div>
        <div class="open-cmp-screen3-vendor-label"><span class="expand-icon">+</span> ${escapeHtml(vendor.name)}${isDeleted ? ` <span class="vendor-inactive">(${escapeHtml(t('vendorInactive'))})</span>` : ''}</div>
        <div>`;

  // Renderuj checkbox lub odpowiednią informację
  if (isDeleted) {
    const deletedDate = new Date(vendor.deletedDate as string);
    const formattedDate = deletedDate.toLocaleDateString();
    vendorHtml += `<span class="vendor-deleted" title="${escapeHtml(t('vendorInactiveSince', { date: formattedDate }))}">${escapeHtml(t('vendorInactive'))}</span>`;
  } else if (requiresConsent) {
    vendorHtml += `<input type="checkbox" data-iab-vendor-id="${vendorId}" ${isChecked ? 'checked' : ''}>`;
  } else {
    vendorHtml += `<span class="vendor-no-consent-required" title="${escapeHtml(t('noConsentRequiredTitle'))}">${escapeHtml(t('noConsentRequired'))}</span>`;
  }

  vendorHtml += `</div>
      </div>
      <div class="open-cmp-screen3-vendor-text">
  `;

  if (url && url.privacy) {
    vendorHtml += `
        <p><a target="_blank" rel="noopener noreferrer" href="${safeUrl(url.privacy)}"><strong>${escapeHtml(texts.privacyPolicyText)}</strong> &#8599;</a></p>
    `;
  }

  if (url && url.legIntClaim) {
    vendorHtml += `
      <p><a target="_blank" rel="noopener noreferrer" href="${safeUrl(url.legIntClaim)}">${escapeHtml(texts.legitimateInterestText)} &#8599;</a></p>
    `;
  }

  // Add data categories
  if (vendor.dataDeclaration && vendor.dataDeclaration.length > 0) {
    vendorHtml += `
      <h2>${escapeHtml(texts.categoriesText.dataDeclaration)}</h2>
      <div data-key="dataDeclaration">
    `;

    for (const categoryId of vendor.dataDeclaration) {
      let categoryName = t('dataCategory', { id: categoryId });

      if (dataCategories && dataCategories[categoryId]) {
        categoryName = dataCategories[categoryId].name;
      }

      vendorHtml += `<span data-category-id="${escapeHtml(categoryId)}">${escapeHtml(categoryName)}</span>`;
    }

    vendorHtml += `
      </div>
    `;
  }

  // Add retention period
  if (vendor.dataRetention && vendor.dataRetention.stdRetention) {
    vendorHtml += `
      <h2>${escapeHtml(texts.categoriesText.stdRetention)}</h2>
      <div data-key="stdRetention">${escapeHtml(t('retentionDays', { count: vendor.dataRetention.stdRetention }))}</div>
    `;
  }

  // Add cookie max age
  if (vendor.cookieMaxAgeSeconds) {
    const days = Math.floor(vendor.cookieMaxAgeSeconds / 86400);
    vendorHtml += `
      <h2>${escapeHtml(texts.categoriesText.cookieMaxAgeSeconds)}</h2>
      <div data-key="cookieMaxAgeSeconds">${escapeHtml(t('cookieMaxAge', { days, seconds: vendor.cookieMaxAgeSeconds }))}</div>
    `;
  }

  // Add purposes
  if (vendor.purposes && vendor.purposes.length > 0) {
    vendorHtml += `
      <h2>${escapeHtml(texts.categoriesText.purposes)}</h2>
      <div data-key="purposes">
    `;

    for (const purposeId of vendor.purposes) {
      const purpose = purposes[purposeId];
      if (purpose) {
        vendorHtml += `<span data-purpose-id="${purposeId}">${escapeHtml(purpose.name)}</span>`;
      }
    }

    vendorHtml += `
      </div>
    `;
  }

  // Add legitimate interest purposes
  if (vendor.legIntPurposes && vendor.legIntPurposes.length > 0) {
    vendorHtml += `
      <h2>${escapeHtml(texts.categoriesText.legIntPurposes)}</h2>
      <div data-key="legIntPurposes">
    `;

    for (const purposeId of vendor.legIntPurposes) {
      const purpose = purposes[purposeId];
      if (purpose) {
        vendorHtml += `<span data-purpose-id="${purposeId}">${escapeHtml(purpose.name)}</span>`;
      }
    }

    vendorHtml += `
      </div>
    `;
  }

  // Add special purposes
  if (vendor.specialPurposes && vendor.specialPurposes.length > 0) {
    vendorHtml += `
      <h2>${escapeHtml(texts.categoriesText.specialPurposes)}</h2>
      <div data-key="specialPurposes">
    `;

    for (const purposeId of vendor.specialPurposes) {
      const purpose = specialPurposes[purposeId];
      if (purpose) {
        vendorHtml += `<span data-special-purpose-id="${purposeId}">${escapeHtml(purpose.name)}</span>`;
      }
    }

    vendorHtml += `
      </div>
    `;
  }

  // Add features
  if (vendor.features && vendor.features.length > 0) {
    vendorHtml += `
      <h2>${escapeHtml(texts.categoriesText.features)}</h2>
      <div data-key="features">
    `;

    for (const featureId of vendor.features) {
      const feature = features[featureId];
      if (feature) {
        vendorHtml += `<span data-feature-id="${featureId}">${escapeHtml(feature.name)}</span>`;
      }
    }

    vendorHtml += `
      </div>
    `;
  }

  // Add special features
  if (vendor.specialFeatures && vendor.specialFeatures.length > 0) {
    vendorHtml += `
      <h2>${escapeHtml(texts.categoriesText.specialFeatures)}</h2>
      <div data-key="specialFeatures">
    `;

    for (const featureId of vendor.specialFeatures) {
      const feature = specialFeatures[featureId];
      if (feature) {
        vendorHtml += `<span data-special-feature-id="${featureId}">${escapeHtml(feature.name)}</span>`;
      }
    }

    vendorHtml += `
      </div>
    `;
  }

  // Add legitimate interest objection checkbox
  if (vendor.legIntPurposes && vendor.legIntPurposes.length > 0) {
    vendorHtml += `
      <div class="legit-interest-objection">
        <label for="plivendor${vendorId}">${escapeHtml(texts.objectButtonText)}</label>
        <input id="plivendor${vendorId}" type="checkbox" ${legitInterestChecked ? '' : 'checked'} data-consent-id="vendor-legitimate-interests-${vendorId}">
        <span class="objection-status">${escapeHtml(t('objectionFiled'))}</span>
      </div>
    `;
  }

  vendorHtml += `
      </div>
    </li>
  `;

  return vendorHtml;
};

/**
 * Renders a custom vendor
 */
export const renderCustomVendor = (
  vendor: CustomVendor,
  isChecked: boolean,
  privacyPolicyText: string,
  vendorType: 'google' | 'other' = 'other',
): string => {
  const vendorId = vendor.id;
  const prefixedId = `${vendorType}:${vendorId}`;
  const url = vendor.urls && vendor.urls.length > 0 ? vendor.urls[0] : null;
  const privacyHtml =
    url && url.privacy
      ? `<p><a target="_blank" rel="noopener noreferrer" href="${safeUrl(url.privacy)}"><strong>${escapeHtml(privacyPolicyText)}</strong> &#8599;</a></p>`
      : '';

  return `
    <li>
      <div>
        <div class="open-cmp-screen3-vendor-label"><span class="expand-icon">+</span> ${escapeHtml(vendor.name)}</div>
        <div><input type="checkbox" data-custom-vendor-id="${escapeHtml(prefixedId)}" ${isChecked ? 'checked' : ''}></div>
      </div>
      <div class="open-cmp-screen3-vendor-text">
        ${privacyHtml}
      </div>
    </li>
  `;
};

/**
 * Checks if all vendors of a given type are checked
 */
export const areAllVendorsChecked = (
  vendorType: string,
  vendors: Record<string, Vendor>,
  customVendors: Record<string, Record<string, CustomVendor>>,
  vendorConsents: Record<string, boolean>,
  customVendorConsents: Record<string, boolean>,
): boolean => {
  if (vendorType === 'iab') {
    let vendorsRequiringConsent = 0;
    let vendorsWithConsent = 0;

    for (const vendorId in vendors) {
      const vendor = vendors[vendorId];

      // Pomijamy vendorów oznaczonych jako usunięte (deletedDate)
      if (vendor.deletedDate) {
        continue;
      }

      // Sprawdź, czy vendor wymaga zgody (ma niepustą tablicę purposes)
      if (vendor.purposes && vendor.purposes.length > 0) {
        vendorsRequiringConsent++;
        if (vendorConsents[vendorId]) {
          vendorsWithConsent++;
        }
      }
    }

    // Jeśli nie ma vendorów wymagających zgody, zwróć true (nie ma nic do sprawdzenia)
    if (vendorsRequiringConsent === 0) {
      return true;
    }

    // Sprawdź czy wszyscy vendorzy wymagający zgody mają ją udzieloną
    return vendorsWithConsent === vendorsRequiringConsent;
  } else if (vendorType === 'google' || vendorType === 'other') {
    for (const vendorId in customVendors[vendorType]) {
      const prefixedId = `${vendorType}:${vendorId}`;
      if (!customVendorConsents[prefixedId]) {
        return false;
      }
    }
    return Object.keys(customVendors[vendorType]).length > 0;
  }
  return false;
};

/**
 * Checks if a vendor uses a specific purpose
 */
export const vendorUsesPurpose = (vendor: Vendor | CustomVendor, purposeId: number): boolean => {
  if ('purposes' in vendor) {
    return (
      (vendor.purposes && vendor.purposes.includes(purposeId)) ||
      (vendor.legIntPurposes && vendor.legIntPurposes.includes(purposeId))
    );
  }
  return false;
};
