(function($){
    if (!$ || typeof ace_redis_admin === 'undefined') return;

    function setTransientBadge(label, state) {
        var $badge = $('#ace-rc-transient-status');
        if (!$badge.length) return;
        var colors = {
            ok: { bg: '#46b450', fg: '#fff' },
            warn: { bg: '#dba617', fg: '#1d2327' },
            error: { bg: '#d63638', fg: '#fff' },
            pending: { bg: '#888', fg: '#fff' }
        };
        var c = colors[state] || colors.pending;
        $badge.text(label).css({ background: c.bg, color: c.fg });
    }

    function humanApproxBytes(bytes) {
        if (!bytes) return '0B';
        var units = ['B', 'KB', 'MB', 'GB', 'TB'];
        var i = 0;
        var value = bytes;
        while (value >= 1024 && i < units.length - 1) {
            value /= 1024;
            i++;
        }
        return (value >= 10 ? Math.round(value) : value.toFixed(1)) + units[i];
    }

    function renderTransientHealth(data) {
        var $tips = $('#ace-rc-transient-tips');
        if (!$('#enable_transient_cache').is(':checked')) {
            setTransientBadge('Off', 'pending');
            if ($tips.length) $tips.html('<em>Transient cache disabled.</em>');
            return;
        }

        var mode = data && data.request_mode ? data.request_mode : '';
        var guestMode = data && data.guest_effective_mode ? data.guest_effective_mode : mode;
        var badge = { label: 'OK', state: 'ok' };

        if (!data || !data.using_dropin || guestMode === 'missing_dropin') {
            badge = { label: 'Missing', state: 'warn' };
        } else if (guestMode === 'fail_open' || guestMode === 'forced_bypass') {
            badge = { label: 'Bypassed', state: 'warn' };
        } else if (guestMode === 'disconnected') {
            badge = { label: 'Down', state: 'error' };
        }

        setTransientBadge(badge.label, badge.state);

        if (!$tips.length || !data) return;

        var parts = [];
        if (!data.using_dropin || guestMode === 'missing_dropin') {
            parts.push('<strong>Drop-in:</strong> <span style="color:#c00;">missing</span>');
        } else if (guestMode === 'active') {
            parts.push('<strong>Drop-in:</strong> <span style="color:green;">guest-active</span>');
            if (mode === 'runtime_only') {
                parts.push('<span style="color:#dba617;">admin request is runtime-only</span>');
            }
        } else if (mode === 'runtime_only') {
            parts.push('<strong>Drop-in:</strong> <span style="color:#dba617;">installed (guest active)</span>');
            parts.push('<span style="color:#dba617;">runtime-only on this admin request</span>');
        } else if (guestMode === 'disconnected') {
            parts.push('<strong>Drop-in:</strong> <span style="color:#c00;">not connected</span>');
        } else {
            parts.push('<strong>Drop-in:</strong> <span style="color:#dba617;">connected (bypassed)</span>');
        }

        parts.push('Autoload ' + humanApproxBytes(data.autoload_size));
        if (data.slow_ops) parts.push(data.slow_ops + ' slow ops');
        if (data.error && mode !== 'runtime_only') parts.push('Error: <code>' + $('<div>').text(data.error).html() + '</code>');

        var html = parts.join(' | ');
        if (Array.isArray(data.tips) && data.tips.length) {
            html += '<ul style="margin:6px 0 0 18px; list-style:disc;">' + data.tips.map(function(tip){
                return '<li>' + $('<div>').text(tip).html() + '</li>';
            }).join('') + '</ul>';
        }
        $tips.html(html).data('populated', true);
    }

    function refreshTransientHealthOverride() {
        $.ajax({
            url: ace_redis_admin.rest_url + 'ace-redis-cache/v1/health',
            type: 'GET',
            beforeSend: function(xhr){ xhr.setRequestHeader('X-WP-Nonce', ace_redis_admin.rest_nonce); }
        }).done(function(resp){
            if (resp && resp.success && resp.data) {
                renderTransientHealth(resp.data);
            }
        });
    }

    $(function(){
        setTimeout(refreshTransientHealthOverride, 900);
        $(document).on('change', '#enable_transient_cache, #enable_object_cache', function(){
            setTimeout(refreshTransientHealthOverride, 500);
        });
        $(document).ajaxComplete(function(_event, xhr, settings){
            var url = settings && settings.url ? settings.url : '';
            if (url.indexOf('/ace-redis-cache/v1/settings') !== -1 || url.indexOf('/ace-redis-cache/v1/health') !== -1) {
                setTimeout(refreshTransientHealthOverride, 500);
            }
        });
    });
})(window.jQuery);
