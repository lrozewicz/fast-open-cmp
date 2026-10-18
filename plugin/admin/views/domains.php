<?php
if (!defined('ABSPATH')) exit;

$show_form = in_array($action, array('add', 'edit'));
?>
<div class="wrap focmp-wrap">
    <h1>
        <?php esc_html_e('Domains', 'fast-open-cmp'); ?>
        <?php if (!$show_form): ?>
            <a href="<?php echo esc_url(admin_url('admin.php?page=focmp-domains&action=add')); ?>" class="page-title-action"><?php esc_html_e('Add new', 'fast-open-cmp'); ?></a>
        <?php endif; ?>
    </h1>

    <?php if (isset($_GET['message'])): ?>
        <div class="notice notice-success is-dismissible">
            <p><?php
                $messages = array(
                    'saved' => __('Saved successfully', 'fast-open-cmp'),
                    'deleted' => __('Deleted successfully', 'fast-open-cmp'),
                );
                echo esc_html($messages[$_GET['message']] ?? '');
            ?></p>
        </div>
    <?php endif; ?>

    <?php if ($show_form): ?>
        <form method="post" action="">
            <?php wp_nonce_field('focmp_domain'); ?>
            <input type="hidden" name="domain_id" value="<?php echo esc_attr($domain->id ?? 0); ?>">

            <table class="form-table">
                <tr>
                    <th><label for="company_id"><?php esc_html_e('Company', 'fast-open-cmp'); ?></label></th>
                    <td>
                        <select name="company_id" id="company_id" required>
                            <option value=""><?php esc_html_e('-- Select a company --', 'fast-open-cmp'); ?></option>
                            <?php foreach ($companies as $c): ?>
                                <option value="<?php echo esc_attr($c->id); ?>" <?php selected($domain->company_id ?? '', $c->id); ?>>
                                    <?php echo esc_html($c->name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="domain_name"><?php esc_html_e('Domain', 'fast-open-cmp'); ?></label></th>
                    <td>
                        <input type="text" name="domain_name" id="domain_name" class="regular-text"
                               value="<?php echo esc_attr($domain->domain ?? ''); ?>" required
                               placeholder="example.com">
                        <p class="description"><?php esc_html_e('Host name without protocol (http/https). The consent box works on this host, www and all its subdomains; other sites embedding the code are refused (see Settings → Access).', 'fast-open-cmp'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="prod_template_id"><?php esc_html_e('PROD template', 'fast-open-cmp'); ?></label></th>
                    <td>
                        <select name="prod_template_id" id="prod_template_id">
                            <option value=""><?php esc_html_e('-- None --', 'fast-open-cmp'); ?></option>
                            <?php foreach ($templates as $t): ?>
                                <option value="<?php echo esc_attr($t->id); ?>" <?php selected($domain->prod_template_id ?? '', $t->id); ?>>
                                    <?php echo esc_html($t->name . ' (' . $t->language . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="test_template_id"><?php esc_html_e('TEST template', 'fast-open-cmp'); ?></label></th>
                    <td>
                        <select name="test_template_id" id="test_template_id">
                            <option value=""><?php esc_html_e('-- None --', 'fast-open-cmp'); ?></option>
                            <?php foreach ($templates as $t): ?>
                                <option value="<?php echo esc_attr($t->id); ?>" <?php selected($domain->test_template_id ?? '', $t->id); ?>>
                                    <?php echo esc_html($t->name . ' (' . $t->language . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
            </table>

            <p class="submit">
                <button type="submit" name="focmp_save_domain" class="button button-primary"><?php esc_html_e('Save', 'fast-open-cmp'); ?></button>
                <a href="<?php echo esc_url(admin_url('admin.php?page=focmp-domains')); ?>" class="button"><?php esc_html_e('Cancel', 'fast-open-cmp'); ?></a>
            </p>
        </form>
    <?php else: ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th style="width: 50px;"><?php esc_html_e('ID', 'fast-open-cmp'); ?></th>
                    <th><?php esc_html_e('Domain', 'fast-open-cmp'); ?></th>
                    <th><?php esc_html_e('Company', 'fast-open-cmp'); ?></th>
                    <th><?php esc_html_e('PROD template', 'fast-open-cmp'); ?></th>
                    <th><?php esc_html_e('TEST template', 'fast-open-cmp'); ?></th>
                    <th><?php esc_html_e('Integration code', 'fast-open-cmp'); ?></th>
                    <th><?php esc_html_e('Actions', 'fast-open-cmp'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($domains)): ?>
                    <tr>
                        <td colspan="7"><?php esc_html_e('No domains. Add the first one.', 'fast-open-cmp'); ?></td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($domains as $d): ?>
                        <tr>
                            <td><?php echo esc_html($d->id); ?></td>
                            <td><strong><?php echo esc_html($d->domain); ?></strong></td>
                            <td><?php echo esc_html($d->company_name ?? '-'); ?></td>
                            <td><?php
                                if ($d->prod_template_id) {
                                    printf('<a href="%s">%s</a>', esc_url(admin_url('admin.php?page=focmp-templates&action=edit&id=' . (int) $d->prod_template_id)), esc_html($d->prod_template_name ?: '#' . $d->prod_template_id));
                                } else {
                                    echo '–';
                                }
                                if (!empty($d->reconsent_at)) {
                                    /* translators: %s: date of the last forced reconsent */
                                    echo '<br><small>' . esc_html(sprintf(__('Reconsent: %s', 'fast-open-cmp'), mysql2date(get_option('date_format'), $d->reconsent_at))) . '</small>';
                                }
                            ?></td>
                            <td><?php
                                if ($d->test_template_id) {
                                    printf('<a href="%s">%s</a>', esc_url(admin_url('admin.php?page=focmp-templates&action=edit&id=' . (int) $d->test_template_id)), esc_html($d->test_template_name ?: '#' . $d->test_template_id));
                                    echo '<br><small><code>&amp;env=test</code></small>';
                                } else {
                                    echo '–';
                                }
                            ?></td>
                            <td>
                                <button type="button" class="button button-small focmp-show-code"
                                        data-code="<?php echo esc_attr('<script src="' . esc_url_raw(FOCMP_Domain_Access::snippet_url($d)) . '"></script>'); ?>">
                                    <?php esc_html_e('Show', 'fast-open-cmp'); ?>
                                </button>
                            </td>
                            <td>
                                <a href="<?php echo esc_url(admin_url('admin.php?page=focmp-domains&action=edit&id=' . $d->id)); ?>"><?php esc_html_e('Edit', 'fast-open-cmp'); ?></a> |
                                <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=focmp-domains&action=delete_domain&id=' . $d->id), 'delete_domain_' . $d->id)); ?>"
                                   onclick="return confirm('<?php esc_attr_e('Are you sure you want to delete?', 'fast-open-cmp'); ?>')"
                                   class="delete"><?php esc_html_e('Delete', 'fast-open-cmp'); ?></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <!-- Integration code modal -->
        <div id="focmp-code-modal" class="focmp-modal" style="display:none;">
            <div class="focmp-modal-backdrop" style="position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:100000;"></div>
            <div class="focmp-modal-box" role="dialog" aria-modal="true"
                 style="position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);z-index:100001;background:#fff;padding:24px;width:min(640px,92vw);box-shadow:0 5px 30px rgba(0,0,0,.3);border-radius:4px;">
                <h2 style="margin-top:0;"><?php esc_html_e('Integration code', 'fast-open-cmp'); ?></h2>
                <p class="description"><?php esc_html_e('Click the field below to copy the code to the clipboard.', 'fast-open-cmp'); ?></p>
                <textarea id="focmp-code-textarea" readonly rows="3"
                          style="width:100%;font-family:monospace;cursor:pointer;"></textarea>
                <p>
                    <span id="focmp-code-copied" style="color:#008a20;font-weight:600;display:none;"><?php esc_html_e('Copied to clipboard!', 'fast-open-cmp'); ?></span>
                </p>
                <p><button type="button" class="button button-primary" id="focmp-code-close"><?php esc_html_e('Close', 'fast-open-cmp'); ?></button></p>
            </div>
        </div>

        <script>
        jQuery(function ($) {
            var $modal = $('#focmp-code-modal'),
                $ta = $('#focmp-code-textarea'),
                $copied = $('#focmp-code-copied');

            $(document).on('click', '.focmp-show-code', function () {
                $ta.val($(this).data('code'));
                $copied.hide();
                $modal.show();
                $ta.focus();
            });

            function closeModal() { $modal.hide(); }
            $('#focmp-code-close').on('click', closeModal);
            $modal.find('.focmp-modal-backdrop').on('click', closeModal);
            $(document).on('keydown', function (e) { if (e.key === 'Escape' && $modal.is(':visible')) closeModal(); });

            $ta.on('click', function () {
                this.select();
                var done = function () { $copied.show(); };
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText($ta.val()).then(done, function () { try { document.execCommand('copy'); } catch (e) {} done(); });
                } else {
                    try { document.execCommand('copy'); } catch (e) {}
                    done();
                }
            });
        });
        </script>
    <?php endif; ?>
</div>
