<?php
/**
 * Plugin Name: Serfex sign-in
 * Description: Opens this site's dashboard from the Serfex panel with a one-time link. Serfex writes this file; changes are overwritten.
 * Version: 1
 * License: AGPL-3.0-only
 */

defined('ABSPATH') || exit;

add_action('init', static function () {
    if (! isset($_GET['serfex_login']) || ! is_string($_GET['serfex_login'])) {
        return;
    }
    nocache_headers();
    $turkish = strpos(function_exists('determine_locale') ? determine_locale() : get_locale(), 'tr') === 0;
    $refuse = static function () use ($turkish) {
        wp_die(
            $turkish
                ? 'Bu giriş bağlantısı kullanılmış ya da süresi dolmuş. WordPress\'i panelden yeniden açın.'
                : 'This sign-in link was already used or has expired. Open WordPress from the panel again.',
            $turkish ? 'Giriş bağlantısı geçersiz' : 'Sign-in link not valid',
            ['response' => 403]
        );
    };
    $saved = get_option('serfex_login');
    if (! is_array($saved) || empty($saved['hash']) || (int) ($saved['expires'] ?? 0) < time()) {
        delete_option('serfex_login');
        $refuse();
    }
    if (! hash_equals((string) $saved['hash'], hash('sha256', wp_unslash($_GET['serfex_login'])))) {
        $refuse();
    }
    // The link works once.
    delete_option('serfex_login');
    $user = get_user_by('id', (int) ($saved['user'] ?? 0));
    if (! $user || ! user_can($user, 'manage_options')) {
        $refuse();
    }
    wp_set_current_user($user->ID, $user->user_login);
    wp_set_auth_cookie($user->ID, false, is_ssl());
    do_action('wp_login', $user->user_login, $user);
    wp_safe_redirect(admin_url());
    exit;
}, 0);
