<?php
/**
 * PHPUnit bootstrap for plain-PHP unit tests of plugin classes.
 *
 * These tests do NOT load WordPress. They exercise pure/decision logic by
 * defining the ABSPATH guard constant and requiring the classes under test, then
 * driving them with a fake $wpdb. For WP-integration coverage a real WP test
 * suite (or Brain Monkey) would be needed — out of scope here.
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', sys_get_temp_dir() . '/');
}

define('FOCMP_VERSION', '1.2.0');
require_once dirname(__DIR__) . '/includes/class-tcf.php';
require_once dirname(__DIR__) . '/includes/class-tc-decoder.php';
require_once dirname(__DIR__) . '/includes/class-domain-access.php';
require_once dirname(__DIR__) . '/includes/class-scheduler.php';
require_once dirname(__DIR__) . '/includes/class-consent-transfer.php';
if (!defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}
require_once dirname(__DIR__) . '/includes/class-api.php';
require_once dirname(__DIR__) . '/includes/class-importer.php';
require_once dirname(__DIR__) . '/includes/class-admin.php';

// Minimal stand-ins for the WordPress sanitizers used by the pure helpers under test.
// Options store for tests: set $GLOBALS['focmp_test_options'][name] = value.
if (!function_exists('get_option')) {
    function get_option($name, $default = false) {
        return $GLOBALS['focmp_test_options'][$name] ?? $default;
    }
}
if (!function_exists('wp_clear_scheduled_hook')) {
    function wp_clear_scheduled_hook($hook) { return 0; }
}
if (!function_exists('delete_option')) {
    function delete_option($name) {
        $GLOBALS['focmp_deleted_options'][] = $name;
        unset($GLOBALS['focmp_test_options'][$name]);
        return true;
    }
}
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($value) { return trim(strip_tags((string) $value)); }
}
if (!function_exists('wp_parse_url')) {
    function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
}
if (!function_exists('esc_url_raw')) {
    function esc_url_raw($url) { return (string) $url; }
}
if (!function_exists('sanitize_textarea_field')) {
    function sanitize_textarea_field($value) { return trim(strip_tags((string) $value)); }
}

/**
 * Minimal fake $wpdb. Returns preset values for the single read each helper
 * runs and records write queries so SQL construction can be asserted, all
 * without a database.
 */
class FakeWpdb
{
    public string $prefix = 'wp_';
    public string $options = 'wp_options';
    public mixed $var = null;       // next get_var() result
    public array $col = [];         // next get_col() result
    public array $queries = [];     // captured query() calls

    public function prepare(string $query, ...$args): string
    {
        return $query;
    }

    public function get_var(string $query): mixed
    {
        return $this->var;
    }

    public function get_col(string $query): array
    {
        return $this->col;
    }

    public array $results = [];     // next get_results() result

    public function get_results(string $query, $output = null): array
    {
        return $this->results;
    }

    public function query(string $query): int
    {
        $this->queries[] = $query;
        return 1;
    }
}
