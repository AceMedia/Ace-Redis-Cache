(function($){
    if (!$ || typeof ace_redis_admin === 'undefined') return;

    function restGet(path) {
        return $.ajax({
            url: ace_redis_admin.rest_url + path,
            type: 'GET',
            timeout: 5000,
            beforeSend: function(xhr){ xhr.setRequestHeader('X-WP-Nonce', ace_redis_admin.rest_nonce); }
        });
    }

    function restPost(path, data) {
        return $.ajax({
            url: ace_redis_admin.rest_url + path,
            type: 'POST',
            data: data,
            timeout: 8000,
            beforeSend: function(xhr){ xhr.setRequestHeader('X-WP-Nonce', ace_redis_admin.rest_nonce); }
        });
    }

    function describeStatus(d) {
        if (!d) return { text: 'Unable to determine status.', meta: '' };
        if (!d.target_exists) return { text: 'Not installed in wp-content.', meta: d.enabled_setting ? 'Settings say this should be deployed. Use the button to install the latest copy.' : 'Feature currently disabled in settings.' };
        if (!d.managed) return { text: 'Foreign drop-in detected.', meta: 'This plugin will not overwrite non-Ace drop-ins.' };
        if (d.outdated && d.active) return { text: 'Active and outdated.', meta: 'The live drop-in is behind the plugin copy. Update recommended now.' };
        if (d.outdated && !d.active) return { text: 'Outdated (inactive).', meta: 'It is behind the plugin copy. You can update it without toggling settings.' };
        if (d.active) return { text: 'Active and up to date.', meta: '' };
        return { text: 'Installed and up to date (inactive).', meta: '' };
    }

    function renderDropinBox(type, d) {
        var boxId = type === 'advanced' ? '#ace-rc-advanced-dropin-box' : '#ace-rc-object-dropin-box';
        var $box = $(boxId);
        if (!$box.length) return;

        var status = describeStatus(d);
        $box.find('.ace-rc-dropin-status').text(status.text);
        $box.find('.ace-rc-dropin-meta').text(status.meta || '');

        var $btn = $box.find('.ace-rc-dropin-update');
        if (d && d.can_update) {
            $btn.show().prop('disabled', false).text(d.action === 'install' ? 'Install Latest Drop-in' : 'Update Drop-in');
        } else {
            $btn.hide().prop('disabled', true);
        }
    }

    function refreshDropinStatus() {
        restGet('ace-redis-cache/v1/dropins-status').done(function(resp){
            if (!resp || !resp.success || !resp.data) return;
            renderDropinBox('advanced', resp.data.advanced || null);
            renderDropinBox('object', resp.data.object || null);
        }).fail(function(xhr, textStatus){
            var meta = 'Drop-in status request failed.';
            if (xhr && xhr.status === 401) {
                meta = 'Permission or nonce error while checking drop-in status. Refresh the page and try again.';
            } else if (textStatus === 'timeout') {
                meta = 'Drop-in status check timed out. You can still save settings or retry.';
            }

            ['#ace-rc-advanced-dropin-box', '#ace-rc-object-dropin-box'].forEach(function(boxId){
                var $box = $(boxId);
                if (!$box.length) return;
                $box.find('.ace-rc-dropin-status').text('Status unavailable');
                $box.find('.ace-rc-dropin-meta').text(meta);
                $box.find('.ace-rc-dropin-update').hide().prop('disabled', true);
            });
        });
    }

    $(document).on('click', '.ace-rc-dropin-update', function(){
        var type = $(this).data('dropin');
        var $btn = $(this);
        if (!type) return;
        if (!window.confirm('Update this active drop-in to the latest plugin version?')) return;

        var original = $btn.text();
        $btn.prop('disabled', true).text('Updating...');

        restPost('ace-redis-cache/v1/dropins-update', {
            nonce: ace_redis_admin.nonce,
            type: type
        }).done(function(resp){
            if (resp && resp.success) {
                alert((resp.data && resp.data.message) || 'Drop-in update completed.');
            } else {
                // Show the specific reason (e.g. "... is not writable: /path") instead of a generic failure.
                alert((resp && resp.data && resp.data.message) || 'Drop-in update failed.');
            }
        }).fail(function(xhr){
            var msg = 'Drop-in update request failed.';
            if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                msg = xhr.responseJSON.data.message;
            }
            alert(msg);
        }).always(function(){
            $btn.prop('disabled', false).text(original);
            refreshDropinStatus();
        });
    });

    $(function(){
        refreshDropinStatus();
        $('#enable_page_cache, #enable_transient_cache').on('change', function(){
            setTimeout(refreshDropinStatus, 400);
        });
        $(document).ajaxSuccess(function(_event, xhr, settings){
            var url = settings && settings.url ? settings.url : '';
            if (url.indexOf('/ace-redis-cache/v1/settings') === -1) return;
            var resp = xhr && xhr.responseJSON ? xhr.responseJSON : null;
            if (resp && resp.success && resp.data && resp.data.dropins) {
                renderDropinBox('advanced', resp.data.dropins.advanced || null);
                renderDropinBox('object', resp.data.dropins.object || null);
            }
        });
    });
})(window.jQuery);
