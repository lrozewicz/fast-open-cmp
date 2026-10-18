import { describe, it, expect } from 'vitest';
import { getStackVendorsCount } from './Stack';
import { Purpose, Vendor } from '../../models/types';

const purposes = {
  '2': { id: 2, vendorsCount: 3 },
  '3': { id: 3, vendorsCount: 2 },
} as unknown as Record<string, Purpose>;

const v = (id: number, p: number[], li: number[] = [], deleted = false) =>
  ({
    id,
    name: `v${id}`,
    purposes: p,
    legIntPurposes: li,
    urls: [],
    ...(deleted ? { deletedDate: '2025-01-01' } : {}),
  }) as Vendor;

describe('getStackVendorsCount', () => {
  it('counts distinct vendors using any purpose of the stack', () => {
    const vendors = { 1: v(1, [2]), 2: v(2, [3]), 3: v(3, [], [2]), 4: v(4, [7]), 5: v(5, [2, 3]) };
    // vendors 1, 2, 3, 5 — max per purpose would say 3
    expect(getStackVendorsCount([2, 3], purposes, vendors)).toBe(4);
  });

  it('ignores deleted vendors and falls back to the per-purpose maximum', () => {
    expect(getStackVendorsCount([2, 3], purposes, { 1: v(1, [2], [], true) })).toBe(0);
    expect(getStackVendorsCount([2, 3], purposes)).toBe(3);
  });
});
