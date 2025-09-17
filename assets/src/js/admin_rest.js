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

        // Initialize cache mode handling
        initCacheMode() {
            const toggleBlockCachingOption = () => {
                const cacheMode = $('#cache-mode-select').val();
                const $blockCachingRow = $('#block-caching-row');
                const $blockCachingCheckbox = $('input[name="ace_redis_cache_settings[enable_block_caching]"]');

                if (cacheMode === 'object') {
                    $blockCachingRow.show();
                } else {
                    $blockCachingRow.hide();
                    $blockCachingCheckbox.prop('checked', false);
                }
            };

            // Initialize on page load
            toggleBlockCachingOption();

            // Handle cache mode changes
            $('#cache-mode-select').on('change', toggleBlockCachingOption);
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
            $('#cache_ttl').on('blur', this.validateTTL);
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
            const ttl = parseInt($('#cache_ttl').val());
            if (!ttl || ttl < 60) {
                errors.push('Cache TTL must be at least 60 seconds');
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
