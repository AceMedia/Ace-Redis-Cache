<?php
/**
 * Admin Settings Page Template
 *
 * @package AceMedia\RedisCache
 * @since 0.5.0
 */

if (!defined('ABSPATH')) exit;

// Variables are passed from AdminInterface::render_settings_page()
// Available: $settings, $cache_manager
?>

<div class="wrap ace-redis-settings">
    
    
    <!-- Yoast-style Two-Column Layout -->
    <div class="ace-redis-container">


        <!-- Left Sidebar Navigation -->
        <div class="ace-redis-sidebar">
    <h1>Ace Redis Cache Settings</h1>
            <nav class="nav-tab-wrapper">
                <a href="#connection" class="nav-tab nav-tab-active">Connection</a>
                <a href="#caching" class="nav-tab">Caching</a>
                <a href="#exclusions" class="nav-tab">Exclusions</a>
                <a href="#diagnostics" class="nav-tab">Diagnostics</a>
            </nav>
            
            <!-- Cache Action Buttons -->
            <div class="cache-actions-panel">
                <h4>Cache Actions</h4>
                <div class="cache-action-buttons">
                    <button type="button" id="ace-redis-cache-flush-btn" class="button button-secondary cache-action-btn">
                        <span class="dashicons dashicons-admin-generic"></span>
                        Clear All Cache
                    </button>
                </div>
            </div>
        </div>
        
        <!-- Main Content Area -->
        <div class="ace-redis-content">

    <?php settings_errors(); ?>
            <!-- Settings Success/Error Messages -->
            <div id="ace-redis-messages" style="display: none;"></div>
            
            <form id="ace-redis-settings-form" method="post" class="ace-redis-form">
            
                <!-- Connection Tab -->
                <div id="connection" class="tab-content active">
                    <h2>Redis Connection Settings</h2>
                
                <div class="settings-form">
                    <div class="setting-row">
                        <div class="setting-label">
                            <label for="enable_cache">Enable Cache</label>
                        </div>
                        <div class="setting-field">
                            <label class="ace-switch">
                                <input type="checkbox" name="ace_redis_cache_settings[enabled]" id="enable_cache" value="1" <?php checked($settings['enabled']); ?> />
                                <span class="ace-slider"></span>
                            </label>
                            <p class="description">Enable or disable Redis caching system</p>
                        </div>
                    </div>
                    
                    <div class="setting-row">
                        <div class="setting-label">
                            <label for="redis_host">Redis Host</label>
                        </div>
                        <div class="setting-field">
                            <input type="text" name="ace_redis_cache_settings[host]" id="redis_host" value="<?php echo esc_attr($settings['host']); ?>" class="regular-text" placeholder="127.0.0.1" />
                            <p class="description">Redis server hostname or IP address</p>
                        </div>
                    </div>
                    
                    <div class="setting-row">
                        <div class="setting-label">
                            <label for="redis_port">Redis Port</label>
                        </div>
                        <div class="setting-field">
                            <input type="number" name="ace_redis_cache_settings[port]" id="redis_port" value="<?php echo esc_attr($settings['port']); ?>" min="1" max="65535" class="small-text" />
                            <p class="description">Redis server port (default: 6379)</p>
                        </div>
                    </div>
                    
                    <div class="setting-row">
                        <div class="setting-label">
                            <label for="redis_password">Password</label>
                        </div>
                        <div class="setting-field">
                            <input type="password" name="ace_redis_cache_settings[password]" id="redis_password" value="<?php echo esc_attr($settings['password']); ?>" class="regular-text" autocomplete="new-password" />
                            <p class="description">Redis AUTH password (leave empty if no authentication required)</p>
                        </div>
                    </div>
                    
                    <div class="setting-row">
                        <div class="setting-label">
                            <label for="enable_tls">TLS Encryption</label>
                        </div>
                        <div class="setting-field">
                            <label class="ace-switch">
                                <input type="checkbox" name="ace_redis_cache_settings[enable_tls]" id="enable_tls" value="1" <?php checked($settings['enable_tls'] ?? 1); ?> />
                                <span class="ace-slider"></span>
                            </label>
                            <p class="description">Enable TLS encryption (recommended for AWS Valkey/ElastiCache)</p>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Caching Tab -->
            <div id="caching" class="tab-content">
                <h2>Caching Options</h2>
                
                <div class="settings-form">
                    <div class="setting-row">
                        <div class="setting-label">
                            <label for="cache_mode">Cache Mode</label>
                        </div>
                        <div class="setting-field">
                            <select name="ace_redis_cache_settings[mode]" id="cache-mode-select" class="regular-text">
                                <option value="full" <?php selected($settings['mode'], 'full'); ?>>Full Page Cache</option>
                                <option value="object" <?php selected($settings['mode'], 'object'); ?>>Object Cache Only</option>
                            </select>
                            <p class="description">
                                <strong>Full Page Cache:</strong> Maximum performance but may conflict with dynamic content<br>
                                <strong>Object Cache:</strong> Cache transients and database queries only
                            </p>
                        </div>
                    </div>
                    
                    <div class="setting-row">
                        <div class="setting-label">
                            <label for="cache_ttl">Cache TTL</label>
                        </div>
                        <div class="setting-field">
                            <div class="input-group">
                                <input type="number" name="ace_redis_cache_settings[ttl]" id="cache_ttl" value="<?php echo esc_attr($settings['ttl']); ?>" min="60" max="604800" class="small-text" />
                                <span>seconds</span>
                            </div>
                            <p class="description">Default cache time-to-live (3600 = 1 hour, 86400 = 1 day)</p>
                        </div>
                    </div>
                    
                    <div class="setting-row" id="block-caching-row" style="<?php echo ($settings['mode'] === 'object') ? '' : 'display: none;'; ?>">
                        <div class="setting-label">
                            <label for="enable_block_caching">Block Caching</label>
                        </div>
                        <div class="setting-field">
                            <label class="ace-switch">
                                <input type="checkbox" name="ace_redis_cache_settings[enable_block_caching]" id="enable_block_caching" value="1" <?php checked($settings['enable_block_caching'] ?? 0); ?> />
                                <span class="ace-slider"></span>
                            </label>
                            <p class="description">Cache individual Gutenberg blocks for improved performance (Object Cache mode only)</p>
                        </div>
                    </div>

                    <div class="setting-row" id="transient-cache-row" style="<?php echo ($settings['mode'] === 'object') ? '' : 'display: none;'; ?>">
                        <div class="setting-label">
                            <label for="enable_transient_cache">Transient Cache (Guests)</label>
                        </div>
                        <div class="setting-field">
                            <label class="ace-switch">
                                <input type="checkbox" name="ace_redis_cache_settings[enable_transient_cache]" id="enable_transient_cache" value="1" <?php checked($settings['enable_transient_cache'] ?? 1); ?> />
                                <span class="ace-slider"></span>
                            </label>
                            <p class="description">Persist WordPress transients in Redis for logged-out visitors. Respects your transient exclusions.</p>
                        </div>
                    </div>

                    
                    
                    <div class="setting-row">
                        <div class="setting-label">
                            <label for="enable_minification">Minification</label>
                        </div>
                        <div class="setting-field">
                            <label class="ace-switch">
                                <input type="checkbox" name="ace_redis_cache_settings[enable_minification]" id="enable_minification" value="1" <?php checked($settings['enable_minification'] ?? 0); ?> />
                                <span class="ace-slider"></span>
                            </label>
                            <p class="description">Enable HTML, CSS, and JavaScript minification</p>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Exclusions Tab -->
            <div id="exclusions" class="tab-content">
                <h2>Cache Exclusions</h2>
                <p class="description">Configure patterns to exclude specific content from caching. Use one pattern per line.</p>
                
                <div class="settings-form">
                    <div class="setting-row">
                        <div class="setting-label">
                            <label for="custom_cache_exclusions">Cache Key Exclusions</label>
                        </div>
                        <div class="setting-field">
                            <textarea name="ace_redis_cache_settings[custom_cache_exclusions]" id="custom_cache_exclusions" rows="8" class="large-text code"><?php echo esc_textarea($settings['custom_cache_exclusions'] ?? ''); ?></textarea>
                            <p class="description">
                                Exclude cache keys matching these patterns (supports wildcards: *, ?).<br>
                                Example: <code>user_meta_*</code>, <code>temporary_*</code>
                            </p>
                        </div>
                    </div>
                    
                    <div class="setting-row">
                        <div class="setting-label">
                            <label for="custom_transient_exclusions">Transient Exclusions</label>
                        </div>
                        <div class="setting-field">
                            <textarea name="ace_redis_cache_settings[custom_transient_exclusions]" id="custom_transient_exclusions" rows="6" class="large-text code"><?php echo esc_textarea($settings['custom_transient_exclusions'] ?? ''); ?></textarea>
                            <p class="description">
                                Exclude WordPress transients from caching.<br>
                                Example: <code>wc_session_*</code>, <code>_transient_feed_*</code>
                            </p>
                        </div>
                    </div>
                    
                    <div class="setting-row">
                        <div class="setting-label">
                            <label for="custom_content_exclusions">Content Exclusions</label>
                        </div>
                        <div class="setting-field">
                            <textarea name="ace_redis_cache_settings[custom_content_exclusions]" id="custom_content_exclusions" rows="6" class="large-text code"><?php echo esc_textarea($settings['custom_content_exclusions'] ?? ''); ?></textarea>
                            <p class="description">
                                Exclude pages containing these text patterns from minification.<br>
                                Example: <code>&lt;script id="sensitive"&gt;</code>, <code>data-no-minify</code>
                            </p>
                        </div>
                    </div>
                    
                    <div class="setting-row">
                        <div class="setting-label">
                            <label for="excluded_blocks">Block Exclusions</label>
                        </div>
                        <div class="setting-field">
                            <textarea name="ace_redis_cache_settings[excluded_blocks]" id="excluded_blocks" rows="6" class="large-text code"><?php echo esc_textarea($settings['excluded_blocks'] ?? ''); ?></textarea>
                            <p class="description">
                                Exclude specific Gutenberg blocks from block-level caching.<br>
                                Example: <code>core/html</code>, <code>custom/dynamic-*</code>
                            </p>
                        </div>
                    </div>
                </div>
                
                <div class="exclusion-help">
                    <h4>📋 Exclusion Pattern Guidelines:</h4>
                    <ul>
                        <li><strong>Wildcards</strong>: Use <code>*</code> to match any characters, <code>?</code> for single character</li>
                        <li><strong>Comments</strong>: Add lines starting with <code>#</code> to document your exclusion patterns</li>
                        <li><strong>Clear All</strong>: Leave textareas empty to disable that exclusion type completely</li>
                    </ul>
                </div>
            </div>
            
            <!-- Diagnostics Tab -->
            <div id="diagnostics" class="tab-content">
                <h2>System Diagnostics</h2>
                
                <h3>Connection Test</h3>
                <div class="connection-test-panel">
                    <div class="test-buttons">
                        <button type="button" id="ace-redis-cache-test-btn" class="button button-primary">Test Connection</button>
                        <button type="button" id="ace-redis-cache-test-write-btn" class="button button-secondary">Test Write/Read</button>
                    </div>
                    <div class="test-results">
                        <p><strong>Status:</strong> <span id="ace-redis-cache-connection" class="status-unknown">Unknown</span></p>
                        <p><strong>Cache Size:</strong> <span id="ace-redis-cache-size">Unknown</span></p>
                        <div id="redis-server-info" style="display: none; margin-top: 10px; padding: 10px; background: #f9f9f9; border-left: 4px solid #0073aa;">
                            <p><strong>Server Type:</strong> <span id="redis-server-type">Unknown</span></p>
                            <div id="redis-suggestions"></div>
                        </div>
                    </div>
                </div>
                
                <h3>System Diagnostics</h3>
                <div class="diagnostics-panel">
                    <button type="button" id="ace-redis-cache-diagnostics-btn" class="button button-primary">Run Diagnostics</button>
                    <div id="diagnostics-results" class="diagnostics-output">
                        <p>Click "Run Diagnostics" to generate a comprehensive system report.</p>
                    </div>
                </div>
                
                <h3>Performance Metrics 
                    <button type="button" id="refresh-metrics-btn" class="button button-small" title="Refresh metrics now">🔄</button>
                    <select id="auto-refresh-select" class="button-small" style="margin-left: 10px; height: 28px;">
                        <option value="0">No auto-refresh</option>
                        <option value="5">Auto-refresh 5s</option>
                        <option value="15">Auto-refresh 15s</option>
                        <option value="30" selected>Auto-refresh 30s</option>
                        <option value="60">Auto-refresh 1min</option>
                    </select>
                    <span id="refresh-timer" style="margin-left: 10px; color: #666; font-size: 12px;"></span>
                </h3>
                <div id="performance-metrics" class="metrics-grid">
                    <div class="metric-card">
                        <h4>Cache Hit Rate</h4>
                        <div class="metric-value">--</div>
                        <div class="metric-description">Percentage of cache requests that were hits</div>
                    </div>
                    <div class="metric-card">
                        <h4>Total Keys</h4>
                        <div class="metric-value">--</div>
                        <div class="metric-description">Number of keys stored in Redis</div>
                    </div>
                    <div class="metric-card">
                        <h4>Memory Usage</h4>
                        <div class="metric-value">--</div>
                        <div class="metric-description">Redis memory consumption</div>
                    </div>
                    <div class="metric-card">
                        <h4>Plugin Memory</h4>
                        <div class="metric-value">--</div>
                        <div class="metric-description">Memory used by this plugin's keys</div>
                    </div>
                    <div class="metric-card">
                        <h4>Response Time</h4>
                        <div class="metric-value">--</div>
                        <div class="metric-description">Redis query response time</div>
                    </div>
                    <div class="metric-card">
                        <h4>Uptime</h4>
                        <div class="metric-value">--</div>
                        <div class="metric-description">Redis server uptime</div>
                    </div>
                    <div class="metric-card">
                        <h4>Connected Clients</h4>
                        <div class="metric-value">--</div>
                        <div class="metric-description">Active connections to Redis</div>
                    </div>
                    <div class="metric-card">
                        <h4>Operations/sec</h4>
                        <div class="metric-value">--</div>
                        <div class="metric-description">Redis operations per second</div>
                    </div>
                </div>
                <div class="metrics-last-updated">Last updated: Never</div>
            </div>
                
                <?php wp_nonce_field('ace_redis_admin_nonce', 'ace_redis_nonce'); ?>
            </form>
        </div> <!-- .ace-redis-content -->
    </div> <!-- .ace-redis-container -->
</div>
