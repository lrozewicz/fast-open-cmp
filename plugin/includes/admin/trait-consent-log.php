<?php
/**
 * Consent log screen: search, 30-day statistics and CSV export.
 * Composed into FOCMP_Admin via `use`.
 */

if (!defined('ABSPATH')) {
    exit;
}

trait FOCMP_Admin_ConsentLog {

    private static $log_per_page = 50;

    /**
     * Filters from the query string, validated.
     */
    private function consent_log_filters() {
        $date = function ($key) {
            $value = isset($_GET[$key]) ? sanitize_text_field(wp_unslash($_GET[$key])) : '';
            return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : '';
        };
        $consent_id = isset($_GET['consent_id']) ? strtolower(sanitize_text_field(wp_unslash($_GET['consent_id']))) : '';
        $action = isset($_GET['decision']) ? sanitize_key(wp_unslash($_GET['decision'])) : '';
        return array(
            'consent_id' => preg_match('/^[0-9a-f-]{8,36}$/', $consent_id) ? $consent_id : '',
            'domain_id' => isset($_GET['domain_id']) ? absint($_GET['domain_id']) : 0,
            'action' => in_array($action, array('accept-all', 'reject-all', 'save', 'close-reject'), true) ? $action : '',
            'from' => $date('from'),
            'to' => $date('to'),
        );
    }

    /**
     * WHERE clause (prepared) for the filters.
     */
    private function consent_log_where(array $f) {
        global $wpdb;
        $sql = array('1=1');
        if ($f['consent_id'] !== '') {
            $sql[] = $wpdb->prepare('l.consent_id LIKE %s', $wpdb->esc_like($f['consent_id']) . '%');
        }
        if ($f['domain_id']) {
            $sql[] = $wpdb->prepare('l.domain_id = %d', $f['domain_id']);
        }
        if ($f['action'] !== '') {
            $sql[] = $wpdb->prepare('l.action = %s', $f['action']);
        }
        if ($f['from'] !== '') {
            $sql[] = $wpdb->prepare('l.created_at >= %s', $f['from'] . ' 00:00:00');
        }
        if ($f['to'] !== '') {
            $sql[] = $wpdb->prepare('l.created_at <= %s', $f['to'] . ' 23:59:59');
        }
        return implode(' AND ', $sql);
    }

    /**
     * WHERE clause on cmp_consent_stats (alias s) for the domain and date filters.
     */
    private function consent_stats_where(array $f) {
        global $wpdb;
        $sql = array('1=1');
        if ($f['domain_id']) {
            $sql[] = $wpdb->prepare('s.domain_id = %d', $f['domain_id']);
        }
        if ($f['action'] !== '') {
            $sql[] = $wpdb->prepare('s.action = %s', $f['action']);
        }
        if ($f['from'] !== '') {
            $sql[] = $wpdb->prepare('s.day >= %s', $f['from']);
        }
        if ($f['to'] !== '') {
            $sql[] = $wpdb->prepare('s.day <= %s', $f['to']);
        }
        return implode(' AND ', $sql);
    }

    public function render_consent_log() {
        global $wpdb;

        $filters = $this->consent_log_filters();
        $where = $this->consent_log_where($filters);
        $table = $wpdb->prefix . 'cmp_consent_log';
        $page = max(1, isset($_GET['paged']) ? absint($_GET['paged']) : 1);
        $per_page = self::$log_per_page;

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is built from prepared fragments
        // Counting millions of rows is slow; without a consent id filter the daily
        // statistics give the same number instantly.
        if ($filters['consent_id'] === '') {
            $stats_filter = $this->consent_stats_where($filters);
            $total = (int) $wpdb->get_var("SELECT COALESCE(SUM(total), 0) FROM {$wpdb->prefix}cmp_consent_stats s WHERE $stats_filter");
        } else {
            $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table l WHERE $where");
        }
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT l.*, d.domain FROM $table l LEFT JOIN {$wpdb->prefix}cmp_domains d ON d.id = l.domain_id
             WHERE $where ORDER BY l.created_at DESC, l.id DESC LIMIT %d OFFSET %d",
            $per_page,
            ($page - 1) * $per_page
        ));

        // Last 30 days per decision (respecting the domain filter only)
        $stats_where = $filters['domain_id'] ? $wpdb->prepare('domain_id = %d AND ', $filters['domain_id']) : '';
        $stats = $wpdb->get_results(
            "SELECT action, SUM(total) AS total FROM {$wpdb->prefix}cmp_consent_stats
             WHERE {$stats_where}day >= (UTC_DATE() - INTERVAL 29 DAY) GROUP BY action",
            OBJECT_K
        );
        // phpcs:enable

        $domains = $wpdb->get_results("SELECT id, domain FROM {$wpdb->prefix}cmp_domains ORDER BY domain ASC");
        $pages = max(1, (int) ceil($total / $per_page));
        $person_export_nonce = wp_create_nonce('focmp_consent_export');
        $tools_notice = $this->consent_tools_notice();
        $export_nonce = wp_create_nonce('focmp_consent_log_export');
        $oldest = $wpdb->get_var("SELECT MIN(created_at) FROM $table");
        $export_url = wp_nonce_url(
            add_query_arg(array_merge(array_filter($filters), array('page' => 'focmp-consent-log', 'export' => 'csv')), admin_url('admin.php')),
            'focmp_consent_log_export'
        );

        include FOCMP_PLUGIN_DIR . 'admin/views/consent-log.php';
    }

    /**
     * POST actions of the consent log tools (admin_init).
     */
    public function handle_consent_log_tools() {
        if (!isset($_POST['focmp_consent_tool'])) {
            return;
        }
        if (!current_user_can('manage_options') || !check_admin_referer('focmp_consent_tools')) {
            wp_die(esc_html__('You are not allowed to change the consent log.', 'fast-open-cmp'), 403);
        }
        $tool = sanitize_key(wp_unslash($_POST['focmp_consent_tool']));
        if ($tool === 'delete_before') {
            $this->delete_consents_before();
        }
        if ($tool === 'import') {
            $this->import_consents();
        }
    }

    /**
     * Uploaded file of consent entries (CSV / JSON Lines, plain or .gz).
     */
    private function import_consents() {
        $back = admin_url('admin.php?page=focmp-consent-log');
        $file = $_FILES['consent_file'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- checked below, read as a file only
        if (!$file || !empty($file['error']) || empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            $code = $file ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;
            wp_safe_redirect(add_query_arg(array('focmp_msg' => 'import_upload', 'count' => $code), $back));
            exit;
        }
        $result = FOCMP_Consent_Transfer::import_file($file['tmp_name']);
        set_transient('focmp_consent_import_' . get_current_user_id(), $result, HOUR_IN_SECONDS);
        wp_safe_redirect(add_query_arg('focmp_msg', 'imported', $back));
        exit;
    }

    /**
     * Manual "delete entries recorded before <date>". What does not fit in one
     * request continues in the background.
     */
    private function delete_consents_before() {
        $date = sanitize_text_field(wp_unslash($_POST['delete_before'] ?? ''));
        $back = admin_url('admin.php?page=focmp-consent-log');
        if (!FOCMP_Scheduler::is_date($date) || empty($_POST['confirm_delete'])) {
            wp_safe_redirect(add_query_arg('focmp_msg', 'delete_invalid', $back));
            exit;
        }
        $cutoff = $date . ' 00:00:00';
        $result = FOCMP_Scheduler::purge_before($cutoff, 20);
        if (!$result['done']) {
            wp_schedule_single_event(time() + MINUTE_IN_SECONDS, FOCMP_Scheduler::PURGE_BEFORE_HOOK, array($cutoff));
        }
        wp_safe_redirect(add_query_arg(array(
            'focmp_msg' => $result['done'] ? 'deleted' : 'deleting',
            'count' => $result['deleted'],
            'date' => $date,
        ), $back));
        exit;
    }

    /**
     * Result notice of a consent log tool (from the redirect).
     */
    private function consent_tools_notice() {
        $msg = isset($_GET['focmp_msg']) ? sanitize_key(wp_unslash($_GET['focmp_msg'])) : '';
        $count = number_format_i18n(isset($_GET['count']) ? absint($_GET['count']) : 0);
        $date = isset($_GET['date']) && FOCMP_Scheduler::is_date(sanitize_text_field(wp_unslash($_GET['date']))) ? sanitize_text_field(wp_unslash($_GET['date'])) : '';
        switch ($msg) {
            case 'deleted':
                /* translators: 1: number of entries, 2: date */
                return array('success', sprintf(__('Deleted %1$s entries recorded before %2$s.', 'fast-open-cmp'), $count, $date));
            case 'deleting':
                /* translators: 1: number of entries, 2: date */
                return array('warning', sprintf(__('Deleted %1$s entries recorded before %2$s so far; the rest is being deleted in the background (WP-Cron).', 'fast-open-cmp'), $count, $date));
            case 'delete_invalid':
                return array('error', __('Choose a date and confirm the deletion.', 'fast-open-cmp'));
            case 'import_upload':
                $code = isset($_GET['count']) ? absint($_GET['count']) : 0;
                if (in_array($code, array(UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE), true)) {
                    /* translators: %s: maximum upload size */
                    return array('error', sprintf(__('The file is larger than the server accepts (%s). Compress it (.gz) or split it.', 'fast-open-cmp'), size_format(wp_max_upload_size())));
                }
                return array('error', __('No file was uploaded.', 'fast-open-cmp'));
            case 'imported':
                $r = get_transient('focmp_consent_import_' . get_current_user_id());
                if (!is_array($r)) {
                    return null;
                }
                /* translators: 1: imported entries, 2: already present, 3: invalid, 4: unknown domain */
                $text = sprintf(__('Import finished: %1$s entries imported, %2$s already present, %3$s invalid, %4$s for domains not configured here.', 'fast-open-cmp'),
                    number_format_i18n($r['imported']), number_format_i18n($r['duplicates']), number_format_i18n($r['invalid']), number_format_i18n($r['unknown_domain']));
                if ($r['unknown_domains']) {
                    /* translators: %s: list of domain names */
                    $text .= ' ' . sprintf(__('Add these domains first and import the file again: %s.', 'fast-open-cmp'), implode(', ', $r['unknown_domains']));
                }
                if ($r['expiring']) {
                    /* translators: %s: number of entries */
                    $text .= ' ' . sprintf(__('%s imported entries are older than the retention period and will be deleted by the next daily clean-up.', 'fast-open-cmp'), number_format_i18n($r['expiring']));
                }
                return array($r['imported'] || $r['duplicates'] ? 'success' : 'warning', $text);
        }
        return null;
    }

    /**
     * Streams the filtered log as CSV (runs on admin_init, before any output).
     */
    public function maybe_export_consent_log() {
        if (isset($_GET['page'], $_GET['export']) && $_GET['page'] === 'focmp-consent-log' && $_GET['export'] === 'consent') {
            $this->export_person_consents();
            return;
        }
        if (!isset($_GET['page'], $_GET['export']) || $_GET['page'] !== 'focmp-consent-log' || !in_array($_GET['export'], array('csv', 'jsonl'), true)) {
            return;
        }
        if (!current_user_can('manage_options') || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'] ?? '')), 'focmp_consent_log_export')) {
            wp_die(esc_html__('You are not allowed to export the consent log.', 'fast-open-cmp'), 403);
        }

        $filters = $this->consent_log_filters();
        $name = 'consent-log';
        if ($filters['from'] !== '' || $filters['to'] !== '') {
            $name .= '-' . ($filters['from'] ?: 'start') . '_' . ($filters['to'] ?: gmdate('Y-m-d'));
        } else {
            $name .= '-' . gmdate('Y-m-d');
        }
        FOCMP_Consent_Transfer::stream_export(
            $this->consent_log_where($filters),
            sanitize_key($_GET['export']),
            !empty($_GET['gzip']),
            $name
        );
    }

    /**
     * Downloads every decision recorded for one consent id as JSON — for a data
     * subject's access or portability request (GDPR Art. 15/20): structured and
     * machine-readable, with the TC String decoded into named purposes, special
     * features and vendors next to the original strings.
     */
    private function export_person_consents() {
        if (!current_user_can('manage_options') || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'] ?? '')), 'focmp_consent_export')) {
            wp_die(esc_html__('You are not allowed to export the consent log.', 'fast-open-cmp'), 403);
        }
        $consent_id = strtolower(sanitize_text_field(wp_unslash($_GET['consent_id'] ?? '')));
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $consent_id)) {
            wp_die(esc_html__('Invalid consent ID.', 'fast-open-cmp'), 400);
        }

        $export = $this->build_person_export($consent_id);

        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="consent-' . $consent_id . '.json"');
        echo wp_json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON download
        exit;
    }

    /**
     * The export document for one consent id.
     */
    private function build_person_export($consent_id) {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT l.*, d.domain, t.name AS template_name, t.language AS template_language, t.config AS template_config,
                    COALESCE(p.tc_string, l.tc_string) AS tc, COALESCE(p.ac_string, l.ac_string) AS ac,
                    COALESCE(p.custom_consents, l.custom_consents) AS cc, COALESCE(p.custom_vendors, l.custom_vendors) AS cv
             FROM {$wpdb->prefix}cmp_consent_log l
             LEFT JOIN {$wpdb->prefix}cmp_domains d ON d.id = l.domain_id
             LEFT JOIN {$wpdb->prefix}cmp_templates t ON t.id = l.template_id
             LEFT JOIN {$wpdb->prefix}cmp_consent_payloads p ON p.id = l.payload_id
             WHERE l.consent_id = %s ORDER BY l.created_at ASC, l.id ASC",
            $consent_id
        ));

        $labels = array(
            'accept-all' => __('Accept all', 'fast-open-cmp'),
            'reject-all' => __('Reject all', 'fast-open-cmp'),
            'save' => __('Saved choices', 'fast-open-cmp'),
            'close-reject' => __('Closed (rejected)', 'fast-open-cmp'),
        );

        $decisions = array();
        foreach ($rows as $row) {
            $lang = $row->template_language ?: 'en';
            $decision = array(
                'date' => gmdate('c', strtotime($row->created_at . ' UTC')),
                'domain' => $row->domain,
                'environment' => $row->env,
                'template' => $row->template_id ? array('id' => (int) $row->template_id, 'name' => $row->template_name) : null,
                'decision' => $row->action,
                'decisionLabel' => $labels[$row->action] ?? $row->action,
                'mode' => $row->mode,
                'versions' => array(
                    'tcfPolicy' => $row->policy_version ? (int) $row->policy_version : null,
                    'vendorList' => $row->gvl_version ? (int) $row->gvl_version : null,
                    'cmp' => $row->cmp_version ? (int) $row->cmp_version : null,
                ),
                'reconsentId' => $row->reconsent_id ? (int) $row->reconsent_id : null,
            );

            $tc = $row->tc ? FOCMP_TC_Decoder::decode($row->tc) : null;
            if ($row->tc) {
                $decision['tcf'] = array(
                    'tcString' => $row->tc,
                    'purposesConsent' => $tc ? $this->named_purposes($tc['purposesConsent'], $lang) : null,
                    'purposesLegitimateInterest' => $tc ? $this->named_purposes($tc['purposesLegitimateInterest'], $lang) : null,
                    'specialFeaturesOptIn' => $tc ? $this->named_special_features($tc['specialFeatures'], $lang) : null,
                    'vendorsConsent' => $tc ? $this->named_rows('cmp_vendors_iab', 'vendor_id', 'name', $tc['vendorsConsent']) : null,
                    'vendorsLegitimateInterest' => $tc ? $this->named_rows('cmp_vendors_iab', 'vendor_id', 'name', $tc['vendorsLegitimateInterest']) : null,
                );
            }
            if ($row->ac) {
                $decision['additionalConsent'] = array(
                    'acString' => $row->ac,
                    'consentedProviders' => $this->named_rows('cmp_vendors_google', 'provider_id', 'provider_name', self::parse_ac_consented($row->ac)),
                );
            }
            if ($row->cc) {
                $titles = array();
                $config = json_decode((string) $row->template_config, true);
                foreach ($config['customConsents'] ?? array() as $consent) {
                    $titles[$consent['id']] = $consent['title'];
                }
                $parsed = self::parse_compact($row->cc);
                $decision['customConsents'] = array(
                    'granted' => array_map(function ($id) use ($titles) {
                        return array('id' => $id, 'title' => $titles[$id] ?? null);
                    }, $parsed['c'] ?? array()),
                    'legitimateInterest' => $parsed['l'] ?? array(),
                );
            }
            if ($row->cv) {
                $parsed = self::parse_compact($row->cv);
                $decision['customVendors'] = array(
                    'google' => $this->named_rows('cmp_vendors_google', 'provider_id', 'provider_name', array_map('intval', $parsed['g'] ?? array())),
                    'other' => $this->named_rows('cmp_vendors_custom', 'id', 'name', array_map('intval', $parsed['o'] ?? array())),
                );
            }
            $decision['page'] = $row->page_url;
            $decision['ipAnonymised'] = $row->ip_anon;
            $decision['userAgent'] = $row->user_agent;
            $decisions[] = $decision;
        }

        return array(
            'consentId' => $consent_id,
            'exportedAt' => gmdate('c'),
            'exportedBy' => 'Fast Open CMP ' . FOCMP_VERSION . ' (' . home_url() . ')',
            'description' => __('All consent decisions recorded for this consent ID, oldest first. "tcf" lists what the TC String (IAB Transparency & Consent Framework) contains; names come from the current vendor list.', 'fast-open-cmp'),
            'decisions' => $decisions,
        );
    }

    /**
     * Compact cookie value "c:a.b|l:x|t:123" → ['c' => ['a', 'b'], 'l' => ['x']] (timestamp dropped).
     */
    public static function parse_compact($value) {
        $out = array();
        foreach (explode('|', (string) $value) as $section) {
            $sep = strpos($section, ':');
            if ($sep === false) {
                continue;
            }
            $key = substr($section, 0, $sep);
            if ($key === 't') {
                continue;
            }
            $body = substr($section, $sep + 1);
            $out[$key] = $body === '' ? array() : explode('.', $body);
        }
        return $out;
    }

    /**
     * Consented Google ATP providers from an Additional Consent string "2~1.2~dv.3".
     */
    public static function parse_ac_consented($ac) {
        $parts = explode('~', (string) $ac);
        if (($parts[0] ?? '') !== '2' && ($parts[0] ?? '') !== '1') {
            return array();
        }
        $ids = isset($parts[1]) && $parts[1] !== '' ? explode('.', $parts[1]) : array();
        return array_values(array_filter(array_map('intval', $ids)));
    }

    /** [{id, name}] for ids, names from a vendor table. */
    private function named_rows($table, $id_column, $name_column, array $ids) {
        global $wpdb;
        if (!$ids) {
            return array();
        }
        $names = array();
        foreach (array_chunk($ids, 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '%d'));
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table/column names, prepared ids
            $found = $wpdb->get_results($wpdb->prepare(
                "SELECT $id_column AS id, $name_column AS name FROM {$wpdb->prefix}$table WHERE $id_column IN ($placeholders)",
                $chunk
            ));
            foreach ($found as $item) {
                $names[(int) $item->id] = $item->name;
            }
        }
        return array_map(function ($id) use ($names) {
            return array('id' => (int) $id, 'name' => $names[(int) $id] ?? null);
        }, $ids);
    }

    private function named_purposes(array $ids, $lang) {
        global $wpdb;
        $names = array();
        foreach (array($lang, 'en') as $language) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT purpose_id, data FROM {$wpdb->prefix}cmp_purposes_iab WHERE language = %s",
                $language
            ));
            foreach ($rows as $row) {
                $data = json_decode($row->data, true);
                if (!isset($names[(int) $row->purpose_id]) && !empty($data['name'])) {
                    $names[(int) $row->purpose_id] = $data['name'];
                }
            }
        }
        return array_map(function ($id) use ($names) {
            return array('id' => $id, 'name' => $names[$id] ?? null);
        }, $ids);
    }

    private function named_special_features(array $ids, $lang) {
        global $wpdb;
        $names = array();
        foreach (array($lang, 'en') as $language) {
            $json = $wpdb->get_var($wpdb->prepare(
                "SELECT data FROM {$wpdb->prefix}cmp_gvl_metadata WHERE meta_key = 'specialFeatures' AND language = %s",
                $language
            ));
            foreach ((array) json_decode((string) $json, true) as $id => $feature) {
                if (!isset($names[(int) $id]) && !empty($feature['name'])) {
                    $names[(int) $id] = $feature['name'];
                }
            }
        }
        return array_map(function ($id) use ($names) {
            return array('id' => $id, 'name' => $names[$id] ?? null);
        }, $ids);
    }
}
