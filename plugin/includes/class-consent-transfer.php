<?php
/**
 * Export and import of consent log entries in a portable form: one record per
 * decision with the consent strings inline and the domain by name, so entries
 * move between installations whose ids differ.
 *
 * Formats: CSV (spreadsheets; formula-like values prefixed with "'") and JSON
 * Lines (one object per line, first line = format header). Both can be gzip
 * compressed; the importer reads plain and .gz files alike.
 *
 * Imports are idempotent: an entry keeps its record_id (entries without one get
 * an id derived from their content), and the log's unique key skips duplicates.
 */

if (!defined('ABSPATH')) {
    exit;
}

class FOCMP_Consent_Transfer {

    const FORMAT = 'fast-open-cmp-consents';
    const FORMAT_VERSION = 1;

    /** Record fields, in CSV column order. */
    const FIELDS = array(
        'created_at', 'consent_id', 'domain_id', 'domain', 'env', 'template_id', 'action', 'mode',
        'policy_version', 'gvl_version', 'cmp_version', 'reconsent_id', 'tc_string', 'ac_string',
        'custom_consents', 'custom_vendors', 'page_url', 'ip_anon', 'user_agent', 'record_id',
    );

    const ACTIONS = array('accept-all', 'reject-all', 'save', 'close-reject');
    const MODES = array('tcf_gcm', 'gcm_only', 'basic');
    const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    /* ------------------------------------------------------------------ export */

    /**
     * Streams the entries matching $where (prepared SQL on alias l) and exits.
     *
     * @param string $format 'csv' or 'jsonl'.
     */
    public static function stream_export($where, $format, $gzip, $filename) {
        global $wpdb;
        $jsonl = $format === 'jsonl';
        $out = new FOCMP_Download_Stream(
            $filename . ($jsonl ? '.jsonl' : '.csv'),
            $jsonl ? 'application/x-ndjson; charset=utf-8' : 'text/csv; charset=utf-8',
            $gzip
        );

        if ($jsonl) {
            $out->write(wp_json_encode(array(
                'format' => self::FORMAT,
                'version' => self::FORMAT_VERSION,
                'plugin' => FOCMP_VERSION,
                'exported_at' => gmdate('c'),
                'site' => home_url(),
            )) . "\n");
        } else {
            $out->write(self::csv_line(self::FIELDS));
        }

        // Keyset pagination (id > last) stays fast on large logs.
        $table = $wpdb->prefix . 'cmp_consent_log';
        $last_id = 0;
        do {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is built from prepared fragments
            $batch = $wpdb->get_results($wpdb->prepare(
                "SELECT l.id, l.created_at, l.consent_id, l.domain_id, d.domain, l.env, l.template_id, l.action, l.mode,
                        l.policy_version, l.gvl_version, l.cmp_version, l.reconsent_id,
                        COALESCE(p.tc_string, l.tc_string) AS tc_string, COALESCE(p.ac_string, l.ac_string) AS ac_string,
                        COALESCE(p.custom_consents, l.custom_consents) AS custom_consents,
                        COALESCE(p.custom_vendors, l.custom_vendors) AS custom_vendors,
                        l.page_url, l.ip_anon, l.user_agent, l.record_id
                 FROM $table l
                 LEFT JOIN {$wpdb->prefix}cmp_domains d ON d.id = l.domain_id
                 LEFT JOIN {$wpdb->prefix}cmp_consent_payloads p ON p.id = l.payload_id
                 WHERE $where AND l.id > %d ORDER BY l.id ASC LIMIT 1000",
                $last_id
            ), ARRAY_A);
            $chunk = '';
            foreach ($batch as $row) {
                $last_id = (int) $row['id'];
                if ($jsonl) {
                    $record = array();
                    foreach (self::FIELDS as $field) {
                        $record[$field] = $row[$field] ?? null;
                    }
                    foreach (array('domain_id', 'template_id', 'policy_version', 'gvl_version', 'cmp_version', 'reconsent_id') as $int) {
                        $record[$int] = $record[$int] === null ? null : (int) $record[$int];
                    }
                    $chunk .= wp_json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
                } else {
                    $line = array();
                    foreach (self::FIELDS as $field) {
                        $line[] = self::csv_safe((string) ($row[$field] ?? ''));
                    }
                    $chunk .= self::csv_line($line);
                }
            }
            $out->write($chunk);
        } while (count($batch) === 1000);

        $out->close();
        exit;
    }

    /** Neutralises spreadsheet formulas (CSV injection). */
    public static function csv_safe($value) {
        return preg_match('/^[=+\-@\t\r]/', $value) ? "'" . $value : $value;
    }

    /** Reverses csv_safe(). */
    public static function csv_unsafe($value) {
        return preg_match("/^'[=+\\-@\\t\\r]/", $value) ? substr($value, 1) : $value;
    }

    private static function csv_line(array $fields) {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, $fields, ',', '"', '\\');
        rewind($handle);
        $line = stream_get_contents($handle);
        fclose($handle);
        return $line;
    }

    /* ------------------------------------------------------------------ import */

    /**
     * Reads records from a CSV or JSON Lines file (plain or gzip).
     *
     * @return Generator<int, array|null> Associative records; null for a line
     *                                    that is not a record (bad JSON).
     */
    public static function read_records($path) {
        $handle = fopen('compress.zlib://' . $path, 'rb');
        if (!$handle) {
            return;
        }
        $first = '';
        while (($first = fgets($handle)) !== false && trim($first) === '') {
            continue;
        }
        if ($first === false) {
            fclose($handle);
            return;
        }
        $first = preg_replace('/^\xEF\xBB\xBF/', '', $first);

        if (ltrim($first)[0] === '{') {
            $line = $first;
            do {
                if (trim($line) === '') {
                    continue;
                }
                $data = json_decode($line, true);
                if (is_array($data) && isset($data['format'])) {
                    continue; // header line
                }
                yield is_array($data) ? $data : null;
            } while (($line = fgets($handle)) !== false);
        } else {
            $header = array_map('trim', str_getcsv($first, ',', '"', '\\'));
            while (($fields = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
                if ($fields === array(null)) {
                    continue; // empty line
                }
                $record = array();
                foreach ($header as $i => $name) {
                    $record[$name] = isset($fields[$i]) ? self::csv_unsafe($fields[$i]) : null;
                }
                yield $record;
            }
        }
        fclose($handle);
    }

    /**
     * Validates one imported record and maps it onto this installation.
     *
     * @param array $ctx {domains: array<string host, int id>, domain_ids: array<int, true>,
     *                    template_ids: array<int, true>, now: int}
     * @return array{record?: array, error?: string, domain?: string}
     */
    public static function normalize_record($d, array $ctx) {
        if (!is_array($d)) {
            return array('error' => 'invalid');
        }
        $str = function ($key) use ($d) {
            return isset($d[$key]) && $d[$key] !== null ? trim((string) $d[$key]) : '';
        };

        $consent_id = strtolower($str('consent_id'));
        $action = $str('action');
        $mode = $str('mode') ?: 'tcf_gcm';
        $created_at = self::utc_datetime($str('created_at'), $ctx['now']);
        if (!preg_match(self::UUID, $consent_id) || !in_array($action, self::ACTIONS, true)
            || !in_array($mode, self::MODES, true) || $created_at === null) {
            return array('error' => 'invalid');
        }

        // Domain by name (ids differ between installations), else by id.
        $name = $str('domain');
        $domain_id = 0;
        if ($name !== '') {
            $domain_id = $ctx['domains'][FOCMP_Domain_Access::normalize_host($name)] ?? 0;
        } elseif ((int) $str('domain_id') && isset($ctx['domain_ids'][(int) $str('domain_id')])) {
            $domain_id = (int) $str('domain_id');
        }
        if (!$domain_id) {
            return array('error' => 'unknown_domain', 'domain' => $name !== '' ? $name : '#' . $str('domain_id'));
        }

        $pattern = function ($value, $regex, $max) {
            return ($value !== '' && strlen($value) <= $max && preg_match($regex, $value)) ? $value : null;
        };
        $int = function ($value, $max) {
            $value = (int) $value;
            return ($value > 0 && $value <= $max) ? $value : null;
        };
        $template_id = (int) $str('template_id');

        $record = array(
            'consent_id' => $consent_id,
            'record_id' => null,
            'domain_id' => $domain_id,
            'action' => $action,
            'mode' => $mode,
            'env' => $str('env') === 'test' ? 'test' : 'prod',
            'policy_version' => $int($str('policy_version'), 65535),
            'gvl_version' => $int($str('gvl_version'), 4294967295),
            'cmp_version' => $int($str('cmp_version'), 4095),
            'reconsent_id' => $int($str('reconsent_id'), PHP_INT_MAX),
            'template_id' => isset($ctx['template_ids'][$template_id]) ? $template_id : null,
            'tc_string' => $pattern($str('tc_string'), '/^[A-Za-z0-9_.-]+$/', 20000),
            'ac_string' => $pattern($str('ac_string'), '/^[0-9~.dv]+$/', 20000),
            'custom_consents' => $pattern($str('custom_consents'), '/^[A-Za-z0-9_.:|-]+$/', 1000),
            'custom_vendors' => $pattern($str('custom_vendors'), '/^[A-Za-z0-9_.:|-]+$/', 20000),
            'page_url' => FOCMP_API::strip_url_query($str('page_url')),
            // Anonymised again: an import must not bring full addresses in.
            'ip_anon' => FOCMP_API::anonymize_ip($str('ip_anon')),
            'user_agent' => $str('user_agent') !== '' ? substr(sanitize_text_field($str('user_agent')), 0, 255) : null,
            'created_at' => $created_at,
        );
        $record_id = strtolower($str('record_id'));
        $record['record_id'] = preg_match(self::UUID, $record_id) ? $record_id : self::derive_record_id($record, $name);

        return array('record' => $record);
    }

    /**
     * Stable UUID-shaped id from an entry's content, for entries exported without
     * a record_id: importing the same file twice adds nothing the second time.
     */
    public static function derive_record_id(array $record, $domain_name) {
        $hash = md5(implode('|', array(
            $record['consent_id'], $record['created_at'], $record['action'], $domain_name ?: $record['domain_id'],
            $record['tc_string'], $record['ac_string'], $record['custom_consents'], $record['custom_vendors'],
        )));
        return substr($hash, 0, 8) . '-' . substr($hash, 8, 4) . '-4' . substr($hash, 13, 3) . '-'
            . dechex(8 | (hexdec($hash[16]) & 3)) . substr($hash, 17, 3) . '-' . substr($hash, 20, 12);
    }

    /**
     * "Y-m-d H:i:s" (taken as UTC) or ISO 8601 with offset → UTC "Y-m-d H:i:s".
     * Null for anything unparseable or in the future.
     */
    public static function utc_datetime($value, $now) {
        if ($value === '') {
            return null;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2})?$/', $value)) {
            $value .= ' UTC';
        }
        try {
            $date = new DateTimeImmutable($value);
        } catch (Exception $e) {
            return null;
        }
        $ts = $date->getTimestamp();
        if ($ts < 0 || $ts > $now + DAY_IN_SECONDS) {
            return null;
        }
        return gmdate('Y-m-d H:i:s', $ts);
    }

    /**
     * Imports a file into the consent log.
     *
     * @return array{imported:int, duplicates:int, invalid:int, unknown_domain:int,
     *               unknown_domains:string[], expiring:int}
     */
    public static function import_file($path) {
        global $wpdb;
        if (function_exists('set_time_limit')) {
            @set_time_limit(0); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        }

        $ctx = array('domains' => array(), 'domain_ids' => array(), 'template_ids' => array(), 'now' => time());
        foreach ($wpdb->get_results("SELECT id, domain FROM {$wpdb->prefix}cmp_domains") as $domain) {
            $ctx['domains'][FOCMP_Domain_Access::normalize_host($domain->domain)] = (int) $domain->id;
            $ctx['domain_ids'][(int) $domain->id] = true;
        }
        foreach ($wpdb->get_col("SELECT id FROM {$wpdb->prefix}cmp_templates") as $id) {
            $ctx['template_ids'][(int) $id] = true;
        }
        $cutoff = FOCMP_Scheduler::retention_cutoff(get_option('focmp_settings', array()), $ctx['now']);

        $result = array('imported' => 0, 'duplicates' => 0, 'invalid' => 0, 'unknown_domain' => 0, 'unknown_domains' => array(), 'expiring' => 0);
        $stats = array();
        $table = $wpdb->prefix . 'cmp_consent_log';

        // One transaction per 500 records: committing every row separately (one
        // log flush each) made imports ~10x slower.
        $wpdb->query('START TRANSACTION');
        $in_transaction = 0;
        foreach (self::read_records($path) as $data) {
            if (++$in_transaction >= 500) {
                $wpdb->query('COMMIT');
                $wpdb->query('START TRANSACTION');
                $in_transaction = 0;
            }
            $normalized = self::normalize_record($data, $ctx);
            if (isset($normalized['error'])) {
                $result[$normalized['error']]++;
                if ($normalized['error'] === 'unknown_domain' && count($result['unknown_domains']) < 20) {
                    $result['unknown_domains'][$normalized['domain']] = true;
                }
                continue;
            }
            $record = $normalized['record'];
            $record['payload_id'] = FOCMP_API::store_payload($record);
            foreach (array('tc_string', 'ac_string', 'custom_consents', 'custom_vendors') as $column) {
                unset($record[$column]);
            }
            if (!FOCMP_API::insert_ignore($table, $record)) {
                $result['duplicates']++;
                continue;
            }
            $result['imported']++;
            if ($cutoff !== null && $record['created_at'] < $cutoff) {
                $result['expiring']++;
            }
            $key = substr($record['created_at'], 0, 10) . '|' . $record['domain_id'] . '|' . $record['action'];
            $stats[$key] = ($stats[$key] ?? 0) + 1;
            if (count($stats) >= 500) {
                self::add_stats($stats);
                $stats = array();
            }
        }
        self::add_stats($stats);
        $wpdb->query('COMMIT');
        $result['unknown_domains'] = array_keys($result['unknown_domains']);
        return $result;
    }

    /** Adds imported entries to the daily counters (slot 0). */
    private static function add_stats(array $stats) {
        global $wpdb;
        if (!$stats) {
            return;
        }
        $values = array();
        foreach ($stats as $key => $count) {
            list($day, $domain_id, $action) = explode('|', $key);
            $values[] = $wpdb->prepare('(%s, %d, %s, 0, %d)', $day, $domain_id, $action, $count);
        }
        $sql = "INSERT INTO {$wpdb->prefix}cmp_consent_stats (day, domain_id, action, slot, total) VALUES "
            . implode(',', $values) . ' ON DUPLICATE KEY UPDATE total = total + VALUES(total)';
        $wpdb->query($sql); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- values prepared above
    }
}
