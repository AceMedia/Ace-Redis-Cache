(function () {
    'use strict';

    if (!window.ace_redis_admin_bar) {
        return;
    }

    var config = window.ace_redis_admin_bar;
    var pollTimer = null;

    function getStatusElement() {
        return document.querySelector('#wp-admin-bar-ace-redis-cache-flush .ace-redis-admin-bar-status');
    }

    function setStatus(text, state) {
        var status = getStatusElement();
        if (!status) {
            return;
        }

        status.textContent = text || '';
        status.className = 'ace-redis-admin-bar-status' + (state ? ' is-' + state : '');
    }

    function formatStatus(data) {
        if (!data || !data.status) {
            return '';
        }

        var text = data.status.charAt(0).toUpperCase() + data.status.slice(1);
        var patterns = parseInt(data.patterns, 10) || 0;
        var index = Math.min(parseInt(data.pattern_index, 10) || 0, patterns);
        var deleted = parseInt(data.deleted, 10) || 0;

        if (patterns) {
            text += ' · patterns ' + index + '/' + patterns;
        }
        return text + ' · ' + deleted + ' keys removed';
    }

    function pollStatus() {
        window.fetch(config.status_url, {
            headers: { 'X-WP-Nonce': config.nonce },
            credentials: 'same-origin'
        })
            .then(function (response) { return response.json(); })
            .then(function (response) {
                var data = response && response.data ? response.data : {};
                var text = formatStatus(data);
                setStatus(text, data.status === 'complete' ? 'complete' : data.status === 'failed' ? 'failed' : 'running');

                if (data.status === 'queued' || data.status === 'running') {
                    pollTimer = window.setTimeout(pollStatus, 3000);
                }
            })
            .catch(function () {
                setStatus('Status unavailable', 'failed');
            });
    }

    function flushCache(event) {
        event.preventDefault();
        if (pollTimer) {
            window.clearTimeout(pollTimer);
        }

        setStatus('Queuing…', 'running');
        window.fetch(config.flush_url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                'X-WP-Nonce': config.nonce
            },
            credentials: 'same-origin',
            body: new URLSearchParams({ nonce: config.nonce, type: 'all' })
        })
            .then(function (response) { return response.json(); })
            .then(function (response) {
                if (!response || !response.success) {
                    throw new Error('Purge request failed');
                }
                pollStatus();
            })
            .catch(function () {
                setStatus('Purge failed', 'failed');
            });
    }

    function init() {
        var item = document.querySelector('#wp-admin-bar-ace-redis-cache-flush > a');
        if (!item) {
            return;
        }

        item.addEventListener('click', flushCache);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}());
