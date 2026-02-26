<?php
namespace SesMailer\Background;
if ( ! defined('ABSPATH') ) { exit; }

use SesMailer\Support\Options;
use SesMailer\Logging\LogViewer;
use SesMailer\Api\SesClient;
use SesMailer\Mail\Mailer;
use WP_Error;

class Queue {
    const HOOK = 'ses_mailer_send_job';
    const JOB_OPTION_PREFIX = 'ses_mailer_job_';
    private static $opts_cache = null;

    public static function init() {
        add_action(self::HOOK, [__CLASS__, 'worker'], 10, 1);

        // Schedule daily cleanup for orphaned jobs
        add_action('ses_mailer_cleanup_jobs', [__CLASS__, 'cleanup_stale_jobs']);
        if ( ! wp_next_scheduled('ses_mailer_cleanup_jobs') ) {
            wp_schedule_event(time(), 'daily', 'ses_mailer_cleanup_jobs');
        }
    }

    public static function is_action_scheduler_available() {
        return function_exists('as_enqueue_async_action') || function_exists('as_schedule_single_action');
    }

    public static function enqueue($payload) {
        $payload = is_array($payload) ? $payload : array();
        $args = array(
            'to'          => isset($payload['to']) ? (array) $payload['to'] : array(),
            'subject'     => isset($payload['subject']) ? (string) $payload['subject'] : '',
            'message'     => isset($payload['message']) ? (string) $payload['message'] : '',
            'headers'     => isset($payload['headers']) ? (array) $payload['headers'] : array(),
            'attachments' => isset($payload['attachments']) ? (array) $payload['attachments'] : array(),
            'attempt'     => 0,
            'created_at'  => time(),
        );
        $job_id = self::store_job($args);
        if ( $job_id === false ) return false;
        if ( ! self::schedule($job_id, 0) ) {
            self::delete_job($job_id);
            return false;
        }
        return true;
    }

    /**
     * @return bool True if the job was scheduled, false on failure.
     */
    private static function schedule($job_id, $delay_seconds) {
        $when = time() + max(0, intval($delay_seconds));
        $args = array('job_id' => $job_id);
        if ( self::is_action_scheduler_available() ) {
            if ( $delay_seconds > 0 || ! function_exists('as_enqueue_async_action') ) {
                if ( function_exists('as_schedule_single_action') ) {
                    $result = as_schedule_single_action($when, self::HOOK, array($args), array('group' => 'api-mailer-for-aws-ses'));
                    return $result !== null && $result !== false && $result !== 0;
                } else {
                    return self::safe_schedule_cron($when, $args);
                }
            } else {
                $result = as_enqueue_async_action(self::HOOK, array($args), 'api-mailer-for-aws-ses');
                return $result !== null && $result !== false && $result !== 0;
            }
        } else {
            return self::safe_schedule_cron($when, $args);
        }
    }

    /**
     * @return bool True if the event was scheduled (or already exists), false on failure.
     */
    private static function safe_schedule_cron($when, $args) {
        $lock_key = 'ses_lock_' . md5(wp_json_encode($args));
        if ( false !== get_transient($lock_key) ) return true; // Already being scheduled
        set_transient($lock_key, 1, 5);
        $result = true;
        // Recheck after acquiring lock to prevent TOCTOU race
        if ( ! wp_next_scheduled(self::HOOK, array($args)) ) {
            $result = wp_schedule_single_event($when, self::HOOK, array($args));
            // wp_schedule_single_event returns true/false in WP 5.7+, void in older
            if ( $result === null ) $result = true;
        }
        delete_transient($lock_key);
        return (bool) $result;
    }

    public static function worker($args) {
        $opts = self::get_opts();
        if ( empty($opts['enable_mailer']) ) return;

        $loaded = null; $job_id = isset($args['job_id']) ? (string)$args['job_id'] : '';
        if ( $job_id !== '' ) {
            $loaded = self::load_job($job_id);
            if ( ! is_array($loaded) ) { return; }
        } else {
            // Legacy path: full payload passed directly
            if ( is_array($args) ) {
                $loaded = $args;
                $job_id = self::store_job($loaded);
                if ( ! is_string($job_id) || $job_id === '' ) {
                    self::maybe_log('ses_queue_persist_failed msg="Legacy worker could not persist job, aborting."');
                    return;
                }
            } else {
                return;
            }
        }

        $to = isset($loaded['to']) ? (array) $loaded['to'] : array();
        $to = array_filter(array_map('sanitize_email', $to));
        if ( empty($to) ) { if ( is_string($job_id) && $job_id !== '' ) self::delete_job($job_id); return; }

        $subject = isset($loaded['subject']) ? (string) $loaded['subject'] : '';
        $message = isset($loaded['message']) ? (string) $loaded['message'] : '';
        $headers = isset($loaded['headers']) ? (array) $loaded['headers'] : array();
        $attachments = isset($loaded['attachments']) ? (array) $loaded['attachments'] : array();
        $attempt = isset($loaded['attempt']) ? max(0, intval($loaded['attempt'])) : 0;

        // Extract tag for logging
        $tag = '';
        foreach ($headers as $h) {
            $line = trim(str_replace(array("\r","\n"), '', (string)$h));
            if ( stripos($line, 'x-ses-mailer-tag:') === 0 ) {
                $tag = trim(substr($line, strlen('x-ses-mailer-tag:')));
                $tag = preg_replace('/[^A-Za-z0-9._-]/', '', $tag);
                break;
            }
        }

        $from_email = isset($opts['from_email']) ? trim($opts['from_email']) : '';
        if ( ! is_email($from_email) ) $from_email = get_option('admin_email');
        $from_name  = isset($opts['from_name'])  ? trim($opts['from_name'])  : '';
        if ( $from_name === '' ) $from_name = get_bloginfo('name');
        if ( ! is_email($from_email) ) {
            self::maybe_log(sprintf('FAIL%s to=%s subject="%s" code=ses_from_invalid status= msg="Configured From Email is invalid or missing."',
                self::tag_str($tag), implode(', ', $to), mb_substr($subject, 0, 120)));
            if ( is_string($job_id) && $job_id !== '' ) { self::delete_job($job_id); }
            return;
        }

        $to_header = implode(', ', $to);

        try {
            $rate = isset($opts['rate_limit']) ? max(0, intval($opts['rate_limit'])) : 10;
            Mailer::throttle($rate);

            $mime = Mailer::build_mime($to, $subject, $message, $headers, $attachments, $from_email, $from_name);
            if ( is_wp_error($mime) ) {
                self::maybe_log(sprintf('FAIL%s to=%s subject="%s" code=%s msg="%s"',
                    self::tag_str($tag), $to_header, mb_substr($subject, 0, 120),
                    $mime->get_error_code(), mb_substr($mime->get_error_message(), 0, 200)));
                if ( is_string($job_id) && $job_id !== '' ) { self::delete_job($job_id); }
                return;
            }
            $send_size = strlen($mime);
            $result = (new SesClient())->send_raw_email($mime);
        } catch (\Throwable $e) {
            self::maybe_log(sprintf('FATAL%s to=%s subject="%s" attempt=%d msg="%s"',
                self::tag_str($tag), $to_header, mb_substr($subject, 0, 120), $attempt, mb_substr($e->getMessage(), 0, 200)));
            self::retry_or_discard($loaded, $job_id, $tag, $to_header, $subject, $attempt);
            return;
        }

        if ( $result === true ) {
            self::maybe_log(sprintf('SUCCESS%s to=%s subject="%s" bytes=%d attempt=%d', self::tag_str($tag), $to_header, mb_substr($subject, 0, 120), $send_size, $attempt));
            if ( is_string($job_id) && $job_id !== '' ) { self::delete_job($job_id); }
            return;
        }
        $err = is_wp_error($result) ? $result : new WP_Error('ses_unknown', 'SES send failed.');
        $code = $err->get_error_code();
        $msg  = $err->get_error_message();
        $data = $err->get_error_data();
        $status = is_array($data) && isset($data['status']) ? (string)$data['status'] : '';
        $hint = '';
        if ( ($code === 'ses_api_error' && (string)$status === '403') || $code === 'ses_creds_missing' || $code === 'ses_region_invalid' ) {
            $hint = ' hint=Check AWS Access Key/Secret and ensure the Region matches your SES setup.';
        }
        self::maybe_log(sprintf('FAIL%s to=%s subject="%s" code=%s status=%s attempt=%d msg="%s"%s', self::tag_str($tag), $to_header, mb_substr($subject, 0, 120), $code, $status, $attempt, mb_substr($msg, 0, 200), $hint));
        self::retry_or_discard($loaded, $job_id, $tag, $to_header, $subject, $attempt);
    }

    private static function retry_or_discard($loaded, $job_id, $tag, $to_header, $subject, $attempt) {
        $max_attempts = 3;
        if ( $attempt + 1 < $max_attempts ) {
            $next_attempt = $attempt + 1;
            $delay = 60 * (1 << ($attempt)); // 60, 120
            $loaded['attempt'] = $next_attempt;
            $stored_id = self::store_job($loaded, $job_id);
            if ( $stored_id === false ) {
                self::maybe_log(sprintf('ses_queue_persist_failed%s to=%s subject="%s" attempt=%d msg="Could not persist job for retry, discarding."',
                    self::tag_str($tag), $to_header, mb_substr($subject, 0, 120), $next_attempt));
                if ( is_string($job_id) && $job_id !== '' ) { self::delete_job($job_id); }
                return;
            }
            if ( ! self::schedule($stored_id, $delay) ) {
                self::maybe_log(sprintf('ses_queue_schedule_failed%s to=%s subject="%s" attempt=%d msg="Could not schedule retry, discarding."',
                    self::tag_str($tag), $to_header, mb_substr($subject, 0, 120), $next_attempt));
                if ( is_string($stored_id) && $stored_id !== '' ) { self::delete_job($stored_id); }
                return;
            }
            self::maybe_log(sprintf('RETRY%s to=%s subject="%s" next_attempt=%d in=%ds', self::tag_str($tag), $to_header, mb_substr($subject, 0, 120), $next_attempt, $delay));
        } else {
            if ( is_string($job_id) && $job_id !== '' ) { self::delete_job($job_id); }
        }
    }

    /**
     * @return string|false Job ID on success, false on DB write failure.
     */
    private static function store_job($payload, $job_id = '') {
        $id = $job_id !== '' ? $job_id : self::generate_job_id();
        $key = self::JOB_OPTION_PREFIX . $id;
        if ( false === get_option($key) ) {
            $ok = add_option($key, $payload, '', 'no');
        } else {
            $ok = update_option($key, $payload, false);
        }
        return $ok ? $id : false;
    }

    private static function load_job($job_id) {
        $key = self::JOB_OPTION_PREFIX . $job_id;
        $val = get_option($key, false);
        return $val !== false ? $val : null;
    }

    private static function delete_job($job_id) {
        $key = self::JOB_OPTION_PREFIX . $job_id;
        delete_option($key);
    }

    private static function generate_job_id() {
        if ( function_exists('wp_generate_uuid4') ) return wp_generate_uuid4();
        return bin2hex( random_bytes(16) );
    }

    public static function cleanup_stale_jobs() {
        global $wpdb;
        $prefix = self::JOB_OPTION_PREFIX;
        $stale_threshold = time() - DAY_IN_SECONDS;
        $batch_size = 500;
        $max_deletes = 2000;
        $deleted = 0;

        do {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_name ASC LIMIT %d",
                    $wpdb->esc_like($prefix) . '%',
                    $batch_size
                )
            );
            if ( ! is_array($rows) || empty($rows) ) break;

            $deleted_this_batch = 0;
            foreach ( $rows as $row ) {
                $payload = maybe_unserialize($row->option_value);
                if ( is_array($payload) && isset($payload['created_at']) && (int) $payload['created_at'] < $stale_threshold ) {
                    delete_option($row->option_name);
                    $deleted++;
                    $deleted_this_batch++;
                    if ( $deleted >= $max_deletes ) break 2;
                }
            }

            // If nothing was deleted this batch, all remaining rows are fresh — stop
            if ( $deleted_this_batch === 0 ) break;
        } while ( count($rows) === $batch_size );
    }

    private static function maybe_log($msg) {
        $opts = self::get_opts();
        if ( empty($opts['disable_logging']) ) {
            LogViewer::log($msg);
        }
    }

    private static function get_opts() {
        if ( self::$opts_cache === null ) {
            self::$opts_cache = get_option(Options::OPTION, Options::defaults());
        }
        return self::$opts_cache;
    }

    private static function tag_str($tag) {
        return ($tag !== '') ? ' tag=' . $tag : '';
    }

}
