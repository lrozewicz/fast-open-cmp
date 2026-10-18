/**
 * The snippet identifies its domain by a random public key (`?key=…`); snippets
 * from before keys existed use the numeric domain id. Both reach the SDK as the
 * second argument of `OpenCMP.init()`.
 */
export const isNumericDomainRef = (ref: string | number | undefined | null): boolean =>
  /^\d+$/.test(String(ref ?? ''));

/** Query parameters for /cmp-config. */
export const domainQuery = (ref: string | undefined): Record<string, string> => {
  if (!ref) return {};
  return isNumericDomainRef(ref) ? { domainId: String(ref) } : { key: String(ref) };
};

/** Fields of a consent log record. */
export const domainRecordFields = (
  ref: string | undefined,
): { domainId: number } | { domainKey: string } =>
  isNumericDomainRef(ref) ? { domainId: Number(ref) } : { domainKey: String(ref || '') };
