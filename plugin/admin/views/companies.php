<?php
if (!defined('ABSPATH')) exit;

$show_form = in_array($action, array('add', 'edit'));
?>
<div class="wrap focmp-wrap">
    <h1>
        <?php esc_html_e('Companies', 'fast-open-cmp'); ?>
        <?php if (!$show_form): ?>
            <a href="<?php echo esc_url(admin_url('admin.php?page=focmp-companies&action=add')); ?>" class="page-title-action"><?php esc_html_e('Add new', 'fast-open-cmp'); ?></a>
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
            <?php wp_nonce_field('focmp_company'); ?>
            <input type="hidden" name="company_id" value="<?php echo esc_attr($company->id ?? 0); ?>">

            <table class="form-table">
                <tr>
                    <th><label for="company_name"><?php esc_html_e('Company name', 'fast-open-cmp'); ?></label></th>
                    <td>
                        <input type="text" name="company_name" id="company_name" class="regular-text"
                               value="<?php echo esc_attr($company->name ?? ''); ?>" required>
                    </td>
                </tr>
                <tr>
                    <th><label for="company_description"><?php esc_html_e('Description', 'fast-open-cmp'); ?></label></th>
                    <td>
                        <textarea name="company_description" id="company_description" class="large-text" rows="4"><?php echo esc_textarea($company->description ?? ''); ?></textarea>
                    </td>
                </tr>
            </table>

            <p class="submit">
                <button type="submit" name="focmp_save_company" class="button button-primary"><?php esc_html_e('Save', 'fast-open-cmp'); ?></button>
                <a href="<?php echo esc_url(admin_url('admin.php?page=focmp-companies')); ?>" class="button"><?php esc_html_e('Cancel', 'fast-open-cmp'); ?></a>
            </p>
        </form>
    <?php else: ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php esc_html_e('ID', 'fast-open-cmp'); ?></th>
                    <th><?php esc_html_e('Name', 'fast-open-cmp'); ?></th>
                    <th><?php esc_html_e('Description', 'fast-open-cmp'); ?></th>
                    <th><?php esc_html_e('Created at', 'fast-open-cmp'); ?></th>
                    <th><?php esc_html_e('Actions', 'fast-open-cmp'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($companies)): ?>
                    <tr>
                        <td colspan="5"><?php esc_html_e('No companies. Add the first one.', 'fast-open-cmp'); ?></td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($companies as $c): ?>
                        <tr>
                            <td><?php echo esc_html($c->id); ?></td>
                            <td><strong><?php echo esc_html($c->name); ?></strong></td>
                            <td><?php echo esc_html(wp_trim_words($c->description, 10)); ?></td>
                            <td><?php echo esc_html($c->created_at); ?></td>
                            <td>
                                <a href="<?php echo esc_url(admin_url('admin.php?page=focmp-companies&action=edit&id=' . $c->id)); ?>"><?php esc_html_e('Edit', 'fast-open-cmp'); ?></a> |
                                <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=focmp-companies&action=delete_company&id=' . $c->id), 'delete_company_' . $c->id)); ?>"
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
