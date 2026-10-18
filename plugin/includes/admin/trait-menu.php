<?php
/**
 * Menu registration, asset enqueue, action dispatch and page rendering.
 * Split out of class-admin.php (todo §3). Composed into FOCMP_Admin via `use`.
 */

if (!defined('ABSPATH')) {
    exit;
}

trait FOCMP_Admin_Menu {
    /**
     * Initialize admin
     */
    public function init() {
        add_action('admin_menu', array($this, 'add_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
        add_action('admin_init', array($this, 'handle_actions'));
        add_action('admin_init', array($this, 'maybe_export_consent_log'));
        add_action('admin_init', array($this, 'handle_consent_log_tools'));
        add_action('admin_init', array($this, 'handle_backup'));
        add_action('admin_notices', array($this, 'render_delete_notice'));
        add_action('admin_notices', array($this, 'render_form_errors'));
        add_action('admin_notices', array($this, 'render_cmp_id_notice'));
        add_action('admin_notices', array($this, 'render_gvl_notice'));
        add_action('wp_ajax_focmp_import_gvl', array($this, 'ajax_import_gvl'));
        add_action('wp_ajax_focmp_import_google', array($this, 'ajax_import_google'));
        add_action('wp_ajax_focmp_trigger_reconsent', array($this, 'ajax_trigger_reconsent'));
        add_action('wp_ajax_focmp_clear_cache', array($this, 'ajax_clear_cache'));
        add_action('wp_ajax_focmp_get_purposes_preview', array($this, 'ajax_get_purposes_preview'));
        add_action('wp_ajax_focmp_toggle_vendor', array($this, 'ajax_toggle_vendor'));
        add_action('wp_ajax_focmp_imported_languages', array($this, 'ajax_imported_languages'));
        add_action('wp_ajax_focmp_delete_translation', array($this, 'ajax_delete_translation'));
        add_action('wp_ajax_focmp_demo_import', array($this, 'ajax_demo_import'));
        add_action('wp_ajax_focmp_preview_config', array($this, 'ajax_preview_config'));
    }

    /**
     * Show the "cannot delete — still in use" error stashed by block_delete().
     */
    public function render_delete_notice() {
        $key = 'focmp_delete_notice_' . get_current_user_id();
        $msg = get_transient($key);
        if ($msg) {
            delete_transient($key);
            printf(
                '<div class="notice notice-error is-dismissible"><p>%s</p></div>',
                esc_html($msg)
            );
        }
    }

    /**
     * Show validation errors added by the save handlers (add_settings_error).
     * WordPress prints them automatically only on Settings pages, so on our own
     * pages they were silently dropped.
     */
    public function render_form_errors() {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if ($page === 'fast-open-cmp' || strpos($page, 'focmp-') === 0) {
            settings_errors('focmp');
        }
    }

    /**
     * Warning on the plugin's pages when the GVL was not refreshed recently.
     */
    public function render_gvl_notice() {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if (($page !== 'fast-open-cmp' && strpos($page, 'focmp-') !== 0) || !FOCMP_Scheduler::is_stale()) {
            return;
        }
        printf(
            '<div class="notice notice-warning"><p><strong>%s</strong> %s <a href="%s">%s</a></p></div>',
            esc_html__('The vendor list (GVL) may be out of date.', 'fast-open-cmp'),
            esc_html(focmp_gvl_status_text()),
            esc_url(admin_url('admin.php?page=focmp-vendors-iab')),
            esc_html__('IAB Vendors', 'fast-open-cmp')
        );
    }

    /**
     * Persistent warning on the plugin's pages until an own CMP ID is configured.
     */
    public function render_cmp_id_notice() {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if (($page !== 'fast-open-cmp' && strpos($page, 'focmp-') !== 0) || FOCMP_TCF::has_registered_cmp_id()) {
            return;
        }
        printf(
            '<div class="notice notice-warning"><p><strong>%s</strong> %s <a href="%s">%s</a></p></div>',
            esc_html__('No own CMP ID configured.', 'fast-open-cmp'),
            esc_html__('TC Strings currently carry the development placeholder 28, which belongs to another registered CMP. Register with IAB Europe and enter your CMP ID before serving the full TCF mode in production.', 'fast-open-cmp'),
            esc_url(admin_url('admin.php?page=focmp-settings')),
            esc_html__('Settings', 'fast-open-cmp')
        );
    }

    /**
     * Add admin menu
     */
    public function add_menu() {
        // Main menu
        add_menu_page(
            __('Fast Open CMP', 'fast-open-cmp'),
            __('Fast Open CMP', 'fast-open-cmp'),
            'manage_options',
            'fast-open-cmp',
            array($this, 'render_dashboard'),
            'dashicons-shield',
            80
        );

        // Submenu pages — ordered to follow the configuration flow:
        // Dashboard -> Companies -> vendors (IAB/Google/Custom) -> Vendor Lists
        // -> Templates -> Domains -> Reconsents -> Settings. (Domains references a
        // Template, which references a Vendor List, so those come first.)
        add_submenu_page(
            'fast-open-cmp',
            __('Dashboard', 'fast-open-cmp'),
            __('Dashboard', 'fast-open-cmp'),
            'manage_options',
            'fast-open-cmp',
            array($this, 'render_dashboard')
        );

        add_submenu_page(
            'fast-open-cmp',
            __('Companies', 'fast-open-cmp'),
            __('Companies', 'fast-open-cmp'),
            'manage_options',
            'focmp-companies',
            array($this, 'render_companies')
        );

        add_submenu_page(
            'fast-open-cmp',
            __('IAB Vendors', 'fast-open-cmp'),
            __('IAB Vendors', 'fast-open-cmp'),
            'manage_options',
            'focmp-vendors-iab',
            array($this, 'render_vendors_iab')
        );

        add_submenu_page(
            'fast-open-cmp',
            __('Google Vendors', 'fast-open-cmp'),
            __('Google Vendors', 'fast-open-cmp'),
            'manage_options',
            'focmp-vendors-google',
            array($this, 'render_vendors_google')
        );

        add_submenu_page(
            'fast-open-cmp',
            __('Custom Vendors', 'fast-open-cmp'),
            __('Custom Vendors', 'fast-open-cmp'),
            'manage_options',
            'focmp-vendors-custom',
            array($this, 'render_vendors_custom')
        );

        add_submenu_page(
            'fast-open-cmp',
            __('Vendor Lists', 'fast-open-cmp'),
            __('Vendor Lists', 'fast-open-cmp'),
            'manage_options',
            'focmp-vendor-lists',
            array($this, 'render_vendor_lists')
        );

        add_submenu_page(
            'fast-open-cmp',
            __('Templates', 'fast-open-cmp'),
            __('Templates', 'fast-open-cmp'),
            'manage_options',
            'focmp-templates',
            array($this, 'render_templates')
        );

        add_submenu_page(
            'fast-open-cmp',
            __('Domains', 'fast-open-cmp'),
            __('Domains', 'fast-open-cmp'),
            'manage_options',
            'focmp-domains',
            array($this, 'render_domains')
        );

        add_submenu_page(
            'fast-open-cmp',
            __('Reconsents', 'fast-open-cmp'),
            __('Reconsents', 'fast-open-cmp'),
            'manage_options',
            'focmp-reconsents',
            array($this, 'render_reconsents')
        );

        add_submenu_page(
            'fast-open-cmp',
            __('Consent log', 'fast-open-cmp'),
            __('Consent log', 'fast-open-cmp'),
            'manage_options',
            'focmp-consent-log',
            array($this, 'render_consent_log')
        );

        add_submenu_page(
            'fast-open-cmp',
            __('Settings', 'fast-open-cmp'),
            __('Settings', 'fast-open-cmp'),
            'manage_options',
            'focmp-settings',
            array($this, 'render_settings')
        );

        add_submenu_page(
            'fast-open-cmp',
            __('Backup & migration', 'fast-open-cmp'),
            __('Backup', 'fast-open-cmp'),
            'manage_options',
            'focmp-backup',
            array($this, 'render_backup')
        );
    }

    /**
     * Enqueue admin assets
     */
    public function enqueue_assets($hook) {
        if (strpos($hook, 'focmp') === false && strpos($hook, 'fast-open-cmp') === false) {
            return;
        }

        // Version admin assets by file mtime (falling back to the plugin version)
        // so changes cache-bust automatically — in dev between edits, and for
        // browsers after a deploy — without bumping FOCMP_VERSION by hand.
        $css_path = FOCMP_PLUGIN_DIR . 'admin/css/admin.css';
        $js_path  = FOCMP_PLUGIN_DIR . 'admin/js/admin.js';
        $css_ver  = file_exists($css_path) ? (string) filemtime($css_path) : FOCMP_VERSION;
        $js_ver   = file_exists($js_path) ? (string) filemtime($js_path) : FOCMP_VERSION;

        wp_enqueue_style(
            'focmp-admin',
            FOCMP_PLUGIN_URL . 'admin/css/admin.css',
            array(),
            $css_ver
        );

        wp_enqueue_script(
            'focmp-admin',
            FOCMP_PLUGIN_URL . 'admin/js/admin.js',
            array('jquery'),
            $js_ver,
            true
        );

        wp_localize_script('focmp-admin', 'focmpAdmin', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('focmp_admin'),
            'strings' => array(
                'confirmDelete' => __('Are you sure you want to delete?', 'fast-open-cmp'),
                'importing' => __('Importing...', 'fast-open-cmp'),
                'importSuccess' => __('Import completed successfully', 'fast-open-cmp'),
                'importError' => __('Import error', 'fast-open-cmp'),
                /* translators: 1: number of enabled vendors, 2: total number of vendors */
                'enabledCount' => __('%1$s / %2$s enabled', 'fast-open-cmp'),
                'toggleError' => __('The change could not be saved. Please reload the page and try again.', 'fast-open-cmp'),
            ),
        ));
    }

    /**
     * Handle form actions
     */
    public function handle_actions() {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Handle company actions
        if (isset($_POST['focmp_save_company']) && wp_verify_nonce($_POST['_wpnonce'], 'focmp_company')) {
            $this->save_company();
        }

        if (isset($_GET['action']) && $_GET['action'] === 'delete_company' && isset($_GET['id'])) {
            if (wp_verify_nonce($_GET['_wpnonce'], 'delete_company_' . $_GET['id'])) {
                $this->delete_company((int) $_GET['id']);
            }
        }

        // Handle domain actions
        if (isset($_POST['focmp_save_domain']) && wp_verify_nonce($_POST['_wpnonce'], 'focmp_domain')) {
            $this->save_domain();
        }

        if (isset($_GET['action']) && $_GET['action'] === 'delete_domain' && isset($_GET['id'])) {
            if (wp_verify_nonce($_GET['_wpnonce'], 'delete_domain_' . $_GET['id'])) {
                $this->delete_domain((int) $_GET['id']);
            }
        }

        // Handle template actions
        if (isset($_POST['focmp_save_template']) && wp_verify_nonce($_POST['_wpnonce'], 'focmp_template')) {
            $this->save_template();
        }

        if (isset($_GET['action']) && $_GET['action'] === 'delete_template' && isset($_GET['id'])) {
            if (wp_verify_nonce($_GET['_wpnonce'], 'delete_template_' . $_GET['id'])) {
                $this->delete_template((int) $_GET['id']);
            }
        }

        if (isset($_GET['action']) && $_GET['action'] === 'clone_template' && isset($_GET['id'])) {
            if (wp_verify_nonce($_GET['_wpnonce'], 'clone_template_' . $_GET['id'])) {
                $this->clone_template((int) $_GET['id']);
            }
        }

        // Handle vendor list actions
        if (isset($_POST['focmp_save_vendor_list']) && wp_verify_nonce($_POST['_wpnonce'], 'focmp_vendor_list')) {
            $this->save_vendor_list();
        }

        if (isset($_GET['action']) && $_GET['action'] === 'delete_vendor_list' && isset($_GET['id'])) {
            if (wp_verify_nonce($_GET['_wpnonce'], 'delete_vendor_list_' . $_GET['id'])) {
                $this->delete_vendor_list((int) $_GET['id']);
            }
        }

        if (isset($_GET['action']) && $_GET['action'] === 'clone_vendor_list' && isset($_GET['id'])) {
            if (wp_verify_nonce($_GET['_wpnonce'], 'clone_vendor_list_' . $_GET['id'])) {
                $this->clone_vendor_list((int) $_GET['id']);
            }
        }

        // Handle custom vendor actions
        if (isset($_POST['focmp_save_vendor_custom']) && wp_verify_nonce($_POST['_wpnonce'], 'focmp_vendor_custom')) {
            $this->save_vendor_custom();
        }

        if (isset($_GET['action']) && $_GET['action'] === 'delete_vendor_custom' && isset($_GET['id'])) {
            if (wp_verify_nonce($_GET['_wpnonce'], 'delete_vendor_custom_' . $_GET['id'])) {
                $this->delete_vendor_custom((int) $_GET['id']);
            }
        }

        // Handle settings save
        if (isset($_POST['focmp_save_settings']) && wp_verify_nonce($_POST['_wpnonce'], 'focmp_settings')) {
            $this->save_settings();
        }

        // Handle vendor enable/disable
        if (isset($_POST['focmp_toggle_vendor']) && wp_verify_nonce($_POST['_wpnonce'], 'focmp_toggle_vendor')) {
            $this->toggle_vendor();
        }
    }

    /**
     * Render dashboard page
     */
    public function render_dashboard() {
        global $wpdb;

        // Get stats
        $stats = array(
            'companies' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}cmp_companies"),
            'domains' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}cmp_domains"),
            'templates' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}cmp_templates"),
            'vendor_lists' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}cmp_vendor_lists"),
            'vendors_iab' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}cmp_vendors_iab"),
            'vendors_google' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}cmp_vendors_google"),
            'vendors_custom' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}cmp_vendors_custom"),
        );

        include FOCMP_PLUGIN_DIR . 'admin/views/dashboard.php';
    }

    /**
     * Render companies page
     */
    public function render_companies() {
        global $wpdb;

        $action = isset($_GET['action']) ? sanitize_text_field($_GET['action']) : 'list';
        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

        $company = null;
        if ($id && in_array($action, array('edit'))) {
            $company = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}cmp_companies WHERE id = %d",
                $id
            ));
        }

        $companies = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}cmp_companies ORDER BY name ASC");

        include FOCMP_PLUGIN_DIR . 'admin/views/companies.php';
    }

    /**
     * Render domains page
     */
    public function render_domains() {
        global $wpdb;

        $action = isset($_GET['action']) ? sanitize_text_field($_GET['action']) : 'list';
        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

        $domain = null;
        if ($id && in_array($action, array('edit'))) {
            $domain = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}cmp_domains WHERE id = %d",
                $id
            ));
        }

        $domains = $wpdb->get_results("
            SELECT d.*, c.name as company_name, tp.name AS prod_template_name, tt.name AS test_template_name,
                   r.created_at AS reconsent_at
            FROM {$wpdb->prefix}cmp_domains d
            LEFT JOIN {$wpdb->prefix}cmp_companies c ON d.company_id = c.id
            LEFT JOIN {$wpdb->prefix}cmp_templates tp ON d.prod_template_id = tp.id
            LEFT JOIN {$wpdb->prefix}cmp_templates tt ON d.test_template_id = tt.id
            LEFT JOIN {$wpdb->prefix}cmp_reconsents r ON d.current_reconsent_id = r.id
            ORDER BY d.domain ASC
        ");

        $companies = $wpdb->get_results("SELECT id, name FROM {$wpdb->prefix}cmp_companies ORDER BY name ASC");
        $templates = $wpdb->get_results("SELECT id, name, language FROM {$wpdb->prefix}cmp_templates ORDER BY name ASC");

        include FOCMP_PLUGIN_DIR . 'admin/views/domains.php';
    }

    /**
     * Render templates page
     */
    public function render_templates() {
        global $wpdb;

        $action = isset($_GET['action']) ? sanitize_text_field($_GET['action']) : 'list';
        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

        $template = null;
        if ($id && in_array($action, array('edit'))) {
            $template = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}cmp_templates WHERE id = %d",
                $id
            ));
        }

        $templates = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}cmp_templates ORDER BY name ASC");

        // Get default texts
        $default_texts = get_option('focmp_texts', array());

        // Get vendor lists for dropdown
        $vendor_lists = $wpdb->get_results("SELECT id, name FROM {$wpdb->prefix}cmp_vendor_lists ORDER BY name ASC");

        // Get global settings as fallback for template-level fields
        $settings = get_option('focmp_settings', array());

        include FOCMP_PLUGIN_DIR . 'admin/views/templates.php';
    }

    /**
     * Render IAB vendors page
     */
    public function render_vendors_iab() {
        global $wpdb;

        $vendors = $wpdb->get_results("
            SELECT * FROM {$wpdb->prefix}cmp_vendors_iab
            ORDER BY vendor_id ASC
        ");

        $settings = get_option('focmp_settings', array());

        // Query imported purpose languages
        $purpose_languages = $wpdb->get_results("
            SELECT language, COUNT(*) as purpose_count, MAX(updated_at) as last_updated
            FROM {$wpdb->prefix}cmp_purposes_iab
            GROUP BY language
            ORDER BY language
        ");

        // Query GVL metadata languages
        $metadata_languages = $wpdb->get_results("
            SELECT language, COUNT(*) as meta_count, MAX(updated_at) as last_updated
            FROM {$wpdb->prefix}cmp_gvl_metadata
            WHERE meta_key IN ('specialPurposes','features','specialFeatures','stacks','dataCategories')
            GROUP BY language
        ");

        // Merge into a single array keyed by language
        $imported_languages = array();
        foreach ($purpose_languages as $row) {
            $imported_languages[$row->language] = array(
                'purpose_count' => (int) $row->purpose_count,
                'meta_count' => 0,
                'last_updated' => $row->last_updated,
            );
        }
        foreach ($metadata_languages as $row) {
            if (isset($imported_languages[$row->language])) {
                $imported_languages[$row->language]['meta_count'] = (int) $row->meta_count;
                if ($row->last_updated > $imported_languages[$row->language]['last_updated']) {
                    $imported_languages[$row->language]['last_updated'] = $row->last_updated;
                }
            } else {
                $imported_languages[$row->language] = array(
                    'purpose_count' => 0,
                    'meta_count' => (int) $row->meta_count,
                    'last_updated' => $row->last_updated,
                );
            }
        }
        ksort($imported_languages);

        include FOCMP_PLUGIN_DIR . 'admin/views/vendors-iab.php';
    }

    /**
     * Render Google vendors page
     */
    public function render_vendors_google() {
        global $wpdb;

        $vendors = $wpdb->get_results("
            SELECT * FROM {$wpdb->prefix}cmp_vendors_google
            ORDER BY provider_id ASC
        ");

        $settings = get_option('focmp_settings', array());

        include FOCMP_PLUGIN_DIR . 'admin/views/vendors-google.php';
    }

    /**
     * Render custom vendors page
     */
    public function render_vendors_custom() {
        global $wpdb;

        $action = isset($_GET['action']) ? sanitize_text_field($_GET['action']) : 'list';
        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

        $vendor = null;
        if ($id && in_array($action, array('edit'))) {
            $vendor = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}cmp_vendors_custom WHERE id = %d",
                $id
            ));
        }

        $vendors = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}cmp_vendors_custom ORDER BY name ASC");

        include FOCMP_PLUGIN_DIR . 'admin/views/vendors-custom.php';
    }

    /**
     * Render reconsents page
     */
    public function render_reconsents() {
        global $wpdb;

        $reconsents = $wpdb->get_results("
            SELECT r.*, d.domain, (d.current_reconsent_id = r.id) AS is_current
            FROM {$wpdb->prefix}cmp_reconsents r
            LEFT JOIN {$wpdb->prefix}cmp_domains d ON r.domain_id = d.id
            ORDER BY r.created_at DESC
        ");

        $domains = $wpdb->get_results("SELECT id, domain FROM {$wpdb->prefix}cmp_domains ORDER BY domain ASC");

        include FOCMP_PLUGIN_DIR . 'admin/views/reconsents.php';
    }

    /**
     * Render settings page
     */
    public function render_settings() {
        $settings = get_option('focmp_settings', array());

        include FOCMP_PLUGIN_DIR . 'admin/views/settings.php';
    }
}
