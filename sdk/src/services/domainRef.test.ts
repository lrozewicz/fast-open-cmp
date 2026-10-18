import { describe, expect, it } from 'vitest';
import { domainQuery, domainRecordFields, isNumericDomainRef } from './domainRef';

describe('domain reference (public key or legacy id)', () => {
  it('tells numeric ids from keys', () => {
    expect(isNumericDomainRef('12')).toBe(true);
    expect(isNumericDomainRef(12)).toBe(true);
    expect(isNumericDomainRef('k3x9p2m7q4w8z6r5t2ya')).toBe(false);
    expect(isNumericDomainRef(undefined)).toBe(false);
  });

  it('builds the /cmp-config parameters', () => {
    expect(domainQuery('12')).toEqual({ domainId: '12' });
    expect(domainQuery('k3x9p2m7q4w8z6r5t2ya')).toEqual({ key: 'k3x9p2m7q4w8z6r5t2ya' });
    expect(domainQuery(undefined)).toEqual({});
  });

  it('builds the consent record fields', () => {
    expect(domainRecordFields('12')).toEqual({ domainId: 12 });
    expect(domainRecordFields('k3x9p2m7q4w8z6r5t2ya')).toEqual({
      domainKey: 'k3x9p2m7q4w8z6r5t2ya',
    });
  });
});
