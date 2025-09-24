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
                <div class="cache-action-buttons" style="<?php echo empty($settings['enabled']) ? 'display:none;' : ''; ?>">
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
            <script>
            (function(){
                // Toggle visibility of Clear Cache button based on Enable Cache checkbox
                var enableCb = document.getElementById('enable_cache');
                var actionsPanel = document.querySelector('.cache-actions-panel .cache-action-buttons');
                if (enableCb && actionsPanel) {
                    var updateVisibility = function() {
                        var enabled = !!enableCb.checked;
                        actionsPanel.style.display = enabled ? '' : 'none';
                        // Toggle diagnostics test buttons state
                        var testBtn = document.getElementById('ace-redis-cache-test-btn');
                        var testWRBtn = document.getElementById('ace-redis-cache-test-write-btn');
                        if (testBtn) testBtn.disabled = !enabled;
                        if (testWRBtn) testWRBtn.disabled = !enabled;
                    };
                    enableCb.addEventListener('change', updateVisibility);
                    // Ensure correct state on load as well (in case PHP didn't render expected state)
                    updateVisibility();
                }
            })();
            </script>
            
            <!-- Caching Tab -->
            <div id="caching" class="tab-content">
                <h2>Caching Options</h2>
                
                <div class="settings-form">
                    <div class="setting-row">
                        <div class="setting-label">
                            <label>Cache Types</label>
                        </div>
                        <div class="setting-field">
                            <!-- Page Cache toggle + options -->
                            <div class="cache-type-group" style="margin-bottom:16px;">
                                <div class="cache-type-toggle">
                                    <label class="ace-switch" style="margin-right:16px;">
                                        <input type="checkbox" name="ace_redis_cache_settings[enable_page_cache]" id="enable_page_cache" value="1" <?php checked($settings['enable_page_cache'] ?? (($settings['mode'] ?? 'full') === 'full')); ?> />
                                        <span class="ace-slider"></span>
                                    </label>
                                    <span><strong>Enable Full Page Cache</strong></span>
                                </div>
                                <div class="cache-type-options" style="margin-left:48px; margin-top:8px;">
                                    <label for="ttl_page" style="width:140px; display:inline-block;">Page Cache TTL</label>
                                    <input type="number" name="ace_redis_cache_settings[ttl_page]" id="ttl_page" value="<?php echo esc_attr($settings['ttl_page'] ?? $settings['ttl']); ?>" min="60" max="604800" class="small-text" />
                                    <span>seconds</span>
                                </div>
                            </div>

                            <!-- Object Cache toggle + options -->
                            <div class="cache-type-group">
                                <div class="cache-type-toggle">
                                    <label class="ace-switch" style="margin-right:16px;">
                                        <input type="checkbox" name="ace_redis_cache_settings[enable_object_cache]" id="enable_object_cache" value="1" <?php checked($settings['enable_object_cache'] ?? (($settings['mode'] ?? 'full') === 'object')); ?> />
                                        <span class="ace-slider"></span>
                                    </label>
                                    <span><strong>Enable Object Cache</strong> <span class="description">(transients, blocks)</span></span>
                                </div>
                                <div class="cache-type-options" style="margin-left:48px; margin-top:8px;">
                                    <label for="ttl_object" style="width:140px; display:inline-block;">Object Cache TTL</label>
                                    <input type="number" name="ace_redis_cache_settings[ttl_object]" id="ttl_object" value="<?php echo esc_attr($settings['ttl_object'] ?? $settings['ttl']); ?>" min="60" max="604800" class="small-text" />
                                    <span>seconds</span>
                                    <p class="description" style="margin-top:6px;">You can enable one or both cache types. Object cache controls transients and optional block caching.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="setting-row" id="transient-cache-row" style="<?php echo (!empty($settings['enable_object_cache']) || ($settings['mode'] ?? '') === 'object') ? '' : 'display: none;'; ?>">
                        <div class="setting-label">
                            <label for="enable_transient_cache">Transient Cache <span id="ace-rc-transient-status" style="display:inline-block; margin-left:6px; font-size:11px; padding:2px 6px; border-radius:10px; background:#ddd; color:#333; vertical-align:middle;">&nbsp;</span></label>
                        </div>
                        <div class="setting-field">
                            <label class="ace-switch">
                                <input type="checkbox" name="ace_redis_cache_settings[enable_transient_cache]" id="enable_transient_cache" value="1" <?php checked(!empty($settings['enable_transient_cache'])); ?> />
                                <span class="ace-slider"></span>
                            </label>
                            <p class="description">Persist WordPress transients (including site transients) in Redis. Respects your transient exclusions.<br/>
                            When enabled, we deploy an object-cache drop-in so WordPress routes transients to Redis. Ensure WP_CACHE is true.</p>
                            <div id="ace-rc-transient-tips" class="ace-rc-tips" style="margin-top:8px; font-size:12px; line-height:1.4;">
                                <!-- Dynamic health tips injected here -->
                            </div>
                        </div>
                    </div>
                    <div class="setting-row" id="block-caching-row" style="<?php echo (!empty($settings['enable_object_cache']) || ($settings['mode'] ?? '') === 'object') ? '' : 'display: none;'; ?>">
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

                    <div class="setting-row">
                        <div class="setting-label">
                            <label for="enable_compression">Compression</label>
                        </div>
                        <div class="setting-field">
                            <label class="ace-switch">
                                <input type="checkbox" name="ace_redis_cache_settings[enable_compression]" id="enable_compression" value="1" <?php checked($settings['enable_compression'] ?? 0); ?> />
                                <span class="ace-slider"></span>
                            </label>
                            <p class="description">Compress cached content to reduce size and bandwidth</p>
                            <?php
                                $brotli_available = function_exists('brotli_compress');
                                $gzip_available = function_exists('gzencode') || function_exists('gzcompress');
                                $method = $settings['compression_method'] ?? 'brotli';
                            ?>
                            <div class="compression-methods" style="margin-top:8px;">
                                <label style="margin-right: 12px; opacity: <?php echo $brotli_available ? '1' : '0.5'; ?>;">
                                    <input type="radio" name="ace_redis_cache_settings[compression_method]" value="brotli" <?php checked($method, 'brotli'); ?> <?php disabled(!$brotli_available); ?> />
                                    Brotli <?php if (!$brotli_available) echo '(not available)'; ?>
                                </label>
                                <label style="opacity: <?php echo $gzip_available ? '1' : '0.5'; ?>;">
                                    <input type="radio" name="ace_redis_cache_settings[compression_method]" value="gzip" <?php checked($method, 'gzip'); ?> <?php disabled(!$gzip_available); ?> />
                                    Gzip <?php if (!$gzip_available) echo '(not available)'; ?>
                                </label>
                            </div>
                            <p class="description">If a method is unavailable in PHP, it will be greyed out.</p>
                        </div>
                    </div>
                    <div class="setting-row">
                        <div class="setting-label">
                            <label for="enable_browser_cache_headers">Browser Cache Headers</label>
                        </div>
                        <div class="setting-field">
                            <label class="ace-switch">
                                <input type="checkbox" name="ace_redis_cache_settings[enable_browser_cache_headers]" id="enable_browser_cache_headers" value="1" <?php checked($settings['enable_browser_cache_headers'] ?? 0); ?> />
                                <span class="ace-slider"></span>
                            </label>
                            <p class="description">Send public Cache-Control headers on page cache HITs to allow browser/proxy reuse.</p>
                            <div style="margin-top:8px;">
                                <label for="browser_cache_max_age" style="width:140px; display:inline-block;">Max-Age</label>
                                <input type="number" name="ace_redis_cache_settings[browser_cache_max_age]" id="browser_cache_max_age" value="<?php echo esc_attr($settings['browser_cache_max_age'] ?? ($settings['ttl_page'] ?? 3600)); ?>" min="60" max="604800" class="small-text" /> seconds
                            </div>
                            <label style="margin-top:8px; display:block;">
                                <input type="checkbox" name="ace_redis_cache_settings[send_cache_meta_headers]" value="1" <?php checked($settings['send_cache_meta_headers'] ?? 0); ?> />
                                Send diagnostic cache meta headers (X-AceRedisCache-*)
                            </label>
                            <p class="description">Includes age, expires, compression, dynamic stats to aid debugging. Disable in production for minimal overhead.</p>
                        </div>
                    </div>
                    <div class="setting-row">
                        <div class="setting-label">
                            <label for="enable_static_asset_cache">Static Asset Cache</label>
                        </div>
                        <div class="setting-field">
                            <label class="ace-switch">
                                <input type="checkbox" name="ace_redis_cache_settings[enable_static_asset_cache]" id="enable_static_asset_cache" value="1" <?php checked($settings['enable_static_asset_cache'] ?? 0); ?> />
                                <span class="ace-slider"></span>
                            </label>
                            <p class="description">Set long-lived Cache-Control headers (public, immutable) for static files (images, CSS, JS, fonts) and pair with minification for optimal Lighthouse scores.</p>
                            <div style="margin-top:8px;">
                                <label for="static_asset_cache_ttl" style="width:140px; display:inline-block;">Static TTL</label>
                                <input type="number" name="ace_redis_cache_settings[static_asset_cache_ttl]" id="static_asset_cache_ttl" value="<?php echo esc_attr($settings['static_asset_cache_ttl'] ?? 604800); ?>" min="86400" max="31536000" class="regular-text" style="max-width:160px;" /> seconds
                                <p class="description" style="margin-top:4px;">Recommended: 7 days (604800) – 1 year (31536000). Values outside 1d–1y are clamped.</p>
                            </div>
                            <?php if (empty($settings['enable_minification'])): ?>
                                <p class="description" style="margin-top:8px; color:#d63638;">Tip: Enable Minification above for smaller CSS/JS when using long-lived asset caching.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="setting-row">
                        <div class="setting-label">
                            <label for="enable_dynamic_microcache">Dynamic Microcache</label>
                        </div>
                        <div class="setting-field">
                            <label class="ace-switch">
                                <input type="checkbox" name="ace_redis_cache_settings[enable_dynamic_microcache]" id="enable_dynamic_microcache" value="1" <?php checked($settings['enable_dynamic_microcache'] ?? 0); ?> />
                                <span class="ace-slider"></span>
                            </label>
                            <p class="description">Short-lived (1-60s) microcache for dynamic block HTML to reduce repeated renders under burst traffic.</p>
                            <div style="margin-top:8px;">
                                <label for="dynamic_microcache_ttl" style="width:140px; display:inline-block;">Microcache TTL</label>
                                <input type="number" name="ace_redis_cache_settings[dynamic_microcache_ttl]" id="dynamic_microcache_ttl" value="<?php echo esc_attr($settings['dynamic_microcache_ttl'] ?? 10); ?>" min="1" max="60" class="small-text" /> seconds
                            </div>
                        </div>
                    </div>
                    <div class="setting-row">
                        <div class="setting-label">
                            <label for="enable_opcache_helpers">OPcache Helpers</label>
                        </div>
                        <div class="setting-field">
                            <label class="ace-switch">
                                <input type="checkbox" name="ace_redis_cache_settings[enable_opcache_helpers]" id="enable_opcache_helpers" value="1" <?php checked($settings['enable_opcache_helpers'] ?? 0); ?> />
                                <span class="ace-slider"></span>
                            </label>
                            <p class="description">Expose buttons to reset and (lightly) prime PHP OPcache for hot files after deploy/settings changes.</p>
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
                            <label style="margin-top:8px; display:block;">
                                <input type="checkbox" name="ace_redis_cache_settings[exclude_basic_blocks]" value="1" <?php checked($settings['exclude_basic_blocks'] ?? 0); ?> />
                                Exclude common basic blocks (paragraph, heading, list, image, gallery)
                            </label>
                            <label style="margin-top:8px; display:block;">
                                <input type="checkbox" name="ace_redis_cache_settings[dynamic_excluded_blocks]" value="1" <?php checked($settings['dynamic_excluded_blocks'] ?? 0); ?> />
                                Treat excluded blocks as dynamic (remove them from page cache and re-render each request)
                            </label>
                            <p class="description">When enabled, every block pattern listed above (and the basic set if selected) is stripped out of the cached HTML and rendered fresh at runtime while the rest of the page remains fully cached.</p>
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
                        <button type="button" id="ace-redis-cache-test-btn" class="button button-primary" <?php echo empty($settings['enabled']) ? 'disabled' : ''; ?>>Test Connection</button>
                        <button type="button" id="ace-redis-cache-test-write-btn" class="button button-secondary" <?php echo empty($settings['enabled']) ? 'disabled' : ''; ?>>Test Write/Read</button>
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
                    <span id="opcache-helper-buttons" style="margin-left:12px; <?php echo empty($settings['enable_opcache_helpers']) ? 'display:none;' : ''; ?>">
                        <button type="button" id="ace-redis-opcache-reset" class="button">OPcache Reset</button>
                        <button type="button" id="ace-redis-opcache-prime" class="button">OPcache Prime</button>
                    </span>
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
                    <div class="metric-card" data-metric="memory_usage">
                        <h4>Memory Usage</h4>
                        <div class="metric-value">--</div>
                        <div class="metric-description">Redis memory consumption <span class="metric-note" style="display:none;"></span></div>
                    </div>
                    <div class="metric-card" data-metric="plugin_memory">
                            <h4>Plugin Memory 
                                <span title="Updated on manual refresh; cached ~2 min for performance" class="dashicons dashicons-info-outline"></span>
                                <button type="button" class="button button-small fetch-plugin-memory" aria-label="Fetch plugin memory" title="Fetch plugin memory">Fetch</button>
                                <span class="spinner is-active plugin-memory-spinner" style="float:none; visibility:hidden; margin-left:6px;"></span>
                            </h4>
                            <div class="metric-value" data-field="plugin_memory_total">--</div>
                            <div class="metric-breakdown small text-muted"></div>
                            <div class="metric-note text-muted small"></div>
                    </div>
                    <div class="metric-card">
                        <h4>Response Time</h4>
                        <div class="metric-value">--</div>
                        <div class="metric-description">Redis query response time</div>
                    </div>
                    <div class="metric-card" data-metric="uptime">
                        <h4>Uptime</h4>
                        <div class="metric-value">--</div>
                        <div class="metric-description">Redis server uptime <span class="metric-note" style="display:none;"></span></div>
                    </div>
                    <div class="metric-card" data-metric="connected_clients">
                        <h4>Connected Clients</h4>
                        <div class="metric-value">--</div>
                        <div class="metric-description">Active connections to Redis <span class="metric-note" style="display:none;"></span></div>
                    </div>
                    <div class="metric-card">
                        <h4>Operations/sec</h4>
                        <div class="metric-value">--</div>
                        <div class="metric-description">Redis operations per second</div>
                    </div>
                </div>
                <div class="metrics-last-updated">Last updated: Never <span id="metrics-scope-label" style="color:#666; font-size:12px;">(light)</span></div>
            </div>



                
                <?php wp_nonce_field('ace_redis_admin_nonce', 'ace_redis_nonce'); ?>
            </form>
        </div> <!-- .ace-redis-content -->
    </div> <!-- .ace-redis-container -->
</div>

<script>
// Fallback initializer: if main admin JS failed to bind tabs or cache actions.
(function(){
    if (window.AceRedisCacheAdminFallbackApplied) return; // avoid duplicate
    window.AceRedisCacheAdminFallbackApplied = true;
    var tabs = document.querySelectorAll('.ace-redis-sidebar .nav-tab');
    if (!tabs.length) return;
    // Detect if primary handler attached by checking for a data-flag on first click
    var first = tabs[0];
    var anyActive = document.querySelector('.tab-content.active');
    // If no active class present, activate first
    if (!anyActive) {
        var firstHref = first.getAttribute('href');
        if (firstHref) {
            var tc = document.querySelector(firstHref);
            if (tc) { tc.classList.add('active'); }
            first.classList.add('nav-tab-active');
        }
    }
    tabs.forEach(function(a){
        a.addEventListener('click', function(e){
            // If jQuery handler already prevented default, ignore fallback
            if (e.defaultPrevented) return;
            e.preventDefault();
            var target = a.getAttribute('href');
            if (!target || target.charAt(0) !== '#') return;
            document.querySelectorAll('.nav-tab').forEach(function(t){ t.classList.remove('nav-tab-active'); });
            a.classList.add('nav-tab-active');
            document.querySelectorAll('.tab-content').forEach(function(div){ div.classList.remove('active'); });
            var panel = document.querySelector(target);
            if (panel) panel.classList.add('active');
            if (history && history.replaceState) { history.replaceState(null, '', target); }
        });
    });

    // Clear cache fallback
    var flushBtn = document.getElementById('ace-redis-cache-flush-btn');
    if (flushBtn && typeof jQuery === 'undefined') {
        flushBtn.addEventListener('click', function(){
            if (!window.fetch) { alert('Fetch API not available'); return; }
            var restBase = (window.ace_redis_admin && ace_redis_admin.rest_url) ? ace_redis_admin.rest_url : (window.location.origin + '/wp-json/');
            var url = restBase.replace(/\/$/, '') + '/ace-redis-cache/v1/flush-cache';
            fetch(url, { method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/x-www-form-urlencoded','X-WP-Nonce': (window.ace_redis_admin? ace_redis_admin.rest_nonce : '')}, body:'nonce='+(window.ace_redis_admin? encodeURIComponent(ace_redis_admin.nonce):'') })
                .then(r=>r.json()).then(function(json){
                    if (json && json.success) {
                        flushBtn.disabled = false;
                        flushBtn.blur();
                        alert('Cache cleared');
                    } else { alert('Failed to clear cache'); }
                }).catch(function(){ alert('Error clearing cache'); });
        });
    }
})();
</script>
