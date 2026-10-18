<?php
if (!defined('ABSPATH')) exit;

$action_labels = array(
    'accept-all' => __('Accept all', 'fast-open-cmp'),
    'reject-all' => __('Reject all', 'fast-open-cmp'),
    'save' => __('Saved choices', 'fast-open-cmp'),
    'close-reject' => __('Closed (rejected)', 'fast-open-cmp'),
);
$stats_total = 0;
foreach ($stats as $stat) {
    $stats_total += (int) $stat->total;
}
?>
<div class="wrap focmp-wrap">
    <h1>
        <?php esc_html_e('Consent log', 'fast-open-cmp'); ?>
        <a href="<?php echo esc_url($export_url); ?>" class="page-title-action"><?php esc_html_e('Export CSV', 'fast-open-cmp'); ?></a>
    </h1>
    <p class="description" style="max-width: 760px;">
        <?php esc_html_e('One entry per decision made in the consent box — the proof of consent (GDPR Art. 7(1)). Entries are pseudonymous: the consent ID is a random value stored in the visitor\'s cookie and shown to them in the settings layer, IP addresses are anonymised and page URLs are stored without query strings.', 'fast-open-cmp'); ?>
    </p>

    <?php if ($tools_notice): ?>
        <div class="notice notice-<?php echo esc_attr($tools_notice[0]); ?> is-dismissible"><p><?php echo esc_html($tools_notice[1]); ?></p></div>
    <?php endif; ?>

    <?php if (!FOCMP_API::consent_log_enabled()): ?>
        <div class="notice notice-warning inline"><p><?php esc_html_e('Consent logging is switched off in the settings — no new entries are recorded.', 'fast-open-cmp'); ?></p></div>
    <?php endif; ?>

    <h2><?php esc_html_e('Last 30 days', 'fast-open-cmp'); ?></h2>
    <div class="focmp-stats" style="display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 16px;">
        <?php foreach ($action_labels as $action => $label):
            $count = isset($stats[$action]) ? (int) $stats[$action]->total : 0;
            $share = $stats_total ? round($count * 100 / $stats_total) : 0; ?>
            <div class="focmp-stat" style="background: #fff; border: 1px solid #dcdcde; padding: 12px 16px; min-width: 160px;">
                <div style="font-size: 22px; font-weight: 600;"><?php echo esc_html(number_format_i18n($count)); ?></div>
                <div><?php echo esc_html($label); ?> · <?php echo esc_html($share); ?>%</div>
            </div>
        <?php endforeach; ?>
    </div>

    <details id="focmp-consent-tools" <?php echo $tools_notice ? 'open' : ''; ?> style="margin-bottom: 16px;">
    <summary style="cursor: pointer; font-size: 1.3em; font-weight: 600; margin: 8px 0;"><?php esc_html_e('Tools', 'fast-open-cmp'); ?></summary>
    <div class="focmp-consent-tools" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 16px; max-width: 1100px;">
        <form method="get" class="focmp-tool-card" style="background: #fff; border: 1px solid #dcdcde; padding: 12px 16px;">
            <h3 style="margin-top: 0;"><?php esc_html_e('Export entries', 'fast-open-cmp'); ?></h3>
            <input type="hidden" name="page" value="focmp-consent-log">
            <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($export_nonce); ?>">
            <p>
                <label><?php esc_html_e('From', 'fast-open-cmp'); ?> <input type="date" name="from"></label>
                <label><?php esc_html_e('To', 'fast-open-cmp'); ?> <input type="date" name="to"></label>
            </p>
            <p>
                <label><?php esc_html_e('Domain', 'fast-open-cmp'); ?>
                    <select name="domain_id">
                        <option value=""><?php esc_html_e('All', 'fast-open-cmp'); ?></option>
                        <?php foreach ($domains as $d): ?>
                            <option value="<?php echo esc_attr($d->id); ?>"><?php echo esc_html($d->domain); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </p>
            <p>
                <label><input type="radio" name="export" value="csv" checked> <?php esc_html_e('CSV (spreadsheets)', 'fast-open-cmp'); ?></label><br>
                <label><input type="radio" name="export" value="jsonl"> <?php esc_html_e('JSON Lines (import into another installation)', 'fast-open-cmp'); ?></label>
            </p>
            <?php if (FOCMP_Download_Stream::gzip_available()): ?>
                <p><label><input type="checkbox" name="gzip" value="1"> <?php esc_html_e('Compress (.gz)', 'fast-open-cmp'); ?></label></p>
            <?php endif; ?>
            <p class="description"><?php esc_html_e('Empty dates = from the first / up to the last entry. Dates are UTC and include both days.', 'fast-open-cmp'); ?></p>
            <p><button type="submit" class="button button-primary"><?php esc_html_e('Download', 'fast-open-cmp'); ?></button></p>
        </form>

        <form method="post" enctype="multipart/form-data" class="focmp-tool-card" style="background: #fff; border: 1px solid #dcdcde; padding: 12px 16px;">
            <h3 style="margin-top: 0;"><?php esc_html_e('Import entries', 'fast-open-cmp'); ?></h3>
            <?php wp_nonce_field('focmp_consent_tools'); ?>
            <input type="hidden" name="focmp_consent_tool" value="import">
            <p><input type="file" name="consent_file" required accept=".csv,.jsonl,.ndjson,.json,.gz,text/csv,application/gzip"></p>
            <p class="description">
                <?php esc_html_e('A file exported here or by another installation of this plugin (CSV or JSON Lines, optionally .gz). Entries are matched to domains by name — add the domains first. Entries already in the log are skipped, so the same file can be imported again safely. IP addresses are anonymised again.', 'fast-open-cmp'); ?>
                <br><?php
                /* translators: %s: maximum upload size */
                printf(esc_html__('Maximum file size: %s.', 'fast-open-cmp'), esc_html(size_format(wp_max_upload_size()))); ?>
            </p>
            <p><button type="submit" class="button"><?php esc_html_e('Import', 'fast-open-cmp'); ?></button></p>
        </form>

        <form method="post" class="focmp-tool-card" style="background: #fff; border: 1px solid #dcdcde; padding: 12px 16px;"
              onsubmit="return confirm(<?php echo esc_attr(wp_json_encode(__('Delete all entries recorded before the chosen date? This cannot be undone.', 'fast-open-cmp'))); ?>);">
            <h3 style="margin-top: 0;"><?php esc_html_e('Delete older entries', 'fast-open-cmp'); ?></h3>
            <?php wp_nonce_field('focmp_consent_tools'); ?>
            <input type="hidden" name="focmp_consent_tool" value="delete_before">
            <p>
                <label><?php esc_html_e('Delete entries recorded before', 'fast-open-cmp'); ?>
                    <input type="date" name="delete_before" required max="<?php echo esc_attr(gmdate('Y-m-d', time() + DAY_IN_SECONDS)); ?>">
                </label>
            </p>
            <p class="description">
                <?php esc_html_e('Dates are UTC; the chosen day itself is kept. Export the entries first if you may still need them as proof of consent.', 'fast-open-cmp'); ?>
                <?php if ($oldest): ?>
                    <br><?php
                    /* translators: %s: date and time of the oldest entry */
                    printf(esc_html__('Oldest entry: %s', 'fast-open-cmp'), esc_html($oldest)); ?>
                <?php endif; ?>
            </p>
            <p><label><input type="checkbox" name="confirm_delete" value="1" required> <?php esc_html_e('I understand that deleted entries cannot be restored', 'fast-open-cmp'); ?></label></p>
            <p><button type="submit" class="button button-link-delete"><?php esc_html_e('Delete entries', 'fast-open-cmp'); ?></button></p>
            <p class="description"><?php esc_html_e('Automatic deletion (days or a fixed date) is configured in Settings → Consent log.', 'fast-open-cmp'); ?></p>
        </form>
    </div>
    </details>

    <form method="get" class="focmp-log-filters" style="margin-bottom: 12px;">
        <input type="hidden" name="page" value="focmp-consent-log">
        <label><?php esc_html_e('Consent ID', 'fast-open-cmp'); ?>
            <input type="search" name="consent_id" value="<?php echo esc_attr($filters['consent_id']); ?>" class="regular-text" placeholder="xxxxxxxx-xxxx-…">
        </label>
        <label><?php esc_html_e('Domain', 'fast-open-cmp'); ?>
            <select name="domain_id">
                <option value=""><?php esc_html_e('All', 'fast-open-cmp'); ?></option>
                <?php foreach ($domains as $d): ?>
                    <option value="<?php echo esc_attr($d->id); ?>" <?php selected($filters['domain_id'], (int) $d->id); ?>><?php echo esc_html($d->domain); ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label><?php esc_html_e('Decision', 'fast-open-cmp'); ?>
            <select name="decision">
                <option value=""><?php esc_html_e('All', 'fast-open-cmp'); ?></option>
                <?php foreach ($action_labels as $action => $label): ?>
                    <option value="<?php echo esc_attr($action); ?>" <?php selected($filters['action'], $action); ?>><?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label><?php esc_html_e('From', 'fast-open-cmp'); ?> <input type="date" name="from" value="<?php echo esc_attr($filters['from']); ?>"></label>
        <label><?php esc_html_e('To', 'fast-open-cmp'); ?> <input type="date" name="to" value="<?php echo esc_attr($filters['to']); ?>"></label>
        <button type="submit" class="button"><?php esc_html_e('Filter', 'fast-open-cmp'); ?></button>
    </form>

    <p><?php
        /* translators: %s: number of entries */
        printf(esc_html__('%s entries', 'fast-open-cmp'), esc_html(number_format_i18n($total)));
    ?></p>

    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th style="width: 150px;"><?php esc_html_e('Date (UTC)', 'fast-open-cmp'); ?></th>
                <th><?php esc_html_e('Consent ID', 'fast-open-cmp'); ?></th>
                <th><?php esc_html_e('Domain', 'fast-open-cmp'); ?></th>
                <th><?php esc_html_e('Decision', 'fast-open-cmp'); ?></th>
                <th><?php esc_html_e('Mode', 'fast-open-cmp'); ?></th>
                <th><?php esc_html_e('Versions', 'fast-open-cmp'); ?></th>
                <th><?php esc_html_e('Page', 'fast-open-cmp'); ?></th>
                <th style="width: 120px;"><?php esc_html_e('Export', 'fast-open-cmp'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($rows)): ?>
                <tr><td colspan="8"><?php esc_html_e('No entries.', 'fast-open-cmp'); ?></td></tr>
            <?php else: foreach ($rows as $row): ?>
                <tr>
                    <td><?php echo esc_html($row->created_at); ?></td>
                    <td><code><?php echo esc_html($row->consent_id); ?></code></td>
                    <td><?php echo esc_html($row->domain ?: '#' . $row->domain_id); ?><?php echo $row->env === 'test' ? ' <em>(test)</em>' : ''; ?></td>
                    <td><?php echo esc_html($action_labels[$row->action] ?? $row->action); ?></td>
                    <td><?php echo esc_html($row->mode); ?></td>
                    <td><?php
                        /* translators: 1: TCF policy version, 2: GVL version, 3: template id */
                        printf(esc_html__('policy %1$s · GVL %2$s · template %3$s', 'fast-open-cmp'), esc_html($row->policy_version ?: '–'), esc_html($row->gvl_version ?: '–'), esc_html($row->template_id ?: '–'));
                    ?></td>
                    <td style="word-break: break-all;"><?php echo esc_html($row->page_url); ?></td>
                    <td>
                        <a class="button button-small" href="<?php echo esc_url(add_query_arg(array(
                            'page' => 'focmp-consent-log',
                            'export' => 'consent',
                            'consent_id' => $row->consent_id,
                            '_wpnonce' => $person_export_nonce,
                        ), admin_url('admin.php'))); ?>" title="<?php esc_attr_e('All decisions of this person (JSON) — for an access or portability request', 'fast-open-cmp'); ?>"><?php esc_html_e('Export consents', 'fast-open-cmp'); ?></a>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>

    <?php if ($pages > 1): ?>
        <div class="tablenav"><div class="tablenav-pages">
            <?php echo wp_kses_post(paginate_links(array(
                'base' => add_query_arg('paged', '%#%'),
                'format' => '',
                'current' => $page,
                'total' => $pages,
            ))); ?>
        </div></div>
    <?php endif; ?>


</div>
