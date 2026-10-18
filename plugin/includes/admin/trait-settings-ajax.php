<?php
/**
 * Settings save, POST parsers, vendor toggle and all AJAX handlers.
 * Split out of class-admin.php (todo §3). Composed into FOCMP_Admin via `use`.
 */

if (!defined('ABSPATH')) {
    exit;
}

trait FOCMP_Admin_SettingsAjax {
    /**
     * Parse enabled stacks from POST (comma-separated string to array of ints)
     */
    private function parse_stacks_from_post() {
        $enabled_stacks = array();
        if (!empty($_POST['enabled_stacks'])) {
            $stacks_str = sanitize_text_field($_POST['enabled_stacks']);
            $stacks_arr = explode(',', $stacks_str);
            foreach ($stacks_arr as $stack_id) {
                $stack_id = (int) trim($stack_id);
                if ($stack_id > 0) {
                    $enabled_stacks[] = $stack_id;
                }
            }
        }
        return $enabled_stacks;
    }

    /**
     * Parse Google Consent Mode config from POST
     */
    private function parse_gcm_from_post() {
        $enabled = !empty($_POST['gcm_enabled']) ? true : false;

        $mapping = array();
        if (!empty($_POST['gcm_mapping']) && is_array($_POST['gcm_mapping'])) {
            $valid_signals = array('ad_storage', 'analytics_storage', 'ad_user_data', 'ad_personalization');
            foreach ($valid_signals as $signal) {
                if (isset($_POST['gcm_mapping'][$signal])) {
                    $raw = sanitize_text_field($_POST['gcm_mapping'][$signal]);
                    $mapping[$signal] = array_map('intval', array_filter(
                        explode(',', $raw),
                        'strlen'
                    ));
                }
            }
        }

        return array(
            'enabled' => $enabled,
            'mapping' => $mapping,
            'waitForUpdate' => max(0, (int) ($_POST['gcm_wait_for_update'] ?? 500)),
            'urlPassthrough' => !empty($_POST['gcm_url_passthrough']),
            'adsDataRedaction' => !empty($_POST['gcm_ads_data_redaction']),
        );
    }

    /**
     * Builds the custom consents of a template from the posted rows, keeping ids stable.
     *
     * Users' choices are stored per consent id (TC String publisher custom purpose N, or
     * the cmp-custom-consents cookie), so an id must never move to another consent:
     * existing rows keep their id, new rows get the next never-used number. A deleted
     * consent's number is not reused (tracked by customConsentsNextId).
     *
     * @param array $rows     Posted rows: [key => [id, title, description, li]] in form order.
     * @param array $previous Previous template config (for its consent ids and counter).
     * @return array{0: array, 1: int} [consents, next id]
     */
    private function build_custom_consents(array $rows, array $previous) {
        $known_ids = array();
        $max = 0;
        foreach ($previous['customConsents'] ?? array() as $consent) {
            if (isset($consent['id']) && preg_match('/^custom-(\d+)$/', $consent['id'], $m)) {
                $known_ids[$consent['id']] = true;
                $max = max($max, (int) $m[1]);
            }
        }
        $next = max($max + 1, (int) ($previous['customConsentsNextId'] ?? 0));

        $consents = array();
        $used = array();
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $title = sanitize_text_field($row['title'] ?? '');
            if ($title === '') {
                continue;
            }
            $id = (string) ($row['id'] ?? '');
            if (!isset($known_ids[$id]) || isset($used[$id])) {
                $id = 'custom-' . $next++;
            }
            $used[$id] = true;
            $consents[] = array(
                'id' => $id,
                'title' => $title,
                'description' => sanitize_textarea_field($row['description'] ?? ''),
                'legitimateInterestAllowed' => !empty($row['li']),
            );
        }

        return array($consents, $next);
    }

    /**
     * Parse the first-layer setup (buttons, close button, TCF summary) from POST.
     *
     * The form has one row per action: a "show" checkbox, an order number, a
     * variant and an optional label. The SDK applies the compliance profile on
     * top of this (sdk/src/services/appearance.ts → resolveFirstLayer).
     */
    private function parse_first_layer_from_post() {
        $actions  = array('settings', 'reject-all', 'accept-all', 'partners');
        $variants = array('primary', 'secondary', 'link');
        $posted   = isset($_POST['fl_buttons']) && is_array($_POST['fl_buttons']) ? wp_unslash($_POST['fl_buttons']) : array();

        $rows = array();
        foreach ($actions as $i => $action) {
            $row = isset($posted[$action]) && is_array($posted[$action]) ? $posted[$action] : array();
            if (empty($row['show'])) {
                continue;
            }
            $variant = isset($row['variant']) && in_array($row['variant'], $variants, true) ? $row['variant'] : 'secondary';
            $rows[] = array(
                'order'  => isset($row['order']) ? (int) $row['order'] : $i + 1,
                'button' => array(
                    'action'  => $action,
                    'variant' => $variant,
                    'label'   => sanitize_text_field($row['label'] ?? ''),
                ),
            );
        }
        usort($rows, function ($a, $b) {
            return $a['order'] <=> $b['order'];
        });

        return array(
            'buttons'        => array_column($rows, 'button'),
            'closeButton'    => (isset($_POST['fl_close_button']) && $_POST['fl_close_button'] === 'reject-all') ? 'reject-all' : 'none',
            'showTcfSummary' => !empty($_POST['fl_show_tcf_summary']),
        );
    }

    /**
     * Parse the consent box appearance from POST. Values are whitelisted here and
     * validated again in the SDK (resolveAppearance).
     */
    private function parse_appearance_from_post() {
        $p = isset($_POST['appearance']) && is_array($_POST['appearance']) ? wp_unslash($_POST['appearance']) : array();

        $colors = array();
        foreach (array('primary', 'primaryText', 'background', 'text') as $key) {
            $value = isset($p['colors'][$key]) ? trim((string) $p['colors'][$key]) : '';
            if (preg_match('/^#[0-9a-fA-F]{3,8}$/', $value)) {
                $colors[$key] = $value;
            }
        }

        $font = isset($p['fontFamily']) ? trim((string) $p['fontFamily']) : 'inherit';
        if ($font === '' || !preg_match('/^[\w\s,\'"-]{1,200}$/u', $font)) {
            $font = 'inherit';
        }

        $layout   = isset($p['layout']) && in_array($p['layout'], array('modal', 'bar', 'corner'), true) ? $p['layout'] : 'modal';
        $position = (isset($p['reopenButton']['position']) && $p['reopenButton']['position'] === 'bottom-right') ? 'bottom-right' : 'bottom-left';

        // Custom CSS is site-wide code: only for users who may post unfiltered HTML.
        $custom_css = '';
        if (current_user_can('unfiltered_html') && !empty($p['customCss'])) {
            $custom_css = wp_strip_all_tags((string) $p['customCss']);
        }

        return array(
            'layout'           => $layout,
            'logoUrl'          => esc_url_raw($p['logoUrl'] ?? ''),
            'logoAlt'          => sanitize_text_field($p['logoAlt'] ?? ''),
            'colors'           => $colors,
            'borderRadius'     => max(0, min(32, (int) ($p['borderRadius'] ?? 4))),
            'buttonRadius'     => max(0, min(32, (int) ($p['buttonRadius'] ?? 0))),
            'fontFamily'       => $font,
            'uppercaseButtons' => !empty($p['uppercaseButtons']),
            'overlay'          => !empty($p['overlay']),
            'blockScroll'      => !empty($p['blockScroll']),
            'reopenButton'     => array(
                'enabled'  => !empty($p['reopenButton']['enabled']),
                'position' => $position,
            ),
            'privacyPolicyUrl' => esc_url_raw($p['privacyPolicyUrl'] ?? ''),
            'customCss'        => $custom_css,
        );
    }

    /**
     * Live preview in the template editor: the SDK config for the current,
     * unsaved form (same code path as saving and as /cmp-config).
     */
    public function ajax_preview_config() {
        check_ajax_referer('focmp_admin', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'fast-open-cmp')), 403);
        }

        $id = isset($_POST['template_id']) ? (int) $_POST['template_id'] : 0;
        $config = $this->template_config_from_post($this->load_template_config($id));
        $lang = strtolower(sanitize_text_field(wp_unslash($_POST['template_language'] ?? 'pl')));
        $lang = preg_match('/^[a-z]{2}$/', $lang) ? $lang : 'pl';
        $vendor_list_id = !empty($_POST['vendor_list_id']) ? (int) $_POST['vendor_list_id'] : null;

        $api = new FOCMP_API();
        wp_send_json_success($api->build_preview_config($config, $vendor_list_id, $lang));
    }

    /**
     * Save settings
     */
    private function save_settings() {
        // Sanitize head_scripts — allow only script/link/meta tags (shared allowlist
        // with the frontend output). wp_unslash before wp_kses (WP slashes $_POST).
        // Preserve old values for API fallback (existing templates without new fields)
        $old_settings = get_option('focmp_settings', array());

        // Scripts run on every page of the site: only users allowed to post
        // unfiltered HTML may change them (on multisite a site admin may not).
        $head_scripts = $old_settings['head_scripts'] ?? '';
        if (current_user_can('unfiltered_html')) {
            $head_scripts = empty($_POST['head_scripts']) ? '' : wp_kses(
                wp_unslash($_POST['head_scripts']),
                focmp_head_scripts_allowed_html()
            );
        }

        $country = strtoupper(sanitize_text_field(wp_unslash($_POST['publisher_country_code'] ?? '')));
        if (!preg_match('/^[A-Z]{2}$/', $country)) {
            $country = $old_settings['publisher_country_code'] ?? 'PL';
        }

        $settings = array(
            'cmp_id' => max(0, (int) ($_POST['cmp_id'] ?? 0)),
            'cmp_version' => max(0, min(4095, (int) ($_POST['cmp_version'] ?? 0))),
            'publisher_country_code' => $country,
            'default_language' => sanitize_text_field(wp_unslash($_POST['default_language'] ?? 'pl')),
            'cache_ttl' => max(0, (int) ($_POST['cache_ttl'] ?? 60)),
            'gvl_url' => esc_url_raw(wp_unslash($_POST['gvl_url'] ?? '')),
            'google_atp_url' => esc_url_raw(wp_unslash($_POST['google_atp_url'] ?? '')),
            'head_scripts' => $head_scripts,
            'delete_data_on_uninstall' => !empty($_POST['delete_data_on_uninstall']),
            'gvl_auto_update' => in_array($_POST['gvl_auto_update'] ?? '', array('weekly', 'daily', 'off'), true) ? $_POST['gvl_auto_update'] : 'weekly',
            'consent_log' => !empty($_POST['consent_log']),
            'consent_log_retention_days' => max(0, (int) ($_POST['consent_log_retention_days'] ?? 1095)),
            'consent_log_delete_before' => FOCMP_Scheduler::is_date(sanitize_text_field(wp_unslash($_POST['consent_log_delete_before'] ?? '')))
                ? sanitize_text_field(wp_unslash($_POST['consent_log_delete_before']))
                : '',
            'origin_check' => !empty($_POST['origin_check']),
            'legacy_domain_ids' => !empty($_POST['legacy_domain_ids']),
        );

        // Keep old per-template fields for fallback (existing templates without these in config)
        $fallback_keys = array('tcf_policy_version', 'cookie_name', 'cookie_expiration_days', 'enabled_stacks', 'gcm_enabled', 'gcm_mapping', 'gcm_wait_for_update');
        foreach ($fallback_keys as $key) {
            if (isset($old_settings[$key])) {
                $settings[$key] = $old_settings[$key];
            }
        }

        update_option('focmp_settings', $settings);
        FOCMP_Scheduler::ensure_scheduled();

        $cache = FOCMP_Cache::get_instance();
        $cache->clear_all();

        wp_redirect(admin_url('admin.php?page=focmp-settings&message=saved'));
        exit;
    }

    /**
     * Toggle vendor enabled/disabled
     */
    private function toggle_vendor() {
        global $wpdb;

        $vendor_id = (int) $_POST['vendor_id'];
        $vendor_type = sanitize_text_field($_POST['vendor_type']);
        $is_enabled = (int) $_POST['is_enabled'];

        $tables = array(
            'iab' => $wpdb->prefix . 'cmp_vendors_iab',
            'google' => $wpdb->prefix . 'cmp_vendors_google',
            'custom' => $wpdb->prefix . 'cmp_vendors_custom',
        );

        if (isset($tables[$vendor_type])) {
            $id_column = ($vendor_type === 'iab') ? 'vendor_id' : (($vendor_type === 'google') ? 'provider_id' : 'id');

            $wpdb->update(
                $tables[$vendor_type],
                array('is_enabled' => $is_enabled),
                array($id_column => $vendor_id)
            );

            // Re-enabling globally should restore the vendor in lists that have
            // no entry for it yet (backfill skips lists where it was toggled off).
            if ($is_enabled) {
                FOCMP_Importer::backfill_vendor_list_entries();
            }
        }

        $cache = FOCMP_Cache::get_instance();
        $cache->clear_vendor_cache();
        $cache->clear_all();

        $page = 'focmp-vendors-' . $vendor_type;
        wp_redirect(admin_url('admin.php?page=' . $page . '&message=updated'));
        exit;
    }

    /**
     * AJAX: Toggle a vendor's global enabled state.
     *
     * Called by the switch in the "Wlaczony" column on the IAB/Google/Custom
     * vendor pages (admin.js .focmp-vendor-toggle handler). Without this handler
     * admin-ajax returns "0" and the toggle silently does nothing.
     */
    public function ajax_toggle_vendor() {
        check_ajax_referer('focmp_admin', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'fast-open-cmp')));
        }

        global $wpdb;

        $vendor_id = isset($_POST['vendor_id']) ? (int) $_POST['vendor_id'] : 0;
        $vendor_type = isset($_POST['vendor_type']) ? sanitize_text_field($_POST['vendor_type']) : '';
        $is_enabled = !empty($_POST['is_enabled']) ? 1 : 0;

        $tables = array(
            'iab' => $wpdb->prefix . 'cmp_vendors_iab',
            'google' => $wpdb->prefix . 'cmp_vendors_google',
            'custom' => $wpdb->prefix . 'cmp_vendors_custom',
        );

        if (!isset($tables[$vendor_type])) {
            wp_send_json_error(array('message' => __('Unknown vendor type', 'fast-open-cmp')));
        }

        $id_column = ($vendor_type === 'iab') ? 'vendor_id' : (($vendor_type === 'google') ? 'provider_id' : 'id');

        $updated = $wpdb->update(
            $tables[$vendor_type],
            array('is_enabled' => $is_enabled),
            array($id_column => $vendor_id),
            array('%d'),
            array('%d')
        );

        if ($updated === false) {
            wp_send_json_error(array('message' => __('Database write error', 'fast-open-cmp')));
        }

        // Re-enabling globally should restore the vendor in lists that have no
        // entry for it yet (backfill skips lists where it was toggled off).
        if ($is_enabled) {
            FOCMP_Importer::backfill_vendor_list_entries();
        }

        $cache = FOCMP_Cache::get_instance();
        $cache->clear_vendor_cache();
        $cache->clear_all();

        wp_send_json_success(array(
            'vendor_id' => $vendor_id,
            'vendor_type' => $vendor_type,
            'is_enabled' => $is_enabled,
        ));
    }

    /**
     * AJAX: Import GVL
     */
    public function ajax_import_gvl() {
        check_ajax_referer('focmp_admin', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'fast-open-cmp')));
        }

        $source = isset($_POST['source']) ? esc_url_raw($_POST['source']) : '';
        $type = isset($_POST['type']) ? sanitize_text_field($_POST['type']) : 'gvl';

        if (empty($source)) {
            $settings = get_option('focmp_settings', array());
            $source = !empty($settings['gvl_url']) ? $settings['gvl_url'] : FOCMP_TCF::GVL_URL;
        }

        $importer = new FOCMP_Importer();

        if ($type === 'purposes') {
            $lang = isset($_POST['lang']) ? sanitize_text_field($_POST['lang']) : 'pl';
            $result = $importer->import_iab_purposes_lang($source, $lang);
        } else {
            $result = $importer->import_iab_gvl($source);
        }

        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }

        if ($type !== 'purposes') {
            FOCMP_Scheduler::record(array(
                'time' => time(),
                'source' => 'manual',
                'ok' => true,
                'vendors' => (int) $result['vendors'],
                'deactivated' => (int) ($result['deactivated'] ?? 0),
            ));
        }

        wp_send_json_success($result);
    }

    /**
     * AJAX: Import Google ATP
     */
    public function ajax_import_google() {
        check_ajax_referer('focmp_admin', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'fast-open-cmp')));
        }

        $source = isset($_POST['source']) ? esc_url_raw($_POST['source']) : '';

        if (empty($source)) {
            $settings = get_option('focmp_settings', array());
            $source = isset($settings['google_atp_url']) ? $settings['google_atp_url'] : 'https://storage.googleapis.com/tcfac/additional-consent-providers.csv';
        }

        $importer = new FOCMP_Importer();
        $result = $importer->import_google_atp($source);

        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }

        wp_send_json_success($result);
    }

    /**
     * AJAX: Trigger reconsent
     */
    public function ajax_trigger_reconsent() {
        check_ajax_referer('focmp_admin', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'fast-open-cmp')));
        }

        global $wpdb;

        $domain_id = (int) $_POST['domain_id'];
        $title = sanitize_text_field($_POST['title']);
        $description = sanitize_textarea_field($_POST['description']);

        if (empty($domain_id) || empty($title)) {
            wp_send_json_error(array('message' => __('Domain and title are required', 'fast-open-cmp')));
        }

        $reconsent_timestamp = (int) (microtime(true) * 1000);

        // Insert reconsent record
        $wpdb->insert(
            $wpdb->prefix . 'cmp_reconsents',
            array(
                'domain_id' => $domain_id,
                'title' => $title,
                'description' => $description,
                'reconsent_timestamp' => $reconsent_timestamp,
            ),
            array('%d', '%s', '%s', '%d')
        );

        $reconsent_id = $wpdb->insert_id;

        // Update domain with current reconsent
        $wpdb->update(
            $wpdb->prefix . 'cmp_domains',
            array('current_reconsent_id' => $reconsent_id),
            array('id' => $domain_id),
            array('%d'),
            array('%d')
        );

        // Clear cache for this domain
        $cache = FOCMP_Cache::get_instance();
        $cache->clear_domain_cache($domain_id);

        wp_send_json_success(array(
            'reconsent_id' => $reconsent_id,
            'timestamp' => $reconsent_timestamp,
        ));
    }

    /**
     * AJAX handler for clearing all cache
     */
    public function ajax_clear_cache() {
        check_ajax_referer('focmp_admin', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'fast-open-cmp')));
        }

        $cache = FOCMP_Cache::get_instance();
        $cache->clear_all();

        wp_send_json_success(array('message' => __('Cache cleared', 'fast-open-cmp')));
    }

    /**
     * AJAX: Get purposes preview for a given language
     */
    public function ajax_get_purposes_preview() {
        check_ajax_referer('focmp_admin', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'fast-open-cmp')));
        }

        global $wpdb;

        $lang = isset($_POST['lang']) ? sanitize_text_field($_POST['lang']) : '';
        if (empty($lang)) {
            wp_send_json_error(array('message' => __('Missing lang parameter', 'fast-open-cmp')));
        }

        // Get translated purposes
        $translated = $wpdb->get_results($wpdb->prepare(
            "SELECT purpose_id, data FROM {$wpdb->prefix}cmp_purposes_iab WHERE language = %s ORDER BY purpose_id",
            $lang
        ));

        // Get EN purposes for comparison
        $english = $wpdb->get_results(
            "SELECT purpose_id, data FROM {$wpdb->prefix}cmp_purposes_iab WHERE language = 'en' ORDER BY purpose_id"
        );

        $en_map = array();
        foreach ($english as $row) {
            $data = json_decode($row->data, true);
            $en_map[$row->purpose_id] = $data;
        }

        $purposes = array();
        foreach ($translated as $row) {
            $data = json_decode($row->data, true);
            $en_data = isset($en_map[$row->purpose_id]) ? $en_map[$row->purpose_id] : null;
            $purposes[] = array(
                'id' => (int) $row->purpose_id,
                'name' => isset($data['name']) ? $data['name'] : '',
                'description' => isset($data['description']) ? $data['description'] : '',
                'illustrations' => isset($data['illustrations']) ? $data['illustrations'] : array(),
                'en_name' => $en_data ? (isset($en_data['name']) ? $en_data['name'] : '') : '',
                'en_description' => $en_data ? (isset($en_data['description']) ? $en_data['description'] : '') : '',
                'en_illustrations' => $en_data ? (isset($en_data['illustrations']) ? $en_data['illustrations'] : array()) : array(),
            );
        }

        // Get GVL metadata sections (specialPurposes, features, specialFeatures, stacks, dataCategories)
        $meta_keys = array('specialPurposes', 'features', 'specialFeatures', 'stacks', 'dataCategories');
        $metadata = array();

        foreach ($meta_keys as $key) {
            // Translated
            $trans_row = $wpdb->get_var($wpdb->prepare(
                "SELECT data FROM {$wpdb->prefix}cmp_gvl_metadata WHERE meta_key = %s AND language = %s",
                $key, $lang
            ));
            // English
            $en_row = $wpdb->get_var($wpdb->prepare(
                "SELECT data FROM {$wpdb->prefix}cmp_gvl_metadata WHERE meta_key = %s AND language = 'en'",
                $key
            ));

            $trans_data = $trans_row ? json_decode($trans_row, true) : null;
            $en_data = $en_row ? json_decode($en_row, true) : null;

            if ($trans_data || $en_data) {
                $items = array();
                $all_ids = array();
                if (is_array($trans_data)) {
                    $all_ids = array_merge($all_ids, array_keys($trans_data));
                }
                if (is_array($en_data)) {
                    $all_ids = array_merge($all_ids, array_keys($en_data));
                }
                $all_ids = array_unique($all_ids);
                sort($all_ids, SORT_NUMERIC);

                foreach ($all_ids as $id) {
                    $t = isset($trans_data[$id]) ? $trans_data[$id] : null;
                    $e = isset($en_data[$id]) ? $en_data[$id] : null;
                    $items[] = array(
                        'id' => $id,
                        'name' => $t ? (isset($t['name']) ? $t['name'] : '') : '',
                        'description' => $t ? (isset($t['description']) ? $t['description'] : '') : '',
                        'en_name' => $e ? (isset($e['name']) ? $e['name'] : '') : '',
                        'en_description' => $e ? (isset($e['description']) ? $e['description'] : '') : '',
                    );
                }

                $metadata[$key] = $items;
            }
        }

        wp_send_json_success(array(
            'purposes' => $purposes,
            'metadata' => $metadata,
            'lang' => $lang,
        ));
    }

    /**
     * Display names for language codes (shared by the view and AJAX refresh).
     */
    private function language_names() {
        return array(
            'en' => 'English', 'pl' => 'Polski', 'de' => 'Deutsch',
            'fr' => 'Français', 'es' => 'Español', 'it' => 'Italiano',
            'nl' => 'Nederlands', 'pt' => 'Português', 'cs' => 'Čeština',
            'da' => 'Dansk', 'fi' => 'Suomi', 'hu' => 'Magyar',
            'ja' => '日本語', 'no' => 'Norsk', 'ro' => 'Română',
            'ru' => 'Русский', 'sk' => 'Slovenčina', 'sv' => 'Svenska',
            'zh' => '中文', 'bg' => 'Български', 'ca' => 'Català',
            'el' => 'Ελληνικά', 'et' => 'Eesti', 'hr' => 'Hrvatski',
            'lt' => 'Lietuvių', 'lv' => 'Latviešu', 'mt' => 'Malti',
            'sl' => 'Slovenščina', 'tr' => 'Türkçe', 'uk' => 'Українська',
        );
    }

    /**
     * Build the imported-translations summary list (one row per language).
     */
    private function build_imported_languages_list() {
        global $wpdb;

        $purpose_languages = $wpdb->get_results("
            SELECT language, COUNT(*) as purpose_count, MAX(updated_at) as last_updated
            FROM {$wpdb->prefix}cmp_purposes_iab GROUP BY language
        ");
        $metadata_languages = $wpdb->get_results("
            SELECT language, COUNT(*) as meta_count, MAX(updated_at) as last_updated
            FROM {$wpdb->prefix}cmp_gvl_metadata
            WHERE meta_key IN ('specialPurposes','features','specialFeatures','stacks','dataCategories')
            GROUP BY language
        ");

        $langs = array();
        foreach ($purpose_languages as $row) {
            $langs[$row->language] = array(
                'purpose_count' => (int) $row->purpose_count,
                'meta_count' => 0,
                'last_updated' => $row->last_updated,
            );
        }
        foreach ($metadata_languages as $row) {
            if (isset($langs[$row->language])) {
                $langs[$row->language]['meta_count'] = (int) $row->meta_count;
                if ($row->last_updated > $langs[$row->language]['last_updated']) {
                    $langs[$row->language]['last_updated'] = $row->last_updated;
                }
            } else {
                $langs[$row->language] = array(
                    'purpose_count' => 0,
                    'meta_count' => (int) $row->meta_count,
                    'last_updated' => $row->last_updated,
                );
            }
        }
        ksort($langs);

        $names = $this->language_names();
        $out = array();
        foreach ($langs as $code => $d) {
            $out[] = array(
                'code' => $code,
                'name' => isset($names[$code]) ? $names[$code] : '',
                'purpose_count' => $d['purpose_count'],
                'meta_count' => $d['meta_count'],
                'last_updated' => $d['last_updated'],
                'has_data' => ($d['purpose_count'] > 0 || $d['meta_count'] > 0),
            );
        }
        return $out;
    }

    /**
     * AJAX: refreshed imported-translations list (for live table update).
     */
    public function ajax_imported_languages() {
        check_ajax_referer('focmp_admin', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'fast-open-cmp')));
        }
        wp_send_json_success(array('languages' => $this->build_imported_languages_list()));
    }

    /**
     * AJAX: delete one imported purpose-translation language (purposes + GVL metadata).
     */
    public function ajax_delete_translation() {
        check_ajax_referer('focmp_admin', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'fast-open-cmp')));
        }

        global $wpdb;
        $lang = isset($_POST['lang']) ? sanitize_text_field($_POST['lang']) : '';
        if (empty($lang)) {
            wp_send_json_error(array('message' => __('Missing language', 'fast-open-cmp')));
        }
        if ($lang === 'en') {
            wp_send_json_error(array('message' => __('The English (base) translation cannot be deleted.', 'fast-open-cmp')));
        }

        $wpdb->delete($wpdb->prefix . 'cmp_purposes_iab', array('language' => $lang), array('%s'));
        $wpdb->delete($wpdb->prefix . 'cmp_gvl_metadata', array('language' => $lang), array('%s'));

        $cache = FOCMP_Cache::get_instance();
        $cache->clear_purposes_cache();
        $cache->clear_all();

        wp_send_json_success(array('languages' => $this->build_imported_languages_list()));
    }

    /**
     * Demo template config — a minimal, editable template with one custom consent.
     */
    private function demo_template_config() {
        return array(
            'mode' => 'tcf_gcm',
            'texts' => array(
                'firstLayer' => array(
                    'title' => 'Szanujemy Twoją prywatność',
                    'description' => 'Używamy plików cookie i podobnych technologii, aby świadczyć usługi, analizować ruch i personalizować treści oraz reklamy.',
                    'partnersLinkText' => 'Nasi partnerzy',
                    'settingsButtonText' => 'Ustawienia',
                    'acceptAllButtonText' => 'Akceptuj wszystko',
                ),
                'secondLayer' => array(
                    'title' => 'Ustawienia prywatności',
                    'description' => 'Zarządzaj swoimi zgodami dla poszczególnych celów i partnerów.',
                    'partnersLinkText' => 'Lista partnerów',
                    'backButtonText' => 'Wstecz',
                    'preferencesTitle' => 'Twoje preferencje',
                    'confirmButtonText' => 'Zapisz wybór',
                    'rejectButtonText' => 'Odrzuć wszystko',
                    'acceptAllButtonText' => 'Akceptuj wszystko',
                ),
                'thirdLayer' => array(
                    'title' => 'Partnerzy (dostawcy)',
                    'description' => 'Poniżej znajduje się lista dostawców, którzy mogą przetwarzać Twoje dane.',
                    'backButtonText' => 'Wstecz',
                    'confirmButtonText' => 'Zapisz wybór',
                    'rejectButtonText' => 'Odrzuć wszystko',
                    'acceptAllButtonText' => 'Akceptuj wszystko',
                    'privacyPolicyText' => 'Polityka prywatności',
                    'legitimateInterestText' => 'Uzasadniony interes',
                    'objectButtonText' => 'Sprzeciw',
                    'alwaysActiveText' => 'Zawsze aktywne',
                    'vendorGroups' => array(
                        'iab' => 'Dostawcy IAB',
                        'google' => 'Dostawcy Google',
                        'other' => 'Pozostali dostawcy',
                    ),
                ),
            ),
            'customConsents' => array(
                array(
                    'id' => 'custom-1',
                    'title' => 'Zgoda demonstracyjna',
                    'description' => 'Przykładowa zgoda niestandardowa utworzona przez import danych testowych.',
                    'legitimateInterestAllowed' => false,
                ),
            ),
            'dimensions' => array('width' => null, 'height' => null),
            'cookieName' => FOCMP_TCF::COOKIE_NAME,
            'cookieExpirationDays' => 180,
            'enabledStacks' => array(42),
            'googleConsentMode' => array(
                'enabled' => true,
                // Default IAB purpose -> Google Consent Mode v2 signal mapping
                // (mirrors the fallback used by the template editor).
                'mapping' => array(
                    'ad_storage' => array(1),
                    'analytics_storage' => array(1),
                    'ad_user_data' => array(1, 2, 3, 4),
                    'ad_personalization' => array(3, 4),
                ),
                'waitForUpdate' => 500,
            ),
        );
    }

    /**
     * AJAX: import a full set of demo/test data (company, vendors, template).
     */
    public function ajax_demo_import() {
        check_ajax_referer('focmp_admin', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'fast-open-cmp')));
        }

        global $wpdb;
        $settings = get_option('focmp_settings', array());
        $importer = new FOCMP_Importer();

        // 1) Demo company (reuse if it already exists).
        $company_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}cmp_companies WHERE name = %s", 'Demo Company'
        ));
        if (!$company_id) {
            $wpdb->insert(
                $wpdb->prefix . 'cmp_companies',
                array('name' => 'Demo Company', 'description' => 'Sample company created by the demo importer.'),
                array('%s', '%s')
            );
            $company_id = (int) $wpdb->insert_id;
        }

        // 2) IAB GVL (vendors + EN purposes).
        $gvl_url = !empty($settings['gvl_url']) ? $settings['gvl_url'] : FOCMP_TCF::GVL_URL;
        $gvl = $importer->import_iab_gvl($gvl_url);
        if (is_wp_error($gvl)) {
            wp_send_json_error(array('message' => 'IAB GVL: ' . $gvl->get_error_message()));
        }

        // 3) Google ATP providers.
        $atp_url = isset($settings['google_atp_url']) ? $settings['google_atp_url'] : 'https://storage.googleapis.com/tcfac/additional-consent-providers.csv';
        $atp = $importer->import_google_atp($atp_url);
        if (is_wp_error($atp)) {
            wp_send_json_error(array('message' => 'Google ATP: ' . $atp->get_error_message()));
        }

        // 4) Two sample custom vendors.
        $custom = array(
            array('name' => 'Demo Analytics Co.', 'privacy_url' => 'https://example.com/privacy', 'category' => 'analytics'),
            array('name' => 'Demo Ads Co.', 'privacy_url' => 'https://example.com/ads-privacy', 'category' => 'advertising'),
        );
        foreach ($custom as $cv) {
            $exists = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}cmp_vendors_custom WHERE name = %s", $cv['name']
            ));
            if (!$exists) {
                $wpdb->insert(
                    $wpdb->prefix . 'cmp_vendors_custom',
                    array('name' => $cv['name'], 'privacy_url' => $cv['privacy_url'], 'category' => $cv['category'], 'is_enabled' => 1),
                    array('%s', '%s', '%s', '%d')
                );
            }
        }
        // New custom vendors must be added to existing vendor lists.
        FOCMP_Importer::backfill_vendor_list_entries();

        // 5) Demo template with one custom consent (reuse if it already exists).
        $template_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}cmp_templates WHERE name = %s", 'Demo Template'
        ));
        if (!$template_id) {
            $wpdb->insert(
                $wpdb->prefix . 'cmp_templates',
                array(
                    'name' => 'Demo Template',
                    'language' => 'pl',
                    'vendor_list_id' => null,
                    'config' => wp_json_encode($this->demo_template_config()),
                ),
                array('%s', '%s', '%d', '%s')
            );
            $template_id = (int) $wpdb->insert_id;
        }

        // 6) Demo domain linking the company + template, so the integration code works.
        $domain_name = 'demo.example.com';
        $domain_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}cmp_domains WHERE domain = %s", $domain_name
        ));
        if (!$domain_id) {
            $wpdb->insert(
                $wpdb->prefix . 'cmp_domains',
                array(
                    'company_id' => $company_id ?: null,
                    'domain' => $domain_name,
                    'prod_template_id' => $template_id ?: null,
                    'public_key' => FOCMP_Domain_Access::generate_key(),
                ),
                array('%d', '%s', '%d', '%s')
            );
            $domain_id = (int) $wpdb->insert_id;
        }

        $cache = FOCMP_Cache::get_instance();
        $cache->clear_all();

        wp_send_json_success(array(
            'message' => __('Demo data imported.', 'fast-open-cmp'),
            'summary' => sprintf(
                /* translators: 1: IAB vendor count, 2: Google provider count */
                __('(%1$d IAB vendors, %2$d Google providers, 2 custom vendors, 1 template, 1 domain)', 'fast-open-cmp'),
                (int) ($gvl['vendors'] ?? 0),
                (int) ($atp['providers'] ?? 0)
            ),
        ));
    }
}
