/**
 * REST API Update for Save Settings
 * 
 * This patch updates the saveSettings function to use REST API instead of admin-ajax
 */

// Wait for document ready and override the saveSettings method
jQuery(document).ready(function($) {
    // Find and override the existing instance
    setTimeout(function() {
        if (window.AceRedisCacheAdmin) {
            // If we have access to the instance, override the method
            if (window.AceRedisCacheAdmin.prototype) {
                window.AceRedisCacheAdmin.prototype.saveSettings = saveSettingsREST;
            }
        }
        
        // Also bind directly to button as backup
        $('#ace-redis-save-btn').off('click').on('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            saveSettingsREST();
            return false;
        });
        
        // Handle form submission
        $('#ace-redis-settings-form').off('submit').on('submit', function(e) {
            e.preventDefault();
            e.stopPropagation();
            saveSettingsREST();
            return false;
        });
    }, 100);
    
    /**
     * Save settings via REST API
     */
    function saveSettingsREST() {
        const $form = $('#ace-redis-settings-form');
        const $button = $('#ace-redis-save-btn');
        const $messages = $('#ace-redis-messages');
        
        console.log('REST API Save triggered...');
        
        // Show loading state
        const originalText = $button.val();
        $button.val('Saving...').prop('disabled', true);
        $messages.hide();

        // Serialize form data into object
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

        console.log('Sending data:', formData);
        console.log('REST URL:', ace_redis_admin.rest_url + 'ace-redis-cache/v1/settings');

        $.ajax({
            url: ace_redis_admin.rest_url + 'ace-redis-cache/v1/settings',
            type: 'POST',
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', ace_redis_admin.rest_nonce);
                console.log('Set REST nonce:', ace_redis_admin.rest_nonce);
            },
            contentType: 'application/json',
            data: JSON.stringify({
                settings: formData,
                nonce: ace_redis_admin.nonce
            }),
            success: function(response) {
                console.log('Success response:', response);
                if (response.success) {
                    showMessage(response.message || 'Settings saved successfully!', 'success');
                    
                    // Refresh connection status after save
                    setTimeout(() => {
                        if (typeof testConnection === 'function') {
                            testConnection();
                        }
                    }, 1000);
                } else {
                    showMessage(response.message || 'Failed to save settings', 'error');
                }
            },
            error: function(xhr, status, error) {
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
                
                showMessage('Error: ' + errorMessage, 'error');
            },
            complete: function() {
                // Reset button state
                $button.val(originalText).prop('disabled', false);
            }
        });
    }
    
    /**
     * Show success/error message
     */
    function showMessage(message, type = 'success') {
        const $messages = $('#ace-redis-messages');
        const cssClass = type === 'success' ? 'notice-success' : 'notice-error';
        
        $messages.html(`
            <div class="notice ${cssClass} is-dismissible">
                <p>${escapeHtml(message)}</p>
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
        $messages.find('.notice-dismiss').on('click', () => {
            $messages.fadeOut();
        });
    }
    
    /**
     * Escape HTML for safe display
     */
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
});
