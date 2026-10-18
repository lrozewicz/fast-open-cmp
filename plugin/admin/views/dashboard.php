<?php
if (!defined('ABSPATH')) exit;
?>
<div class="wrap focmp-wrap">
    <h1><?php esc_html_e('Fast Open CMP - Dashboard', 'fast-open-cmp'); ?></h1>

    <div class="focmp-stats-grid">
        <div class="focmp-stat-box">
            <span class="focmp-stat-number"><?php echo esc_html($stats['companies']); ?></span>
            <span class="focmp-stat-label"><?php esc_html_e('Companies', 'fast-open-cmp'); ?></span>
            <a href="<?php echo esc_url(admin_url('admin.php?page=focmp-companies')); ?>" class="focmp-stat-link"><?php esc_html_e('Manage', 'fast-open-cmp'); ?></a>
        </div>

        <div class="focmp-stat-box">
            <span class="focmp-stat-number"><?php echo esc_html($stats['domains']); ?></span>
            <span class="focmp-stat-label"><?php esc_html_e('Domains', 'fast-open-cmp'); ?></span>
            <a href="<?php echo esc_url(admin_url('admin.php?page=focmp-domains')); ?>" class="focmp-stat-link"><?php esc_html_e('Manage', 'fast-open-cmp'); ?></a>
        </div>

        <div class="focmp-stat-box">
            <span class="focmp-stat-number"><?php echo esc_html($stats['templates']); ?></span>
            <span class="focmp-stat-label"><?php esc_html_e('Templates', 'fast-open-cmp'); ?></span>
            <a href="<?php echo esc_url(admin_url('admin.php?page=focmp-templates')); ?>" class="focmp-stat-link"><?php esc_html_e('Manage', 'fast-open-cmp'); ?></a>
        </div>

        <div class="focmp-stat-box">
            <span class="focmp-stat-number"><?php echo esc_html($stats['vendor_lists']); ?></span>
            <span class="focmp-stat-label"><?php esc_html_e('Vendor Lists', 'fast-open-cmp'); ?></span>
            <a href="<?php echo esc_url(admin_url('admin.php?page=focmp-vendor-lists')); ?>" class="focmp-stat-link"><?php esc_html_e('Manage', 'fast-open-cmp'); ?></a>
        </div>

        <div class="focmp-stat-box">
            <span class="focmp-stat-number"><?php echo esc_html($stats['vendors_iab']); ?></span>
            <span class="focmp-stat-label"><?php esc_html_e('IAB Vendors', 'fast-open-cmp'); ?></span>
            <a href="<?php echo esc_url(admin_url('admin.php?page=focmp-vendors-iab')); ?>" class="focmp-stat-link"><?php esc_html_e('Manage', 'fast-open-cmp'); ?></a>
        </div>

        <div class="focmp-stat-box">
            <span class="focmp-stat-number"><?php echo esc_html($stats['vendors_google']); ?></span>
            <span class="focmp-stat-label"><?php esc_html_e('Google Vendors', 'fast-open-cmp'); ?></span>
            <a href="<?php echo esc_url(admin_url('admin.php?page=focmp-vendors-google')); ?>" class="focmp-stat-link"><?php esc_html_e('Manage', 'fast-open-cmp'); ?></a>
        </div>

        <div class="focmp-stat-box">
            <span class="focmp-stat-number"><?php echo esc_html($stats['vendors_custom']); ?></span>
            <span class="focmp-stat-label"><?php esc_html_e('Custom Vendors', 'fast-open-cmp'); ?></span>
            <a href="<?php echo esc_url(admin_url('admin.php?page=focmp-vendors-custom')); ?>" class="focmp-stat-link"><?php esc_html_e('Manage', 'fast-open-cmp'); ?></a>
        </div>
    </div>

    <div class="focmp-demo-box" style="margin: 20px 0; padding: 16px 20px; background: #fff; border: 1px solid #ddd;">
        <h2 style="margin-top: 0;"><?php esc_html_e('Demo', 'fast-open-cmp'); ?></h2>
        <p class="description" style="max-width: 720px;">
            <?php esc_html_e('One-click sample data: creates a demo company, imports the IAB Global Vendor List and Google ATP providers, adds 2 example custom vendors, creates a test template with one custom consent, and a test domain linking the company and template.', 'fast-open-cmp'); ?>
        </p>
        <button type="button" class="button button-primary" id="focmp-demo-import">
            <?php esc_html_e('Demo — import test data', 'fast-open-cmp'); ?>
        </button>
        <span id="focmp-demo-status" style="margin-left: 10px;"></span>
    </div>

    <script>
    jQuery(function ($) {
        $('#focmp-demo-import').on('click', function () {
            if (!window.confirm('<?php echo esc_js(__('Import demo test data? This creates a company, imports vendors and creates a test template.', 'fast-open-cmp')); ?>')) {
                return;
            }
            var $btn = $(this), $status = $('#focmp-demo-status');
            $btn.prop('disabled', true);
            $status.html('<span class="spinner is-active" style="float:none;margin:0 4px 0 0;"></span>' +
                '<?php echo esc_js(__('Importing… this can take a moment.', 'fast-open-cmp')); ?>');

            $.post(focmpAdmin.ajaxUrl, { action: 'focmp_demo_import', nonce: focmpAdmin.nonce })
                .done(function (r) {
                    if (r && r.success) {
                        $status.html('<span style="color:#008a20;">' +
                            '<?php echo esc_js(__('Done! Reloading…', 'fast-open-cmp')); ?> ' +
                            (r.data && r.data.summary ? r.data.summary : '') + '</span>');
                        setTimeout(function () { location.reload(); }, 1400);
                    } else {
                        $btn.prop('disabled', false);
                        $status.html('<span style="color:#d63638;"><?php echo esc_js(__('Error', 'fast-open-cmp')); ?>: ' +
                            (r && r.data && r.data.message ? r.data.message : '') + '</span>');
                    }
                })
                .fail(function () {
                    $btn.prop('disabled', false);
                    $status.html('<span style="color:#d63638;"><?php echo esc_js(__('Connection error', 'fast-open-cmp')); ?></span>');
                });
        });
    });
    </script>

    <div class="focmp-info-box">
        <h2><?php esc_html_e('Integration', 'fast-open-cmp'); ?></h2>
        <p><?php esc_html_e('To place the consent box on a third-party site, add the code below:', 'fast-open-cmp'); ?></p>
        <?php
        // add_query_arg picks the right separator: with plain permalinks rest_url()
        // already contains "?rest_route=…", so appending "?domain_id=" breaks the URL.
        $focmp_domains = $GLOBALS['wpdb']->get_results("SELECT id, domain, public_key FROM {$GLOBALS['wpdb']->prefix}cmp_domains ORDER BY id");
        ?>
        <?php if ($focmp_domains): ?>
            <?php foreach ($focmp_domains as $focmp_domain): ?>
                <p><strong><?php echo esc_html($focmp_domain->domain); ?></strong></p>
                <pre>&lt;script src="<?php echo esc_html(FOCMP_Domain_Access::snippet_url($focmp_domain)); ?>"&gt;&lt;/script&gt;</pre>
            <?php endforeach; ?>
        <?php else: ?>
            <pre>&lt;script src="<?php echo esc_html(add_query_arg('key', 'KEY', rest_url('fast-open-cmp/v1/sdk'))); ?>"&gt;&lt;/script&gt;</pre>
            <p class="description"><?php esc_html_e('Add a domain in the Domains tab — its integration code contains the key.', 'fast-open-cmp'); ?></p>
        <?php endif; ?>

        <h3><?php esc_html_e('API endpoints', 'fast-open-cmp'); ?></h3>
        <ul>
            <li><code><?php echo esc_html(add_query_arg('key', 'KEY', rest_url('fast-open-cmp/v1/cmp-config'))); ?></code> — <?php esc_html_e('CMP configuration', 'fast-open-cmp'); ?></li>
            <li><code><?php echo esc_html(rest_url('fast-open-cmp/v1/vendor-list')); ?></code> — <?php esc_html_e('IAB vendor list (GVL)', 'fast-open-cmp'); ?></li>
            <li><code><?php echo esc_html(rest_url('fast-open-cmp/v1/purposes-pl')); ?></code> — <?php esc_html_e('Purposes (Polish)', 'fast-open-cmp'); ?></li>
        </ul>
    </div>
</div>
