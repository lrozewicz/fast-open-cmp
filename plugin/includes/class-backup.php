<?php
/**
 * Backup and migration of the whole plugin: every configuration table, the
 * plugin options and, optionally, the consent log.
 *
 * File: JSON Lines (gzip by default) —
 *   {"format":"fast-open-cmp-backup","version":1,"db_version":5,…}
 *   {"table":"cmp_domains","columns":[…]}
 *   {"r":[…values…]}                       one line per row
 *   {"option":"focmp_settings","value":…}
 *   {"end":true,"rows":{"cmp_domains":3,…}}
 * Rows keep their ids, so the references between tables stay intact. A file
 * without the end line (truncated download/upload) is refused.
 *
 * Restoring REPLACES the configuration: the configuration tables are emptied and
 * filled in one transaction (rolled back on any error). The consent log is only
 * touched when the file contains it and the admin asks for it.
 */

if (!defined('ABSPATH')) {
    exit;
}

class FOCMP_Backup {

    const FORMAT = 'fast-open-cmp-backup';
    const VERSION = 1;

    /** Configuration tables, parents before children. */
    const CONFIG_TABLES = array(
        'cmp_companies', 'cmp_gvl_metadata', 'cmp_purposes_iab', 'cmp_vendors_iab', 'cmp_vendors_google',
        'cmp_vendors_custom', 'cmp_vendor_lists', 'cmp_vendor_list_entries', 'cmp_custom_consents',
        'cmp_templates', 'cmp_template_vendors', 'cmp_domains', 'cmp_reconsents',
    );
    const CONSENT_TABLES = array('cmp_consent_payloads', 'cmp_consent_log', 'cmp_consent_stats');
    const OPTIONS = array('focmp_settings', 'focmp_texts', 'focmp_gvl_status');

    /** Tables of the list that exist in this database. */
    private static function existing(array $tables) {
        global $wpdb;
        $found = array();
        foreach ($tables as $table) {
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($wpdb->prefix . $table)))) {
                $found[] = $table;
            }
        }
        return $found;
    }

    private static function columns($table) {
        global $wpdb;
        return $wpdb->get_col("SHOW COLUMNS FROM {$wpdb->prefix}{$table}"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table name
    }

    /**
     * Rows and approximate size (bytes) of the consent log tables, for the
     * "include consents" choice.
     */
    public static function consent_log_size() {
        global $wpdb;
        $names = array_map(function ($t) use ($wpdb) { return $wpdb->prefix . $t; }, self::CONSENT_TABLES);
        $placeholders = implode(',', array_fill(0, count($names), '%s'));
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders only
        $bytes = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(DATA_LENGTH) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ($placeholders)",
            $names
        ));
        $rows = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}cmp_consent_log");
        return array('rows' => $rows, 'bytes' => $bytes);
    }

    /* ------------------------------------------------------------------ export */

    public static function stream_export($include_consents, $gzip) {
        global $wpdb;
        $host = (string) wp_parse_url(home_url(), PHP_URL_HOST);
        $out = new FOCMP_Download_Stream(
            'fast-open-cmp-backup-' . ($host ? $host . '-' : '') . gmdate('Y-m-d-His') . ($include_consents ? '-with-consents' : '') . '.jsonl',
            'application/x-ndjson; charset=utf-8',
            $gzip
        );

        $tables = self::existing(array_merge(self::CONFIG_TABLES, $include_consents ? self::CONSENT_TABLES : array()));
        $out->write(wp_json_encode(array(
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'plugin' => FOCMP_VERSION,
            'db_version' => (int) get_option('focmp_db_version', FOCMP_Activator::DB_VERSION),
            'created_at' => gmdate('c'),
            'site' => home_url(),
            'consents' => (bool) $include_consents,
            'tables' => $tables,
        )) . "\n");

        $counts = array();
        foreach ($tables as $table) {
            $columns = self::columns($table);
            $out->write(wp_json_encode(array('table' => $table, 'columns' => $columns)) . "\n");
            $counts[$table] = 0;
            foreach (self::table_rows($table, $columns) as $row) {
                $out->write(wp_json_encode(array('r' => array_values($row)), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
                $counts[$table]++;
            }
        }
        foreach (self::OPTIONS as $option) {
            $value = get_option($option, null);
            if ($value !== null) {
                $out->write(wp_json_encode(array('option' => $option, 'value' => $value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
            }
        }
        $out->write(wp_json_encode(array('end' => true, 'rows' => $counts)) . "\n");
        $out->close();
        exit;
    }

    /**
     * All rows of a table in batches: keyset on id, or ordered pages for the
     * statistics table (composite key).
     */
    private static function table_rows($table, array $columns) {
        global $wpdb;
        $name = $wpdb->prefix . $table;
        if (in_array('id', $columns, true)) {
            $last = 0;
            do {
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table name
                $batch = $wpdb->get_results($wpdb->prepare("SELECT * FROM $name WHERE id > %d ORDER BY id LIMIT 1000", $last), ARRAY_A);
                foreach ($batch as $row) {
                    $last = (int) $row['id'];
                    yield $row;
                }
            } while (count($batch) === 1000);
            return;
        }
        $offset = 0;
        do {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table name
            $batch = $wpdb->get_results($wpdb->prepare("SELECT * FROM $name ORDER BY 1, 2, 3, 4 LIMIT 1000 OFFSET %d", $offset), ARRAY_A);
            foreach ($batch as $row) {
                yield $row;
            }
            $offset += 1000;
        } while (count($batch) === 1000);
    }

    /* ------------------------------------------------------------------ import */

    /**
     * Reads the file once without changing anything: format, version, end line.
     *
     * @return array{meta: array, rows: array}|WP_Error
     */
    public static function inspect($path) {
        $meta = null;
        $end = null;
        $handle = fopen('compress.zlib://' . $path, 'rb');
        if (!$handle) {
            return new WP_Error('unreadable', __('The file cannot be read.', 'fast-open-cmp'));
        }
        while (($line = fgets($handle)) !== false) {
            if (trim($line) === '') {
                continue;
            }
            if ($meta === null) {
                $meta = json_decode($line, true);
                if (!is_array($meta) || ($meta['format'] ?? '') !== self::FORMAT) {
                    fclose($handle);
                    return new WP_Error('format', __('This is not a Fast Open CMP backup file.', 'fast-open-cmp'));
                }
                continue;
            }
            if (strpos($line, '{"end":') === 0) {
                $end = json_decode($line, true);
            }
        }
        fclose($handle);

        if ($meta === null) {
            return new WP_Error('format', __('This is not a Fast Open CMP backup file.', 'fast-open-cmp'));
        }
        if ((int) ($meta['version'] ?? 0) > self::VERSION || (int) ($meta['db_version'] ?? 0) > FOCMP_Activator::DB_VERSION) {
            /* translators: %s: plugin version that created the backup */
            return new WP_Error('newer', sprintf(__('The backup comes from a newer plugin version (%s). Update the plugin first.', 'fast-open-cmp'), $meta['plugin'] ?? '?'));
        }
        if (!is_array($end)) {
            return new WP_Error('truncated', __('The backup file is incomplete (no end marker) — the download or upload was cut off.', 'fast-open-cmp'));
        }
        return array('meta' => $meta, 'rows' => (array) ($end['rows'] ?? array()));
    }

    /**
     * Restores a backup.
     *
     * @param bool $include_consents Replace the consent log with the file's.
     * @param bool $trusted          The admin may post unfiltered HTML/CSS
     *                               (unfiltered_html); otherwise texts are
     *                               sanitised and custom CSS / HEAD code dropped.
     * @return array{tables: array<string,int>, options: string[], consents: bool}|WP_Error
     */
    public static function import_file($path, $include_consents, $trusted) {
        global $wpdb;
        $info = self::inspect($path);
        if (is_wp_error($info)) {
            return $info;
        }
        if (function_exists('set_time_limit')) {
            @set_time_limit(0); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        }

        $config = self::existing(self::CONFIG_TABLES);
        $consent = $include_consents && !empty($info['meta']['consents']) ? self::existing(self::CONSENT_TABLES) : array();
        $result = array('tables' => array(), 'options' => array(), 'consents' => (bool) $consent);

        $wpdb->query('START TRANSACTION');
        $consent_started = false;
        $table = null;
        $columns = array();
        $keep = array();
        $batch = array();
        $pending = 0;

        $handle = fopen('compress.zlib://' . $path, 'rb');
        fgets($handle); // header
        while (($line = fgets($handle)) !== false) {
            if (trim($line) === '') {
                continue;
            }
            $data = json_decode($line, true);
            if (!is_array($data)) {
                return self::abort($handle, __('The backup file contains an unreadable line.', 'fast-open-cmp'));
            }

            if (isset($data['r'])) {
                if ($table === null) {
                    continue; // rows of a table this installation does not restore
                }
                $row = array();
                foreach ($keep as $index => $column) {
                    $row[$column] = $data['r'][$index] ?? null;
                }
                if (!$trusted && $table === 'cmp_templates' && isset($row['config'])) {
                    $row['config'] = self::sanitize_template_config($row['config']);
                }
                $batch[] = $row;
                $result['tables'][$table]++;
                if (count($batch) >= 200 && !self::insert_rows($table, $batch)) {
                    return self::abort($handle, $wpdb->last_error);
                }
                if (count($batch) >= 200) {
                    $batch = array();
                }
                // The consent log is not one transaction: commit in chunks.
                if ($consent_started && ++$pending >= 2000) {
                    $wpdb->query('COMMIT');
                    $wpdb->query('START TRANSACTION');
                    $pending = 0;
                }
                continue;
            }

            // Next section: write what is left of the previous table.
            if ($table !== null && $batch && !self::insert_rows($table, $batch)) {
                return self::abort($handle, $wpdb->last_error);
            }
            $batch = array();

            if (isset($data['table'])) {
                $name = (string) $data['table'];
                $table = null;
                if (in_array($name, $config, true) || in_array($name, $consent, true)) {
                    if (in_array($name, $consent, true) && !$consent_started) {
                        // Configuration is complete and consistent: commit it first.
                        $wpdb->query('COMMIT');
                        $wpdb->query('START TRANSACTION');
                        $consent_started = true;
                    }
                    $table = $name;
                    $present = self::columns($table);
                    $columns = (array) $data['columns'];
                    $keep = array_intersect($columns, $present); // index => column
                    $result['tables'][$table] = 0;
                    // Configuration: DELETE inside the transaction (rolled back on error).
                    // Consent log: TRUNCATE — a transactional DELETE of millions of rows
                    // is far too heavy, and the configuration is committed by now.
                    $empty = $consent_started ? 'TRUNCATE TABLE' : 'DELETE FROM';
                    if ($wpdb->query("$empty {$wpdb->prefix}{$table}") === false) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table name
                        return self::abort($handle, $wpdb->last_error);
                    }
                }
                continue;
            }

            $table = null;
            if (isset($data['option']) && in_array($data['option'], self::OPTIONS, true)) {
                $value = $data['value'];
                if ($data['option'] === 'focmp_settings' && is_array($value) && !$trusted) {
                    // HEAD code needs unfiltered_html: keep this site's.
                    $current = get_option('focmp_settings', array());
                    $value['head_scripts'] = $current['head_scripts'] ?? '';
                }
                update_option($data['option'], $value);
                $result['options'][] = $data['option'];
            }
        }
        fclose($handle);
        $wpdb->query('COMMIT');

        self::after_import((int) ($info['meta']['db_version'] ?? FOCMP_Activator::DB_VERSION));
        return $result;
    }

    private static function abort($handle, $message) {
        global $wpdb;
        $wpdb->query('ROLLBACK');
        if (is_resource($handle)) {
            fclose($handle);
        }
        /* translators: %s: database or file error */
        return new WP_Error('import_failed', sprintf(__('Restore failed and was rolled back: %s', 'fast-open-cmp'), $message));
    }

    private static function insert_rows($table, array $rows) {
        global $wpdb;
        $columns = array_keys($rows[0]);
        $values = array();
        foreach ($rows as $row) {
            $cells = array();
            foreach ($columns as $column) {
                $value = $row[$column];
                $cells[] = $value === null ? 'NULL' : $wpdb->prepare('%s', is_scalar($value) ? (string) $value : wp_json_encode($value));
            }
            $values[] = '(' . implode(',', $cells) . ')';
        }
        $names = implode(',', array_map(function ($c) { return '`' . preg_replace('/[^A-Za-z0-9_]/', '', $c) . '`'; }, $columns));
        $sql = "INSERT INTO {$wpdb->prefix}{$table} ($names) VALUES " . implode(',', $values);
        return $wpdb->query($sql) !== false; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- values prepared above, columns whitelisted
    }

    /**
     * Template config from an untrusted backup: texts through wp_kses_post (as on
     * save), no custom CSS.
     */
    public static function sanitize_template_config($json) {
        $config = json_decode((string) $json, true);
        if (!is_array($config)) {
            return '{}';
        }
        unset($config['appearance']['customCss']);
        array_walk_recursive($config, function (&$value) {
            if (is_string($value)) {
                $value = wp_kses_post($value);
            }
        });
        return wp_json_encode($config);
    }

    /**
     * Caches, schema of older backups, schedules.
     */
    private static function after_import($file_db_version) {
        FOCMP_Cache::get_instance()->clear_all();
        if ($file_db_version < FOCMP_Activator::DB_VERSION) {
            // Data of an older schema: run the newer migration steps over it.
            update_option('focmp_db_version', $file_db_version);
            delete_transient('focmp_upgrading');
            FOCMP_Activator::maybe_upgrade();
        }
        FOCMP_Scheduler::ensure_scheduled();
    }
}
