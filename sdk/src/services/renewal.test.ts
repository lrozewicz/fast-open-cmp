import { describe, it, expect } from 'vitest';
import { renewalReason } from './renewal';

const DAY = 24 * 60 * 60 * 1000;
const now = Date.UTC(2026, 9, 14);

describe('renewalReason', () => {
  it('keeps a fresh decision', () => {
    expect(
      renewalReason({
        tcf: true,
        storedPolicyVersion: 5,
        currentPolicyVersion: 5,
        lastDecision: now - 30 * DAY,
        disclosedVendors: [1, 2, 3],
        currentVendors: [1, 2],
        now,
      }),
    ).toBeNull();
  });

  it('renews after a TCF Policy version change', () => {
    expect(renewalReason({ tcf: true, storedPolicyVersion: 4, currentPolicyVersion: 5, now })).toBe(
      'policy-version',
    );
  });

  it('renews decisions older than the maximum age (default 13 months)', () => {
    expect(renewalReason({ tcf: false, lastDecision: now - 396 * DAY, now })).toBe('max-age');
    expect(renewalReason({ tcf: false, lastDecision: now - 394 * DAY, now })).toBeNull();
    expect(renewalReason({ tcf: false, lastDecision: now - 40 * DAY, maxAgeDays: 30, now })).toBe(
      'max-age',
    );
    expect(
      renewalReason({ tcf: false, lastDecision: now - 900 * DAY, maxAgeDays: 0, now }),
    ).toBeNull();
  });

  it('renews when vendors the user never saw were added', () => {
    const base = { tcf: true, lastDecision: now, disclosedVendors: [1, 2], now };
    expect(renewalReason({ ...base, currentVendors: [1, 2, 7], renewOnNewVendors: true })).toBe(
      'new-vendors',
    );
    // Off by default
    expect(renewalReason({ ...base, currentVendors: [1, 2, 7] })).toBeNull();
    // Strings written before the Disclosed Vendors segment was populated
    expect(
      renewalReason({
        ...base,
        disclosedVendors: [],
        currentVendors: [1, 2, 7],
        renewOnNewVendors: true,
      }),
    ).toBeNull();
  });
});
