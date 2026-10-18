import { describe, it, expect } from 'vitest';
import php from '../../../plugin/includes/class-tcf.php?raw';
import { TCF_POLICY_VERSION, GVL_SPEC_VERSION, TC_COOKIE_NAME, DEV_CMP_ID } from './spec';

// The PHP side (plugin/includes/class-tcf.php) must declare the same values.
const constant = (name: string): string => {
  const m = php.match(new RegExp(`const ${name} = ([^;]+);`));
  if (!m) throw new Error(`${name} not found in class-tcf.php`);
  return m[1].trim().replace(/^'(.*)'$/, '$1');
};

describe('TCF spec values are the same in SDK and plugin', () => {
  it('policy version, GVL spec version, cookie name, dev CMP id', () => {
    expect(Number(constant('POLICY_VERSION'))).toBe(TCF_POLICY_VERSION);
    expect(Number(constant('GVL_SPEC_VERSION'))).toBe(GVL_SPEC_VERSION);
    expect(constant('COOKIE_NAME')).toBe(TC_COOKIE_NAME);
    expect(Number(constant('DEV_CMP_ID'))).toBe(DEV_CMP_ID);
  });
});
