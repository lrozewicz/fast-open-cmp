<?php
/**
 * wp-config.php for the container image (see the root Dockerfile).
 *
 * Everything environment-specific comes from environment variables, so one image runs
 * in every environment. WordPress core and plugins are part of the image and read-only:
 * updates ship as a new image, not through the admin panel.
 *
 * Environment:
 *   DB_HOST, DB_PORT (3306), DB_NAME, DB_USER, DB_PASSWORD   database connection
 *   WORDPRESS_URL            public URL, e.g. https://cmp.example.com (sets WP_HOME / WP_SITEURL)
 *   WORDPRESS_ENV            production | staging | development (wp_get_environment_type)
 *   WORDPRESS_DEBUG          "1" logs PHP notices to stderr
 *
 * Authentication keys and salts are not defined here on purpose: WordPress then generates
 * random ones on first use and keeps them in the database (wp_salt()), so no secret has to be
 * shipped with the image.
 */

function focmp_env(string $name, ?string $default = null): ?string
{
    $value = getenv($name);
    return ($value === false || $value === '') ? $default : $value;
}

define('DB_NAME', focmp_env('DB_NAME', 'wordpress'));
define('DB_USER', focmp_env('DB_USER', 'wordpress'));
define('DB_PASSWORD', focmp_env('DB_PASSWORD', ''));
define('DB_HOST', focmp_env('DB_HOST', 'localhost') . ':' . focmp_env('DB_PORT', '3306'));
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');

$table_prefix = 'wp_';

// TLS is terminated by the ingress proxy, which is the only way into the pod.
if (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') {
    $_SERVER['HTTPS'] = 'on';
}

if ($url = focmp_env('WORDPRESS_URL')) {
    define('WP_HOME', rtrim($url, '/'));
    define('WP_SITEURL', rtrim($url, '/'));
    if (str_starts_with($url, 'https://')) {
        define('FORCE_SSL_ADMIN', true);
    }
}

define('WP_ENVIRONMENT_TYPE', focmp_env('WORDPRESS_ENV', 'production'));

// Read-only core and plugins: no installs, updates or file editing from the admin panel.
define('DISALLOW_FILE_MODS', true);
define('DISALLOW_FILE_EDIT', true);
define('AUTOMATIC_UPDATER_DISABLED', true);
define('WP_AUTO_UPDATE_CORE', false);

// Scheduled tasks run from a Kubernetes CronJob (wp-cron.php), not on visitor requests.
define('DISABLE_WP_CRON', true);

define('WP_POST_REVISIONS', 10);
define('WP_TEMP_DIR', '/tmp');

$debug = focmp_env('WORDPRESS_DEBUG') === '1';
define('WP_DEBUG', $debug);
define('WP_DEBUG_LOG', $debug ? '/dev/stderr' : false);
define('WP_DEBUG_DISPLAY', false);

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

require_once ABSPATH . 'wp-settings.php';
