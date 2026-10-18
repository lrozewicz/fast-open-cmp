import { CMPConfig, ConsentState, CMPEventType } from '../../models/types';
import { t } from '../../i18n/uiTexts';
import { escapeHtml } from '../../utils/html';
import { getConsentId } from '../../services/consentLog';
import consentService from '../../services/consent';
import { renderPurpose } from '../items/Purpose';
import { renderSpecialFeature, renderFeature } from '../items/Feature';
import { renderSpecialPurpose } from '../items/SpecialPurpose';
import { renderCustomConsent } from '../items/CustomConsent';
import { isStackChecked, getStackVendorsCount } from '../items/Stack';
import { handleButtonClick } from '../events/EventHandlers';

/**
 * Renders the second layer of the consent box (advanced settings)
 */
export const renderSecondLayer = (
  config: CMPConfig,
  state: ConsentState,
  container: HTMLElement,
  onShowLayer: (layer: number) => void,
  onSavePreferences: () => void,
  onAcceptAll: () => void,
  onRejectAll: () => void,
  onUpdateState: () => void,
  setFilterVendorsByPurpose: (purposeId: number | null) => void,
): void => {
  const { texts, purposes, specialFeatures, specialPurposes, features, stacks, customConsents } =
    config;
  const mode = config.mode || 'tcf_gcm';
  const consentId = getConsentId();

  // Czy istnieją jacyś vendorzy niestandardowi (Google / inni)?
  const hasCustomVendors =
    Object.keys(config.vendors?.custom?.google || {}).length > 0 ||
    Object.keys(config.vendors?.custom?.other || {}).length > 0;

  // Link do listy partnerów (warstwa 3): w trybie pełnym zawsze; w gcm_only tylko gdy są vendorzy niestandardowi; w basic nigdy
  const partnersLinkHtml =
    mode === 'tcf_gcm' || (mode === 'gcm_only' && hasCustomVendors)
      ? `<p><a href="#" data-type="partners-link">${escapeHtml(texts.secondLayer.partnersLinkText)}</a></p>`
      : '';

  // Start with header and description
  let layerHtml = `
    <div id="open-cmp-screen2" role="dialog" aria-modal="true" aria-labelledby="open-cmp-title2" tabindex="-1">
      <div class="open-cmp-screen2-header">
        <button type="button" data-type="back">&#10094; ${escapeHtml(texts.secondLayer.backButtonText)}</button>
        <h2 id="open-cmp-title2">${escapeHtml(texts.secondLayer.title)}</h2>
      </div>
      <div class="open-cmp-screen2-content">
        <div class="open-cmp-description">${texts.secondLayer.description}</div>
        ${partnersLinkHtml}
        ${consentId ? `<p class="open-cmp-consent-id">${escapeHtml(t('consentId', { id: consentId }))}</p>` : ''}
      </div>
      <div class="open-cmp-screen2-constents">
        <h2>${escapeHtml(texts.secondLayer.preferencesTitle)}</h2>
        <div class="open-cmp-screen2-constents-list">
          <ul>
  `;

  // Add custom consents
  for (const consent of customConsents) {
    layerHtml += renderCustomConsent(
      consent,
      state.customPurposeConsents[consent.id] || false,
      consent.legitimateInterestAllowed
        ? (state.customPurposeLegitimateInterests &&
            state.customPurposeLegitimateInterests[consent.id]) ||
            state.purposeLegitimateInterests[consent.id] ||
            false
        : false,
      texts.common,
    );
  }

  // Sekcja TCF (stacki, cele, funkcje) — renderowana wyłącznie w trybie pełnym.
  // W trybach gcm_only / basic warstwa 2 pokazuje tylko zgody niestandardowe.
  if (mode === 'tcf_gcm') {
    // Add TCF purposes
    // Keep track of rendered stacks
    const renderedStacks = new Set();

    // First, render all stacks
    for (const stackId in stacks) {
      const stack = stacks[stackId];

      const stackVendorsCount = getStackVendorsCount(stack.purposes, purposes, config.vendors?.iab);
      const stackPartnerCountHtml =
        stackVendorsCount > 0
          ? `<div class="partner-count-link"><a href="#" data-type="purpose-partner-count" data-stack-id="${stack.id}" data-vendor-count="${stackVendorsCount}">${escapeHtml(t('partnersPurpose', { count: stackVendorsCount }))}</a></div>`
          : '';

      layerHtml += `
      <li>
        <div>
          <div class="open-cmp-screen2-constents-list-label"><span class="expand-icon">+</span> <div class="label-content"><span class="label-name">${escapeHtml(stack.name)}</span>${stackPartnerCountHtml}</div></div>
          <div><input type="checkbox" data-consent-id="purpose-stack${stack.id}" ${isStackChecked(stack.purposes, state.purposeConsents) ? 'checked' : ''}></div>
        </div>
        <div class="open-cmp-screen2-constents-list-text">
          <p>${escapeHtml(stack.description)}</p>
          <ul class="stack-grouped" data-stack="${stack.id}">
    `;

      // Add all purposes in the stack
      for (const pId of stack.purposes) {
        const stackPurpose = purposes[pId];
        layerHtml += renderPurpose(
          stackPurpose,
          state.purposeConsents[pId] || false,
          state.purposeLegitimateInterests[pId] || false,
          texts.common,
          true,
        );
      }

      layerHtml += `
          </ul>
        </div>
      </li>
    `;

      // Mark all the purposes in this stack as rendered
      stack.purposes.forEach((purposeId) => renderedStacks.add(purposeId));
    }

    // Then render purposes that are not part of any stack
    for (const purposeId in purposes) {
      const purpose = purposes[purposeId];

      // Skip if this purpose is part of a stack (already rendered)
      if (renderedStacks.has(purpose.id)) {
        continue;
      }

      // Render purposes not in any stack
      layerHtml += renderPurpose(
        purpose,
        state.purposeConsents[purposeId] || false,
        state.purposeLegitimateInterests[purposeId] || false,
        texts.common,
      );
    }

    // Add special features
    for (const featureId in specialFeatures) {
      const feature = specialFeatures[featureId];
      layerHtml += renderSpecialFeature(feature, state.specialFeatureOptins[featureId] || false);
    }

    // Add special purposes
    for (const purposeId in specialPurposes) {
      const purpose = specialPurposes[purposeId];
      layerHtml += renderSpecialPurpose(
        purpose,
        texts.thirdLayer.alwaysActiveText,
        texts.common.exampleUseText,
      );
    }

    // Add standard features
    for (const featureId in features) {
      const feature = features[featureId];
      layerHtml += renderFeature(feature, texts.thirdLayer.alwaysActiveText);
    }
  } // koniec sekcji TCF (mode === 'tcf_gcm')

  // Finish list and add buttons
  layerHtml += `
          </ul>
        </div>
      </div>
      <div class="open-cmp-screen2-footer">
        <button data-type="accept-only-checked">${escapeHtml(texts.secondLayer.confirmButtonText)}</button>
        <button data-type="not-accept">${escapeHtml(texts.secondLayer.rejectButtonText)}</button>
        <button data-type="accept-all">${escapeHtml(texts.secondLayer.acceptAllButtonText)}</button>
      </div>
    </div>
  `;

  container.innerHTML = layerHtml;

  // Add event handlers for buttons
  const backButton = container.querySelector('button[data-type="back"]');
  if (backButton) {
    backButton.addEventListener('click', () => {
      handleButtonClick('back');
      onShowLayer(1);
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

  const partnersLink = container.querySelector(
    '.open-cmp-screen2-content a[data-type="partners-link"]',
  );
  if (partnersLink) {
    partnersLink.addEventListener('click', (e) => {
      e.preventDefault();
      handleButtonClick('partners-link');
      setFilterVendorsByPurpose(null);
      onShowLayer(3);
    });
  }

  // Add event handlers for checkboxes
  const checkboxes = container.querySelectorAll('input[type="checkbox"]');

  console.log('Adding event handlers to checkboxes, total found:', checkboxes.length);

  // Flag to prevent event recursion
  let isHandlingCheckboxChange = false;

  // Function to handle checkbox change
  const handleCheckboxChange = (e: Event) => {
    // Prevent recursive event handling
    if (isHandlingCheckboxChange) return;

    try {
      isHandlingCheckboxChange = true;

      const elem = e.target as HTMLInputElement;
      const consentId = elem.getAttribute('data-consent-id');
      const inStack = elem.hasAttribute('data-in-stack');

      console.log('Checkbox changed:', consentId, 'In stack:', inStack, 'New state:', elem.checked);

      // Dispatch checkbox toggle event
      if (consentId) {
        const checkboxType = consentId.startsWith('purpose-')
          ? 'purpose'
          : consentId.startsWith('special-feature-')
            ? 'specialFeature'
            : consentId.startsWith('custom-consent-')
              ? 'customConsent'
              : 'purpose';

        consentService.dispatchConsentEvent(CMPEventType.CHECKBOX_TOGGLED, {
          checkboxId: consentId,
          checkboxValue: elem.checked,
          checkboxType: checkboxType,
          layer: 2,
        });
      }

      // Handle stack checkboxes separately
      if (consentId && consentId.startsWith('purpose-stack')) {
        // This is a stack checkbox
        const stackId = consentId.replace('purpose-stack', '');
        const stack = stacks[stackId];

        if (stack) {
          // Find all purpose checkboxes that belong to this stack
          const stackPurposes = stack.purposes;
          stackPurposes.forEach((purposeId) => {
            const purposeCheckbox = container.querySelector(
              `input[data-consent-id="purpose-${purposeId}"]`,
            ) as HTMLInputElement;
            if (purposeCheckbox) {
              // Update the purpose checkbox state to match the stack checkbox
              purposeCheckbox.checked = elem.checked;
            }
          });
        }
      }
      // If this is an individual purpose checkbox inside a stack
      else if (inStack && consentId && consentId.startsWith('purpose-')) {
        // Get the purpose ID
        const purposeId = consentId.replace('purpose-', '');

        // Find the stack this purpose belongs to
        for (const stackId in stacks) {
          const stack = stacks[stackId];
          if (stack.purposes.includes(parseInt(purposeId))) {
            // Check if all purposes in the stack have the same value
            const stackPurposes = stack.purposes;
            const allChecked = stackPurposes.every((id) => {
              const checkbox = container.querySelector(
                `input[data-consent-id="purpose-${id}"]`,
              ) as HTMLInputElement;
              return checkbox && checkbox.checked;
            });

            // Update the stack checkbox
            const stackCheckbox = container.querySelector(
              `input[data-consent-id="purpose-stack${stack.id}"]`,
            ) as HTMLInputElement;
            if (stackCheckbox && stackCheckbox.checked !== allChecked) {
              console.log('Updating stack checkbox to:', allChecked);
              stackCheckbox.checked = allChecked;
            }
            break;
          }
        }
      }

      // Call the update state function
      onUpdateState();
    } finally {
      // Reset flag regardless of whether an error occurred
      isHandlingCheckboxChange = false;
    }
  };

  // Add listeners to all checkboxes
  checkboxes.forEach((checkbox) => {
    const elem = checkbox as HTMLInputElement;
    const consentId = elem.getAttribute('data-consent-id');
    const inStack = elem.hasAttribute('data-in-stack');

    console.log('Setting up checkbox:', consentId, 'In stack:', inStack);

    if (!elem.hasAttribute('onclick')) {
      // Don't add listener to checkboxes that already have onclick attribute
      // Remove any existing listeners to avoid duplicates
      elem.removeEventListener('change', handleCheckboxChange);

      // Add the new listener
      elem.addEventListener('change', handleCheckboxChange);

      console.log('Event handler added to:', consentId);
    }
  });

  // Add event handler for expanding purpose descriptions + toggle expand icon
  // Click target is the entire row (div:first-child) not just the label, excluding the checkbox
  const purposeRows = container.querySelectorAll(
    '.open-cmp-screen2-constents-list > ul > li > div:first-child, .stack-grouped li > div:first-child',
  );
  purposeRows.forEach((row) => {
    row.addEventListener('click', (e) => {
      // Don't toggle when clicking the checkbox itself
      if ((e.target as HTMLElement).tagName === 'INPUT') return;
      const listItem = (e.currentTarget as HTMLElement).closest('li');
      if (listItem) {
        const textContainer = listItem.querySelector('.open-cmp-screen2-constents-list-text');
        if (textContainer) {
          textContainer.classList.toggle('open');
          // Toggle expand icon
          const icon = listItem.querySelector('.expand-icon');
          if (icon) {
            icon.textContent = textContainer.classList.contains('open') ? '−' : '+';
          }
        }
      }
    });
  });

  // Add event handler for illustrations lightbox
  const illustrationsLinks = container.querySelectorAll('a[data-type="illustrations-link"]');
  illustrationsLinks.forEach((link) => {
    link.addEventListener('click', (e) => {
      e.preventDefault();
      e.stopPropagation();
      const el = e.currentTarget as HTMLElement;
      const illustrationsJson = el.getAttribute('data-illustrations');
      const itemName = el.getAttribute('data-item-name') || '';

      if (!illustrationsJson) return;

      let illustrations: string[];
      try {
        illustrations = JSON.parse(illustrationsJson);
      } catch {
        return;
      }

      if (!illustrations || illustrations.length === 0) return;

      // Build lightbox content
      const illustrationsHtml = illustrations.map((text) => `<p>${escapeHtml(text)}</p>`).join('');

      // Create lightbox overlay inside the CMP container
      const cmpContainer = container.closest('#open-cmp-container') || container;
      const overlay = document.createElement('div');
      overlay.className = 'cmp-illustrations-overlay';
      overlay.setAttribute('role', 'dialog');
      overlay.setAttribute('aria-modal', 'true');
      overlay.innerHTML = `
        <div class="cmp-illustrations-lightbox">
          <div class="cmp-illustrations-header">
            <h3>${escapeHtml(itemName)}</h3>
            <button type="button" class="cmp-illustrations-close" data-type="close-illustrations" aria-label="×">&times;</button>
          </div>
          <div class="cmp-illustrations-body">
            ${illustrationsHtml}
          </div>
        </div>
      `;

      cmpContainer.appendChild(overlay);
      (overlay.querySelector('[data-type="close-illustrations"]') as HTMLElement | null)?.focus();

      // Close handlers
      const closeBtn = overlay.querySelector('[data-type="close-illustrations"]');
      if (closeBtn) {
        closeBtn.addEventListener('click', () => overlay.remove());
      }
      overlay.addEventListener('click', (ev) => {
        if (ev.target === overlay) overlay.remove();
      });
    });
  });

  // Add event handler for legitimate interest objection checkboxes - show/hide red text
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

  // Add event handler for partner count links (purpose, stack, special feature, special purpose, feature)
  // Filter encoding convention:
  //   1-99: purpose ID
  //   -1 to -99: special feature (abs = ID)
  //   100-199: special purpose (value - 100 = ID)
  //   200-299: feature (value - 200 = ID)
  //   >= 1000: stack (value - 1000 = stack ID)
  //   0: no filter, but keep IAB expanded
  const partnerCountLinks = container.querySelectorAll('a[data-type="purpose-partner-count"]');
  partnerCountLinks.forEach((link) => {
    link.addEventListener('click', (e) => {
      e.preventDefault();
      e.stopPropagation(); // Prevent row expand/collapse
      const el = e.currentTarget as HTMLElement;
      const purposeId = el.getAttribute('data-purpose-id');
      const stackId = el.getAttribute('data-stack-id');
      const specialFeatureId = el.getAttribute('data-special-feature-id');
      const specialPurposeId = el.getAttribute('data-special-purpose-id');
      const featureId = el.getAttribute('data-feature-id');

      if (purposeId) {
        setFilterVendorsByPurpose(parseInt(purposeId));
      } else if (specialFeatureId) {
        setFilterVendorsByPurpose(parseInt(specialFeatureId) * -1);
      } else if (specialPurposeId) {
        setFilterVendorsByPurpose(100 + parseInt(specialPurposeId));
      } else if (featureId) {
        setFilterVendorsByPurpose(200 + parseInt(featureId));
      } else if (stackId && stacks[stackId]) {
        setFilterVendorsByPurpose(1000 + parseInt(stackId));
      }
      onShowLayer(3);
    });
  });
};
