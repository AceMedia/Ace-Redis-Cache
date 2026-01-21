/**
 * Ace Redis Cache Admin JavaScript
 *
 * Handles admin interface interactions, AJAX requests,
 * and dynamic UI updates.
 *
 * @package AceMedia\RedisCache
 * @since 0.5.0
 */

(function($) {
    'use strict';

    // Main admin class
    class AceRedisCacheAdmin {
        constructor() {
            this.init();
        }

        init() {
            this.initTabs();
            this.initToggleSwitch();
            this.initCacheMode();
            this.initConnectionTest();
            this.initCacheManagement();
            this.initDiagnostics();
            this.initPerformanceMetrics();
            this.initAjaxForm();
            this.initFormValidation();
            this.initOpcacheButtons();
            this.initHealthTips();
            this.initDynamicAdvancedToggles();
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

        // Initialize cache mode handling (dual toggles)
        initCacheMode() {
            const toggleTTLVisibility = () => {
                const pageOn = $('#enable_page_cache').is(':checked');
                const objOn = $('#enable_object_cache').is(':checked');
                const $ttlPageWrap = $('#ttl_page').closest('.cache-type-options');
                const $ttlObjWrap = $('#ttl_object').closest('.cache-type-options');
                // Hide/show the entire option row portion so label + input disappear
                if ($ttlPageWrap.length) {
                    $ttlPageWrap.toggle(!!pageOn);
                }
                if ($ttlObjWrap.length) {
                    $ttlObjWrap.toggle(!!objOn);
                }
            };

            // Initialize on page load
            toggleTTLVisibility();

            // Handle toggle changes
            $('#enable_object_cache').on('change', function(){
                toggleTTLVisibility();
            });
            $('#enable_page_cache').on('change', function(){
                toggleTTLVisibility();
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

            $status.text(data.status)
                .removeClass('status-unknown status-error')
                .addClass('status-success');

            let sizeText = `${data.size} keys (${data.size_kb} KB)`;
            if (data.debug_info) {
                sizeText += ` - ${data.debug_info}`;
            }
            $size.text(sizeText);
        }

        // Show connection error
        showConnectionError(message) {
            const $status = $('#ace-redis-cache-connection');
            const $size = $('#ace-redis-cache-size');

            $status.text(message)
                .removeClass('status-unknown status-success')
                .addClass('status-error');

            $size.text('0 keys (0 KB)');
        }

        // Initialize cache management
        initCacheManagement() {
            $('#ace-redis-cache-flush-btn').on('click', (e) => {
                e.preventDefault();
                this.clearAllCache();
            });

            $('#ace-redis-cache-flush-blocks-btn').on('click', (e) => {
                e.preventDefault();
                this.clearBlockCache();
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
                    this.showNotification('❌ AJAX request failed', 'error');
                })
                .always(() => {
                    $btn.text(originalText).prop('disabled', false);
                });
        }

        // Clear block cache
        clearBlockCache() {
            if (!confirm('Clear all block cache? This will remove cached Gutenberg blocks.')) {
                return;
            }
            const $btn = $('#ace-redis-cache-flush-blocks-btn');
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
                    type: 'blocks'
                }
            })
                .done((response) => {
                    if (response.success) {
                        this.showNotification(`✅ ${response.data.message || 'Block cache cleared'}`, 'success');
                        // Refresh status
                        setTimeout(() => this.testConnection(), 500);
                    } else {
                        this.showNotification(`❌ Failed to clear block cache: ${response.data}`, 'error');
                    }
                })
                .fail(() => {
                    this.showNotification('❌ AJAX request failed', 'error');
                })
                .always(() => {
                    $btn.text(originalText).prop('disabled', false);
                });
        }
        initHealthTips() {
            setTimeout(() => this.refreshHealthTips(), 400);
            $('#enable_transient_cache').on('change', () => {
                setTimeout(() => this.refreshHealthTips(), 300);
            });
        }

        refreshHealthTips() {
            const $target = $('#ace-rc-transient-tips');
            if (!$target.length) return;
            $target.text('Loading cache health...');
            $.ajax({
                url: ace_redis_admin.rest_url + 'ace-redis-cache/v1/health',
                type: 'GET',
                beforeSend: function(xhr){ xhr.setRequestHeader('X-WP-Nonce', ace_redis_admin.rest_nonce); }
            }).done((resp) => {
                if (!resp || !resp.success) { $target.html('<span style="color:#cc0000;">Unable to load health data.</span>'); return; }
                const d = resp.data;
                const parts = [];
                parts.push('<strong>Cache Health:</strong> ' + (d.using_dropin ? 'Drop-in ' + (d.dropin_connected ? '<span style="color:green;">connected</span>' : '<span style="color:#cc0000;">not connected</span>') : '<span style="color:#cc0000;">drop-in missing</span>'));
                if (d.via) { parts.push('via ' + this.escapeHtml(d.via)); }
                if (d.bypass) { parts.push('<span style="color:#cc0000;">bypass active</span>'); }
                parts.push('autoload ~ ' + this.humanBytes(d.autoload_size));
                if (d.slow_ops) { parts.push(d.slow_ops + ' slow ops'); }
                if (d.error) { parts.push('error: <code>'+ this.escapeHtml(d.error) +'</code>'); }
                let tipsHtml = '';
                if (Array.isArray(d.tips) && d.tips.length) {
                    tipsHtml = '<ul style="margin:6px 0 0 16px; list-style:disc;">' + d.tips.map(t => '<li>'+this.escapeHtml(t)+'</li>').join('') + '</ul>';
                }
                $target.html(parts.join(' | ') + tipsHtml);
            }).fail(()=>{
                $target.html('<span style="color:#cc0000;">Health request failed.</span>');
            });
        }

        humanBytes(bytes) {
            if (!bytes || bytes < 1024) return (bytes||0) + 'B';
            const units=['KB','MB','GB','TB'];
            let i=-1; let val=bytes;
            do { val/=1024; i++; } while(val>=1024 && i<units.length-1);
            return val.toFixed(val>=10?0:1) + units[i];
        }
        // Initialize diagnostics
        initDiagnostics() {
            $('#ace-redis-cache-diagnostics-btn').on('click', (e) => {
                e.preventDefault();
                this.runDiagnostics();
            });
        }

        initOpcacheButtons() {
            const self = this;
            $('#ace-redis-opcache-reset').on('click', function(e){
                e.preventDefault();
                self.callOpcacheEndpoint('opcache-reset', this);
            });
            $('#ace-redis-opcache-prime').on('click', function(e){
                e.preventDefault();
                self.callOpcacheEndpoint('opcache-prime', this);
            });
            // Fetch status if buttons visible
            if ($('#opcache-helper-buttons').is(':visible')) {
                this.fetchOpcacheStatus();
            }
        }

        callOpcacheEndpoint(endpoint, btn) {
            const $btn = $(btn);
            const original = $btn.text();
            $btn.prop('disabled', true).text('Working...');
            $.ajax({
                url: ace_redis_admin.rest_url + 'ace-redis-cache/v1/' + endpoint,
                method: 'POST',
                beforeSend: function(xhr){ xhr.setRequestHeader('X-WP-Nonce', ace_redis_admin.rest_nonce); },
                data: { nonce: ace_redis_admin.nonce }
            }).done(resp => {
                const base = resp.message || 'Completed';
                if (resp.success) {
                    let extra = '';
                    if (resp.files && resp.files.length) {
                        extra = '\nFiles: ' + resp.files.join(', ');
                    }
                    self.showNotification('✅ ' + base + extra, 'success');
                    self.fetchOpcacheStatus();
                } else {
                    self.showNotification('❌ ' + base, 'error');
                }
            }).fail(() => alert('❌ Request failed'))
            .always(() => { $btn.prop('disabled', false).text(original); });
        }

        initDynamicAdvancedToggles() {
            // Show/hide OPcache buttons depending on toggle
            $('#enable_opcache_helpers').on('change', function(){
                const on = $(this).is(':checked');
                $('#opcache-helper-buttons').toggle(on);
                if (on) { setTimeout(() => { $('.opcache-status-inline').remove(); }); }
            });
            // Initial state update for buttons visibility handled by PHP inline style, also fetch status when enabled
            if ($('#enable_opcache_helpers').is(':checked')) {
                this.fetchOpcacheStatus();
            }
        }

        fetchOpcacheStatus() {
            $.ajax({
                url: ace_redis_admin.rest_url + 'ace-redis-cache/v1/opcache-status',
                method: 'GET',
                beforeSend: function(xhr){ xhr.setRequestHeader('X-WP-Nonce', ace_redis_admin.rest_nonce); }
            }).done(resp => {
                if (resp && resp.success && resp.data) {
                    const d = resp.data;
                    let text = 'OPcache: ' + (d.enabled ? 'Enabled' : 'Disabled');
                    if (d.cached_scripts !== null) {
                        text += ` | Scripts: ${d.cached_scripts}`;
                    }
                    if (d.hit_rate !== null) {
                        text += ` | HitRate: ${parseFloat(d.hit_rate).toFixed(1)}%`;
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
                    $results.html('<p class="error">❌ Diagnostics request failed</p>');
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

        // Save settings via REST API
        saveSettings() {
            const $form = $('#ace-redis-settings-form');
            const $button = $('#ace-redis-save-btn');
            const $messages = $('#ace-redis-messages');
            
            // Show loading state
            const originalText = $button.val();
            $button.val('Saving...').prop('disabled', true);
            $messages.hide();

            // Serialize form data properly for REST API
            const formData = {};
            $form.serializeArray().forEach(function(item) {
                if (item.name.includes('ace_redis_cache_settings[')) {
                    // Extract the setting name from the array notation
                    const settingName = item.name.match(/\[([^\]]+)\]/)[1];
                    formData[settingName] = item.value;
                }
            });

            $.ajax({
                url: ace_redis_admin.rest_url + "ace-redis-cache/v1/settings",
                type: 'POST',
                beforeSend: function(xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', ace_redis_admin.rest_nonce);
                },
                data: {
                    nonce: ace_redis_admin.nonce,
                    settings: formData
                },
                success: (response) => {
                    if (response.success) {
                        this.showMessage(response.data.message, 'success');
                        
                        // Refresh metrics if settings changed
                        if (response.data.settings_changed) {
                            setTimeout(() => {
                                this.loadPerformanceMetrics();
                            }, 1000);
                        }
                    } else {
                        this.showMessage(response.data || 'Failed to save settings', 'error');
                    }
                },
                error: (xhr, status, error) => {
                    this.showMessage('Network error: ' + error, 'error');
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
            // Load metrics on page load
            this.loadPerformanceMetrics();
            
            // Refresh metrics every 30 seconds
            setInterval(() => {
                this.loadPerformanceMetrics();
            }, 30000);
        }

        // Load performance metrics via AJAX
        loadPerformanceMetrics() {
            $.ajax({
                url: ace_redis_admin.rest_url + "ace-redis-cache/v1/metrics",
                type: 'GET',
                beforeSend: function(xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', ace_redis_admin.rest_nonce);
                },
                success: (response) => {
                    if (response.success) {
                        this.updateMetricsDisplay(response.data);
                    }
                },
                error: () => {
                    // Keep showing "--" on error
                }
            });
        }

        // Update metrics display
        updateMetricsDisplay(metrics) {
            $('#performance-metrics .metric-card').each(function() {
                const $card = $(this);
                const $value = $card.find('.metric-value');
                const title = $card.find('h4').text();
                
                switch (title) {
                    case 'Cache Hit Rate':
                        $value.text(metrics.cache_hit_rate || '--');
                        break;
                    case 'Memory Usage':
                        $value.text(metrics.memory_usage || '--');
                        break;
                    case 'Total Keys':
                        $value.text(metrics.total_keys || '--');
                        break;
                    case 'Connection Time':
                        $value.text(metrics.connection_time || '--');
                        break;
                }
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
