<?php
namespace SesMailer\Logging;
if ( ! defined('ABSPATH') ) { exit; }

use SesMailer\Support\Options;

class LogViewer {
    private static $log_dir;
    private static $log_file;
    private static $max_bytes = 2097152; // 2 MiB

    public static function init() {
        $base = self::resolve_log_base();
        if ( ! file_exists($base) ) { wp_mkdir_p($base); }
        self::$log_dir  = trailingslashit($base);
        self::$log_file = self::$log_dir . 'email-log.txt';

        $index = self::$log_dir . 'index.html';
        if ( ! file_exists($index) ) @file_put_contents($index, "<!-- silence is golden -->");

        $uploads = wp_get_upload_dir();
        $uploads_base = isset($uploads['basedir']) ? trailingslashit($uploads['basedir']) : '';
        if ( $uploads_base !== '' && strpos(self::$log_dir, $uploads_base) === 0 ) {
            $ht = self::$log_dir . '.htaccess';
            if ( ! file_exists($ht) ) @file_put_contents($ht, "Require all denied\n");
        }
    }

    private static function resolve_log_base() {
        if ( defined('SES_MAILER_LOG_DIR') ) {
            return rtrim(SES_MAILER_LOG_DIR, "/\\ ");
        }
        $uploads = wp_get_upload_dir();
        if ( isset($uploads['basedir']) && $uploads['basedir'] ) {
            return trailingslashit($uploads['basedir']) . 'ses-mailer-logs';
        }
        // Fallback to content dir to avoid failures if uploads are unavailable
        if ( defined('WP_CONTENT_DIR') ) {
            return trailingslashit(WP_CONTENT_DIR) . 'ses-mailer-logs';
        }
        return trailingslashit(ABSPATH) . 'wp-content/ses-mailer-logs';
    }

    public static function log($message) {
        // Respect Disable Logging option
        $opts = get_option(Options::OPTION, Options::defaults());
        if ( isset($opts['disable_logging']) && $opts['disable_logging'] === '1' ) return;
        if ( ! self::$log_file ) self::init();
        $line = sprintf("[%s] %s\n", gmdate('Y-m-d H:i:s'), $message);
        @file_put_contents(self::$log_file, $line, FILE_APPEND | LOCK_EX);
        self::maybe_trim();
    }

    private static function maybe_trim() {
        clearstatcache(true, self::$log_file);
        $size = @filesize(self::$log_file);
        if ( $size === false || $size <= self::$max_bytes ) return;
        $data = @file_get_contents(self::$log_file);
        if ( $data === false ) return;
        $keep = substr($data, -1048576);
        @file_put_contents(self::$log_file, $keep, LOCK_EX);
    }

    public static function render() {
        if ( ! self::$log_file ) self::init();
        $opts = get_option(Options::OPTION, Options::defaults());
        echo '<h3>' . esc_html__('Email Logs', 'api-mailer-for-aws-ses') . '</h3>';
        if ( isset($opts['disable_logging']) && $opts['disable_logging'] === '1' ) {
            echo '<div class="notice notice-warning"><p>' . esc_html__('Logging is disabled by settings. No new entries will be recorded.', 'api-mailer-for-aws-ses') . '</p></div>';
        }
        if ( isset($_POST['ses_clear_logs']) && check_admin_referer('ses_clear_logs_action') ) {
            if ( function_exists('wp_delete_file') ) {
                wp_delete_file(self::$log_file);
            }
            echo '<div class="notice notice-success"><p>' . esc_html__('Logs cleared.', 'api-mailer-for-aws-ses') . '</p></div>';
        }
        if ( file_exists(self::$log_file) ) {
            $content = @file_get_contents(self::$log_file);
            if ( $content === false || $content === '' ) {
                echo '<p>' . esc_html__('No log entries yet.', 'api-mailer-for-aws-ses') . '</p>';
            } else {
                echo '<pre style="background:#fff;border:1px solid #ccc;padding:10px;max-height:400px;overflow:auto;">' . esc_html($content) . '</pre>';
            }
        } else {
            echo '<p>' . esc_html__('Log file not found.', 'api-mailer-for-aws-ses') . '</p>';
        }
        echo '<form method="post">';
        wp_nonce_field('ses_clear_logs_action');
        submit_button(__('Clear Logs', 'api-mailer-for-aws-ses'), 'delete', 'ses_clear_logs', false);
        echo '</form>';
    }
}
