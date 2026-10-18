<?php
/**
 * Backup & migration screen: download the whole configuration (optionally with
 * the consent log) and restore it. Composed into FOCMP_Admin via `use`.
 */

if (!defined('ABSPATH')) {
    exit;
}

trait FOCMP_Admin_Backup {

    public function render_backup() {
        $consent_size = FOCMP_Backup::consent_log_size();
        $notice = get_transient('focmp_backup_result_' . get_current_user_id());
        if ($notice && isset($_GET['focmp_msg'])) {
            delete_transient('focmp_backup_result_' . get_current_user_id());
        } else {
            $notice = null;
        }
        include FOCMP_PLUGIN_DIR . 'admin/views/backup.php';
    }

    /**
     * Download (GET) and restore (POST) — admin_init, before any output.
     */
    public function handle_backup() {
        if (($_GET['page'] ?? '') !== 'focmp-backup') {
            return;
        }
        if (isset($_GET['download'])) {
            if (!current_user_can('manage_options') || !check_admin_referer('focmp_backup_download')) {
                wp_die(esc_html__('You are not allowed to download a backup.', 'fast-open-cmp'), 403);
            }
            FOCMP_Backup::stream_export(!empty($_GET['consents']), !empty($_GET['gzip']));
        }
        if (isset($_POST['focmp_restore'])) {
            if (!current_user_can('manage_options') || !check_admin_referer('focmp_backup_restore')) {
                wp_die(esc_html__('You are not allowed to restore a backup.', 'fast-open-cmp'), 403);
            }
            $this->restore_backup();
        }
    }

    private function restore_backup() {
        $back = admin_url('admin.php?page=focmp-backup&focmp_msg=1');
        $key = 'focmp_backup_result_' . get_current_user_id();
        $file = $_FILES['backup_file'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- checked below, read as a file only

        if (empty($_POST['confirm_replace'])) {
            $result = new WP_Error('confirm', __('Confirm that the current configuration will be replaced.', 'fast-open-cmp'));
        } elseif (!$file || !empty($file['error']) || empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            $code = $file ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;
            $result = in_array($code, array(UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE), true)
                /* translators: %s: maximum upload size */
                ? new WP_Error('size', sprintf(__('The file is larger than the server accepts (%s).', 'fast-open-cmp'), size_format(wp_max_upload_size())))
                : new WP_Error('upload', __('No file was uploaded.', 'fast-open-cmp'));
        } else {
            $result = FOCMP_Backup::import_file($file['tmp_name'], !empty($_POST['restore_consents']), current_user_can('unfiltered_html'));
        }

        set_transient($key, is_wp_error($result) ? array('error' => $result->get_error_message()) : $result, HOUR_IN_SECONDS);
        wp_safe_redirect($back);
        exit;
    }
}
