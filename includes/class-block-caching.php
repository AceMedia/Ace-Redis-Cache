<?php
/**
 * Block Caching Class
 * 
 * Handles WordPress Block API integration and block-level caching
 * with intelligent cache key generation and exclusion support.
 *
 * @package AceMedia\RedisCache
 * @since 0.5.0
 */

namespace AceMedia\RedisCache;

if (!defined('ABSPATH')) exit;

class BlockCaching {
    
    private $cache_manager;
    private $settings;
    
    /**
     * Constructor
     *
     * @param CacheManager $cache_manager Cache manager instance
     * @param array $settings Plugin settings
     */
    public function __construct($cache_manager, $settings) {
        $this->cache_manager = $cache_manager;
        $this->settings = $settings;
    }
    
    /**
     * Setup block caching hooks
     */
    public function setup_hooks() {
        if (!($this->settings['enable_block_caching'] ?? false)) {
            return;
        }
        
        // Only attach render-time caching hooks for guest frontend requests
        if (!\is_admin() && !\is_user_logged_in() && !\wp_doing_ajax() && !(defined('REST_REQUEST') && REST_REQUEST)) {
            // Hook into block rendering
            add_filter('pre_render_block', [$this, 'control_block_caching'], 10, 2);
            add_filter('render_block', [$this, 'cache_block_output'], 10, 2);

            // Modify block cache support
            add_filter('block_type_supports', [$this, 'modify_block_cache_support'], 10, 3);
        }

        // Per-post invalidation: clear cached blocks when a post is saved/deleted (always active)
        add_action('save_post', [$this, 'invalidate_post_blocks'], 20, 1);
        add_action('deleted_post', [$this, 'invalidate_post_blocks'], 20, 1);
        // Additionally, when any published post updates, query loop blocks on other pages may reference it.
        // We can't map inner posts to container block cache keys (they key off the page's post id), so we aggressively
        // purge cached query-type blocks to avoid stale titles/permalinks. Filter allows refinement.
        add_action('post_updated', [$this, 'maybe_invalidate_query_blocks_on_post_update'], 20, 3);
        // Fallback: optionally purge all block cache after a post update (prevents partial layout mismatches)
        add_action('post_updated', [$this, 'maybe_purge_all_blocks_after_post_update'], 30, 3);
    }
    
    /**
     * Control block caching on pre-render
     *
     * @param string|null $pre_render Pre-rendered block content
     * @param array $parsed_block Parsed block data
     * @return string|null Pre-rendered content or null to continue rendering
     */
    public function control_block_caching($pre_render, $parsed_block) {
        if ($pre_render !== null) {
            return $pre_render;
        }
        
        $block_name = $parsed_block['blockName'] ?? '';
        
        // Skip if block should be excluded
        if ($this->should_exclude_block($block_name)) {
            return null;
        }
        
        // Generate cache key for this specific block instance
        $cache_key = $this->generate_block_cache_key($block_name, $parsed_block);
        
        // Try to get cached version
        $cached_content = $this->cache_manager->get($cache_key);
        if ($cached_content !== null) {
            return $cached_content;
        }
        
        return null; // Continue with normal rendering
    }
    
    /**
     * Cache block output after rendering
     *
     * @param string $block_content Rendered block content
     * @param array $block Block data
     * @return string Block content (unchanged)
     */
    public function cache_block_output($block_content, $block) {
        $block_name = $block['blockName'] ?? '';
        
        // Skip empty content or excluded blocks
        if (empty($block_content) || $this->should_exclude_block($block_name)) {
            return $block_content;
        }
        
        // Skip dynamic blocks that shouldn't be cached
        if ($this->is_dynamic_block($block_name)) {
            return $block_content;
        }
        
        // Generate cache key
        $cache_key = $this->generate_block_cache_key($block_name, $block, $block_content);
        
        // Cache the rendered content
        $this->cache_manager->set($cache_key, $block_content, $this->get_block_cache_ttl($block_name));
        
        return $block_content;
    }
    
    /**
     * Check if block should be excluded from caching
     *
     * @param string $block_name Block name
     * @return bool True if block should be excluded
     */
    private function should_exclude_block($block_name) {
        if (empty($block_name)) {
            return true;
        }
        
        // Get excluded blocks from settings
        $excluded_blocks = $this->get_excluded_blocks();
        
        foreach ($excluded_blocks as $pattern) {
            if (fnmatch($pattern, $block_name)) {
                return true;
            }
        }
        
        // Always exclude certain block types
        $always_exclude = [
            'core/shortcode',
            'core/html',
            'core/code',
            'core/preformatted'
        ];
        
        return in_array($block_name, $always_exclude);
    }
    
    /**
     * Get excluded blocks from settings
     *
     * @return array Array of excluded block patterns
     */
    private function get_excluded_blocks() {
        $exclusions = [];
        
        $custom_exclusions = $this->settings['excluded_blocks'] ?? '';
        if (!empty($custom_exclusions)) {
            $lines = explode("\n", $custom_exclusions);
            foreach ($lines as $line) {
                $line = trim($line);
                if (!empty($line) && !str_starts_with($line, '#')) {
                    $exclusions[] = $line;
                }
            }
        }

        // Optional: exclude a set of basic static blocks globally
        if (!empty($this->settings['exclude_basic_blocks'])) {
            // Expanded "basic" list now also includes common structural/layout blocks which are cheap to render
            // and tend to explode key cardinality. This reduces block_cache:* key count significantly.
            $basic = [
                // Text / simple content
                'core/paragraph',
                'core/heading',
                'core/list',
                'core/image',
                'core/gallery',
                // Structural/layout wrappers (new additions)
                'core/group',
                'core/columns',
                'core/column',
                'core/separator',
                'core/spacer',
                'core/cover',
                'core/buttons',
                'core/button',
                'core/media-text',
            ];
            // Allow external customization of the basic exclusion set
            $basic = apply_filters('ace_rc_basic_block_exclusions', $basic, $this->settings);
            $exclusions = array_merge($exclusions, $basic);
        }
        
        return $exclusions;
    }
    
    /**
     * Check if block is dynamic and requires fresh data
     *
     * @param string $block_name Block name
     * @return bool True if block is dynamic
     */
    private function is_dynamic_block($block_name) {
        $dynamic_blocks = [
            'core/query',
            'core/post-template',
            'core/query-loop',
            'core/post-content',
            'core/post-excerpt',
            'core/post-date',
            'core/post-title',
            'core/post-author',
            'core/post-featured-image',
            'core/comments',
            'core/comment-template',
            'core/latest-posts',
            'core/latest-comments',
            'core/calendar',
            'core/archives',
            'core/categories',
            'core/tag-cloud',
            'core/search',
            'core/loginout'
        ];
        
        return in_array($block_name, $dynamic_blocks);
    }
    
    /**
     * Generate cache key for block instance
     *
     * @param string $block_name Block name
     * @param array $block Block data
     * @param string $block_content Rendered content (optional)
     * @return string Cache key
     */
    private function generate_block_cache_key($block_name, $block, $block_content = '') {
        $post_id = (int) (get_the_ID() ?: 0);
        $key_components = [
            'block_cache',
            $block_name,
            'post-' . $post_id,
            md5(serialize($block['attrs'] ?? [])),
            md5($block['innerHTML'] ?? ''),
        ];
        // Previously we added a rendered content hash (first 1KB) which produced many near-duplicate keys
        // when content only changed minutely or when filters injected markup. To reduce cardinality we
        // disable this by default; a filter can re-enable if required for edge cases.
    $default_include = !empty($this->settings['include_rendered_block_hash']);
    $include_render_hash = apply_filters('ace_rc_block_cache_include_rendered_hash', $default_include, $block_name, $block, $block_content, $this->settings);
        if ($include_render_hash && !empty($block_content)) {
            $key_components[] = md5(substr($block_content, 0, 1000));
        }
        
        // Add context-specific data
        $context_data = [
            'post_id' => $post_id,
            'is_single' => is_single(),
            'is_page' => is_page(),
            'is_home' => is_home(),
            'is_front_page' => is_front_page(),
            'user_logged_in' => is_user_logged_in()
        ];
        
        $key_components[] = md5(serialize($context_data));
        // Final opportunity to adjust components (e.g. remove context hash or attrs) for advanced tuning
        $key_components = apply_filters('ace_rc_block_cache_key_components', $key_components, $block_name, $block, $block_content, $this->settings);
        return implode(':', $key_components);
    }

    /**
     * Invalidate cached blocks for a specific post.
     *
     * @param int $post_id Post ID
     */
    public function invalidate_post_blocks($post_id) {
        if (!$post_id || wp_is_post_revision($post_id)) {
            return;
        }
        // Pattern matches keys built as: block_cache:{block}:{post-<id>}:*
        $pattern = 'block_cache:*:post-' . (int)$post_id . ':*';
        $keys = $this->cache_manager->scan_keys($pattern);
        if (!empty($keys)) {
            $this->cache_manager->delete_keys_chunked($keys);
        }
    }

    /**
     * Invalidate cached query loop style blocks (query, latest posts, post template) when a published post changes.
     * These block cache keys are keyed by the containing page/post id, not inner loop post ids, so the direct
     * post-specific invalidation above won't touch them. This broad purge can be narrowed via filter.
     */
    public function maybe_invalidate_query_blocks_on_post_update($post_ID, $post_after, $post_before) {
        if (wp_is_post_revision($post_ID)) return;
        if (!$post_after || !$post_before) return;
        // Only act if the post was or is published (affects public listings)
        if ($post_before->post_status !== 'publish' && $post_after->post_status !== 'publish') return;
        // Skip if block caching disabled mid-request
        if (!($this->settings['enable_block_caching'] ?? false)) return;
        $blocks_to_purge = apply_filters('ace_rc_query_block_invalidation_block_types', [
            'core/query',
            'core/query-loop',
            'core/latest-posts',
            'core/post-template',
        ], $post_ID, $post_after, $post_before, $this->settings);
        if (empty($blocks_to_purge)) return;
        $deleted_total = 0;
        foreach ($blocks_to_purge as $bname) {
            $pattern = 'block_cache:' . $bname . ':post-*';
            $keys = $this->cache_manager->scan_keys($pattern . ':*');
            if (!empty($keys)) {
                $this->cache_manager->delete_keys_chunked($keys, 500);
                $deleted_total += count($keys);
            }
        }
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf('Ace-Redis-Cache: query block purge after post update id=%d deleted=%d', $post_ID, $deleted_total));
        }
    }

    /**
     * Optional aggressive fallback: purge the entire block cache after any published post update.
     * Prevents broken layout when partial block invalidation leaves stale structural fragments.
     * Controlled by filter 'ace_rc_purge_all_block_cache_on_post_update' (default true until granular dependency graph exists).
     */
    public function maybe_purge_all_blocks_after_post_update($post_ID, $post_after, $post_before) {
        if (wp_is_post_revision($post_ID)) return;
        if (!$post_after || !$post_before) return;
        if ($post_before->post_status !== 'publish' && $post_after->post_status !== 'publish') return;
        if (!($this->settings['enable_block_caching'] ?? false)) return;
        $enable = apply_filters('ace_rc_purge_all_block_cache_on_post_update', true, $post_ID, $post_after, $post_before, $this->settings);
        if (!$enable) return;
        $all_keys = $this->cache_manager->scan_keys('block_cache:*');
        if (!empty($all_keys)) {
            $this->cache_manager->delete_keys_chunked($all_keys, 1000);
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(sprintf('Ace-Redis-Cache: FULL block cache purge after post update id=%d deleted=%d', $post_ID, count($all_keys)));
            }
        } else if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf('Ace-Redis-Cache: FULL block cache purge after post update id=%d no_keys_found', $post_ID));
        }
    }
    
    /**
     * Get cache TTL for specific block type
     *
     * @param string $block_name Block name
     * @return int TTL in seconds
     */
    private function get_block_cache_ttl($block_name) {
    // Default TTL from settings (object-level TTL preferred)
    $default_ttl = isset($this->settings['ttl_object']) ? (int)$this->settings['ttl_object'] : (int)($this->settings['ttl'] ?? 3600);
        
        // Specific TTLs for different block types
        $block_ttls = [
            // Static content - longer cache
            'core/paragraph' => $default_ttl * 24, // 24 hours
            'core/heading' => $default_ttl * 24,
            'core/list' => $default_ttl * 24,
            'core/image' => $default_ttl * 24,
            'core/gallery' => $default_ttl * 12,
            
            // Semi-dynamic content - medium cache
            'core/latest-posts' => $default_ttl * 2, // 2 hours
            'core/categories' => $default_ttl * 4,
            'core/archives' => $default_ttl * 4,
            
            // Dynamic content - short cache
            'core/calendar' => $default_ttl / 2, // 30 minutes
            'core/latest-comments' => $default_ttl / 4, // 15 minutes
        ];
        
        return $block_ttls[$block_name] ?? $default_ttl;
    }
    
    /**
     * Modify block cache support
     *
     * @param bool $supports Whether the feature is supported
     * @param string $feature Feature name
     * @param \WP_Block_Type $block_type Block type object
     * @return bool Modified support value
     */
    public function modify_block_cache_support($supports, $feature, $block_type) {
        if ($feature !== 'cache') {
            return $supports;
        }
        
        // Enable caching for blocks that don't explicitly disable it
        if (!isset($block_type->supports['cache'])) {
            return !$this->should_exclude_block($block_type->name);
        }
        
        return $supports;
    }
    
    /**
     * Clear all block cache
     *
     * @return array Result information
     */
    public function clear_all_block_cache() {
        return $this->cache_manager->clear_block_cache();
    }
    
    /**
     * Get block cache statistics
     *
     * @return array Block cache statistics
     */
    public function get_cache_stats() {
        $block_keys = $this->cache_manager->scan_keys('block_cache:*');
        $total_blocks = count($block_keys);
        
        // Count by block type
        $block_types = [];
        foreach ($block_keys as $key) {
            $parts = explode(':', $key);
            if (isset($parts[1])) {
                $block_type = $parts[1];
                $block_types[$block_type] = ($block_types[$block_type] ?? 0) + 1;
            }
        }
        
        return [
            'total_cached_blocks' => $total_blocks,
            'block_types' => $block_types,
            'excluded_patterns' => count($this->get_excluded_blocks())
        ];
    }
}
