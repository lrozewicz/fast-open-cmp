<?php
/**
 * Which domain a public request belongs to, and whether the page that made it
 * may use that domain.
 *
 * Snippets identify a domain by its random public key (`?key=…`). The numeric id
 * of older snippets keeps working until "Accept numeric domain IDs" is switched
 * off in the settings; sequential ids let anyone enumerate the configured sites.
 *
 * The origin check compares the Origin header (fetch/XHR) or the Referer (the
 * <script> request) with the domain's host: the host itself, www and any other
 * subdomain match. Requests without either header are let through: such a page
 * cannot be identified (strict Referrer-Policy, non-browser clients), and the
 * headers are trivial to forge outside a browser anyway. The check stops other
 * sites from embedding a client's snippet: using its configuration and filling
 * its consent log and statistics.
 */

if (!defined('ABSPATH')) {
    exit;
}

class FOCMP_Domain_Access {

    const KEY_LENGTH = 20;

    /**
     * New random public key: letters and digits, never all digits (numeric
     * identifiers are domain ids).
     */
    public static function generate_key() {
        $alphabet = 'abcdefghijkmnopqrstuvwxyz23456789';
        do {
            $key = '';
            for ($i = 0; $i < self::KEY_LENGTH; $i++) {
                $key .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
        } while (ctype_digit($key));
        return $key;
    }

    public static function is_key($value) {
        return is_string($value) && (bool) preg_match('/^[a-z0-9]{' . self::KEY_LENGTH . '}$/', $value) && !ctype_digit($value);
    }

    /**
     * Host part of whatever an admin typed as the domain ("https://www.Example.pl/",
     * "example.pl:443"), lowercase, without the "www." prefix.
     */
    public static function normalize_host($value) {
        $value = strtolower(trim((string) $value));
        if ($value === '') {
            return '';
        }
        if (strpos($value, '//') === false) {
            $value = 'http://' . $value;
        }
        $host = (string) parse_url($value, PHP_URL_HOST);
        $host = rtrim($host, '.');
        return strpos($host, 'www.') === 0 ? substr($host, 4) : $host;
    }

    /**
     * Whether $host is the domain, or a subdomain of it.
     */
    public static function host_matches($host, $domain) {
        $host = self::normalize_host($host);
        $domain = self::normalize_host($domain);
        if ($host === '' || $domain === '') {
            return false;
        }
        return $host === $domain || substr($host, -strlen($domain) - 1) === '.' . $domain;
    }

    /**
     * Host of the page behind the request: Origin, else Referer. Null when neither
     * is present ("null" origins of sandboxed frames count as absent).
     *
     * @param array $server $_SERVER-like array.
     */
    public static function request_host(array $server) {
        foreach (array('HTTP_ORIGIN', 'HTTP_REFERER') as $header) {
            $value = isset($server[$header]) ? trim((string) $server[$header]) : '';
            if ($value === '' || $value === 'null') {
                continue;
            }
            $host = parse_url($value, PHP_URL_HOST);
            if (is_string($host) && $host !== '') {
                return strtolower($host);
            }
        }
        return null;
    }

    /**
     * Whether a page on $host may use $domain. The CMP's own site (admin preview,
     * test pages) and the hosts added through the `focmp_allowed_hosts` filter are
     * always allowed.
     *
     * @param string|null $host   From request_host(); null = unknown → allowed.
     * @param string      $domain The domain's stored name.
     * @param string[]    $extra  Additional allowed domains.
     */
    public static function host_allowed($host, $domain, array $extra = array()) {
        if ($host === null) {
            return true;
        }
        foreach (array_merge(array($domain), $extra) as $allowed) {
            if (self::host_matches($host, $allowed)) {
                return true;
            }
        }
        return false;
    }

    /**
     * URL of the SDK for a domain row: by public key, by id only for a domain
     * without one (not migrated yet).
     */
    public static function snippet_url($domain) {
        $sdk = rest_url('fast-open-cmp/v1/sdk');
        return !empty($domain->public_key)
            ? add_query_arg('key', $domain->public_key, $sdk)
            : add_query_arg('domain_id', (int) $domain->id, $sdk);
    }

    public static function settings() {
        $settings = get_option('focmp_settings', array());
        return array(
            'origin_check' => !isset($settings['origin_check']) || !empty($settings['origin_check']),
            'legacy_ids' => !isset($settings['legacy_domain_ids']) || !empty($settings['legacy_domain_ids']),
        );
    }

    /**
     * Domain row for a public key or (while accepted) a numeric id; null otherwise.
     *
     * @param string|int $identifier
     */
    public static function find_domain($identifier) {
        global $wpdb;
        $table = $wpdb->prefix . 'cmp_domains';
        $identifier = is_string($identifier) ? trim($identifier) : $identifier;

        if (self::is_key($identifier)) {
            return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE public_key = %s", $identifier)) ?: null;
        }
        if ((is_int($identifier) || (is_string($identifier) && ctype_digit($identifier))) && (int) $identifier > 0) {
            if (!self::settings()['legacy_ids']) {
                return null;
            }
            return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", (int) $identifier)) ?: null;
        }
        return null;
    }

    /**
     * Whether the current request's page may use the domain (setting + filter).
     *
     * @param object $domain Row of cmp_domains.
     */
    public static function request_allowed($domain) {
        return self::request_allowed_host($domain, self::request_host($_SERVER));
    }

    /**
     * Whether a page on $host may use the domain (setting + filter).
     *
     * @param object      $domain Row of cmp_domains.
     * @param string|null $host   Null = unknown → allowed.
     */
    public static function request_allowed_host($domain, $host) {
        if (!self::settings()['origin_check']) {
            return true;
        }
        $extra = array(wp_parse_url(home_url(), PHP_URL_HOST));
        /**
         * Additional hosts (with their subdomains) allowed to use a domain's
         * configuration, e.g. a second brand domain or a staging host.
         *
         * @param string[] $hosts
         * @param object   $domain Row of cmp_domains.
         */
        $extra = (array) apply_filters('focmp_allowed_hosts', $extra, $domain);
        return self::host_allowed($host, (string) $domain->domain, array_filter($extra, 'is_string'));
    }

    /**
     * 403 for a page that may not use the domain. Not cacheable, so a CDN does not
     * hand it to the legitimate site.
     */
    public static function forbidden() {
        $response = new WP_REST_Response(array(
            'code' => 'origin_not_allowed',
            'message' => 'This page is not allowed to use this CMP domain.',
        ), 403);
        $response->header('Cache-Control', 'no-store');
        return $response;
    }
}
