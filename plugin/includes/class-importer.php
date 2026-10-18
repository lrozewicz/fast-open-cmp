<?php
/**
 * Data Importer for IAB GVL and Google ATP
 */

if (!defined('ABSPATH')) {
    exit;
}

class FOCMP_Importer {

    /**
     * Import IAB Global Vendor List from URL or file
     *
     * @param string $source URL or file path
     * @return array|WP_Error Result with counts or error
     */
    public function import_iab_gvl($source) {
        // Fetch data
        $data = $this->fetch_json($source);

        if (is_wp_error($data)) {
            return $data;
        }

        global $wpdb;
        $vendors_table = $wpdb->prefix . 'cmp_vendors_iab';
        $purposes_table = $wpdb->prefix . 'cmp_purposes_iab';
        $meta_table = $wpdb->prefix . 'cmp_gvl_metadata';

        $vendors_count = 0;
        $purposes_count = 0;
        $deactivated = 0;
        $seen_ids = array();

        // Import vendors
        if (!empty($data['vendors'])) {
            foreach ($data['vendors'] as $vendor_id => $vendor_data) {
                $seen_ids[] = (int) $vendor_id;
                // Vendors the IAB marked as deleted must not be offered for consent.
                $is_deleted = !empty($vendor_data['deletedDate']);
                $existing = $wpdb->get_row($wpdb->prepare(
                    "SELECT id, is_enabled FROM $vendors_table WHERE vendor_id = %d",
                    $vendor_id
                ));

                $vendor_json = wp_json_encode($vendor_data);
                $vendor_name = isset($vendor_data['name']) ? $vendor_data['name'] : 'Unknown';

                if ($existing) {
                    $fields = array('name' => $vendor_name, 'data' => $vendor_json);
                    $formats = array('%s', '%s');
                    if ($is_deleted) {
                        $fields['is_enabled'] = 0;
                        $formats[] = '%d';
                    }
                    $wpdb->update($vendors_table, $fields, array('vendor_id' => $vendor_id), $formats, array('%d'));
                } else {
                    $wpdb->insert(
                        $vendors_table,
                        array(
                            'vendor_id' => $vendor_id,
                            'name' => $vendor_name,
                            'data' => $vendor_json,
                            'is_enabled' => $is_deleted ? 0 : 1,
                        ),
                        array('%d', '%s', '%s', '%d')
                    );
                }
                // Count only vendors switched off by this import.
                if ($is_deleted && $existing && (int) $existing->is_enabled === 1) {
                    $deactivated++;
                }
                $vendors_count++;
            }

            // Vendors no longer present in the GVL at all are switched off as well.
            if (!empty($seen_ids)) {
                $placeholders = implode(',', array_fill(0, count($seen_ids), '%d'));
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders built above
                $deactivated += (int) $wpdb->query($wpdb->prepare(
                    "UPDATE $vendors_table SET is_enabled = 0 WHERE is_enabled = 1 AND vendor_id NOT IN ($placeholders)",
                    $seen_ids
                ));
            }
        }

        // Import purposes
        if (!empty($data['purposes'])) {
            foreach ($data['purposes'] as $purpose_id => $purpose_data) {
                $existing = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM $purposes_table WHERE purpose_id = %d AND language = 'en'",
                    $purpose_id
                ));

                $purpose_json = wp_json_encode($purpose_data);

                if ($existing) {
                    $wpdb->update(
                        $purposes_table,
                        array('data' => $purpose_json),
                        array('purpose_id' => $purpose_id, 'language' => 'en'),
                        array('%s'),
                        array('%d', '%s')
                    );
                } else {
                    $wpdb->insert(
                        $purposes_table,
                        array(
                            'purpose_id' => $purpose_id,
                            'language' => 'en',
                            'data' => $purpose_json,
                        ),
                        array('%d', '%s', '%s')
                    );
                }
                $purposes_count++;
            }
        }

        // Import metadata (specialPurposes, features, specialFeatures, stacks, dataCategories)
        $meta_keys = array('specialPurposes', 'features', 'specialFeatures', 'stacks', 'dataCategories');

        foreach ($meta_keys as $key) {
            if (!empty($data[$key])) {
                $this->save_gvl_meta($key, $data[$key], 'en');
            }
        }

        // Save vendor list version
        if (!empty($data['vendorListVersion'])) {
            $this->save_gvl_meta('vendorListVersion', $data['vendorListVersion'], 'en');
        }

        // Ensure newly imported vendors are present (enabled) in existing lists.
        self::backfill_vendor_list_entries();

        // Clear cache (also bumps the version the SDK compares with its localStorage copy)
        FOCMP_Cache::get_instance()->clear_all();

        return array(
            'vendors' => $vendors_count,
            'purposes' => $purposes_count,
            'deactivated' => $deactivated,
        );
    }

    /**
     * Import IAB purposes translations
     *
     * @param string $source URL or file path
     * @param string $lang Language code
     * @return array|WP_Error Result with count or error
     */
    public function import_iab_purposes_lang($source, $lang) {
        $data = $this->fetch_json($source);

        if (is_wp_error($data)) {
            return $data;
        }

        global $wpdb;
        $purposes_table = $wpdb->prefix . 'cmp_purposes_iab';

        $count = 0;

        // Import purposes
        if (!empty($data['purposes'])) {
            foreach ($data['purposes'] as $purpose_id => $purpose_data) {
                $existing = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM $purposes_table WHERE purpose_id = %d AND language = %s",
                    $purpose_id,
                    $lang
                ));

                $purpose_json = wp_json_encode($purpose_data);

                if ($existing) {
                    $wpdb->update(
                        $purposes_table,
                        array('data' => $purpose_json),
                        array('purpose_id' => $purpose_id, 'language' => $lang),
                        array('%s'),
                        array('%d', '%s')
                    );
                } else {
                    $wpdb->insert(
                        $purposes_table,
                        array(
                            'purpose_id' => $purpose_id,
                            'language' => $lang,
                            'data' => $purpose_json,
                        ),
                        array('%d', '%s', '%s')
                    );
                }
                $count++;
            }
        }

        // Import other metadata with translations
        $meta_keys = array('specialPurposes', 'features', 'specialFeatures', 'stacks', 'dataCategories');

        foreach ($meta_keys as $key) {
            if (!empty($data[$key])) {
                $this->save_gvl_meta($key, $data[$key], $lang);
            }
        }

        // Clear cache
        $cache = FOCMP_Cache::get_instance();
        $cache->clear_purposes_cache();

        return array('purposes' => $count);
    }

    /**
     * Import Google Additional Consent providers from CSV
     *
     * @param string $source URL or file path
     * @return array|WP_Error Result with count or error
     */
    public function import_google_atp($source) {
        // Fetch CSV data
        $csv_data = $this->fetch_csv($source);

        if (is_wp_error($csv_data)) {
            return $csv_data;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'cmp_vendors_google';

        $count = 0;

        // Skip header row
        $rows = explode("\n", $csv_data);
        $header_skipped = false;

        foreach ($rows as $row) {
            $row = trim($row);
            if (empty($row)) continue;

            // Skip header
            if (!$header_skipped) {
                $header_skipped = true;
                continue;
            }

            // Parse CSV row
            $columns = str_getcsv($row);

            if (count($columns) < 2) continue;

            $provider_id = (int) trim($columns[0]);
            $provider_name = trim($columns[1]);

            if ($provider_id <= 0 || empty($provider_name)) continue;

            // Check if exists
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $table WHERE provider_id = %d",
                $provider_id
            ));

            if ($existing) {
                $wpdb->update(
                    $table,
                    array('provider_name' => $provider_name),
                    array('provider_id' => $provider_id),
                    array('%s'),
                    array('%d')
                );
            } else {
                $wpdb->insert(
                    $table,
                    array(
                        'provider_id' => $provider_id,
                        'provider_name' => $provider_name,
                        'is_enabled' => 1,
                    ),
                    array('%d', '%s', '%d')
                );
            }
            $count++;
        }

        // Ensure newly imported providers are present (enabled) in existing lists.
        self::backfill_vendor_list_entries();

        // Clear cache
        $cache = FOCMP_Cache::get_instance();
        $cache->clear_vendor_cache();

        return array('providers' => $count);
    }

    /**
     * Downloads a source file. Only public http(s) URLs are accepted:
     * wp_safe_remote_get refuses internal hosts (no SSRF), and local paths are
     * not read (the source comes from an admin request).
     */
    private function fetch_remote($source) {
        $scheme = strtolower((string) wp_parse_url($source, PHP_URL_SCHEME));
        if (!in_array($scheme, array('http', 'https'), true) || !filter_var($source, FILTER_VALIDATE_URL)) {
            return new WP_Error('invalid_source', __('The source must be an http(s) URL.', 'fast-open-cmp'));
        }

        $response = wp_safe_remote_get($source, array(
            'timeout' => 30,
            'sslverify' => true,
        ));
        if (is_wp_error($response)) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            /* translators: %d: HTTP status code */
            return new WP_Error('http_error', sprintf(__('The source responded with HTTP %d.', 'fast-open-cmp'), $code));
        }

        return wp_remote_retrieve_body($response);
    }

    /**
     * Fetch JSON data from an http(s) URL
     */
    private function fetch_json($source) {
        $body = $this->fetch_remote($source);
        if (is_wp_error($body)) {
            return $body;
        }

        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error('json_error', 'Invalid JSON: ' . json_last_error_msg());
        }

        return $data;
    }

    /**
     * Fetch CSV data from URL or file
     */
    private function fetch_csv($source) {
        return $this->fetch_remote($source);
    }

    /**
     * Save GVL metadata
     */
    private function save_gvl_meta($key, $data, $lang = 'en') {
        global $wpdb;

        $table = $wpdb->prefix . 'cmp_gvl_metadata';

        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table WHERE meta_key = %s AND language = %s",
            $key,
            $lang
        ));

        $json_data = is_array($data) ? wp_json_encode($data) : $data;

        if ($existing) {
            $wpdb->update(
                $table,
                array('data' => $json_data),
                array('meta_key' => $key, 'language' => $lang),
                array('%s'),
                array('%s', '%s')
            );
        } else {
            $wpdb->insert(
                $table,
                array(
                    'meta_key' => $key,
                    'language' => $lang,
                    'data' => $json_data,
                ),
                array('%s', '%s', '%s')
            );
        }
    }

    /**
     * Backfill vendor list entries so every existing vendor list has a row for
     * every currently globally-enabled vendor.
     *
     * Vendors added after a list was created (imported GVL/ATP, or a newly added
     * custom vendor) otherwise never get a row in cmp_vendor_list_entries. Since a
     * missing row is treated as "not in this list" (see
     * FOCMP_API::is_vendor_enabled_in_list / get_enabled_iab_vendor_ids), such
     * vendors would silently disappear from every list that predates them.
     *
     * Idempotent: relies on the UNIQUE(vendor_list_id, vendor_id, vendor_type)
     * key plus INSERT IGNORE, so it only adds missing rows (default enabled) and
     * never overrides an entry an admin explicitly toggled off in a list.
     *
     * Lives here (always-loaded class) rather than in FOCMP_Admin so it stays
     * callable from import paths even outside an admin request (cron/CLI).
     */
    public static function backfill_vendor_list_entries() {
        global $wpdb;

        $entries = $wpdb->prefix . 'cmp_vendor_list_entries';
        $lists   = $wpdb->prefix . 'cmp_vendor_lists';

        // Vendor type => source table + its primary-key column.
        $sources = array(
            'iab'    => array('table' => $wpdb->prefix . 'cmp_vendors_iab',    'col' => 'vendor_id'),
            'google' => array('table' => $wpdb->prefix . 'cmp_vendors_google', 'col' => 'provider_id'),
            'custom' => array('table' => $wpdb->prefix . 'cmp_vendors_custom', 'col' => 'id'),
        );

        // Table/column names are internal constants (not user input); only the
        // vendor_type literal is parameterized.
        foreach ($sources as $type => $src) {
            $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO $entries (vendor_list_id, vendor_id, vendor_type, is_enabled)
                 SELECT l.id, v.{$src['col']}, %s, 1
                 FROM $lists l
                 CROSS JOIN {$src['table']} v
                 WHERE v.is_enabled = 1",
                $type
            ));
        }
    }
}
