import { describe, it, expect } from 'vitest';
import { escapeHtml, safeUrl } from './html';

describe('escapeHtml', () => {
  it('escapes markup and quotes', () => {
    expect(escapeHtml('<img src=x onerror="alert(1)">')).toBe(
      '&lt;img src=x onerror=&quot;alert(1)&quot;&gt;',
    );
    expect(escapeHtml("O'Reilly & Co")).toBe('O&#39;Reilly &amp; Co');
  });

  it('handles null/undefined/numbers', () => {
    expect(escapeHtml(undefined)).toBe('');
    expect(escapeHtml(null)).toBe('');
    expect(escapeHtml(42)).toBe('42');
  });
});

describe('safeUrl', () => {
  it('keeps http(s), mailto and relative URLs', () => {
    expect(safeUrl('https://example.com/a?b=1&c=2')).toBe('https://example.com/a?b=1&amp;c=2');
    expect(safeUrl('mailto:dpo@example.com')).toBe('mailto:dpo@example.com');
    expect(safeUrl('/polityka-prywatnosci')).toBe('/polityka-prywatnosci');
  });

  it('neutralises script and data URLs', () => {
    expect(safeUrl('javascript:alert(1)')).toBe('#');
    expect(safeUrl('  JavaScript:alert(1)')).toBe('#');
    expect(safeUrl('data:text/html,<script>')).toBe('#');
    expect(safeUrl(undefined)).toBe('#');
  });

  it('escapes quotes so the attribute cannot be broken out of', () => {
    expect(safeUrl('https://x.test/"onmouseover="alert(1)')).toBe(
      'https://x.test/&quot;onmouseover=&quot;alert(1)',
    );
  });
});
