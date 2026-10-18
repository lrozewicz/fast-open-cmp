// Import from @iabtechlabtcf/core (TCF 2.2 official library)
import { VersionOrVendorList } from '@iabtechlabtcf/core';
import type { UiTextKey } from '../i18n/uiTexts';

export interface Purpose {
  id: number;
  name: string;
  description: string;
  descriptionLegal?: string;
  example?: string;
  illustrations?: string[];
  legitimateInterestAllowed: boolean;
  showVendors: boolean;
  vendorsCount?: number;
  legIntVendorsCount?: number;
}

export interface SpecialFeature {
  id: number;
  name: string;
  description: string;
  vendorsCount?: number;
}

export interface SpecialPurpose {
  id: number;
  name: string;
  description: string;
  example?: string;
  illustrations?: string[];
  vendorsCount?: number;
}

export interface Feature {
  id: number;
  name: string;
  description: string;
  vendorsCount?: number;
}

export interface Stack {
  id: number;
  name: string;
  description: string;
  purposes: number[];
}

export interface CustomConsent {
  id: string;
  title: string;
  description: string;
  legitimateInterestAllowed: boolean;
}

export interface VendorUrl {
  langId: string;
  privacy: string;
  legIntClaim?: string;
}

export interface DataRetention {
  stdRetention: number;
  purposes: Record<string, number>;
  specialPurposes: Record<string, number>;
}

export interface Vendor {
  id: number;
  name: string;
  purposes: number[];
  legIntPurposes: number[];
  flexiblePurposes?: number[];
  specialPurposes?: number[];
  features?: number[];
  specialFeatures?: number[];
  cookieMaxAgeSeconds?: number;
  usesCookies?: boolean;
  cookieRefresh?: boolean;
  usesNonCookieAccess?: boolean;
  dataRetention?: DataRetention;
  urls: VendorUrl[];
  dataDeclaration?: number[];
  deviceStorageDisclosureUrl?: string;
  deletedDate?: string; // Date when vendor was deleted from GVL
}

export interface CustomVendor {
  id: number;
  name: string;
  urls: VendorUrl[];
}

/** What a first-layer button does. */
export type FirstLayerAction = 'accept-all' | 'reject-all' | 'settings' | 'partners';

/** Visual weight of a button. */
export type ButtonVariant = 'primary' | 'secondary' | 'link';

export interface FirstLayerButton {
  action: FirstLayerAction;
  variant?: ButtonVariant;
  /** Custom label; empty = the default text for the action. */
  label?: string;
}

/**
 * Compliance profile of a template:
 * - certified: the SDK enforces the regulator/TCF-friendly layout (accept and
 *   reject side by side with equal weight, settings always reachable);
 * - custom: the publisher's button setup is rendered as configured — the
 *   publisher takes the legal risk.
 */
export type ComplianceProfile = 'certified' | 'custom';

export interface FirstLayerConfig {
  buttons?: FirstLayerButton[];
  /** "×" in the corner of layer 1: none, or close = reject all ("continue without accepting"). */
  closeButton?: 'none' | 'reject-all';
  /** TCF mode: list purposes/stacks/special features and the partner count on layer 1. */
  showTcfSummary?: boolean;
}

export interface AppearanceConfig {
  layout?: 'modal' | 'bar' | 'corner';
  logoUrl?: string;
  logoAlt?: string;
  colors?: {
    primary?: string;
    primaryText?: string;
    background?: string;
    text?: string;
    overlay?: string;
  };
  borderRadius?: number;
  buttonRadius?: number;
  /** 'inherit' = the site's font, 'system' = OS UI font, anything else = CSS font-family. */
  fontFamily?: string;
  uppercaseButtons?: boolean;
  overlay?: boolean;
  blockScroll?: boolean;
  reopenButton?: {
    enabled?: boolean;
    position?: 'bottom-left' | 'bottom-right';
  };
  privacyPolicyUrl?: string;
  customCss?: string;
}

export interface Text {
  firstLayer: {
    title: string;
    description: string;
    partnersLinkText: string;
    settingsButtonText: string;
    acceptAllButtonText: string;
    rejectAllButtonText?: string;
    closeButtonLabel?: string;
    privacyPolicyLinkText?: string;
    /** Supports {iab} and {other} placeholders. */
    vendorCountText?: string;
    purposesIntroText?: string;
    reopenButtonLabel?: string;
  };
  secondLayer: {
    title: string;
    description: string;
    partnersLinkText: string;
    backButtonText: string;
    preferencesTitle: string;
    confirmButtonText: string;
    rejectButtonText: string;
    acceptAllButtonText: string;
  };
  thirdLayer: {
    title: string;
    description: string;
    backButtonText: string;
    confirmButtonText: string;
    rejectButtonText: string;
    acceptAllButtonText: string;
    privacyPolicyText: string;
    legitimateInterestText: string;
    objectButtonText: string;
    alwaysActiveText: string;
    searchPlaceholder?: string;
    vendorGroups: {
      iab: string;
      google: string;
      other: string;
    };
    categoriesText: {
      dataDeclaration: string;
      stdRetention: string;
      cookieMaxAgeSeconds: string;
      purposes: string;
      specialPurposes: string;
      features: string;
      specialFeatures: string;
      legIntPurposes: string;
    };
  };
  common: {
    exampleUseText: string;
    objectButtonText: string;
  };
  /** Optional overrides of the built-in microcopy (see i18n/uiTexts.ts). */
  ui?: Partial<Record<UiTextKey, string>>;
}

export interface DataCategory {
  id: number;
  name: string;
  description: string;
}

export interface CMPConfig {
  language: string;
  cmpId: number;
  cmpVersion: number;
  tcfPolicyVersion: number;
  vendorListVersion: number;
  publisherCountryCode: string;
  isServiceSpecific: boolean;
  useNonStandardStacks: boolean;
  consentScreen: number;
  cookieName: string;
  cookieExpirationDays: number;
  gvlUrl?: string;
  gvlBaseUrl?: string;
  cacheVersion?: number;
  // Tryb pracy CMP: pełny (TCF + Consent Mode), tylko Google Consent Mode v2, lub podstawowy (zgody niestandardowe)
  mode?: 'tcf_gcm' | 'gcm_only' | 'basic';
  // Konfiguracja Google Consent Mode v2 (mapowanie celów IAB na sygnały gtag)
  googleConsentMode?: {
    enabled: boolean;
    mapping: Record<string, number[]>;
    waitForUpdate: number;
    urlPassthrough?: boolean;
    adsDataRedaction?: boolean;
  } | null;
  reconsentID?: number; // ID wymagający reconsentu, zazwyczaj timestamp ostatniej zmiany konfiguracji
  /** Ask again after this many days (0 = never); default 395 (13 months). */
  consentMaxAgeDays?: number;
  /** Ask again when vendors the user was not shown are added (default false). */
  renewOnNewVendors?: boolean;
  texts: Text;
  purposes: Record<string, Purpose>;
  specialFeatures: Record<string, SpecialFeature>;
  specialPurposes: Record<string, SpecialPurpose>;
  features: Record<string, Feature>;
  stacks: Record<string, Stack>;
  dataCategories?: Record<string, DataCategory>;
  dimensions?: { width?: number | null; height?: number | null };
  appearance?: AppearanceConfig | null;
  /** Send each decision to the plugin's consent log (default true). */
  consentLog?: boolean;
  templateId?: number;
  firstLayer?: FirstLayerConfig | null;
  complianceProfile?: ComplianceProfile;
  customConsents: CustomConsent[];
  vendors: {
    iab: Record<string, Vendor>;
    custom: {
      google: Record<string, CustomVendor>;
      other: Record<string, CustomVendor>;
    };
  };
  // Which vendor groups have enabled vendors in the active list. A group set to
  // false is hidden in the consent box (even in full mode). Defaults to visible
  // when absent (backward compatible).
  visibleVendorGroups?: {
    iab: boolean;
    google: boolean;
    other: boolean;
  };
  // IAB vendor IDs enabled in the active list. The SDK restricts vendors.iab to
  // these IDs (shown + consented). null/absent means no restriction (show all
  // globally enabled IAB vendors from the GVL).
  iabVendorIds?: number[] | null;
  // Optional metadata fields for vendor counts
  purposesMetadata?: {
    vendorCounts: Record<
      string,
      {
        purposes: number;
        legIntPurposes: number;
      }
    >;
  };
  specialFeaturesMetadata?: {
    vendorCounts: Record<string, number>;
  };
  specialPurposesMetadata?: {
    vendorCounts: Record<string, number>;
  };
  featuresMetadata?: {
    vendorCounts: Record<string, number>;
  };
}

export interface ConsentState {
  purposeConsents: Record<string, boolean>;
  purposeLegitimateInterests: Record<string, boolean>;
  specialFeatureOptins: Record<string, boolean>;
  vendorConsents: Record<string, boolean>;
  vendorLegitimateInterests: Record<string, boolean>;
  customVendorConsents: Record<string, boolean>;
  customPurposeConsents: Record<string, boolean>;
  customPurposeLegitimateInterests: Record<string, boolean>;
}

export enum ConsentAction {
  ACCEPT_ALL = 'ACCEPT_ALL',
  REJECT_ALL = 'REJECT_ALL',
  SAVE_PREFERENCES = 'SAVE_PREFERENCES',
}

export enum CMPEventType {
  CONSENT_UI_SHOWN = 'consent-ui-shown',
  CONSENT_UI_CLOSED = 'consent-ui-closed',
  CONSENT_BUTTON_CLICKED = 'consent-button-clicked',
  CONSENT_CHANGED = 'consent-changed',
  CONSENT_ERROR = 'consent-error',
  // Phase 1 extensions
  LAYER_CHANGED = 'consent-layer-changed',
  CHECKBOX_TOGGLED = 'consent-checkbox-toggled',
}

export interface CMPEventDetail {
  type: CMPEventType;
  layer?: number;
  buttonId?: string;
  action?: ConsentAction;
  error?: Error | string | unknown;
  // Phase 1 extensions
  fromLayer?: number;
  toLayer?: number;
  checkboxId?: string;
  checkboxValue?: boolean;
  checkboxType?: 'purpose' | 'vendor' | 'specialFeature' | 'customConsent';
}

// Vendor list data interface - flexible for external data
export interface VendorListData {
  vendors: Record<string, Vendor>;
  purposes: Record<string, Purpose>;
  specialFeatures: Record<string, SpecialFeature>;
  specialPurposes: Record<string, SpecialPurpose>;
  features: Record<string, Feature>;
  stacks: Record<string, Stack>;
  dataCategories: Record<string, DataCategory>;
  gvlSpecificationVersion: number;
  vendorListVersion: number;
  tcfPolicyVersion: number;
  lastUpdated: string;
  [key: string]: unknown; // Allow additional properties
}

// Type guard to safely convert VendorListData to IAB format
export function toIABVendorList(data: VendorListData): VersionOrVendorList {
  // Safe conversion through unknown for incompatible but structurally similar types
  return data as unknown as VersionOrVendorList;
}

// Google Tag Manager slot interface
export interface GoogleTagSlot {
  getSlotElementId(): string;
  [key: string]: unknown;
}

// CMP API interface
export interface CMPApi {
  // UI Control
  showLayer(layer: 1 | 2 | 3): void;
  hideUI(): void;
  showUI(layer?: number): void;
  getCurrentLayer(): number | null;

  // Consent Management
  acceptAll(): void;
  rejectAll(): void;
  saveCurrentState(): void;
  resetToDefaults(): void;

  // State Queries
  getCurrentConsents(): ConsentState;
  isConsentGiven(purposeId: string): boolean;
  isVendorConsented(vendorId: string): boolean;

  // Configuration
  getConfig(): CMPConfig;

  // Events
  on(eventType: string, callback: (detail: CMPEventDetail) => void): void;
  off(eventType: string, callback: (detail: CMPEventDetail) => void): void;

  // Debug
  getDebugInfo(): Record<string, unknown>;
  exportConsents(): string;
  importConsents(data: string): boolean;
}

// Extend global Window interface for TCF API and Google Tag Manager
declare global {
  interface Window {
    __tcfapi?: (command: string, version: number, callback: TCFCallback) => void;
    __vendorListData?: VendorListData | null;
    /** Injected by the plugin's /sdk endpoint before the bundle (see build_sdk_boot). */
    __focmpBoot?: SdkBoot;
    __cmp?: CMPApi;
    // Google Consent Mode v2 (gtag) — wstrzykiwane przez SDK lub przez stronę
    dataLayer?: unknown[];
    gtag?: (...args: unknown[]) => void;
    googletag?: {
      cmd: Array<() => void>;
      pubads(): {
        getSlots(): Array<GoogleTagSlot>;
        refresh(slots?: Array<GoogleTagSlot>): void;
        isInitialLoadDisabled?(): boolean;
      };
    };
  }
}

/** Settings the SDK needs before /cmp-config is fetched. */
export interface SdkBoot {
  env?: 'prod' | 'test';
  cmpId?: number;
  cmpVersion?: number;
  gcm?: {
    enabled: boolean;
    waitForUpdate: number;
    urlPassthrough?: boolean;
    adsDataRedaction?: boolean;
  };
}

// TCF API types
export interface TCFCallback {
  (success: boolean, data: TCData | VendorListData | PingReturn | unknown): void;
}

// TCF Data interface
export interface TCData {
  tcString: string;
  tcfPolicyVersion: number;
  cmpId: number;
  cmpVersion: number;
  cmpStatus: string;
  cmpDisplayStatus: string;
  gdprApplies: boolean;
  eventStatus: string;
  isServiceSpecific: boolean;
  useNonStandardStacks: boolean;
  publisherCC: string;
  purposeOneTreatment: boolean;
  purpose: {
    consents: Record<string, boolean>;
    legitimateInterests: Record<string, boolean>;
  };
  vendor: {
    consents: Record<string, boolean>;
    legitimateInterests: Record<string, boolean>;
  };
  specialFeatureOptins: Record<string, boolean>;
  publisher: {
    consents: Record<string, boolean>;
    legitimateInterests: Record<string, boolean>;
    customPurpose: {
      consents: Record<string, boolean>;
      legitimateInterests: Record<string, boolean>;
    };
    restrictions: Record<string, unknown>;
  };
  addtlConsent?: string;
}

// Extendable TCData for adding additional properties
export interface ExtendableTCData extends TCData {
  [key: string]: unknown;
}

// Ping return interface
export interface PingReturn {
  gdprApplies: boolean;
  cmpLoaded: boolean;
  cmpStatus: string;
  displayStatus: string;
  apiVersion: string;
  cmpVersion: number;
  cmpId: number;
  gvlVersion: number;
  tcfPolicyVersion: number;
}

export interface CustomCommands {
  [command: string]: (
    next: (tcData: unknown, status: boolean) => void,
    tcData: unknown,
    status: boolean,
  ) => void;
}
