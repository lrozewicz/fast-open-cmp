<?php
if (!defined('ABSPATH')) exit;
?>
<div class="wrap focmp-wrap">
    <h1><?php esc_html_e('Google Vendors (Additional Consent)', 'fast-open-cmp'); ?></h1>

    <?php if (isset($_GET['message'])): ?>
        <div class="notice notice-success is-dismissible">
            <p><?php esc_html_e('Updated successfully', 'fast-open-cmp'); ?></p>
        </div>
    <?php endif; ?>

    <div class="focmp-import-section" style="background: #fff; padding: 20px; margin-bottom: 20px; border: 1px solid #ddd;">
        <h2><?php esc_html_e('Import from the Google ATP CSV', 'fast-open-cmp'); ?></h2>
        <p><?php esc_html_e('Import the Google provider list from the official URL.', 'fast-open-cmp'); ?></p>

        <p>
            <label><?php esc_html_e('Import URL:', 'fast-open-cmp'); ?></label><br>
            <input type="text" id="focmp-google-url" class="large-text"
                   value="<?php echo esc_attr($settings['google_atp_url'] ?? 'https://storage.googleapis.com/tcfac/additional-consent-providers.csv'); ?>">
        </p>

        <p>
            <button type="button" class="button button-primary" id="focmp-import-google-btn">
                <?php esc_html_e('Import Google ATP', 'fast-open-cmp'); ?>
            </button>
            <span id="focmp-import-google-status" style="margin-left: 10px;"></span>
        </p>
    </div>

    <h2><?php esc_html_e('Provider list', 'fast-open-cmp'); ?> (<?php echo count($vendors); ?>)</h2>

    <?php if (empty($vendors)): ?>
        <p><?php esc_html_e('No providers. Import from Google ATP.', 'fast-open-cmp'); ?></p>
    <?php else: ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th style="width: 80px;"><?php esc_html_e('ID', 'fast-open-cmp'); ?></th>
                    <th><?php esc_html_e('Name', 'fast-open-cmp'); ?></th>
                    <th style="width: 100px;"><?php esc_html_e('Enabled', 'fast-open-cmp'); ?></th>
                    <th><?php esc_html_e('Updated at', 'fast-open-cmp'); ?></th>
                </tr>
            </thead>
            <tbody id="focmp-vendors-google-body"></tbody>
        </table>
        <!-- Rows hydrated by admin.js (focmp-lazy-rows) to keep first paint fast;
             toggles still save via the delegated handler. -->
        <template class="focmp-lazy-rows" data-target="#focmp-vendors-google-body">
                <?php foreach ($vendors as $v): ?>
                    <tr>
                        <td><?php echo esc_html($v->provider_id); ?></td>
                        <td><?php echo esc_html($v->provider_name); ?></td>
                        <td>
                            <label class="focmp-switch">
                                <input type="checkbox"
                                       class="focmp-vendor-toggle"
                                       data-vendor-id="<?php echo esc_attr($v->provider_id); ?>"
                                       data-vendor-type="google"
                                       <?php checked($v->is_enabled, 1); ?>>
                                <span class="focmp-slider"></span>
                            </label>
                        </td>
                        <td><?php echo esc_html($v->updated_at); ?></td>
                    </tr>
                <?php endforeach; ?>
        </template>
    <?php endif; ?>
</div>

<script>
jQuery(document).ready(function($) {
    $('#focmp-import-google-btn').on('click', function() {
        var btn = $(this);
        var status = $('#focmp-import-google-status');
        var url = $('#focmp-google-url').val();

        btn.prop('disabled', true);
        status.html('<span class="spinner is-active" style="float: none;"></span> <?php esc_html_e('Importing...', 'fast-open-cmp'); ?>');

        $.post(focmpAdmin.ajaxUrl, {
            action: 'focmp_import_google',
            nonce: focmpAdmin.nonce,
            source: url
        }, function(response) {
            btn.prop('disabled', false);
            if (response.success) {
                status.empty().append($('<span style="color: green;"></span>').text(
                    '<?php echo esc_js(__('Imported providers:', 'fast-open-cmp')); ?> ' + response.data.providers));
                setTimeout(function() { location.reload(); }, 2000);
            } else {
                status.empty().append($('<span style="color: red;"></span>').text('<?php echo esc_js(__('Error', 'fast-open-cmp')); ?>: ' + response.data.message));
            }
        });
    });

    // Toggle saving is handled by the delegated `.focmp-vendor-toggle` handler in
    // admin.js (fires for lazily-hydrated rows too, with opacity feedback +
    // revert-on-error). A second binding here would double-POST.
});
</script>

<style>
.focmp-switch {
    position: relative;
    display: inline-block;
    width: 50px;
    height: 24px;
}
.focmp-switch input {
    opacity: 0;
    width: 0;
    height: 0;
}
.focmp-slider {
    position: absolute;
    cursor: pointer;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background-color: #ccc;
    transition: .4s;
    border-radius: 24px;
}
.focmp-slider:before {
    position: absolute;
    content: "";
    height: 18px;
    width: 18px;
    left: 3px;
    bottom: 3px;
    background-color: white;
    transition: .4s;
    border-radius: 50%;
}
input:checked + .focmp-slider {
    background-color: #2196F3;
}
input:checked + .focmp-slider:before {
    transform: translateX(26px);
}
</style>
