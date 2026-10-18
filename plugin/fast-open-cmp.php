<?php
/**
 * Plugin Name: Fast Open CMP
 * Plugin URI: https://github.com/lrozewicz/fast-open-cmp
 * Description: Consent Management Platform (CMP) compliant with IAB TCF 2.3 and Google Additional Consent
 * Version: 1.2.0
 * Author: Lucas Web Art
 * Author URI: https://lucaswebart.pl
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: fast-open-cmp
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Plugin constants
define('FOCMP_VERSION', '1.2.0');
define('FOCMP_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('FOCMP_PLUGIN_URL', plugin_dir_url(__FILE__));
define('FOCMP_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Allowed HTML for the publisher "head scripts" setting.
 *
 * Shared by the admin save handler (sanitize on input) and the frontend output
 * (sanitize on output) so the two never drift. This is a manage_options-only
 * feature, but we still wp_kses on both ends as defense in depth.
 *
 * @return array<string,array<string,bool>>
 */
function focmp_head_scripts_allowed_html() {
    return array(
        'script' => array(
            'src'         => true,
            'type'        => true,
            'async'       => true,
            'defer'       => true,
            'crossorigin' => true,
            'integrity'   => true,
            'nonce'       => true,
        ),
        'link' => array(
            'rel'         => true,
            'href'        => true,
            'type'        => true,
            'crossorigin' => true,
        ),
        'meta' => array(
            'name'      => true,
            'content'   => true,
            'http-equiv' => true,
        ),
    );
}

/**
 * Main plugin class
 */
class Fast_Open_CMP {

    /**
     * Single instance of the class
     */
    private static $instance = null;

    /**
     * Get singleton instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        $this->load_dependencies();
        $this->init_hooks();
    }

    /**
     * Load required files
     */
    private function load_dependencies() {
        // Core classes
        require_once FOCMP_PLUGIN_DIR . 'includes/class-activator.php';
        require_once FOCMP_PLUGIN_DIR . 'includes/class-deactivator.php';
        require_once FOCMP_PLUGIN_DIR . 'includes/class-tcf.php';
        require_once FOCMP_PLUGIN_DIR . 'includes/class-tc-decoder.php';
        require_once FOCMP_PLUGIN_DIR . 'includes/class-domain-access.php';
        require_once FOCMP_PLUGIN_DIR . 'includes/class-download-stream.php';
        require_once FOCMP_PLUGIN_DIR . 'includes/class-consent-transfer.php';
        require_once FOCMP_PLUGIN_DIR . 'includes/class-backup.php';
        require_once FOCMP_PLUGIN_DIR . 'includes/class-cache.php';
        require_once FOCMP_PLUGIN_DIR . 'includes/class-api.php';
        require_once FOCMP_PLUGIN_DIR . 'includes/class-importer.php';
        require_once FOCMP_PLUGIN_DIR . 'includes/class-scheduler.php';

        // Admin classes (only in admin)
        if (is_admin()) {
            require_once FOCMP_PLUGIN_DIR . 'includes/class-admin.php';
        }

        // Multilingual integrations (load the active one, if any)
        if (function_exists('pll_languages_list')) {
            require_once FOCMP_PLUGIN_DIR . 'includes/class-polylang.php';
        }
        if (defined('ICL_SITEPRESS_VERSION')) {
            require_once FOCMP_PLUGIN_DIR . 'includes/class-wpml.php';
        }
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Activation/Deactivation hooks
        register_activation_hook(__FILE__, array('FOCMP_Activator', 'activate'));
        register_deactivation_hook(__FILE__, array('FOCMP_Deactivator', 'deactivate'));

        // Initialize components after plugins are loaded
        add_action('plugins_loaded', array($this, 'init_components'));

        // Load text domain
        add_action('init', array($this, 'load_textdomain'));
    }

    /**
     * Initialize plugin components
     */
    public function init_components() {
        // Schema/data upgrades (not only on admin_init: the REST API may be the
        // first thing hit after an update).
        FOCMP_Activator::maybe_upgrade();

        // Initialize REST API
        $api = new FOCMP_API();
        $api->init();

        // Initialize Admin
        if (is_admin()) {
            $admin = new FOCMP_Admin();
            $admin->init();
        }

        // Initialize Cache
        $cache = FOCMP_Cache::get_instance();

        // Scheduled GVL updates (WP-Cron)
        (new FOCMP_Scheduler())->init();

        // Wire multilingual language detection (Polylang / WPML) when active, so
        // the consent box follows the current front-end language.
        if (class_exists('FOCMP_Polylang')) {
            (new FOCMP_Polylang())->init();
        }
        if (class_exists('FOCMP_WPML')) {
            (new FOCMP_WPML())->init();
        }

        // Head scripts injection (frontend only)
        if (!is_admin()) {
            add_action('wp_head', array($this, 'output_head_scripts'), 1);
        }
    }

    /**
     * Output custom head scripts on frontend pages
     * Priority 1 = very early in <head>
     */
    public function output_head_scripts() {
        $settings = get_option('focmp_settings', array());

        if (!empty($settings['head_scripts'])) {
            // Sanitized on save, but escape on output too (defense in depth) using
            // the same allowlist — wp_kses is the correct escaping function here.
            echo wp_kses($settings['head_scripts'], focmp_head_scripts_allowed_html()) . "\n";
        }
    }

    /**
     * Load plugin text domain
     */
    public function load_textdomain() {
        load_plugin_textdomain(
            'fast-open-cmp',
            false,
            dirname(FOCMP_PLUGIN_BASENAME) . '/languages/'
        );
    }
}

// Initialize the plugin
function focmp_init() {
    return Fast_Open_CMP::get_instance();
}

// Start the plugin
focmp_init();
