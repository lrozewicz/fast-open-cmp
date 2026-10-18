<?php
/**
 * REST routing and endpoint handlers (config, vendor-list, purposes, SDK).
 * Split out of class-api.php (todo §3). Composed into FOCMP_API via `use`.
 */

if (!defined('ABSPATH')) {
    exit;
}

trait FOCMP_API_Endpoints {
    /**
     * Initialize API
     */
    public function init() {
        add_action('rest_api_init', array($this, 'register_routes'));
        // After core's rest_send_cors_headers (priority 10): the body is sent here.
        add_filter('rest_pre_serve_request', array($this, 'serve_json'), 100, 4);
        $this->cache = FOCMP_Cache::get_instance();
    }

    /**
     * Register REST API routes
     */
    public function register_routes() {
        // CMP Config endpoint
        register_rest_route(self::NAMESPACE, '/cmp-config', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_cmp_config'),
            'permission_callback' => '__return_true',
            'args' => array(
                'domain_id' => array(
                    'required' => false,
                    'type' => 'integer',
                    'sanitize_callback' => 'absint',
                ),
                'domainId' => array(
                    'required' => false,
                    'type' => 'integer',
                    'sanitize_callback' => 'absint',
                ),
                'domain' => array(
                    'required' => false,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'key' => array(
                    'required' => false,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_key',
                ),
                'env' => array(
                    'required' => false,
                    'type' => 'string',
                    'default' => 'prod',
                    'enum' => array('prod', 'test'),
                ),
            ),
        ));

        // Vendor List endpoint
        register_rest_route(self::NAMESPACE, '/vendor-list', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_vendor_list'),
            'permission_callback' => '__return_true',
            'args' => array(
                'lang' => array(
                    'required' => false,
                    'type' => 'string',
                    'default' => 'pl',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'stacks' => array(
                    'required' => false,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
            ),
        ));

        // GVL vendor-list.json endpoint (for @iabtcf/core GVL library)
        // Pattern: /vendor-list/vendor-list.json
        register_rest_route(self::NAMESPACE, '/vendor-list/vendor-list\.json', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_vendor_list'),
            'permission_callback' => '__return_true',
            'args' => array(
                'lang' => array(
                    'required' => false,
                    'type' => 'string',
                    'default' => 'en',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
            ),
        ));

        // GVL language file endpoint (for @iabtcf/core GVL.changeLanguage)
        // Pattern: /vendor-list/purposes-{lang}.json
        register_rest_route(self::NAMESPACE, '/vendor-list/purposes-(?P<lang>[a-z]{2})\.json', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_purposes'),
            'permission_callback' => '__return_true',
            'args' => array(
                'lang' => array(
                    'required' => true,
                    'type' => 'string',
                    'validate_callback' => function($param) {
                        return preg_match('/^[a-z]{2}$/', $param);
                    },
                ),
            ),
        ));

        // Purposes by language endpoint (with and without .json extension)
        register_rest_route(self::NAMESPACE, '/purposes-(?P<lang>[a-z]{2})(?:\.json)?', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_purposes'),
            'permission_callback' => '__return_true',
            'args' => array(
                'lang' => array(
                    'required' => true,
                    'type' => 'string',
                    'validate_callback' => function($param) {
                        return preg_match('/^[a-z]{2}$/', $param);
                    },
                ),
            ),
        ));

        // SDK JavaScript endpoint (bez .js w URL - WordPress ma z tym problem)
        register_rest_route(self::NAMESPACE, '/sdk', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_sdk_js'),
            'permission_callback' => '__return_true',
            'args' => array(
                // Public key of the domain; domain_id = numeric id of older snippets.
                'key' => array(
                    'required' => false,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_key',
                ),
                'domain_id' => array(
                    'required' => false,
                    'type' => 'integer',
                    'sanitize_callback' => 'absint',
                ),
                'env' => array(
                    'required' => false,
                    'type' => 'string',
                    'default' => 'prod',
                    'enum' => array('prod', 'test'),
                ),
            ),
        ));

        // Consent log (POST /consent)
        $this->register_consent_log_route();
    }

    /**
     * Serves the plugin's public GET responses (config ~70 KB, vendor list ~750 KB)
     * gzip-compressed and with an ETag. Web servers often compress JavaScript but
     * not JSON; a body that already carries Content-Encoding is left alone by
     * Apache mod_deflate and nginx gzip, so nothing is compressed twice. A
     * matching If-None-Match gets a body-less 304 once max-age has run out.
     *
     * Hooked to rest_pre_serve_request; true = response sent here.
     */
    public function serve_json($served, $result, $request, $server) {
        if ($served
            || $request->get_method() !== 'GET'
            || strpos($request->get_route(), '/' . self::NAMESPACE . '/') !== 0
            || $result->get_status() !== 200
            || headers_sent()
            || isset($_GET['_jsonp']) || isset($_GET['_envelope']) || isset($_GET['_pretty']) || isset($_GET['_embed'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return $served;
        }
        $body = wp_json_encode($server->response_to_data($result, false));
        if (!is_string($body)) {
            return $served;
        }

        $etag = '"' . substr(md5($body), 0, 24) . '"';
        header('ETag: ' . $etag);
        header('Vary: Accept-Encoding', false);
        $if_none_match = isset($_SERVER['HTTP_IF_NONE_MATCH']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_IF_NONE_MATCH'])) : '';
        if (self::etag_matches($if_none_match, $etag)) {
            status_header(304);
            return true;
        }

        $accept = isset($_SERVER['HTTP_ACCEPT_ENCODING']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_ACCEPT_ENCODING'])) : '';
        /** Set to false when the web server or CDN compresses JSON itself. */
        $compress = apply_filters('focmp_compress_responses', true)
            && strlen($body) > 1024
            && function_exists('gzencode')
            && !ini_get('zlib.output_compression')
            && self::accepts_gzip($accept);
        if ($compress) {
            $gzipped = gzencode($body, 6);
            if ($gzipped !== false) {
                $body = $gzipped;
                header('Content-Encoding: gzip');
            }
        }
        header('Content-Length: ' . strlen($body));
        echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON (or its gzip) of our own response
        return true;
    }

    /**
     * Whether an Accept-Encoding header allows gzip (q=0 forbids it).
     */
    public static function accepts_gzip($header) {
        foreach (explode(',', strtolower((string) $header)) as $part) {
            $pieces = array_map('trim', explode(';', $part));
            if ($pieces[0] !== 'gzip' && $pieces[0] !== '*') {
                continue;
            }
            foreach (array_slice($pieces, 1) as $param) {
                if (preg_match('/^q=0(\.0*)?$/', str_replace(' ', '', $param))) {
                    return false;
                }
            }
            return true;
        }
        return false;
    }

    /**
     * If-None-Match against our ETag ("*", lists and weak validators included).
     */
    public static function etag_matches($header, $etag) {
        $header = trim((string) $header);
        if ($header === '') {
            return false;
        }
        if ($header === '*') {
            return true;
        }
        foreach (explode(',', $header) as $candidate) {
            $candidate = trim($candidate);
            if (stripos($candidate, 'W/') === 0) {
                $candidate = substr($candidate, 2);
            }
            // Proxies that compress on their own append a suffix ("…-gzip").
            if ($candidate === $etag || preg_replace('/-(gzip|br|deflate)"$/', '"', $candidate) === $etag) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get CMP configuration
     */
    public function get_cmp_config($request) {
        $env = $request->get_param('env') ?: 'prod';

        // Public key; older snippets send the numeric id, the standalone SDK only
        // the host name (both only while numeric ids are accepted).
        $identifier = $request->get_param('key') ?: ($request->get_param('domain_id') ?: $request->get_param('domainId'));
        if (!$identifier && $request->get_param('domain') && FOCMP_Domain_Access::settings()['legacy_ids']) {
            $identifier = (int) $this->get_domain_id_by_name($request->get_param('domain'));
        }
        if (!$identifier) {
            return new WP_Error('missing_domain', 'Domain key is required', array('status' => 400));
        }
        $domain = FOCMP_Domain_Access::find_domain($identifier);
        if (!$domain) {
            return new WP_Error('domain_not_found', 'Domain not found', array('status' => 404));
        }
        if (!FOCMP_Domain_Access::request_allowed($domain)) {
            return FOCMP_Domain_Access::forbidden();
        }
        $domain_id = (int) $domain->id;

        // The detected front-end language (Polylang/WPML) changes the config
        // (template/texts/GVL), so it must be part of the cache key — otherwise a
        // PL config could be served to an EN page and vice versa. No suffix when
        // no plugin reports a language (cache key unchanged on the default path).
        $detected_lang = apply_filters('focmp_detect_language', null);
        $cache_key = $this->cache->get_config_key($domain_id, $env);
        if ($detected_lang) {
            $cache_key .= '_' . sanitize_key($detected_lang);
        }
        $cached = $this->cache->get($cache_key);

        if ($cached !== false) {
            return $this->vary_on_origin($this->add_cache_headers(new WP_REST_Response($cached, 200)));
        }

        // Build config
        $config = $this->build_config($domain_id, $env);

        if (is_wp_error($config)) {
            return $config;
        }

        // Cache the result
        $this->cache->set($cache_key, $config);

        return $this->vary_on_origin($this->add_cache_headers(new WP_REST_Response($config, 200)));
    }

    /**
     * The origin check decides between 200 and 403, so shared caches must not
     * hand one origin's response to another.
     */
    private function vary_on_origin($response) {
        $response->header('Vary', 'Origin');
        return $response;
    }

    /**
     * Language for the vendor list: an imported translation, or English.
     */
    private function normalize_gvl_language($lang) {
        global $wpdb;
        static $available = null;

        $lang = strtolower((string) $lang);
        if (!preg_match('/^[a-z]{2}(-[a-z]{2,4})?$/', $lang)) {
            return 'en';
        }
        if ($available === null) {
            $available = $wpdb->get_col("SELECT DISTINCT language FROM {$wpdb->prefix}cmp_purposes_iab");
            $available = array_merge(array('en', 'pl'), array_map('strtolower', (array) $available));
        }
        return in_array($lang, $available, true) ? $lang : 'en';
    }

    /**
     * Canonical stacks list: unique ids 1-999, sorted, at most 30.
     */
    private function normalize_stacks_param($stacks) {
        $ids = array();
        foreach (explode(',', (string) $stacks) as $id) {
            $id = (int) trim($id);
            if ($id >= 1 && $id <= 999) {
                $ids[$id] = $id;
            }
        }
        sort($ids);
        return implode(',', array_slice($ids, 0, 30));
    }

    /**
     * Get vendor list (GVL format)
     */
    public function get_vendor_list($request) {
        // Public, unauthenticated endpoint: normalise the inputs that form the cache
        // key, otherwise arbitrary values create unbounded ~1 MB cache entries.
        $lang = $this->normalize_gvl_language($request->get_param('lang') ?: 'pl');
        $request->set_param('lang', $lang);
        $stacks_param = $this->normalize_stacks_param($request->get_param('stacks') ?: '');
        $request->set_param('stacks', $stacks_param);

        // Check cache (per language + stacks)
        $cache_key = 'vendor_list_' . $lang . ($stacks_param ? '_s' . md5($stacks_param) : '');
        $cached = $this->cache->get($cache_key);

        if ($cached !== false) {
            return $this->add_cache_headers(new WP_REST_Response($cached, 200));
        }

        // Build vendor list with translated purposes
        $vendor_list = $this->build_vendor_list($lang, $request);

        // Cache the result
        $this->cache->set($cache_key, $vendor_list);

        return $this->add_cache_headers(new WP_REST_Response($vendor_list, 200));
    }

    /**
     * Get purposes by language
     */
    public function get_purposes($request) {
        $lang = $request->get_param('lang');

        // Check cache
        $cache_key = 'purposes_' . $lang;
        $cached = $this->cache->get($cache_key);

        if ($cached !== false) {
            return $this->add_cache_headers(new WP_REST_Response($cached, 200));
        }

        // Build purposes response
        $purposes = $this->build_purposes_response($lang);

        // Cache the result
        $this->cache->set($cache_key, $purposes);

        return $this->add_cache_headers(new WP_REST_Response($purposes, 200));
    }

    /**
     * Settings the SDK needs synchronously, before /cmp-config is fetched: whether
     * to emit the Google Consent Mode default (only when the domain's template uses
     * GCM — basic mode and disabled GCM must not leave Google tags denied forever)
     * and its wait_for_update / url_passthrough / ads_data_redaction options.
     */
    private function build_sdk_boot($domain, $env) {
        global $wpdb;

        $template_id = ($env === 'test') ? $domain->test_template_id : $domain->prod_template_id;
        $config = array();
        if ($template_id) {
            $json = $wpdb->get_var($wpdb->prepare(
                "SELECT config FROM {$wpdb->prefix}cmp_templates WHERE id = %d",
                $template_id
            ));
            $config = $json ? (json_decode($json, true) ?: array()) : array();
        }

        $settings = get_option('focmp_settings', array());
        $mode = $config['mode'] ?? 'tcf_gcm';
        $gcm = $config['googleConsentMode'] ?? null;
        $enabled = is_array($gcm) ? !empty($gcm['enabled']) : !empty($settings['gcm_enabled']);
        $wait = is_array($gcm) && isset($gcm['waitForUpdate'])
            ? (int) $gcm['waitForUpdate']
            : (int) ($settings['gcm_wait_for_update'] ?? 500);

        return array(
            'env' => $env === 'test' ? 'test' : 'prod',
            'cmpId' => FOCMP_TCF::cmp_id(),
            'cmpVersion' => FOCMP_TCF::cmp_version(),
            'gcm' => array(
                'enabled' => $enabled && in_array($mode, array('tcf_gcm', 'gcm_only'), true),
                'waitForUpdate' => max(0, $wait),
                'urlPassthrough' => is_array($gcm) && !empty($gcm['urlPassthrough']),
                'adsDataRedaction' => is_array($gcm) && !empty($gcm['adsDataRedaction']),
            ),
        );
    }

    /**
     * Get SDK JavaScript
     */
    public function get_sdk_js($request) {
        $key = $request->get_param('key');
        $env = $request->get_param('env') ?: 'prod';

        $domain = FOCMP_Domain_Access::find_domain($key ?: $request->get_param('domain_id'));
        if (!$domain) {
            return new WP_Error('domain_not_found', 'Domain not found', array('status' => 404));
        }
        // <script> requests carry no Origin; the Referer names the page. Refused as
        // JavaScript: a JSON body would be dropped by the browser's opaque-response
        // blocking (ERR_BLOCKED_BY_ORB) and the reason would never show.
        if (!FOCMP_Domain_Access::request_allowed($domain)) {
            status_header(403);
            header('Content-Type: application/javascript; charset=utf-8');
            header('Cache-Control: no-store');
            echo "/* Fast Open CMP: this page's host does not match the domain of this integration code (origin check). Check the domain name in CMP → Domains. */\n";
            exit;
        }
        // The page gets back the identifier it used: a key snippet never reveals
        // the sequential id.
        $identifier = FOCMP_Domain_Access::is_key($key) ? $key : (string) (int) $domain->id;

        // Read SDK file
        $sdk_path = FOCMP_PLUGIN_DIR . 'assets/js/cmp-sdk.js';

        if (!file_exists($sdk_path)) {
            return new WP_Error('sdk_not_found', 'SDK file not found', array('status' => 500));
        }

        $sdk_content = file_get_contents($sdk_path);

        // Get API URL
        $api_url = rest_url(self::NAMESPACE);

        // Replace URL placeholders
        $sdk_content = str_replace('{API_BASE_URL}', rtrim($api_url, '/'), $sdk_content);
        $sdk_content = str_replace('{GVL_URL}', rest_url(self::NAMESPACE . '/vendor-list'), $sdk_content);

        // Disable auto-init (we'll call init manually with correct params) and hand
        // the SDK what it needs before the config arrives (Consent Mode defaults).
        $boot = $this->build_sdk_boot($domain, $env);
        $disable_autoinit = "window.__autocmp = false;\nwindow.__focmpBoot = " . wp_json_encode($boot) . ";\n";

        // Inject configuration
        $init_code = sprintf(
            "\n;(function(){window.OpenCMP && window.OpenCMP.init('%s', '%s', '%s', '%s');})();",
            esc_js($domain->domain),
            esc_js($identifier),
            esc_js($api_url),
            $env === 'test' ? 'test' : 'prod'
        );

        $js_content = $disable_autoinit . $sdk_content . $init_code;

        // Output raw JavaScript (WP_REST_Response always JSON-encodes)
        header('Content-Type: application/javascript; charset=utf-8');
        // No "Vary: Referer": that would make the script uncacheable. Refusals are
        // no-store, so a shared cache only ever holds the (identical) allowed body.
        header('Cache-Control: public, max-age=60');
        // $sdk_content is our own built bundle; injected values are esc_js()'d /
        // cast to int. Served as application/javascript, so HTML escaping would
        // corrupt it — not an HTML output context.
        echo $js_content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        exit;
    }
}
