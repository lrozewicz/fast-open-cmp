<?php
if (!defined('ABSPATH')) exit;

$show_form = in_array($action, array('add', 'edit'));
?>
<div class="wrap focmp-wrap">
    <h1>
        <?php esc_html_e('Custom Vendors', 'fast-open-cmp'); ?>
        <?php if (!$show_form): ?>
            <a href="<?php echo esc_url(admin_url('admin.php?page=focmp-vendors-custom&action=add')); ?>" class="page-title-action"><?php esc_html_e('Add new', 'fast-open-cmp'); ?></a>
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
            <?php wp_nonce_field('focmp_vendor_custom'); ?>
            <input type="hidden" name="vendor_id" value="<?php echo esc_attr($vendor->id ?? 0); ?>">

            <table class="form-table">
                <tr>
                    <th><label for="vendor_name"><?php esc_html_e('Vendor name', 'fast-open-cmp'); ?></label></th>
                    <td>
                        <input type="text" name="vendor_name" id="vendor_name" class="regular-text"
                               value="<?php echo esc_attr($vendor->name ?? ''); ?>" required>
                    </td>
                </tr>
                <tr>
                    <th><label for="vendor_privacy_url"><?php esc_html_e('Privacy policy URL', 'fast-open-cmp'); ?></label></th>
                    <td>
                        <input type="url" name="vendor_privacy_url" id="vendor_privacy_url" class="large-text"
                               value="<?php echo esc_attr($vendor->privacy_url ?? ''); ?>">
                    </td>
                </tr>
                <tr>
                    <th><label for="vendor_category"><?php esc_html_e('Category', 'fast-open-cmp'); ?></label></th>
                    <td>
                        <select name="vendor_category" id="vendor_category">
                            <option value="other" <?php selected($vendor->category ?? '', 'other'); ?>><?php esc_html_e('Other', 'fast-open-cmp'); ?></option>
                        </select>
                    </td>
                </tr>
            </table>

            <p class="submit">
                <button type="submit" name="focmp_save_vendor_custom" class="button button-primary"><?php esc_html_e('Save', 'fast-open-cmp'); ?></button>
                <a href="<?php echo esc_url(admin_url('admin.php?page=focmp-vendors-custom')); ?>" class="button"><?php esc_html_e('Cancel', 'fast-open-cmp'); ?></a>
            </p>
        </form>
    <?php else: ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th style="width: 50px;"><?php esc_html_e('ID', 'fast-open-cmp'); ?></th>
                    <th><?php esc_html_e('Name', 'fast-open-cmp'); ?></th>
                    <th><?php esc_html_e('Privacy policy URL', 'fast-open-cmp'); ?></th>
                    <th style="width: 100px;"><?php esc_html_e('Enabled', 'fast-open-cmp'); ?></th>
                    <th><?php esc_html_e('Actions', 'fast-open-cmp'); ?></th>
                </tr>
            </thead>
            <tbody id="focmp-vendors-custom-body">
                <?php if (empty($vendors)): ?>
                    <tr>
                        <td colspan="5"><?php esc_html_e('No custom vendors. Add the first one.', 'fast-open-cmp'); ?></td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
        <?php if (!empty($vendors)): ?>
        <!-- Rows hydrated by admin.js (focmp-lazy-rows); toggles save via the delegated handler. -->
        <template class="focmp-lazy-rows" data-target="#focmp-vendors-custom-body">
                    <?php foreach ($vendors as $v): ?>
                        <tr>
                            <td><?php echo esc_html($v->id); ?></td>
                            <td><strong><?php echo esc_html($v->name); ?></strong></td>
                            <td><a href="<?php echo esc_url($v->privacy_url); ?>" target="_blank"><?php echo esc_html($v->privacy_url); ?></a></td>
                            <td>
                                <label class="focmp-switch">
                                    <input type="checkbox"
                                           class="focmp-vendor-toggle"
                                           data-vendor-id="<?php echo esc_attr($v->id); ?>"
                                           data-vendor-type="custom"
                                           <?php checked($v->is_enabled, 1); ?>>
                                    <span class="focmp-slider"></span>
                                </label>
                            </td>
                            <td>
                                <a href="<?php echo esc_url(admin_url('admin.php?page=focmp-vendors-custom&action=edit&id=' . $v->id)); ?>"><?php esc_html_e('Edit', 'fast-open-cmp'); ?></a> |
                                <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=focmp-vendors-custom&action=delete_vendor_custom&id=' . $v->id), 'delete_vendor_custom_' . $v->id)); ?>"
                                   onclick="return confirm('<?php esc_attr_e('Are you sure you want to delete?', 'fast-open-cmp'); ?>')"
                                   class="delete"><?php esc_html_e('Delete', 'fast-open-cmp'); ?></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
        </template>
        <?php endif; ?>
    <?php endif; ?>
</div>

<script>
jQuery(document).ready(function($) {
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
