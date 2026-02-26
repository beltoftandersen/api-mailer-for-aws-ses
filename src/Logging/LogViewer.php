<?php
namespace SesMailer\Logging;
if ( ! defined('ABSPATH') ) { exit; }

use SesMailer\Support\Options;

class LogViewer {

    public static function init() {
        // No file setup needed — logging delegates to WC_Logger or error_log().
    }

    public static function log($message) {
        $opts = get_option(Options::OPTION, Options::defaults());
        if ( ! empty($opts['disable_logging']) ) return;

        $message = (string) $message;

        if ( function_exists('wc_get_logger') ) {
            $logger = wc_get_logger();
            $context = array('source' => 'api-mailer-for-aws-ses');
            if ( stripos($message, 'FAIL') === 0 || stripos($message, 'FATAL') === 0 ) {
                $logger->error($message, $context);
            } else {
                $logger->info($message, $context);
            }
            return;
        }

        // Fallback: PHP error log
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Fallback logger when WC_Logger is unavailable.
        error_log('[ses-mailer] ' . $message);
    }

    public static function render() {
        $opts = get_option(Options::OPTION, Options::defaults());
        echo '<h3>' . esc_html__('Email Logs', 'api-mailer-for-aws-ses') . '</h3>';

        if ( ! empty($opts['disable_logging']) ) {
            echo '<div class="notice notice-info"><p>' . esc_html__('Logging is disabled. Enable it in Settings to record email activity.', 'api-mailer-for-aws-ses') . '</p></div>';
            return;
        }

        if ( function_exists('wc_get_logger') ) {
            $logs_url = admin_url('admin.php?page=wc-status&tab=logs');
            echo '<p>' . sprintf(
                wp_kses(
                    /* translators: %s: URL to WooCommerce logs */
                    __('Logs are written to <a href="%s">WooCommerce &rarr; Status &rarr; Logs</a> under source <code>api-mailer-for-aws-ses</code>.', 'api-mailer-for-aws-ses'),
                    array('a' => array('href' => array()), 'code' => array())
                ),
                esc_url($logs_url)
            ) . '</p>';
        } else {
            echo '<p>' . esc_html__('WooCommerce is not active. Logs are written to the PHP error log (typically wp-content/debug.log).', 'api-mailer-for-aws-ses') . '</p>';
        }
    }
}
