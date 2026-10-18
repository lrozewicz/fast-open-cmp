<?php
/**
 * Config builder + vendor-list/purposes response assembly and filtering.
 * Split out of class-api.php (todo §3). Composed into FOCMP_API via `use`.
 */

if (!defined('ABSPATH')) {
    exit;
}

trait FOCMP_API_Builder {
    /**
     * Build CMP configuration for a domain
     */
    private function build_config($domain_id, $env = 'prod') {
        global $wpdb;

        // Get domain
        $domains_table = $wpdb->prefix . 'cmp_domains';
        $domain = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $domains_table WHERE id = %d",
            $domain_id
        ));

        if (!$domain) {
            return new WP_Error('domain_not_found', 'Domain not found', array('status' => 404));
        }

        // Get template
        $template_id = ($env === 'test') ? $domain->test_template_id : $domain->prod_template_id;

        if (!$template_id) {
            return new WP_Error('template_not_found', 'No template assigned to this domain', array('status' => 404));
        }

        $templates_table = $wpdb->prefix . 'cmp_templates';
        $template = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $templates_table WHERE id = %d",
            $template_id
        ));

        if (!$template) {
            return new WP_Error('template_not_found', 'Template not found', array('status' => 404));
        }

        // Effective language: the template's language, overridden by a translation
        // plugin (Polylang/WPML) reporting the current front-end language. When a
        // template authored in that language exists for the domain's company we
        // switch to it (per-language box texts); otherwise we keep this template
        // but render it in the detected language (our localized default texts + GVL
        // in that language). No-op without such a plugin — the filter returns null.
        $lang = $template->language ?: 'pl';
        $detected_lang = apply_filters('focmp_detect_language', null);
        if ($detected_lang) {
            $lang = $detected_lang;
            $localized = $this->find_template_for_language($domain, $detected_lang);
            if ($localized) {
                $template = $localized;
            }
        }

        // Parse template config
        $config = json_decode($template->config, true);

        if (!$config) {
            $config = array();
        }

        // Get reconsent ID
        $reconsent_id = 0;
        if ($domain->current_reconsent_id) {
            $reconsents_table = $wpdb->prefix . 'cmp_reconsents';
            $reconsent = $wpdb->get_row($wpdb->prepare(
                "SELECT reconsent_timestamp FROM $reconsents_table WHERE id = %d",
                $domain->current_reconsent_id
            ));
            if ($reconsent) {
                $reconsent_id = (int) $reconsent->reconsent_timestamp;
            }
        }

        return $this->assemble_config($config, $template, $lang, $reconsent_id);
    }

    /**
     * Config for the template editor's live preview: the (unsaved) template config
     * from the form, assembled exactly like the public /cmp-config response.
     */
    public function build_preview_config(array $config, $vendor_list_id, $lang) {
        $template = (object) array(
            'id' => 0,
            'vendor_list_id' => $vendor_list_id ? (int) $vendor_list_id : null,
            'language' => $lang,
        );
        return $this->assemble_config($config, $template, $lang, 0);
    }

    /**
     * Turns a template config into the SDK config (vendors, metadata, texts, GCM…).
     */
    private function assemble_config(array $config, $template, $lang, $reconsent_id) {
        // Get settings
        $settings = get_option('focmp_settings', array());

        // Get vendor list ID from template
        $vendor_list_id = !empty($template->vendor_list_id) ? (int) $template->vendor_list_id : null;

        // Get custom vendors
        $custom_vendors = $this->get_custom_vendors($vendor_list_id);

        // IAB vendor IDs enabled in this list (null = no list assigned = no
        // restriction). The SDK shows/consents only these IAB vendors, so a list
        // with one vendor checked shows exactly one vendor in the consent box.
        $iab_vendor_ids = $this->get_enabled_iab_vendor_ids($vendor_list_id);

        // Which vendor groups have at least one enabled vendor in this list.
        // An empty group is hidden in the consent box (even in full mode).
        $visible_vendor_groups = $this->get_visible_vendor_groups($vendor_list_id, $custom_vendors, $iab_vendor_ids);

        // Get purposes metadata
        $purposes_metadata = $this->get_purposes_metadata($vendor_list_id);

        // Template mode (default: tcf_gcm for backward compatibility)
        $mode = isset($config['mode']) ? $config['mode'] : 'tcf_gcm';

        // Google Consent Mode v2 configuration
        // Priority: template config > global settings
        $gcm_config = null;
        if (isset($config['googleConsentMode'])) {
            // Template-level config (mapping already stored as arrays of ints)
            if (!empty($config['googleConsentMode']['enabled'])) {
                $gcm_config = array(
                    'enabled' => true,
                    'mapping' => isset($config['googleConsentMode']['mapping']) ? $config['googleConsentMode']['mapping'] : array(),
                    'waitForUpdate' => isset($config['googleConsentMode']['waitForUpdate']) ? (int) $config['googleConsentMode']['waitForUpdate'] : 500,
                    'urlPassthrough' => !empty($config['googleConsentMode']['urlPassthrough']),
                    'adsDataRedaction' => !empty($config['googleConsentMode']['adsDataRedaction']),
                );
            }
        } elseif (!empty($settings['gcm_enabled'])) {
            // Fallback to global settings (mapping stored as comma-separated strings)
            $default_mapping = array(
                'ad_storage' => '1',
                'analytics_storage' => '1',
                'ad_user_data' => '1,2,3,4',
                'ad_personalization' => '3,4',
            );
            $raw_mapping = isset($settings['gcm_mapping']) ? $settings['gcm_mapping'] : $default_mapping;

            $parsed_mapping = array();
            foreach ($raw_mapping as $signal => $purposes_str) {
                $parsed_mapping[$signal] = array_map('intval', array_filter(
                    explode(',', $purposes_str),
                    'strlen'
                ));
            }

            $gcm_config = array(
                'enabled' => true,
                'mapping' => $parsed_mapping,
                'waitForUpdate' => isset($settings['gcm_wait_for_update']) ? (int) $settings['gcm_wait_for_update'] : 500,
            );
        }

        // Build response
        $response = array(
            'mode' => $mode,
            'language' => $lang,
            'cmpId' => FOCMP_TCF::cmp_id(),
            'cmpVersion' => FOCMP_TCF::cmp_version(),
            'tcfPolicyVersion' => FOCMP_TCF::POLICY_VERSION,
            'vendorListVersion' => $this->get_vendor_list_version(),
            'publisherCountryCode' => isset($settings['publisher_country_code']) ? $settings['publisher_country_code'] : 'PL',
            'isServiceSpecific' => true,
            'useNonStandardStacks' => false,
            'consentScreen' => 1,
            'cookieName' => !empty($config['cookieName']) ? $config['cookieName'] : (!empty($settings['cookie_name']) ? $settings['cookie_name'] : FOCMP_TCF::COOKIE_NAME),
            'cookieExpirationDays' => isset($config['cookieExpirationDays']) ? (int) $config['cookieExpirationDays'] : (isset($settings['cookie_expiration_days']) ? (int) $settings['cookie_expiration_days'] : 180),
            'reconsentID' => $reconsent_id,
            'consentMaxAgeDays' => isset($config['consentMaxAgeDays']) ? max(0, (int) $config['consentMaxAgeDays']) : 395,
            'renewOnNewVendors' => !empty($config['renewOnNewVendors']),
            'texts' => $this->merge_texts($config, $lang),
            'customConsents' => isset($config['customConsents']) ? $config['customConsents'] : array(),
            'vendors' => array(
                'custom' => $custom_vendors,
            ),
            'visibleVendorGroups' => $visible_vendor_groups,
            'iabVendorIds' => $iab_vendor_ids,
            'purposesMetadata' => $purposes_metadata,
            'specialFeaturesMetadata' => $this->get_special_features_metadata(),
            'specialPurposesMetadata' => $this->get_special_purposes_metadata($vendor_list_id),
            'featuresMetadata' => $this->get_features_metadata($vendor_list_id),
            'gvlUrl' => $this->build_gvl_url($lang, $config, $settings),
            'gvlBaseUrl' => rest_url(self::NAMESPACE . '/'),
            'cacheVersion' => (int) get_option('focmp_cache_version', 0),
            'dimensions' => isset($config['dimensions']) ? $config['dimensions'] : null,
            // Look & feel and first-layer buttons; the SDK applies defaults and, in the
            // certified profile, enforces a compliant layer 1 (see sdk/src/services/appearance.ts).
            'appearance' => isset($config['appearance']) && is_array($config['appearance']) ? $config['appearance'] : null,
            'firstLayer' => isset($config['firstLayer']) && is_array($config['firstLayer']) ? $config['firstLayer'] : null,
            'complianceProfile' => (isset($config['complianceProfile']) && $config['complianceProfile'] === 'custom') ? 'custom' : 'certified',
            'googleConsentMode' => $gcm_config,
            'consentLog' => self::consent_log_enabled(),
            'templateId' => (int) $template->id,
        );

        return $response;
    }

    /**
     * Build vendor list in GVL format
     */
    private function build_vendor_list($lang = 'pl', $request = null) {
        global $wpdb;

        $settings = get_option('focmp_settings', array());

        // Parse stacks override from request parameter
        $override_stacks = null;
        if ($request) {
            $stacks_param = $request->get_param('stacks');
            if ($stacks_param) {
                $override_stacks = array_map('intval', array_filter(explode(',', $stacks_param), 'strlen'));
            }
        }

        // Get IAB vendors
        $vendors_table = $wpdb->prefix . 'cmp_vendors_iab';
        $vendors_raw = $wpdb->get_results(
            "SELECT vendor_id, data FROM $vendors_table WHERE is_enabled = 1",
            ARRAY_A
        );

        $vendors = array();
        foreach ($vendors_raw as $v) {
            $data = json_decode($v['data'], true);
            if ($data) {
                $vendors[$v['vendor_id']] = $data;
            }
        }

        // Get purposes in requested language (with fallback to English)
        $purposes = $this->get_purposes_from_db($lang);
        if (empty($purposes)) {
            $purposes = $this->get_purposes_from_db('en');
        }

        // Get other GVL data in requested language
        $special_purposes = $this->get_gvl_meta('specialPurposes', $lang);
        $features = $this->get_gvl_meta('features', $lang);
        $special_features = $this->get_gvl_meta('specialFeatures', $lang);
        // Get only enabled stacks (default: stack 42 like in original project)
        $all_stacks = $this->get_gvl_meta('stacks', $lang);
        $stacks = $this->filter_enabled_stacks($all_stacks, $override_stacks);
        $data_categories = $this->get_gvl_meta('dataCategories', $lang);

        // Enrich purposes with legitimateInterestAllowed based on vendor declarations
        foreach ($purposes as $purpose_id => &$purpose_data) {
            $purpose_data['legitimateInterestAllowed'] = false;
            $purpose_data['showVendors'] = true;
            foreach ($vendors as $vendor) {
                if (isset($vendor['legIntPurposes']) && in_array((int)$purpose_id, $vendor['legIntPurposes'])) {
                    $purpose_data['legitimateInterestAllowed'] = true;
                    break;
                }
            }
        }
        unset($purpose_data);

        return array(
            'gvlSpecificationVersion' => FOCMP_TCF::GVL_SPEC_VERSION,
            'vendorListVersion' => $this->get_vendor_list_version(),
            'tcfPolicyVersion' => FOCMP_TCF::POLICY_VERSION,
            'lastUpdated' => gmdate('c'),
            'purposes' => $purposes ?: new stdClass(),
            'specialPurposes' => $special_purposes ?: new stdClass(),
            'features' => $features ?: new stdClass(),
            'specialFeatures' => $special_features ?: new stdClass(),
            'stacks' => $stacks,
            'vendors' => $vendors ?: new stdClass(),
            'dataCategories' => $data_categories ?: new stdClass(),
        );
    }

    /**
     * Build purposes response for language
     */
    private function build_purposes_response($lang) {
        $purposes = $this->get_purposes_from_db($lang);
        $special_purposes = $this->get_gvl_meta('specialPurposes', $lang);
        $features = $this->get_gvl_meta('features', $lang);
        $special_features = $this->get_gvl_meta('specialFeatures', $lang);
        // Get only enabled stacks
        $all_stacks = $this->get_gvl_meta('stacks', $lang);
        $stacks = $this->filter_enabled_stacks($all_stacks);

        // Return full purpose data from DB (already in correct GVL format)
        return array(
            'purposes' => !empty($purposes) ? $purposes : new stdClass(),
            'specialPurposes' => $special_purposes ?: new stdClass(),
            'features' => $features ?: new stdClass(),
            'specialFeatures' => $special_features ?: new stdClass(),
            'stacks' => $stacks,
        );
    }

    /**
     * Get custom vendors filtered by vendor list
     */
    private function get_custom_vendors($vendor_list_id) {
        global $wpdb;

        $result = array(
            'google' => array(),
            'other' => array(),
        );

        // Get Google vendors
        $google_table = $wpdb->prefix . 'cmp_vendors_google';
        $google_vendors = $wpdb->get_results(
            "SELECT * FROM $google_table WHERE is_enabled = 1",
            ARRAY_A
        );

        foreach ($google_vendors as $v) {
            if (!$this->is_vendor_enabled_in_list($vendor_list_id, $v['provider_id'], 'google')) {
                continue;
            }

            $result['google'][$v['provider_id']] = array(
                'id' => (int) $v['provider_id'],
                'name' => $v['provider_name'],
                'urls' => array(
                    array(
                        'langId' => 'pl',
                        'privacy' => $v['privacy_url'],
                    ),
                ),
            );
        }

        // Get custom vendors
        $custom_table = $wpdb->prefix . 'cmp_vendors_custom';
        $custom_vendors = $wpdb->get_results(
            "SELECT * FROM $custom_table WHERE is_enabled = 1",
            ARRAY_A
        );

        foreach ($custom_vendors as $v) {
            if (!$this->is_vendor_enabled_in_list($vendor_list_id, $v['id'], 'custom')) {
                continue;
            }

            $result['other'][$v['id']] = array(
                'id' => (int) $v['id'],
                'name' => $v['name'],
                'urls' => array(
                    array(
                        'langId' => 'pl',
                        'privacy' => $v['privacy_url'],
                    ),
                ),
            );
        }

        return $result;
    }

    /**
     * Check if vendor is enabled in a vendor list
     *
     * If vendor_list_id is NULL, all globally enabled vendors are included (backward compatible).
     * If vendor_list_id is set, check the vendor_list_entries table.
     */
    private function is_vendor_enabled_in_list($vendor_list_id, $vendor_id, $vendor_type) {
        if ($vendor_list_id === null) {
            return true;
        }

        // If no entry exists, vendor is not in the list (disabled)
        $entries = $this->list_entries($vendor_list_id, $vendor_type);
        return !empty($entries[(int) $vendor_id]);
    }

    /**
     * All entries of a list for one vendor type, loaded with a single query and
     * memoised for the request ([vendor_id => enabled]). Building a config used to
     * run one query per vendor — thousands for the IAB list.
     */
    private function list_entries($vendor_list_id, $vendor_type) {
        static $memo = array();
        $key = (int) $vendor_list_id . ':' . $vendor_type;
        if (!isset($memo[$key])) {
            global $wpdb;
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT vendor_id, is_enabled FROM {$wpdb->prefix}cmp_vendor_list_entries
                 WHERE vendor_list_id = %d AND vendor_type = %s",
                $vendor_list_id,
                $vendor_type
            ));
            $memo[$key] = array();
            foreach ((array) $rows as $row) {
                $memo[$key][(int) $row->vendor_id] = (string) $row->is_enabled === '1';
            }
        }
        return $memo[$key];
    }

    /**
     * Determine which vendor groups (iab/google/other) have at least one
     * enabled vendor in the given list.
     *
     * Google/other are derived from the already list-filtered custom vendors.
     * IAB is counted directly: a vendor counts only if it is globally enabled
     * AND enabled in this list (same semantics as is_vendor_enabled_in_list).
     * When no list is assigned, all groups are visible (backward compatible).
     */
    private function get_visible_vendor_groups($vendor_list_id, $custom_vendors, $iab_vendor_ids) {
        return array(
            // iab_vendor_ids is null when no list is assigned (everything visible).
            'iab'    => ($vendor_list_id === null) ? true : !empty($iab_vendor_ids),
            'google' => !empty($custom_vendors['google']),
            'other'  => !empty($custom_vendors['other']),
        );
    }

    /**
     * Get the IAB vendor IDs enabled in a list.
     *
     * Returns null when no list is assigned (no restriction — the SDK shows all
     * globally enabled IAB vendors from the GVL). Otherwise returns the IDs that
     * are both globally enabled and enabled in the list (same semantics as
     * is_vendor_enabled_in_list), so the consent box reflects the list exactly.
     */
    private function get_enabled_iab_vendor_ids($vendor_list_id) {
        if ($vendor_list_id === null) {
            return null;
        }

        global $wpdb;
        $entries_table = $wpdb->prefix . 'cmp_vendor_list_entries';
        $iab_table     = $wpdb->prefix . 'cmp_vendors_iab';

        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT e.vendor_id
             FROM $entries_table e
             INNER JOIN $iab_table v ON v.vendor_id = e.vendor_id
             WHERE e.vendor_list_id = %d
               AND e.vendor_type = 'iab'
               AND e.is_enabled = 1
               AND v.is_enabled = 1",
            $vendor_list_id
        ));

        return array_map('intval', $ids);
    }

    /**
     * Get purposes metadata (vendor counts)
     */
    private function get_purposes_metadata($vendor_list_id) {
        global $wpdb;

        $vendors_table = $wpdb->prefix . 'cmp_vendors_iab';

        // Get all enabled vendors
        $vendors = $wpdb->get_results(
            "SELECT vendor_id, data FROM $vendors_table WHERE is_enabled = 1",
            ARRAY_A
        );

        $vendor_counts = array();

        // Initialize for purposes 1-11
        for ($i = 1; $i <= 11; $i++) {
            $vendor_counts[$i] = array(
                'purposes' => 0,
                'legIntPurposes' => 0,
            );
        }

        // Count vendors per purpose
        foreach ($vendors as $v) {
            // Check if enabled in vendor list
            if (!$this->is_vendor_enabled_in_list($vendor_list_id, $v['vendor_id'], 'iab')) {
                continue;
            }

            $data = json_decode($v['data'], true);
            if (!$data) continue;

            if (!empty($data['purposes'])) {
                foreach ($data['purposes'] as $purpose_id) {
                    if (isset($vendor_counts[$purpose_id])) {
                        $vendor_counts[$purpose_id]['purposes']++;
                    }
                }
            }

            if (!empty($data['legIntPurposes'])) {
                foreach ($data['legIntPurposes'] as $purpose_id) {
                    if (isset($vendor_counts[$purpose_id])) {
                        $vendor_counts[$purpose_id]['legIntPurposes']++;
                    }
                }
            }
        }

        return array('vendorCounts' => $vendor_counts);
    }

    /**
     * Get special features metadata
     */
    private function get_special_features_metadata() {
        global $wpdb;

        $vendors_table = $wpdb->prefix . 'cmp_vendors_iab';
        $vendors = $wpdb->get_results(
            "SELECT data FROM $vendors_table WHERE is_enabled = 1",
            ARRAY_A
        );

        $vendor_counts = array();

        foreach ($vendors as $v) {
            $data = json_decode($v['data'], true);
            if (!$data || empty($data['specialFeatures'])) continue;

            foreach ($data['specialFeatures'] as $feature_id) {
                if (!isset($vendor_counts[$feature_id])) {
                    $vendor_counts[$feature_id] = 0;
                }
                $vendor_counts[$feature_id]++;
            }
        }

        return array('vendorCounts' => $vendor_counts);
    }

    /**
     * Get special purposes metadata (vendor counts per special purpose)
     */
    private function get_special_purposes_metadata($vendor_list_id) {
        global $wpdb;

        $vendors_table = $wpdb->prefix . 'cmp_vendors_iab';
        $vendors = $wpdb->get_results(
            "SELECT vendor_id, data FROM $vendors_table WHERE is_enabled = 1",
            ARRAY_A
        );

        $vendor_counts = array();

        foreach ($vendors as $v) {
            if (!$this->is_vendor_enabled_in_list($vendor_list_id, $v['vendor_id'], 'iab')) {
                continue;
            }

            $data = json_decode($v['data'], true);
            if (!$data || empty($data['specialPurposes'])) continue;

            foreach ($data['specialPurposes'] as $sp_id) {
                if (!isset($vendor_counts[$sp_id])) {
                    $vendor_counts[$sp_id] = 0;
                }
                $vendor_counts[$sp_id]++;
            }
        }

        return array('vendorCounts' => $vendor_counts);
    }

    /**
     * Get features metadata (vendor counts per feature)
     */
    private function get_features_metadata($vendor_list_id) {
        global $wpdb;

        $vendors_table = $wpdb->prefix . 'cmp_vendors_iab';
        $vendors = $wpdb->get_results(
            "SELECT vendor_id, data FROM $vendors_table WHERE is_enabled = 1",
            ARRAY_A
        );

        $vendor_counts = array();

        foreach ($vendors as $v) {
            if (!$this->is_vendor_enabled_in_list($vendor_list_id, $v['vendor_id'], 'iab')) {
                continue;
            }

            $data = json_decode($v['data'], true);
            if (!$data || empty($data['features'])) continue;

            foreach ($data['features'] as $feature_id) {
                if (!isset($vendor_counts[$feature_id])) {
                    $vendor_counts[$feature_id] = 0;
                }
                $vendor_counts[$feature_id]++;
            }
        }

        return array('vendorCounts' => $vendor_counts);
    }
}
