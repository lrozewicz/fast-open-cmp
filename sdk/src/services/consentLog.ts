import cookieService from './cookie';
import { domainRecordFields } from './domainRef';
import { CMPConfig, ConsentAction } from '../models/types';

/**
 * Sends one record per consent decision to the plugin's consent log
 * (POST /consent), the publisher's proof of consent (GDPR Art. 7(1)).
 *
 * The record is pseudonymous: a random consent id stored in the visitor's cookie
 * (shown in the settings layer, so the visitor can quote it in a data request),
 * the stored consent strings and the page URL without query string. The server
 * adds the template and an anonymised IP. Records wait in a small localStorage
 * queue until the server confirms them (retried on later page views).
 */
export const CONSENT_ID_COOKIE_NAME = 'cmp-consent-id';

export type LoggedAction = 'accept-all' | 'reject-all' | 'save' | 'close-reject';

const UUID_RE = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;

const uuidV4 = (): string => {
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
    return crypto.randomUUID();
  }
  const b = new Uint8Array(16);
  crypto.getRandomValues(b);
  b[6] = (b[6] & 0x0f) | 0x40;
  b[8] = (b[8] & 0x3f) | 0x80;
  const h = Array.from(b, (x) => x.toString(16).padStart(2, '0')).join('');
  return `${h.slice(0, 8)}-${h.slice(8, 12)}-${h.slice(12, 16)}-${h.slice(16, 20)}-${h.slice(20)}`;
};

/** The visitor's consent id, if a decision was logged before. */
export const getConsentId = (): string | null => {
  const id = cookieService.getCookie(CONSENT_ID_COOKIE_NAME);
  return id && UUID_RE.test(id) ? id : null;
};

const getOrCreateConsentId = (days: number): string => {
  const existing = getConsentId();
  if (existing) return existing;
  const id = uuidV4();
  cookieService.setCookie(CONSENT_ID_COOKIE_NAME, id, days);
  return id;
};

/** Maps the SDK's consent action (+ the clicked control) to the logged action. */
export const toLoggedAction = (action?: ConsentAction, button?: string): LoggedAction | null => {
  if (button === 'close-reject') return 'close-reject';
  switch (action) {
    case ConsentAction.ACCEPT_ALL:
      return 'accept-all';
    case ConsentAction.REJECT_ALL:
      return 'reject-all';
    case ConsentAction.SAVE_PREFERENCES:
      return 'save';
    default:
      return null;
  }
};

export interface ConsentLogContext {
  baseUrl: string;
  /** Public key of the domain, or its numeric id (older snippets). */
  domainRef?: string;
  env: 'prod' | 'test';
}

export const buildConsentRecord = (
  config: CMPConfig,
  ctx: ConsentLogContext,
  action: LoggedAction,
  consentId: string,
  values: { tcString?: string | null; acString?: string | null },
): Record<string, unknown> => ({
  consentId,
  // Idempotency key: retries of this decision are ignored by the server.
  recordId: uuidV4(),
  ...domainRecordFields(ctx.domainRef),
  env: ctx.env,
  action,
  mode: config.mode || 'tcf_gcm',
  policyVersion: config.tcfPolicyVersion,
  gvlVersion: config.vendorListVersion,
  cmpVersion: config.cmpVersion,
  reconsentId: config.reconsentID || undefined,
  tcString: values.tcString || undefined,
  acString: values.acString || undefined,
  customConsents: cookieService.getCookie('cmp-custom-consents') || undefined,
  customVendors: cookieService.getCookie('custom-vendors-consent') || undefined,
  pageUrl: `${window.location.origin}${window.location.pathname}`,
});

const QUEUE_KEY = 'focmp-consent-queue';
const QUEUE_MAX = 20;
const QUEUE_MAX_AGE = 7 * 24 * 60 * 60 * 1000;

interface QueuedRecord {
  url: string;
  body: string;
  recordId: string;
  queuedAt: number;
}

const readQueue = (): QueuedRecord[] => {
  try {
    const items = JSON.parse(localStorage.getItem(QUEUE_KEY) || '[]');
    return Array.isArray(items)
      ? items.filter((i) => i && i.recordId && Date.now() - i.queuedAt < QUEUE_MAX_AGE)
      : [];
  } catch {
    return [];
  }
};

const writeQueue = (items: QueuedRecord[]): void => {
  try {
    if (items.length) localStorage.setItem(QUEUE_KEY, JSON.stringify(items.slice(-QUEUE_MAX)));
    else localStorage.removeItem(QUEUE_KEY);
  } catch {
    // storage unavailable — the record is sent once without retries
  }
};

const dequeue = (recordId: string): void =>
  writeQueue(readQueue().filter((i) => i.recordId !== recordId));

/**
 * Sends one queued record. It leaves the queue when the server stored it (or
 * already had it), or rejected it for good (4xx other than 429); on 429/5xx or a
 * network error it stays queued for the next page view. Retries are safe: the
 * server ignores a recordId it already has.
 */
const deliver = (item: QueuedRecord): Promise<void> =>
  fetch(item.url, {
    method: 'POST',
    body: item.body,
    keepalive: true,
    // text/plain: a "simple" request, no CORS preflight from the publisher's site
    headers: { 'Content-Type': 'text/plain' },
  })
    .then((response) => {
      if (
        response.ok ||
        (response.status >= 400 && response.status < 500 && response.status !== 429)
      ) {
        dequeue(item.recordId);
      }
    })
    .catch(() => {
      // offline / blocked — keep it queued
    });

/** Queues the record and sends it right away. */
export const sendConsentRecord = (
  ctx: ConsentLogContext,
  record: Record<string, unknown>,
): Promise<void> => {
  const item: QueuedRecord = {
    url: new URL(`${ctx.baseUrl}/consent`).toString(),
    body: JSON.stringify(record),
    recordId: String(record.recordId),
    queuedAt: Date.now(),
  };
  writeQueue([...readQueue(), item]);
  return deliver(item);
};

/** Re-sends records a previous page view could not deliver. */
export const flushConsentQueue = (): Promise<void> =>
  Promise.all(readQueue().map(deliver)).then(() => undefined);

export const logConsentDecision = (
  config: CMPConfig,
  ctx: ConsentLogContext,
  action: LoggedAction,
  values: { tcString?: string | null; acString?: string | null },
): void => {
  if (config.consentLog === false || !ctx.domainRef) return;
  const consentId = getOrCreateConsentId(config.cookieExpirationDays || 180);
  sendConsentRecord(ctx, buildConsentRecord(config, ctx, action, consentId, values));
};
