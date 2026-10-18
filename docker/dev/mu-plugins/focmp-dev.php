<?php
/**
 * Plugin Name: Fast Open CMP — dev helpers (LOCAL DEV ONLY)
 * Description: Stops WordPress from phoning home to api.wordpress.org on admin
 *   requests. The core/plugin/theme update checks otherwise run inline on
 *   admin_init and stall random admin pages by ~1–1.5s, because the wp-cron
 *   loopback target (siteurl = http://localhost:8089) is unreachable from inside
 *   the container, so background cron never refreshes the update transients.
 *   Mounted into wp-content/mu-plugins by docker-compose.yml. NOT for production
 *   (the production overlay handles cron properly instead).
 *
 * @package fast-open-cmp-dev
 */

if (!defined('ABSPATH')) {
    exit;
}

// The pseudo-cron loopback can't reach localhost:8089 from the container, so it
// only ever fails fast and never runs scheduled tasks — turn it off explicitly.
if (!defined('DISABLE_WP_CRON')) {
    define('DISABLE_WP_CRON', true);
}

// Drop the blocking update checks that fire on every admin page via admin_init.
// (default-filters.php has already registered these by the time mu-plugins load.)
// The Updates screen / Plugins screen still check on demand when you open them.
remove_action('admin_init', '_maybe_update_core');
remove_action('admin_init', '_maybe_update_plugins');
remove_action('admin_init', '_maybe_update_themes');
