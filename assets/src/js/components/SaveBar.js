/**
 * SaveBar Component for Ace Redis Cache
 * 
 * Inspired by GlossPress SaveBar - provides a fixed bottom save bar
 * with save status messages, auto-save toggle, and unsaved changes tracking
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
            autoSaveEnabled: false,
            autoSaveInterval: 30000, // 30 seconds
            ...options
        };

        this.isInitialized = false;
        this.hasUnsavedChanges = false;
        this.isSaving = false;
        this.isSuccess = false;
        this.message = '';
        this.elapsedTime = 0;
        this.intervalId = null;
        this.autoSaveIntervalId = null;
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

        // Load auto-save preference from localStorage
        const savedAutoSave = localStorage.getItem('ace_redis_cache_auto_save_enabled');
        if (savedAutoSave !== null) {
            this.options.autoSaveEnabled = savedAutoSave === '1';
        }

        const saveBarHTML = `
            <div class="ace-redis-save-bar">
                <div class="save-bar-content">
                    <div class="save-bar-left">
                        <span class="save-message"></span>
                    </div>
                    <div class="save-bar-right">
                        <div class="auto-save-toggle">
                            <label>
                                <input type="checkbox" id="auto-save-toggle" ${this.options.autoSaveEnabled ? 'checked' : ''}>
                                <span class="toggle-slider"></span>
                                Auto Save (${this.options.autoSaveInterval / 1000}s)
                            </label>
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
        
        // Initialize auto-save if enabled
        if (this.options.autoSaveEnabled) {
            this.startAutoSave();
        }
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

        // Auto-save toggle
        $(document).on('change', '#auto-save-toggle', (e) => {
            this.options.autoSaveEnabled = e.target.checked;
            this.toggleAutoSave();
            
            // Save auto-save preference to localStorage
            localStorage.setItem('ace_redis_cache_auto_save_enabled', this.options.autoSaveEnabled ? '1' : '0');
            
            // Show feedback message
            if (this.options.autoSaveEnabled) {
                this.showMessage(`Auto-save enabled! Changes will be saved every ${this.options.autoSaveInterval / 1000} seconds.`, 'info');
            } else {
                this.showMessage('Auto-save disabled.', 'info');
            }
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
        this.hasUnsavedChanges = hasChanges;
        this.updateSaveButtonState();
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
            $button.prop('disabled', this.options.autoSaveEnabled).removeClass('success'); // Disable if auto-save is on
            $buttonText.text(this.options.autoSaveEnabled ? 'Auto-saving...' : 'Save Changes');
            $icon.removeClass('dashicons-update dashicons-yes-alt').addClass('dashicons-admin-settings');
            
            // Add auto-saving visual indicator
            if (this.options.autoSaveEnabled) {
                $button.addClass('auto-saving');
            } else {
                $button.removeClass('auto-saving');
            }
        } else {
            $button.prop('disabled', true).removeClass('success');
            $buttonText.text('Saved');
            $icon.removeClass('dashicons-update dashicons-yes-alt').addClass('dashicons-admin-settings');
        }
    }

    async handleSave(isAutoSave = false) {
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
                const message = isAutoSave ? 
                    `Auto-saved successfully! (${new Date().toLocaleTimeString()})` : 
                    'Settings saved successfully!';
                    
                this.showMessage(message, 'success');
                this.setSuccess(true);
                this.captureOriginalFormData();
                this.setUnsavedChanges(false);
                
                // Clear success state after 3 seconds for manual saves, 2 seconds for auto-save
                setTimeout(() => this.setSuccess(false), isAutoSave ? 2000 : 3000);
            } else {
                const message = isAutoSave ? 
                    'Auto-save failed. Please save manually.' : 
                    'Failed to save settings. Please try again.';
                this.showMessage(message, 'error');
                
                // If auto-save fails, disable it temporarily
                if (isAutoSave) {
                    this.options.autoSaveEnabled = false;
                    $('#auto-save-toggle').prop('checked', false);
                    this.stopAutoSave();
                    localStorage.setItem('ace_redis_cache_auto_save_enabled', '0');
                }
            }
        } catch (error) {
            console.error('Save error:', error);
            const message = isAutoSave ? 
                'Auto-save error occurred. Auto-save disabled.' : 
                'An error occurred while saving. Please try again.';
            this.showMessage(message, 'error');
            
            // Disable auto-save on error
            if (isAutoSave) {
                this.options.autoSaveEnabled = false;
                $('#auto-save-toggle').prop('checked', false);
                this.stopAutoSave();
                localStorage.setItem('ace_redis_cache_auto_save_enabled', '0');
            }
        } finally {
            this.setSaving(false);
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
        if (seconds < 60) return `Saved ${seconds} seconds ago`;
        if (seconds < 3600) return `Saved ${Math.floor(seconds / 60)} minutes ago`;
        return `Saved ${Math.floor(seconds / 3600)} hours ago`;
    }

    toggleAutoSave() {
        if (this.options.autoSaveEnabled) {
            this.startAutoSave();
        } else {
            this.stopAutoSave();
        }
    }

    startAutoSave() {
        this.stopAutoSave();
        
        console.log(`Starting auto-save with ${this.options.autoSaveInterval / 1000}s interval`);
        
        this.autoSaveIntervalId = setInterval(() => {
            if (this.hasUnsavedChanges && !this.isSaving) {
                console.log('Auto-save triggered - saving changes...');
                this.showMessage(`Auto-saving changes...`, 'info');
                this.handleSave(true); // Pass true to indicate auto-save
            }
        }, this.options.autoSaveInterval);
        
        // Show confirmation message
        this.showMessage(`Auto-save started! Changes will be saved every ${this.options.autoSaveInterval / 1000} seconds.`, 'success');
    }

    stopAutoSave() {
        if (this.autoSaveIntervalId) {
            console.log('Stopping auto-save');
            clearInterval(this.autoSaveIntervalId);
            this.autoSaveIntervalId = null;
        }
    }

    destroy() {
        this.stopElapsedTimeTracking();
        this.stopAutoSave();
        
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
