<?php
if (!defined('ABSPATH')) exit;
?>
<div class="wrap focmp-wrap">
    <h1><?php esc_html_e('Reconsents', 'fast-open-cmp'); ?></h1>

    <div class="focmp-reconsent-form" style="background: #fff; padding: 20px; margin-bottom: 20px; border: 1px solid #ddd;">
        <h2><?php esc_html_e('Force reconsent for a domain', 'fast-open-cmp'); ?></h2>
        <p><?php esc_html_e('A reconsent forces the consent box to show again for all users of the domain.', 'fast-open-cmp'); ?></p>

        <table class="form-table">
            <tr>
                <th><label for="reconsent-domain"><?php esc_html_e('Domain', 'fast-open-cmp'); ?></label></th>
                <td>
                    <select id="reconsent-domain" required>
                        <option value=""><?php esc_html_e('-- Select a domain --', 'fast-open-cmp'); ?></option>
                        <?php foreach ($domains as $d): ?>
                            <option value="<?php echo esc_attr($d->id); ?>"><?php echo esc_html($d->domain); ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
            <tr>
                <th><label for="reconsent-title"><?php esc_html_e('Title (reason)', 'fast-open-cmp'); ?></label></th>
                <td>
                    <input type="text" id="reconsent-title" class="regular-text" required
                           placeholder="<?php esc_attr_e('e.g. Partner list update', 'fast-open-cmp'); ?>">
                </td>
            </tr>
            <tr>
                <th><label for="reconsent-description"><?php esc_html_e('Description', 'fast-open-cmp'); ?></label></th>
                <td>
                    <textarea id="reconsent-description" class="large-text" rows="3"
                              placeholder="<?php esc_attr_e('Optional longer description of the reconsent reason', 'fast-open-cmp'); ?>"></textarea>
                </td>
            </tr>
        </table>

        <p>
            <button type="button" class="button button-primary" id="focmp-trigger-reconsent">
                <?php esc_html_e('Force reconsent', 'fast-open-cmp'); ?>
            </button>
            <span id="focmp-reconsent-status" style="margin-left: 10px;"></span>
        </p>
    </div>

    <h2><?php esc_html_e('Reconsent history', 'fast-open-cmp'); ?></h2>

    <?php if (empty($reconsents)): ?>
        <p><?php esc_html_e('No reconsent history.', 'fast-open-cmp'); ?></p>
    <?php else: ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th style="width: 50px;"><?php esc_html_e('ID', 'fast-open-cmp'); ?></th>
                    <th><?php esc_html_e('Domain', 'fast-open-cmp'); ?></th>
                    <th><?php esc_html_e('Title', 'fast-open-cmp'); ?></th>
                    <th><?php esc_html_e('Description', 'fast-open-cmp'); ?></th>
                    <th><?php esc_html_e('Created at', 'fast-open-cmp'); ?></th>
                    <th><?php esc_html_e('Status', 'fast-open-cmp'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($reconsents as $r): ?>
                    <tr>
                        <td><?php echo esc_html($r->id); ?></td>
                        <td><strong><?php echo esc_html($r->domain ?? 'N/A'); ?></strong></td>
                        <td><?php echo esc_html($r->title); ?></td>
                        <td><?php echo esc_html(wp_trim_words($r->description, 10)); ?></td>
                        <td><?php echo esc_html(mysql2date(get_option('date_format') . ' H:i', $r->created_at)); ?></td>
                        <td><?php echo !empty($r->is_current) ? '<strong>' . esc_html__('Active', 'fast-open-cmp') . '</strong>' : esc_html__('Superseded', 'fast-open-cmp'); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<script>
jQuery(document).ready(function($) {
    $('#focmp-trigger-reconsent').on('click', function() {
        var btn = $(this);
        var status = $('#focmp-reconsent-status');
        var domainId = $('#reconsent-domain').val();
        var title = $('#reconsent-title').val();
        var description = $('#reconsent-description').val();

        if (!domainId || !title) {
            alert('<?php esc_html_e('Select a domain and provide a title', 'fast-open-cmp'); ?>');
            return;
        }

        if (!confirm('<?php esc_html_e('Are you sure you want to force a reconsent? All users of this domain will be asked to consent again.', 'fast-open-cmp'); ?>')) {
            return;
        }

        btn.prop('disabled', true);
        status.html('<span class="spinner is-active" style="float: none;"></span>');

        $.post(focmpAdmin.ajaxUrl, {
            action: 'focmp_trigger_reconsent',
            nonce: focmpAdmin.nonce,
            domain_id: domainId,
            title: title,
            description: description
        }, function(response) {
            btn.prop('disabled', false);
            if (response.success) {
                status.empty().append($('<span style="color: green;"></span>').text('<?php echo esc_js(__('Reconsent created!', 'fast-open-cmp')); ?>'));
                setTimeout(function() { location.reload(); }, 2000);
            } else {
                status.empty().append($('<span style="color: red;"></span>').text('<?php echo esc_js(__('Error', 'fast-open-cmp')); ?>: ' + response.data.message));
            }
        });
    });
});
</script>
