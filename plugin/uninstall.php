<?php
/**
 * Runs when the plugin is deleted from the Plugins screen.
 *
 * Data is removed only when the administrator opted in (Settings → Uninstall),
 * so deleting and reinstalling the plugin keeps the configuration by default.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$focmp_settings = get_option('focmp_settings', array());
if (empty($focmp_settings['delete_data_on_uninstall'])) {
    return;
}

wp_clear_scheduled_hook('focmp_gvl_update');
wp_clear_scheduled_hook('focmp_consent_log_purge');
wp_clear_scheduled_hook('focmp_consent_log_purge_before');

global $wpdb;

$focmp_tables = array(
    'cmp_consent_log',
    'cmp_consent_payloads',
    'cmp_consent_stats',
    'cmp_vendor_list_entries',
    'cmp_template_vendors',
    'cmp_reconsents',
    'cmp_domains',
    'cmp_templates',
    'cmp_vendor_lists',
    'cmp_custom_consents',
    'cmp_vendors_custom',
    'cmp_vendors_google',
    'cmp_vendors_iab',
    'cmp_purposes_iab',
    'cmp_gvl_metadata',
    'cmp_companies',
);
foreach ($focmp_tables as $focmp_table) {
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed internal table names
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}{$focmp_table}");
}

foreach (array('focmp_settings', 'focmp_texts', 'focmp_version', 'focmp_db_version', 'focmp_cache_version', 'focmp_gvl_status') as $focmp_option) {
    delete_option($focmp_option);
}

$wpdb->query(
    "DELETE FROM {$wpdb->options}
     WHERE option_name LIKE '\\_transient\\_focmp\\_%'
        OR option_name LIKE '\\_transient\\_timeout\\_focmp\\_%'
        OR option_name LIKE 'focmp\\_delete\\_notice\\_%'"
);
