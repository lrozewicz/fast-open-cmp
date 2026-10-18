<?php
/**
 * Consent log (proof of consent, GDPR Art. 7(1)).
 *
 * The SDK posts one record per decision (fetch keepalive, text/plain JSON,
 * so no CORS preflight). Records are pseudonymous: a random consent id kept in the
 * visitor's cookie, the anonymised IP (last IPv4 octet / beyond /48 for IPv6
 * dropped), the user agent and the page URL without its query string.
 * Composed into FOCMP_API via `use`.
 */

if (!defined('ABSPATH')) {
    exit;
}

trait FOCMP_API_ConsentLog {

    private static $consent_actions = array('accept-all', 'reject-all', 'save', 'close-reject');
    private static $consent_modes = array('tcf_gcm', 'gcm_only', 'basic');

    /**
     * Records per consent id and minute before the endpoint answers 429. Limiting
     * by IP would drop most records behind a CDN, reverse proxy or carrier NAT,
     * where many visitors share one address. Volumetric abuse belongs to the
     * CDN/WAF in front of WordPress (see docs/production.md).
     */
    const CONSENT_RATE_LIMIT = 20;

    private function register_consent_log_route() {
        register_rest_route(self::NAMESPACE, '/consent', array(
            'methods' => 'POST',
            'callback' => array($this, 'log_consent'),
            'permission_callback' => '__return_true',
        ));
    }

    /**
     * Whether consent logging is enabled (Settings, default on).
     */
    public static function consent_log_enabled() {
        $settings = get_option('focmp_settings', array());
        return !isset($settings['consent_log']) || !empty($settings['consent_log']);
    }

    /**
     * POST /consent — store one consent decision.
     */
    public function log_consent($request) {
        if (!self::consent_log_enabled()) {
            return $this->consent_response(array('logged' => false), 202);
        }

        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';

        $data = json_decode((string) $request->get_body(), true);
        $record = is_array($data) ? $this->sanitize_consent_record($data) : null;
        if (!$record) {
            return $this->consent_response(array('error' => 'invalid_payload'), 400);
        }

        global $wpdb;
        $log_table = $wpdb->prefix . 'cmp_consent_log';

        // Per-visitor limit (indexed on consent_id, created_at).
        $limit = (int) apply_filters('focmp_consent_log_rate_limit', self::CONSENT_RATE_LIMIT);
        $recent = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $log_table WHERE consent_id = %s AND created_at > (UTC_TIMESTAMP() - INTERVAL 1 MINUTE)",
            $record['consent_id']
        ));
        if ($limit > 0 && $recent >= $limit) {
            return $this->consent_response(array('error' => 'rate_limited'), 429);
        }
        $domain = FOCMP_Domain_Access::find_domain($record['domain_key'] ?: $record['domain_id']);
        if (!$domain) {
            return $this->consent_response(array('error' => 'unknown_domain'), 404);
        }
        // Other sites must not fill this domain's log and statistics.
        if (!FOCMP_Domain_Access::request_allowed($domain)) {
            return $this->consent_response(array('error' => 'origin_not_allowed'), 403);
        }
        $record['domain_id'] = (int) $domain->id;
        unset($record['domain_key']);
        $page_host = $record['page_url'] ? wp_parse_url($record['page_url'], PHP_URL_HOST) : null;
        if ($page_host && !FOCMP_Domain_Access::request_allowed_host($domain, $page_host)) {
            $record['page_url'] = null;
        }

        // The template is resolved here, not taken from the client.
        $record['template_id'] = (int) ($record['env'] === 'test' ? $domain->test_template_id : $domain->prod_template_id) ?: null;
        $record['created_at'] = current_time('mysql', true);
        $record['ip_anon'] = self::anonymize_ip($ip);
        $record['user_agent'] = isset($_SERVER['HTTP_USER_AGENT'])
            ? substr(sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])), 0, 255)
            : null;

        // The consent strings go to the deduplicated payload table.
        $record['payload_id'] = self::store_payload($record);
        foreach (array('tc_string', 'ac_string', 'custom_consents', 'custom_vendors') as $column) {
            unset($record[$column]);
        }

        // Idempotent: the SDK retries unsent records with the same record_id.
        if (!self::insert_ignore($log_table, $record)) {
            return $this->consent_response(array('logged' => true, 'duplicate' => true), 200);
        }

        // Spread over 16 counter rows: one row per day/domain/action would be a
        // hot spot every concurrent request waits for. Readers SUM the slots.
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$wpdb->prefix}cmp_consent_stats (day, domain_id, action, slot, total)
             VALUES (UTC_DATE(), %d, %s, %d, 1) ON DUPLICATE KEY UPDATE total = total + 1",
            $record['domain_id'],
            $record['action'],
            wp_rand(0, 15)
        ));

        return $this->consent_response(array('logged' => true), 201);
    }

    /**
     * Removes the per-visitor timestamp section ("t:…") from a compact consent
     * cookie value, so identical choices produce identical payloads.
     */
    public static function strip_compact_timestamp($value) {
        if ($value === null || $value === '') {
            return null;
        }
        $sections = array_filter(explode('|', (string) $value), function ($section) {
            return strpos($section, 't:') !== 0;
        });
        return implode('|', $sections);
    }

    /**
     * Content hash of a payload (TC String, AC string, custom consents, custom vendors).
     */
    public static function payload_hash($tc, $ac, $consents, $vendors) {
        return hash('sha256', implode("\n", array((string) $tc, (string) $ac, (string) $consents, (string) $vendors)));
    }

    /**
     * Stores the consent strings once and returns their id (null when empty).
     */
    public static function store_payload(array $record) {
        global $wpdb;
        $tc = $record['tc_string'] ?? null;
        $ac = $record['ac_string'] ?? null;
        $consents = self::strip_compact_timestamp($record['custom_consents'] ?? null);
        $vendors = self::strip_compact_timestamp($record['custom_vendors'] ?? null);
        if ($tc === null && $ac === null && $consents === null && $vendors === null) {
            return null;
        }

        $table = $wpdb->prefix . 'cmp_consent_payloads';
        $hash = self::payload_hash($tc, $ac, $consents, $vendors);

        // Common case: the payload exists — a plain read takes no lock (an upsert
        // would lock the same hot row for every concurrent "accept all").
        $id = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE hash = %s", $hash));
        if ($id) {
            return (int) $id;
        }

        $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO $table (hash, tc_string, ac_string, custom_consents, custom_vendors, created_at)
             VALUES (%s, NULLIF(%s, ''), NULLIF(%s, ''), NULLIF(%s, ''), NULLIF(%s, ''), UTC_TIMESTAMP())",
            $hash,
            (string) $tc,
            (string) $ac,
            (string) $consents,
            (string) $vendors
        ));
        $id = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE hash = %s", $hash));
        return $id ? (int) $id : null;
    }

    /**
     * INSERT IGNORE of one row; returns false when a unique key already existed.
     */
    public static function insert_ignore($table, array $row) {
        global $wpdb;
        $columns = array();
        $values = array();
        foreach ($row as $column => $value) {
            $columns[] = '`' . preg_replace('/[^a-z_]/', '', $column) . '`';
            if ($value === null) {
                $values[] = 'NULL';
            } elseif (is_int($value)) {
                $values[] = $wpdb->prepare('%d', $value);
            } else {
                $values[] = $wpdb->prepare('%s', $value);
            }
        }
        // Values are prepared one by one above; column names are whitelisted.
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $affected = $wpdb->query("INSERT IGNORE INTO $table (" . implode(',', $columns) . ') VALUES (' . implode(',', $values) . ')');
        return (int) $affected === 1;
    }

    /**
     * Whitelists the client payload. Returns null when a required field is invalid.
     */
    private function sanitize_consent_record(array $d) {
        $consent_id = isset($d['consentId']) ? strtolower((string) $d['consentId']) : '';
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $consent_id)) {
            return null;
        }
        $action = (string) ($d['action'] ?? '');
        $mode = (string) ($d['mode'] ?? '');
        $domain_id = absint($d['domainId'] ?? 0);
        $domain_key = FOCMP_Domain_Access::is_key($d['domainKey'] ?? null) ? $d['domainKey'] : '';
        if (!in_array($action, self::$consent_actions, true) || !in_array($mode, self::$consent_modes, true) || (!$domain_id && !$domain_key)) {
            return null;
        }

        $pattern = function ($value, $regex, $max) {
            $value = (string) $value;
            return ($value !== '' && strlen($value) <= $max && preg_match($regex, $value)) ? $value : null;
        };
        $int = function ($value, $max) {
            $value = (int) $value;
            return ($value > 0 && $value <= $max) ? $value : null;
        };

        $record_id = isset($d['recordId']) ? strtolower((string) $d['recordId']) : '';

        return array(
            'consent_id' => $consent_id,
            'record_id' => preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $record_id) ? $record_id : null,
            'domain_id' => $domain_id,
            'domain_key' => $domain_key,
            'action' => $action,
            'mode' => $mode,
            'env' => ($d['env'] ?? '') === 'test' ? 'test' : 'prod',
            'policy_version' => $int($d['policyVersion'] ?? 0, 65535),
            'gvl_version' => $int($d['gvlVersion'] ?? 0, 4294967295),
            'cmp_version' => $int($d['cmpVersion'] ?? 0, 4095),
            'reconsent_id' => $int($d['reconsentId'] ?? 0, PHP_INT_MAX),
            'tc_string' => $pattern($d['tcString'] ?? '', '/^[A-Za-z0-9_.-]+$/', 20000),
            'ac_string' => $pattern($d['acString'] ?? '', '/^[0-9~.dv]+$/', 20000),
            'custom_consents' => $pattern($d['customConsents'] ?? '', '/^[A-Za-z0-9_.:|-]+$/', 1000),
            'custom_vendors' => $pattern($d['customVendors'] ?? '', '/^[A-Za-z0-9_.:|-]+$/', 20000),
            'page_url' => self::strip_url_query($d['pageUrl'] ?? ''),
        );
    }

    /**
     * Keeps scheme, host and path only (queries and fragments may carry personal data).
     */
    public static function strip_url_query($url) {
        $parts = wp_parse_url((string) $url);
        if (empty($parts['host']) || !in_array($parts['scheme'] ?? '', array('http', 'https'), true)) {
            return null;
        }
        return substr(esc_url_raw($parts['scheme'] . '://' . $parts['host'] . ($parts['path'] ?? '/')), 0, 500);
    }

    /**
     * 203.0.113.42 → 203.0.113.0 ; 2001:db8:85a3:8d3::1 → 2001:db8:85a3::
     */
    public static function anonymize_ip($ip) {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return preg_replace('/\.\d+$/', '.0', $ip);
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $packed = inet_pton($ip);
            return inet_ntop(substr($packed, 0, 6) . str_repeat("\0", 10));
        }
        return null;
    }

    private function consent_response(array $body, $status) {
        $response = new WP_REST_Response($body, $status);
        $response->header('Access-Control-Allow-Origin', '*');
        $response->header('Cache-Control', 'no-store');
        return $response;
    }
}
