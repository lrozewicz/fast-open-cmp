import { Purpose, Vendor } from '../../models/types';

/**
 * Checks if a stack is checked by verifying all purposes in the stack
 * @param stackPurposes - List of purpose IDs in the stack
 * @param purposeConsents - Record of purpose consents
 * @returns True if all purposes in the stack are checked
 */
export const isStackChecked = (
  stackPurposes: number[],
  purposeConsents: Record<string, boolean>,
): boolean => {
  for (const purposeId of stackPurposes) {
    if (!purposeConsents[purposeId]) {
      return false;
    }
  }
  return true;
};

/**
 * Gets the number of distinct vendors that use any purpose in the stack (by
 * consent or legitimate interest). Without the vendor list it falls back to the
 * largest per-purpose count, a lower bound.
 * @param stackPurposes - List of purpose IDs in the stack
 * @param purposes - Record of purposes
 * @param vendors - IAB vendors shown in the consent box
 */
export const getStackVendorsCount = (
  stackPurposes: number[],
  purposes: Record<string, Purpose>,
  vendors?: Record<string, Vendor>,
): number => {
  if (vendors && Object.keys(vendors).length > 0) {
    return Object.values(vendors).filter(
      (v) =>
        !v.deletedDate &&
        stackPurposes.some(
          (p) => (v.purposes || []).includes(p) || (v.legIntPurposes || []).includes(p),
        ),
    ).length;
  }

  let maxVendors = 0;

  for (const purposeId of stackPurposes) {
    const purpose = purposes[purposeId];
    if (purpose && purpose.vendorsCount && purpose.vendorsCount > maxVendors) {
      maxVendors = purpose.vendorsCount;
    }
  }

  return maxVendors;
};
