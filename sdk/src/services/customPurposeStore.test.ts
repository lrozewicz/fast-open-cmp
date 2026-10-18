import { describe, it, expect } from 'vitest';
import { serializeCustomPurposes, parseCustomPurposes } from './customPurposeStore';

describe('custom purpose cookie format', () => {
  it('round-trips consents and legitimate interests', () => {
    const value = serializeCustomPurposes({
      consents: { 'custom-1': true, 'custom-2': false, 'custom-3': true },
      legitimateInterests: { 'custom-2': true },
      timestamp: 1769720229228,
    });
    expect(value).toBe('c:custom-1.custom-3|l:custom-2|t:1769720229228');
    expect(parseCustomPurposes(value)).toEqual({
      consents: { 'custom-1': true, 'custom-3': true },
      legitimateInterests: { 'custom-2': true },
      timestamp: 1769720229228,
    });
  });

  it('serializes an all-rejected choice to empty sections', () => {
    expect(
      serializeCustomPurposes({
        consents: { 'custom-1': false },
        legitimateInterests: {},
        timestamp: 5,
      }),
    ).toBe('c:|l:|t:5');
    expect(parseCustomPurposes('c:|l:|t:5')?.consents).toEqual({});
  });

  it('ignores malformed input', () => {
    expect(parseCustomPurposes(null)).toBeNull();
    expect(parseCustomPurposes('garbage|c:ok-1.<bad>|x:1')?.consents).toEqual({ 'ok-1': true });
  });
});
