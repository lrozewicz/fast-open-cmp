<?php
if (!defined('ABSPATH')) exit;

$show_form = in_array($action, array('add', 'edit'));
$config = $template ? json_decode($template->config, true) : array();
$texts = $config['texts'] ?? ($default_texts['pl'] ?? array());
$custom_consents = $config['customConsents'] ?? array();
$template_mode = $config['mode'] ?? 'tcf_gcm';
?>
<div class="wrap focmp-wrap">
    <h1>
        <?php esc_html_e('Consent box templates', 'fast-open-cmp'); ?>
        <?php if (!$show_form): ?>
            <a href="<?php echo esc_url(admin_url('admin.php?page=focmp-templates&action=add')); ?>" class="page-title-action"><?php esc_html_e('Add new', 'fast-open-cmp'); ?></a>
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
        <p>
            <button type="button" class="button" id="focmp-preview-toggle" aria-expanded="false" aria-controls="focmp-preview-panel">
                <span class="dashicons dashicons-visibility" style="vertical-align: text-bottom;"></span>
                <?php esc_html_e('Live preview', 'fast-open-cmp'); ?>
            </button>
            <span class="description"><?php esc_html_e('Shows this template with the current, unsaved settings. Choices made in the preview are not stored.', 'fast-open-cmp'); ?></span>
        </p>
        <div id="focmp-preview-panel" hidden>
            <div class="focmp-preview-toolbar">
                <strong><?php esc_html_e('Preview', 'fast-open-cmp'); ?></strong>
                <button type="button" class="button button-small" data-preview-layer="1"><?php esc_html_e('Layer 1', 'fast-open-cmp'); ?></button>
                <button type="button" class="button button-small" data-preview-layer="2"><?php esc_html_e('Layer 2', 'fast-open-cmp'); ?></button>
                <button type="button" class="button button-small" data-preview-layer="3"><?php esc_html_e('Layer 3', 'fast-open-cmp'); ?></button>
                <label><input type="checkbox" id="focmp-preview-mobile"> <?php esc_html_e('Mobile', 'fast-open-cmp'); ?></label>
                <button type="button" class="button button-small" id="focmp-preview-close" aria-label="<?php esc_attr_e('Close preview', 'fast-open-cmp'); ?>">&times;</button>
            </div>
            <div class="focmp-preview-stage">
                <iframe id="focmp-preview-frame" title="<?php esc_attr_e('Consent box preview', 'fast-open-cmp'); ?>"></iframe>
            </div>
        </div>
        <style>
            #focmp-preview-panel { position: fixed; top: 32px; right: 0; bottom: 0; width: min(760px, 48vw); background: #f0f0f1; border-left: 1px solid #c3c4c7; box-shadow: -4px 0 12px rgba(0,0,0,.08); z-index: 9990; display: flex; flex-direction: column; }
            #focmp-preview-panel[hidden] { display: none; }
            .focmp-preview-toolbar { display: flex; gap: 8px; align-items: center; padding: 8px 12px; background: #fff; border-bottom: 1px solid #c3c4c7; }
            .focmp-preview-toolbar #focmp-preview-close { margin-left: auto; }
            .focmp-preview-stage { flex: 1; position: relative; overflow: hidden; padding: 12px; }
            #focmp-preview-frame { border: 1px solid #c3c4c7; background: #fff; transform-origin: 0 0; position: absolute; top: 12px; left: 12px; }
        </style>
        <form method="post" action="" id="focmp-template-form">
            <?php wp_nonce_field('focmp_template'); ?>
            <input type="hidden" name="template_id" value="<?php echo esc_attr($template->id ?? 0); ?>">

            <table class="form-table">
                <tr>
                    <th><label for="template_name"><?php esc_html_e('Template name', 'fast-open-cmp'); ?></label></th>
                    <td>
                        <input type="text" name="template_name" id="template_name" class="regular-text"
                               value="<?php echo esc_attr($template->name ?? ''); ?>" required>
                    </td>
                </tr>
                <tr>
                    <th><label for="template_language"><?php esc_html_e('Language', 'fast-open-cmp'); ?></label></th>
                    <td>
                        <select name="template_language" id="template_language">
                            <option value="pl" <?php selected($template->language ?? 'pl', 'pl'); ?>>Polski</option>
                            <option value="en" <?php selected($template->language ?? '', 'en'); ?>>English</option>
                            <option value="de" <?php selected($template->language ?? '', 'de'); ?>>Deutsch</option>
                            <option value="fr" <?php selected($template->language ?? '', 'fr'); ?>>Francais</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="template_mode"><?php esc_html_e('Working mode', 'fast-open-cmp'); ?></label></th>
                    <td>
                        <select name="template_mode" id="template_mode">
                            <option value="tcf_gcm" <?php selected($template_mode, 'tcf_gcm'); ?>><?php esc_html_e('Full (TCF + Consent Mode)', 'fast-open-cmp'); ?></option>
                            <option value="gcm_only" <?php selected($template_mode, 'gcm_only'); ?>><?php esc_html_e('Google Consent Mode v2', 'fast-open-cmp'); ?></option>
                            <option value="basic" <?php selected($template_mode, 'basic'); ?>><?php esc_html_e('Basic', 'fast-open-cmp'); ?></option>
                        </select>
                        <p class="description">
                            <strong><?php esc_html_e('Full', 'fast-open-cmp'); ?></strong> — <?php esc_html_e('TCF 2.3 + Google Consent Mode v2 + Additional Consent (large publishers)', 'fast-open-cmp'); ?><br>
                            <strong><?php esc_html_e('Google Consent Mode v2', 'fast-open-cmp'); ?></strong> — <?php esc_html_e('Google signals only (ad_storage, analytics_storage, ...) without TCF (medium sites)', 'fast-open-cmp'); ?><br>
                            <strong><?php esc_html_e('Basic', 'fast-open-cmp'); ?></strong> — <?php esc_html_e('Custom consents only, a simple consent box (small sites)', 'fast-open-cmp'); ?>
                        </p>
                    </td>
                </tr>
                <tr class="focmp-mode-tcf focmp-mode-gcm">
                    <th><label for="vendor_list_id"><?php esc_html_e('Vendor list', 'fast-open-cmp'); ?></label></th>
                    <td>
                        <select name="vendor_list_id" id="vendor_list_id">
                            <option value=""><?php esc_html_e('-- Default (all enabled) --', 'fast-open-cmp'); ?></option>
                            <?php foreach ($vendor_lists as $vl): ?>
                                <option value="<?php echo esc_attr($vl->id); ?>" <?php selected($template->vendor_list_id ?? '', $vl->id); ?>>
                                    <?php echo esc_html($vl->name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (!empty($template->vendor_list_id)): ?>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=focmp-vendor-lists&action=edit&id=' . $template->vendor_list_id)); ?>" class="button button-small" style="margin-left: 5px;"><?php esc_html_e('Edit list', 'fast-open-cmp'); ?></a>
                        <?php endif; ?>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=focmp-vendor-lists&action=add')); ?>" class="button button-small" style="margin-left: 5px;"><?php esc_html_e('New list', 'fast-open-cmp'); ?></a>
                    </td>
                </tr>
            </table>

            <?php
            $profile = ($config['complianceProfile'] ?? 'certified') === 'custom' ? 'custom' : 'certified';
            $fl = $config['firstLayer'] ?? array();
            $ap = $config['appearance'] ?? array();

            // One row per action; defaults = Gazeta-like layout with a reject button.
            $fl_rows = array(
                'settings'   => array('show' => true,  'order' => 1, 'variant' => 'secondary', 'label' => ''),
                'reject-all' => array('show' => true,  'order' => 2, 'variant' => 'primary',   'label' => ''),
                'accept-all' => array('show' => true,  'order' => 3, 'variant' => 'primary',   'label' => ''),
                'partners'   => array('show' => false, 'order' => 4, 'variant' => 'link',      'label' => ''),
            );
            if (!empty($fl['buttons']) && is_array($fl['buttons'])) {
                // Hidden actions are numbered after the visible ones, so orders never collide.
                $hidden_order = count($fl['buttons']);
                foreach ($fl_rows as $action => $row) {
                    $fl_rows[$action]['show'] = false;
                    $fl_rows[$action]['order'] = ++$hidden_order;
                }
                foreach (array_values($fl['buttons']) as $i => $button) {
                    if (isset($button['action'], $fl_rows[$button['action']])) {
                        $fl_rows[$button['action']] = array(
                            'show'    => true,
                            'order'   => $i + 1,
                            'variant' => $button['variant'] ?? 'secondary',
                            'label'   => $button['label'] ?? '',
                        );
                    }
                }
            }
            $fl_labels = array(
                'settings'   => __('Settings (layer 2)', 'fast-open-cmp'),
                'reject-all' => __('Reject all', 'fast-open-cmp'),
                'accept-all' => __('Accept all', 'fast-open-cmp'),
                'partners'   => __('Partner list (layer 3)', 'fast-open-cmp'),
            );
            $variant_labels = array(
                'primary'   => __('Filled button', 'fast-open-cmp'),
                'secondary' => __('Outlined button', 'fast-open-cmp'),
                'link'      => __('Text link', 'fast-open-cmp'),
            );
            ?>
            <h2 class="focmp-mode-all"><?php esc_html_e('Layer 1: buttons and compliance', 'fast-open-cmp'); ?></h2>
            <table class="form-table" id="focmp-first-layer">
                <tr>
                    <th><label for="compliance_profile"><?php esc_html_e('Compliance profile', 'fast-open-cmp'); ?></label></th>
                    <td>
                        <select name="compliance_profile" id="compliance_profile">
                            <option value="certified" <?php selected($profile, 'certified'); ?>><?php esc_html_e('Certified (enforced compliant layout)', 'fast-open-cmp'); ?></option>
                            <option value="custom" <?php selected($profile, 'custom'); ?>><?php esc_html_e('Custom (publisher decides)', 'fast-open-cmp'); ?></option>
                        </select>
                        <p class="description focmp-profile-note" data-profile="certified">
                            <?php esc_html_e('The consent box always offers "Reject all" next to "Accept all" with the same visual weight, keeps the settings one click away and (in TCF mode) lists the purposes and the number of partners on layer 1. Missing buttons are added automatically.', 'fast-open-cmp'); ?>
                        </p>
                        <div class="notice notice-warning inline focmp-profile-note" data-profile="custom" style="margin: 8px 0 0;">
                            <p><?php esc_html_e('The buttons are rendered exactly as configured below. Data protection authorities (EDPB, UODO, CNIL) expect refusing to be as easy as accepting — without a reject option on layer 1 the publisher takes the legal risk.', 'fast-open-cmp'); ?></p>
                        </div>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Layer 1 buttons', 'fast-open-cmp'); ?></th>
                    <td>
                        <table class="widefat striped" style="max-width: 760px;">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e('Show', 'fast-open-cmp'); ?></th>
                                    <th><?php esc_html_e('Action', 'fast-open-cmp'); ?></th>
                                    <th><?php esc_html_e('Order', 'fast-open-cmp'); ?></th>
                                    <th><?php esc_html_e('Style', 'fast-open-cmp'); ?></th>
                                    <th><?php esc_html_e('Label (empty = default text)', 'fast-open-cmp'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($fl_rows as $action => $row): $key = 'fl_buttons[' . $action . ']'; ?>
                                <tr>
                                    <td><input type="checkbox" name="<?php echo esc_attr($key); ?>[show]" value="1" <?php checked($row['show']); ?> aria-label="<?php echo esc_attr($fl_labels[$action]); ?>"></td>
                                    <td><?php echo esc_html($fl_labels[$action]); ?></td>
                                    <td><input type="number" name="<?php echo esc_attr($key); ?>[order]" value="<?php echo esc_attr($row['order']); ?>" min="1" max="9" class="small-text"></td>
                                    <td>
                                        <select name="<?php echo esc_attr($key); ?>[variant]">
                                            <?php foreach ($variant_labels as $variant => $variant_label): ?>
                                                <option value="<?php echo esc_attr($variant); ?>" <?php selected($row['variant'], $variant); ?>><?php echo esc_html($variant_label); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td><input type="text" name="<?php echo esc_attr($key); ?>[label]" value="<?php echo esc_attr($row['label']); ?>" class="regular-text"></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <p class="description"><?php esc_html_e('"Settings" opens layer 2 (purposes), "Partner list" opens layer 3 (vendors, not available in Basic mode).', 'fast-open-cmp'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="fl_close_button"><?php esc_html_e('Close button (×)', 'fast-open-cmp'); ?></label></th>
                    <td>
                        <select name="fl_close_button" id="fl_close_button">
                            <option value="none" <?php selected($fl['closeButton'] ?? 'none', 'none'); ?>><?php esc_html_e('None', 'fast-open-cmp'); ?></option>
                            <option value="reject-all" <?php selected($fl['closeButton'] ?? 'none', 'reject-all'); ?>><?php esc_html_e('Closes and rejects ("continue without accepting")', 'fast-open-cmp'); ?></option>
                        </select>
                    </td>
                </tr>
                <tr class="focmp-mode-tcf-row">
                    <th><?php esc_html_e('TCF summary on layer 1', 'fast-open-cmp'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="fl_show_tcf_summary" value="1" <?php checked($fl['showTcfSummary'] ?? true); ?>>
                            <?php esc_html_e('Show the number of partners and the list of purposes, stacks and special features (required by the TCF Policy; always on in the certified profile)', 'fast-open-cmp'); ?>
                        </label>
                    </td>
                </tr>
            </table>

            <h2 class="focmp-mode-all"><?php esc_html_e('Appearance', 'fast-open-cmp'); ?></h2>
            <table class="form-table">
                <tr>
                    <th><label for="appearance_layout"><?php esc_html_e('Layout', 'fast-open-cmp'); ?></label></th>
                    <td>
                        <select name="appearance[layout]" id="appearance_layout">
                            <option value="modal" <?php selected($ap['layout'] ?? 'modal', 'modal'); ?>><?php esc_html_e('Centered window (modal)', 'fast-open-cmp'); ?></option>
                            <option value="bar" <?php selected($ap['layout'] ?? '', 'bar'); ?>><?php esc_html_e('Bottom bar', 'fast-open-cmp'); ?></option>
                            <option value="corner" <?php selected($ap['layout'] ?? '', 'corner'); ?>><?php esc_html_e('Bottom-right corner', 'fast-open-cmp'); ?></option>
                        </select>
                        <label style="margin-left: 12px;">
                            <input type="checkbox" name="appearance[overlay]" value="1" <?php checked($ap['overlay'] ?? true); ?>>
                            <?php esc_html_e('Dim the page behind the box', 'fast-open-cmp'); ?>
                        </label>
                        <label style="margin-left: 12px;">
                            <input type="checkbox" name="appearance[blockScroll]" value="1" <?php checked(!empty($ap['blockScroll'])); ?>>
                            <?php esc_html_e('Block page scrolling while the box is open', 'fast-open-cmp'); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th><label for="appearance_logo"><?php esc_html_e('Logo URL', 'fast-open-cmp'); ?></label></th>
                    <td>
                        <input type="url" name="appearance[logoUrl]" id="appearance_logo" class="regular-text" value="<?php echo esc_attr($ap['logoUrl'] ?? ''); ?>" placeholder="https://">
                        <input type="text" name="appearance[logoAlt]" class="regular-text" value="<?php echo esc_attr($ap['logoAlt'] ?? ''); ?>" placeholder="<?php esc_attr_e('Alternative text', 'fast-open-cmp'); ?>" aria-label="<?php esc_attr_e('Logo alternative text', 'fast-open-cmp'); ?>">
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Colors', 'fast-open-cmp'); ?></th>
                    <td>
                        <?php
                        $color_fields = array(
                            'primary'     => array(__('Buttons and accents', 'fast-open-cmp'), '#1a1a1a'),
                            'primaryText' => array(__('Text on filled buttons', 'fast-open-cmp'), '#ffffff'),
                            'background'  => array(__('Background', 'fast-open-cmp'), '#ffffff'),
                            'text'        => array(__('Text', 'fast-open-cmp'), '#333333'),
                        );
                        foreach ($color_fields as $color_key => $color_field): ?>
                            <label style="display: inline-block; margin: 0 16px 8px 0;">
                                <input type="color" name="appearance[colors][<?php echo esc_attr($color_key); ?>]" value="<?php echo esc_attr($ap['colors'][$color_key] ?? $color_field[1]); ?>">
                                <?php echo esc_html($color_field[0]); ?>
                            </label>
                        <?php endforeach; ?>
                    </td>
                </tr>
                <tr>
                    <th><label for="appearance_font"><?php esc_html_e('Font', 'fast-open-cmp'); ?></label></th>
                    <td>
                        <input type="text" name="appearance[fontFamily]" id="appearance_font" class="regular-text" value="<?php echo esc_attr($ap['fontFamily'] ?? 'inherit'); ?>">
                        <p class="description"><?php esc_html_e('"inherit" = the site\'s font, "system" = the operating system font, or a CSS font-family (e.g. "Open Sans", sans-serif). No web fonts are loaded by the consent box.', 'fast-open-cmp'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Corners and buttons', 'fast-open-cmp'); ?></th>
                    <td>
                        <label><?php esc_html_e('Box radius (px)', 'fast-open-cmp'); ?> <input type="number" name="appearance[borderRadius]" min="0" max="32" class="small-text" value="<?php echo esc_attr($ap['borderRadius'] ?? 4); ?>"></label>
                        <label style="margin-left: 12px;"><?php esc_html_e('Button radius (px)', 'fast-open-cmp'); ?> <input type="number" name="appearance[buttonRadius]" min="0" max="32" class="small-text" value="<?php echo esc_attr($ap['buttonRadius'] ?? 0); ?>"></label>
                        <label style="margin-left: 12px;"><input type="checkbox" name="appearance[uppercaseButtons]" value="1" <?php checked($ap['uppercaseButtons'] ?? true); ?>> <?php esc_html_e('Uppercase button labels', 'fast-open-cmp'); ?></label>
                    </td>
                </tr>
                <tr>
                    <th><label for="appearance_privacy_url"><?php esc_html_e('Privacy policy URL', 'fast-open-cmp'); ?></label></th>
                    <td>
                        <input type="url" name="appearance[privacyPolicyUrl]" id="appearance_privacy_url" class="regular-text" value="<?php echo esc_attr($ap['privacyPolicyUrl'] ?? ''); ?>" placeholder="https://">
                        <p class="description"><?php esc_html_e('Shown as a link on layer 1.', 'fast-open-cmp'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e('"Privacy settings" button', 'fast-open-cmp'); ?></th>
                    <td>
                        <label><input type="checkbox" name="appearance[reopenButton][enabled]" value="1" <?php checked(!empty($ap['reopenButton']['enabled'])); ?>> <?php esc_html_e('Show a floating button that reopens the settings after a decision', 'fast-open-cmp'); ?></label>
                        <select name="appearance[reopenButton][position]" aria-label="<?php esc_attr_e('Button position', 'fast-open-cmp'); ?>">
                            <option value="bottom-left" <?php selected($ap['reopenButton']['position'] ?? 'bottom-left', 'bottom-left'); ?>><?php esc_html_e('Bottom left', 'fast-open-cmp'); ?></option>
                            <option value="bottom-right" <?php selected($ap['reopenButton']['position'] ?? '', 'bottom-right'); ?>><?php esc_html_e('Bottom right', 'fast-open-cmp'); ?></option>
                        </select>
                        <p class="description"><?php esc_html_e('Alternatively add a link anywhere on the site: <a href="#cmp-settings">…</a> or any element with the data-cmp-open attribute.', 'fast-open-cmp'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="appearance_css"><?php esc_html_e('Custom CSS', 'fast-open-cmp'); ?></label></th>
                    <td>
                        <textarea name="appearance[customCss]" id="appearance_css" class="large-text code" rows="4" <?php disabled(!current_user_can('unfiltered_html')); ?>><?php echo esc_textarea($ap['customCss'] ?? ''); ?></textarea>
                        <p class="description"><?php esc_html_e('Scope rules to #open-cmp-container. Requires the unfiltered_html capability.', 'fast-open-cmp'); ?></p>
                    </td>
                </tr>
            </table>

            <h2 class="focmp-mode-all"><?php esc_html_e('Consent box dimensions', 'fast-open-cmp'); ?></h2>
            <table class="form-table">
                <tr>
                    <th><label for="consentbox_width"><?php esc_html_e('Width (px)', 'fast-open-cmp'); ?></label></th>
                    <td>
                        <input type="number" name="consentbox_width" id="consentbox_width" placeholder="800" value="<?php echo esc_attr($config['dimensions']['width'] ?? ''); ?>">
                        <p class="description"><?php esc_html_e('Default: 800px', 'fast-open-cmp'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="consentbox_height"><?php esc_html_e('Height (px)', 'fast-open-cmp'); ?></label></th>
                    <td>
                        <input type="number" name="consentbox_height" id="consentbox_height" placeholder="520" value="<?php echo esc_attr($config['dimensions']['height'] ?? ''); ?>">
                        <p class="description"><?php esc_html_e('Default: 520px', 'fast-open-cmp'); ?></p>
                    </td>
                </tr>
            </table>

            <h2 class="focmp-mode-tcf"><?php esc_html_e('TCF Policy version', 'fast-open-cmp'); ?></h2>
            <table class="form-table">
                <tr>
                    <th><?php esc_html_e('TCF Policy version', 'fast-open-cmp'); ?></th>
                    <td>
                        <strong><?php echo esc_html(FOCMP_TCF::POLICY_VERSION); ?></strong>
                        <p class="description"><?php esc_html_e('Set by the plugin (TCF 2.3 = 5) and updated with plugin releases — a CMP must always write the current policy version.', 'fast-open-cmp'); ?></p>
                    </td>
                </tr>
            </table>

            <h2 class="focmp-mode-gcm"><?php esc_html_e('Google Consent Mode v2', 'fast-open-cmp'); ?></h2>
            <table class="form-table">
                <tr>
                    <th><?php esc_html_e('Enable GCM v2', 'fast-open-cmp'); ?></th>
                    <td>
                        <?php
                        $gcm_enabled = $config['googleConsentMode']['enabled'] ?? (!empty($settings['gcm_enabled']) ? 1 : 0);
                        ?>
                        <label>
                            <input type="checkbox" name="gcm_enabled" value="1" <?php checked(!empty($gcm_enabled)); ?>>
                            <?php esc_html_e('Send gtag consent default/update signals', 'fast-open-cmp'); ?>
                        </label>
                        <p class="description" style="max-width: 640px; margin-top: 8px;">
                            <?php esc_html_e('Google Consent Mode v2 lets Google tags (Google Analytics, Google Ads, gtag.js) adjust their behaviour to the user\'s consent. When enabled, the SDK emits gtag("consent", "default"/"update", …) for the signals ad_storage, analytics_storage, ad_user_data and ad_personalization, derived from the user\'s choices.', 'fast-open-cmp'); ?>
                            <br>
                            <strong><?php esc_html_e('Enable it', 'fast-open-cmp'); ?></strong> <?php esc_html_e('if this site uses Google Analytics / Google Ads / gtag and you want them to respect consent (recommended for EEA/UK traffic).', 'fast-open-cmp'); ?>
                            <strong><?php esc_html_e('Leave it off', 'fast-open-cmp'); ?></strong> <?php esc_html_e('if the site does not use any Google tags, or another tool already manages Consent Mode.', 'fast-open-cmp'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th>
                        <span class="focmp-gcm-map-tcf"><?php esc_html_e('IAB purpose mapping', 'fast-open-cmp'); ?></span>
                        <span class="focmp-gcm-map-custom"><?php esc_html_e('Custom consent mapping', 'fast-open-cmp'); ?></span>
                    </th>
                    <td>
                        <p class="description focmp-gcm-map-custom" style="margin-bottom: 8px;">
                            <?php esc_html_e('Without TCF the signals are driven by your custom consents: enter their numbers (e.g. 2 = custom-2, shown next to each consent below). A signal is granted when all listed consents are granted.', 'fast-open-cmp'); ?>
                        </p>
                        <?php
                        // Get mapping from template config or global settings
                        $gcm_mapping_raw = $config['googleConsentMode']['mapping'] ?? null;
                        if ($gcm_mapping_raw === null) {
                            $gcm_mapping_raw = $settings['gcm_mapping'] ?? array(
                                'ad_storage' => '1',
                                'analytics_storage' => '1',
                                'ad_user_data' => '1,2,3,4',
                                'ad_personalization' => '3,4',
                            );
                        }
                        // Normalize: if values are arrays, convert to comma-separated strings
                        $gcm_mapping = array();
                        foreach ($gcm_mapping_raw as $k => $v) {
                            $gcm_mapping[$k] = is_array($v) ? implode(',', $v) : $v;
                        }

                        $gcm_signals = array(
                            'ad_storage' => __('ad_storage (advertising cookies)', 'fast-open-cmp'),
                            'analytics_storage' => __('analytics_storage (analytics cookies)', 'fast-open-cmp'),
                            'ad_user_data' => __('ad_user_data (user data for ads)', 'fast-open-cmp'),
                            'ad_personalization' => __('ad_personalization (ad personalization)', 'fast-open-cmp'),
                        );
                        foreach ($gcm_signals as $signal_key => $signal_label): ?>
                        <p>
                            <code style="display: inline-block; width: 160px;"><?php echo esc_html($signal_key); ?></code>
                            <label>
                                <span class="focmp-gcm-map-tcf"><?php esc_html_e('← Purposes:', 'fast-open-cmp'); ?></span>
                                <span class="focmp-gcm-map-custom"><?php esc_html_e('← Custom consents:', 'fast-open-cmp'); ?></span>
                                <input type="text" name="gcm_mapping[<?php echo esc_attr($signal_key); ?>]" class="small-text" style="width: 100px;"
                                       value="<?php echo esc_attr($gcm_mapping[$signal_key] ?? ''); ?>">
                            </label>
                            <span class="description"><?php echo esc_html($signal_label); ?></span>
                        </p>
                        <?php endforeach; ?>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e('wait_for_update', 'fast-open-cmp'); ?></th>
                    <td>
                        <input type="number" name="gcm_wait_for_update" class="small-text"
                               value="<?php echo esc_attr($config['googleConsentMode']['waitForUpdate'] ?? $settings['gcm_wait_for_update'] ?? 500); ?>">
                        <span class="description"><?php esc_html_e('ms — how long to wait for the CMP before firing Google tags (default: 500)', 'fast-open-cmp'); ?></span>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Without consent', 'fast-open-cmp'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="gcm_url_passthrough" value="1" <?php checked(!empty($config['googleConsentMode']['urlPassthrough'])); ?>>
                            <?php esc_html_e('url_passthrough — pass ad click information in URLs when ad_storage is denied', 'fast-open-cmp'); ?>
                        </label><br>
                        <label>
                            <input type="checkbox" name="gcm_ads_data_redaction" value="1" <?php checked(!empty($config['googleConsentMode']['adsDataRedaction'])); ?>>
                            <?php esc_html_e('ads_data_redaction — redact ad click identifiers when ad_storage is denied', 'fast-open-cmp'); ?>
                        </label>
                    </td>
                </tr>
            </table>

            <h2 class="focmp-mode-all"><?php esc_html_e('Cookie settings', 'fast-open-cmp'); ?></h2>
            <table class="form-table">
                <tr>
                    <th><label for="cookie_name"><?php esc_html_e('Cookie name', 'fast-open-cmp'); ?></label></th>
                    <td>
                        <input type="text" name="cookie_name" id="cookie_name" class="regular-text"
                               value="<?php echo esc_attr($config['cookieName'] ?? $settings['cookie_name'] ?? FOCMP_TCF::COOKIE_NAME); ?>">
                        <p class="description"><?php esc_html_e('Standard TCF name: eupubconsent-v2', 'fast-open-cmp'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="cookie_expiration_days"><?php esc_html_e('Cookie lifetime (days)', 'fast-open-cmp'); ?></label></th>
                    <td>
                        <input type="number" name="cookie_expiration_days" id="cookie_expiration_days" class="small-text"
                               value="<?php echo esc_attr($config['cookieExpirationDays'] ?? $settings['cookie_expiration_days'] ?? 180); ?>">
                    </td>
                </tr>
                <tr>
                    <th><label for="consent_max_age_days"><?php esc_html_e('Ask again after (days)', 'fast-open-cmp'); ?></label></th>
                    <td>
                        <input type="number" name="consent_max_age_days" id="consent_max_age_days" class="small-text" min="0"
                               value="<?php echo esc_attr($config['consentMaxAgeDays'] ?? 395); ?>">
                        <p class="description"><?php esc_html_e('A stored decision older than this is collected again (0 = never). 395 days ≈ 13 months, the period regulators consider reasonable. A change of the TCF Policy version always triggers a new request.', 'fast-open-cmp'); ?></p>
                    </td>
                </tr>
                <tr class="focmp-mode-tcf-row">
                    <th><?php esc_html_e('New partners', 'fast-open-cmp'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="renew_on_new_vendors" value="1" <?php checked(!empty($config['renewOnNewVendors'])); ?>>
                            <?php esc_html_e('Ask again when IAB partners the user has not seen are added to the list', 'fast-open-cmp'); ?>
                        </label>
                        <p class="description"><?php esc_html_e('New partners never get consent from an older decision, so this is optional. The GVL changes weekly — enabling it shows the box again more often.', 'fast-open-cmp'); ?></p>
                    </td>
                </tr>
            </table>

            <h2 class="focmp-mode-tcf"><?php esc_html_e('IAB stacks', 'fast-open-cmp'); ?></h2>
            <table class="form-table">
                <tr>
                    <th><label for="enabled_stacks"><?php esc_html_e('Enabled stacks', 'fast-open-cmp'); ?></label></th>
                    <td>
                        <?php
                        $tpl_stacks = $config['enabledStacks'] ?? $settings['enabled_stacks'] ?? array(42);
                        $tpl_stacks_str = is_array($tpl_stacks) ? implode(', ', $tpl_stacks) : $tpl_stacks;
                        ?>
                        <input type="text" name="enabled_stacks" id="enabled_stacks" class="regular-text"
                               value="<?php echo esc_attr($tpl_stacks_str); ?>">
                        <p class="description">
                            <?php esc_html_e('Stack IDs separated by commas. A stack groups IAB purposes into one checkbox.', 'fast-open-cmp'); ?><br>
                            <?php esc_html_e('Default: 42 (purposes 2-10: personalised ads, content, measurement, audiences, service improvement)', 'fast-open-cmp'); ?><br>
                            <?php
                            printf(
                                /* translators: %s: link to the IAB Europe TCF policies page */
                                esc_html__('More information about stacks can be found in the %s.', 'fast-open-cmp'),
                                '<a href="https://iabeurope.eu/iab-europe-transparency-consent-framework-policies/#headline-720-18959" target="_blank" rel="noopener noreferrer">'
                                    . esc_html__('IAB Europe TCF policies', 'fast-open-cmp') . '</a>'
                            );
                            ?>
                        </p>
                    </td>
                </tr>
            </table>

            <h2 class="focmp-mode-all"><?php esc_html_e('Texts - Layer 1 (Banner)', 'fast-open-cmp'); ?></h2>
            <table class="form-table">
                <tr>
                    <th><label for="text_first_title"><?php esc_html_e('Title', 'fast-open-cmp'); ?></label></th>
                    <td><input type="text" name="text_first_title" id="text_first_title" class="large-text" value="<?php echo esc_attr($texts['firstLayer']['title'] ?? ''); ?>"></td>
                </tr>
                <tr>
                    <th><label for="text_first_description"><?php esc_html_e('Description', 'fast-open-cmp'); ?></label></th>
                    <td><?php
                        wp_editor(
                            $texts['firstLayer']['description'] ?? '',
                            'text_first_description',
                            array(
                                'textarea_name' => 'text_first_description',
                                'textarea_rows' => 5,
                                'media_buttons' => false,
                                'teeny' => true,
                                'quicktags' => true,
                            )
                        );
                    ?></td>
                </tr>
                <tr>
                    <th><label for="text_first_partners_link"><?php esc_html_e('Partner link text', 'fast-open-cmp'); ?></label></th>
                    <td><input type="text" name="text_first_partners_link" class="regular-text" value="<?php echo esc_attr($texts['firstLayer']['partnersLinkText'] ?? ''); ?>"></td>
                </tr>
                <tr>
                    <th><label for="text_first_settings_button"><?php esc_html_e('Settings button', 'fast-open-cmp'); ?></label></th>
                    <td><input type="text" name="text_first_settings_button" class="regular-text" value="<?php echo esc_attr($texts['firstLayer']['settingsButtonText'] ?? ''); ?>"></td>
                </tr>
                <tr>
                    <th><label for="text_first_accept_button"><?php esc_html_e('Accept button', 'fast-open-cmp'); ?></label></th>
                    <td><input type="text" name="text_first_accept_button" class="regular-text" value="<?php echo esc_attr($texts['firstLayer']['acceptAllButtonText'] ?? ''); ?>"></td>
                </tr>
                <?php
                $extra_first_texts = array(
                    'text_first_reject_button'  => array('rejectAllButtonText', __('Reject button', 'fast-open-cmp'), ''),
                    'text_first_close_label'    => array('closeButtonLabel', __('Close button (× label for screen readers)', 'fast-open-cmp'), ''),
                    'text_first_privacy_link'   => array('privacyPolicyLinkText', __('Privacy policy link', 'fast-open-cmp'), ''),
                    'text_first_vendor_count'   => array('vendorCountText', __('Partner count sentence', 'fast-open-cmp'), __('Placeholders: {iab} and {other}', 'fast-open-cmp')),
                    'text_first_purposes_intro' => array('purposesIntroText', __('Purposes intro', 'fast-open-cmp'), ''),
                    'text_first_reopen_label'   => array('reopenButtonLabel', __('"Privacy settings" button', 'fast-open-cmp'), ''),
                );
                foreach ($extra_first_texts as $field => $def): ?>
                <tr>
                    <th><label for="<?php echo esc_attr($field); ?>"><?php echo esc_html($def[1]); ?></label></th>
                    <td>
                        <input type="text" name="<?php echo esc_attr($field); ?>" id="<?php echo esc_attr($field); ?>" class="large-text" value="<?php echo esc_attr($texts['firstLayer'][$def[0]] ?? ''); ?>" placeholder="<?php esc_attr_e('Default text', 'fast-open-cmp'); ?>">
                        <?php if ($def[2]): ?><p class="description"><?php echo esc_html($def[2]); ?></p><?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </table>

            <h2 class="focmp-mode-tcf focmp-mode-gcm"><?php esc_html_e('Texts - Layer 2 (Settings)', 'fast-open-cmp'); ?></h2>
            <table class="form-table">
                <tr>
                    <th><label><?php esc_html_e('Title', 'fast-open-cmp'); ?></label></th>
                    <td><input type="text" name="text_second_title" class="large-text" value="<?php echo esc_attr($texts['secondLayer']['title'] ?? ''); ?>"></td>
                </tr>
                <tr>
                    <th><label><?php esc_html_e('Description', 'fast-open-cmp'); ?></label></th>
                    <td><?php
                        wp_editor(
                            $texts['secondLayer']['description'] ?? '',
                            'text_second_description',
                            array(
                                'textarea_name' => 'text_second_description',
                                'textarea_rows' => 5,
                                'media_buttons' => false,
                                'teeny' => true,
                                'quicktags' => true,
                            )
                        );
                    ?></td>
                </tr>
                <tr>
                    <th><label><?php esc_html_e('Partner link', 'fast-open-cmp'); ?></label></th>
                    <td><input type="text" name="text_second_partners_link" class="regular-text" value="<?php echo esc_attr($texts['secondLayer']['partnersLinkText'] ?? ''); ?>"></td>
                </tr>
                <tr>
                    <th><label><?php esc_html_e('Back button', 'fast-open-cmp'); ?></label></th>
                    <td><input type="text" name="text_second_back_button" class="regular-text" value="<?php echo esc_attr($texts['secondLayer']['backButtonText'] ?? ''); ?>"></td>
                </tr>
                <tr>
                    <th><label><?php esc_html_e('Preferences title', 'fast-open-cmp'); ?></label></th>
                    <td><input type="text" name="text_second_preferences_title" class="regular-text" value="<?php echo esc_attr($texts['secondLayer']['preferencesTitle'] ?? ''); ?>"></td>
                </tr>
                <tr>
                    <th><label><?php esc_html_e('Confirm button', 'fast-open-cmp'); ?></label></th>
                    <td><input type="text" name="text_second_confirm_button" class="regular-text" value="<?php echo esc_attr($texts['secondLayer']['confirmButtonText'] ?? ''); ?>"></td>
                </tr>
                <tr>
                    <th><label><?php esc_html_e('Reject button', 'fast-open-cmp'); ?></label></th>
                    <td><input type="text" name="text_second_reject_button" class="regular-text" value="<?php echo esc_attr($texts['secondLayer']['rejectButtonText'] ?? ''); ?>"></td>
                </tr>
                <tr>
                    <th><label><?php esc_html_e('Accept button', 'fast-open-cmp'); ?></label></th>
                    <td><input type="text" name="text_second_accept_button" class="regular-text" value="<?php echo esc_attr($texts['secondLayer']['acceptAllButtonText'] ?? ''); ?>"></td>
                </tr>
            </table>

            <h2 class="focmp-mode-tcf"><?php esc_html_e('Texts - Layer 3 (Partners)', 'fast-open-cmp'); ?></h2>
            <table class="form-table">
                <tr>
                    <th><label><?php esc_html_e('Title', 'fast-open-cmp'); ?></label></th>
                    <td><input type="text" name="text_third_title" class="large-text" value="<?php echo esc_attr($texts['thirdLayer']['title'] ?? ''); ?>"></td>
                </tr>
                <tr>
                    <th><label><?php esc_html_e('Description', 'fast-open-cmp'); ?></label></th>
                    <td><textarea name="text_third_description" class="large-text" rows="2"><?php echo esc_textarea($texts['thirdLayer']['description'] ?? ''); ?></textarea></td>
                </tr>
                <tr>
                    <th><label><?php esc_html_e('Back button', 'fast-open-cmp'); ?></label></th>
                    <td><input type="text" name="text_third_back_button" class="regular-text" value="<?php echo esc_attr($texts['thirdLayer']['backButtonText'] ?? ''); ?>"></td>
                </tr>
                <tr>
                    <th><label><?php esc_html_e('Confirm button', 'fast-open-cmp'); ?></label></th>
                    <td><input type="text" name="text_third_confirm_button" class="regular-text" value="<?php echo esc_attr($texts['thirdLayer']['confirmButtonText'] ?? ''); ?>"></td>
                </tr>
                <tr>
                    <th><label><?php esc_html_e('Reject button', 'fast-open-cmp'); ?></label></th>
                    <td><input type="text" name="text_third_reject_button" class="regular-text" value="<?php echo esc_attr($texts['thirdLayer']['rejectButtonText'] ?? ''); ?>"></td>
                </tr>
                <tr>
                    <th><label><?php esc_html_e('Accept button', 'fast-open-cmp'); ?></label></th>
                    <td><input type="text" name="text_third_accept_button" class="regular-text" value="<?php echo esc_attr($texts['thirdLayer']['acceptAllButtonText'] ?? ''); ?>"></td>
                </tr>
                <tr>
                    <th><label><?php esc_html_e('Privacy policy', 'fast-open-cmp'); ?></label></th>
                    <td><input type="text" name="text_third_privacy_policy" class="regular-text" value="<?php echo esc_attr($texts['thirdLayer']['privacyPolicyText'] ?? ''); ?>"></td>
                </tr>
                <tr>
                    <th><label><?php esc_html_e('Legitimate interest', 'fast-open-cmp'); ?></label></th>
                    <td><input type="text" name="text_third_legitimate_interest" class="regular-text" value="<?php echo esc_attr($texts['thirdLayer']['legitimateInterestText'] ?? ''); ?>"></td>
                </tr>
                <tr>
                    <th><label><?php esc_html_e('Object button', 'fast-open-cmp'); ?></label></th>
                    <td><input type="text" name="text_third_object_button" class="regular-text" value="<?php echo esc_attr($texts['thirdLayer']['objectButtonText'] ?? ''); ?>"></td>
                </tr>
                <tr>
                    <th><label><?php esc_html_e('Always active', 'fast-open-cmp'); ?></label></th>
                    <td><input type="text" name="text_third_always_active" class="regular-text" value="<?php echo esc_attr($texts['thirdLayer']['alwaysActiveText'] ?? ''); ?>"></td>
                </tr>
                <tr>
                    <th><label><?php esc_html_e('Group: IAB', 'fast-open-cmp'); ?></label></th>
                    <td><input type="text" name="text_vendor_group_iab" class="regular-text" value="<?php echo esc_attr($texts['thirdLayer']['vendorGroups']['iab'] ?? ''); ?>"></td>
                </tr>
                <tr>
                    <th><label><?php esc_html_e('Group: Google', 'fast-open-cmp'); ?></label></th>
                    <td><input type="text" name="text_vendor_group_google" class="regular-text" value="<?php echo esc_attr($texts['thirdLayer']['vendorGroups']['google'] ?? ''); ?>"></td>
                </tr>
                <tr>
                    <th><label><?php esc_html_e('Group: Other', 'fast-open-cmp'); ?></label></th>
                    <td><input type="text" name="text_vendor_group_other" class="regular-text" value="<?php echo esc_attr($texts['thirdLayer']['vendorGroups']['other'] ?? ''); ?>"></td>
                </tr>
            </table>

            <h2><?php esc_html_e('Custom Consents', 'fast-open-cmp'); ?></h2>
            <p class="description" style="max-width: 760px;">
                <?php esc_html_e('To run a script or embed only after a consent, mark it on your site with its number, e.g.', 'fast-open-cmp'); ?>
                <code>&lt;script type="text/plain" data-cmp-requires="custom-2"&gt;…&lt;/script&gt;</code>
                <?php esc_html_e('or', 'fast-open-cmp'); ?> <code>&lt;iframe data-cmp-src="…" data-cmp-requires="custom-2"&gt;</code>.
            </p>
            <?php
            // One row per consent, keyed by its stable id (new rows: "new-N"). The id never
            // depends on the row position — stored user choices are keyed by it.
            $render_consent_row = function ($key, $consent) {
                $name = 'custom_consents[' . $key . ']';
                ?>
                <div class="focmp-custom-consent-row" style="background: #f9f9f9; padding: 15px; margin-bottom: 10px; border: 1px solid #ddd;">
                    <input type="hidden" name="<?php echo esc_attr($name); ?>[id]" value="<?php echo esc_attr($consent['id'] ?? ''); ?>">
                    <p>
                        <label><?php esc_html_e('Title', 'fast-open-cmp'); ?>:</label>
                        <input type="text" name="<?php echo esc_attr($name); ?>[title]" class="regular-text" value="<?php echo esc_attr($consent['title'] ?? ''); ?>">
                        <?php if (!empty($consent['id'])): ?><code style="margin-left: 8px;"><?php echo esc_html($consent['id']); ?></code><?php endif; ?>
                    </p>
                    <p>
                        <label><?php esc_html_e('Description', 'fast-open-cmp'); ?>:</label>
                        <textarea name="<?php echo esc_attr($name); ?>[description]" class="large-text" rows="2"><?php echo esc_textarea($consent['description'] ?? ''); ?></textarea>
                    </p>
                    <p>
                        <label>
                            <input type="checkbox" name="<?php echo esc_attr($name); ?>[li]" value="1" <?php checked(!empty($consent['legitimateInterestAllowed'])); ?>>
                            <?php esc_html_e('Legitimate interest allowed', 'fast-open-cmp'); ?>
                        </label>
                        <button type="button" class="button focmp-remove-consent" style="float: right;"><?php esc_html_e('Delete', 'fast-open-cmp'); ?></button>
                    </p>
                </div>
                <?php
            };
            ?>
            <div id="custom-consents-container">
                <?php foreach ($custom_consents as $i => $consent) {
                    $render_consent_row(!empty($consent['id']) ? $consent['id'] : 'old-' . $i, $consent);
                } ?>
            </div>
            <template id="focmp-consent-row-template">
                <?php $render_consent_row('__KEY__', array()); ?>
            </template>
            <p>
                <button type="button" class="button" id="focmp-add-consent"><?php esc_html_e('Add custom consent', 'fast-open-cmp'); ?></button>
            </p>

            <p class="submit">
                <button type="submit" name="focmp_save_template" class="button button-primary"><?php esc_html_e('Save template', 'fast-open-cmp'); ?></button>
                <a href="<?php echo esc_url(admin_url('admin.php?page=focmp-templates')); ?>" class="button"><?php esc_html_e('Cancel', 'fast-open-cmp'); ?></a>
            </p>
        </form>

        <script>
        jQuery(document).ready(function($) {
            var consentIndex = 0;

            $('#focmp-add-consent').on('click', function() {
                var html = $('#focmp-consent-row-template').html().replace(/__KEY__/g, 'new-' + (consentIndex++));
                $('#custom-consents-container').append(html);
            });

            $(document).on('click', '.focmp-remove-consent', function() {
                $(this).closest('.focmp-custom-consent-row').remove();
            });

            // Mode-dependent section visibility
            function updateModeSections() {
                var mode = $('#template_mode').val();

                // Hide all mode-dependent sections (h2 + following table)
                $('.focmp-mode-tcf, .focmp-mode-gcm, .focmp-mode-all').each(function() {
                    $(this).hide().next('table').hide();
                });

                // Show sections based on mode
                if (mode === 'tcf_gcm') {
                    $('.focmp-mode-tcf, .focmp-mode-gcm, .focmp-mode-all').each(function() {
                        $(this).show().next('table').show();
                    });
                } else if (mode === 'gcm_only') {
                    $('.focmp-mode-gcm, .focmp-mode-all').each(function() {
                        $(this).show().next('table').show();
                    });
                } else if (mode === 'basic') {
                    $('.focmp-mode-all').each(function() {
                        $(this).show().next('table').show();
                    });
                }
            }

            $('#template_mode').on('change', updateModeSections);
            $('#template_mode').on('change', function() {
                var tcf = $(this).val() === 'tcf_gcm';
                $('.focmp-mode-tcf-row').toggle(tcf);
                $('.focmp-gcm-map-tcf').toggle(tcf);
                $('.focmp-gcm-map-custom').toggle(!tcf);
            }).trigger('change');
            updateModeSections();

            // ---- Live preview -------------------------------------------------
            var $panel = $('#focmp-preview-panel');
            var frame = document.getElementById('focmp-preview-frame');
            var previewReady = false, previewLayer = 1, previewTimer = null, previewLoaded = false;
            var sdkUrl = <?php echo wp_json_encode(FOCMP_PLUGIN_URL . 'assets/js/cmp-sdk.js?ver=' . (int) @filemtime(FOCMP_PLUGIN_DIR . 'assets/js/cmp-sdk.js')); ?>;
            var sampleText = <?php echo wp_json_encode(__('Sample article — this is how the consent box appears on top of your page.', 'fast-open-cmp')); ?>;

            function previewDocument() {
                return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">' +
                    '<style>body{margin:0;font-family:Georgia,serif;color:#222}main{max-width:680px;margin:40px auto;padding:0 20px}' +
                    'h1{font-size:28px}.line{height:12px;background:#e5e5e5;margin:14px 0;border-radius:3px}</style>' +
                    '<script>window.__autocmp=false;<\/script><script src="' + sdkUrl + '"><\/script></head><body><main><h1>' +
                    $('<div>').text(sampleText).html() + '</h1>' + new Array(14).join('<div class="line"></div>') + '</main>' +
                    '<script>var P=' + JSON.stringify(window.location.origin) + ';' +
                    'window.addEventListener("message",function(e){if(e.origin!==P||!e.data||!e.data.focmpPreview)return;' +
                    'window.OpenCMP.preview(e.data.config,e.data.layer||1);});parent.postMessage({focmpPreviewReady:true},P);<\/script>' +
                    '</body></html>';
            }

            function previewPayload() {
                if (window.tinyMCE) { window.tinyMCE.triggerSave(); }
                var data = $('#focmp-template-form').serializeArray();
                data.push({ name: 'action', value: 'focmp_preview_config' }, { name: 'nonce', value: focmpAdmin.nonce });
                return $.param(data);
            }

            function refreshPreview() {
                if (!previewReady) { return; }
                $.post(focmpAdmin.ajaxUrl, previewPayload()).done(function(response) {
                    if (response && response.success) {
                        frame.contentWindow.postMessage({ focmpPreview: true, config: response.data, layer: previewLayer }, window.location.origin);
                    }
                });
            }

            window.addEventListener('message', function(e) {
                if (e.source === frame.contentWindow && e.data && e.data.focmpPreviewReady) {
                    previewReady = true;
                    refreshPreview();
                }
            });

            $('#focmp-preview-toggle').on('click', function() {
                var open = $panel.prop('hidden');
                $panel.prop('hidden', !open);
                $(this).attr('aria-expanded', open ? 'true' : 'false');
                if (open && !previewLoaded) { previewLoaded = true; frame.srcdoc = previewDocument(); }
            });
            $('#focmp-preview-close').on('click', function() { $('#focmp-preview-toggle').trigger('click'); });
            $panel.on('click', '[data-preview-layer]', function() { previewLayer = parseInt($(this).data('preview-layer'), 10) || 1; refreshPreview(); });
            // Desktop preview renders 1200px wide and is scaled down to the panel;
            // mobile renders at 390px.
            function fitPreview() {
                var stage = $panel.find('.focmp-preview-stage')[0];
                if (!stage || $panel.prop('hidden')) { return; }
                var mobile = $('#focmp-preview-mobile').prop('checked');
                var width = mobile ? 390 : 1200;
                var availW = stage.clientWidth - 24, availH = stage.clientHeight - 24;
                var scale = Math.min(1, availW / width);
                frame.style.width = width + 'px';
                frame.style.height = Math.round(availH / scale) + 'px';
                frame.style.transform = 'scale(' + scale + ')';
                frame.style.left = Math.max(12, Math.round((stage.clientWidth - width * scale) / 2)) + 'px';
            }
            $(window).on('resize', fitPreview);
            $('#focmp-preview-toggle').on('click', function() { setTimeout(fitPreview, 0); });
            $('#focmp-preview-mobile').on('change', fitPreview);
            $('#focmp-template-form').on('change input', ':input', function() {
                if ($panel.prop('hidden')) { return; }
                clearTimeout(previewTimer);
                previewTimer = setTimeout(refreshPreview, 600);
            });
            if (window.tinyMCE) {
                $(document).on('tinymce-editor-init', function(event, editor) {
                    editor.on('keyup change', function() {
                        if ($panel.prop('hidden')) { return; }
                        clearTimeout(previewTimer);
                        previewTimer = setTimeout(refreshPreview, 600);
                    });
                });
            }

            // Compliance profile: show the matching explanation
            function updateProfileNote() {
                var profile = $('#compliance_profile').val();
                $('.focmp-profile-note').each(function() {
                    $(this).toggle($(this).data('profile') === profile);
                });
            }
            $('#compliance_profile').on('change', updateProfileNote);
            updateProfileNote();
        });
        </script>

    <?php else: ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th style="width: 50px;"><?php esc_html_e('ID', 'fast-open-cmp'); ?></th>
                    <th><?php esc_html_e('Name', 'fast-open-cmp'); ?></th>
                    <th><?php esc_html_e('Mode', 'fast-open-cmp'); ?></th>
                    <th><?php esc_html_e('Language', 'fast-open-cmp'); ?></th>
                    <th><?php esc_html_e('Vendor list', 'fast-open-cmp'); ?></th>
                    <th><?php esc_html_e('Created at', 'fast-open-cmp'); ?></th>
                    <th><?php esc_html_e('Actions', 'fast-open-cmp'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($templates)): ?>
                    <tr>
                        <td colspan="7"><?php esc_html_e('No templates. Add the first one.', 'fast-open-cmp'); ?></td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($templates as $t): ?>
                        <tr>
                            <?php
                                $t_config = json_decode($t->config, true);
                                $t_mode = $t_config['mode'] ?? 'tcf_gcm';
                                $mode_labels = array(
                                    'tcf_gcm' => __('Full (TCF+GCM)', 'fast-open-cmp'),
                                    'gcm_only' => __('Consent Mode v2', 'fast-open-cmp'),
                                    'basic' => __('Basic', 'fast-open-cmp'),
                                );
                            ?>
                            <td><?php echo esc_html($t->id); ?></td>
                            <td><strong><?php echo esc_html($t->name); ?></strong></td>
                            <td><?php echo esc_html($mode_labels[$t_mode] ?? $t_mode); ?></td>
                            <td><?php echo esc_html(strtoupper($t->language)); ?></td>
                            <td><?php
                                if (!empty($t->vendor_list_id)) {
                                    $vl_name = '';
                                    foreach ($vendor_lists as $vl) {
                                        if ($vl->id == $t->vendor_list_id) {
                                            $vl_name = $vl->name;
                                            break;
                                        }
                                    }
                                    echo esc_html($vl_name ?: '#' . $t->vendor_list_id);
                                } else {
                                    echo '<em>' . esc_html__('Default', 'fast-open-cmp') . '</em>';
                                }
                            ?></td>
                            <td><?php echo esc_html($t->created_at); ?></td>
                            <td>
                                <a href="<?php echo esc_url(admin_url('admin.php?page=focmp-templates&action=edit&id=' . $t->id)); ?>"><?php esc_html_e('Edit', 'fast-open-cmp'); ?></a> |
                                <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=focmp-templates&action=clone_template&id=' . $t->id), 'clone_template_' . $t->id)); ?>"><?php esc_html_e('Clone', 'fast-open-cmp'); ?></a> |
                                <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=focmp-templates&action=delete_template&id=' . $t->id), 'delete_template_' . $t->id)); ?>"
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
