<?php
/**
 * Plugin Name: API Mailer for AWS SES
 * Description: Fast, lightweight WordPress mailer that sends via Amazon SES SendRawEmail API (no SMTP). Includes background queue, logging, and wp-config credentials.
 * Version: 1.4.1
 * Author: beltoft.net
 * Author URI: https://beltoft.net
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: api-mailer-for-aws-ses
 * Domain Path: /languages
 */
if ( ! defined('ABSPATH') ) { exit; }

spl_autoload_register(function ($class) {
    if (strpos($class, 'SesMailer\\') !== 0) return;
    $rel = substr($class, strlen('SesMailer\\'));
    if ( preg_match('/[^A-Za-z0-9_\\\\]/', $rel) ) return;
    $rel = str_replace('\\', DIRECTORY_SEPARATOR, $rel) . '.php';
    $file = plugin_dir_path(__FILE__) . 'src/' . $rel;
    if ( file_exists($file) ) { require_once $file; }
});

define('SES_MAILER_PATH', plugin_dir_path(__FILE__));
define('SES_MAILER_URL',  plugin_dir_url(__FILE__));
define('SES_MAILER_VER',  '1.4');

register_activation_hook(__FILE__, function () {
    $option = \SesMailer\Support\Options::OPTION;
    if ( false === get_option($option) ) {
        add_option($option, \SesMailer\Support\Options::defaults(), '', 'no');
    }
});

add_action('plugins_loaded', function () {
    // Translations auto-load from WordPress.org since WP 4.6+
    SesMailer\Plugin::init();
});

register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook('ses_mailer_send_job');
    wp_clear_scheduled_hook('ses_mailer_cleanup_jobs');
});

// Add "Settings" link on the Plugins listing for this plugin only
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function ($links) {
    $url = admin_url('options-general.php?page=ses-mailer');
    $settings_link = '<a href="' . esc_url($url) . '">' . esc_html__('Settings', 'api-mailer-for-aws-ses') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
});
