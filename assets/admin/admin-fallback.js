jQuery(function($) {
            // Show/hide rows based on dual toggles (fallback when compiled JS not present)
            function toggleBasedOnToggles() {
                const objectOn = $('#enable_object_cache').is(':checked');
                const $blockCachingRow = $('#block-caching-row');
                const $blockCachingCheckbox = $('input[name="ace_redis_cache_settings[enable_block_caching]"]');
                const $transientRow = $('#transient-cache-row');
                $blockCachingRow.toggle(!!objectOn);
                $transientRow.toggle(!!objectOn);
                if (!objectOn) { $blockCachingCheckbox.prop('checked', false); }
            }

            toggleBasedOnToggles();
            $(document).on('change', '#enable_object_cache', toggleBasedOnToggles);

            // Compression sub-options visibility (fallback)
            function toggleCompressionSubOptions() {
                const enabled = $('#enable_compression').is(':checked');
                const $field = $('#enable_compression').closest('.setting-field');
                if ($field.length) {
                    $field.find('.compression-methods').toggle(!!enabled);
                    $field.find('.compression-methods').next('p.description').toggle(!!enabled);
                }
            }
            toggleCompressionSubOptions();
            $(document).on('change', '#enable_compression', toggleCompressionSubOptions);

            // Basic TTL validation fallback
            function validateTTLField($field) {
                const v = parseInt($field.val(), 10);
                if (!v || v < 60) { $field.addClass('error'); } else { $field.removeClass('error'); }
            }
            $('#ttl_page, #ttl_object').on('blur', function(){ validateTTLField($(this)); });
        });
