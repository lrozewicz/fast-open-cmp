import { CMPConfig, ConsentState, ConsentAction, CMPEventType, Stack } from '../models/types';
import { renderFirstLayer } from './layers/FirstLayer';
import { renderSecondLayer } from './layers/SecondLayer';
import { renderThirdLayer } from './layers/ThirdLayer';
import consentService from '../services/consent';
import { applyAppearance, resolveAppearance, resolveFirstLayer } from '../services/appearance';
import { escapeHtml } from '../utils/html';
import { setUiLanguage } from '../i18n/uiTexts';
import { attachKeyboard, enhanceLayer } from './a11y';

/**
 * Main class for managing consent UI and interactions
 */
export class ConsentManager {
  private config: CMPConfig;
  private container: HTMLDivElement | null = null;
  private state: ConsentState;
  private currentLayer: number = 1;
  private filterVendorsByPurpose: number | null = null;
  // Admin preview: decisions are not stored, logged or sent anywhere.
  private preview: boolean;

  /**
   * Constructor
   * @param config - CMP configuration
   */
  constructor(config: CMPConfig, options: { preview?: boolean } = {}) {
    this.preview = options.preview === true;
    // Add placeholders for data that will be loaded from the vendor list
    if (!config.purposes) {
      config.purposes = {};
    }
    if (!config.specialFeatures) {
      config.specialFeatures = {};
    }
    if (!config.specialPurposes) {
      config.specialPurposes = {};
    }
    if (!config.features) {
      config.features = {};
    }
    if (!config.stacks) {
      config.stacks = {};
    }
    if (!config.dataCategories) {
      config.dataCategories = {};
    }
    if (!config.vendors.iab) {
      config.vendors.iab = {};
    }

    // Apply vendor counts from metadata if available
    if (config.purposesMetadata && config.purposesMetadata.vendorCounts) {
      for (const purposeId in config.purposesMetadata.vendorCounts) {
        if (config.purposes[purposeId]) {
          config.purposes[purposeId].vendorsCount =
            (config.purposesMetadata.vendorCounts[purposeId].purposes || 0) +
            (config.purposesMetadata.vendorCounts[purposeId].legIntPurposes || 0);
          config.purposes[purposeId].legIntVendorsCount =
            config.purposesMetadata.vendorCounts[purposeId].legIntPurposes;
        }
      }
    }

    if (config.specialFeaturesMetadata && config.specialFeaturesMetadata.vendorCounts) {
      for (const featureId in config.specialFeaturesMetadata.vendorCounts) {
        if (config.specialFeatures[featureId]) {
          config.specialFeatures[featureId].vendorsCount =
            config.specialFeaturesMetadata.vendorCounts[featureId];
        }
      }
    }

    if (config.specialPurposesMetadata && config.specialPurposesMetadata.vendorCounts) {
      for (const spId in config.specialPurposesMetadata.vendorCounts) {
        if (config.specialPurposes[spId]) {
          config.specialPurposes[spId].vendorsCount =
            config.specialPurposesMetadata.vendorCounts[spId];
        }
      }
    }

    if (config.featuresMetadata && config.featuresMetadata.vendorCounts) {
      for (const fId in config.featuresMetadata.vendorCounts) {
        if (config.features[fId]) {
          config.features[fId].vendorsCount = config.featuresMetadata.vendorCounts[fId];
        }
      }
    }

    this.config = config;
    this.state = consentService.getConsentState();
    setUiLanguage(config.language, config.texts?.ui);
  }

  /**
   * Updates the consent state from UI
   */
  private updateState(): void {
    if (!this.container) return;

    console.log('Updating consent state from UI');

    // Update purpose consents
    const purposeCheckboxes = this.container.querySelectorAll(
      'input[data-consent-id^="purpose-"]:not([data-consent-id^="purpose-legitimate-interests-"]):not([data-consent-id^="purpose-stack"])',
    );

    console.log('Purpose checkboxes found:', purposeCheckboxes.length);

    purposeCheckboxes.forEach((checkbox) => {
      const elem = checkbox as HTMLInputElement;
      const consentId = elem.getAttribute('data-consent-id');
      const inStack = elem.hasAttribute('data-in-stack');

      console.log(
        'Processing checkbox:',
        consentId,
        'In stack:',
        inStack,
        'Checked:',
        elem.checked,
      );

      if (consentId) {
        const purposeId = consentId.replace('purpose-', '');
        this.state.purposeConsents[purposeId] = elem.checked;

        console.log('Setting purpose consent:', purposeId, 'to', elem.checked);

        // Aktualizuj również odpowiedni stack checkbox, jeśli ten purpose jest częścią stacka
        if (inStack) {
          for (const stackId in this.config.stacks) {
            const stack = this.config.stacks[stackId];
            if (stack.purposes.includes(parseInt(purposeId))) {
              console.log('Purpose is part of stack:', stackId);

              // Sprawdź, czy po zmianie wszystkie cele w stacku mają tę samą wartość
              const allChecked = stack.purposes.every(
                (purpose) => this.state.purposeConsents[purpose] === true,
              );

              console.log('All checked in stack:', allChecked);

              const stackCheckbox = this.container?.querySelector(
                `input[data-consent-id="purpose-stack${stack.id}"]`,
              ) as HTMLInputElement;
              if (stackCheckbox) {
                console.log('Stack checkbox found, current state:', stackCheckbox.checked);
                if (stackCheckbox.checked !== allChecked) {
                  stackCheckbox.checked = allChecked;
                  console.log('Updated stack checkbox to:', allChecked);
                }
              } else {
                console.log('Stack checkbox not found');
              }
            }
          }
        }
      }
    });

    // Update purpose legitimate interests
    const legitInterestCheckboxes = this.container.querySelectorAll(
      'input[data-consent-id^="purpose-legitimate-interests-"]',
    );
    legitInterestCheckboxes.forEach((checkbox) => {
      const elem = checkbox as HTMLInputElement;
      const consentId = elem.getAttribute('data-consent-id');
      if (consentId) {
        const purposeId = consentId.replace('purpose-legitimate-interests-', '');
        this.state.purposeLegitimateInterests[purposeId] = !elem.checked; // checked = objection filed
      }
    });

    // Update special features
    const specialFeatureCheckboxes = this.container.querySelectorAll(
      'input[data-consent-id^="special-feature-"]',
    );
    specialFeatureCheckboxes.forEach((checkbox) => {
      const elem = checkbox as HTMLInputElement;
      const consentId = elem.getAttribute('data-consent-id');
      if (consentId) {
        const featureId = consentId.replace('special-feature-', '');
        this.state.specialFeatureOptins[featureId] = elem.checked;
      }
    });

    // Update IAB vendors
    const iabVendorCheckboxes = this.container.querySelectorAll('input[data-iab-vendor-id]');
    iabVendorCheckboxes.forEach((checkbox) => {
      const elem = checkbox as HTMLInputElement;
      const vendorId = elem.getAttribute('data-iab-vendor-id');
      if (vendorId) {
        this.state.vendorConsents[vendorId] = elem.checked;
      }
    });

    // Update vendor legitimate interests
    const vendorLegitInterestCheckboxes = this.container.querySelectorAll(
      'input[data-consent-id^="vendor-legitimate-interests-"]',
    );
    vendorLegitInterestCheckboxes.forEach((checkbox) => {
      const elem = checkbox as HTMLInputElement;
      const consentId = elem.getAttribute('data-consent-id');
      if (consentId) {
        const vendorId = consentId.replace('vendor-legitimate-interests-', '');
        this.state.vendorLegitimateInterests[vendorId] = !elem.checked; // checked = objection filed
      }
    });

    // Update custom vendors - only if checkboxes are found (layer 3 is visible)
    // This preserves existing state when saving from layer 2
    const customVendorCheckboxes = this.container.querySelectorAll('input[data-custom-vendor-id]');
    console.log(
      '[CMP v2] updateState() found custom vendor checkboxes:',
      customVendorCheckboxes.length,
      'on layer:',
      this.currentLayer,
    );
    if (customVendorCheckboxes.length > 0) {
      customVendorCheckboxes.forEach((checkbox) => {
        const elem = checkbox as HTMLInputElement;
        const vendorId = elem.getAttribute('data-custom-vendor-id');
        if (vendorId) {
          console.log('[CMP v2] updateState() vendor:', vendorId, '=', elem.checked);
          this.state.customVendorConsents[vendorId] = elem.checked;
        }
      });
    } else {
      console.log(
        '[CMP v2] updateState() NO custom vendor checkboxes - preserving state:',
        JSON.stringify(this.state.customVendorConsents),
      );
    }

    // Update custom consents
    const customConsentCheckboxes = this.container.querySelectorAll(
      'input[data-consent-id^="custom-"]:not([data-consent-id^="custom-purpose-legitimate-interests-"])',
    );
    customConsentCheckboxes.forEach((checkbox) => {
      const elem = checkbox as HTMLInputElement;
      const consentId = elem.getAttribute('data-consent-id');
      if (consentId) {
        this.state.customPurposeConsents[consentId] = elem.checked;
      }
    });

    // Update custom consent legitimate interests
    const customLegitInterestCheckboxes = this.container.querySelectorAll(
      'input[data-consent-id^="custom-purpose-legitimate-interests-"]',
    );

    // Inicjalizacja obiektu customPurposeLegitimateInterests, jeśli nie istnieje
    if (!this.state.customPurposeLegitimateInterests) {
      this.state.customPurposeLegitimateInterests = {};
    }

    customLegitInterestCheckboxes.forEach((checkbox) => {
      const elem = checkbox as HTMLInputElement;
      const consentId = elem.getAttribute('data-consent-id');
      if (consentId) {
        const customId = consentId.replace('custom-purpose-legitimate-interests-', '');
        this.state.customPurposeLegitimateInterests[customId] = !elem.checked; // checked = objection filed
      }
    });

    // Process stack checkboxes last
    const stackCheckboxes = this.container.querySelectorAll(
      'input[data-consent-id^="purpose-stack"]',
    );
    stackCheckboxes.forEach((checkbox) => {
      const elem = checkbox as HTMLInputElement;
      const consentId = elem.getAttribute('data-consent-id');
      if (consentId) {
        const stackId = consentId.replace('purpose-stack', '');
        const stack = this.config.stacks[stackId];

        if (stack) {
          // If the stack checkbox is checked, make sure all purposes in the stack are checked
          if (elem.checked) {
            for (const purposeId of stack.purposes) {
              if (!this.state.purposeConsents[purposeId]) {
                console.log(
                  `Setting purpose ${purposeId} to true because stack ${stackId} is checked`,
                );
                this.state.purposeConsents[purposeId] = true;
              }
            }
          }
          // If the stack checkbox is unchecked, don't change the individual purposes
          // They will have been already updated by their own checkboxes
        }
      }
    });
  }

  // Flag to prevent recursive UI updates
  private isTogglingStack: boolean = false;

  /**
   * Toggles all purposes in a stack
   * @param stack - Stack to toggle
   * @param checked - Whether to check or uncheck all purposes
   */
  private toggleStack(stack: Stack, checked: boolean): void {
    // Prevent recursive calls to toggleStack
    if (this.isTogglingStack) return;

    try {
      this.isTogglingStack = true;
      console.log('Toggling stack:', stack.id, 'to', checked);

      // Update the state directly without triggering UI events
      for (const purposeId of stack.purposes) {
        this.state.purposeConsents[purposeId] = checked;
        console.log('Setting purpose in stack:', purposeId, 'to', checked);

        // Update checkboxes in UI without dispatching events
        if (this.container) {
          const checkbox = this.container.querySelector(
            `input[data-consent-id="purpose-${purposeId}"]`,
          ) as HTMLInputElement;
          if (checkbox && checkbox.checked !== checked) {
            console.log('Checkbox found, updating UI:', purposeId);
            checkbox.checked = checked;
          } else {
            console.log('Checkbox not found in UI or already has correct state:', purposeId);
          }
        }
      }
    } finally {
      this.isTogglingStack = false;
    }
  }

  /**
   * Toggles all vendors of a type
   * @param vendorType - Vendor type to toggle
   * @param checked - Whether to check or uncheck all vendors
   */
  private toggleVendorGroup(vendorType: string, checked: boolean): void {
    if (vendorType === 'iab') {
      for (const vendorId in this.config.vendors.iab) {
        this.state.vendorConsents[vendorId] = checked;

        // Update checkboxes in UI
        if (this.container) {
          const checkbox = this.container.querySelector(
            `input[data-iab-vendor-id="${vendorId}"]`,
          ) as HTMLInputElement;
          if (checkbox) {
            checkbox.checked = checked;
          }
        }
      }
    } else if (vendorType === 'google' || vendorType === 'other') {
      console.log(`Toggling all ${vendorType} vendors to ${checked}`);

      for (const vendorId in this.config.vendors.custom[vendorType]) {
        const prefixedId = `${vendorType}:${vendorId}`;
        console.log(`Setting custom vendor ${prefixedId} to ${checked}`);
        this.state.customVendorConsents[prefixedId] = checked;

        // Update checkboxes in UI
        if (this.container) {
          const checkbox = this.container.querySelector(
            `input[data-custom-vendor-id="${prefixedId}"]`,
          ) as HTMLInputElement;
          if (checkbox) {
            checkbox.checked = checked;
          }
        }
      }
    }

    // Call updateState to ensure changes are reflected
    this.updateState();
  }

  /**
   * Renders and shows the consent box
   * @param targetContainer - ID of the container element
   * @param initialLayer - Initial layer to show
   */
  show(targetContainer: string = 'open-cmp-container', initialLayer: number = 1): void {
    // Find or create container
    let container = document.getElementById(targetContainer) as HTMLDivElement;

    if (!container) {
      container = document.createElement('div');
      container.id = targetContainer;
      document.body.appendChild(container);
    }

    this.container = container;
    this.currentLayer = initialLayer;

    // Once per container (the admin preview re-creates the manager on each change).
    if (!container.hasAttribute('data-cmp-keyboard')) {
      container.setAttribute('data-cmp-keyboard', '1');
      attachKeyboard(container, {
        getLayer: () => this.currentLayer,
        onBack: (layer) => this.showLayer(layer),
        onEscapeFirstLayer:
          resolveFirstLayer(this.config).closeButton === 'reject-all'
            ? () => {
                container
                  .querySelector<HTMLButtonElement>('button[data-type="close-reject"]')
                  ?.click();
              }
            : undefined,
      });
    }

    // Aktualizuj stan zgód z serwisu przed renderowaniem
    this.state = consentService.getConsentState();
    console.log('Updated consent state before showing UI:', this.state);

    // Render appropriate layer
    this.renderLayer(initialLayer);

    // Show container (if it was hidden)
    container.style.display = 'flex';

    // Publisher's look & feel (colors, layout, font, custom CSS)
    const appearance = resolveAppearance(this.config.appearance);
    applyAppearance(container, appearance);
    container.setAttribute('data-profile', resolveFirstLayer(this.config).profile);
    document.documentElement.classList.toggle('open-cmp-scroll-lock', appearance.blockScroll);
    this.setReopenButtonVisible(false);
    this.focusFirstButton();

    // Apply dimension overrides from config
    if (this.config.dimensions?.width) {
      container.style.setProperty('--desktop-width', this.config.dimensions.width + 'px');
    }
    if (this.config.dimensions?.height) {
      container.style.setProperty('--desktop-height', this.config.dimensions.height + 'px');
    }

    // Emit UI shown event
    consentService.dispatchConsentEvent(CMPEventType.CONSENT_UI_SHOWN, { layer: initialLayer });
  }

  /**
   * Hides the consent box
   */
  hide(): void {
    if (this.container) {
      this.container.style.display = 'none';
      document.documentElement.classList.remove('open-cmp-scroll-lock');
      consentService.dispatchConsentEvent(CMPEventType.CONSENT_UI_CLOSED);
    }
    this.setReopenButtonVisible(true);
  }

  /**
   * Shows/hides the floating "privacy settings" button (when enabled in the template).
   * Clicking it opens layer 2, like the footer link on gazeta.pl.
   */
  setReopenButtonVisible(visible: boolean): void {
    if (this.preview) return;
    const appearance = resolveAppearance(this.config.appearance);
    let button = document.getElementById('open-cmp-reopen');
    if (!appearance.reopenButton.enabled) {
      button?.remove();
      return;
    }
    if (!button) {
      const label =
        this.config.texts?.firstLayer?.reopenButtonLabel ||
        ((this.config.language || 'pl').startsWith('pl')
          ? 'Ustawienia prywatności'
          : 'Privacy settings');
      button = document.createElement('button');
      button.id = 'open-cmp-reopen';
      button.setAttribute('type', 'button');
      button.setAttribute('aria-label', label);
      button.title = label;
      button.innerHTML = `<span aria-hidden="true">&#128737;</span><span class="open-cmp-reopen-label">${escapeHtml(label)}</span>`;
      button.addEventListener('click', () => this.show('open-cmp-container', 2));
      document.body.appendChild(button);
    }
    button.className = `open-cmp-reopen--${appearance.reopenButton.position}`;
    applyAppearance(button, appearance);
    button.style.display = visible ? '' : 'none';
  }

  /** Moves keyboard focus into the dialog so keyboard and screen-reader users land in it. */
  private focusFirstButton(): void {
    const dialog = this.container?.querySelector('[role="dialog"]') as HTMLElement | null;
    dialog?.focus({ preventScroll: true });
  }

  /**
   * Shows a specific layer
   * @param layer - Layer number to show
   */
  showLayer(layer: number): void {
    const previousLayer = this.currentLayer;
    this.currentLayer = layer;
    this.renderLayer(layer);
    this.focusFirstButton();

    // Emit layer change event if layer actually changed
    if (previousLayer !== layer && previousLayer !== 0) {
      consentService.dispatchConsentEvent(CMPEventType.LAYER_CHANGED, {
        fromLayer: previousLayer,
        toLayer: layer,
        layer: layer,
      });
    }

    consentService.dispatchConsentEvent(CMPEventType.CONSENT_UI_SHOWN, { layer });
  }

  /**
   * Renders a specific layer
   * @param layer - Layer number to render
   */
  private renderLayer(layer: number): void {
    if (!this.container) return;

    // Layer 3 (vendor list) is not available in basic mode — fall back to layer 2
    if ((this.config.mode || 'tcf_gcm') === 'basic' && layer === 3) {
      console.warn('[CMP] Layer 3 not available in basic mode, showing layer 2');
      layer = 2;
      this.currentLayer = 2;
    }

    // Clear container
    this.container.innerHTML = '';

    // Render appropriate layer
    switch (layer) {
      case 1:
        renderFirstLayer(
          this.config,
          this.container,
          this.showLayer.bind(this),
          this.acceptAll.bind(this),
          this.rejectAll.bind(this),
        );
        break;
      case 2:
        renderSecondLayer(
          this.config,
          this.state,
          this.container,
          this.showLayer.bind(this),
          this.savePreferences.bind(this),
          this.acceptAll.bind(this),
          this.rejectAll.bind(this),
          this.updateState.bind(this),
          this.setFilterVendorsByPurpose.bind(this),
        );
        break;
      case 3:
        renderThirdLayer(
          this.config,
          this.state,
          this.container,
          this.filterVendorsByPurpose,
          this.showLayer.bind(this),
          this.savePreferences.bind(this),
          this.acceptAll.bind(this),
          this.rejectAll.bind(this),
          this.updateState.bind(this),
          this.toggleVendorGroup.bind(this),
          this.setFilterVendorsByPurpose.bind(this),
        );
        break;
      default:
        console.error('Invalid layer number:', layer);
    }

    enhanceLayer(this.container);
  }

  /**
   * Sets the vendor filter for the third layer
   * @param purposeId - Purpose ID to filter by, or null to show all vendors
   */
  private setFilterVendorsByPurpose(purposeId: number | null): void {
    this.filterVendorsByPurpose = purposeId;
  }

  /**
   * Gets the current layer number
   * @returns Current layer number
   */
  getCurrentLayer(): number {
    return this.currentLayer;
  }

  /**
   * Accepts all consents
   */
  private acceptAll(): void {
    if (this.preview) return this.previewDecision();
    const mode = this.config.mode || 'tcf_gcm';
    console.log('ConsentManager: acceptAll (mode:', mode, ')');

    // Custom consents (zgody niestandardowe) — akceptowane we wszystkich trybach
    if (this.config.customConsents.length > 0) {
      for (const consent of this.config.customConsents) {
        this.state.customPurposeConsents[consent.id] = true;
      }
    }

    // Cele TCF / funkcje specjalne / vendorzy IAB — tylko w trybie pełnym
    if (mode === 'tcf_gcm') {
      for (const purposeId in this.config.purposes) {
        this.state.purposeConsents[purposeId] = true;
      }

      for (const featureId in this.config.specialFeatures) {
        this.state.specialFeatureOptins[featureId] = true;
      }

      for (const vendorId in this.config.vendors.iab) {
        this.state.vendorConsents[vendorId] = true;
      }
    }

    // Vendorzy niestandardowi (Google + inni) — tryb pełny i gcm_only
    if (mode === 'tcf_gcm' || mode === 'gcm_only') {
      for (const type of ['google', 'other'] as const) {
        for (const vendorId in this.config.vendors.custom[type]) {
          const prefixedId = `${type}:${vendorId}`;
          this.state.customVendorConsents[prefixedId] = true;
        }
      }
    }

    // Przetwórz akcję zgody
    consentService.processConsentAction(ConsentAction.ACCEPT_ALL);

    // Zaktualizuj lokalny stan z consentService po przetworzeniu akcji
    this.state = consentService.getConsentState();

    this.hide();
  }

  /**
   * Rejects all consents
   */
  private rejectAll(): void {
    if (this.preview) return this.previewDecision();
    const mode = this.config.mode || 'tcf_gcm';

    // Cele TCF / funkcje specjalne / vendorzy IAB — tylko w trybie pełnym
    if (mode === 'tcf_gcm') {
      for (const purposeId in this.config.purposes) {
        this.state.purposeConsents[purposeId] = false;
      }

      for (const featureId in this.config.specialFeatures) {
        this.state.specialFeatureOptins[featureId] = false;
      }

      for (const vendorId in this.config.vendors.iab) {
        this.state.vendorConsents[vendorId] = false;
      }
    }

    // Vendorzy niestandardowi (Google + inni) — tryb pełny i gcm_only
    if (mode === 'tcf_gcm' || mode === 'gcm_only') {
      for (const type of ['google', 'other'] as const) {
        for (const vendorId in this.config.vendors.custom[type]) {
          const prefixedId = `${type}:${vendorId}`;
          this.state.customVendorConsents[prefixedId] = false;
        }
      }
    }

    // Odrzuć również zgody niestandardowe (wszystkie tryby)
    if (this.config.customConsents.length > 0) {
      for (const consent of this.config.customConsents) {
        this.state.customPurposeConsents[consent.id] = false;
      }
    }

    consentService.processConsentAction(ConsentAction.REJECT_ALL);

    // Zaktualizuj lokalny stan z consentService po przetworzeniu akcji
    this.state = consentService.getConsentState();

    this.hide();
  }

  /**
   * Saves the current preferences
   */
  /** Preview mode: show that the decision was not stored, then start over. */
  private previewDecision(): void {
    this.showLayer(1);
    const note = document.createElement('div');
    note.className = 'open-cmp-preview-note';
    note.setAttribute('role', 'status');
    note.textContent = (this.config.language || 'pl').startsWith('pl')
      ? 'Podgląd — wybór nie został zapisany'
      : 'Preview — the choice was not stored';
    this.container?.appendChild(note);
    setTimeout(() => note.remove(), 2000);
  }

  private savePreferences(): void {
    if (this.preview) return this.previewDecision();
    // Najpierw zaktualizuj lokalny stan na podstawie UI
    this.updateState();

    consentService.processConsentAction(ConsentAction.SAVE_PREFERENCES, this.state);

    // Zaktualizuj lokalny stan z consentService po przetworzeniu akcji
    this.state = consentService.getConsentState();

    this.hide();
  }
}

export default ConsentManager;
