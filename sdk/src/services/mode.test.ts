import { describe, it, expect, vi, afterEach } from 'vitest';
import { normalizeMode, CMP_MODES, DEFAULT_MODE } from './mode';

describe('normalizeMode', () => {
  afterEach(() => vi.restoreAllMocks());

  it('passes through each known mode unchanged', () => {
    for (const mode of CMP_MODES) {
      expect(normalizeMode(mode)).toBe(mode);
    }
  });

  it('falls back to the default for missing values without warning', () => {
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
    expect(normalizeMode(undefined)).toBe(DEFAULT_MODE);
    expect(normalizeMode(null)).toBe(DEFAULT_MODE);
    expect(normalizeMode('')).toBe(DEFAULT_MODE);
    expect(warn).not.toHaveBeenCalled();
  });

  it('warns and falls back for an unknown non-empty value', () => {
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
    expect(normalizeMode('tcf')).toBe(DEFAULT_MODE);
    expect(normalizeMode(42)).toBe(DEFAULT_MODE);
    expect(warn).toHaveBeenCalledTimes(2);
    expect(warn.mock.calls[0][0]).toContain('Unknown mode');
  });
});
