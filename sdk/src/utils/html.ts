/**
 * HTML helpers for building consent box markup with template strings.
 *
 * Every value that is not trusted, pre-sanitized rich text (admin descriptions go
 * through wp_kses_post on the server) must pass through escapeHtml() / safeUrl()
 * before being interpolated into innerHTML.
 */

const HTML_ESCAPES: Record<string, string> = {
  '&': '&amp;',
  '<': '&lt;',
  '>': '&gt;',
  '"': '&quot;',
  "'": '&#39;',
};

/** Escapes text for use in element content and quoted attribute values. */
export const escapeHtml = (value: unknown): string => {
  if (value === null || value === undefined) return '';
  return String(value).replace(/[&<>"']/g, (ch) => HTML_ESCAPES[ch]);
};

const SAFE_URL = /^(https?:|mailto:|tel:|\/|#|\?)/i;

/**
 * Returns an escaped URL safe for an href attribute. Anything that is not
 * http(s)/mailto/tel or a relative URL (e.g. javascript:, data:) becomes "#".
 */
export const safeUrl = (value: unknown): string => {
  if (typeof value !== 'string') return '#';
  const url = value.trim();
  if (!url || !SAFE_URL.test(url)) return '#';
  return escapeHtml(url);
};
