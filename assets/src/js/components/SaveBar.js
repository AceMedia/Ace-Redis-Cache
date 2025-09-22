/**
 * SaveBar Component for Ace Redis Cache
 * 
 * Inspired by GlossPress SaveBar - provides a fixed bottom save bar
 * with save status messages, unsaved changes tracking and immediate auto-save
 *
 * @package AceMedia\RedisCache
 * @since 0.5.0
 */

class SaveBar {
    constructor(options = {}) {
        this.options = {
            containerSelector: '#ace-redis-settings-form',
            saveButtonSelector: '#ace-redis-save-btn',
            messageContainerSelector: '#ace-redis-messages',
            onSave: null,
            ...options
        };

        this.isInitialized = false;
        this.hasUnsavedChanges = false;
        this.isSaving = false;
        // Determine auto-save preference from localStorage; default to enabled on first run
        try {
            const stored = localStorage.getItem('ace_redis_auto_save_enabled');
            if (stored === null) {
                // Allow an explicit initial option to set the first-run default
                if (typeof this.options.autoSaveEnabled === 'boolean') {
                    this.isAutoSaveEnabled = this.options.autoSaveEnabled;
                } else {
                    this.isAutoSaveEnabled = true; // sensible default
                }
                // Persist the initial choice
                localStorage.setItem('ace_redis_auto_save_enabled', this.isAutoSaveEnabled ? '1' : '0');
            } else {
                this.isAutoSaveEnabled = (stored === '1');
            }
        } catch (e) {
            // Fallback if storage not available
            this.isAutoSaveEnabled = true;
        }
        this.isSuccess = false;
        this.message = '';
        this.elapsedTime = 0;
        this.intervalId = null;
        this.originalFormData = null;

        this.init();
    }

    init() {
        if (this.isInitialized) return;
        
        this.createSaveBar();
        this.setupEventListeners();
        this.captureOriginalFormData();
        this.updateSaveButtonState();
        this.isInitialized = true;
    }

    createSaveBar() {
        // Check if SaveBar already exists
        if (document.querySelector('.ace-redis-save-bar')) {
            return;
        }

    const autoSaveToggle = this.isAutoSaveEnabled ? 'checked' : '';

        const saveBarHTML = `
            <div class="ace-redis-save-bar">
                <div class="save-bar-content">
                    <div class="save-bar-left">
                        <span class="save-message"></span>
                    </div>
                    <div class="save-bar-right">
                        <div class="auto-save-toggle-wrapper">
                            <label class="ace-switch" for="auto-save-toggle">
                                <input type="checkbox" id="auto-save-toggle" ${autoSaveToggle}>
                                <span class="ace-slider"></span>
                            </label>
                            <span class="toggle-label">Auto-save</span>
                        </div>
                        <button type="button" id="save-bar-button" class="button button-primary" disabled>
                            <span class="dashicons dashicons-admin-settings"></span>
                            <span class="button-text">Saved</span>
                        </button>
                    </div>
                </div>
            </div>
        `;

        // Insert SaveBar into DOM
        document.body.insertAdjacentHTML('beforeend', saveBarHTML);
        this.updateFixedPosition();
    }

    setupEventListeners() {
        // Watch for form changes
        $(this.options.containerSelector).on('input change', 'input, select, textarea', () => {
            setTimeout(() => this.checkForChanges(), 10);
        });

        // SaveBar button click
        $(document).on('click', '#save-bar-button', (e) => {
            e.preventDefault();
            this.handleSave();
        });

        // Auto-save toggle click
        $(document).on('change', '#auto-save-toggle', () => {
            this.toggleAutoSave();
        });

        // Window events for positioning
        $(window).on('resize scroll load', () => this.updateFixedPosition());

        // Prevent unsaved changes from being lost
        $(window).on('beforeunload', (e) => {
            if (this.hasUnsavedChanges && !this.isSaving) {
                const message = 'You have unsaved changes. Are you sure you want to leave?';
                e.originalEvent.returnValue = message;
                return message;
            }
        });

        // WordPress admin menu resize handling
        if (window.wp && wp.hooks) {
            wp.hooks.addAction('wp-collapse-menu', 'ace-redis-cache', () => {
                setTimeout(() => this.updateFixedPosition(), 300);
            });
        }
    }

    updateFixedPosition() {
        const saveBar = document.querySelector('.ace-redis-save-bar');
        if (!saveBar) return;

        const adminMenuWrap = document.querySelector('#adminmenuwrap');
        if (adminMenuWrap) {
            const adminMenuWidth = adminMenuWrap.offsetWidth;
            saveBar.style.left = `${adminMenuWidth}px`;
        }
    }

    captureOriginalFormData() {
        const $form = $(this.options.containerSelector);
        this.originalFormData = this.getFormDataObject($form);
    }

    getFormDataObject($form) {
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

    checkForChanges() {
        if (!this.originalFormData) return;
        
        const $form = $(this.options.containerSelector);
        const currentData = this.getFormDataObject($form);
        const hasChanges = JSON.stringify(this.originalFormData) !== JSON.stringify(currentData);
        
        this.setUnsavedChanges(hasChanges);
    }

    setUnsavedChanges(hasChanges) {
        if (this.hasUnsavedChanges !== hasChanges) {
            this.hasUnsavedChanges = hasChanges;
            this.updateSaveButtonState();
            
            if (hasChanges) {
                this.startElapsedTimeTracking();
                // Auto-save immediately when changes are detected (if enabled)
                if (this.isAutoSaveEnabled) {
                    setTimeout(() => this.handleAutoSave(), 500); // Small delay to avoid rapid saves
                }
            } else {
                this.stopElapsedTimeTracking();
            }
        }
    }

    updateSaveButtonState() {
        const $button = $('#save-bar-button');
        const $buttonText = $button.find('.button-text');
        const $icon = $button.find('.dashicons');

        if (this.isSaving) {
            $button.prop('disabled', true).removeClass('success');
            $buttonText.text('Saving...');
            $icon.removeClass('dashicons-admin-settings dashicons-yes-alt').addClass('dashicons-update');
        } else if (this.isSuccess) {
            $button.prop('disabled', true).addClass('success');
            $buttonText.text('Saved!');
            $icon.removeClass('dashicons-admin-settings dashicons-update').addClass('dashicons-yes-alt');
        } else if (this.hasUnsavedChanges) {
            $button.prop('disabled', false).removeClass('success');
            $buttonText.text('Save Changes');
            $icon.removeClass('dashicons-update dashicons-yes-alt').addClass('dashicons-admin-settings');
        } else {
            $button.prop('disabled', true).removeClass('success');
            $buttonText.text('Saved');
            $icon.removeClass('dashicons-update dashicons-yes-alt').addClass('dashicons-admin-settings');
        }
    }

    async handleSave() {
        if (!this.hasUnsavedChanges || this.isSaving) return;

        this.setSaving(true);
        
        try {
            let success = false;
            
            if (this.options.onSave && typeof this.options.onSave === 'function') {
                success = await this.options.onSave();
            } else {
                // Default save logic - trigger the original form save
                success = await this.defaultSave();
            }

            if (success) {
                this.showMessage('Settings saved successfully!', 'success');
                this.setSuccess(true);
                this.captureOriginalFormData();
                this.setUnsavedChanges(false);
                
                // Clear success state after 3 seconds
                setTimeout(() => this.setSuccess(false), 3000);
            } else {
                this.showMessage('Save failed. Please try again.', 'error');
            }
        } catch (error) {
            console.error('Save error:', error);
            this.showMessage('An error occurred while saving.', 'error');
        } finally {
            this.setSaving(false);
        }
    }

    async handleAutoSave() {
        if (!this.hasUnsavedChanges || this.isSaving) return;

        console.log('[SaveBar] Auto-saving changes...');
        this.showMessage('Auto-saving...', 'info');

        try {
            let success = false;
            
            if (this.options.onSave && typeof this.options.onSave === 'function') {
                success = await this.options.onSave();
            } else {
                success = await this.defaultSave();
            }

            if (success) {
                this.showMessage('Changes auto-saved!', 'success');
                this.captureOriginalFormData();
                this.setUnsavedChanges(false);
            } else {
                this.showMessage('Auto-save failed', 'error');
            }
        } catch (error) {
            console.error('[SaveBar] Auto-save error:', error);
            this.showMessage('Auto-save error occurred', 'error');
        }
    }

    async defaultSave() {
        return new Promise((resolve) => {
            const $form = $(this.options.containerSelector);
            const formData = this.getFormDataObject($form);

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
                    resolve(response.success === true);
                },
                error: () => {
                    resolve(false);
                }
            });
        });
    }

    setSaving(isSaving) {
        this.isSaving = isSaving;
        this.updateSaveButtonState();
    }

    setSuccess(isSuccess) {
        this.isSuccess = isSuccess;
        this.updateSaveButtonState();
    }

    showMessage(message, type = 'info') {
        this.message = message;
        this.updateMessageDisplay(type);

        // Start elapsed time tracking for success messages
        if (type === 'success') {
            this.startElapsedTimeTracking();
        }

        // Auto-hide messages after different intervals based on type
        const hideDelay = type === 'error' ? 8000 : (type === 'success' ? 5000 : 3000);
        setTimeout(() => {
            this.clearMessage();
        }, hideDelay);
    }

    updateMessageDisplay(type = 'info') {
        const $messageContainer = $('.save-message');
        
        if (this.message) {
            $messageContainer
                .text(this.message)
                .addClass('visible')
                .removeClass('error success info')
                .addClass(type);
        } else {
            $messageContainer
                .removeClass('visible error success info')
                .text('');
        }
    }

    clearMessage() {
        this.message = '';
        this.updateMessageDisplay();
        this.stopElapsedTimeTracking();
    }

    startElapsedTimeTracking() {
        this.stopElapsedTimeTracking();
        this.elapsedTime = 0;
        
        this.intervalId = setInterval(() => {
            this.elapsedTime++;
            this.updateElapsedTimeDisplay();
        }, 1000);
    }

    stopElapsedTimeTracking() {
        if (this.intervalId) {
            clearInterval(this.intervalId);
            this.intervalId = null;
        }
    }

    updateElapsedTimeDisplay() {
        if (this.elapsedTime > 0) {
            const timeText = this.formatElapsedTime(this.elapsedTime);
            $('.save-message').text(timeText);
        }
    }

    formatElapsedTime(seconds) {
        if (seconds < 60) return `${seconds}s ago`;
        if (seconds < 3600) return `${Math.floor(seconds / 60)}m ago`;
        return `${Math.floor(seconds / 3600)}h ago`;
    }

    toggleAutoSave() {
        const $toggle = $('#auto-save-toggle');
    this.isAutoSaveEnabled = $toggle.is(':checked');
        
        if (this.isAutoSaveEnabled) {
            this.showMessage('Auto-save enabled - changes will be saved automatically', 'success');
        } else {
            this.showMessage('Auto-save disabled - manual save required', 'info');
        }
        
        // Store the preference
        try {
            localStorage.setItem('ace_redis_auto_save_enabled', this.isAutoSaveEnabled ? '1' : '0');
        } catch (e) { /* ignore */ }

        // Also persist per-user server-side so it survives devices/browsers
        if (typeof ace_redis_admin !== 'undefined' && ace_redis_admin.ajax_url) {
            try {
                jQuery.post(ace_redis_admin.ajax_url, {
                    action: 'ace_redis_toggle_autosave',
                    nonce: ace_redis_admin.nonce,
                    enabled: this.isAutoSaveEnabled ? 1 : 0
                });
            } catch (e) { /* ignore */ }
        }
    }

    destroy() {
        this.stopElapsedTimeTracking();
        
        // Remove event listeners
        $(this.options.containerSelector).off('input change');
        $(document).off('click', '#save-bar-button');
        $(document).off('change', '#auto-save-toggle');
        $(window).off('resize scroll load');
        $(window).off('beforeunload');

        // Remove SaveBar from DOM
        $('.ace-redis-save-bar').remove();
        
        this.isInitialized = false;
    }
}

// Export the SaveBar class as default for ES6 modules
export default SaveBar;
