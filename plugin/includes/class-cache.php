<?php
/**
 * Cache Management
 */

if (!defined('ABSPATH')) {
    exit;
}

class FOCMP_Cache {

    /**
     * Single instance
     */
    private static $instance = null;

    /**
     * Default TTL in seconds
     */
    private $default_ttl = 60;

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
        $settings = get_option('focmp_settings', array());
        $this->default_ttl = isset($settings['cache_ttl']) ? (int) $settings['cache_ttl'] : 60;
    }

    /**
     * Cache generation, part of every key. clear_all() bumps it, which invalidates
     * everything at once with any storage behind transients (wp_options or an
     * object cache such as Redis — deleting rows from wp_options alone does nothing
     * there). Old entries simply expire with their TTL.
     */
    private function generation() {
        return (int) get_option('focmp_cache_version', 0);
    }

    private function storage_key($key) {
        return 'focmp_' . $this->generation() . '_' . $key;
    }

    /**
     * Get cached value
     *
     * @param string $key Cache key
     * @return mixed|false Cached value or false
     */
    public function get($key) {
        return get_transient($this->storage_key($key));
    }

    /**
     * Set cached value
     *
     * @param string $key Cache key
     * @param mixed $value Value to cache
     * @param int|null $ttl Time to live in seconds
     * @return bool Success
     */
    public function set($key, $value, $ttl = null) {
        $ttl = $ttl ?? $this->default_ttl;
        return set_transient($this->storage_key($key), $value, $ttl);
    }

    /**
     * Delete cached value
     *
     * @param string $key Cache key
     * @return bool Success
     */
    public function delete($key) {
        return delete_transient($this->storage_key($key));
    }

    /**
     * Clear cache for specific domain.
     *
     * Config entries also exist per detected language (Polylang/WPML), so a
     * targeted delete would miss them; a new generation is reliable and cheap.
     *
     * @param int $domain_id Domain ID
     */
    public function clear_domain_cache($domain_id) {
        $this->clear_all();
    }

    /**
     * Clear vendor list cache (vendor lists are keyed by language and stacks).
     */
    public function clear_vendor_cache() {
        $this->clear_all();
    }

    /**
     * Clear all purposes cache
     */
    public function clear_purposes_cache() {
        $this->clear_all();
    }

    /**
     * Clear all plugin cache
     */
    public function clear_all() {
        global $wpdb;

        // New generation: every key changes (works with object caches too). The
        // SDK compares this value with its localStorage copy of the vendor list.
        $current = (int) get_option('focmp_cache_version', 0);
        update_option('focmp_cache_version', max(time(), $current + 1));

        // Housekeeping for the default storage: drop stale rows from wp_options.
        $wpdb->query(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE '\\_transient\\_focmp\\_%'
             OR option_name LIKE '\\_transient\\_timeout\\_focmp\\_%'"
        );
    }

    /**
     * Get cache key for config
     *
     * @param int $domain_id Domain ID
     * @param string $env Environment (prod/test)
     * @return string Cache key
     */
    public function get_config_key($domain_id, $env = 'prod') {
        return 'config_' . $domain_id . '_' . $env;
    }
}
