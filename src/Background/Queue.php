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
    const FILES_DIR = 'ses-mailer-queue';
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

    /**
     * Store a prepared mail (see Mailer::prepare()) and schedule it.
     * Attachments and embeds are copied into a private per-job folder so
     * temporary files deleted after wp_mail() returns are still sent.
     */
    public static function enqueue($payload) {
        $payload = is_array($payload) ? $payload : array();
        $args = array(
            'to'           => isset($payload['to']) ? (array) $payload['to'] : array(),
            'subject'      => isset($payload['subject']) ? (string) $payload['subject'] : '',
            'message'      => isset($payload['message']) ? (string) $payload['message'] : '',
            'headers'      => isset($payload['headers']) ? (array) $payload['headers'] : array(),
            'attachments'  => isset($payload['attachments']) ? Mailer::normalize_files($payload['attachments']) : array(),
            'embeds'       => isset($payload['embeds']) ? Mailer::normalize_files($payload['embeds']) : array(),
            'attempt'      => 0,
            'created_at'   => time(),
        );
        foreach ( array('from_email', 'from_name', 'content_type', 'charset') as $key ) {
            if ( isset($payload[$key]) ) $args[$key] = (string) $payload[$key];
        }

        $job_id = self::generate_job_id();
        if ( ! empty($args['attachments']) || ! empty($args['embeds']) ) {
            $args['attachments'] = self::stash_files($job_id, $args['attachments'], 'a');
            $args['embeds']      = self::stash_files($job_id, $args['embeds'], 'e');
        }
        if ( self::store_job($args, $job_id) === false ) {
            self::delete_job_files($job_id);
            return false;
        }
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
                    $result = as_schedule_single_action($when, self::HOOK, array($args), 'api-mailer-for-aws-ses');
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
        if ( empty($opts['enable_mailer']) ) {
            // Mailer switched off while jobs were queued: keep the job and try again later
            // (stale jobs are still removed by the daily cleanup after 24h).
            $job_id = isset($args['job_id']) ? (string) $args['job_id'] : '';
            if ( $job_id !== '' && is_array(self::load_job($job_id)) ) self::schedule($job_id, HOUR_IN_SECONDS);
            return;
        }

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
        $to = Mailer::parse_recipients($to);
        if ( empty($to) ) { if ( is_string($job_id) && $job_id !== '' ) self::delete_job($job_id); return; }

        $subject = isset($loaded['subject']) ? (string) $loaded['subject'] : '';
        $headers = isset($loaded['headers']) ? (array) $loaded['headers'] : array();
        $attempt = isset($loaded['attempt']) ? max(0, intval($loaded['attempt'])) : 0;
        $tag = Mailer::extract_tag($headers);

        // Jobs queued before 1.5 carry no resolved sender; fall back to the settings.
        $from_email = isset($loaded['from_email']) ? (string) $loaded['from_email'] : '';
        if ( ! is_email($from_email) ) {
            $from_email = isset($opts['from_email']) ? trim($opts['from_email']) : '';
            if ( ! is_email($from_email) ) $from_email = get_option('admin_email');
        }
        $from_name = isset($loaded['from_name']) ? (string) $loaded['from_name'] : '';
        if ( $from_name === '' ) {
            $from_name = isset($opts['from_name']) ? trim($opts['from_name']) : '';
            if ( $from_name === '' ) $from_name = get_bloginfo('name');
        }
        if ( ! is_email($from_email) ) {
            self::maybe_log(sprintf('FAIL%s to=%s subject="%s" code=ses_from_invalid status= msg="Configured From Email is invalid or missing."',
                self::tag_str($tag), implode(', ', $to), mb_substr($subject, 0, 120)));
            if ( is_string($job_id) && $job_id !== '' ) { self::delete_job($job_id); }
            return;
        }

        $message = isset($loaded['message']) ? (string) $loaded['message'] : '';
        if ( isset($loaded['content_type']) ) {
            $content_type = (string) $loaded['content_type'];
        } else {
            // Pre-1.5 job: derive the type from its headers / body like older versions did.
            $content_type = 'text/plain';
            foreach ( Mailer::header_lines($headers) as $line ) {
                if ( stripos($line, 'content-type:') === 0 && stripos($line, 'text/html') !== false ) $content_type = 'text/html';
            }
            $trimmed = ltrim($message);
            if ( stripos($trimmed, '<!doctype') === 0 || stripos($trimmed, '<html') === 0 ) $content_type = 'text/html';
        }

        $mail = array(
            'to'           => $to,
            'subject'      => $subject,
            'message'      => $message,
            'headers'      => $headers,
            'attachments'  => isset($loaded['attachments']) ? (array) $loaded['attachments'] : array(),
            'embeds'       => isset($loaded['embeds']) ? (array) $loaded['embeds'] : array(),
            'from_email'   => $from_email,
            'from_name'    => $from_name,
            'content_type' => $content_type,
            'charset'      => isset($loaded['charset']) ? (string) $loaded['charset'] : 'UTF-8',
        );

        $to_header = implode(', ', $to);

        try {
            $rate = isset($opts['rate_limit']) ? max(0, intval($opts['rate_limit'])) : 10;
            Mailer::throttle($rate);

            $mime = Mailer::build_mime($mail);
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
        self::delete_job_files($job_id);
    }

    private static function files_base_dir() {
        $uploads = wp_get_upload_dir();
        $base = isset($uploads['basedir']) ? (string) $uploads['basedir'] : '';
        return $base !== '' ? trailingslashit($base) . self::FILES_DIR : '';
    }

    /**
     * Copy allowed files into uploads/ses-mailer-queue/{job_id}/ and return
     * the list with paths pointing at the copies. Files that fail the
     * Mailer::allowed_path() check are kept as-is so the worker logs them.
     */
    private static function stash_files($job_id, $files, $prefix) {
        $base = self::files_base_dir();
        if ( $base === '' || ! preg_match('/^[A-Za-z0-9-]+$/', $job_id) ) return $files;
        $dir = $base . '/' . $job_id;

        $out = array();
        foreach ( $files as $i => $file ) {
            $real = Mailer::allowed_path($file['path']);
            if ( $real === false || ( ! is_dir($dir) && ! wp_mkdir_p($dir) ) ) {
                $out[] = $file;
                continue;
            }
            self::protect_dir($base);
            self::protect_dir($dir);
            $copy = $dir . '/' . $prefix . $i . '-' . sanitize_file_name(basename($real));
            if ( @copy($real, $copy) ) {
                // Keep the original file name for display unless the caller gave one.
                $name = $file['name'] !== '' ? $file['name'] : ( $prefix === 'e' ? (string) $i : basename($real) );
                $out[] = array('path' => $copy, 'name' => $name);
            } else {
                $out[] = $file;
            }
        }
        return $out;
    }

    /**
     * Block web access to queued files (Apache/LiteSpeed) and directory listing.
     */
    private static function protect_dir($dir) {
        $guards = array(
            '.htaccess'  => "Require all denied\nDeny from all\n",
            'index.php'  => "<?php\n// Silence is golden.\n",
            'index.html' => '',
        );
        foreach ( $guards as $file => $content ) {
            if ( ! file_exists($dir . '/' . $file) ) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- tiny guard files in our own folder.
                @file_put_contents($dir . '/' . $file, $content);
            }
        }
    }

    private static function delete_job_files($job_id) {
        $base = self::files_base_dir();
        if ( $base === '' || ! preg_match('/^[A-Za-z0-9-]+$/', (string) $job_id) ) return;
        self::remove_dir($base . '/' . $job_id);
    }

    private static function remove_dir($dir) {
        if ( ! is_dir($dir) ) return;
        foreach ( array_merge((array) glob($dir . '/*'), (array) glob($dir . '/.htaccess')) as $file ) {
            if ( is_string($file) && is_file($file) ) wp_delete_file($file);
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- removing our own empty job folder.
        @rmdir($dir);
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
                    self::delete_job(substr($row->option_name, strlen($prefix)));
                    $deleted++;
                    $deleted_this_batch++;
                    if ( $deleted >= $max_deletes ) break 2;
                }
            }

            // If nothing was deleted this batch, all remaining rows are fresh — stop
            if ( $deleted_this_batch === 0 ) break;
        } while ( count($rows) === $batch_size );

        // Remove file folders whose job no longer exists.
        $base = self::files_base_dir();
        if ( $base !== '' && is_dir($base) ) {
            foreach ( (array) glob($base . '/*', GLOB_ONLYDIR) as $dir ) {
                $id = basename($dir);
                if ( filemtime($dir) < $stale_threshold && false === get_option(self::JOB_OPTION_PREFIX . $id) ) {
                    self::remove_dir($dir);
                }
            }
        }
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
