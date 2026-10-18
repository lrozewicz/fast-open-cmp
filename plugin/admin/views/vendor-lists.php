<?php
if (!defined('ABSPATH')) exit;

$show_form = in_array($action, array('add', 'edit'));
?>
<div class="wrap focmp-wrap">
    <h1>
        <?php esc_html_e('Vendor Lists', 'fast-open-cmp'); ?>
        <?php if (!$show_form): ?>
            <a href="<?php echo esc_url(admin_url('admin.php?page=focmp-vendor-lists&action=add')); ?>" class="page-title-action"><?php esc_html_e('Add new', 'fast-open-cmp'); ?></a>
        <?php endif; ?>
    </h1>

    <?php if (isset($_GET['message'])): ?>
        <div class="notice notice-success is-dismissible">
            <p><?php
                $messages = array(
                    'saved' => __('Saved successfully', 'fast-open-cmp'),
                    'deleted' => __('Deleted successfully', 'fast-open-cmp'),
                    'cloned' => __('Cloned successfully', 'fast-open-cmp'),
                );
                echo esc_html($messages[$_GET['message']] ?? '');
            ?></p>
        </div>
    <?php endif; ?>

    <?php if ($show_form): ?>
        <form method="post" action="" id="focmp-vendor-list-form">
            <?php wp_nonce_field('focmp_vendor_list'); ?>
            <input type="hidden" name="vendor_list_id" value="<?php echo esc_attr($vendor_list->id ?? 0); ?>">
            <!-- Compact hidden fields for vendor states (populated by JS on submit) -->
            <input type="hidden" name="disabled_iab" id="focmp-disabled-iab" value="">
            <input type="hidden" name="disabled_google" id="focmp-disabled-google" value="">
            <input type="hidden" name="disabled_custom" id="focmp-disabled-custom" value="">

            <table class="form-table">
                <tr>
                    <th><label for="list_name"><?php esc_html_e('List name', 'fast-open-cmp'); ?></label></th>
                    <td>
                        <input type="text" name="list_name" id="list_name" class="regular-text"
                               value="<?php echo esc_attr($vendor_list->name ?? ''); ?>" required>
                    </td>
                </tr>
                <tr>
                    <th><label for="list_description"><?php esc_html_e('Description', 'fast-open-cmp'); ?></label></th>
                    <td>
                        <textarea name="list_description" id="list_description" class="large-text" rows="3"><?php echo esc_textarea($vendor_list->description ?? ''); ?></textarea>
                    </td>
                </tr>
            </table>

            <?php if ($vendor_list): ?>
                <h2><?php esc_html_e('Vendors in list', 'fast-open-cmp'); ?></h2>

                <div class="focmp-vendor-list-tabs">
                    <button type="button" class="focmp-tab-btn active" data-tab="iab"><?php esc_html_e('IAB', 'fast-open-cmp'); ?> (<?php echo count($vendors_iab); ?>)</button>
                    <button type="button" class="focmp-tab-btn" data-tab="google"><?php esc_html_e('Google', 'fast-open-cmp'); ?> (<?php echo count($vendors_google); ?>)</button>
                    <button type="button" class="focmp-tab-btn" data-tab="custom"><?php esc_html_e('Custom', 'fast-open-cmp'); ?> (<?php echo count($vendors_custom); ?>)</button>
                </div>

                <!-- IAB Tab -->
                <div class="focmp-tab-content active" id="focmp-tab-iab">
                    <div class="focmp-vendor-list-controls">
                        <input type="text" class="focmp-vendor-search" data-tab="iab" placeholder="<?php esc_attr_e('Search vendors...', 'fast-open-cmp'); ?>">
                        <button type="button" class="button focmp-select-all" data-tab="iab" data-type="iab"><?php esc_html_e('Select all', 'fast-open-cmp'); ?></button>
                        <button type="button" class="button focmp-deselect-all" data-tab="iab" data-type="iab"><?php esc_html_e('Deselect all', 'fast-open-cmp'); ?></button>
                        <span class="focmp-vendor-count" data-tab="iab"></span>
                    </div>
                    <table class="wp-list-table widefat fixed striped focmp-vendor-table" data-tab="iab">
                        <thead>
                            <tr>
                                <th style="width: 50px;"><?php esc_html_e('ID', 'fast-open-cmp'); ?></th>
                                <th><?php esc_html_e('Name', 'fast-open-cmp'); ?></th>
                                <th style="width: 80px;"><?php esc_html_e('Global', 'fast-open-cmp'); ?></th>
                                <th style="width: 80px;"><?php esc_html_e('List', 'fast-open-cmp'); ?></th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                    <!-- Rows are kept inert in a <template> and hydrated by admin.js
                         (chunked for the active tab, on-demand for the rest) to keep
                         the initial render fast with thousands of vendors. -->
                    <template id="focmp-rows-iab" class="focmp-rows-template">
                            <?php foreach ($vendors_iab as $v):
                                $entry_enabled = isset($list_entries['iab'][$v->vendor_id]) ? (int)$list_entries['iab'][$v->vendor_id] : 1;
                            ?>
                                <tr class="focmp-vendor-row <?php echo !$v->is_enabled ? 'focmp-globally-disabled' : ''; ?>">
                                    <td><?php echo esc_html($v->vendor_id); ?></td>
                                    <td><?php echo esc_html($v->name); ?></td>
                                    <td>
                                        <?php if ($v->is_enabled): ?>
                                            <span class="focmp-badge focmp-badge-on"><?php esc_html_e('ON', 'fast-open-cmp'); ?></span>
                                        <?php else: ?>
                                            <span class="focmp-badge focmp-badge-off"><?php esc_html_e('OFF', 'fast-open-cmp'); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <label class="focmp-switch">
                                            <input type="checkbox"
                                                   data-vendor-id="<?php echo esc_attr($v->vendor_id); ?>"
                                                   class="focmp-list-vendor-toggle"
                                                   data-type="iab"
                                                   <?php checked($entry_enabled, 1); ?>
                                                   <?php disabled(!$v->is_enabled); ?>>
                                            <span class="focmp-slider"></span>
                                        </label>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                    </template>
                </div>

                <!-- Google Tab -->
                <div class="focmp-tab-content" id="focmp-tab-google">
                    <div class="focmp-vendor-list-controls">
                        <input type="text" class="focmp-vendor-search" data-tab="google" placeholder="<?php esc_attr_e('Search vendors...', 'fast-open-cmp'); ?>">
                        <button type="button" class="button focmp-select-all" data-tab="google" data-type="google"><?php esc_html_e('Select all', 'fast-open-cmp'); ?></button>
                        <button type="button" class="button focmp-deselect-all" data-tab="google" data-type="google"><?php esc_html_e('Deselect all', 'fast-open-cmp'); ?></button>
                        <span class="focmp-vendor-count" data-tab="google"></span>
                    </div>
                    <table class="wp-list-table widefat fixed striped focmp-vendor-table" data-tab="google">
                        <thead>
                            <tr>
                                <th style="width: 50px;"><?php esc_html_e('ID', 'fast-open-cmp'); ?></th>
                                <th><?php esc_html_e('Name', 'fast-open-cmp'); ?></th>
                                <th style="width: 80px;"><?php esc_html_e('Global', 'fast-open-cmp'); ?></th>
                                <th style="width: 80px;"><?php esc_html_e('List', 'fast-open-cmp'); ?></th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                    <template id="focmp-rows-google" class="focmp-rows-template">
                            <?php foreach ($vendors_google as $v):
                                $entry_enabled = isset($list_entries['google'][$v->provider_id]) ? (int)$list_entries['google'][$v->provider_id] : 1;
                            ?>
                                <tr class="focmp-vendor-row <?php echo !$v->is_enabled ? 'focmp-globally-disabled' : ''; ?>">
                                    <td><?php echo esc_html($v->provider_id); ?></td>
                                    <td><?php echo esc_html($v->provider_name); ?></td>
                                    <td>
                                        <?php if ($v->is_enabled): ?>
                                            <span class="focmp-badge focmp-badge-on"><?php esc_html_e('ON', 'fast-open-cmp'); ?></span>
                                        <?php else: ?>
                                            <span class="focmp-badge focmp-badge-off"><?php esc_html_e('OFF', 'fast-open-cmp'); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <label class="focmp-switch">
                                            <input type="checkbox"
                                                   data-vendor-id="<?php echo esc_attr($v->provider_id); ?>"
                                                   class="focmp-list-vendor-toggle"
                                                   data-type="google"
                                                   <?php checked($entry_enabled, 1); ?>
                                                   <?php disabled(!$v->is_enabled); ?>>
                                            <span class="focmp-slider"></span>
                                        </label>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                    </template>
                </div>

                <!-- Custom Tab -->
                <div class="focmp-tab-content" id="focmp-tab-custom">
                    <div class="focmp-vendor-list-controls">
                        <input type="text" class="focmp-vendor-search" data-tab="custom" placeholder="<?php esc_attr_e('Search vendors...', 'fast-open-cmp'); ?>">
                        <button type="button" class="button focmp-select-all" data-tab="custom" data-type="custom"><?php esc_html_e('Select all', 'fast-open-cmp'); ?></button>
                        <button type="button" class="button focmp-deselect-all" data-tab="custom" data-type="custom"><?php esc_html_e('Deselect all', 'fast-open-cmp'); ?></button>
                        <span class="focmp-vendor-count" data-tab="custom"></span>
                    </div>
                    <table class="wp-list-table widefat fixed striped focmp-vendor-table" data-tab="custom">
                        <thead>
                            <tr>
                                <th style="width: 50px;"><?php esc_html_e('ID', 'fast-open-cmp'); ?></th>
                                <th><?php esc_html_e('Name', 'fast-open-cmp'); ?></th>
                                <th style="width: 80px;"><?php esc_html_e('Global', 'fast-open-cmp'); ?></th>
                                <th style="width: 80px;"><?php esc_html_e('List', 'fast-open-cmp'); ?></th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                    <template id="focmp-rows-custom" class="focmp-rows-template">
                            <?php foreach ($vendors_custom as $v):
                                $entry_enabled = isset($list_entries['custom'][$v->id]) ? (int)$list_entries['custom'][$v->id] : 1;
                            ?>
                                <tr class="focmp-vendor-row <?php echo !$v->is_enabled ? 'focmp-globally-disabled' : ''; ?>">
                                    <td><?php echo esc_html($v->id); ?></td>
                                    <td><?php echo esc_html($v->name); ?></td>
                                    <td>
                                        <?php if ($v->is_enabled): ?>
                                            <span class="focmp-badge focmp-badge-on"><?php esc_html_e('ON', 'fast-open-cmp'); ?></span>
                                        <?php else: ?>
                                            <span class="focmp-badge focmp-badge-off"><?php esc_html_e('OFF', 'fast-open-cmp'); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <label class="focmp-switch">
                                            <input type="checkbox"
                                                   data-vendor-id="<?php echo esc_attr($v->id); ?>"
                                                   class="focmp-list-vendor-toggle"
                                                   data-type="custom"
                                                   <?php checked($entry_enabled, 1); ?>
                                                   <?php disabled(!$v->is_enabled); ?>>
                                            <span class="focmp-slider"></span>
                                        </label>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                    </template>
                </div>
            <?php endif; ?>

            <p class="submit">
                <button type="submit" name="focmp_save_vendor_list" class="button button-primary"><?php esc_html_e('Save list', 'fast-open-cmp'); ?></button>
                <a href="<?php echo esc_url(admin_url('admin.php?page=focmp-vendor-lists')); ?>" class="button"><?php esc_html_e('Cancel', 'fast-open-cmp'); ?></a>
            </p>
        </form>

    <?php else: ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th style="width: 50px;"><?php esc_html_e('ID', 'fast-open-cmp'); ?></th>
                    <th><?php esc_html_e('Name', 'fast-open-cmp'); ?></th>
                    <th><?php esc_html_e('Description', 'fast-open-cmp'); ?></th>
                    <th><?php esc_html_e('Used by templates', 'fast-open-cmp'); ?></th>
                    <th><?php esc_html_e('Created at', 'fast-open-cmp'); ?></th>
                    <th><?php esc_html_e('Actions', 'fast-open-cmp'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($vendor_lists)): ?>
                    <tr>
                        <td colspan="6"><?php esc_html_e('No vendor lists. Add the first one.', 'fast-open-cmp'); ?></td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($vendor_lists as $vl): ?>
                        <tr>
                            <td><?php echo esc_html($vl->id); ?></td>
                            <td><strong><?php echo esc_html($vl->name); ?></strong></td>
                            <td><?php echo esc_html($vl->description ?: '-'); ?></td>
                            <td><?php echo esc_html($vl->template_count); ?></td>
                            <td><?php echo esc_html($vl->created_at); ?></td>
                            <td>
                                <a href="<?php echo esc_url(admin_url('admin.php?page=focmp-vendor-lists&action=edit&id=' . $vl->id)); ?>"><?php esc_html_e('Edit', 'fast-open-cmp'); ?></a> |
                                <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=focmp-vendor-lists&action=clone_vendor_list&id=' . $vl->id), 'clone_vendor_list_' . $vl->id)); ?>"><?php esc_html_e('Clone', 'fast-open-cmp'); ?></a> |
                                <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=focmp-vendor-lists&action=delete_vendor_list&id=' . $vl->id), 'delete_vendor_list_' . $vl->id)); ?>"
                                   onclick="return confirm('<?php esc_attr_e('Are you sure you want to delete?', 'fast-open-cmp'); ?>')"
                                   class="delete"><?php esc_html_e('Delete', 'fast-open-cmp'); ?></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
