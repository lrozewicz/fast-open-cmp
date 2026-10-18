<?php
if (!defined('ABSPATH')) exit;
?>
<div class="wrap focmp-wrap">
    <h1><?php esc_html_e('Fast Open CMP settings', 'fast-open-cmp'); ?></h1>

    <?php if (isset($_GET['message']) && $_GET['message'] === 'saved'): ?>
        <div class="notice notice-success is-dismissible">
            <p><?php esc_html_e('Settings saved successfully', 'fast-open-cmp'); ?></p>
        </div>
    <?php endif; ?>

    <form method="post" action="">
        <?php wp_nonce_field('focmp_settings'); ?>

        <h2><?php esc_html_e('CMP configuration', 'fast-open-cmp'); ?></h2>
        <table class="form-table">
            <tr>
                <th><label for="cmp_id"><?php esc_html_e('CMP ID', 'fast-open-cmp'); ?></label></th>
                <td>
                    <input type="number" name="cmp_id" id="cmp_id" class="small-text" min="0"
                           value="<?php echo esc_attr($settings['cmp_id'] ?? 0); ?>">
                    <p class="description"><?php esc_html_e('The CMP ID IAB Europe assigned to you. Without your own ID the plugin uses 28 as a development placeholder — that ID belongs to another registered CMP, so TC Strings must not be served in production with it.', 'fast-open-cmp'); ?>
                        <a href="https://iabeurope.eu/cmp-list/" target="_blank" rel="noopener noreferrer"><?php esc_html_e('IAB CMP list', 'fast-open-cmp'); ?></a></p>
                </td>
            </tr>
            <tr>
                <th><label for="cmp_version"><?php esc_html_e('CMP version', 'fast-open-cmp'); ?></label></th>
                <td>
                    <input type="number" name="cmp_version" id="cmp_version" class="small-text" min="0" max="4095"
                           value="<?php echo esc_attr((int) ($settings['cmp_version'] ?? 0) > 1 ? (int) $settings['cmp_version'] : ''); ?>"
                           placeholder="<?php echo esc_attr(FOCMP_TCF::cmp_version()); ?>">
                    <p class="description"><?php
                        printf(
                            /* translators: %d: CMP version derived from the plugin version */
                            esc_html__('Leave empty to derive it from the plugin version (now %d) — TCF expects it to change with every release.', 'fast-open-cmp'),
                            (int) FOCMP_TCF::version_to_cmp_version(FOCMP_VERSION)
                        );
                    ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="publisher_country_code"><?php esc_html_e('Publisher country code', 'fast-open-cmp'); ?></label></th>
                <td>
                    <input type="text" name="publisher_country_code" id="publisher_country_code" class="small-text"
                           value="<?php echo esc_attr($settings['publisher_country_code'] ?? 'PL'); ?>" maxlength="2">
                    <p class="description"><?php esc_html_e('ISO 3166-1 alpha-2 (e.g. PL, DE, FR)', 'fast-open-cmp'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="default_language"><?php esc_html_e('Default language', 'fast-open-cmp'); ?></label></th>
                <td>
                    <select name="default_language" id="default_language">
                        <option value="pl" <?php selected($settings['default_language'] ?? 'pl', 'pl'); ?>>Polski</option>
                        <option value="en" <?php selected($settings['default_language'] ?? '', 'en'); ?>>English</option>
                        <option value="de" <?php selected($settings['default_language'] ?? '', 'de'); ?>>Deutsch</option>
                        <option value="fr" <?php selected($settings['default_language'] ?? '', 'fr'); ?>>Francais</option>
                    </select>
                </td>
            </tr>
        </table>

        <h2><?php esc_html_e('HEAD script (frontend)', 'fast-open-cmp'); ?></h2>
        <table class="form-table">
            <tr>
                <th><label for="head_scripts"><?php esc_html_e('Code in the HEAD section', 'fast-open-cmp'); ?></label></th>
                <td>
                    <textarea name="head_scripts" id="head_scripts" class="large-text code" rows="5" style="font-family: monospace;" <?php disabled(!current_user_can('unfiltered_html')); ?>><?php echo esc_textarea($settings['head_scripts'] ?? ''); ?></textarea>
                    <p class="description">
                        <?php esc_html_e('The code is injected high in the &lt;head&gt; section on all frontend pages (except the admin panel).', 'fast-open-cmp'); ?><br>
                        <?php esc_html_e('Allowed tags: &lt;script&gt;, &lt;link&gt;, &lt;meta&gt;. Use this field to add the CMP SDK script on this WordPress installation.', 'fast-open-cmp'); ?><br>
                        <?php esc_html_e('Example:', 'fast-open-cmp'); ?> <code>&lt;script src="<?php echo esc_html(add_query_arg('key', 'KEY', rest_url('fast-open-cmp/v1/sdk'))); ?>"&gt;&lt;/script&gt;</code> <?php esc_html_e('(the integration code of a domain, Domains tab)', 'fast-open-cmp'); ?><br>
                        <?php esc_html_e('Changing this field requires the unfiltered_html capability.', 'fast-open-cmp'); ?>
                    </p>
                </td>
            </tr>
        </table>

        <h2><?php esc_html_e('Consent log', 'fast-open-cmp'); ?></h2>
        <table class="form-table">
            <tr>
                <th><?php esc_html_e('Record decisions', 'fast-open-cmp'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="consent_log" value="1" <?php checked(!isset($settings['consent_log']) || !empty($settings['consent_log'])); ?>>
                        <?php esc_html_e('Store every consent decision (proof of consent, GDPR Art. 7(1))', 'fast-open-cmp'); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th><label for="consent_log_retention_days"><?php esc_html_e('Keep entries for (days)', 'fast-open-cmp'); ?></label></th>
                <td>
                    <input type="number" name="consent_log_retention_days" id="consent_log_retention_days" class="small-text" min="0"
                           value="<?php echo esc_attr($settings['consent_log_retention_days'] ?? 1095); ?>">
                    <p class="description"><?php esc_html_e('Older entries are deleted daily (0 = keep forever). 1095 days = 3 years, the usual limitation period for claims.', 'fast-open-cmp'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="consent_log_delete_before"><?php esc_html_e('Also delete entries recorded before', 'fast-open-cmp'); ?></label></th>
                <td>
                    <input type="date" name="consent_log_delete_before" id="consent_log_delete_before"
                           value="<?php echo esc_attr($settings['consent_log_delete_before'] ?? ''); ?>">
                    <p class="description"><?php esc_html_e('Optional fixed date (UTC). The daily clean-up keeps deleting everything recorded before it, imported entries included. With both settings, whichever deletes more applies. Leave empty to use only the number of days.', 'fast-open-cmp'); ?></p>
                </td>
            </tr>
        </table>

        <h2><?php esc_html_e('Access', 'fast-open-cmp'); ?></h2>
        <table class="form-table">
            <tr>
                <th><?php esc_html_e('Origin check', 'fast-open-cmp'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="origin_check" value="1" <?php checked(!isset($settings['origin_check']) || !empty($settings['origin_check'])); ?>>
                        <?php esc_html_e('Serve a domain\'s consent box and accept its consent log entries only on that domain (and its subdomains)', 'fast-open-cmp'); ?>
                    </label>
                    <p class="description"><?php esc_html_e('Compares the page address the browser sends (Origin / Referer) with the domain name. Requests without that information are accepted. This WordPress site is always allowed; more hosts can be added with the focmp_allowed_hosts filter.', 'fast-open-cmp'); ?></p>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e('Numeric domain IDs', 'fast-open-cmp'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="legacy_domain_ids" value="1" <?php checked(!isset($settings['legacy_domain_ids']) || !empty($settings['legacy_domain_ids'])); ?>>
                        <?php esc_html_e('Accept integration codes with domain_id (older snippets)', 'fast-open-cmp'); ?>
                    </label>
                    <p class="description"><?php esc_html_e('Integration codes now use a random key (key=…). Sequential IDs reveal how many and which sites use this CMP. Switch this off once every site uses the new code.', 'fast-open-cmp'); ?></p>
                </td>
            </tr>
        </table>

        <h2><?php esc_html_e('Uninstall', 'fast-open-cmp'); ?></h2>
        <table class="form-table">
            <tr>
                <th><?php esc_html_e('Data on deletion', 'fast-open-cmp'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="delete_data_on_uninstall" value="1" <?php checked(!empty($settings['delete_data_on_uninstall'])); ?>>
                        <?php esc_html_e('Delete all plugin data (tables, templates, vendor lists, settings) when the plugin is deleted', 'fast-open-cmp'); ?>
                    </label>
                    <p class="description"><?php esc_html_e('Off by default, so deleting and reinstalling the plugin keeps your configuration.', 'fast-open-cmp'); ?></p>
                </td>
            </tr>
        </table>

        <h2><?php esc_html_e('Cache', 'fast-open-cmp'); ?></h2>
        <table class="form-table">
            <tr>
                <th><label for="cache_ttl"><?php esc_html_e('Cache TTL (seconds)', 'fast-open-cmp'); ?></label></th>
                <td>
                    <input type="number" name="cache_ttl" id="cache_ttl" class="small-text"
                           value="<?php echo esc_attr($settings['cache_ttl'] ?? 60); ?>">
                    <p class="description"><?php esc_html_e('Cache lifetime for the API endpoints', 'fast-open-cmp'); ?></p>
                </td>
            </tr>
        </table>

        <h2><?php esc_html_e('Import URLs', 'fast-open-cmp'); ?></h2>
        <table class="form-table">
            <tr>
                <th><label for="gvl_auto_update"><?php esc_html_e('Automatic GVL update', 'fast-open-cmp'); ?></label></th>
                <td>
                    <?php $focmp_freq = FOCMP_Scheduler::frequency(); ?>
                    <select name="gvl_auto_update" id="gvl_auto_update">
                        <option value="weekly" <?php selected($focmp_freq, 'weekly'); ?>><?php esc_html_e('Weekly (recommended)', 'fast-open-cmp'); ?></option>
                        <option value="daily" <?php selected($focmp_freq, 'daily'); ?>><?php esc_html_e('Daily', 'fast-open-cmp'); ?></option>
                        <option value="off" <?php selected($focmp_freq, 'off'); ?>><?php esc_html_e('Off (manual import only)', 'fast-open-cmp'); ?></option>
                    </select>
                    <p class="description"><?php echo esc_html(focmp_gvl_status_text()); ?>
                        <?php
                        $focmp_next = wp_next_scheduled(FOCMP_Scheduler::HOOK);
                        if ($focmp_next) {
                            /* translators: %s: date and time of the next scheduled update */
                            echo ' ' . esc_html(sprintf(__('Next run: %s.', 'fast-open-cmp'), wp_date(get_option('date_format') . ' H:i', $focmp_next)));
                        }
                        ?>
                    </p>
                    <p class="description"><?php esc_html_e('Uses WP-Cron: on low-traffic sites trigger it from a real cron job (wp-cron.php) so it runs on time.', 'fast-open-cmp'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="gvl_url"><?php esc_html_e('IAB GVL URL', 'fast-open-cmp'); ?></label></th>
                <td>
                    <input type="url" name="gvl_url" id="gvl_url" class="large-text"
                           value="<?php echo esc_attr($settings['gvl_url'] ?? FOCMP_TCF::GVL_URL); ?>">
                </td>
            </tr>
            <tr>
                <th><label for="google_atp_url"><?php esc_html_e('Google ATP URL', 'fast-open-cmp'); ?></label></th>
                <td>
                    <input type="url" name="google_atp_url" id="google_atp_url" class="large-text"
                           value="<?php echo esc_attr($settings['google_atp_url'] ?? 'https://storage.googleapis.com/tcfac/additional-consent-providers.csv'); ?>">
                </td>
            </tr>
        </table>

        <h2><?php esc_html_e('Actions', 'fast-open-cmp'); ?></h2>
        <table class="form-table">
            <tr>
                <th>
                    <?php esc_html_e('Clear cache', 'fast-open-cmp'); ?>
                    <span class="dashicons dashicons-editor-help" tabindex="0" style="cursor: help; color: #787c82;"
                          title="<?php esc_attr_e('Clears Fast Open CMP server-side caches (WordPress transients): the built CMP config for each domain, the IAB vendor list, and imported purpose translations. It does NOT touch visitors\' cookies or saved consents. Use it after editing templates or vendor lists if changes are not visible on the frontend.', 'fast-open-cmp'); ?>"></span>
                </th>
                <td>
                    <button type="button" class="button" id="focmp-clear-cache"><?php esc_html_e('Clear all cache', 'fast-open-cmp'); ?></button>
                    <span id="focmp-cache-status" style="margin-left: 10px;"></span>
                    <p class="description"><?php esc_html_e('Removes the plugin\'s cached API responses (config / vendor-list / purposes). Safe — they are rebuilt on the next request.', 'fast-open-cmp'); ?></p>
                </td>
            </tr>
        </table>

        <p class="submit">
            <button type="submit" name="focmp_save_settings" class="button button-primary"><?php esc_html_e('Save settings', 'fast-open-cmp'); ?></button>
        </p>
    </form>
</div>

<script>
jQuery(document).ready(function($) {
    $('#focmp-clear-cache').on('click', function() {
        var btn = $(this);
        var status = $('#focmp-cache-status');

        btn.prop('disabled', true);
        status.html('<span style="color: #666;"><?php esc_html_e('Clearing...', 'fast-open-cmp'); ?></span>');

        $.post(focmpAdmin.ajaxUrl, {
            action: 'focmp_clear_cache',
            nonce: focmpAdmin.nonce
        }).done(function(response) {
            btn.prop('disabled', false);
            if (response.success) {
                status.html('<span style="color: green;"><?php esc_html_e('Cache cleared!', 'fast-open-cmp'); ?></span>');
            } else {
                status.empty().append($('<span style="color: red;"></span>').text('<?php echo esc_js(__('Error:', 'fast-open-cmp')); ?> ' + (response.data ? response.data.message : '<?php echo esc_js(__('Unknown error', 'fast-open-cmp')); ?>')));
            }
            setTimeout(function() { status.html(''); }, 3000);
        }).fail(function(jqXHR, textStatus, errorThrown) {
            btn.prop('disabled', false);
            status.empty().append($('<span style="color: red;"></span>').text('<?php echo esc_js(__('Error:', 'fast-open-cmp')); ?> ' + textStatus));
            setTimeout(function() { status.html(''); }, 3000);
        });
    });
});
</script>
