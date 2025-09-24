/**
 * Ace Redis Cache Admin JavaScript
 *
 * Handles admin interface interactions, AJAX requests,
 * and dynamic UI updates.
 *
 * @package AceMedia\RedisCache
 * @since 0.5.0
 */

// Import SaveBar component
import SaveBar from './components/SaveBar.js';

(function($) {
    'use strict';

    // Make SaveBar available globally for WordPress integration
    window.AceRedisCacheSaveBar = SaveBar;

    // Main admin class
    class AceRedisCacheAdmin {
        constructor() {
            this.originalFormData = null;
            // State for Plugin Memory auto-fetch toggle and fetch in-flight guard
            this.pluginMemoryAuto = false;
            this.pluginMemoryFetching = false;
            // Timestamp when transient cache was (re)enabled to allow warm-up grace
            this.transientEnableTs = null;
            this.init();
        }

        init() {
            this.initTabs();
            this.initToggleSwitch();
            this.initEnableCacheUi();
            this.initCacheMode();
            this.initConnectionTest();
            this.initCacheManagement();
            this.initDiagnostics();
            this.initPerformanceMetrics();
            this.initAjaxForm();
            this.initFormValidation();
            this.initChangeTracking();
            this.initSaveBar(); // Initialize the SaveBar component
            this.initCompressionToggle();
            this.initOpcacheHelpers();
            this.initManagedPlugins();
            this.initTransientHealth();
            this.initHealthActions();
        }

        // Toggle UI based on Enable Cache switch
        initEnableCacheUi() {
            const update = () => {
                const enabled = $('#enable_cache').is(':checked');
                // Show/hide cache action buttons panel
                const $actions = $('.cache-actions-panel .cache-action-buttons');
                if ($actions.length) {
                    $actions.toggle(!!enabled);
                }
                // Disable/enable diagnostics test buttons
                $('#ace-redis-cache-test-btn').prop('disabled', !enabled);
                $('#ace-redis-cache-test-write-btn').prop('disabled', !enabled);
            };

            // Initial state on load
            update();
            // React to changes
            $(document).on('change', '#enable_cache', update);
        }

        // Initialize SaveBar component
        initSaveBar() {
            // Wait for SaveBar component to be available
            if (typeof window.AceRedisCacheSaveBar !== 'undefined') {
                // Read any prior preference to seed the component correctly
                // Default: auto-save OFF unless user explicitly enabled before
                let initialAuto = false;
                if (typeof ace_redis_admin !== 'undefined' && ace_redis_admin.user_auto_save !== null) {
                    initialAuto = !!ace_redis_admin.user_auto_save;
                } else {
                    try {
                        const stored = localStorage.getItem('ace_redis_auto_save_enabled');
                        if (stored !== null) initialAuto = (stored === '1');
                    } catch (e) { /* ignore */ }
                }

                this.saveBar = new window.AceRedisCacheSaveBar({
                    containerSelector: '#ace-redis-settings-form',
                    saveButtonSelector: '#ace-redis-save-btn',
                    messageContainerSelector: '#ace-redis-messages',
                    onSave: () => this.saveSettingsViaSaveBar(),
                    autoSaveEnabled: initialAuto,
                    autoSaveInterval: 15000 // 15 seconds - shorter interval for better UX
                });
                console.log('SaveBar initialized successfully');
            } else {
                // Fallback if SaveBar component isn't loaded
                console.warn('SaveBar component not loaded, falling back to standard save handling');
            }
        }

        // Save settings specifically for SaveBar component
        async saveSettingsViaSaveBar() {
            try {
                const success = await this.performSaveSettings();
                
                if (success) {
                    // Refresh connection status after successful save
                    setTimeout(() => {
                        if (typeof this.testConnection === 'function') {
                            this.testConnection();
                        }
                    }, 1000);
                }
                
                return success;
            } catch (error) {
                console.error('SaveBar save error:', error);
                return false;
            }
        }

        // Extracted save logic that can be used by both SaveBar and regular form
        async performSaveSettings() {
            return new Promise((resolve) => {
                const $form = $('#ace-redis-settings-form');
                const formData = this.getFormDataObject();
                // Inject managed plugins payload
                formData.__managed_plugins = this.collectManagedPlugins();

                $.ajax({
                    url: ace_redis_admin.rest_url + 'ace-redis-cache/v1/settings',
                    type: 'POST',
                    beforeSend: function(xhr) {
                        xhr.setRequestHeader('X-WP-Nonce', ace_redis_admin.rest_nonce);
                    },
                    data: {
                        settings: formData,
                        nonce: ace_redis_admin.nonce
                    },
                    success: (response) => {
                        if (response.success) {
                            // Update the original form data for change tracking
                            this.captureOriginalFormData();
                            // Refresh transient cache health after save
                            if ($('#enable_transient_cache').is(':checked')) {
                                this.transientEnableTs = Date.now();
                            } else {
                                this.transientEnableTs = null;
                            }
                            setTimeout(() => this.refreshTransientHealth && this.refreshTransientHealth(), 300);
                            resolve(true);
                        } else {
                            resolve(false);
                        }
                    },
                    error: () => {
                        resolve(false);
                    }
                });
            });
        }

        // Collect managed plugin selections into compact object
        collectManagedPlugins() {
            const selections = {};
            $('.ace-mp-enable').each(function(){
                if ($(this).is(':checked')) {
                    selections[$(this).data('plugin-file')] = { enabled_on_init: 1 };
                }
            });
            return selections;
        }

        initManagedPlugins() {
            // Nothing heavy needed; saving handled in performSaveSettings
        }

        // --- Transient Cache Health ---
        initTransientHealth() {
            const $toggle = $('#enable_transient_cache');
            if (!$toggle.length) return;
            // Initial fetch only (no toggle-triggered re-ping per request)
            setTimeout(() => this.refreshTransientHealth(), 500);
            // Keep a reference for polling interval
            this.transientInitPoll = null;
        }

        refreshTransientHealth() {
            const $badge = $('#ace-rc-transient-status');
            const $tips = $('#ace-rc-transient-tips');
            if (!$badge.length) return;
            const enabled = $('#enable_transient_cache').is(':checked');
            if (!enabled) {
                this.setTransientStatus('Off','pending');
                if ($tips.length) { $tips.html('<em>Transient cache disabled.</em>').data('populated', true); }
                if (this.transientInitPoll) { clearInterval(this.transientInitPoll); this.transientInitPoll = null; }
                return; // Skip network call when disabled
            }
            $badge.text('checking').css({background:'#ddd', color:'#333'});
            if ($tips.length && !$tips.data('populated')) { $tips.html('Loading cache health…'); }
            $.ajax({
                url: ace_redis_admin.rest_url + 'ace-redis-cache/v1/health',
                type: 'GET',
                beforeSend: (xhr) => xhr.setRequestHeader('X-WP-Nonce', ace_redis_admin.rest_nonce)
            }).done((resp) => {
                if (!resp || !resp.success || !resp.data) {
                    this.setTransientStatus('error', 'error');
                    if ($tips.length) { $tips.html('<span style="color:#c00;">Unable to load cache health.</span>'); }
                    return;
                }
                const d = resp.data;
                let state = 'ok'; let label = 'OK';
                if (!d.using_dropin) { state='warn'; label='Missing'; }
                else if (!d.dropin_connected) { state='error'; label='Down'; }
                else if (d.bypass) { state='warn'; label='Bypassed'; }
                // Grace period: if recently enabled and not yet fully connected treat as initializing
                const now = Date.now();
                if (this.transientEnableTs && (now - this.transientEnableTs) < 15000) {
                    if (state !== 'ok') { state = 'init'; label = 'Init'; }
                }
                this.setTransientStatus(label, state);
                // Manage polling: if in init start interval, else clear
                if (label === 'Init') {
                    if (!this.transientInitPoll) {
                        this.transientInitPoll = setInterval(() => {
                            // Only poll if still enabled and badge shows Init
                            if (!$('#enable_transient_cache').is(':checked')) { clearInterval(this.transientInitPoll); this.transientInitPoll = null; return; }
                            const currentLabel = $('#ace-rc-transient-status').text();
                            if (currentLabel !== 'Init') { clearInterval(this.transientInitPoll); this.transientInitPoll = null; return; }
                            this.refreshTransientHealth();
                        }, 10000); // 10s polling while initializing
                    }
                } else if (this.transientInitPoll) {
                    clearInterval(this.transientInitPoll); this.transientInitPoll = null;
                }
                if ($tips.length) {
                    const parts = [];
                    parts.push('<strong>Drop-in:</strong> ' + (d.using_dropin ? (d.dropin_connected ? '<span style="color:green;">connected</span>' : '<span style="color:#c00;">not connected</span>') : '<span style="color:#c00;">missing</span>'));
                    if (d.bypass) parts.push('<span style="color:#c00;">bypass active</span>');
                    parts.push('Autoload ' + this.humanApproxBytes(d.autoload_size));
                    if (d.slow_ops) parts.push(d.slow_ops + ' slow ops');
                    if (d.error) parts.push('Error: <code>' + this.escapeHtml(d.error) + '</code>');
                    let html = parts.join(' | ');
                    if (Array.isArray(d.tips) && d.tips.length) {
                        html += '<ul style="margin:6px 0 0 18px; list-style:disc;">' + d.tips.map(t => '<li>' + this.escapeHtml(t) + '</li>').join('') + '</ul>';
                    }
                    if (state === 'init') {
                        html = '<strong>Initializing:</strong> Deploying drop-in / establishing Redis connection. This can take a few seconds on first enable.<br>' + html;
                    }
                    $tips.html(html).data('populated', true);
                }
            }).fail(() => {
                this.setTransientStatus('error', 'error');
                if ($tips.length) { $tips.html('<span style="color:#c00;">Health request failed.</span>'); }
            });
        }

        setTransientStatus(text, state) {
            const $badge = $('#ace-rc-transient-status'); if (!$badge.length) return;
            const colors = { ok:{bg:'#46b450',fg:'#fff'}, warn:{bg:'#dba617',fg:'#1d2327'}, error:{bg:'#d63638',fg:'#fff'}, pending:{bg:'#888',fg:'#fff'}, init:{bg:'#2271b1',fg:'#fff'} };
            const c = colors[state] || colors.pending;
            $badge.text(text).css({background:c.bg, color:c.fg});
        }

        humanApproxBytes(bytes){ if(!bytes) return '0B'; const u=['B','KB','MB','GB','TB']; let i=0,v=bytes; while(v>=1024 && i<u.length-1){v/=1024;i++;} return (v>=10?Math.round(v):v.toFixed(1))+u[i]; }

        initHealthActions() {
            const $reset = $('#ace-rc-reset-slow-ops');
            if ($reset.length) {
                $reset.on('click', () => {
                    // Simple REST shim via existing settings endpoint: send flag to reset
                    $.ajax({
                        url: ace_redis_admin.rest_url + 'ace-redis-cache/v1/settings',
                        type: 'POST',
                        beforeSend: (xhr) => xhr.setRequestHeader('X-WP-Nonce', ace_redis_admin.rest_nonce),
                        data: { reset_slow_ops: 1, nonce: ace_redis_admin.nonce },
                        success: () => {
                            $('#ace-rc-slow-ops-val').text('0');
                        }
                    });
                });
            }
        }

        // Initialize tab navigation
        initTabs() {
            // Handle tab clicks
            $('.nav-tab').on('click', (e) => {
                e.preventDefault();
                const target = $(e.target).attr('href');
                this.switchToTab(target);
                
                // Update URL hash
                if (target.startsWith('#')) {
                    window.location.hash = target;
                }
            });
            
            // Handle browser back/forward navigation
            $(window).on('hashchange', () => {
                this.handleHashChange();
            });
            
            // Initialize tab based on URL hash on page load
            this.handleHashChange();
        }
        
        // Switch to a specific tab
        switchToTab(target) {
            if (!target || !$(target).length) {
                return;
            }
            
            // Update tab states
            $('.nav-tab').removeClass('nav-tab-active');
            $(`.nav-tab[href="${target}"]`).addClass('nav-tab-active');

            // Update content visibility with fade effect
            $('.tab-content.active').removeClass('active');
            setTimeout(() => {
                $(target).addClass('active');
                
                // Load lightweight metrics when diagnostics tab is opened
                if (target === '#diagnostics') {
                    setTimeout(() => {
                        this.loadPerformanceMetrics({ scope: 'basic' });
                    }, 100);
                    // Resume timer countdown if auto-refresh is enabled
                    this.resumeAutoRefreshTimer();
                } else {
                    // Pause timer countdown when leaving diagnostics tab
                    this.pauseAutoRefreshTimer();
                }
            }, 50); // Small delay to allow previous content to fade out
        }
        
        // Handle URL hash changes
        handleHashChange() {
            let hash = window.location.hash;
            
            // If no hash or invalid hash, default to first tab
            if (!hash || !$(hash).length) {
                const firstTab = $('.nav-tab').first().attr('href');
                if (firstTab) {
                    hash = firstTab;
                    // Don't update URL if we're defaulting to first tab
                }
            }
            
            // Switch to the tab if it exists
            if (hash && $(hash).length) {
                this.switchToTab(hash);
            }
        }

        // Initialize toggle switches
        initToggleSwitch() {
            $('.ace-switch input').on('change', function() {
                const $switch = $(this);
                const $slider = $switch.siblings('.ace-slider');

                // Add visual feedback
                if ($switch.is(':checked')) {
                    $slider.addClass('checked');
                } else {
                    $slider.removeClass('checked');
                }
            });
        }

        // Initialize cache controls (dual toggles)
        initCacheMode() {
            const toggleObjectRelatedOptions = () => {
                const enabled = $('#enable_object_cache').is(':checked');
                const $blockCachingRow = $('#block-caching-row');
                const $transientRow = $('#transient-cache-row');
                $blockCachingRow.toggle(!!enabled);
                $transientRow.toggle(!!enabled);
            };

            const toggleTTLVisibility = () => {
                const pageOn = $('#enable_page_cache').is(':checked');
                const objOn = $('#enable_object_cache').is(':checked');
                const $ttlPageWrap = $('#ttl_page').closest('.cache-type-options');
                const $ttlObjWrap = $('#ttl_object').closest('.cache-type-options');
                if ($ttlPageWrap.length) { $ttlPageWrap.toggle(!!pageOn); }
                if ($ttlObjWrap.length) { $ttlObjWrap.toggle(!!objOn); }
            };

            // Initialize
            toggleObjectRelatedOptions();
            toggleTTLVisibility();

            $('#enable_object_cache').on('change', function(){
                toggleObjectRelatedOptions();
                toggleTTLVisibility();
            });
            $('#enable_page_cache').on('change', function(){
                toggleTTLVisibility();
            });
        }

        // Hide/show compression sub-options when compression is disabled/enabled
        initCompressionToggle() {
            const updateCompressionUI = () => {
                const enabled = $('#enable_compression').is(':checked');
                const $field = $('#enable_compression').closest('.setting-field');
                if ($field.length) {
                    // Show/hide available methods
                    $field.find('.compression-methods').toggle(!!enabled);
                    // Show/hide the description immediately following the methods block
                    $field.find('.compression-methods').next('p.description').toggle(!!enabled);
                }
            };
            // Initial state
            updateCompressionUI();
            // React to changes
            $(document).on('change', '#enable_compression', updateCompressionUI);
        }

        // OPcache helper buttons (reset / prime + status)
        initOpcacheHelpers() {
            if (!$('#enable_opcache_helpers').length) return; // feature not present
            const $wrapper = $('#opcache-helper-buttons');
            const $checkbox = $('#enable_opcache_helpers');
            const updateVisibility = () => {
                const on = $checkbox.is(':checked');
                $wrapper.toggle(on);
                if (on) {
                    this.fetchOpcacheStatus();
                }
            };
            $checkbox.on('change', updateVisibility);
            updateVisibility();

            $('#ace-redis-opcache-reset').on('click', (e) => {
                e.preventDefault();
                this.callOpcacheEndpoint('opcache-reset', e.currentTarget);
            });
            $('#ace-redis-opcache-prime').on('click', (e) => {
                e.preventDefault();
                this.callOpcacheEndpoint('opcache-prime', e.currentTarget);
            });
        }

        callOpcacheEndpoint(endpoint, btn) {
            const $btn = $(btn);
            const original = $btn.text();
            $btn.prop('disabled', true).text('Working...');
            $.ajax({
                url: ace_redis_admin.rest_url + 'ace-redis-cache/v1/' + endpoint,
                method: 'POST',
                beforeSend: (xhr) => xhr.setRequestHeader('X-WP-Nonce', ace_redis_admin.rest_nonce),
                data: { nonce: ace_redis_admin.nonce }
            }).done((resp) => {
                if (resp && resp.success) {
                    let message = '✅ ' + (resp.message || 'Success');
                    if (resp.files && resp.files.length) {
                        message += '\nFiles: ' + resp.files.join(', ');
                    }
                    alert(message);
                    this.fetchOpcacheStatus();
                } else {
                    alert('❌ ' + (resp && resp.message ? resp.message : 'Failed'));
                }
            }).fail(() => {
                alert('❌ Request failed');
            }).always(() => {
                $btn.prop('disabled', false).text(original);
            });
        }

        fetchOpcacheStatus() {
            $.ajax({
                url: ace_redis_admin.rest_url + 'ace-redis-cache/v1/opcache-status',
                method: 'GET',
                beforeSend: (xhr) => xhr.setRequestHeader('X-WP-Nonce', ace_redis_admin.rest_nonce)
            }).done((resp) => {
                if (resp && resp.success && resp.data) {
                    const d = resp.data;
                    let text = 'OPcache: ' + (d.enabled ? 'Enabled' : 'Disabled');
                    if (d.cached_scripts !== null) {
                        text += ' | Scripts: ' + d.cached_scripts;
                    }
                    if (d.hit_rate !== null) {
                        const hr = parseFloat(d.hit_rate);
                        if (!isNaN(hr)) text += ' | HitRate: ' + hr.toFixed(1) + '%';
                    }
                    let $inline = $('.opcache-status-inline');
                    if (!$inline.length) {
                        $inline = $('<span class="opcache-status-inline" style="margin-left:8px; font-size:11px; opacity:0.8;"></span>');
                        $('#opcache-helper-buttons').append($inline);
                    }
                    $inline.text(text);
                }
            });
        }

        // Initialize connection testing
        initConnectionTest() {
            $('#ace-redis-cache-test-btn').on('click', (e) => {
                e.preventDefault();
                this.testConnection();
            });

            $('#ace-redis-cache-test-write-btn').on('click', (e) => {
                e.preventDefault();
                this.testWriteRead();
            });
        }

        // Test Redis connection
        testConnection() {
            const $btn = $('#ace-redis-cache-test-btn');
            const originalText = $btn.text();

            $btn.text('Testing...').prop('disabled', true);

            $.ajax({
                url: ace_redis_admin.rest_url + "ace-redis-cache/v1/test-connection",
                type: 'POST',
                beforeSend: function(xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', ace_redis_admin.rest_nonce);
                },
                data: {
                    nonce: ace_redis_admin.nonce
                }
            })
                .done((response) => {
                    if (response.success) {
                        this.updateConnectionStatus(response.data);
                    } else {
                        this.showConnectionError(response.data || 'Connection failed');
                    }
                })
                .fail(() => {
                    this.showConnectionError('REST API request failed');
                })
                .always(() => {
                    $btn.text(originalText).prop('disabled', false);
                });
        }

        // Test write/read operations
        testWriteRead() {
            const $btn = $('#ace-redis-cache-test-write-btn');
            const originalText = $btn.text();

            $btn.text('Testing...').prop('disabled', true);

            $.ajax({
                url: ace_redis_admin.rest_url + "ace-redis-cache/v1/test-write-read",
                type: 'POST',
                beforeSend: function(xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', ace_redis_admin.rest_nonce);
                },
                data: {
                    nonce: ace_redis_admin.nonce
                }
            })
                .done((response) => {
                    if (response.success) {
                        this.showNotification(
                            '✅ Write/Read Test Successful\n' +
                        `Write: ${response.data.write}\n` +
                        `Read: ${response.data.read}\n` +
                        `Value: ${response.data.value}`,
                            'success'
                        );
                    } else {
                        this.showNotification(`❌ Test failed: ${response.data}`, 'error');
                    }
                })
                .fail(() => {
                    this.showNotification('❌ REST API request failed', 'error');
                })
                .always(() => {
                    $btn.text(originalText).prop('disabled', false);
                });
        }

        // Update connection status display
        updateConnectionStatus(data) {
            const $status = $('#ace-redis-cache-connection');
            const $size = $('#ace-redis-cache-size');
            const $serverInfo = $('#redis-server-info');
            const $serverType = $('#redis-server-type');
            const $suggestions = $('#redis-suggestions');

            $status.text(data.status)
                .removeClass('status-unknown status-error')
                .addClass('status-success');

            let sizeText = `${data.size} keys (${data.size_kb} KB)`;
            if (data.debug_info) {
                sizeText += ` - ${data.debug_info}`;
            }
            $size.text(sizeText);
            
            // Show server information if available
            if (data.server_type || data.suggestions) {
                $serverType.text(data.server_type || 'Unknown');
                
                // Display suggestions
                if (data.suggestions && data.suggestions.length > 0) {
                    let suggestionsHtml = '<p><strong>Recommendations:</strong></p><ul>';
                    data.suggestions.forEach(suggestion => {
                        suggestionsHtml += `<li>${suggestion}</li>`;
                    });
                    suggestionsHtml += '</ul>';
                    $suggestions.html(suggestionsHtml);
                } else {
                    $suggestions.html('<p><strong>Recommendations:</strong> Configuration looks good ✅</p>');
                }
                
                $serverInfo.slideDown(300);
            }
        }

        // Show connection error
        showConnectionError(message) {
            const $status = $('#ace-redis-cache-connection');
            const $size = $('#ace-redis-cache-size');
            const $serverInfo = $('#redis-server-info');

            $status.text(message)
                .removeClass('status-unknown status-success')
                .addClass('status-error');

            $size.text('0 keys (0 KB)');
            $serverInfo.slideUp(300);
        }

        // Initialize cache management
        initCacheManagement() {
            $('#ace-redis-cache-flush-btn').on('click', (e) => {
                e.preventDefault();
                this.clearAllCache();
            });
        }

        // Clear all cache
        clearAllCache() {
            if (!confirm('Are you sure you want to clear all cache? This action cannot be undone.')) {
                return;
            }

            const $btn = $('#ace-redis-cache-flush-btn');
            const originalText = $btn.text();

            $btn.text('Clearing...').prop('disabled', true);

            $.ajax({
                url: ace_redis_admin.rest_url + "ace-redis-cache/v1/flush-cache",
                type: 'POST',
                beforeSend: function(xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', ace_redis_admin.rest_nonce);
                },
                data: {
                    nonce: ace_redis_admin.nonce,
                    type: 'all'
                }
            })
                .done((response) => {
                    if (response.success) {
                        this.showNotification(`✅ ${response.data.message || 'Cache cleared successfully'}`, 'success');
                        $('#ace-redis-cache-size').text('0 keys (0 KB)');
                    } else {
                        this.showNotification(`❌ Failed to clear cache: ${response.data}`, 'error');
                    }
                })
                .fail(() => {
                    this.showNotification('❌ REST API request failed', 'error');
                })
                .always(() => {
                    $btn.text(originalText).prop('disabled', false);
                });
        }

    // Note: clearBlockCache removed; single Clear All handles all plugin-managed keys.

        // Initialize diagnostics
        initDiagnostics() {
            $('#ace-redis-cache-diagnostics-btn').on('click', (e) => {
                e.preventDefault();
                this.runDiagnostics();
            });
        }

        // Run system diagnostics
        runDiagnostics() {
            const $btn = $('#ace-redis-cache-diagnostics-btn');
            const $results = $('#diagnostics-results');
            const originalText = $btn.text();

            $btn.text('Running...').prop('disabled', true);
            $results.html('<p>⏳ Running comprehensive diagnostics...</p>');

            $.ajax({
                url: ace_redis_admin.rest_url + "ace-redis-cache/v1/diagnostics",
                type: 'POST',
                beforeSend: function(xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', ace_redis_admin.rest_nonce);
                },
                data: {
                    nonce: ace_redis_admin.nonce
                }
            })
                .done((response) => {
                    if (response.success && response.data) {
                        const diagnostics = Array.isArray(response.data)
                            ? response.data.join('\n')
                            : response.data;
                        $results.html(`<pre>${this.escapeHtml(diagnostics)}</pre>`);
                    } else {
                        $results.html(`<p class="error">❌ Failed to load diagnostics: ${response.data || 'Unknown error'}</p>`);
                    }
                })
                .fail(() => {
                    $results.html('<p class="error">❌ Diagnostics REST API request failed</p>');
                })
                .always(() => {
                    $btn.text(originalText).prop('disabled', false);
                });
        }

        // Initialize form validation
        initFormValidation() {
            $('#ace-redis-save-btn').on('click', (e) => {
                if (!this.validateForm()) {
                    e.preventDefault();
                    return false;
                }
            });

            // Real-time validation
            $('#redis_host').on('blur', this.validateHost);
            $('#redis_port').on('blur', this.validatePort);
            $('#ttl_page, #ttl_object').on('blur', this.validateTTL);
        }

        // Validate form inputs
        validateForm() {
            let isValid = true;
            const errors = [];

            // Validate host
            const host = $('#redis_host').val().trim();
            if (!host) {
                errors.push('Redis host is required');
                isValid = false;
            }

            // Validate port
            const port = parseInt($('#redis_port').val());
            if (!port || port < 1 || port > 65535) {
                errors.push('Redis port must be between 1 and 65535');
                isValid = false;
            }

            // Validate TTL
            const ttlPage = parseInt($('#ttl_page').val());
            const ttlObj = parseInt($('#ttl_object').val());
            if (!ttlPage || ttlPage < 60) {
                errors.push('Page Cache TTL must be at least 60 seconds');
                isValid = false;
            }
            if (!ttlObj || ttlObj < 60) {
                errors.push('Object Cache TTL must be at least 60 seconds');
                isValid = false;
            }

            if (!isValid) {
                this.showNotification(`❌ Validation errors:\n${errors.join('\n')}`, 'error');
            }

            return isValid;
        }

        // Individual field validators
        validateHost() {
            const $field = $(this);
            const value = $field.val().trim();

            if (!value) {
                $field.addClass('error');
                return false;
            }

            $field.removeClass('error');
            return true;
        }

        validatePort() {
            const $field = $(this);
            const value = parseInt($field.val());

            if (!value || value < 1 || value > 65535) {
                $field.addClass('error');
                return false;
            }

            $field.removeClass('error');
            return true;
        }

        validateTTL() {
            const $field = $(this);
            const value = parseInt($field.val());
            if (!value || value < 60) {
                $field.addClass('error');
                return false;
            }

            $field.removeClass('error');
            return true;
        }

        // Show notification
        showNotification(message, type = 'info') {
            // Use native alert for now - can be enhanced later
            alert(message);

            // Future: Create toast notifications
            // this.createToast(message, type);
        }

        // Initialize AJAX form submission
        initAjaxForm() {
            console.log('Initializing AJAX form...');
            
            // Handle form submission
            $('#ace-redis-settings-form').on('submit', (e) => {
                console.log('Form submit intercepted');
                e.preventDefault();
                e.stopPropagation();
                this.saveSettings();
                return false;
            });
            
            // Also handle direct button click as backup
            $('#ace-redis-save-btn').on('click', (e) => {
                console.log('Save button clicked');
                e.preventDefault();
                e.stopPropagation();
                this.saveSettings();
                return false;
            });
        }

        // Initialize change tracking for form fields
        initChangeTracking() {
            // Store original form data
            setTimeout(() => {
                // Store original button text
                const $button = $('#ace-redis-save-btn');
                if (!$button.data('original-text')) {
                    $button.data('original-text', $button.val());
                }
                
                this.captureOriginalFormData();
                this.updateSaveButtonState();
                
                // Watch for changes
                $('#ace-redis-settings-form input, #ace-redis-settings-form select, #ace-redis-settings-form textarea').on('input change', () => {
                    setTimeout(() => this.updateSaveButtonState(), 10);
                });
            }, 100);
        }
        
        // Capture original form data
        captureOriginalFormData() {
            this.originalFormData = this.getFormDataObject();
        }
        
        // Check if form has changes
        hasFormChanges() {
            if (!this.originalFormData) return false;
            
            const currentData = this.getFormDataObject();
            return JSON.stringify(this.originalFormData) !== JSON.stringify(currentData);
        }
        
        // Update save button state based on changes
        updateSaveButtonState() {
            const $button = $('#ace-redis-save-btn');
            const hasChanges = this.hasFormChanges();
            
            $button.prop('disabled', !hasChanges);
            
            if (!hasChanges) {
                $button.val($button.data('original-text') || 'Save Changes (No Changes)');
            } else {
                $button.val($button.data('original-text') || 'Save Changes');
            }
        }
        
        // Get form data as object
        getFormDataObject() {
            const $form = $('#ace-redis-settings-form');
            const formData = {};
            
            $form.serializeArray().forEach(field => {
                if (field.name.startsWith('ace_redis_cache_settings[')) {
                    const key = field.name.replace('ace_redis_cache_settings[', '').replace(']', '');
                    formData[key] = field.value;
                }
            });

            // Add checkbox values (unchecked boxes don't get serialized)
            $form.find('input[type="checkbox"]').each(function() {
                const name = $(this).attr('name');
                if (name && name.startsWith('ace_redis_cache_settings[')) {
                    const key = name.replace('ace_redis_cache_settings[', '').replace(']', '');
                    formData[key] = $(this).is(':checked') ? '1' : '0';
                }
            });
            
            return formData;
        }

        // Save settings via REST API
        saveSettings() {
            // Check if SaveBar is handling saves
            if (this.saveBar) {
                // Let SaveBar handle the save
                this.saveBar.handleSave();
                return;
            }
            
            // Fallback to original save logic
            this.performOriginalSave();
        }

        // Original save method for backward compatibility
        performOriginalSave() {
            // Check for changes first
            if (!this.hasFormChanges()) {
                this.showMessage('Error: Failed to save settings. No changes detected or database error.', 'error');
                return;
            }
            
            const $form = $('#ace-redis-settings-form');
            const $button = $('#ace-redis-save-btn');
            const $messages = $('#ace-redis-messages');
            
            console.log('REST API Save triggered...');
            
            // Show loading state
            const originalText = $button.val();
            $button.val('Saving...').prop('disabled', true);
            $messages.hide();

            // Get form data
            const formData = this.getFormDataObject();

            console.log('Sending data:', formData);
            console.log('REST URL:', ace_redis_admin.rest_url + 'ace-redis-cache/v1/settings');

            $.ajax({
                url: ace_redis_admin.rest_url + 'ace-redis-cache/v1/settings',
                type: 'POST',
                beforeSend: function(xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', ace_redis_admin.rest_nonce);
                    console.log('Set REST nonce:', ace_redis_admin.rest_nonce);
                },
                data: {
                    settings: formData,
                    nonce: ace_redis_admin.nonce
                },
                success: (response) => {
                    console.log('Success response:', response);
                    if (response.success) {
                        this.showMessage(response.message || 'Settings saved successfully!', 'success');
                        
                        // Update original form data and button state
                        this.captureOriginalFormData();
                        this.updateSaveButtonState();
                        
                        // Refresh connection status after save
                        setTimeout(() => {
                            if (typeof this.testConnection === 'function') {
                                this.testConnection();
                            }
                        }, 1000);
                    } else {
                        this.showMessage(response.message || 'Failed to save settings', 'error');
                    }
                },
                error: (xhr, status, error) => {
                    console.error('Save error:', {
                        status: xhr.status,
                        statusText: xhr.statusText,
                        responseText: xhr.responseText,
                        error: error
                    });
                    
                    let errorMessage = 'Network error occurred';
                    
                    if (xhr.responseJSON && xhr.responseJSON.message) {
                        errorMessage = xhr.responseJSON.message;
                    } else if (xhr.status === 504) {
                        errorMessage = 'Gateway timeout - settings may still be saved. Please refresh the page.';
                    } else if (xhr.status === 403) {
                        errorMessage = 'Permission denied. Please refresh the page and try again.';
                    } else if (xhr.status === 404) {
                        errorMessage = 'REST API endpoint not found. The plugin may not be properly configured.';
                    } else {
                        errorMessage = `${error} (Status: ${xhr.status})`;
                    }
                    
                    this.showMessage('Error: ' + errorMessage, 'error');
                },
                complete: () => {
                    // Reset button state
                    $button.val(originalText).prop('disabled', false);
                }
            });
        }

        // Show success/error message
        showMessage(message, type = 'success') {
            const $messages = $('#ace-redis-messages');
            const cssClass = type === 'success' ? 'notice-success' : 'notice-error';
            
            $messages.html(`
                <div class="notice ${cssClass} is-dismissible">
                    <p>${this.escapeHtml(message)}</p>
                    <button type="button" class="notice-dismiss">
                        <span class="screen-reader-text">Dismiss this notice.</span>
                    </button>
                </div>
            `).show();

            // Auto-hide success messages after 5 seconds
            if (type === 'success') {
                setTimeout(() => {
                    $messages.fadeOut();
                }, 5000);
            }

            // Handle dismiss button
            $messages.find('.notice-dismiss').on('click', function() {
                $messages.fadeOut();
            });
        }

        // Initialize performance metrics
        initPerformanceMetrics() {
            // Load lightweight metrics immediately if diagnostics tab is active
            if ($('#diagnostics').hasClass('active')) {
                setTimeout(() => {
                    this.loadPerformanceMetrics({ scope: 'basic' });
                }, 100);
            }
            
            // Initialize auto-refresh functionality
            this.initAutoRefresh();
            
            // Manual refresh button (light metrics only)
            $('#refresh-metrics-btn').on('click', () => {
                this.loadPerformanceMetrics({ scope: 'basic' });
                // Visual feedback for manual refresh
                const $btn = $('#refresh-metrics-btn');
                $btn.prop('disabled', true).html('⏳');
                setTimeout(() => {
                    $btn.prop('disabled', false).html('🔄');
                }, 1000);
            });

            // Make Plugin Memory button a toggle for auto-fetch
            $('#performance-metrics').on('click', '.fetch-plugin-memory', (e) => {
                e.preventDefault();
                // If cache disabled, ignore (annotateCacheDisabled handles disabling UI)
                const isDisabled = $(e.currentTarget).is(':disabled');
                if (isDisabled) return;

                this.pluginMemoryAuto = !this.pluginMemoryAuto;
                this.updatePluginMemoryToggleUI();

                // On enable, immediately compute once
                if (this.pluginMemoryAuto) {
                    this.fetchPluginMemory(true);
                }
            });
        }
        
        // Initialize auto-refresh functionality
        initAutoRefresh() {
            // Store references at class level
            this.autoRefreshInterval = null;
            this.countdownInterval = null;
            this.remainingSeconds = 0;
            
            const updateTimer = () => {
                const $timer = $('#refresh-timer');
                if (this.remainingSeconds > 0) {
                    $timer.text(`Next refresh in ${this.remainingSeconds}s`);
                    this.remainingSeconds--;
                } else {
                    $timer.text('');
                }
            };
            
            this.startAutoRefresh = (seconds) => {
                // Clear existing intervals
                if (this.autoRefreshInterval) {
                    clearInterval(this.autoRefreshInterval);
                }
                if (this.countdownInterval) {
                    clearInterval(this.countdownInterval);
                }
                
                const $timer = $('#refresh-timer');
                
                if (seconds > 0) {
                    this.remainingSeconds = seconds;
                    
                    // Start countdown timer
                    this.countdownInterval = setInterval(updateTimer, 1000);
                    
                    // Start auto-refresh timer
                    this.autoRefreshInterval = setInterval(() => {
                        // Only refresh if diagnostics tab is active
                        if ($('#diagnostics').hasClass('active')) {
                            // Use lightweight metrics for auto-refresh
                            this.loadPerformanceMetrics({ scope: 'basic' });
                            this.remainingSeconds = seconds; // Reset countdown
                        }
                    }, seconds * 1000);
                    
                    updateTimer(); // Show initial timer
                } else {
                    $timer.text('');
                }
            };
            
            // Handle dropdown change
            $('#auto-refresh-select').on('change', () => {
                const seconds = parseInt($('#auto-refresh-select').val());
                this.startAutoRefresh(seconds);
            });
            
            // Start with default value (30 seconds)
            this.startAutoRefresh(30);
        }
        
        // Pause auto-refresh timer (when leaving diagnostics tab)
        pauseAutoRefreshTimer() {
            if (this.countdownInterval) {
                clearInterval(this.countdownInterval);
            }
            $('#refresh-timer').text('');
        }
        
        // Resume auto-refresh timer (when entering diagnostics tab)
        resumeAutoRefreshTimer() {
            const selectedSeconds = parseInt($('#auto-refresh-select').val());
            if (selectedSeconds > 0) {
                // Restart the timer to show countdown immediately
                this.startAutoRefresh(selectedSeconds);
            }
        }

        // Load performance metrics via REST API
        loadPerformanceMetrics(options = {}) {
            // Safety check - only load if diagnostics tab is active
            if (!$('#diagnostics').hasClass('active')) {
                return;
            }
            const scope = options.scope || 'basic';
            $.ajax({
                url: ace_redis_admin.rest_url + 'ace-redis-cache/v1/metrics',
                type: 'GET',
                beforeSend: function(xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', ace_redis_admin.rest_nonce);
                },
                data: { scope },
                success: (response) => {
                    // Double-check we're still on the diagnostics tab when response comes back
                    if (!$('#diagnostics').hasClass('active')) {
                        return;
                    }
                    
                    if (response && response.data) {
                        const data = response.data;
                        this.updateMetricsDisplay(data, scope);
                        // If cache is disabled, annotate notes and disable per-card fetch
                        if (data.cache_enabled === false || response.message === 'Cache is disabled') {
                            this.annotateCacheDisabled();
                        } else {
                            // If auto mode is on, also fetch plugin memory without wiping existing values
                            if (this.pluginMemoryAuto) {
                                this.fetchPluginMemory(false);
                            }
                        }
                    } else if (response.data) {
                        // Even on error, use the fallback data provided
                        this.updateMetricsDisplay(response.data, scope);
                        if (this.pluginMemoryAuto) {
                            this.fetchPluginMemory(false);
                        }
                    }
                },
                error: () => {
                    // Use fallback metrics on error
                    this.updateMetricsDisplay({
                        cache_hit_rate: '--',
                        total_keys: '--',
                        memory_usage: '--',
                        response_time: '--',
                        uptime: '--',
                        connected_clients: '--',
                        ops_per_sec: '--'
                    }, scope);
                    if (this.pluginMemoryAuto) {
                        this.fetchPluginMemory(false);
                    }
                }
            });
        }

    // Update metrics display
        updateMetricsDisplay(metrics, scope = 'basic') {
            // Add debug logging for keyspace stats
            console.log('Debug keyspace stats:', {
                hits: metrics.debug_keyspace_hits,
                misses: metrics.debug_keyspace_misses,
                hit_rate: metrics.cache_hit_rate
            });
            // Show scope label (light/basic vs full)
            const $scopeLabel = $('#metrics-scope-label');
            if ($scopeLabel.length) {
                $scopeLabel.text(`(${scope === 'full' ? 'full' : 'light'})`);
            }
            
            $('#performance-metrics .metric-card').each(function() {
                const $card = $(this);
                const $value = $card.find('.metric-value');
                const title = $card.find('h4').text();
                const metricKey = $card.data('metric');
                let newValue = '--';
                
                switch (title) {
                    case 'Cache Hit Rate':
                        newValue = metrics.cache_hit_rate || '--';
                        break;
                    case 'Total Keys':
                        newValue = metrics.total_keys || '--';
                        break;
                    case 'Memory Usage':
                        newValue = metrics.memory_usage || '--';
                        break;
                    case 'Plugin Memory':
                        // Do not overwrite existing value if the payload doesn't include plugin memory data
                        if (typeof metrics.plugin_memory_total !== 'undefined' && metrics.plugin_memory_total !== null && metrics.plugin_memory_total !== '') {
                            newValue = metrics.plugin_memory_total;
                        } else {
                            newValue = $value.text() || '--';
                        }
                        // Also update breakdown if available, without overwriting the base description
                        const $breakdown = $card.find('.metric-breakdown');
                        if (typeof metrics.plugin_memory_total !== 'undefined') {
                            const parts = [];
                            if (metrics.plugin_memory_page) parts.push(`Page ${metrics.plugin_memory_page}`);
                            if (metrics.plugin_memory_minified) parts.push(`Minified ${metrics.plugin_memory_minified}`);
                            if (metrics.plugin_memory_blocks) parts.push(`Blocks ${metrics.plugin_memory_blocks}`);
                            if (metrics.plugin_memory_transients) parts.push(`Transients ${metrics.plugin_memory_transients}`);
                            if ($breakdown.length) {
                                $breakdown.text(parts.length ? ` | ${parts.join(' | ')}` : '');
                            }
                        }
                        break;
                    case 'Response Time':
                        newValue = metrics.response_time || '--';
                        break;
                    case 'Uptime':
                        newValue = metrics.uptime || '--';
                        break;
                    case 'Connected Clients':
                        newValue = metrics.connected_clients || '--';
                        break;
                    case 'Operations/sec':
                    case 'Ops/sec':
                        newValue = (metrics.ops_per_sec === 0) ? '0' : (metrics.ops_per_sec != null ? metrics.ops_per_sec : '--');
                        break;
                    case 'Connection Time':
                        newValue = metrics.connection_time || '--';
                        break;
                }
                
                // Add visual feedback when value changes
                const oldValue = $value.text();
                if (oldValue !== newValue) {
                    $value.fadeOut(100, function() {
                        $(this).text(newValue).fadeIn(100);
                    });
                } else {
                    $value.text(newValue);
                }

                // Annotate missing values with reasons where possible
                const $note = $card.find('.metric-note');
                if ($note.length) {
                    if (newValue === '--') {
                        let reason = '';
                        // Always light mode now
                            reason = 'light mode';
                        // Special instruction for plugin memory card
                        if (metricKey === 'plugin_memory') {
                            // If auto mode is enabled, annotate accordingly; otherwise prompt for fetch
                            if (this.pluginMemoryAuto) {
                                $note.text('auto mode (updated periodically)').show();
                            } else {
                                $note.text('Click Fetch to compute plugin memory').show();
                            }
                            return; // Skip generic reason
                        }
                        // For known restricted providers, indicate a possible restriction
                        // We can heuristically show this if memory/uptime/clients are missing
                        if (metricKey === 'memory_usage' || metricKey === 'uptime' || metricKey === 'connected_clients') {
                            reason = reason ? `${reason}; provider restrictions` : 'provider restrictions';
                        }
                        $note.text(reason).show();
                    } else {
                        $note.text('').hide();
                    }
                }
            });
            
            // Update last updated timestamp
            const now = new Date().toLocaleTimeString();
            $('.metrics-last-updated').text(`Last updated: ${now}`);
        }

        // When cache is disabled, reflect that in the UI and prevent heavy fetch actions
        annotateCacheDisabled() {
            // Note on all metric cards
            $('#performance-metrics .metric-card').each(function() {
                const $card = $(this);
                const $note = $card.find('.metric-note');
                if ($note.length) {
                    $note.text('cache disabled').show();
                }
            });
            // Disable per-card plugin memory fetch
            const $fetchBtn = $('#performance-metrics .fetch-plugin-memory');
            if ($fetchBtn.length) {
                $fetchBtn.prop('disabled', true).attr('title', 'Enable cache to compute plugin memory');
            }
            // Turn off auto mode since cache is off
            this.pluginMemoryAuto = false;
            this.updatePluginMemoryToggleUI();
        }

        // Update the Plugin Memory toggle button UI to reflect auto mode
        updatePluginMemoryToggleUI() {
            const $btn = $('#performance-metrics .fetch-plugin-memory');
            if (!$btn.length) return;
            if ($btn.is(':disabled')) return;
            if (this.pluginMemoryAuto) {
                $btn.text('Auto: On').addClass('is-on').attr('title', 'Disable auto plugin memory refresh');
            } else {
                $btn.text('Fetch').removeClass('is-on').attr('title', 'Fetch plugin memory and enable auto refresh');
            }
        }

        // Fetch plugin memory metrics (manual or auto), without wiping existing values
        fetchPluginMemory(showSpinner = true) {
            if (this.pluginMemoryFetching) return;
            const $card = $('#performance-metrics .metric-card[data-metric="plugin_memory"]');
            if (!$card.length) return;
            const $spinner = $card.find('.plugin-memory-spinner');
            const $value = $card.find('[data-field="plugin_memory_total"]');
            const $breakdown = $card.find('.metric-breakdown');
            const $note = $card.find('.metric-note');

            if (showSpinner) {
                $spinner.css('visibility', 'visible');
                $note.text(this.pluginMemoryAuto ? 'Auto computing…' : 'Computing…').show();
            }
            // Do not clear existing value/breakdown; we will update in place

            this.pluginMemoryFetching = true;
            $.ajax({
                url: ace_redis_admin.rest_url + 'ace-redis-cache/v1/plugin-memory',
                type: 'GET',
                beforeSend: function(xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', ace_redis_admin.rest_nonce);
                }
            })
            .done((resp) => {
                if (resp && resp.success) {
                    const d = resp.data || {};
                    if (d.plugin_memory_total) {
                        $value.text(d.plugin_memory_total);
                    }
                    const keyParts = [];
                    if (d.plugin_page_keys != null) keyParts.push(`pages: ${d.plugin_page_keys}`);
                    if (d.plugin_block_keys != null) keyParts.push(`blocks: ${d.plugin_block_keys}`);
                    if (d.plugin_total_keys != null) keyParts.push(`total keys: ${d.plugin_total_keys}`);

                    const memParts = [];
                    if (d.plugin_memory_page) memParts.push(`pages ${d.plugin_memory_page}`);
                    if (d.plugin_memory_minified) memParts.push(`minified ${d.plugin_memory_minified}`);
                    if (d.plugin_memory_blocks) memParts.push(`blocks ${d.plugin_memory_blocks}`);
                    if (d.plugin_memory_transients) memParts.push(`transients ${d.plugin_memory_transients}`);

                    const text = [keyParts.join(', '), memParts.join(', ')].filter(Boolean).join(' | ');
                    if (text) $breakdown.text(text);
                    const now = new Date().toLocaleTimeString();
                    $note.text(this.pluginMemoryAuto ? `auto updated ${now}` : 'Computed just now').show();
                } else {
                    $note.text((resp && resp.message) ? resp.message : 'Failed to fetch').show();
                }
            })
            .fail(() => {
                $note.text('Failed to fetch').show();
            })
            .always(() => {
                this.pluginMemoryFetching = false;
                if (showSpinner) $spinner.css('visibility', 'hidden');
            });
        }

        // Escape HTML for safe display
        escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    }

    // Initialize when document is ready
    $(document).ready(() => {
        // eslint-disable-next-line no-new
        new AceRedisCacheAdmin();
    });
})(jQuery);
