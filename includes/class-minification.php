<?php
/**
 * Minification Class
 * 
 * Handles HTML, CSS, and JavaScript minification for cached content
 * with intelligent parsing and performance optimization.
 *
 * @package AceMedia\RedisCache
 * @since 0.5.0
 */

namespace AceMedia\RedisCache;

if (!defined('ABSPATH')) exit;

class Minification {
    
    private $settings;
    
    /**
     * Constructor
     *
     * @param array $settings Plugin settings
     */
    public function __construct($settings) {
        $this->settings = $settings;
    }
    
    /**
     * Setup minification hooks
     */
    public function setup_hooks() {
        if (!($this->settings['enable_minification'] ?? false)) {
            return;
        }
        
        // Hook into output buffering for full page cache mode
        if ($this->settings['mode'] === 'full') {
            add_action('template_redirect', [$this, 'start_output_buffering'], 1);
        }
    }
    
    /**
     * Start output buffering for minification
     */
    public function start_output_buffering() {
        if (!$this->should_minify_page()) {
            return;
        }
        
        ob_start([$this, 'minify_output']);
    }
    
    /**
     * Check if current page should be minified
     *
     * @return bool True if page should be minified
     */
    private function should_minify_page() {
        // Don't minify admin pages
        if (is_admin()) {
            return false;
        }
        
        // Don't minify AJAX requests
        if (wp_doing_ajax()) {
            return false;
        }
        
        // Don't minify REST API requests
        if (defined('REST_REQUEST') && REST_REQUEST) {
            return false;
        }
        
        // Don't minify if user is logged in and in customize mode
        if (is_customize_preview()) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Minify output buffer content
     *
     * @param string $html HTML content to minify
     * @return string Minified HTML content
     */
    public function minify_output($html) {
        if (empty($html) || !is_string($html)) {
            return $html;
        }
        
        // Check if content should be excluded from minification
        if ($this->should_exclude_from_minification($html)) {
            return $html;
        }
        
        try {
            // Minify HTML structure
            $html = $this->minify_html($html);
            
            // Minify inline CSS
            $html = $this->minify_inline_css($html);
            
            // Minify inline JavaScript
            $html = $this->minify_inline_js($html);
            
            return $html;
            
        } catch (\Exception $e) {
            // If minification fails, return original content
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('Ace Redis Cache Minification Error: ' . $e->getMessage());
            }
            return $html;
        }
    }
    
    /**
     * Check if content should be excluded from minification
     *
     * @param string $html HTML content
     * @return bool True if should be excluded
     */
    private function should_exclude_from_minification($html) {
        $exclusions = $this->settings['custom_content_exclusions'] ?? '';
        
        if (empty($exclusions)) {
            return false;
        }
        
        $patterns = explode("\n", $exclusions);
        foreach ($patterns as $pattern) {
            $pattern = trim($pattern);
            if (!empty($pattern) && !str_starts_with($pattern, '#')) {
                if (strpos($html, $pattern) !== false) {
                    return true;
                }
            }
        }
        
        return false;
    }
    
    /**
     * Minify HTML content
     *
     * @param string $html HTML content
     * @return string Minified HTML
     */
    public function minify_html($html) {
        // Preserve important whitespace in pre, code, script, style, and textarea tags
        $preserve_tags = [];
        $preserve_patterns = [
            '/<(pre|code|script|style|textarea)[^>]*>.*?<\/\1>/is',
            '/<!--.*?-->/s'
        ];
        
        $placeholder_index = 0;
        foreach ($preserve_patterns as $pattern) {
            $html = preg_replace_callback($pattern, function($matches) use (&$preserve_tags, &$placeholder_index) {
                $placeholder = "___PRESERVE_{$placeholder_index}___";
                $preserve_tags[$placeholder] = $matches[0];
                $placeholder_index++;
                return $placeholder;
            }, $html);
        }
        
        // Remove HTML comments (except IE conditionals)
        $html = preg_replace('/<!--(?!\s*(?:\[if [^\]]+]|<!|>))(?:(?!-->).)*-->/s', '', $html);
        
        // Remove unnecessary whitespace
        $html = preg_replace('/\s+/', ' ', $html);
        
        // Remove whitespace around block elements
        $block_elements = 'div|p|h[1-6]|ul|ol|li|table|tr|td|th|thead|tbody|tfoot|section|article|header|footer|nav|aside|main';
        $html = preg_replace('/\s*(<\/?(?:' . $block_elements . ')[^>]*>)\s*/', '$1', $html);
        
        // Remove whitespace before closing tags
        $html = preg_replace('/\s+(<\/[^>]+>)/', '$1', $html);
        
        // Remove whitespace after opening tags
        $html = preg_replace('/(<[^\/][^>]*>)\s+/', '$1', $html);
        
        // Remove empty lines
        $html = preg_replace('/\n\s*\n/', "\n", $html);
        
        // Restore preserved content
        foreach ($preserve_tags as $placeholder => $original) {
            $html = str_replace($placeholder, $original, $html);
        }
        
        return trim($html);
    }
    
    /**
     * Minify inline CSS
     *
     * @param string $html HTML content with CSS
     * @return string HTML with minified CSS
     */
    public function minify_inline_css($html) {
        return preg_replace_callback('/<style[^>]*>(.*?)<\/style>/is', function($matches) {
            $css = $matches[1];
            
            // Remove comments
            $css = preg_replace('/\/\*.*?\*\//s', '', $css);
            
            // Remove unnecessary whitespace
            $css = preg_replace('/\s+/', ' ', $css);
            
            // Remove whitespace around specific characters
            $css = preg_replace('/\s*([\{\}:;,>+~])\s*/', '$1', $css);
            
            // Remove trailing semicolons before closing braces
            $css = preg_replace('/;(\s*})/', '$1', $css);
            
            // Remove unnecessary quotes from URLs
            $css = preg_replace('/url\((["\'])([^"\']*)\1\)/', 'url($2)', $css);
            
            $css = trim($css);
            
            // Reconstruct the style tag
            $opening_tag = substr($matches[0], 0, strpos($matches[0], '>') + 1);
            return $opening_tag . $css . '</style>';
        }, $html);
    }
    
    /**
     * Minify inline JavaScript
     *
     * @param string $html HTML content with JavaScript
     * @return string HTML with minified JavaScript
     */
    public function minify_inline_js($html) {
        return preg_replace_callback('/<script[^>]*>(.*?)<\/script>/is', function($matches) {
            $js = $matches[1];
            
            // Skip if it's not inline JavaScript (has src attribute)
            if (strpos($matches[0], 'src=') !== false) {
                return $matches[0];
            }
            
            // Simple JS minification (be careful not to break functionality)
            
            // Remove single-line comments (but preserve URLs and regex)
            $js = preg_replace('/(?<!["\'])\/\/[^\r\n]*/', '', $js);
            
            // Remove multi-line comments
            $js = preg_replace('/\/\*.*?\*\//s', '', $js);
            
            // Remove unnecessary whitespace
            $js = preg_replace('/\s+/', ' ', $js);
            
            // Remove whitespace around operators and punctuation
            $js = preg_replace('/\s*([\{\}:;,=\(\)\[\]><!&\|+\-\*\/])\s*/', '$1', $js);
            
            // Remove trailing semicolons before closing braces
            $js = preg_replace('/;(\s*})/', '$1', $js);
            
            $js = trim($js);
            
            // Reconstruct the script tag
            $opening_tag = substr($matches[0], 0, strpos($matches[0], '>') + 1);
            return $opening_tag . $js . '</script>';
        }, $html);
    }
    
    /**
     * Minify CSS content (for external use)
     *
     * @param string $css CSS content
     * @return string Minified CSS
     */
    public function minify_css($css) {
        // Remove comments
        $css = preg_replace('/\/\*.*?\*\//s', '', $css);
        
        // Remove unnecessary whitespace
        $css = preg_replace('/\s+/', ' ', $css);
        
        // Remove whitespace around specific characters
        $css = preg_replace('/\s*([\{\}:;,>+~])\s*/', '$1', $css);
        
        // Remove trailing semicolons before closing braces
        $css = preg_replace('/;(\s*})/', '$1', $css);
        
        // Convert hex colors to shorter format where possible
        $css = preg_replace('/#([0-9a-f])\1([0-9a-f])\2([0-9a-f])\3/i', '#$1$2$3', $css);
        
        // Remove unnecessary quotes from URLs
        $css = preg_replace('/url\((["\'])([^"\']*)\1\)/', 'url($2)', $css);
        
        // Remove empty rules
        $css = preg_replace('/[^}]+\{\s*\}/', '', $css);
        
        return trim($css);
    }
    
    /**
     * Minify JavaScript content (for external use)
     *
     * @param string $js JavaScript content
     * @return string Minified JavaScript
     */
    public function minify_js($js) {
        // This is a basic minification - for production use, consider a proper JS minifier
        
        // Remove single-line comments (be careful with URLs and regex)
        $js = preg_replace('/(?<!["\'])\/\/[^\r\n]*/', '', $js);
        
        // Remove multi-line comments
        $js = preg_replace('/\/\*.*?\*\//s', '', $js);
        
        // Remove unnecessary whitespace
        $js = preg_replace('/\s+/', ' ', $js);
        
        // Remove whitespace around operators and punctuation
        $js = preg_replace('/\s*([\{\}:;,=\(\)\[\]><!&\|+\-\*\/])\s*/', '$1', $js);
        
        // Remove trailing semicolons before closing braces
        $js = preg_replace('/;(\s*})/', '$1', $js);
        
        return trim($js);
    }
    
    /**
     * Get minification statistics
     *
     * @param string $original Original content
     * @param string $minified Minified content
     * @return array Minification statistics
     */
    public function get_minification_stats($original, $minified) {
        $original_size = strlen($original);
        $minified_size = strlen($minified);
        $saved_bytes = $original_size - $minified_size;
        $savings_percent = $original_size > 0 ? round(($saved_bytes / $original_size) * 100, 2) : 0;
        
        return [
            'original_size' => $original_size,
            'minified_size' => $minified_size,
            'bytes_saved' => $saved_bytes,
            'savings_percent' => $savings_percent
        ];
    }
    
    /**
     * Check if minification is enabled
     *
     * @return bool True if minification is enabled
     */
    public function is_enabled() {
        return !empty($this->settings['enable_minification']);
    }
}
