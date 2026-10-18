import { CMPApi, CMPConfig, ConsentState, CMPEventDetail, ConsentAction } from '../models/types';
import consentService from './consent';
import { ConsentManager } from '../components/ConsentManager';

/**
 * Implementation of the CMP API that will be exposed as window.__cmp
 */
export class CMPApiImplementation implements CMPApi {
  private eventListeners: Map<string, Set<(detail: CMPEventDetail) => void>> = new Map();
  private consentManager: ConsentManager | null = null;

  constructor() {
    // Listen to all CMP events and forward to registered listeners
    this.setupEventForwarding();
  }

  setConsentManager(manager: ConsentManager): void {
    this.consentManager = manager;
  }

  private setupEventForwarding(): void {
    // Forward all DOM events to registered listeners
    const eventTypes = [
      'consent-ui-shown',
      'consent-ui-closed',
      'consent-button-clicked',
      'consent-changed',
      'consent-error',
      'consent-layer-changed',
      'consent-checkbox-toggled',
    ];

    eventTypes.forEach((eventType) => {
      document.addEventListener(eventType, (event: Event) => {
        const customEvent = event as CustomEvent<CMPEventDetail>;
        this.notifyListeners(eventType, customEvent.detail);
      });
    });
  }

  private notifyListeners(eventType: string, detail: CMPEventDetail): void {
    const listeners = this.eventListeners.get(eventType);
    if (listeners) {
      listeners.forEach((callback) => {
        try {
          callback(detail);
        } catch (error) {
          console.error('Error in CMP event listener:', error);
        }
      });
    }
  }

  // UI Control Methods
  showLayer(layer: 1 | 2 | 3): void {
    if (this.consentManager) {
      this.consentManager.showLayer(layer);
    }
  }

  hideUI(): void {
    if (this.consentManager) {
      this.consentManager.hide();
    }
  }

  showUI(layer?: number): void {
    if (this.consentManager) {
      this.consentManager.show('open-cmp-container', layer || 1);
    }
  }

  getCurrentLayer(): number | null {
    if (this.consentManager) {
      return this.consentManager.getCurrentLayer();
    }
    return null;
  }

  // Consent Management Methods
  acceptAll(): void {
    consentService.processConsentAction(ConsentAction.ACCEPT_ALL);
  }

  rejectAll(): void {
    consentService.processConsentAction(ConsentAction.REJECT_ALL);
  }

  saveCurrentState(): void {
    const currentState = consentService.getConsentState();
    consentService.processConsentAction(ConsentAction.SAVE_PREFERENCES, currentState);
  }

  resetToDefaults(): void {
    // Reset to defaults by clearing stored data and reloading
    try {
      localStorage.removeItem('IABTCF_TCString');
      localStorage.removeItem('IABTCF_CmpSdkID');
      localStorage.removeItem('IABTCF_CmpSdkVersion');
      localStorage.removeItem('IABTCF_VendorConsents');
      localStorage.removeItem('IABTCF_VendorLegitimateInterests');
      localStorage.removeItem('IABTCF_PurposeConsents');
      localStorage.removeItem('IABTCF_PurposeLegitimateInterests');
      localStorage.removeItem('IABTCF_SpecialFeaturesOptIns');
      localStorage.removeItem('IABTCF_AddtlConsent');

      // Trigger a page reload to reset everything
      window.location.reload();
    } catch (error) {
      console.error('Failed to reset to defaults:', error);
    }
  }

  // State Query Methods
  getCurrentConsents(): ConsentState {
    return consentService.getConsentState();
  }

  isConsentGiven(purposeId: string): boolean {
    const state = consentService.getConsentState();
    return state.purposeConsents[purposeId] || false;
  }

  isVendorConsented(vendorId: string): boolean {
    const state = consentService.getConsentState();
    return state.vendorConsents[vendorId] || false;
  }

  // Configuration Methods
  getConfig(): CMPConfig {
    if (consentService['config']) {
      return consentService['config'];
    }
    throw new Error('CMP not initialized');
  }

  // Event Methods
  on(eventType: string, callback: (detail: CMPEventDetail) => void): void {
    if (!this.eventListeners.has(eventType)) {
      this.eventListeners.set(eventType, new Set());
    }
    this.eventListeners.get(eventType)!.add(callback);
  }

  off(eventType: string, callback: (detail: CMPEventDetail) => void): void {
    const listeners = this.eventListeners.get(eventType);
    if (listeners) {
      listeners.delete(callback);
      if (listeners.size === 0) {
        this.eventListeners.delete(eventType);
      }
    }
  }

  // Debug Methods
  getDebugInfo(): Record<string, unknown> {
    return {
      currentLayer: this.getCurrentLayer(),
      currentConsents: this.getCurrentConsents(),
      config: this.getConfig(),
      eventListeners: Array.from(this.eventListeners.keys()),
      tcString: consentService.getTCString(),
    };
  }

  exportConsents(): string {
    const state = this.getCurrentConsents();
    const exportData = {
      version: '1.0',
      timestamp: new Date().toISOString(),
      consents: state,
      tcString: consentService.getTCString(),
    };
    return JSON.stringify(exportData, null, 2);
  }

  importConsents(data: string): boolean {
    try {
      const importData = JSON.parse(data);
      if (!importData.consents) {
        throw new Error('Invalid consent data format');
      }

      // Apply imported consents
      consentService.processConsentAction(ConsentAction.SAVE_PREFERENCES, importData.consents);
      return true;
    } catch (error) {
      console.error('Failed to import consents:', error);
      return false;
    }
  }
}

// Create singleton instance
export const cmpApiInstance = new CMPApiImplementation();
