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
if (!function_exists('ace_rc_scope_traffic_light')) {
    function ace_rc_scope_traffic_light($guest_state, $logged_state, $note = '') {
        $map = [
            'green' => ['label' => 'Active', 'class' => 'is-green', 'icon' => 'dashicons-yes-alt'],
            'amber' => ['label' => 'Limited', 'class' => 'is-amber', 'icon' => 'dashicons-warning'],
            'red'   => ['label' => 'Bypassed', 'class' => 'is-red', 'icon' => 'dashicons-no-alt'],
        ];

        $guest = $map[$guest_state] ?? $map['amber'];
        $logged = $map[$logged_state] ?? $map['amber'];
        $tooltip = sprintf(
            'Guest: %s. Logged-in/Admin: %s.%s',
            $guest['label'],
            $logged['label'],
            $note !== '' ? ' ' . $note : ''
        );

        ob_start();
        ?>
        <div class="ace-rc-scope-legend" tabindex="0" aria-label="<?php echo esc_attr($tooltip); ?>">
            <span class="ace-rc-scope-pill <?php echo esc_attr($guest['class']); ?>" aria-hidden="true">
                <span class="dashicons dashicons-admin-home ace-rc-scope-user-icon"></span>
                <span class="dashicons <?php echo esc_attr($guest['icon']); ?> ace-rc-scope-state-icon"></span>
            </span>
            <span class="ace-rc-scope-pill <?php echo esc_attr($logged['class']); ?>" aria-hidden="true">
                <span class="dashicons dashicons-admin-users ace-rc-scope-user-icon"></span>
                <span class="dashicons <?php echo esc_attr($logged['icon']); ?> ace-rc-scope-state-icon"></span>
            </span>
            <span class="ace-rc-scope-tooltip">
                <strong>Scope</strong>
                <span>Guest: <?php echo esc_html($guest['label']); ?></span>
                <span>Logged-in/Admin: <?php echo esc_html($logged['label']); ?></span>
                <?php if ($note !== '') : ?>
                    <span><?php echo esc_html($note); ?></span>
                <?php endif; ?>
            </span>
        </div>
        <?php
        return ob_get_clean();
    }
}

if (!function_exists('ace_rc_dropin_status_bootstrap')) {
    function ace_rc_dropin_status_bootstrap($type, $settings = []) {
        $plugin_root = dirname(dirname(__DIR__));
        $defs = [
            'object' => [
                'label' => 'Object Cache Drop-in',
                'source' => trailingslashit($plugin_root) . 'assets/dropins/object-cache.php',
                'target' => trailingslashit(WP_CONTENT_DIR) . 'object-cache.php',
                'signature' => 'Ace Redis Cache Drop-In',
            ],
            'advanced' => [
                'label' => 'Advanced Cache Drop-in',
                'source' => trailingslashit($plugin_root) . 'assets/dropins/advanced-cache.php',
                'target' => trailingslashit(WP_CONTENT_DIR) . 'advanced-cache.php',
                'signature' => 'Ace Redis Cache advanced-cache drop-in',
            ],
        ];

        if (!isset($defs[$type])) {
            return ['text' => 'Status unavailable', 'meta' => 'Unknown drop-in type.', 'button' => false, 'button_label' => ''];
        }

        $def = $defs[$type];
        $source_exists = file_exists($def['source']);
        $target_exists = file_exists($def['target']);
        $target_contents = $target_exists ? @file_get_contents($def['target']) : false;
        $managed = is_string($target_contents) && strpos($target_contents, $def['signature']) !== false;
        $source_hash = $source_exists ? @md5_file($def['source']) : null;
        $target_hash = is_string($target_contents) ? md5($target_contents) : null;
        $outdated = (bool) ($source_exists && $target_exists && $managed && $source_hash && $target_hash && $source_hash !== $target_hash);
        $enabled_setting = $type === 'object'
            ? (!empty($settings['enable_transient_cache']) || !empty($settings['enable_object_cache_dropin']))
            : !empty($settings['enable_page_cache']);
        $active = $type === 'object'
            ? (function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache())
            : (defined('WP_CACHE') && WP_CACHE && $target_exists);
        $target_dir = dirname($def['target']);
        $target_writable = $target_exists ? is_writable($def['target']) : (is_dir($target_dir) && is_writable($target_dir));
        $needs_install = $enabled_setting && !$target_exists;
        $can_update = $source_exists && $target_writable && ($needs_install || ($target_exists && $managed && $outdated));

        if (!$target_exists) {
            return [
                'text' => 'Not installed in wp-content.',
                'meta' => $enabled_setting ? 'Settings say this should be deployed. Use the button to install the latest copy.' : 'Feature currently disabled in settings.',
                'button' => $can_update,
                'button_label' => 'Install Latest Drop-in',
            ];
        }

        if (!$managed) {
            return [
                'text' => 'Foreign drop-in detected.',
                'meta' => 'This plugin will not overwrite non-Ace drop-ins.',
                'button' => false,
                'button_label' => '',
            ];
        }

        if ($outdated && $active) {
            return [
                'text' => 'Active and outdated.',
                'meta' => 'The live drop-in is behind the plugin copy. Update recommended now.',
                'button' => $can_update,
                'button_label' => 'Update Drop-in',
            ];
        }

        if ($outdated) {
            return [
                'text' => 'Outdated (inactive).',
                'meta' => 'It is behind the plugin copy. You can update it without toggling settings.',
                'button' => $can_update,
                'button_label' => 'Update Drop-in',
            ];
        }

        return [
            'text' => $active ? 'Active and up to date.' : 'Installed and up to date (inactive).',
            'meta' => '',
            'button' => false,
            'button_label' => '',
        ];
    }
}

$ace_rc_advanced_dropin_bootstrap = ace_rc_dropin_status_bootstrap('advanced', $settings);
$ace_rc_object_dropin_bootstrap = ace_rc_dropin_status_bootstrap('object', $settings);
?>

<div class="wrap ace-redis-settings">
    <style>
        .ace-redis-container { display: flex; align-items: flex-start; gap: 24px; }
        .ace-redis-sidebar { position: sticky; top: 48px; align-self: flex-start; max-height: calc(100vh - 64px); overflow: auto; }
        .ace-redis-content { min-width: 0; flex: 1 1 auto; }
        .ace-redis-settings h1,
        .ace-redis-settings h2,
        .ace-redis-settings h3,
        .ace-redis-settings h4 { font-weight: 800; }
        .ace-redis-settings .setting-label label,
        .ace-redis-settings .cache-type-toggle strong,
        .ace-redis-settings .setting-field > label:not(.ace-switch) { font-weight: 800; }
        .ace-redis-sidebar .nav-tab { display: inline-flex; align-items: center; gap: 8px; }
        .ace-redis-sidebar .nav-tab .ace-rc-tab-icon { display: none; }
        .ace-redis-sidebar .nav-tab::before {
            font-family: dashicons !important;
            font-size: 16px;
            line-height: 1;
            font-weight: 400;
            font-style: normal;
            speak: never;
            text-decoration: none;
            text-transform: none;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
            width: 16px;
            height: 16px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            opacity: 0.8;
            background: none !important;
            transform: none !important;
            border-radius: 0 !important;
            box-shadow: none !important;
        }
        .ace-redis-sidebar .nav-tab[href="#connection"]::before { content: "\f319"; }
        .ace-redis-sidebar .nav-tab[href="#caching"]::before { content: "\f226"; }
        .ace-redis-sidebar .nav-tab[href="#exclusions"]::before { content: "\f536"; }
        .ace-redis-sidebar .nav-tab[href="#diagnostics"]::before { content: "\f239"; }
        .ace-redis-sidebar .nav-tab.nav-tab-active { font-weight: 900; }
        .ace-redis-sidebar .nav-tab.nav-tab-active::before { opacity: 1; }
        .ace-redis-sidebar .nav-tab::after,
        .ace-redis-sidebar .nav-tab.nav-tab-active::after { display: none !important; content: none !important; }
        .ace-redis-brand { display: flex; flex-direction: column; align-items: center; gap: 10px; margin-bottom: 16px; text-align: center; }
        .ace-redis-brand-logo { display: block; width: min(88px, 100%); height: auto; filter: brightness(0); }
        .ace-redis-brand h1 { margin: 0; text-align: center; font-weight: 800; }
        @media (max-width: 960px) {
            .ace-redis-container { display: block; }
            .ace-redis-sidebar { position: static; top: auto; max-height: none; overflow: visible; }
        }
        .ace-rc-scope-panel,
        .ace-redis-settings .setting-field,
        .ace-redis-settings .cache-type-options { position: relative; }
        .ace-rc-scope-panel { margin: 0 0 16px; padding: 14px 16px; border: 1px solid #dcdcde; border-radius: 8px; background: linear-gradient(180deg, #fff, #f6f7f7); }
        .ace-rc-scope-panel .description { margin: 8px 0 0; }
        .ace-rc-scope-panel-toggle { display: flex; align-items: center; justify-content: space-between; gap: 12px; cursor: pointer; }
        .ace-rc-scope-panel-toggle h3 { margin: 0; font-size: 14px; }
        .ace-rc-scope-panel-toggle button { display: inline-flex; align-items: center; gap: 6px; }
        .ace-rc-scope-panel-body { margin-top: 12px; }
        .ace-rc-scope-panel.is-collapsed .ace-rc-scope-panel-body { display: none; }
        .ace-rc-scope-overview { display: flex; flex-wrap: wrap; gap: 10px; margin: 0 0 10px; }
        .ace-rc-scope-overview-item { display: inline-flex; align-items: center; gap: 8px; padding: 8px 10px; border-radius: 8px; background: rgba(255,255,255,0.8); border: 1px solid #dcdcde; font-size: 12px; }
        .ace-rc-scope-explainer { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 10px; margin-top: 10px; }
        .ace-rc-scope-explainer-item { padding: 10px 12px; border-radius: 8px; background: rgba(255,255,255,0.9); border: 1px solid #dcdcde; font-size: 12px; line-height: 1.45; }
        .ace-rc-scope-explainer-item strong { display: block; margin-bottom: 4px; font-size: 12px; }
        .ace-rc-scope-inline-icon { width: 16px; height: 16px; font-size: 16px; line-height: 16px; vertical-align: text-bottom; }
        .ace-rc-scope-legend { position: absolute; top: 0; right: 0; z-index: 3; display: inline-flex; gap: 6px; align-items: center; padding: 4px 6px; border-radius: 999px; background: rgba(255, 255, 255, 0.96); box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08); cursor: help; }
        .ace-rc-scope-legend:focus { outline: 2px solid #2271b1; outline-offset: 2px; }
        .ace-rc-scope-pill { display: inline-flex; align-items: center; gap: 4px; padding: 3px 7px; border-radius: 999px; border: 1px solid transparent; font-size: 11px; line-height: 1; }
        .ace-rc-scope-user-icon,
        .ace-rc-scope-state-icon { width: 14px; height: 14px; font-size: 14px; line-height: 14px; }
        .ace-rc-scope-user-icon { opacity: 0.9; }
        .ace-rc-scope-pill.is-green { background: #edf7ed; border-color: #b7dfb9; color: #155724; }
        .ace-rc-scope-pill.is-green .ace-rc-scope-state-icon { color: #2e7d32; }
        .ace-rc-scope-pill.is-amber { background: #fff8e1; border-color: #f0d18a; color: #8a5a00; }
        .ace-rc-scope-pill.is-amber .ace-rc-scope-state-icon { color: #d48a00; }
        .ace-rc-scope-pill.is-red { background: #fdecea; border-color: #efb8b2; color: #8a1f17; }
        .ace-rc-scope-pill.is-red .ace-rc-scope-state-icon { color: #c62828; }
        .ace-rc-scope-tooltip { position: absolute; top: calc(100% + 8px); right: 0; min-width: 250px; max-width: 320px; display: none; padding: 10px 12px; border-radius: 8px; background: #1d2327; color: #f0f0f1; box-shadow: 0 6px 18px rgba(0, 0, 0, 0.2); font-size: 12px; line-height: 1.45; }
        .ace-rc-scope-tooltip strong { display: block; margin-bottom: 4px; color: #fff; }
        .ace-rc-scope-tooltip span { display: block; }
        .ace-rc-scope-legend:hover .ace-rc-scope-tooltip,
        .ace-rc-scope-legend:focus .ace-rc-scope-tooltip,
        .ace-rc-scope-legend:focus-within .ace-rc-scope-tooltip { display: block; }
        .ace-redis-settings .tab-content {
            opacity: 0;
            transform: translateY(8px);
            transition: opacity 180ms ease, transform 220ms cubic-bezier(0.2, 0.8, 0.2, 1);
        }
        .ace-redis-settings .tab-content.active {
            opacity: 1;
            transform: translateY(0);
        }
        .ace-redis-settings .tab-content.is-leaving {
            opacity: 0;
            transform: translateY(-6px);
        }
        @media (prefers-reduced-motion: reduce) {
            .ace-redis-settings .tab-content,
            .ace-redis-settings .tab-content.active,
            .ace-redis-settings .tab-content.is-leaving {
                transition: none;
                transform: none;
            }
        }
    </style>
    
    
    <!-- Yoast-style Two-Column Layout -->
    <div class="ace-redis-container">


        <!-- Left Sidebar Navigation -->
        <div class="ace-redis-sidebar">
            <div class="ace-redis-brand">
                <img
                    src="<?php echo esc_url( plugins_url( 'assets/images/ace-media-logo-pure.png', dirname( dirname( __DIR__ ) ) . '/ace-redis-cache.php' ) ); ?>"
                    alt="Ace Media"
                    class="ace-redis-brand-logo"
                />
                <h1>Ace Redis Cache</h1>
            </div>
            <nav class="nav-tab-wrapper">
                <a href="#connection" class="nav-tab nav-tab-active"><span class="ace-rc-tab-icon"></span><span>Connection</span></a>
                <a href="#caching" class="nav-tab"><span class="ace-rc-tab-icon"></span><span>Caching</span></a>
                <a href="#exclusions" class="nav-tab"><span class="ace-rc-tab-icon"></span><span>Exclusions</span></a>
                <a href="#diagnostics" class="nav-tab"><span class="ace-rc-tab-icon"></span><span>Diagnostics</span></a>
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
                <div class="ace-rc-scope-panel" id="ace-rc-scope-panel">
                    <div class="ace-rc-scope-panel-toggle">
                        <h3>Traffic Scope Guide</h3>
                        <button type="button" class="button button-secondary" id="ace-rc-scope-panel-toggle-btn" aria-expanded="true">
                            <span class="dashicons dashicons-visibility"></span>
                            <span class="ace-rc-scope-panel-toggle-text">Hide guide</span>
                        </button>
                    </div>
                    <div class="ace-rc-scope-panel-body">
                        <p class="description">These icons show who each caching feature applies to. Guest traffic can use persistent page/object/transient cache. Logged-in, admin, AJAX, REST, and WooCommerce session requests use runtime-only behavior for those layers.</p>
                        <div class="ace-rc-scope-overview">
                            <span class="ace-rc-scope-overview-item">
                                <span class="dashicons dashicons-admin-home ace-rc-scope-inline-icon"></span>
                                <strong>Guest traffic</strong>
                            </span>
                            <span class="ace-rc-scope-overview-item">
                                <span class="dashicons dashicons-admin-users ace-rc-scope-inline-icon"></span>
                                <strong>Logged-in/Admin traffic</strong>
                            </span>
                            <span class="ace-rc-scope-overview-item">
                                <span class="dashicons dashicons-yes-alt ace-rc-scope-inline-icon" style="color:#2e7d32;"></span>
                                <span>Active</span>
                            </span>
                            <span class="ace-rc-scope-overview-item">
                                <span class="dashicons dashicons-warning ace-rc-scope-inline-icon" style="color:#d48a00;"></span>
                                <span>Limited / partial</span>
                            </span>
                            <span class="ace-rc-scope-overview-item">
                                <span class="dashicons dashicons-no-alt ace-rc-scope-inline-icon" style="color:#c62828;"></span>
                                <span>Bypassed</span>
                            </span>
                        </div>
                        <div class="ace-rc-scope-explainer">
                            <div class="ace-rc-scope-explainer-item">
                                <strong>Page, object, and transient cache</strong>
                                Guest requests can persist through Redis. Logged-in and admin-side requests are intentionally runtime-only.
                            </div>
                            <div class="ace-rc-scope-explainer-item">
                                <strong>Minification, static assets, and OPcache helpers</strong>
                                These are broader delivery/runtime features, so they can still apply beyond guest-only cache persistence.
                            </div>
                        </div>
                    </div>
                </div>
                
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
                                    <?php echo ace_rc_scope_traffic_light('green', 'red', 'Full page cache is guest/frontend only.'); ?>
                                    <label for="ttl_page" style="width:140px; display:inline-block;">Page Cache TTL</label>
                                    <input type="number" name="ace_redis_cache_settings[ttl_page]" id="ttl_page" value="<?php echo esc_attr($settings['ttl_page'] ?? $settings['ttl']); ?>" min="60" max="604800" class="small-text" />
                                    <span>seconds</span>
                                    <div id="ace-rc-advanced-dropin-box" style="margin-top:8px; font-size:12px; line-height:1.4;">
                                        <strong>Advanced Cache Drop-in:</strong>
                                        <span class="ace-rc-dropin-status"><?php echo esc_html($ace_rc_advanced_dropin_bootstrap['text']); ?></span>
                                        <button type="button" class="button button-secondary ace-rc-dropin-update" data-dropin="advanced" style="<?php echo !empty($ace_rc_advanced_dropin_bootstrap['button']) ? 'margin-left:8px;' : 'display:none; margin-left:8px;'; ?>"><?php echo esc_html($ace_rc_advanced_dropin_bootstrap['button_label'] ?: 'Update Drop-in'); ?></button>
                                        <div class="ace-rc-dropin-meta" style="margin-top:4px; color:#666;"><?php echo esc_html($ace_rc_advanced_dropin_bootstrap['meta']); ?></div>
                                    </div>
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
                                    <?php echo ace_rc_scope_traffic_light('green', 'amber', 'Logged-in/admin requests still use per-request runtime cache, but not Redis persistence.'); ?>
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
                            <?php echo ace_rc_scope_traffic_light('green', 'red', 'Transient persistence is only for guest/frontend traffic.'); ?>
                            <p class="description">Persist WordPress transients (including site transients) in Redis. Respects your transient exclusions. When enabled, we deploy an object-cache drop-in so WordPress routes transients to Redis. Ensure WP_CACHE is true.</p>
                            <div id="ace-rc-transient-tips" class="ace-rc-tips" style="margin-top:8px; font-size:12px; line-height:1.4;">
                                <!-- Dynamic health tips injected here -->
                            </div>
                            <div id="ace-rc-object-dropin-box" style="margin-top:10px; font-size:12px; line-height:1.4;">
                                <strong>Object Cache Drop-in:</strong>
                                <span class="ace-rc-dropin-status"><?php echo esc_html($ace_rc_object_dropin_bootstrap['text']); ?></span>
                                <button type="button" class="button button-secondary ace-rc-dropin-update" data-dropin="object" style="<?php echo !empty($ace_rc_object_dropin_bootstrap['button']) ? 'margin-left:8px;' : 'display:none; margin-left:8px;'; ?>"><?php echo esc_html($ace_rc_object_dropin_bootstrap['button_label'] ?: 'Update Drop-in'); ?></button>
                                <div class="ace-rc-dropin-meta" style="margin-top:4px; color:#666;"><?php echo esc_html($ace_rc_object_dropin_bootstrap['meta']); ?></div>
                            </div>
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
                            <?php echo ace_rc_scope_traffic_light('green', 'green'); ?>
                            <p class="description">Enable HTML, CSS, and JavaScript minification where this plugin processes the response.</p>
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
                            <?php echo ace_rc_scope_traffic_light('green', 'amber', 'Compression mainly affects cached guest responses and any shared output processing.'); ?>
                            <p class="description">Compress cached content to reduce size and bandwidth.</p>
                            <?php
                                $brotli_available = function_exists('brotli_compress');
                                $gzip_available = function_exists('gzencode') || function_exists('gzcompress');
                                $php_mm = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
                                $method = $settings['compression_method'] ?? 'brotli';
                                $available_methods = [];
                                if ($brotli_available) { $available_methods[] = 'brotli'; }
                                if ($gzip_available) { $available_methods[] = 'gzip'; }
                                if (count($available_methods) === 1) {
                                    $method = $available_methods[0];
                                } elseif (!in_array($method, ['brotli', 'gzip'], true)) {
                                    $method = !empty($available_methods) ? $available_methods[0] : 'brotli';
                                } elseif (!empty($available_methods) && !in_array($method, $available_methods, true)) {
                                    $method = in_array('gzip', $available_methods, true) ? 'gzip' : $available_methods[0];
                                }
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
                            <p class="description">If a method is unavailable in PHP, it will be greyed out. Runtime detected: PHP <?php echo esc_html($php_mm); ?>.</p>
                            <?php if (!$brotli_available || !$gzip_available): ?>
                            <details style="margin-top:10px;">
                                <summary style="cursor:pointer;">Install commands for missing compression methods</summary>
                                <div style="margin-top:8px;">
                                    <?php if (!$brotli_available): ?>
                                    <p style="margin:6px 0;"><strong>Brotli missing:</strong></p>
                                    <pre style="white-space:pre-wrap;">sudo apt update
sudo apt install -y php<?php echo esc_html($php_mm); ?>-brotli || sudo apt install -y php-brotli
sudo phpenmod -v <?php echo esc_html($php_mm); ?> -s fpm brotli || true
sudo systemctl restart php<?php echo esc_html($php_mm); ?>-fpm
php -m | grep -i brotli</pre>
                                    <?php endif; ?>
                                    <?php if (!$gzip_available): ?>
                                    <p style="margin:6px 0;"><strong>Gzip (zlib) missing:</strong></p>
                                    <pre style="white-space:pre-wrap;">sudo apt update
sudo apt install -y php<?php echo esc_html($php_mm); ?>-common zlib1g
sudo systemctl restart php<?php echo esc_html($php_mm); ?>-fpm
php -m | grep -i zlib</pre>
                                    <?php endif; ?>
                                </div>
                            </details>
                            <?php endif; ?>
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
                            <?php echo ace_rc_scope_traffic_light('green', 'red', 'This only applies on guest page-cache hits.'); ?>
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
                            <?php echo ace_rc_scope_traffic_light('green', 'green'); ?>
                            <p class="description">Set long-lived Cache-Control headers (public, immutable) and apply cache-busting query params to same-origin asset URLs.</p>
                            <div style="margin-top:8px;">
                                <label for="static_asset_cache_ttl" style="width:140px; display:inline-block;">Static TTL</label>
                                <input type="number" name="ace_redis_cache_settings[static_asset_cache_ttl]" id="static_asset_cache_ttl" value="<?php echo esc_attr($settings['static_asset_cache_ttl'] ?? 604800); ?>" min="86400" max="31536000" class="regular-text" style="max-width:160px;" /> seconds
                                <p class="description" style="margin-top:4px;">Recommended: 7 days (604800) – 1 year (31536000). Values outside 1d–1y are clamped.</p>
                            </div>
                            <?php $server_headers_detected = !empty($static_header_probe['checked']) && !empty($static_header_probe['detected']); ?>
                            <label style="margin-top:8px; display:block; <?php echo $server_headers_detected ? 'opacity:0.5;' : ''; ?>">
                                <input type="checkbox" name="ace_redis_cache_settings[manage_static_cache_via_htaccess]" id="manage_static_cache_via_htaccess" value="1"
                                    <?php checked($settings['manage_static_cache_via_htaccess'] ?? 0); ?>
                                    <?php disabled($server_headers_detected); ?> />
                                Manage static cache headers via site-root .htaccess (Apache only)
                            </label>
                            <?php if ($server_headers_detected): ?>
                                <p class="description" style="margin-top:4px; color:#2e7d32;">&#10003; Server-level headers already detected — .htaccess management disabled to avoid conflicts.</p>
                            <?php else: ?>
                                <p class="description">Writes a small managed block to <code>.htaccess</code> for static file Cache-Control and Expires headers when the server is not already doing it.</p>
                            <?php endif; ?>

                            <label style="margin-top:8px; display:block;">
                                <input type="checkbox" name="ace_redis_cache_settings[prefer_existing_static_cache_headers]" id="prefer_existing_static_cache_headers" value="1" <?php checked($settings['prefer_existing_static_cache_headers'] ?? 1); ?> />
                                Prefer existing server/CDN static headers
                            </label>
                            <p class="description">If long-lived cache headers are already detected, the plugin removes its managed .htaccess block to avoid duplicate logic.</p>

                            <?php if (!empty($static_header_probe['checked'])): ?>
                                <?php if ($server_headers_detected): ?>
                                    <p class="description" style="margin-top:8px; color:#2e7d32;">&#10003; Server is already sending long-lived static cache headers<?php echo !empty($static_header_probe['cache_control']) ? ' (<code>' . esc_html($static_header_probe['cache_control']) . '</code>)' : '.'; ?></p>
                                <?php else: ?>
                                    <p class="description" style="margin-top:8px; color:#b26a00;">Probe did not detect long-lived static cache headers<?php echo !empty($static_header_probe['cache_control']) ? ': ' . esc_html($static_header_probe['cache_control']) : '.'; ?> Enable .htaccess management above, or apply the server configuration below.</p>
                                <?php endif; ?>
                            <?php endif; ?>

                            <?php
                            $htaccess_ttl = (int)($settings['static_asset_cache_ttl'] ?? 604800);
                            if ($htaccess_ttl < 86400) { $htaccess_ttl = 86400; }
                            if ($htaccess_ttl > 31536000) { $htaccess_ttl = 31536000; }
                            $htaccess_years  = $htaccess_ttl >= 31536000 ? '1 year'   : ($htaccess_ttl >= 2592000 ? '1 month' : '1 week');
                            $abspath_str     = esc_js(ABSPATH);
                            $site_root       = rtrim(ABSPATH, '/\\');
                            ?>
                            <details class="ace-server-config-details" <?php echo !empty($static_header_probe['checked']) && empty($static_header_probe['detected']) ? 'open' : ''; ?>>
                                <summary>
                                    Server Configuration Instructions
                                    <?php if ($server_headers_detected): ?>
                                        <span class="ace-config-badge ace-config-badge--detected">&#10003; already applied</span>
                                    <?php else: ?>
                                        <span class="ace-config-badge ace-config-badge--missing">not yet detected</span>
                                    <?php endif; ?>
                                </summary>
                                <div class="ace-server-config-body">
                                    <p class="ace-snippet-intro">Apply one of these at the server level instead of using the .htaccess toggle above. Apache and Nginx options are provided.</p>

                                    <div class="ace-snippet-block">
                                        <p class="ace-snippet-label">Apache — add to <code><?php echo esc_html($site_root); ?>/.htaccess</code></p>
                                        <div class="ace-snippet-wrap">
                                            <pre id="ace-htaccess-rules"><?php
echo htmlspecialchars(
'# BEGIN Ace Redis Cache - Static Asset Headers
<IfModule mod_expires.c>
    ExpiresActive On
    ExpiresDefault                              "access plus 1 month"
    ExpiresByType text/html                     "access plus 0 seconds"
    ExpiresByType text/css                      "access plus ' . $htaccess_years . '"
    ExpiresByType application/javascript        "access plus ' . $htaccess_years . '"
    ExpiresByType text/javascript               "access plus ' . $htaccess_years . '"
    ExpiresByType application/x-javascript      "access plus ' . $htaccess_years . '"
    ExpiresByType image/jpeg                    "access plus ' . $htaccess_years . '"
    ExpiresByType image/png                     "access plus ' . $htaccess_years . '"
    ExpiresByType image/gif                     "access plus ' . $htaccess_years . '"
    ExpiresByType image/webp                    "access plus ' . $htaccess_years . '"
    ExpiresByType image/avif                    "access plus ' . $htaccess_years . '"
    ExpiresByType image/svg+xml                 "access plus ' . $htaccess_years . '"
    ExpiresByType image/x-icon                  "access plus ' . $htaccess_years . '"
    ExpiresByType font/woff                     "access plus ' . $htaccess_years . '"
    ExpiresByType font/woff2                    "access plus ' . $htaccess_years . '"
    ExpiresByType application/font-woff2        "access plus ' . $htaccess_years . '"
    ExpiresByType application/x-font-woff       "access plus ' . $htaccess_years . '"
    ExpiresByType application/x-font-ttf        "access plus ' . $htaccess_years . '"
    ExpiresByType font/opentype                 "access plus ' . $htaccess_years . '"
    ExpiresByType video/mp4                     "access plus ' . $htaccess_years . '"
    ExpiresByType application/pdf               "access plus 1 month"
</IfModule>
<IfModule mod_headers.c>
    <FilesMatch "\.(css|js|png|jpg|jpeg|gif|webp|avif|svg|ico|woff|woff2|ttf|eot|otf|mp4|pdf)$">
        Header set Cache-Control "public, max-age=' . $htaccess_ttl . ', immutable"
        Header unset ETag
    </FilesMatch>
    FileETag None
</IfModule>
# END Ace Redis Cache - Static Asset Headers'
);
                                            ?></pre>
                                            <button type="button" class="ace-copy-btn" data-target="ace-htaccess-rules">Copy</button>
                                        </div>
                                    </div>

                                    <div class="ace-snippet-block">
                                        <p class="ace-snippet-label">Nginx — add inside your <code>server {}</code> block</p>
                                        <div class="ace-snippet-wrap">
                                            <pre id="ace-nginx-rules"><?php
echo htmlspecialchars(
'# Ace Redis Cache - Static Asset Headers (Nginx)
location ~* \.(css|js|png|jpg|jpeg|gif|webp|avif|svg|ico|woff|woff2|ttf|eot|otf|mp4|pdf)$ {
    expires ' . $htaccess_ttl . 's;
    add_header Cache-Control "public, max-age=' . $htaccess_ttl . ', immutable";
    add_header X-Content-Type-Options "nosniff";
    etag off;
    access_log off;
}

# Reload after editing:
# sudo nginx -t && sudo systemctl reload nginx'
);
                                            ?></pre>
                                            <button type="button" class="ace-copy-btn" data-target="ace-nginx-rules">Copy</button>
                                        </div>
                                    </div>

                                    <p class="ace-snippet-footer">After applying, save settings here and the probe will re-run to confirm detection. The <em>Manage via .htaccess</em> option above will then be automatically disabled.</p>
                                </div>
                            </details>

                            <p class="description" style="margin-top:8px;">Legacy proxy mode remains disabled. The plugin now relies on long-lived headers plus filemtime-based asset URL versioning.</p>
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
                            <?php echo ace_rc_scope_traffic_light('green', 'red', 'Dynamic microcache only applies inside guest page-cache flows.'); ?>
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
                            <?php echo ace_rc_scope_traffic_light('green', 'green', 'These helpers act on server-level PHP OPcache, not per-user page/object cache state.'); ?>
                            <p class="description">Expose buttons to reset and lightly prime PHP OPcache for hot files after deploy or settings changes.</p>
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
                            <label for="exclude_sitemaps">Exclude Sitemaps</label>
                        </div>
                        <div class="setting-field">
                            <label class="ace-switch">
                                <input type="checkbox" name="ace_redis_cache_settings[exclude_sitemaps]" id="exclude_sitemaps" value="1" <?php checked($settings['exclude_sitemaps'] ?? 0); ?> />
                                <span class="ace-slider"></span>
                            </label>
                            <p class="description">Exclude sitemap URLs from full page cache when they are already cached elsewhere or need fresher regeneration.</p>
                        </div>
                    </div>
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

    // Code snippet copy buttons
    document.addEventListener('click', function(e) {
        var btn = e.target.closest('.ace-copy-btn');
        if (!btn) return;
        var targetId = btn.getAttribute('data-target');
        var pre = targetId ? document.getElementById(targetId) : null;
        if (!pre) return;
        navigator.clipboard.writeText(pre.textContent).then(function() {
            btn.textContent = 'Copied!';
            btn.classList.add('copied');
            setTimeout(function() {
                btn.textContent = 'Copy';
                btn.classList.remove('copied');
            }, 2000);
        });
    });
})();
</script>

<script>
(function(){
    var tabs = Array.prototype.slice.call(document.querySelectorAll('.ace-redis-sidebar .nav-tab'));
    var panels = Array.prototype.slice.call(document.querySelectorAll('.tab-content'));
    if (!tabs.length || !panels.length) return;

    var animating = false;

    function setActiveTab(target) {
        tabs.forEach(function(tab){
            tab.classList.toggle('nav-tab-active', tab.getAttribute('href') === target);
        });
    }

    function transitionTo(target, updateHash) {
        var next = document.querySelector(target);
        var current = document.querySelector('.tab-content.active');
        if (!next || animating || current === next) {
            if (next) setActiveTab(target);
            return;
        }

        animating = true;
        setActiveTab(target);

        if (updateHash && history && history.replaceState) {
            history.replaceState(null, '', target);
        }

        current.classList.add('is-leaving');

        window.setTimeout(function(){
            current.classList.remove('active', 'is-leaving');
            next.classList.add('active');
            animating = false;
        }, 170);
    }

    tabs.forEach(function(tab){
        tab.addEventListener('click', function(e){
            var target = tab.getAttribute('href');
            if (!target || target.charAt(0) !== '#') return;
            e.preventDefault();
            e.stopImmediatePropagation();
            transitionTo(target, true);
        }, true);
    });

    window.addEventListener('hashchange', function(){
        var hash = window.location.hash;
        if (hash && document.querySelector(hash)) {
            transitionTo(hash, false);
        }
    });
})();
</script>

<script>
(function($){
    if (!$ || typeof ace_redis_admin === 'undefined') return;

    function restGet(path) {
        return $.ajax({
            url: ace_redis_admin.rest_url + path,
            type: 'GET',
            timeout: 5000,
            beforeSend: function(xhr){ xhr.setRequestHeader('X-WP-Nonce', ace_redis_admin.rest_nonce); }
        });
    }

    function restPost(path, data) {
        return $.ajax({
            url: ace_redis_admin.rest_url + path,
            type: 'POST',
            data: data,
            timeout: 8000,
            beforeSend: function(xhr){ xhr.setRequestHeader('X-WP-Nonce', ace_redis_admin.rest_nonce); }
        });
    }

    function describeStatus(d) {
        if (!d) return { text: 'Unable to determine status.', meta: '' };
        if (!d.target_exists) return { text: 'Not installed in wp-content.', meta: d.enabled_setting ? 'Settings say this should be deployed. Use the button to install the latest copy.' : 'Feature currently disabled in settings.' };
        if (!d.managed) return { text: 'Foreign drop-in detected.', meta: 'This plugin will not overwrite non-Ace drop-ins.' };
        if (d.outdated && d.active) return { text: 'Active and outdated.', meta: 'The live drop-in is behind the plugin copy. Update recommended now.' };
        if (d.outdated && !d.active) return { text: 'Outdated (inactive).', meta: 'It is behind the plugin copy. You can update it without toggling settings.' };
        if (d.active) return { text: 'Active and up to date.', meta: '' };
        return { text: 'Installed and up to date (inactive).', meta: '' };
    }

    function renderDropinBox(type, d) {
        var boxId = type === 'advanced' ? '#ace-rc-advanced-dropin-box' : '#ace-rc-object-dropin-box';
        var $box = $(boxId);
        if (!$box.length) return;

        var status = describeStatus(d);
        $box.find('.ace-rc-dropin-status').text(status.text);
        $box.find('.ace-rc-dropin-meta').text(status.meta || '');

        var $btn = $box.find('.ace-rc-dropin-update');
        if (d && d.can_update) {
            $btn.show().prop('disabled', false).text(d.action === 'install' ? 'Install Latest Drop-in' : 'Update Drop-in');
        } else {
            $btn.hide().prop('disabled', true);
        }
    }

    function refreshDropinStatus() {
        restGet('ace-redis-cache/v1/dropins-status').done(function(resp){
            if (!resp || !resp.success || !resp.data) return;
            renderDropinBox('advanced', resp.data.advanced || null);
            renderDropinBox('object', resp.data.object || null);
        }).fail(function(xhr, textStatus){
            var meta = 'Drop-in status request failed.';
            if (xhr && xhr.status === 401) {
                meta = 'Permission or nonce error while checking drop-in status. Refresh the page and try again.';
            } else if (textStatus === 'timeout') {
                meta = 'Drop-in status check timed out. You can still save settings or retry.';
            }

            ['#ace-rc-advanced-dropin-box', '#ace-rc-object-dropin-box'].forEach(function(boxId){
                var $box = $(boxId);
                if (!$box.length) return;
                $box.find('.ace-rc-dropin-status').text('Status unavailable');
                $box.find('.ace-rc-dropin-meta').text(meta);
                $box.find('.ace-rc-dropin-update').hide().prop('disabled', true);
            });
        });
    }

    $(document).on('click', '.ace-rc-dropin-update', function(){
        var type = $(this).data('dropin');
        var $btn = $(this);
        if (!type) return;
        if (!window.confirm('Update this active drop-in to the latest plugin version?')) return;

        var original = $btn.text();
        $btn.prop('disabled', true).text('Updating...');

        restPost('ace-redis-cache/v1/dropins-update', {
            nonce: ace_redis_admin.nonce,
            type: type
        }).done(function(resp){
            if (resp && resp.success && resp.data) {
                var msg = resp.data.message || 'Drop-in update completed.';
                alert(msg);
            } else {
                alert('Drop-in update failed.');
            }
        }).fail(function(){
            alert('Drop-in update request failed.');
        }).always(function(){
            $btn.prop('disabled', false).text(original);
            refreshDropinStatus();
        });
    });

    $(function(){
        refreshDropinStatus();
        $('#enable_page_cache, #enable_transient_cache').on('change', function(){
            setTimeout(refreshDropinStatus, 400);
        });
        $(document).ajaxSuccess(function(_event, xhr, settings){
            var url = settings && settings.url ? settings.url : '';
            if (url.indexOf('/ace-redis-cache/v1/settings') === -1) return;
            var resp = xhr && xhr.responseJSON ? xhr.responseJSON : null;
            if (resp && resp.success && resp.data && resp.data.dropins) {
                renderDropinBox('advanced', resp.data.dropins.advanced || null);
                renderDropinBox('object', resp.data.dropins.object || null);
            }
        });
    });
})(window.jQuery);
</script>

<script>
(function(){
    var panel = document.getElementById('ace-rc-scope-panel');
    var btn = document.getElementById('ace-rc-scope-panel-toggle-btn');
    if (!panel || !btn) return;

    var key = 'ace_rc_scope_panel_collapsed';
    var textEl = btn.querySelector('.ace-rc-scope-panel-toggle-text');
    var iconEl = btn.querySelector('.dashicons');

    function applyState(collapsed) {
        panel.classList.toggle('is-collapsed', collapsed);
        btn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        if (textEl) textEl.textContent = collapsed ? 'Show guide' : 'Hide guide';
        if (iconEl) {
            iconEl.classList.remove('dashicons-visibility', 'dashicons-hidden');
            iconEl.classList.add(collapsed ? 'dashicons-hidden' : 'dashicons-visibility');
        }
    }

    var collapsed = false;
    try {
        collapsed = window.localStorage.getItem(key) === '1';
    } catch (e) {}
    applyState(collapsed);

    btn.addEventListener('click', function(){
        collapsed = !panel.classList.contains('is-collapsed');
        applyState(collapsed);
        try {
            window.localStorage.setItem(key, collapsed ? '1' : '0');
        } catch (e) {}
    });
})();
</script>

<script>
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
</script>
