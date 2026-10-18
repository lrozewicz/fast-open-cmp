<?php
/**
 * IAB TCF constants and CMP identity, in one place.
 *
 * When IAB publishes a new TCF / GVL version, this is the first file to update
 * (see docs/tcf-upgrade.md). The SDK mirrors the spec values in sdk/src/tcf/spec.ts.
 */

if (!defined('ABSPATH')) {
    exit;
}

class FOCMP_TCF {
    /** TCF Policy version (TCF 2.2 = 4, TCF 2.3 = 5). */
    const POLICY_VERSION = 5;

    /** Global Vendor List specification version served by IAB at /v3/. */
    const GVL_SPEC_VERSION = 3;

    const GVL_URL = 'https://vendor-list.consensu.org/v3/vendor-list.json';

    const COOKIE_NAME = 'eupubconsent-v2';

    /**
     * CMP IDs that are not this installation's own: 0/1 are invalid for the IAB
     * library, 28 is the historical default of this plugin and belongs to another
     * registered CMP (OneTrust). TC Strings must carry the publisher's own CMP ID.
     */
    const PLACEHOLDER_CMP_IDS = array(0, 1, 28);

    /** Placeholder used for development when no own ID is configured yet. */
    const DEV_CMP_ID = 28;

    /**
     * Configured CMP ID (falls back to the development placeholder).
     */
    public static function cmp_id() {
        $settings = get_option('focmp_settings', array());
        $id = isset($settings['cmp_id']) ? (int) $settings['cmp_id'] : 0;
        return $id > 1 ? $id : self::DEV_CMP_ID;
    }

    /**
     * Whether a real, IAB-registered CMP ID has been configured.
     */
    public static function has_registered_cmp_id() {
        $settings = get_option('focmp_settings', array());
        $id = isset($settings['cmp_id']) ? (int) $settings['cmp_id'] : 0;
        return !in_array($id, self::PLACEHOLDER_CMP_IDS, true);
    }

    /**
     * CMP version written into TC Strings. TCF expects it to change with every CMP
     * release, so unless the admin pins a value (> 1), it is derived from the
     * plugin version.
     */
    public static function cmp_version() {
        $settings = get_option('focmp_settings', array());
        $pinned = isset($settings['cmp_version']) ? (int) $settings['cmp_version'] : 0;
        return $pinned > 1 ? $pinned : self::version_to_cmp_version(FOCMP_VERSION);
    }

    /**
     * Maps a semantic version to the 12-bit cmpVersion field:
     * 1.2.0 → 120, 1.3.4 → 134, 2.0.0 → 200 (minor and patch capped at 9).
     */
    public static function version_to_cmp_version($version) {
        $parts = array_map('intval', array_pad(explode('.', (string) $version), 3, 0));
        $value = $parts[0] * 100 + min(9, $parts[1]) * 10 + min(9, $parts[2]);
        return max(2, min(4095, $value));
    }
}
