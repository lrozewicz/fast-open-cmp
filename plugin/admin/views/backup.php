<?php
if (!defined('ABSPATH')) exit;

$focmp_download_url = wp_nonce_url(admin_url('admin.php?page=focmp-backup&download=1'), 'focmp_backup_download');
?>
<div class="wrap focmp-wrap">
    <h1><?php esc_html_e('Backup & migration', 'fast-open-cmp'); ?></h1>
    <p class="description" style="max-width: 760px;">
        <?php esc_html_e('The whole plugin in one file: companies, vendors and vendor lists, the imported IAB vendor list and translations, templates, domains (with their integration keys), reconsents and settings — optionally with the consent log. Use it as a backup or to move the CMP to another WordPress.', 'fast-open-cmp'); ?>
    </p>

    <?php if (is_array($notice) && isset($notice['error'])): ?>
        <div class="notice notice-error"><p><?php echo esc_html($notice['error']); ?></p></div>
    <?php elseif (is_array($notice)): ?>
        <div class="notice notice-success">
            <p><strong><?php esc_html_e('Backup restored.', 'fast-open-cmp'); ?></strong>
                <?php echo $notice['consents']
                    ? esc_html__('The consent log was replaced with the one from the file.', 'fast-open-cmp')
                    : esc_html__('The consent log was not changed.', 'fast-open-cmp'); ?></p>
            <p><?php
                $focmp_parts = array();
                foreach ($notice['tables'] as $focmp_table => $focmp_count) {
                    $focmp_parts[] = $focmp_table . ': ' . number_format_i18n($focmp_count);
                }
                echo esc_html(implode(' · ', $focmp_parts));
            ?></p>
        </div>
    <?php endif; ?>

    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(340px, 1fr)); gap: 16px; max-width: 1100px;">
        <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" style="background: #fff; border: 1px solid #dcdcde; padding: 12px 16px;">
            <h2 style="margin-top: 0;"><?php esc_html_e('Download a backup', 'fast-open-cmp'); ?></h2>
            <input type="hidden" name="page" value="focmp-backup">
            <input type="hidden" name="download" value="1">
            <?php wp_nonce_field('focmp_backup_download', '_wpnonce', false); ?>
            <p>
                <label><input type="checkbox" name="consents" value="1">
                    <?php esc_html_e('Include the consent log', 'fast-open-cmp'); ?></label><br>
                <span class="description"><?php
                    /* translators: 1: number of entries, 2: size in the database */
                    printf(esc_html__('%1$s entries, about %2$s in the database. Large logs make large files — they can also be exported separately in Consent log → Tools.', 'fast-open-cmp'),
                        esc_html(number_format_i18n($consent_size['rows'])), esc_html(size_format($consent_size['bytes'])));
                ?></span>
            </p>
            <?php if (FOCMP_Download_Stream::gzip_available()): ?>
                <p><label><input type="checkbox" name="gzip" value="1" checked> <?php esc_html_e('Compress (.gz)', 'fast-open-cmp'); ?></label></p>
            <?php endif; ?>
            <p class="description"><?php esc_html_e('The file contains your whole configuration and, with the consent log, pseudonymous personal data — store it securely.', 'fast-open-cmp'); ?></p>
            <p><button type="submit" class="button button-primary"><?php esc_html_e('Download', 'fast-open-cmp'); ?></button></p>
        </form>

        <form method="post" enctype="multipart/form-data" style="background: #fff; border: 1px solid #dcdcde; padding: 12px 16px;"
              onsubmit="return confirm(<?php echo esc_attr(wp_json_encode(__('Replace the current configuration with the backup?', 'fast-open-cmp'))); ?>);">
            <h2 style="margin-top: 0;"><?php esc_html_e('Restore a backup', 'fast-open-cmp'); ?></h2>
            <?php wp_nonce_field('focmp_backup_restore'); ?>
            <p><input type="file" name="backup_file" required accept=".jsonl,.gz,application/gzip"></p>
            <p>
                <label><input type="checkbox" name="restore_consents" value="1">
                    <?php esc_html_e('Also restore the consent log (if the file contains it) — replaces the current log', 'fast-open-cmp'); ?></label><br>
                <span class="description"><?php esc_html_e('Without it the current log stays. Its entries keep their domain IDs, so they belong to the restored domains with the same IDs.', 'fast-open-cmp'); ?></span>
            </p>
            <p class="description">
                <?php esc_html_e('The configuration is replaced in one transaction: on any error nothing changes. Download a backup of the current state first.', 'fast-open-cmp'); ?>
                <?php if (!current_user_can('unfiltered_html')): ?>
                    <br><?php esc_html_e('Without the unfiltered_html capability texts are sanitised, custom CSS and the HEAD code are not restored.', 'fast-open-cmp'); ?>
                <?php endif; ?>
                <br><?php
                /* translators: %s: maximum upload size */
                printf(esc_html__('Maximum file size: %s.', 'fast-open-cmp'), esc_html(size_format(wp_max_upload_size()))); ?>
            </p>
            <p><label><input type="checkbox" name="confirm_replace" value="1" required> <?php esc_html_e('I understand that the current configuration will be replaced', 'fast-open-cmp'); ?></label></p>
            <p><button type="submit" name="focmp_restore" value="1" class="button"><?php esc_html_e('Restore', 'fast-open-cmp'); ?></button></p>
        </form>
    </div>
</div>
