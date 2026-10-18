import cookieService from './cookie';

/**
 * Storage of the publisher's custom consents in the modes without TCF
 * (gcm_only, basic), where no TC String is written. In tcf_gcm mode custom
 * consents live in the TC String's publisher custom purposes segment instead.
 *
 * Compact format, like the custom vendors cookie:
 *   c:custom-1.custom-3|l:custom-2|t:1769720229228
 *   c = consented ids, l = ids with legitimate interest (no objection), t = timestamp
 */
export const CUSTOM_PURPOSES_COOKIE_NAME = 'cmp-custom-consents';

export interface CustomPurposeChoices {
  consents: Record<string, boolean>;
  legitimateInterests: Record<string, boolean>;
  timestamp: number;
}

const ID_RE = /^[A-Za-z0-9_-]+$/;

export const serializeCustomPurposes = (choices: CustomPurposeChoices): string => {
  const ids = (map: Record<string, boolean>) =>
    Object.keys(map)
      .filter((id) => map[id] && ID_RE.test(id))
      .join('.');
  return `c:${ids(choices.consents)}|l:${ids(choices.legitimateInterests)}|t:${choices.timestamp}`;
};

export const parseCustomPurposes = (value: string | null): CustomPurposeChoices | null => {
  if (!value) return null;
  const choices: CustomPurposeChoices = { consents: {}, legitimateInterests: {}, timestamp: 0 };
  for (const section of value.split('|')) {
    const sep = section.indexOf(':');
    if (sep === -1) continue;
    const key = section.slice(0, sep);
    const body = section.slice(sep + 1);
    const ids = body ? body.split('.').filter((id) => ID_RE.test(id)) : [];
    if (key === 'c') ids.forEach((id) => (choices.consents[id] = true));
    else if (key === 'l') ids.forEach((id) => (choices.legitimateInterests[id] = true));
    else if (key === 't') choices.timestamp = parseInt(body, 10) || 0;
  }
  return choices;
};

export const readCustomPurposes = (): CustomPurposeChoices | null =>
  parseCustomPurposes(cookieService.getCookie(CUSTOM_PURPOSES_COOKIE_NAME));

export const writeCustomPurposes = (
  consents: Record<string, boolean>,
  legitimateInterests: Record<string, boolean>,
  expirationDays: number,
): void => {
  cookieService.setCookie(
    CUSTOM_PURPOSES_COOKIE_NAME,
    serializeCustomPurposes({ consents, legitimateInterests, timestamp: Date.now() }),
    expirationDays,
  );
};

export const clearCustomPurposes = (): void => {
  cookieService.deleteCookie(CUSTOM_PURPOSES_COOKIE_NAME);
};
