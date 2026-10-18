<?php
/**
 * Scheduled Global Vendor List updates.
 *
 * The TCF Policy expects CMPs to work with a current GVL (IAB publishes a new
 * version weekly). A WP-Cron event re-imports the GVL, the purpose translations
 * that were imported before and the Google ATP list, and records the outcome so
 * the admin can see when the list was last refreshed.
 */

if (!defined('ABSPATH')) {
    exit;
}

class FOCMP_Scheduler {
    const HOOK = 'focmp_gvl_update';
    const STATUS_OPTION = 'focmp_gvl_status';
    const STALE_DAYS = 14;
    const PURGE_HOOK = 'focmp_consent_log_purge';
    const PURGE_BEFORE_HOOK = 'focmp_consent_log_purge_before';

    public function init() {
        add_action(self::HOOK, array(__CLASS__, 'run'));
        add_action(self::PURGE_HOOK, array(__CLASS__, 'purge_consent_log'));
        add_action(self::PURGE_BEFORE_HOOK, array(__CLASS__, 'continue_purge_before'));
        add_action('admin_init', array(__CLASS__, 'ensure_scheduled'));
    }

    /**
     * Deletes consent log entries older than the retention period: the rolling
     * number of days and/or the fixed date from the settings, whichever removes
     * more (none set = keep everything).
     *
     * @return int Number of deleted rows.
     */
    public static function purge_consent_log() {
        $cutoff = self::retention_cutoff(get_option('focmp_settings', array()), time());
        if ($cutoff === null) {
            return 0;
        }
        return self::purge_before($cutoff)['deleted'];
    }

    /**
     * Cutoff (UTC "Y-m-d H:i:s") of the automatic deletion, or null.
     *
     * @param array $settings focmp_settings.
     * @param int   $now      Unix time.
     */
    public static function retention_cutoff(array $settings, $now) {
        $cutoffs = array();
        $days = isset($settings['consent_log_retention_days']) ? (int) $settings['consent_log_retention_days'] : 1095;
        if ($days > 0) {
            $cutoffs[] = gmdate('Y-m-d H:i:s', $now - $days * DAY_IN_SECONDS);
        }
        $date = (string) ($settings['consent_log_delete_before'] ?? '');
        if (self::is_date($date)) {
            $cutoffs[] = $date . ' 00:00:00';
        }
        // Same format, so the later cutoff is the larger string.
        return $cutoffs ? max($cutoffs) : null;
    }

    public static function is_date($value) {
        if (!is_string($value) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
            return false;
        }
        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /**
     * Deletes entries recorded before $cutoff (UTC) in batches until done or the
     * time budget is used up. Statistics of the fully deleted days and payloads
     * nobody references any more go too.
     *
     * @return array{deleted:int, done:bool}
     */
    public static function purge_before($cutoff, $budget = 25) {
        global $wpdb;

        // Small batches: one huge DELETE would lock the table and bloat the undo
        // log. Whatever is left after the time budget goes in the next run.
        $deleted = 0;
        $started = time();
        do {
            $batch = (int) $wpdb->query($wpdb->prepare(
                "DELETE FROM {$wpdb->prefix}cmp_consent_log WHERE created_at < %s ORDER BY created_at LIMIT 5000",
                $cutoff
            ));
            $deleted += $batch;
        } while ($batch === 5000 && time() - $started < $budget);
        $done = $batch < 5000;

        if ($done) {
            // Days entirely before the cutoff (a cutoff inside a day keeps its counter).
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$wpdb->prefix}cmp_consent_stats WHERE day < DATE(%s)",
                $cutoff
            ));
            // Payloads nobody references any more (kept while a newer row uses them).
            $wpdb->query($wpdb->prepare(
                "DELETE p FROM {$wpdb->prefix}cmp_consent_payloads p
                 WHERE p.created_at < %s
                   AND NOT EXISTS (SELECT 1 FROM {$wpdb->prefix}cmp_consent_log l WHERE l.payload_id = p.id)",
                $cutoff
            ));
        }

        return array('deleted' => $deleted, 'done' => $done);
    }

    /**
     * Continues a manual "delete older than" in the background (single event,
     * rescheduled until everything before the cutoff is gone).
     */
    public static function continue_purge_before($cutoff) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2} 00:00:00$/', (string) $cutoff)) {
            return;
        }
        if (!self::purge_before($cutoff)['done']) {
            wp_schedule_single_event(time() + MINUTE_IN_SECONDS, self::PURGE_BEFORE_HOOK, array($cutoff));
        }
    }

    /**
     * 'weekly' (default), 'daily' or 'off'.
     */
    public static function frequency() {
        $settings = get_option('focmp_settings', array());
        $value = $settings['gvl_auto_update'] ?? 'weekly';
        return in_array($value, array('weekly', 'daily', 'off'), true) ? $value : 'weekly';
    }

    /**
     * Keeps the cron event in line with the setting (idempotent, cheap).
     */
    public static function ensure_scheduled() {
        if (!wp_next_scheduled(self::PURGE_HOOK)) {
            wp_schedule_event(time() + 2 * HOUR_IN_SECONDS, 'daily', self::PURGE_HOOK);
        }

        $frequency = self::frequency();
        $next = wp_next_scheduled(self::HOOK);

        if ($frequency === 'off') {
            if ($next) {
                wp_clear_scheduled_hook(self::HOOK);
            }
            return;
        }
        if ($next && wp_get_schedule(self::HOOK) === $frequency) {
            return;
        }
        wp_clear_scheduled_hook(self::HOOK);
        wp_schedule_event(time() + HOUR_IN_SECONDS, $frequency, self::HOOK);
    }

    public static function unschedule() {
        wp_clear_scheduled_hook(self::HOOK);
        wp_clear_scheduled_hook(self::PURGE_HOOK);
        wp_clear_scheduled_hook(self::PURGE_BEFORE_HOOK);
    }

    /**
     * Imports the GVL, the already imported purpose translations and the Google
     * ATP list. Returns and stores the outcome.
     */
    public static function run() {
        global $wpdb;

        $settings = get_option('focmp_settings', array());
        $gvl_url = !empty($settings['gvl_url']) ? $settings['gvl_url'] : FOCMP_TCF::GVL_URL;
        $importer = new FOCMP_Importer();

        $result = $importer->import_iab_gvl($gvl_url);
        $status = array('time' => time(), 'source' => 'cron');

        if (is_wp_error($result)) {
            $status['ok'] = false;
            $status['error'] = $result->get_error_message();
        } else {
            $status['ok'] = true;
            $status['vendors'] = (int) $result['vendors'];
            $status['deactivated'] = (int) ($result['deactivated'] ?? 0);

            // Refresh the translations that were imported before (purposes-xx.json
            // lives next to vendor-list.json).
            $base = trailingslashit(dirname($gvl_url));
            $languages = $wpdb->get_col("SELECT DISTINCT language FROM {$wpdb->prefix}cmp_purposes_iab WHERE language <> 'en'");
            $status['translations'] = array();
            foreach ((array) $languages as $lang) {
                if (!preg_match('/^[a-z]{2}(-[a-z]{2,4})?$/', $lang)) {
                    continue;
                }
                $translation = $importer->import_iab_purposes_lang($base . 'purposes-' . $lang . '.json', $lang);
                $status['translations'][$lang] = is_wp_error($translation) ? $translation->get_error_message() : 'ok';
            }
        }

        if (!empty($settings['google_atp_url'])) {
            $atp = $importer->import_google_atp($settings['google_atp_url']);
            $status['atp'] = is_wp_error($atp) ? $atp->get_error_message() : (int) ($atp['providers'] ?? 0);
        }

        self::record($status);
        return $status;
    }

    /**
     * Stores the outcome of an update (also used by the manual import).
     */
    public static function record(array $status) {
        update_option(self::STATUS_OPTION, $status, false);
    }

    public static function status() {
        $status = get_option(self::STATUS_OPTION, array());
        return is_array($status) ? $status : array();
    }

    /**
     * No successful update within STALE_DAYS (or never).
     */
    public static function is_stale() {
        $status = self::status();
        if (empty($status['ok']) || empty($status['time'])) {
            return true;
        }
        return (time() - (int) $status['time']) > self::STALE_DAYS * DAY_IN_SECONDS;
    }
}

/**
 * Human-readable status of the last GVL update, for admin screens.
 */
function focmp_gvl_status_text() {
    $status = FOCMP_Scheduler::status();
    if (empty($status['time'])) {
        return __('No GVL update has been recorded yet.', 'fast-open-cmp');
    }
    $when = wp_date(get_option('date_format') . ' H:i', (int) $status['time']);
    if (empty($status['ok'])) {
        /* translators: 1: date and time, 2: error message */
        return sprintf(__('Last GVL update failed on %1$s: %2$s', 'fast-open-cmp'), $when, $status['error'] ?? '');
    }
    /* translators: 1: date and time, 2: number of vendors, 3: number of vendors switched off */
    return sprintf(
        __('Last GVL update: %1$s — %2$d vendors, %3$d switched off (deleted or no longer listed).', 'fast-open-cmp'),
        $when,
        (int) ($status['vendors'] ?? 0),
        (int) ($status['deactivated'] ?? 0)
    );
}
