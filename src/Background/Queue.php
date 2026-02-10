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

    public static function init() {
        add_action(self::HOOK, [__CLASS__, 'worker'], 10, 1);
    }

    public static function is_action_scheduler_available() {
        return function_exists('as_enqueue_async_action') || function_exists('as_schedule_single_action');
    }

    public static function enqueue($payload) {
        $payload = is_array($payload) ? $payload : array();
        // Minimal sanitize
        $args = array(
            'to'          => isset($payload['to']) ? (array) $payload['to'] : array(),
            'subject'     => isset($payload['subject']) ? (string) $payload['subject'] : '',
            'message'     => isset($payload['message']) ? (string) $payload['message'] : '',
            'headers'     => isset($payload['headers']) ? (array) $payload['headers'] : array(),
            'attachments' => isset($payload['attachments']) ? (array) $payload['attachments'] : array(),
            'attempt'     => 0,
        );
        // Always use job_id indirection to keep args tiny and consistent
        $job_id = self::store_job($args);
        self::schedule($job_id, 0);
    }

    private static function schedule($job_id, $delay_seconds) {
        $when = time() + max(0, intval($delay_seconds));
        $args = array('job_id' => $job_id);
        if ( self::is_action_scheduler_available() ) {
            if ( $delay_seconds > 0 || ! function_exists('as_enqueue_async_action') ) {
                if ( function_exists('as_schedule_single_action') ) {
                as_schedule_single_action($when, self::HOOK, array($args), array('group' => 'api-mailer-for-aws-ses'));
                } else {
                    if ( ! wp_next_scheduled(self::HOOK, array($args)) ) { wp_schedule_single_event($when, self::HOOK, array($args)); }
                }
            } else {
                as_enqueue_async_action(self::HOOK, array($args), 'api-mailer-for-aws-ses');
            }
        } else {
            if ( ! wp_next_scheduled(self::HOOK, array($args)) ) { wp_schedule_single_event($when, self::HOOK, array($args)); }
        }
    }

    public static function worker($args) {
        $opts = get_option(Options::OPTION, Options::defaults());
        if ( empty($opts['enable_mailer']) ) return; // Do nothing if mailer disabled

        // Expect job_id indirection; if not present, support legacy full-args jobs by converting them
        $loaded = null; $job_id = isset($args['job_id']) ? (string)$args['job_id'] : '';
        if ( $job_id !== '' ) {
            $loaded = self::load_job($job_id);
            if ( ! is_array($loaded) ) { return; }
        } else {
            // Legacy path: full payload passed directly (e.g., older scheduled AS job)
            if ( is_array($args) ) {
                $loaded = $args;
                // Migrate to job_id-based flow for retries
                $job_id = self::store_job($loaded);
            } else {
                return;
            }
        }

        $to = isset($loaded['to']) ? (array) $loaded['to'] : array();
        $to = array_filter(array_map('sanitize_email', $to));
        if ( empty($to) ) { if ( $job_id !== '' ) self::delete_job($job_id); return; }

        $subject = isset($loaded['subject']) ? (string) $loaded['subject'] : '';
        $message = isset($loaded['message']) ? (string) $loaded['message'] : '';
        $headers = isset($loaded['headers']) ? (array) $loaded['headers'] : array();
        $attempt = isset($loaded['attempt']) ? max(0, intval($loaded['attempt'])) : 0;

        // Rebuild minimal sanitized headers and tag
        $sanitized = array();
        $count = 0; $tag = '';
        foreach ($headers as $h) {
            $line = trim(str_replace(array("\r","\n"), '', (string)$h));
            if ( $line === '' ) continue;
            if ( preg_match('/^(from|to|subject|content-type)\s*:/i', $line) ) continue;
            if ( stripos($line, 'x-') !== 0 && stripos($line, 'reply-to:') !== 0 ) continue;
            if ( strlen($line) > 256 ) $line = substr($line, 0, 256);
            if ( stripos($line, 'x-ses-mailer-tag:') === 0 ) {
                $tag = trim(substr($line, strlen('x-ses-mailer-tag:')));
                $tag = preg_replace('/[^A-Za-z0-9._-]/', '', $tag);
            }
            $sanitized[] = $line;
            $count++; if ( $count >= 10 ) break;
        }

        $from_email = isset($opts['from_email']) ? trim($opts['from_email']) : '';
        if ( ! is_email($from_email) ) $from_email = get_option('admin_email');
        $from_name  = isset($opts['from_name'])  ? trim($opts['from_name'])  : '';
        if ( $from_name === '' ) $from_name = get_bloginfo('name');
        if ( ! is_email($from_email) ) {
            self::maybe_log(sprintf('FAIL%s to=%s subject="%s" code=ses_from_invalid status= msg="Configured From Email is invalid or missing."',
                self::tag_str($tag), implode(', ', $to), mb_substr($subject, 0, 120)));
            return;
        }

        $to_header   = implode(', ', $to);
        $from_header = $from_name !== '' ? sprintf('"%s" <%s>', Mailer::q($from_name), $from_email) : $from_email;

        // Determine content type similar to Mailer
        $content_type = 'text/plain; charset=UTF-8';
        foreach ((array)$headers as $hline) {
            $l = trim((string)$hline);
            if ( stripos($l, 'content-type:') === 0 ) {
                if ( stripos($l, 'text/html') !== false ) { $content_type = 'text/html; charset=UTF-8'; }
                break;
            }
        }
        if ( function_exists('apply_filters') ) {
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core wp_mail_content_type filter
            $filtered = apply_filters('wp_mail_content_type', $content_type);
            if ( is_string($filtered) && stripos($filtered, 'text/html') !== false ) {
                $content_type = 'text/html; charset=UTF-8';
            }
        }

        $attachments = isset($loaded['attachments']) ? (array)$loaded['attachments'] : array();
        $has_attachments = ! empty($attachments);
        $is_html = stripos($content_type, 'text/html') !== false;

        $rate = isset($opts['rate_limit']) ? max(0, intval($opts['rate_limit'])) : 10;
        if ( $rate > 0 ) { usleep(intval(1000000 / max(1, $rate))); }

        $client = new SesClient();
        $send_size = strlen($message);

        if ( $has_attachments ) {
            $mime = Mailer::build_raw_mime($from_header, $to_header, $subject, $message, $sanitized, $is_html, $attachments);
            $send_size = strlen($mime);
            $result = $client->send_raw_email($mime);
        } else {
            $text_body = Mailer::html_to_text($message);
            $html_body = $is_html ? $message : '';
            $reply_to = null;
            foreach ($sanitized as $h) {
                if ( stripos($h, 'reply-to:') === 0 ) {
                    $reply_to = trim(substr($h, strlen('reply-to:')));
                    break;
                }
            }
            $result = $client->send_email($from_header, $to, $subject, $html_body, $text_body, $reply_to);
        }
        if ( $result === true ) {
            self::maybe_log(sprintf('SUCCESS%s to=%s subject="%s" bytes=%d attempt=%d', self::tag_str($tag), $to_header, mb_substr($subject, 0, 120), $send_size, $attempt));
            if ( $job_id !== '' ) { self::delete_job($job_id); }
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

        // Retry with backoff up to 3 attempts total (attempt 0 + 2 retries => or adjust to 3 retries total)
        $max_attempts = 3; // total attempts including the first
        if ( $attempt + 1 < $max_attempts ) {
            $next_attempt = $attempt + 1;
            $delay = 60 * (1 << ($attempt)); // 60, 120
            self::maybe_log(sprintf('RETRY%s to=%s subject="%s" next_attempt=%d in=%ds', self::tag_str($tag), $to_header, mb_substr($subject, 0, 120), $next_attempt, $delay));
            // Update stored job and reschedule by job_id
            $loaded['attempt'] = $next_attempt;
            self::store_job($loaded, $job_id);
            self::schedule($job_id, $delay);
        } else {
            if ( $job_id !== '' ) { self::delete_job($job_id); }
        }
    }

    private static function store_job($payload, $job_id = '') {
        $id = $job_id !== '' ? $job_id : self::generate_job_id();
        $key = self::JOB_OPTION_PREFIX . $id;
        // Use update_option with autoload = no to keep it out of alloptions
        if ( get_option($key, null) === null ) { add_option($key, $payload, '', 'no'); }
        else { update_option($key, $payload, false); }
        return $id;
    }

    private static function load_job($job_id) {
        $key = self::JOB_OPTION_PREFIX . $job_id;
        $val = get_option($key, null);
        return $val;
    }

    private static function delete_job($job_id) {
        $key = self::JOB_OPTION_PREFIX . $job_id;
        delete_option($key);
    }

    private static function generate_job_id() {
        if ( function_exists('wp_generate_uuid4') ) return wp_generate_uuid4();
        return bin2hex( random_bytes(16) );
    }

    private static function maybe_log($msg) {
        $opts = get_option(Options::OPTION, Options::defaults());
        if ( empty($opts['disable_logging']) ) {
            LogViewer::log($msg);
        }
    }

    private static function tag_str($tag) {
        return ($tag !== '') ? ' tag=' . $tag : '';
    }

}
