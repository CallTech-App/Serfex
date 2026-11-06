<?php
/**
 * Plugin Name: Serfex staging copy
 * Description: This site is a staging copy made in the Serfex panel. While its address is a staging address, e-mail is not sent and/or scheduled tasks (WP-Cron) do not run, so the copy cannot reach the live site's customers. Serfex writes this file, changes it from the panel and removes it when the copy is published; on any other address it does nothing.
 * Version: 1
 * License: AGPL-3.0-only
 */

defined('ABSPATH') || exit;

(static function () {
    $hosts = /*HOSTS*/[];
    $blockMail = /*MAIL*/false;
    $blockCron = /*CRON*/false;

    // Only on the staging address: a copy of this file that reached the
    // live site some other way stays idle there.
    $home = strtolower((string) parse_url((string) get_option('home'), PHP_URL_HOST));
    if (! in_array($home, $hosts, true)) {
        return;
    }
    $turkish = static function () {
        return strpos(function_exists('determine_locale') ? determine_locale() : get_locale(), 'tr') === 0;
    };

    if ($blockMail) {
        // wp_mail() stops here and reports success, so forms and orders
        // carry on as on the live site.
        add_filter('pre_wp_mail', static function ($return, $atts) {
            $to = is_array($atts['to'] ?? null) ? implode(', ', $atts['to']) : (string) ($atts['to'] ?? '');
            error_log('Serfex staging copy: e-mail not sent to '.$to.': '.(string) ($atts['subject'] ?? ''));

            return true;
        }, PHP_INT_MAX, 2);
        // A plugin that replaces wp_mail() still goes through PHPMailer:
        // with no recipients nothing leaves.
        add_action('phpmailer_init', static function ($mailer) {
            $mailer->clearAllRecipients();
        }, PHP_INT_MAX);
    }

    if ($blockCron) {
        // No scheduled event runs: from wp-cron.php, from a page view or
        // from WP-CLI.
        add_filter('pre_get_ready_cron_jobs', static function () {
            return [];
        }, PHP_INT_MAX);
    }

    add_action('admin_notices', static function () use ($blockMail, $blockCron, $turkish) {
        if (! current_user_can('manage_options')) {
            return;
        }
        $tr = $turkish();
        $what = [];
        if ($blockMail) {
            $what[] = $tr ? 'e-posta gönderilmez' : 'e-mail is not sent';
        }
        if ($blockCron) {
            $what[] = $tr ? 'zamanlanmış görevler (WP-Cron) çalışmaz' : 'scheduled tasks (WP-Cron) do not run';
        }
        $text = ($tr ? 'Bu bir hazırlık kopyası: ' : 'This is a staging copy: ').implode($tr ? ' ve ' : ' and ', $what)
            .($tr ? '. Serfex panelinden değiştirebilirsiniz.' : '. You can change this in the Serfex panel.');
        echo '<div class="notice notice-warning"><p>'.esc_html($text).'</p></div>';
    });
})();
