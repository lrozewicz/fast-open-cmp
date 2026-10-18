<?php
/**
 * Save/delete/clone handlers for companies, domains, templates, vendor lists and custom vendors.
 * Split out of class-admin.php (todo §3). Composed into FOCMP_Admin via `use`.
 */

if (!defined('ABSPATH')) {
    exit;
}

trait FOCMP_Admin_Entities {
    /**
     * Save company
     */
    private function save_company() {
        global $wpdb;

        $id = isset($_POST['company_id']) ? (int) $_POST['company_id'] : 0;
        $name = sanitize_text_field($_POST['company_name']);
        $description = sanitize_textarea_field($_POST['company_description']);

        if (empty($name)) {
            add_settings_error('focmp', 'empty_name', __('Company name is required', 'fast-open-cmp'));
            return;
        }

        $table = $wpdb->prefix . 'cmp_companies';

        if ($id) {
            $wpdb->update(
                $table,
                array('name' => $name, 'description' => $description),
                array('id' => $id),
                array('%s', '%s'),
                array('%d')
            );
        } else {
            $wpdb->insert(
                $table,
                array('name' => $name, 'description' => $description),
                array('%s', '%s')
            );
        }

        wp_redirect(admin_url('admin.php?page=focmp-companies&message=saved'));
        exit;
    }

    /**
     * Abort a delete that would break a referencing record: stash a human-readable
     * reason (shown by FOCMP_Admin::render_delete_notice on the next page) and
     * redirect back to the list without deleting.
     */
    private function block_delete($redirect_url, $message) {
        set_transient('focmp_delete_notice_' . get_current_user_id(), $message, 30);
        wp_safe_redirect($redirect_url);
        exit;
    }

    /**
     * Format a list of dependent labels for an error message (capped).
     */
    private function format_dependents($items, $max = 5) {
        $items = array_values(array_filter(array_map('strval', $items), 'strlen'));
        if (count($items) > $max) {
            $rest = count($items) - $max;
            return implode(', ', array_slice($items, 0, $max)) . ' ' . sprintf(
                /* translators: %d: number of further items not listed */
                _n('and %d more', 'and %d more', $rest, 'fast-open-cmp'),
                $rest
            );
        }
        return implode(', ', $items);
    }

    /**
     * Delete company (blocked if any domain still uses it).
     */
    private function delete_company($id) {
        global $wpdb;

        $domains = $wpdb->get_col($wpdb->prepare(
            "SELECT domain FROM {$wpdb->prefix}cmp_domains WHERE company_id = %d",
            $id
        ));
        if (!empty($domains)) {
            $this->block_delete(
                admin_url('admin.php?page=focmp-companies'),
                sprintf(
                    /* translators: %s: comma-separated domain names */
                    __('Cannot delete this company — it is still used by domain(s): %s. Reassign or delete those domains first.', 'fast-open-cmp'),
                    $this->format_dependents($domains)
                )
            );
        }

        $wpdb->delete($wpdb->prefix . 'cmp_companies', array('id' => $id), array('%d'));
        wp_redirect(admin_url('admin.php?page=focmp-companies&message=deleted'));
        exit;
    }

    /**
     * Save domain
     */
    private function save_domain() {
        global $wpdb;

        $id = isset($_POST['domain_id']) ? (int) $_POST['domain_id'] : 0;
        $company_id = (int) $_POST['company_id'];
        // Stored as a bare host: the origin check compares it with the page's host.
        $domain = FOCMP_Domain_Access::normalize_host(sanitize_text_field(wp_unslash($_POST['domain_name'] ?? '')));
        $prod_template_id = !empty($_POST['prod_template_id']) ? (int) $_POST['prod_template_id'] : null;
        $test_template_id = !empty($_POST['test_template_id']) ? (int) $_POST['test_template_id'] : null;

        if (empty($domain) || empty($company_id)) {
            add_settings_error('focmp', 'empty_field', __('Domain and company are required', 'fast-open-cmp'));
            return;
        }

        $table = $wpdb->prefix . 'cmp_domains';

        $data = array(
            'company_id' => $company_id,
            'domain' => $domain,
            'prod_template_id' => $prod_template_id,
            'test_template_id' => $test_template_id,
        );

        if ($id) {
            $wpdb->update($table, $data, array('id' => $id));
        } else {
            $data['public_key'] = FOCMP_Domain_Access::generate_key();
            $wpdb->insert($table, $data);
        }

        // Clear cache
        $cache = FOCMP_Cache::get_instance();
        $cache->clear_domain_cache($id ?: $wpdb->insert_id);

        wp_redirect(admin_url('admin.php?page=focmp-domains&message=saved'));
        exit;
    }

    /**
     * Delete domain
     */
    private function delete_domain($id) {
        global $wpdb;

        // Clear cache first
        $cache = FOCMP_Cache::get_instance();
        $cache->clear_domain_cache($id);

        $wpdb->delete($wpdb->prefix . 'cmp_domains', array('id' => $id), array('%d'));
        // The reconsent history belongs to the domain; orphans showed up as "N/A".
        $wpdb->delete($wpdb->prefix . 'cmp_reconsents', array('domain_id' => $id), array('%d'));
        wp_redirect(admin_url('admin.php?page=focmp-domains&message=deleted'));
        exit;
    }

    /**
     * Template config from the editor form (shared by save and live preview).
     *
     * @param array $previous_config Stored config of the template (stable consent ids).
     */
    private function template_config_from_post(array $previous_config) {
        // Template mode
        $valid_modes = array('tcf_gcm', 'gcm_only', 'basic');
        $mode = isset($_POST['template_mode']) && in_array($_POST['template_mode'], $valid_modes)
            ? $_POST['template_mode']
            : 'tcf_gcm';

        // Build config from form data
        $config = array(
            'mode' => $mode,
            'texts' => array(
                'firstLayer' => array(
                    'title' => sanitize_text_field($_POST['text_first_title']),
                    'description' => wp_kses_post($_POST['text_first_description']),
                    'partnersLinkText' => sanitize_text_field($_POST['text_first_partners_link']),
                    'settingsButtonText' => sanitize_text_field($_POST['text_first_settings_button']),
                    'acceptAllButtonText' => sanitize_text_field($_POST['text_first_accept_button']),
                    'rejectAllButtonText' => sanitize_text_field($_POST['text_first_reject_button'] ?? ''),
                    'closeButtonLabel' => sanitize_text_field($_POST['text_first_close_label'] ?? ''),
                    'privacyPolicyLinkText' => sanitize_text_field($_POST['text_first_privacy_link'] ?? ''),
                    'vendorCountText' => sanitize_text_field($_POST['text_first_vendor_count'] ?? ''),
                    'purposesIntroText' => sanitize_text_field($_POST['text_first_purposes_intro'] ?? ''),
                    'reopenButtonLabel' => sanitize_text_field($_POST['text_first_reopen_label'] ?? ''),
                ),
                'secondLayer' => array(
                    'title' => sanitize_text_field($_POST['text_second_title']),
                    'description' => wp_kses_post($_POST['text_second_description']),
                    'partnersLinkText' => sanitize_text_field($_POST['text_second_partners_link']),
                    'backButtonText' => sanitize_text_field($_POST['text_second_back_button']),
                    'preferencesTitle' => sanitize_text_field($_POST['text_second_preferences_title']),
                    'confirmButtonText' => sanitize_text_field($_POST['text_second_confirm_button']),
                    'rejectButtonText' => sanitize_text_field($_POST['text_second_reject_button']),
                    'acceptAllButtonText' => sanitize_text_field($_POST['text_second_accept_button']),
                ),
                'thirdLayer' => array(
                    'title' => sanitize_text_field($_POST['text_third_title']),
                    'description' => sanitize_textarea_field($_POST['text_third_description']),
                    'backButtonText' => sanitize_text_field($_POST['text_third_back_button']),
                    'confirmButtonText' => sanitize_text_field($_POST['text_third_confirm_button']),
                    'rejectButtonText' => sanitize_text_field($_POST['text_third_reject_button']),
                    'acceptAllButtonText' => sanitize_text_field($_POST['text_third_accept_button']),
                    'privacyPolicyText' => sanitize_text_field($_POST['text_third_privacy_policy']),
                    'legitimateInterestText' => sanitize_text_field($_POST['text_third_legitimate_interest']),
                    'objectButtonText' => sanitize_text_field($_POST['text_third_object_button']),
                    'alwaysActiveText' => sanitize_text_field($_POST['text_third_always_active']),
                    'vendorGroups' => array(
                        'iab' => sanitize_text_field($_POST['text_vendor_group_iab']),
                        'google' => sanitize_text_field($_POST['text_vendor_group_google']),
                        'other' => sanitize_text_field($_POST['text_vendor_group_other']),
                    ),
                ),
            ),
            'customConsents' => array(),
            'dimensions' => array(
                'width' => !empty($_POST['consentbox_width']) ? (int) $_POST['consentbox_width'] : null,
                'height' => !empty($_POST['consentbox_height']) ? (int) $_POST['consentbox_height'] : null,
            ),
            'cookieName' => sanitize_text_field($_POST['cookie_name'] ?? FOCMP_TCF::COOKIE_NAME) ?: FOCMP_TCF::COOKIE_NAME,
            'cookieExpirationDays' => (int) ($_POST['cookie_expiration_days'] ?? 180),
            'consentMaxAgeDays' => max(0, (int) ($_POST['consent_max_age_days'] ?? 395)),
            'renewOnNewVendors' => !empty($_POST['renew_on_new_vendors']),
            'complianceProfile' => (isset($_POST['compliance_profile']) && $_POST['compliance_profile'] === 'custom') ? 'custom' : 'certified',
            'firstLayer' => $this->parse_first_layer_from_post(),
            'appearance' => $this->parse_appearance_from_post(),
            'enabledStacks' => $this->parse_stacks_from_post(),
            'googleConsentMode' => $this->parse_gcm_from_post(),
        );

        // Custom consents with stable ids (see build_custom_consents)
        $rows = isset($_POST['custom_consents']) && is_array($_POST['custom_consents']) ? wp_unslash($_POST['custom_consents']) : array();
        list($config['customConsents'], $config['customConsentsNextId']) = $this->build_custom_consents($rows, $previous_config);

        return $config;
    }

    /**
     * Stored config of a template (empty for a new one).
     */
    private function load_template_config($id) {
        global $wpdb;
        if (!$id) {
            return array();
        }
        $json = $wpdb->get_var($wpdb->prepare("SELECT config FROM {$wpdb->prefix}cmp_templates WHERE id = %d", $id));
        return $json ? (json_decode($json, true) ?: array()) : array();
    }

    /**
     * Save template
     */
    private function save_template() {
        global $wpdb;

        $id = isset($_POST['template_id']) ? (int) $_POST['template_id'] : 0;
        $name = sanitize_text_field($_POST['template_name']);
        $language = strtolower(sanitize_text_field(wp_unslash($_POST['template_language'] ?? 'pl')));
        if (!preg_match('/^[a-z]{2}$/', $language)) {
            $language = 'pl';
        }
        $vendor_list_id = !empty($_POST['vendor_list_id']) ? (int) $_POST['vendor_list_id'] : null;

        $table = $wpdb->prefix . 'cmp_templates';
        $config = $this->template_config_from_post($this->load_template_config($id));

        $data = array(
            'name' => $name,
            'language' => $language,
            'vendor_list_id' => $vendor_list_id,
            'config' => wp_json_encode($config),
        );

        if ($id) {
            $wpdb->update($table, $data, array('id' => $id));
        } else {
            $data['public_key'] = FOCMP_Domain_Access::generate_key();
            $wpdb->insert($table, $data);
        }

        // Clear all domain caches that use this template
        $cache = FOCMP_Cache::get_instance();
        $cache->clear_all();

        wp_redirect(admin_url('admin.php?page=focmp-templates&message=saved'));
        exit;
    }

    /**
     * Delete template
     */
    private function delete_template($id) {
        global $wpdb;

        $domains = $wpdb->get_col($wpdb->prepare(
            "SELECT domain FROM {$wpdb->prefix}cmp_domains WHERE prod_template_id = %d OR test_template_id = %d",
            $id, $id
        ));
        if (!empty($domains)) {
            $this->block_delete(
                admin_url('admin.php?page=focmp-templates'),
                sprintf(
                    /* translators: %s: comma-separated domain names */
                    __('Cannot delete this template — it is still used by domain(s): %s. Change or delete those domains first.', 'fast-open-cmp'),
                    $this->format_dependents($domains)
                )
            );
        }

        $wpdb->delete($wpdb->prefix . 'cmp_templates', array('id' => $id), array('%d'));

        $cache = FOCMP_Cache::get_instance();
        $cache->clear_all();

        wp_redirect(admin_url('admin.php?page=focmp-templates&message=deleted'));
        exit;
    }

    /**
     * Clone template
     */
    private function clone_template($id) {
        global $wpdb;

        $template = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}cmp_templates WHERE id = %d",
            $id
        ));

        if ($template) {
            $wpdb->insert(
                $wpdb->prefix . 'cmp_templates',
                array(
                    'name' => sprintf(/* translators: %s: name of the cloned item */ __('%s (copy)', 'fast-open-cmp'), $template->name),
                    'language' => $template->language,
                    'vendor_list_id' => $template->vendor_list_id,
                    'config' => $template->config,
                )
            );
        }

        wp_redirect(admin_url('admin.php?page=focmp-templates&message=cloned'));
        exit;
    }

    /**
     * Render vendor lists page
     */
    public function render_vendor_lists() {
        global $wpdb;

        $action = isset($_GET['action']) ? sanitize_text_field($_GET['action']) : 'list';
        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

        $vendor_list = null;
        $list_entries = array('iab' => array(), 'google' => array(), 'custom' => array());
        $vendors_iab = array();
        $vendors_google = array();
        $vendors_custom = array();

        if ($id && in_array($action, array('edit'))) {
            $vendor_list = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}cmp_vendor_lists WHERE id = %d",
                $id
            ));

            if ($vendor_list) {
                // Get all vendors
                $vendors_iab = $wpdb->get_results("SELECT vendor_id, name, is_enabled FROM {$wpdb->prefix}cmp_vendors_iab ORDER BY vendor_id ASC");
                $vendors_google = $wpdb->get_results("SELECT provider_id, provider_name, is_enabled FROM {$wpdb->prefix}cmp_vendors_google ORDER BY provider_id ASC");
                $vendors_custom = $wpdb->get_results("SELECT id, name, is_enabled FROM {$wpdb->prefix}cmp_vendors_custom ORDER BY name ASC");

                // Get list entries
                $entries = $wpdb->get_results($wpdb->prepare(
                    "SELECT vendor_id, vendor_type, is_enabled FROM {$wpdb->prefix}cmp_vendor_list_entries WHERE vendor_list_id = %d",
                    $id
                ));

                foreach ($entries as $entry) {
                    $list_entries[$entry->vendor_type][$entry->vendor_id] = $entry->is_enabled;
                }
            }
        }

        // For list view, get all vendor lists with template count
        $vendor_lists = $wpdb->get_results("
            SELECT vl.*, COUNT(t.id) as template_count
            FROM {$wpdb->prefix}cmp_vendor_lists vl
            LEFT JOIN {$wpdb->prefix}cmp_templates t ON t.vendor_list_id = vl.id
            GROUP BY vl.id
            ORDER BY vl.name ASC
        ");

        include FOCMP_PLUGIN_DIR . 'admin/views/vendor-lists.php';
    }

    /**
     * Save vendor list
     */
    private function save_vendor_list() {
        global $wpdb;

        $id = isset($_POST['vendor_list_id']) ? (int) $_POST['vendor_list_id'] : 0;
        $name = sanitize_text_field($_POST['list_name']);
        $description = sanitize_textarea_field($_POST['list_description']);

        if (empty($name)) {
            add_settings_error('focmp', 'empty_name', __('List name is required', 'fast-open-cmp'));
            return;
        }

        $table = $wpdb->prefix . 'cmp_vendor_lists';

        if ($id) {
            $wpdb->update(
                $table,
                array('name' => $name, 'description' => $description),
                array('id' => $id),
                array('%s', '%s'),
                array('%d')
            );
            $list_id = $id;
        } else {
            $wpdb->insert(
                $table,
                array('name' => $name, 'description' => $description),
                array('%s', '%s')
            );
            $list_id = $wpdb->insert_id;

            // For new lists, populate entries for all globally enabled vendors
            $this->populate_vendor_list_entries($list_id);
        }

        // Update entries if this is an existing list with vendor checkboxes submitted
        if ($id) {
            $this->update_vendor_list_entries($list_id);
        }

        $cache = FOCMP_Cache::get_instance();
        $cache->clear_all();

        // Redirect back to edit page if editing, otherwise to list
        if ($list_id && $id) {
            wp_redirect(admin_url('admin.php?page=focmp-vendor-lists&action=edit&id=' . $list_id . '&message=saved'));
        } else {
            wp_redirect(admin_url('admin.php?page=focmp-vendor-lists&action=edit&id=' . $list_id . '&message=saved'));
        }
        exit;
    }

    /**
     * Populate vendor list entries for a new list with all globally enabled vendors
     */
    private function populate_vendor_list_entries($list_id) {
        global $wpdb;

        $entries_table = $wpdb->prefix . 'cmp_vendor_list_entries';

        // IAB vendors
        $iab_vendors = $wpdb->get_results("SELECT vendor_id FROM {$wpdb->prefix}cmp_vendors_iab WHERE is_enabled = 1");
        foreach ($iab_vendors as $v) {
            $wpdb->insert($entries_table, array(
                'vendor_list_id' => $list_id,
                'vendor_id' => $v->vendor_id,
                'vendor_type' => 'iab',
                'is_enabled' => 1,
            ));
        }

        // Google vendors
        $google_vendors = $wpdb->get_results("SELECT provider_id FROM {$wpdb->prefix}cmp_vendors_google WHERE is_enabled = 1");
        foreach ($google_vendors as $v) {
            $wpdb->insert($entries_table, array(
                'vendor_list_id' => $list_id,
                'vendor_id' => $v->provider_id,
                'vendor_type' => 'google',
                'is_enabled' => 1,
            ));
        }

        // Custom vendors
        $custom_vendors = $wpdb->get_results("SELECT id FROM {$wpdb->prefix}cmp_vendors_custom WHERE is_enabled = 1");
        foreach ($custom_vendors as $v) {
            $wpdb->insert($entries_table, array(
                'vendor_list_id' => $list_id,
                'vendor_id' => $v->id,
                'vendor_type' => 'custom',
                'is_enabled' => 1,
            ));
        }
    }

    /**
     * Update vendor list entries from form submission
     *
     * Uses compact "disabled IDs" approach: JS collects unchecked vendor IDs
     * into comma-separated hidden fields (disabled_iab, disabled_google, disabled_custom).
     * This avoids PHP max_input_vars limit with thousands of vendors.
     */
    private function update_vendor_list_entries($list_id) {
        global $wpdb;

        $entries_table = $wpdb->prefix . 'cmp_vendor_list_entries';

        // Parse disabled vendor IDs from comma-separated hidden fields
        $disabled = array(
            'iab' => $this->parse_id_list($_POST['disabled_iab'] ?? ''),
            'google' => $this->parse_id_list($_POST['disabled_google'] ?? ''),
            'custom' => $this->parse_id_list($_POST['disabled_custom'] ?? ''),
        );

        // Map each vendor type to its source table + ID column
        $sources = array(
            'iab'    => array('table' => $wpdb->prefix . 'cmp_vendors_iab',    'col' => 'vendor_id'),
            'google' => array('table' => $wpdb->prefix . 'cmp_vendors_google', 'col' => 'provider_id'),
            'custom' => array('table' => $wpdb->prefix . 'cmp_vendors_custom', 'col' => 'id'),
        );

        // Upsert an entry for EVERY current vendor: enabled unless it is in the
        // submitted disabled list. This (re)creates entries that may never have
        // been populated (e.g. list created before vendors were imported), which
        // is why toggling previously appeared to "not save".
        foreach ($sources as $type => $src) {
            $vendor_ids = $wpdb->get_col("SELECT {$src['col']} FROM {$src['table']}");
            if (empty($vendor_ids)) {
                continue;
            }

            $disabled_ids = $disabled[$type];

            // Chunk the multi-row upsert to stay within query-size limits.
            foreach (array_chunk($vendor_ids, 500) as $chunk) {
                $rows = array();
                foreach ($chunk as $vid) {
                    $vid = (int) $vid;
                    $is_enabled = in_array($vid, $disabled_ids, true) ? 0 : 1;
                    $rows[] = $wpdb->prepare('(%d, %d, %s, %d)', $list_id, $vid, $type, $is_enabled);
                }

                // Each row in $rows is individually built with $wpdb->prepare()
                // above; the table name is an internal constant.
                // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
                $wpdb->query(
                    "INSERT INTO $entries_table (vendor_list_id, vendor_id, vendor_type, is_enabled)
                     VALUES " . implode(',', $rows) . "
                     ON DUPLICATE KEY UPDATE is_enabled = VALUES(is_enabled)"
                );
                // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
            }
        }
    }

    /**
     * Parse a comma-separated string of IDs into an array of integers
     */
    private function parse_id_list($str) {
        if (empty($str)) {
            return array();
        }
        return array_map('intval', array_filter(explode(',', $str), 'strlen'));
    }

    /**
     * Delete vendor list
     */
    private function delete_vendor_list($id) {
        global $wpdb;

        $templates = $wpdb->get_col($wpdb->prepare(
            "SELECT name FROM {$wpdb->prefix}cmp_templates WHERE vendor_list_id = %d",
            $id
        ));
        if (!empty($templates)) {
            $this->block_delete(
                admin_url('admin.php?page=focmp-vendor-lists'),
                sprintf(
                    /* translators: %s: comma-separated template names */
                    __('Cannot delete this vendor list — it is still used by template(s): %s. Change or delete those templates first.', 'fast-open-cmp'),
                    $this->format_dependents($templates)
                )
            );
        }

        // Delete entries
        $wpdb->delete($wpdb->prefix . 'cmp_vendor_list_entries', array('vendor_list_id' => $id), array('%d'));

        // Delete the list
        $wpdb->delete($wpdb->prefix . 'cmp_vendor_lists', array('id' => $id), array('%d'));

        $cache = FOCMP_Cache::get_instance();
        $cache->clear_all();

        wp_redirect(admin_url('admin.php?page=focmp-vendor-lists&message=deleted'));
        exit;
    }

    /**
     * Clone vendor list
     */
    private function clone_vendor_list($id) {
        global $wpdb;

        $list = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}cmp_vendor_lists WHERE id = %d",
            $id
        ));

        if ($list) {
            // Create new list
            $wpdb->insert(
                $wpdb->prefix . 'cmp_vendor_lists',
                array(
                    'name' => sprintf(/* translators: %s: name of the cloned item */ __('%s (copy)', 'fast-open-cmp'), $list->name),
                    'description' => $list->description,
                ),
                array('%s', '%s')
            );

            $new_id = $wpdb->insert_id;

            // Copy all entries
            $entries = $wpdb->get_results($wpdb->prepare(
                "SELECT vendor_id, vendor_type, is_enabled FROM {$wpdb->prefix}cmp_vendor_list_entries WHERE vendor_list_id = %d",
                $id
            ));

            foreach ($entries as $entry) {
                $wpdb->insert(
                    $wpdb->prefix . 'cmp_vendor_list_entries',
                    array(
                        'vendor_list_id' => $new_id,
                        'vendor_id' => $entry->vendor_id,
                        'vendor_type' => $entry->vendor_type,
                        'is_enabled' => $entry->is_enabled,
                    )
                );
            }
        }

        wp_redirect(admin_url('admin.php?page=focmp-vendor-lists&message=cloned'));
        exit;
    }

    /**
     * Save custom vendor
     */
    private function save_vendor_custom() {
        global $wpdb;

        $id = isset($_POST['vendor_id']) ? (int) $_POST['vendor_id'] : 0;
        $name = sanitize_text_field($_POST['vendor_name']);
        $privacy_url = esc_url_raw($_POST['vendor_privacy_url']);
        $category = sanitize_text_field($_POST['vendor_category']);

        $table = $wpdb->prefix . 'cmp_vendors_custom';

        $data = array(
            'name' => $name,
            'privacy_url' => $privacy_url,
            'category' => $category,
        );

        if ($id) {
            $wpdb->update($table, $data, array('id' => $id));
        } else {
            $wpdb->insert($table, $data);
            // A brand-new custom vendor must be added (enabled) to existing lists,
            // otherwise it has no entry and is treated as "not in the list".
            FOCMP_Importer::backfill_vendor_list_entries();
        }

        $cache = FOCMP_Cache::get_instance();
        $cache->clear_vendor_cache();

        wp_redirect(admin_url('admin.php?page=focmp-vendors-custom&message=saved'));
        exit;
    }

    /**
     * Delete custom vendor
     */
    private function delete_vendor_custom($id) {
        global $wpdb;
        $wpdb->delete($wpdb->prefix . 'cmp_vendors_custom', array('id' => $id), array('%d'));

        // Clean up this vendor's entries in every vendor list (no hard dependency —
        // removing a custom vendor just drops it from the lists).
        $wpdb->delete(
            $wpdb->prefix . 'cmp_vendor_list_entries',
            array('vendor_type' => 'custom', 'vendor_id' => $id),
            array('%s', '%d')
        );

        $cache = FOCMP_Cache::get_instance();
        $cache->clear_vendor_cache();
        $cache->clear_all();

        wp_redirect(admin_url('admin.php?page=focmp-vendors-custom&message=deleted'));
        exit;
    }
}
