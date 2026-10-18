<?php
if (!defined('ABSPATH')) exit;
?>
<div class="wrap focmp-wrap">
    <h1><?php esc_html_e('IAB Vendors', 'fast-open-cmp'); ?></h1>

    <?php if (isset($_GET['message'])): ?>
        <div class="notice notice-success is-dismissible">
            <p><?php esc_html_e('Updated successfully', 'fast-open-cmp'); ?></p>
        </div>
    <?php endif; ?>

    <div class="focmp-import-section" style="background: #fff; padding: 20px; margin-bottom: 20px; border: 1px solid #ddd;">
        <h2><?php esc_html_e('Import from the IAB Global Vendor List', 'fast-open-cmp'); ?></h2>
        <p><?php esc_html_e('Import the vendor list from the official IAB URL or a local file.', 'fast-open-cmp'); ?></p>

        <p>
            <label><?php esc_html_e('Import URL:', 'fast-open-cmp'); ?></label><br>
            <input type="text" id="focmp-gvl-url" class="large-text"
                   value="<?php echo esc_attr($settings['gvl_url'] ?? FOCMP_TCF::GVL_URL); ?>">
        </p>

        <p>
            <button type="button" class="button button-primary" id="focmp-import-gvl-btn">
                <?php esc_html_e('Import IAB GVL', 'fast-open-cmp'); ?>
            </button>
            <span id="focmp-import-gvl-status" style="margin-left: 10px;"></span>
        </p>

        <h3><?php esc_html_e('Import purpose translations', 'fast-open-cmp'); ?></h3>
        <p>
            <select id="focmp-purposes-lang">
                <option value="pl"><?php esc_html_e('Polish', 'fast-open-cmp'); ?> (PL)</option>
                <option value="de"><?php esc_html_e('German', 'fast-open-cmp'); ?> (DE)</option>
                <option value="fr"><?php esc_html_e('French', 'fast-open-cmp'); ?> (FR)</option>
                <option value="es"><?php esc_html_e('Spanish', 'fast-open-cmp'); ?> (ES)</option>
                <option value="it"><?php esc_html_e('Italian', 'fast-open-cmp'); ?> (IT)</option>
            </select>
            <button type="button" class="button" id="focmp-import-purposes-btn">
                <?php esc_html_e('Import translations', 'fast-open-cmp'); ?>
            </button>
            <span id="focmp-import-purposes-status" style="margin-left: 10px;"></span>
        </p>
    </div>

    <!-- Imported translations section -->
    <div class="focmp-import-section">
        <h2><?php esc_html_e('Imported translations', 'fast-open-cmp'); ?></h2>

        <div id="focmp-imported-langs">
        <?php if (empty($imported_languages)): ?>
            <p><?php esc_html_e('No imported translations. Use the form above to import purpose translations.', 'fast-open-cmp'); ?></p>
        <?php else: ?>
            <?php
            $lang_names = array(
                'en' => 'English', 'pl' => 'Polski', 'de' => 'Deutsch',
                'fr' => 'Français', 'es' => 'Español', 'it' => 'Italiano',
                'nl' => 'Nederlands', 'pt' => 'Português', 'cs' => 'Čeština',
                'da' => 'Dansk', 'fi' => 'Suomi', 'hu' => 'Magyar',
                'ja' => '日本語', 'no' => 'Norsk', 'ro' => 'Română',
                'ru' => 'Русский', 'sk' => 'Slovenčina', 'sv' => 'Svenska',
                'zh' => '中文', 'bg' => 'Български', 'ca' => 'Català',
                'el' => 'Ελληνικά', 'et' => 'Eesti', 'hr' => 'Hrvatski',
                'lt' => 'Lietuvių', 'lv' => 'Latviešu', 'mt' => 'Malti',
                'sl' => 'Slovenščina', 'tr' => 'Türkçe', 'uk' => 'Українська',
            );
            ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Language', 'fast-open-cmp'); ?></th>
                        <th><?php esc_html_e('IAB purposes', 'fast-open-cmp'); ?></th>
                        <th><?php esc_html_e('GVL metadata', 'fast-open-cmp'); ?></th>
                        <th><?php esc_html_e('Updated at', 'fast-open-cmp'); ?></th>
                        <th><?php esc_html_e('Actions', 'fast-open-cmp'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($imported_languages as $lang_code => $lang_data): ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html(strtoupper($lang_code)); ?></strong>
                                <?php if (isset($lang_names[$lang_code])): ?>
                                    (<?php echo esc_html($lang_names[$lang_code]); ?>)
                                <?php endif; ?>
                            </td>
                            <td><?php echo (int) $lang_data['purpose_count']; ?></td>
                            <td><?php echo (int) $lang_data['meta_count']; ?></td>
                            <td><?php echo esc_html($lang_data['last_updated']); ?></td>
                            <td>
                                <?php if ($lang_data['purpose_count'] > 0 || $lang_data['meta_count'] > 0): ?>
                                    <button type="button" class="button focmp-preview-lang" data-lang="<?php echo esc_attr($lang_code); ?>">
                                        <?php esc_html_e('Preview', 'fast-open-cmp'); ?>
                                    </button>
                                <?php endif; ?>
                                <button type="button" class="button focmp-delete-lang" data-lang="<?php echo esc_attr($lang_code); ?>" data-name="<?php echo esc_attr(strtoupper($lang_code)); ?>">
                                    <?php esc_html_e('Delete', 'fast-open-cmp'); ?>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        </div><!-- #focmp-imported-langs -->

        <!-- Preview area (hidden by default) -->
        <div id="focmp-purposes-preview" style="display:none; margin-top: 20px;">
            <h3><?php esc_html_e('Translation preview', 'fast-open-cmp'); ?>: <span id="focmp-preview-lang-name"></span></h3>
            <div class="focmp-preview-table-wrapper">
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width: 50px;">ID</th>
                            <th>EN</th>
                            <th><?php esc_html_e('Translation', 'fast-open-cmp'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="focmp-preview-body"></tbody>
                </table>
            </div>
            <p style="margin-top: 10px;">
                <button type="button" class="button" id="focmp-close-preview"><?php esc_html_e('Close preview', 'fast-open-cmp'); ?></button>
            </p>
        </div>
    </div>

    <h2><?php esc_html_e('Vendor list', 'fast-open-cmp'); ?> (<?php echo count($vendors); ?>)</h2>

    <?php if (empty($vendors)): ?>
        <p><?php esc_html_e('No vendors. Import from the IAB GVL.', 'fast-open-cmp'); ?></p>
    <?php else: ?>
        <form method="post" action="">
            <?php wp_nonce_field('focmp_toggle_vendor'); ?>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width: 80px;"><?php esc_html_e('ID', 'fast-open-cmp'); ?></th>
                        <th><?php esc_html_e('Name', 'fast-open-cmp'); ?></th>
                        <th style="width: 100px;"><?php esc_html_e('Enabled', 'fast-open-cmp'); ?></th>
                        <th><?php esc_html_e('Updated at', 'fast-open-cmp'); ?></th>
                    </tr>
                </thead>
                <tbody id="focmp-vendors-iab-body"></tbody>
            </table>
            <!-- Rows hydrated by admin.js (focmp-lazy-rows) to keep first paint
                 fast with ~1k+ vendors; toggles still save via the delegated handler. -->
            <template class="focmp-lazy-rows" data-target="#focmp-vendors-iab-body">
                    <?php foreach ($vendors as $v): ?>
                        <tr>
                            <td><?php echo esc_html($v->vendor_id); ?></td>
                            <td><?php echo esc_html($v->name); ?></td>
                            <td>
                                <label class="focmp-switch">
                                    <input type="checkbox"
                                           class="focmp-vendor-toggle"
                                           data-vendor-id="<?php echo esc_attr($v->vendor_id); ?>"
                                           data-vendor-type="iab"
                                           <?php checked($v->is_enabled, 1); ?>>
                                    <span class="focmp-slider"></span>
                                </label>
                            </td>
                            <td><?php echo esc_html($v->updated_at); ?></td>
                        </tr>
                    <?php endforeach; ?>
            </template>
        </form>
    <?php endif; ?>
</div>

<script>
jQuery(document).ready(function($) {
    $('#focmp-import-gvl-btn').on('click', function() {
        var btn = $(this);
        var status = $('#focmp-import-gvl-status');
        var url = $('#focmp-gvl-url').val();

        btn.prop('disabled', true);
        status.html('<span class="spinner is-active" style="float: none;"></span> <?php esc_html_e('Importing...', 'fast-open-cmp'); ?>');

        $.post(focmpAdmin.ajaxUrl, {
            action: 'focmp_import_gvl',
            nonce: focmpAdmin.nonce,
            source: url,
            type: 'gvl'
        }, function(response) {
            btn.prop('disabled', false);
            if (response.success) {
                status.empty().append($('<span style="color: green;"></span>').text(
                    '<?php echo esc_js(__('Imported vendors / purposes:', 'fast-open-cmp')); ?> ' + response.data.vendors + ' / ' + response.data.purposes));
                setTimeout(function() { location.reload(); }, 2000);
            } else {
                status.empty().append($('<span style="color: red;"></span>').text('<?php echo esc_js(__('Error', 'fast-open-cmp')); ?>: ' + response.data.message));
            }
        });
    });

    function escHtml(t) { return $('<span>').text(t == null ? '' : t).html(); }

    // Rebuild the "Imported translations" table from server data (used after
    // import and after delete, so no manual page reload is needed).
    function renderImportedLanguages(languages) {
        var $box = $('#focmp-imported-langs');
        if (!languages || languages.length === 0) {
            $box.html('<p><?php echo esc_js(__('No imported translations. Use the form above to import purpose translations.', 'fast-open-cmp')); ?></p>');
            return;
        }
        var h = '<table class="wp-list-table widefat fixed striped"><thead><tr>' +
            '<th><?php echo esc_js(__('Language', 'fast-open-cmp')); ?></th>' +
            '<th><?php echo esc_js(__('IAB purposes', 'fast-open-cmp')); ?></th>' +
            '<th><?php echo esc_js(__('GVL metadata', 'fast-open-cmp')); ?></th>' +
            '<th><?php echo esc_js(__('Updated at', 'fast-open-cmp')); ?></th>' +
            '<th><?php echo esc_js(__('Actions', 'fast-open-cmp')); ?></th>' +
            '</tr></thead><tbody>';
        for (var i = 0; i < languages.length; i++) {
            var l = languages[i];
            var label = '<strong>' + escHtml(String(l.code).toUpperCase()) + '</strong>' + (l.name ? ' (' + escHtml(l.name) + ')' : '');
            var actions = '';
            if (l.has_data) {
                actions += '<button type="button" class="button focmp-preview-lang" data-lang="' + escHtml(l.code) + '"><?php echo esc_js(__('Preview', 'fast-open-cmp')); ?></button> ';
            }
            actions += '<button type="button" class="button focmp-delete-lang" data-lang="' + escHtml(l.code) + '" data-name="' + escHtml(String(l.code).toUpperCase()) + '"><?php echo esc_js(__('Delete', 'fast-open-cmp')); ?></button>';
            h += '<tr><td>' + label + '</td><td>' + (l.purpose_count | 0) + '</td><td>' + (l.meta_count | 0) + '</td><td>' + escHtml(l.last_updated) + '</td><td>' + actions + '</td></tr>';
        }
        $box.html(h + '</tbody></table>');
    }

    function refreshImportedLanguages() {
        return $.post(focmpAdmin.ajaxUrl, { action: 'focmp_imported_languages', nonce: focmpAdmin.nonce })
            .done(function (r) { if (r && r.success) renderImportedLanguages(r.data.languages); });
    }

    $('#focmp-import-purposes-btn').on('click', function() {
        var lang = $('#focmp-purposes-lang').val();
        var url = <?php echo wp_json_encode(trailingslashit(dirname(FOCMP_TCF::GVL_URL)) . 'purposes-'); ?> + lang + '.json';
        var $btn = $(this), $status = $('#focmp-import-purposes-status');

        $btn.prop('disabled', true);
        $status.html('<span class="spinner is-active" style="float:none;margin:0 4px 0 0;"></span><?php echo esc_js(__('Importing translations…', 'fast-open-cmp')); ?>');

        $.post(focmpAdmin.ajaxUrl, {
            action: 'focmp_import_gvl',
            nonce: focmpAdmin.nonce,
            source: url,
            type: 'purposes',
            lang: lang
        }).done(function(response) {
            $btn.prop('disabled', false);
            if (response && response.success) {
                $status.html('<span style="color:#008a20;"><?php echo esc_js(__('Imported', 'fast-open-cmp')); ?>: ' + (response.data.purposes | 0) + ' <?php echo esc_js(__('purposes', 'fast-open-cmp')); ?></span>');
                refreshImportedLanguages();
                setTimeout(function () { $status.html(''); }, 4000);
            } else {
                $status.html('<span style="color:#d63638;"><?php echo esc_js(__('Error', 'fast-open-cmp')); ?>: ' + (response && response.data ? response.data.message : '') + '</span>');
            }
        }).fail(function () {
            $btn.prop('disabled', false);
            $status.html('<span style="color:#d63638;"><?php echo esc_js(__('Connection error', 'fast-open-cmp')); ?></span>');
        });
    });

    // Delete an imported translation (delegated — rows are re-rendered).
    $(document).on('click', '.focmp-delete-lang', function() {
        var $btn = $(this), lang = $btn.data('lang'), name = $btn.data('name') || lang;
        if (!window.confirm('<?php echo esc_js(__('Delete the imported translation for', 'fast-open-cmp')); ?> ' + name + '?')) return;
        $btn.prop('disabled', true);
        $.post(focmpAdmin.ajaxUrl, { action: 'focmp_delete_translation', nonce: focmpAdmin.nonce, lang: lang })
            .done(function (r) {
                if (r && r.success) { renderImportedLanguages(r.data.languages); }
                else { $btn.prop('disabled', false); alert(r && r.data ? r.data.message : 'Error'); }
            })
            .fail(function () { $btn.prop('disabled', false); alert('<?php echo esc_js(__('Connection error', 'fast-open-cmp')); ?>'); });
    });

    // Preview translations (delegated — rows are re-rendered)
    $(document).on('click', '.focmp-preview-lang', function() {
        var btn = $(this);
        var lang = btn.data('lang');

        btn.prop('disabled', true).text('<?php esc_html_e('Loading...', 'fast-open-cmp'); ?>');

        $.post(focmpAdmin.ajaxUrl, {
            action: 'focmp_get_purposes_preview',
            nonce: focmpAdmin.nonce,
            lang: lang
        }, function(response) {
            btn.prop('disabled', false).text('<?php esc_html_e('Preview', 'fast-open-cmp'); ?>');

            if (response.success) {
                var purposes = response.data.purposes;
                var metadata = response.data.metadata || {};
                var langUpper = lang.toUpperCase();
                $('#focmp-preview-lang-name').text(langUpper);

                var sectionLabels = {
                    'specialPurposes': '<?php esc_html_e('Special Purposes', 'fast-open-cmp'); ?>',
                    'features': '<?php esc_html_e('Features', 'fast-open-cmp'); ?>',
                    'specialFeatures': '<?php esc_html_e('Special Features', 'fast-open-cmp'); ?>',
                    'stacks': '<?php esc_html_e('Stacks', 'fast-open-cmp'); ?>',
                    'dataCategories': '<?php esc_html_e('Data Categories', 'fast-open-cmp'); ?>'
                };

                function esc(text) {
                    return $('<span>').text(text || '').html();
                }

                function renderIllustrations(arr) {
                    if (!arr || arr.length === 0) return '';
                    var h = '<div style="margin-top:6px;padding:6px 8px;background:#f9f9f9;border-left:3px solid #2196F3;font-size:12px;line-height:1.5;">';
                    h += '<strong><?php esc_html_e('Illustrations:', 'fast-open-cmp'); ?></strong><br>';
                    for (var j = 0; j < arr.length; j++) {
                        h += '<div style="margin-top:4px;">' + esc(arr[j]) + '</div>';
                    }
                    h += '</div>';
                    return h;
                }

                function renderItems(items) {
                    var h = '';
                    for (var i = 0; i < items.length; i++) {
                        var p = items[i];
                        h += '<tr>';
                        h += '<td>' + esc(String(p.id)) + '</td>';
                        h += '<td><strong>' + esc(p.en_name) + '</strong><br><small>' + esc(p.en_description) + '</small>';
                        if (p.en_illustrations) h += renderIllustrations(p.en_illustrations);
                        h += '</td>';
                        h += '<td><strong>' + esc(p.name) + '</strong><br><small>' + esc(p.description) + '</small>';
                        if (p.illustrations) h += renderIllustrations(p.illustrations);
                        h += '</td>';
                        h += '</tr>';
                    }
                    return h;
                }

                var html = '';

                // Purposes section
                if (purposes.length > 0) {
                    html += '<tr class="focmp-preview-section-header"><td colspan="3"><strong><?php esc_html_e('Purposes', 'fast-open-cmp'); ?></strong></td></tr>';
                    html += renderItems(purposes);
                }

                // Metadata sections
                var metaKeys = ['specialPurposes', 'features', 'specialFeatures', 'stacks', 'dataCategories'];
                for (var m = 0; m < metaKeys.length; m++) {
                    var key = metaKeys[m];
                    if (metadata[key] && metadata[key].length > 0) {
                        html += '<tr class="focmp-preview-section-header"><td colspan="3"><strong>' + sectionLabels[key] + '</strong></td></tr>';
                        html += renderItems(metadata[key]);
                    }
                }

                if (html === '') {
                    html = '<tr><td colspan="3"><?php esc_html_e('No data', 'fast-open-cmp'); ?></td></tr>';
                }

                $('#focmp-preview-body').html(html);
                $('#focmp-purposes-preview').slideDown();
                $('html, body').animate({ scrollTop: $('#focmp-purposes-preview').offset().top - 50 }, 300);
            } else {
                alert('<?php esc_html_e('Error', 'fast-open-cmp'); ?>: ' + response.data.message);
            }
        }).fail(function() {
            btn.prop('disabled', false).text('<?php esc_html_e('Preview', 'fast-open-cmp'); ?>');
            alert('<?php esc_html_e('Connection error', 'fast-open-cmp'); ?>');
        });
    });

    $('#focmp-close-preview').on('click', function() {
        $('#focmp-purposes-preview').slideUp();
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
