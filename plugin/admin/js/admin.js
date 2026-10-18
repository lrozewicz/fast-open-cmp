/**
 * Fast Open CMP - Admin JavaScript
 */

(function($) {
    'use strict';

    $(document).ready(function() {

        // ---- Lazy hydration of vendor rows (perf: thousands of <tr>) ----
        // Rows ship inside <template id="focmp-rows-{type}"> and are injected into
        // the matching <tbody> on demand. The active tab streams in chunked (non-
        // blocking); inactive tabs hydrate on first open; everything is force-
        // hydrated before submit / search / select-all so behaviour and the saved
        // payload are byte-for-byte identical to eager rendering.
        function hydrateTab(type, chunked) {
            var tpl = document.getElementById('focmp-rows-' + type);
            if (!tpl) { return; } // already hydrated (template removed)
            var tbody = document.querySelector('#focmp-tab-' + type + ' .focmp-vendor-table tbody');
            if (!tbody) { return; }
            var frag = tpl.content;

            function finish() {
                if (tpl.parentNode) { tpl.parentNode.removeChild(tpl); }
                updateVendorCount(type);
            }

            if (!chunked) {
                tbody.appendChild(frag); // move every row in one shot
                finish();
                return;
            }

            // Spread the work across turns of the event loop so first paint isn't
            // blocked. setTimeout (not requestAnimationFrame) so it still completes
            // when the tab is backgrounded / not painting.
            (function step() {
                var n = 150;
                while (n-- && frag.firstChild) { tbody.appendChild(frag.firstChild); }
                if (frag.firstChild) { setTimeout(step, 0); } else { finish(); }
            })();
        }

        // Synchronously guarantee a tab is fully populated before we read/iterate it.
        function ensureHydrated(type) { hydrateTab(type, false); }

        // Stream the initially-active tab in without blocking first paint.
        $('.focmp-tab-content.active').each(function () {
            if (this.id) { hydrateTab(this.id.replace('focmp-tab-', ''), true); }
        });

        // Generic lazy-row hydration for standalone vendor tables (vendors-iab /
        // vendors-google). Rows ship in <template class="focmp-lazy-rows"
        // data-target="#tbody-id"> and stream into the target tbody chunked after
        // first paint. Toggle saves rely on the delegated .focmp-vendor-toggle
        // handler above, so hydrated rows need no per-row wiring.
        $('template.focmp-lazy-rows').each(function () {
            var tbody = document.querySelector(this.getAttribute('data-target'));
            if (!tbody) { return; }
            var tpl = this, frag = this.content;
            (function step() {
                var n = 150;
                while (n-- && frag.firstChild) { tbody.appendChild(frag.firstChild); }
                if (frag.firstChild) { setTimeout(step, 0); }
                else if (tpl.parentNode) { tpl.parentNode.removeChild(tpl); }
            })();
        });

        // Note: delete confirmation is handled by each link's inline
        // onclick="return confirm(...)" (works on lazily-hydrated rows too).
        // A second handler here would pop the confirm dialog twice.

        // Generic vendor toggle handler
        $(document).on('change', '.focmp-vendor-toggle', function() {
            var checkbox = $(this);
            var vendorId = checkbox.data('vendor-id');
            var vendorType = checkbox.data('vendor-type');
            var isEnabled = checkbox.is(':checked') ? 1 : 0;

            // Visual feedback
            checkbox.closest('tr').css('opacity', '0.5');

            $.post(focmpAdmin.ajaxUrl, {
                action: 'focmp_toggle_vendor',
                nonce: focmpAdmin.nonce,
                vendor_id: vendorId,
                vendor_type: vendorType,
                is_enabled: isEnabled
            }, function(response) {
                checkbox.closest('tr').css('opacity', '1');
                if (!response || !response.success) {
                    // Server refused (permission, nonce, DB error): undo the visual change
                    checkbox.prop('checked', !checkbox.is(':checked'));
                    window.alert(focmpAdmin.strings.toggleError);
                }
            }).fail(function() {
                checkbox.closest('tr').css('opacity', '1');
                // Revert checkbox state on error
                checkbox.prop('checked', !checkbox.is(':checked'));
                window.alert(focmpAdmin.strings.toggleError);
            });
        });

        // Vendor list tab switching
        $(document).on('click', '.focmp-tab-btn', function() {
            var tab = $(this).data('tab');
            $('.focmp-tab-btn').removeClass('active');
            $(this).addClass('active');
            $('.focmp-tab-content').removeClass('active');
            $('#focmp-tab-' + tab).addClass('active');
            hydrateTab(tab, true); // populate on first open (no-op afterwards)
        });

        // Vendor list: Select all
        $(document).on('click', '.focmp-select-all', function() {
            var type = $(this).data('type');
            ensureHydrated(type);
            var tab = $(this).closest('.focmp-tab-content');
            tab.find('.focmp-list-vendor-toggle[data-type="' + type + '"]').not(':disabled').prop('checked', true);
            updateVendorCount(type);
        });

        // Vendor list: Deselect all
        $(document).on('click', '.focmp-deselect-all', function() {
            var type = $(this).data('type');
            ensureHydrated(type);
            var tab = $(this).closest('.focmp-tab-content');
            tab.find('.focmp-list-vendor-toggle[data-type="' + type + '"]').not(':disabled').prop('checked', false);
            updateVendorCount(type);
        });

        // Vendor list: Search/filter (debounced; rows hydrated on first keystroke)
        var searchTimers = {};
        $(document).on('input', '.focmp-vendor-search', function() {
            var type = $(this).data('tab');
            var input = this;
            ensureHydrated(type);
            clearTimeout(searchTimers[type]);
            searchTimers[type] = setTimeout(function() {
                var query = input.value.toLowerCase();
                var rows = document.querySelectorAll('#focmp-tab-' + type + ' .focmp-vendor-row');
                for (var i = 0; i < rows.length; i++) {
                    var cells = rows[i].cells;
                    var name = (cells[1] ? cells[1].textContent : '').toLowerCase();
                    var id = cells[0] ? cells[0].textContent : '';
                    rows[i].style.display = (name.indexOf(query) !== -1 || id.indexOf(query) !== -1) ? '' : 'none';
                }
            }, 120);
        });

        // Update vendor count on toggle change
        $(document).on('change', '.focmp-list-vendor-toggle', function() {
            var type = $(this).data('type');
            updateVendorCount(type);
        });

        function updateVendorCount(type) {
            var tab = $('#focmp-tab-' + type);
            var checked = tab.find('.focmp-list-vendor-toggle:checked').length;
            var total = tab.find('.focmp-list-vendor-toggle').length;
            tab.find('.focmp-vendor-count').text(
                focmpAdmin.strings.enabledCount.replace('%1$s', checked).replace('%2$s', total)
            );
        }

        // Vendor list form: collect disabled vendor IDs into hidden fields before submit
        // This avoids PHP max_input_vars limit (thousands of checkboxes would exceed it)
        $('#focmp-vendor-list-form').on('submit', function() {
            var types = ['iab', 'google', 'custom'];
            // Tabs the user never opened are still inert <template>s; hydrate them
            // first so their toggle states are included in the disabled-IDs payload.
            for (var h = 0; h < types.length; h++) { ensureHydrated(types[h]); }
            for (var i = 0; i < types.length; i++) {
                var type = types[i];
                var disabled = [];
                // Globally disabled vendors are greyed out but keep their per-list state;
                // they must be submitted too, otherwise saving re-enables them in the list.
                $('#focmp-tab-' + type + ' .focmp-list-vendor-toggle[data-type="' + type + '"]').not(':checked').each(function() {
                    disabled.push($(this).data('vendor-id'));
                });
                $('#focmp-disabled-' + type).val(disabled.join(','));
            }
        });

    });

})(jQuery);
