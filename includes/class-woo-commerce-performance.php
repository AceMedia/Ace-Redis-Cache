<?php
/**
 * WooCommerce-specific performance controls.
 *
 * @package AceMedia\RedisCache
 */

namespace AceMedia\RedisCache;

if (!defined('ABSPATH')) {
    exit;
}

class WooCommercePerformance {

    /** @var array */
    private $settings;

    /**
     * @param array $settings Plugin settings array.
     */
    public function __construct(array $settings) {
        $this->settings = $settings;
        $this->apply();
    }

    /**
     * Read a module setting with fallback.
     *
     * @param string $key
     * @param mixed  $default
     * @return mixed
     */
    private function setting(string $key, $default = false) {
        return $this->settings[$key] ?? $default;
    }

    /**
     * Register WooCommerce-specific performance patches.
     */
    private function apply(): void {
        if ($this->setting('wc_skip_cart_cookies', true)) {
            add_filter('woocommerce_set_cart_cookies', function ($set) {
                $uri = $_SERVER['REQUEST_URI'] ?? '';
                if (preg_match('#^/(product|product-category|shop|products)(/|$)#i', $uri)) {
                    return false;
                }
                return $set;
            }, 1);
        }

        if ($this->setting('wc_disable_persistent_cart', true)) {
            add_filter('woocommerce_persistent_cart_enabled', '__return_false');
        }

        $threshold = (int) $this->setting('wc_variation_threshold', 15);
        if ($threshold > 0 && $threshold !== 30) {
            add_filter('woocommerce_ajax_variation_threshold', function () use ($threshold) {
                return $threshold;
            });
        }

        $as_time = (int) $this->setting('wc_action_scheduler_time_limit', 15);
        if ($as_time > 0) {
            add_filter('action_scheduler_queue_runner_time_limit', function () use ($as_time) {
                return $as_time;
            });
        }

        $as_batch = (int) $this->setting('wc_action_scheduler_batch_size', 10);
        if ($as_batch > 0) {
            add_filter('action_scheduler_queue_runner_batch_size', function () use ($as_batch) {
                return $as_batch;
            });
        }

        if ($this->setting('wc_skip_children_on_archives', true)) {
            add_filter('woocommerce_get_children', function ($children, $product, $visible_only) {
                if (!did_action('wp')) {
                    return $children;
                }

                if (is_singular('product') || is_admin() || wp_doing_ajax()
                    || (defined('REST_REQUEST') && REST_REQUEST)) {
                    return $children;
                }

                return [];
            }, 20, 3);
        }

        if ($this->setting('wc_skip_composite_sync_on_archives', true)) {
            // WC_Product_Composite::is_on_sale() has a built-in fast path:
            //   if 'cart' === $this->get_object_context() → parent::is_on_sale() (reads _sale_price meta)
            //   else if contains('priced_individually') → sync() → loads ALL component products (SLOW)
            // By setting object_context = 'cart' on archive/listing pages, is_on_sale() skips
            // sync() entirely. This does NOT affect is_purchasable() or price calculation.
            // The cart class (class-wc-cp-cart.php) also calls set_object_context('cart') for real
            // cart products, so this is semantically consistent.
            add_action('woocommerce_product_read', function ($product_id, $product) {
                if (!class_exists('WC_Product_Composite') || !($product instanceof \WC_Product_Composite)) {
                    return;
                }

                // Allow full sync on single product pages, admin, ajax, cron, REST, and cart/checkout.
                if (is_singular('product') || is_cart() || is_checkout()
                    || is_admin() || wp_doing_ajax() || wp_doing_cron()
                    || (defined('REST_REQUEST') && REST_REQUEST)) {
                    return;
                }

                // Setting context to 'cart' causes is_on_sale() to use the fast parent path.
                // No reflection needed; set_object_context() is a public method.
                $product->set_object_context('cart');
            }, 10, 2);
        }

        if ($this->setting('wc_cache_url_exclusions', true)) {
            add_filter('ace_redis_cache_excluded_urls', function ($exclusions) {
                return array_merge((array) $exclusions, [
                    '/cart',
                    '/checkout',
                    '/my-account',
                    '?wc-ajax=',
                    '?add-to-cart=',
                ]);
            });
        }

        if ($this->setting('wc_gla_disable_notification_pill', true)) {
            add_filter('ace_perf_disable_gla_admin_notification_pill', '__return_true', 20);

            add_action('admin_init', function () {
                $class = 'Automattic\\WooCommerce\\GoogleListingsAndAds\\Menu\\NotificationManager';
                $this->remove_object_method_hook('admin_menu', $class, 'display_aggregated_notification_pill');
                $this->remove_object_method_hook('google_for_woocommerce_admin_menu_notification_count', $class, 'performance_max_ad_strength_count');
                $this->remove_object_method_hook('google_for_woocommerce_admin_menu_notification_count', $class, 'raise_budget_recommendations_count');
            }, 0);
        } else {
            add_filter('ace_perf_disable_gla_admin_notification_pill', '__return_false', 20);
        }

        if ($this->setting('wc_disable_blocks_animation_translate', true)) {
            add_filter('blocks-animation_sdk_enable_translate', '__return_false');
        }

        // Disable WooCommerce remote logging: it calls get_plugins() on every frontend
        // page load to check the WC version, scanning all plugin headers via preg_match.
        add_filter('pre_option_woocommerce_feature_remote_logging_enabled', function () {
            return 'no';
        });


        // Microsoft Clarity makes a live wp_remote_get() to api.wordpress.org on every
        // admin_init (including admin-ajax.php) to check for plugin updates. Remove it.
        add_action('plugins_loaded', function () {
            if (function_exists('check_if_installed_plugin_version_is_latest')) {
                remove_action('admin_init', 'check_if_installed_plugin_version_is_latest');
            }
        }, 20);

        add_filter('ace_redis_cache_transient_exclusions', function ($exclusions) {
            $exclusions[] = 'customtaxorder_get_settings';
            return $exclusions;
        }, 20);

        add_filter('customtaxorder_settings', function ($settings) {
            static $cached = null;
            if (null === $cached) {
                $cached = $settings;
            }
            return $cached;
        }, PHP_INT_MAX);
    }

    /**
     * Remove a specific object-method callback from a hook.
     *
     * @param string $hook_name
     * @param string $class
     * @param string $method
     * @return void
     */
    private function remove_object_method_hook(string $hook_name, string $class, string $method): void {
        if (function_exists('ace_perf_remove_object_method_hook')) {
            ace_perf_remove_object_method_hook($hook_name, $class, $method);
            return;
        }

        global $wp_filter;

        if (empty($wp_filter[$hook_name])) {
            return;
        }

        $hook = $wp_filter[$hook_name];
        if (!($hook instanceof \WP_Hook)) {
            return;
        }

        foreach ($hook->callbacks as $priority => $callbacks) {
            foreach ($callbacks as $callback) {
                $fn = $callback['function'] ?? null;
                if (!is_array($fn) || empty($fn[0]) || !is_object($fn[0])) {
                    continue;
                }

                if (get_class($fn[0]) !== $class || ($fn[1] ?? null) !== $method) {
                    continue;
                }

                remove_filter($hook_name, $fn, $priority);
            }
        }
    }
}
