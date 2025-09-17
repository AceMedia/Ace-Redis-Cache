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
        
        // Only setup output buffering if not in full page cache mode
        // (full page cache will handle minification directly)
        if ($this->settings['mode'] !== 'full') {
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
        
        // Don't minify XML feeds
        if (is_feed()) {
            return false;
        }
        
        // Don't minify if user is logged in and in customize mode
        if (is_customize_preview()) {
            return false;
        }
        
        // Don't minify non-HTML content types
        if (function_exists('http_response_code') && http_response_code() !== 200) {
            return false;
        }
        
        // Only minify for guest users (logged out users)
        // This ensures minification only applies to cached public content
        if (is_user_logged_in()) {
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
        
        // Check if this looks like HTML content (should start with <!DOCTYPE or <html)
        $trimmed_html = ltrim($html);
        if (!preg_match('/^<!DOCTYPE\s+html|^<html/i', $trimmed_html)) {
            // Not HTML content, skip minification
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
     * Minify HTML content - Remove all blank lines
     *
     * @param string $html HTML content
     * @return string Minified HTML
     */
    public function minify_html($html) {
        // Preserve important content completely - don't touch script, style, pre, code, textarea
        $preserve_tags = [];
        $preserve_patterns = [
            '/<(pre|code|script|style|textarea)[^>]*>.*?<\/\1>/is',
            '/<!--\[if\s.*?\[endif\]-->/s' // IE conditional comments
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
        
        // Remove regular HTML comments (but keep IE conditionals)
        $html = preg_replace('/<!--(?!\s*(?:\[if|\[endif)).*?-->/s', '', $html);
        
        // Remove ALL blank lines (lines with only whitespace)
        $html = preg_replace('/^\s*$/m', '', $html);
        
        // Remove all consecutive newlines (collapse multiple newlines into none)
        $html = preg_replace('/\n+/', '', $html);
        
        // Convert multiple spaces to single spaces
        $html = preg_replace('/ {2,}/', ' ', $html);
        
        // Restore preserved content
        foreach ($preserve_tags as $placeholder => $original) {
            $html = str_replace($placeholder, $original, $html);
        }
        
        return trim($html);
    }
    
    /**
     * Minify inline CSS - Light minification only
     *
     * @param string $html HTML content with CSS
     * @return string HTML with minified CSS
     */
    public function minify_inline_css($html) {
        return preg_replace_callback('/<style[^>]*>(.*?)<\/style>/is', function($matches) {
            $css = $matches[1];
            
            // Remove CSS comments
            $css = preg_replace('/\/\*.*?\*\//s', '', $css);
            
            // Remove empty lines
            $css = preg_replace('/\n\s*\n/', "\n", $css);
            
            // Remove leading and trailing whitespace from each line
            $css = preg_replace('/^\s+|\s+$/m', '', $css);
            
            // Remove newlines
            $css = preg_replace('/\n+/', '', $css);
            
            // Trim final result
            $css = trim($css);
            
            // Reconstruct the style tag
            $opening_tag = substr($matches[0], 0, strpos($matches[0], '>') + 1);
            return $opening_tag . $css . '</style>';
        }, $html);
    }
    
    /**
     * Minify inline JavaScript - Minimal safe minification
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
            
            // Skip if it's JSON data
            if (strpos($matches[0], 'application/json') !== false) {
                return $matches[0];
            }
            
            // Only do the safest possible minification: remove completely blank lines
            // Don't touch anything else to avoid breaking JS syntax
            
            // Remove lines that are completely empty (only whitespace)
            $js = preg_replace('/^\s*$/m', '', $js);
            
            // Remove consecutive newlines (multiple blank lines become single)
            $js = preg_replace('/\n{3,}/', "\n\n", $js);
            
            // Trim the start and end
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
