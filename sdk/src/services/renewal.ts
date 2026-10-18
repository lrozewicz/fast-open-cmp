/**
 * Decides whether a stored consent decision must be collected again.
 *
 * Besides the publisher-triggered reconsent (reconsentID), a decision is renewed when:
 *  - the TCF Policy version it was given under is older than the current one;
 *  - it is older than the configured maximum age (default 13 months — the
 *    period regulators such as CNIL consider reasonable);
 *  - optionally (renewOnNewVendors), the vendor list grew by vendors the user was
 *    never shown (compared with the TC String's Disclosed Vendors segment; skipped
 *    when that segment is empty, i.e. for strings written before it was populated).
 *    Off by default: new vendors simply have no consent in the stored string, so
 *    this is about reach, not compliance — and GVL updates add vendors weekly.
 */
export interface RenewalInput {
  tcf: boolean;
  /** Policy version stored in the TC String (tcf only). */
  storedPolicyVersion?: number;
  currentPolicyVersion?: number;
  /** When the stored decision was last made (ms since epoch). */
  lastDecision?: number;
  maxAgeDays?: number;
  /** Vendor ids from the Disclosed Vendors segment (tcf only). */
  disclosedVendors?: number[];
  /** IAB vendor ids currently presented in the consent box. */
  currentVendors?: number[];
  renewOnNewVendors?: boolean;
  now: number;
}

export type RenewalReason = 'policy-version' | 'max-age' | 'new-vendors';

export const DEFAULT_MAX_AGE_DAYS = 395;

export const renewalReason = (input: RenewalInput): RenewalReason | null => {
  if (
    input.tcf &&
    input.storedPolicyVersion !== undefined &&
    input.currentPolicyVersion !== undefined &&
    input.storedPolicyVersion < input.currentPolicyVersion
  ) {
    return 'policy-version';
  }

  const maxAgeDays = input.maxAgeDays ?? DEFAULT_MAX_AGE_DAYS;
  if (maxAgeDays > 0 && input.lastDecision && input.lastDecision > 0) {
    if (input.now - input.lastDecision > maxAgeDays * 24 * 60 * 60 * 1000) {
      return 'max-age';
    }
  }

  if (input.tcf && input.renewOnNewVendors === true) {
    const disclosed = new Set(input.disclosedVendors || []);
    if (disclosed.size > 0 && (input.currentVendors || []).some((id) => !disclosed.has(id))) {
      return 'new-vendors';
    }
  }

  return null;
};
