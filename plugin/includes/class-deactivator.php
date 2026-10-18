<?php
/**
 * Plugin Deactivator
 */

if (!defined('ABSPATH')) {
    exit;
}

class FOCMP_Deactivator {

    /**
     * Deactivate the plugin
     */
    public static function deactivate() {
        // Clear all transients
        self::clear_cache();

        // Stop scheduled GVL updates
        if (class_exists('FOCMP_Scheduler')) {
            FOCMP_Scheduler::unschedule();
        }

        // Flush rewrite rules
        flush_rewrite_rules();
    }

    /**
     * Clear all plugin cache
     */
    private static function clear_cache() {
        global $wpdb;

        // Delete all plugin transients
        $wpdb->query(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE '_transient_focmp_%'
             OR option_name LIKE '_transient_timeout_focmp_%'"
        );
    }
}
