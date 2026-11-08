<?php
/**
 * Page cache for signed-in visitors on the front end.
 *
 * A signed-in render differs from a guest one in a few known places, and each is handled so one
 * stored copy can be served to everyone in the same group:
 *
 * - The toolbar (#wpadminbar) carries the user's name, avatar, profile and a log-out nonce. It is
 *   cut out of the stored copy at the wp_before/after_admin_bar_render hooks and rendered afresh
 *   for whoever is served the copy.
 * - Nonces (the REST nonce in wpApiSettings, wp_nonce_field() inputs, log-out links) are tied to
 *   the user and their session. Every action a nonce was made for during the render is recorded
 *   through the nonce_life filter; the stored copy holds a token instead of each value, and a hit
 *   puts back the viewer's own nonce for that action.
 * - What a user may do (edit links, role-only blocks) is the same for everyone in a group, so the
 *   copy is keyed by group: admins, editors (can edit everyone's posts) and readers (can edit
 *   nothing). Authors and contributors see edit links for their own posts only, so they are never
 *   served a stored copy.
 * - Anything else that names the user (their email, a comment form's "Logged in as") stops the
 *   copy being stored at all; sites add their own markers with ace_rc_logged_in_store_block.
 *
 * Guests and signed-in visitors never share a key: the guest key is unchanged and a signed-in copy
 * carries a trailing variant part ("li-editor-ab").
 *
 * @package AceMedia\RedisCache
 */

namespace AceMedia\RedisCache;

if (!defined('ABSPATH')) {
    exit;
}

class LoggedInPageCache {
    const BAR_START = '<!--ACE_RC_ADMINBAR_START-->';
    const BAR_END = '<!--ACE_RC_ADMINBAR_END-->';
    // Stored tokens are plain text, not comments: the minifier strips comments from stored copies.
    const BAR_SLOT = '__ACE_RC_ADMINBAR__';
    const NONCE_MAP = 'ACE_RC_NONCES:';
    const NONCE_TOKEN = '__ACE_RC_NONCE_%d__';

    /** Every group a copy can be stored under, so a purge can name each key. */
    const GROUPS = ['admin', 'editor', 'reader', 'customer'];

    /** @var string[] Nonce actions seen during this render. */
    private $actions = [];

    /**
     * The group this user's copy is stored under, or null when they must not be served one.
     *
     * @param array    $settings Plugin settings.
     * @param callable $can      Capability check, current_user_can() in WordPress.
     * @param bool     $is_customer The user is a WooCommerce customer and WooCommerce is active.
     */
    public static function group(array $settings, callable $can, $is_customer = false) {
        if ($can('manage_options')) {
            $group = 'admin';
        } elseif ($can('edit_others_posts') && $can('edit_others_pages')) {
            $group = 'editor';
        } elseif (!$can('edit_posts')) {
            $group = $is_customer ? 'customer' : 'reader';
        } else {
            return null; // authors and contributors: edit links differ per user
        }
        $enabled = [
            'admin' => !empty($settings['page_cache_li_admins']),
            'editor' => !empty($settings['page_cache_li_editors']),
            'reader' => !empty($settings['page_cache_li_readers']),
            'customer' => !empty($settings['wc_page_cache_customers']),
        ];
        return $enabled[$group] ? $group : null;
    }

    /** The trailing key part for a group, with or without the toolbar. */
    public static function variant($group, $bar_showing) {
        return 'li-' . $group . ($bar_showing ? '-ab' : '');
    }

    /** Every variant part a purge must delete. */
    public static function all_variants() {
        $variants = [];
        foreach (self::GROUPS as $group) {
            $variants[] = self::variant($group, true);
            $variants[] = self::variant($group, false);
        }
        return $variants;
    }

    /** Hooked to nonce_life (which WordPress passes the action) to record each nonce made. */
    public function record_nonce_action($life, $action = -1) {
        if (is_scalar($action) && (string) $action !== '-1') {
            $this->actions[(string) $action] = true;
        }
        return $life;
    }

    public function actions() {
        return array_keys($this->actions);
    }

    public function bar_start() {
        echo self::BAR_START; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed marker.
    }

    public function bar_end() {
        echo self::BAR_END; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed marker.
    }

    /** The page as the user is sent it: the toolbar markers removed, nothing else touched. */
    public static function strip_markers($html) {
        return str_replace([self::BAR_START, self::BAR_END], '', (string) $html);
    }

    /**
     * The page as it is stored: the toolbar replaced by a slot and each nonce by a token, with the
     * token-to-action map in a leading comment.
     *
     * @param string   $html    Rendered page.
     * @param string[] $actions Nonce actions recorded during the render.
     * @param callable $nonce   Makes this user's nonce for an action, wp_create_nonce() in WordPress.
     */
    public static function to_stored($html, array $actions, callable $nonce) {
        $html = (string) preg_replace('/' . preg_quote(self::BAR_START, '/') . '.*?' . preg_quote(self::BAR_END, '/') . '/s', self::BAR_SLOT, (string) $html);
        $map = [];
        foreach (array_values(array_unique($actions)) as $i => $action) {
            $value = (string) $nonce($action);
            if ($value === '' || strpos($html, $value) === false) {
                continue;
            }
            $token = sprintf(self::NONCE_TOKEN, $i);
            $html = str_replace($value, $token, $html);
            $map[$token] = $action;
        }
        if ($map) {
            $html = self::NONCE_MAP . base64_encode((string) json_encode($map)) . '|' . $html; // '|' is never in base64, and survives minifying
        }
        return $html;
    }

    /**
     * The stored copy for the viewer: their own nonces and their own toolbar put back.
     *
     * @param string   $stored Stored copy.
     * @param callable $nonce  Makes the viewer's nonce for an action.
     * @param string   $bar    The viewer's toolbar markup ('' when it is hidden).
     */
    public static function from_stored($stored, callable $nonce, $bar) {
        $html = (string) $stored;
        if (strpos($html, self::NONCE_MAP) === 0) {
            $end = strpos($html, '|');
            if ($end === false) {
                return str_replace(self::BAR_SLOT, (string) $bar, $html);
            }
            $map = json_decode((string) base64_decode(substr($html, strlen(self::NONCE_MAP), $end - strlen(self::NONCE_MAP))), true);
            $html = (string) substr($html, $end + 1);
            if (is_array($map)) {
                $replace = [];
                foreach ($map as $token => $action) {
                    $replace[(string) $token] = (string) $nonce($action);
                }
                $html = strtr($html, $replace);
            }
        }
        return str_replace(self::BAR_SLOT, (string) $bar, $html);
    }

    /**
     * Why this copy must not be stored, or null: anything left in it that names the user.
     *
     * @param string $stored The copy as it would be stored (toolbar already cut out).
     * @param object $user   WP_User-like object with user_email.
     */
    public static function identity_block_reason($stored, $user) {
        $stored = (string) $stored;
        if (strpos($stored, self::BAR_START) !== false || strpos($stored, 'id="wpadminbar"') !== false) {
            return 'li_toolbar_left';
        }
        $email = is_object($user) ? (string) ($user->user_email ?? '') : '';
        if ($email !== '' && stripos($stored, $email) !== false) {
            return 'li_user_email';
        }
        if (preg_match('/class=["\'][^"\']*\blogged-in-as\b/', $stored)) {
            return 'li_comment_form';
        }
        return null;
    }
}
