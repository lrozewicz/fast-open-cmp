import { CMPConfig, ConsentState, CMPEventType } from '../../models/types';
import { t } from '../../i18n/uiTexts';
import { escapeHtml } from '../../utils/html';
import consentService from '../../services/consent';
import {
  renderIABVendor,
  renderCustomVendor,
  areAllVendorsChecked,
  vendorUsesPurpose,
} from '../items/Vendor';
import { handleButtonClick } from '../events/EventHandlers';

/**
 * Renders the third layer of the consent box (vendor list)
 */
export const renderThirdLayer = (
  config: CMPConfig,
  state: ConsentState,
  container: HTMLElement,
  filterVendorsByPurpose: number | null,
  onShowLayer: (layer: number) => void,
  onSavePreferences: () => void,
  onAcceptAll: () => void,
  onRejectAll: () => void,
  onUpdateState: () => void,
  onToggleVendorGroup: (vendorType: string, checked: boolean) => void,
  setFilterVendorsByPurpose: (purposeId: number | null) => void,
): void => {
  const { texts, vendors, purposes, specialPurposes, features, specialFeatures, stacks } = config;
  const mode = config.mode || 'tcf_gcm';

  // A vendor group with no enabled vendors in the active list is hidden entirely.
  // When the flag is absent (older config), every group defaults to visible.
  const visibleGroups = config.visibleVendorGroups || { iab: true, google: true, other: true };

  // Warstwa 3 (lista vendorów) nie jest dostępna w trybie podstawowym
  if (mode === 'basic') {
    console.warn('[CMP] Layer 3 not available in basic mode');
    return;
  }

  // Filter encoding convention:
  //   null: no filter, IAB collapsed
  //   0: no filter, IAB expanded (after clearing filter)
  //   1-99: purpose ID
  //   -1 to -99: special feature (abs = ID)
  //   100-199: special purpose (value - 100 = ID)
  //   200-299: feature (value - 200 = ID)
  //   >= 1000: stack (value - 1000 = stack ID)

  // Determine filter info for display
  let filterName = '';
  let filterCount = 0;
  const hasActiveFilter = filterVendorsByPurpose !== null && filterVendorsByPurpose !== 0;

  if (hasActiveFilter && filterVendorsByPurpose !== null) {
    if (filterVendorsByPurpose >= 1000) {
      // Stack filter
      const stackId = filterVendorsByPurpose - 1000;
      const stack = stacks[stackId];
      if (stack) {
        filterName = stack.name;
        // Count filtered vendors for stacks
        filterCount = Object.values(vendors.iab).filter((v) =>
          stack.purposes.some((pId: number) => vendorUsesPurpose(v, pId)),
        ).length;
      }
    } else if (filterVendorsByPurpose >= 200) {
      // Feature filter
      const fId = filterVendorsByPurpose - 200;
      if (features[fId]) {
        filterName = features[fId].name;
        filterCount = features[fId].vendorsCount || 0;
      }
    } else if (filterVendorsByPurpose >= 100) {
      // Special purpose filter
      const spId = filterVendorsByPurpose - 100;
      if (specialPurposes[spId]) {
        filterName = specialPurposes[spId].name;
        filterCount = specialPurposes[spId].vendorsCount || 0;
      }
    } else if (filterVendorsByPurpose > 0) {
      // Purpose filter
      if (purposes[filterVendorsByPurpose]) {
        const p = purposes[filterVendorsByPurpose];
        filterName = p.name;
        filterCount = p.vendorsCount || 0;
      }
    } else if (filterVendorsByPurpose < 0) {
      // Special feature filter
      const sfId = Math.abs(filterVendorsByPurpose);
      if (specialFeatures[sfId]) {
        const sf = specialFeatures[sfId];
        filterName = sf.name;
        filterCount = sf.vendorsCount || 0;
      }
    }
  }

  // IAB should expand if any filter is set (including value 0 = just cleared)
  const iabGroupExpanded = filterVendorsByPurpose !== null;

  // Start with header, search bar, and vendor list
  let layerHtml = `
    <div id="open-cmp-screen3" role="dialog" aria-modal="true" aria-labelledby="open-cmp-title3" tabindex="-1">
      <div class="open-cmp-screen3-header">
        <button type="button" data-type="back">&#10094; ${escapeHtml(texts.thirdLayer.backButtonText)}</button>
        <h2 id="open-cmp-title3">${escapeHtml(texts.thirdLayer.title)}</h2>
      </div>
      <div class="open-cmp-screen3-search">
        <div class="search-input-wrapper">
          <input type="search" data-type="vendor-search" aria-label="${escapeHtml(texts.thirdLayer.searchPlaceholder || t('searchVendors'))}" placeholder="${escapeHtml(texts.thirdLayer.searchPlaceholder || t('searchVendors'))}">
          <button type="button" class="search-clear" data-type="search-clear" style="display:none;" aria-label="×">&times;</button>
          <span class="search-icon" aria-hidden="true">&#128269;</span>
        </div>
      </div>
      <div class="open-cmp-screen3-vendors">
        <ul>
  `;

  // Grupa vendor\u00f3w IAB \u2014 renderowana wy\u0142\u0105cznie w trybie pe\u0142nym (TCF).
  // W trybie gcm_only warstwa 3 pokazuje tylko grupy Google / inni.
  if (mode === 'tcf_gcm' && visibleGroups.iab && Object.keys(vendors.iab).length > 0) {
    // Render IAB vendors section
    layerHtml += `
    <li>
      <div>
        <div class="open-cmp-screen3-vendors-label"><span class="expand-icon">${iabGroupExpanded ? '\u2212' : '+'}</span> ${escapeHtml(texts.thirdLayer.vendorGroups.iab)}</div>
        <div><input type="checkbox" data-vendors-type="iab" ${areAllVendorsChecked('iab', vendors.iab, vendors.custom, state.vendorConsents, state.customVendorConsents) ? 'checked' : ''}></div>
      </div>
  `;

    // Add filter bar if there's an active filter with a name
    if (hasActiveFilter && filterName) {
      layerHtml += `
      <div class="vendor-filter-bar">
        <span class="vendor-filter-text">${escapeHtml(filterName)} (${escapeHtml(t('filterCount', { count: filterCount }))})</span>
        <button type="button" class="vendor-filter-clear" data-type="clear-vendor-filter" aria-label="×">&times;</button>
      </div>
    `;
    }

    layerHtml += `
      <ul class="vendors-grouped ${iabGroupExpanded ? '' : 'hidden'}">
  `;

    // Filter IAB vendors if needed
    let filteredIabVendors = Object.values(vendors.iab);

    if (hasActiveFilter && filterVendorsByPurpose !== null) {
      if (filterVendorsByPurpose >= 1000) {
        // Stack: filter by any purpose in the stack
        const stackId = filterVendorsByPurpose - 1000;
        const stack = stacks[stackId];
        if (stack) {
          filteredIabVendors = filteredIabVendors.filter((v) =>
            stack.purposes.some((pId: number) => vendorUsesPurpose(v, pId)),
          );
        }
      } else if (filterVendorsByPurpose >= 200) {
        // Feature: filter by vendors that have this feature
        const fId = filterVendorsByPurpose - 200;
        filteredIabVendors = filteredIabVendors.filter(
          (v) => v.features && v.features.includes(fId),
        );
      } else if (filterVendorsByPurpose >= 100) {
        // Special purpose: filter by vendors that have this special purpose
        const spId = filterVendorsByPurpose - 100;
        filteredIabVendors = filteredIabVendors.filter(
          (v) => v.specialPurposes && v.specialPurposes.includes(spId),
        );
      } else if (filterVendorsByPurpose > 0) {
        // Purpose
        filteredIabVendors = filteredIabVendors.filter((v) =>
          vendorUsesPurpose(v, filterVendorsByPurpose),
        );
      } else if (filterVendorsByPurpose < 0) {
        // Special feature
        const specialFeatureId = Math.abs(filterVendorsByPurpose);
        filteredIabVendors = filteredIabVendors.filter(
          (v) => v.specialFeatures && v.specialFeatures.includes(specialFeatureId),
        );
      }
    }

    // Add IAB vendors
    for (const vendor of filteredIabVendors) {
      layerHtml += renderIABVendor(
        vendor,
        state.vendorConsents[vendor.id] || false,
        state.vendorLegitimateInterests[vendor.id] || false,
        purposes,
        specialPurposes,
        features,
        specialFeatures,
        texts.thirdLayer,
        config.dataCategories,
      );
    }

    layerHtml += `
      </ul>
    </li>
  `;
  } // koniec grupy IAB (mode === 'tcf_gcm')

  // Render Google vendors section
  if (visibleGroups.google && Object.keys(vendors.custom.google).length > 0) {
    layerHtml += `
      <li>
        <div>
          <div class="open-cmp-screen3-custom-vendors-label"><span class="expand-icon">+</span> ${escapeHtml(texts.thirdLayer.vendorGroups.google)}</div>
          <div><input type="checkbox" data-vendors-type="google" ${areAllVendorsChecked('google', vendors.iab, vendors.custom, state.vendorConsents, state.customVendorConsents) ? 'checked' : ''}></div>
        </div>
        <ul class="vendors-grouped hidden">
    `;

    // Add Google vendors
    for (const vendorId in vendors.custom.google) {
      const vendor = vendors.custom.google[vendorId];
      const prefixedId = `google:${vendorId}`;
      layerHtml += renderCustomVendor(
        vendor,
        state.customVendorConsents[prefixedId] || false,
        texts.thirdLayer.privacyPolicyText,
        'google',
      );
    }

    layerHtml += `
        </ul>
      </li>
    `;
  }

  // Render other vendors section
  if (visibleGroups.other && Object.keys(vendors.custom.other).length > 0) {
    layerHtml += `
      <li>
        <div>
          <div class="open-cmp-screen3-custom-vendors-label"><span class="expand-icon">+</span> ${escapeHtml(texts.thirdLayer.vendorGroups.other)}</div>
          <div><input type="checkbox" data-vendors-type="other" ${areAllVendorsChecked('other', vendors.iab, vendors.custom, state.vendorConsents, state.customVendorConsents) ? 'checked' : ''}></div>
        </div>
        <ul class="vendors-grouped hidden">
    `;

    // Add other vendors
    for (const vendorId in vendors.custom.other) {
      const vendor = vendors.custom.other[vendorId];
      const prefixedId = `other:${vendorId}`;
      layerHtml += renderCustomVendor(
        vendor,
        state.customVendorConsents[prefixedId] || false,
        texts.thirdLayer.privacyPolicyText,
        'other',
      );
    }

    layerHtml += `
        </ul>
      </li>
    `;
  }

  // Finish list and add buttons
  layerHtml += `
        </ul>
      </div>
      <div class="open-cmp-screen3-footer">
        <button data-type="accept-only-checked">${escapeHtml(texts.thirdLayer.confirmButtonText)}</button>
        <button data-type="not-accept">${escapeHtml(texts.thirdLayer.rejectButtonText)}</button>
        <button data-type="accept-all">${escapeHtml(texts.thirdLayer.acceptAllButtonText)}</button>
      </div>
    </div>
  `;

  container.innerHTML = layerHtml;

  // Add event handlers for buttons
  const backButton = container.querySelector('button[data-type="back"]');
  if (backButton) {
    backButton.addEventListener('click', () => {
      handleButtonClick('back');
      setFilterVendorsByPurpose(null); // Reset filter
      onShowLayer(2);
    });
  }

  // Add event handler for clearing the vendor filter
  // Use 0 instead of null so IAB stays expanded after clearing
  const clearFilterButton = container.querySelector('[data-type="clear-vendor-filter"]');
  if (clearFilterButton) {
    clearFilterButton.addEventListener('click', () => {
      setFilterVendorsByPurpose(0);
      onShowLayer(3);
    });
  }

  const saveButton = container.querySelector('button[data-type="accept-only-checked"]');
  if (saveButton) {
    saveButton.addEventListener('click', () => {
      handleButtonClick('accept-only-checked');
      onSavePreferences();
    });
  }

  const rejectButton = container.querySelector('button[data-type="not-accept"]');
  if (rejectButton) {
    rejectButton.addEventListener('click', () => {
      handleButtonClick('not-accept');
      onRejectAll();
    });
  }

  const acceptAllButton = container.querySelector('button[data-type="accept-all"]');
  if (acceptAllButton) {
    acceptAllButton.addEventListener('click', () => {
      handleButtonClick('accept-all');
      onAcceptAll();
    });
  }

  // Add event handlers for vendor group checkboxes
  const vendorGroupCheckboxes = container.querySelectorAll('input[data-vendors-type]');
  vendorGroupCheckboxes.forEach((checkbox) => {
    checkbox.addEventListener('change', (e) => {
      const elem = e.target as HTMLInputElement;
      const vendorType = elem.getAttribute('data-vendors-type');
      if (vendorType) {
        onToggleVendorGroup(vendorType, elem.checked);
      }
    });
  });

  // Add event handlers for individual vendor checkboxes
  const vendorCheckboxes = container.querySelectorAll(
    'input[data-iab-vendor-id], input[data-custom-vendor-id]',
  );
  vendorCheckboxes.forEach((checkbox) => {
    checkbox.addEventListener('change', (e) => {
      const elem = e.target as HTMLInputElement;
      const vendorId =
        elem.getAttribute('data-iab-vendor-id') || elem.getAttribute('data-custom-vendor-id');

      // Dispatch checkbox toggle event
      if (vendorId) {
        consentService.dispatchConsentEvent(CMPEventType.CHECKBOX_TOGGLED, {
          checkboxId: `vendor-${vendorId}`,
          checkboxValue: elem.checked,
          checkboxType: 'vendor',
          layer: 3,
        });
      }

      onUpdateState();
    });
  });

  // Add event handlers for vendor legitimate interest checkboxes
  const vendorLegitInterestCheckboxes = container.querySelectorAll(
    'input[data-consent-id^="vendor-legitimate-interests-"]',
  );
  vendorLegitInterestCheckboxes.forEach((checkbox) => {
    checkbox.addEventListener('change', (e) => {
      const elem = e.target as HTMLInputElement;
      const consentId = elem.getAttribute('data-consent-id');

      // Dispatch checkbox toggle event
      if (consentId) {
        consentService.dispatchConsentEvent(CMPEventType.CHECKBOX_TOGGLED, {
          checkboxId: consentId,
          checkboxValue: elem.checked,
          checkboxType: 'vendor',
          layer: 3,
        });
      }

      onUpdateState();
    });
  });

  // Add event handler for legitimate interest objection - show/hide red text
  // Checked = objection filed (legitimate interest withdrawn)
  const legitCheckboxes = container.querySelectorAll(
    'input[data-consent-id*="legitimate-interests"]',
  );
  legitCheckboxes.forEach((checkbox) => {
    const elem = checkbox as HTMLInputElement;
    const statusSpan = elem.parentElement?.querySelector('.objection-status') as HTMLElement;
    if (statusSpan) {
      // Set initial state: show the "objection filed" status when NOT checked (objection)
      statusSpan.style.display = elem.checked ? 'inline' : 'none';
      // Listen for changes
      elem.addEventListener('change', () => {
        statusSpan.style.display = elem.checked ? 'inline' : 'none';
      });
    }
  });

  // Add event handler for expanding vendor details + toggle icon
  // Click target is the entire row (div:first-child), excluding checkbox
  const vendorRows = container.querySelectorAll('.vendors-grouped > li > div:first-child');
  vendorRows.forEach((row) => {
    row.addEventListener('click', (e) => {
      if ((e.target as HTMLElement).tagName === 'INPUT') return;
      const listItem = (e.currentTarget as HTMLElement).closest('li');
      if (listItem) {
        const textContainer = listItem.querySelector('.open-cmp-screen3-vendor-text');
        if (textContainer) {
          textContainer.classList.toggle('open');
          const icon = listItem.querySelector('.expand-icon');
          if (icon) {
            icon.textContent = textContainer.classList.contains('open') ? '−' : '+';
          }
        }
      }
    });
  });

  // Add event handler for expanding vendor groups + toggle icon and add info text
  // Click target is the entire row (div:first-child), excluding checkbox
  const vendorGroupRows = container.querySelectorAll(
    '.open-cmp-screen3-vendors > ul > li > div:first-child',
  );
  vendorGroupRows.forEach((row, index) => {
    row.addEventListener('click', (e) => {
      if ((e.target as HTMLElement).tagName === 'INPUT') return;
      const listItem = (e.currentTarget as HTMLElement).closest('li');
      if (listItem) {
        const groupList = listItem.querySelector('.vendors-grouped');
        if (groupList) {
          groupList.classList.toggle('hidden');
          const icon = listItem.querySelector('.expand-icon');
          if (icon) {
            icon.textContent = groupList.classList.contains('hidden') ? '+' : '−';
          }
        }
      }
    });

    // Add info text only to the IAB vendors group (first group)
    if (index === 0) {
      const listItem = row.closest('li');
      const vendorList = listItem?.querySelector('.vendors-grouped');

      if (vendorList) {
        // Create info element
        const infoElement = document.createElement('div');
        infoElement.className = 'vendor-list-info';
        infoElement.textContent = t('vendorListInfo');

        // Add info element as first child of the vendor list
        vendorList.insertBefore(infoElement, vendorList.firstChild);
      }
    }
  });

  // Add event handler for search
  const searchInput = container.querySelector(
    'input[data-type="vendor-search"]',
  ) as HTMLInputElement;
  const searchClear = container.querySelector('[data-type="search-clear"]') as HTMLElement;
  if (searchInput) {
    const doSearch = () => {
      const query = searchInput.value.toLowerCase().trim();
      const allVendorItems = container.querySelectorAll('.vendors-grouped > li');

      // Show/hide clear button
      if (searchClear) {
        searchClear.style.display = query ? 'block' : 'none';
      }

      allVendorItems.forEach((item) => {
        const label = item.querySelector('.open-cmp-screen3-vendor-label');
        if (label) {
          const name = label.textContent?.toLowerCase() || '';
          (item as HTMLElement).style.display = !query || name.includes(query) ? '' : 'none';
        }
      });

      // Auto-expand groups when searching
      if (query) {
        const groups = container.querySelectorAll('.vendors-grouped.hidden');
        groups.forEach((group) => {
          group.classList.remove('hidden');
          const parentLi = group.closest('li');
          if (parentLi) {
            const icon = parentLi.querySelector('.expand-icon');
            if (icon) icon.textContent = '−';
          }
        });
      }
    };

    searchInput.addEventListener('input', doSearch);

    // Clear button handler
    if (searchClear) {
      searchClear.addEventListener('click', () => {
        searchInput.value = '';
        doSearch();
        searchInput.focus();
      });
    }
  }
};
