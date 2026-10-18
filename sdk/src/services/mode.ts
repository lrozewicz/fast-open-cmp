import { CMPConfig } from '../models/types';

export type CmpMode = NonNullable<CMPConfig['mode']>; // 'tcf_gcm' | 'gcm_only' | 'basic'

export const CMP_MODES: readonly CmpMode[] = ['tcf_gcm', 'gcm_only', 'basic'];

export const DEFAULT_MODE: CmpMode = 'tcf_gcm';

/**
 * Validate a raw `mode` value coming from the API/config.
 *
 * Known values pass through. Missing values fall back to the default silently
 * (backward compatible — old configs have no `mode`). An unknown non-empty value
 * is a misconfiguration, so we warn (kept in production builds — only
 * console.log/info/debug are stripped) and fall back rather than degrade silently.
 */
export function normalizeMode(raw: unknown): CmpMode {
  if (typeof raw === 'string' && (CMP_MODES as readonly string[]).includes(raw)) {
    return raw as CmpMode;
  }
  if (raw !== undefined && raw !== null && raw !== '') {
    console.warn(`[CMP] Unknown mode "${String(raw)}", falling back to "${DEFAULT_MODE}".`);
  }
  return DEFAULT_MODE;
}
