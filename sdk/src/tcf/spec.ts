/**
 * IAB TCF values the SDK depends on. Mirrors plugin/includes/class-tcf.php —
 * spec.test.ts fails when the two disagree. First stop when IAB publishes a new
 * TCF / GVL version (see docs/tcf-upgrade.md).
 */
export const TCF_POLICY_VERSION = 5;
export const GVL_SPEC_VERSION = 3;
export const TC_COOKIE_NAME = 'eupubconsent-v2';
/** Development placeholder used until the publisher configures an own CMP ID. */
export const DEV_CMP_ID = 28;
