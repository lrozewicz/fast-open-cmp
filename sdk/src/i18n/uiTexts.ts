/**
 * Built-in microcopy of the consent box (counts, statuses, vendor details) that
 * is not part of the template's editable texts. Phrased so that no plural forms
 * are needed ("Partners that may use this purpose: 12"), gender-neutral.
 *
 * Templates may override any key via texts.ui.<key> (sent by the server).
 */
export type UiTextKey =
  | 'partnersPurpose'
  | 'partnersSpecialPurpose'
  | 'partnersFeature'
  | 'partnersSpecialFeature'
  | 'objectionFiled'
  | 'searchVendors'
  | 'filterCount'
  | 'vendorInactive'
  | 'vendorInactiveSince'
  | 'noConsentRequired'
  | 'noConsentRequiredTitle'
  | 'dataCategory'
  | 'retentionDays'
  | 'cookieMaxAge'
  | 'vendorListInfo'
  | 'consentId';

type Dictionary = Record<UiTextKey, string>;

const pl: Dictionary = {
  consentId: 'Identyfikator Twojej zgody: {id}',
  partnersPurpose: 'Partnerzy mogący użyć tego celu: {count}',
  partnersSpecialPurpose: 'Partnerzy mogący użyć tego celu specjalnego: {count}',
  partnersFeature: 'Partnerzy mogący użyć tej funkcji: {count}',
  partnersSpecialFeature: 'Partnerzy mogący użyć tej funkcji specjalnej: {count}',
  objectionFiled: 'Zgłoszono sprzeciw',
  searchVendors: 'Wyszukaj partnerów',
  filterCount: 'partnerzy: {count}',
  vendorInactive: 'nieaktywny',
  vendorInactiveSince: 'Partner nieaktywny od {date}',
  noConsentRequired: 'Nie wymaga zgody',
  noConsentRequiredTitle:
    'Ten partner nie prosi o zgodę — korzysta wyłącznie z prawnie uzasadnionego interesu',
  dataCategory: 'Kategoria {id}',
  retentionDays: 'Liczba dni: {count}',
  cookieMaxAge: 'Liczba dni: {days} ({seconds} s)',
  vendorListInfo:
    'Aktywnej zgody wymagają tylko partnerzy z polem wyboru. Pozostali działają na podstawie prawnie uzasadnionego interesu. Partnerzy oznaczeni jako nieaktywni nie uczestniczą już w systemie.',
};

const en: Dictionary = {
  consentId: 'Your consent ID: {id}',
  partnersPurpose: 'Partners that may use this purpose: {count}',
  partnersSpecialPurpose: 'Partners that may use this special purpose: {count}',
  partnersFeature: 'Partners that may use this feature: {count}',
  partnersSpecialFeature: 'Partners that may use this special feature: {count}',
  objectionFiled: 'Objection filed',
  searchVendors: 'Search partners',
  filterCount: 'partners: {count}',
  vendorInactive: 'inactive',
  vendorInactiveSince: 'Partner inactive since {date}',
  noConsentRequired: 'No consent required',
  noConsentRequiredTitle:
    'This partner does not ask for consent — it relies on legitimate interest only',
  dataCategory: 'Category {id}',
  retentionDays: 'Days: {count}',
  cookieMaxAge: 'Days: {days} ({seconds} s)',
  vendorListInfo:
    'Only partners with a checkbox need your active consent. The others rely on legitimate interest. Partners marked as inactive no longer take part in the framework.',
};

const de: Dictionary = {
  consentId: 'Ihre Einwilligungs-ID: {id}',
  partnersPurpose: 'Partner, die diesen Zweck nutzen können: {count}',
  partnersSpecialPurpose: 'Partner, die diesen besonderen Zweck nutzen können: {count}',
  partnersFeature: 'Partner, die diese Funktion nutzen können: {count}',
  partnersSpecialFeature: 'Partner, die diese besondere Funktion nutzen können: {count}',
  objectionFiled: 'Widerspruch eingelegt',
  searchVendors: 'Partner suchen',
  filterCount: 'Partner: {count}',
  vendorInactive: 'inaktiv',
  vendorInactiveSince: 'Partner inaktiv seit {date}',
  noConsentRequired: 'Keine Einwilligung erforderlich',
  noConsentRequiredTitle:
    'Dieser Partner fragt nicht nach Einwilligung – er stützt sich nur auf berechtigtes Interesse',
  dataCategory: 'Kategorie {id}',
  retentionDays: 'Tage: {count}',
  cookieMaxAge: 'Tage: {days} ({seconds} s)',
  vendorListInfo:
    'Nur Partner mit Kontrollkästchen benötigen Ihre aktive Einwilligung. Die übrigen stützen sich auf berechtigtes Interesse. Als inaktiv markierte Partner nehmen nicht mehr am Framework teil.',
};

const fr: Dictionary = {
  consentId: 'Votre identifiant de consentement : {id}',
  partnersPurpose: 'Partenaires pouvant utiliser cette finalité : {count}',
  partnersSpecialPurpose: 'Partenaires pouvant utiliser cette finalité spéciale : {count}',
  partnersFeature: 'Partenaires pouvant utiliser cette fonctionnalité : {count}',
  partnersSpecialFeature: 'Partenaires pouvant utiliser cette fonctionnalité spéciale : {count}',
  objectionFiled: 'Opposition enregistrée',
  searchVendors: 'Rechercher des partenaires',
  filterCount: 'partenaires : {count}',
  vendorInactive: 'inactif',
  vendorInactiveSince: 'Partenaire inactif depuis le {date}',
  noConsentRequired: 'Aucun consentement requis',
  noConsentRequiredTitle:
    'Ce partenaire ne demande pas de consentement — il se fonde uniquement sur l’intérêt légitime',
  dataCategory: 'Catégorie {id}',
  retentionDays: 'Jours : {count}',
  cookieMaxAge: 'Jours : {days} ({seconds} s)',
  vendorListInfo:
    'Seuls les partenaires avec une case à cocher ont besoin de votre consentement actif. Les autres se fondent sur l’intérêt légitime. Les partenaires marqués comme inactifs ne participent plus au framework.',
};

const DICTIONARIES: Record<string, Dictionary> = { pl, en, de, fr };

/** Dictionary for a language code (e.g. "pl", "en-GB"); English when unknown. */
export const uiDictionary = (language?: string): Dictionary =>
  DICTIONARIES[(language || 'en').slice(0, 2).toLowerCase()] || en;

/**
 * Text for a key in the given language, with {placeholders} filled in.
 * `overrides` are template-provided texts (texts.ui) and win over the built-ins.
 */
export const uiText = (
  language: string | undefined,
  key: UiTextKey,
  values: Record<string, string | number> = {},
  overrides?: Partial<Record<UiTextKey, string>>,
): string => {
  const template = overrides?.[key] || uiDictionary(language)[key];
  return template.replace(/\{(\w+)\}/g, (m, k) => (k in values ? String(values[k]) : m));
};

// Language of the consent box currently rendered (set by ConsentManager), so the
// item renderers can use t() without threading the config through every call.
let active: { language?: string; overrides?: Partial<Record<UiTextKey, string>> } = {};

export const setUiLanguage = (
  language: string | undefined,
  overrides?: Partial<Record<UiTextKey, string>>,
): void => {
  active = { language, overrides };
};

export const t = (key: UiTextKey, values: Record<string, string | number> = {}): string =>
  uiText(active.language, key, values, active.overrides);
