<?php
namespace SesMailer\Mail;
if ( ! defined('ABSPATH') ) { exit; }

use WP_Error;
use SesMailer\Api\SesClient;
use SesMailer\Support\Options;
use SesMailer\Logging\LogViewer;
use SesMailer\Background\Queue;

class Mailer {
    /**
     * Headers handled explicitly or set by PHPMailer itself; never passed through as custom headers.
     */
    const RESERVED_HEADERS = array('from', 'to', 'subject', 'cc', 'bcc', 'reply-to', 'content-type', 'mime-version', 'x-mailer', 'date', 'message-id', 'content-transfer-encoding');

    private $opts;

    public function __construct() {
        $this->opts = get_option(Options::OPTION, Options::defaults());

        add_filter('wp_mail_from',      [$this, 'from_email'],  999);
        add_filter('wp_mail_from_name', [$this, 'from_name'],  999);
        add_filter('wp_mail',           [$this, 'normalize'],   999);
        add_filter('pre_wp_mail',       [$this, 'send'], 10, 2);

        add_action('wp_mail_failed',    [$this, 'log_failure'], 10, 1);
    }

    public function from_email($email) {
        $configured = isset($this->opts['from_email']) ? trim($this->opts['from_email']) : '';
        if ( ! is_email($configured) ) $configured = get_option('admin_email');
        if ( is_email($configured) && ! empty($this->opts['force_from']) ) return $configured;
        return $email;
    }
    public function from_name($name) {
        $configured = isset($this->opts['from_name']) ? trim($this->opts['from_name']) : '';
        if ( $configured === '' ) $configured = get_bloginfo('name');
        if ( $configured !== '' && ! empty($this->opts['force_from']) ) return $configured;
        return $name;
    }

    public function normalize($args) {
        if ( empty($this->opts['enable_mailer']) ) return $args;

        $args = wp_parse_args($args, array(
            'to'          => array(),
            'subject'     => '',
            'message'     => '',
            'headers'     => array(),
            'attachments' => array(),
        ));
        $args['headers'] = self::header_lines($args['headers']);

        $reply_to = isset($this->opts['reply_to']) ? trim($this->opts['reply_to']) : '';
        if ( is_email($reply_to) ) {
            $has = false;
            foreach ($args['headers'] as $h) { if ( stripos($h, 'reply-to:') === 0 ) { $has = true; break; } }
            if ( ! $has ) $args['headers'][] = 'Reply-To: ' . $reply_to;
        }

        // Append custom headers from settings
        $custom = isset($this->opts['custom_headers']) ? trim($this->opts['custom_headers']) : '';
        if ( $custom !== '' ) {
            foreach ( explode("\n", str_replace("\r", "\n", $custom)) as $ch ) {
                $ch = trim($ch);
                if ( $ch !== '' && stripos($ch, 'x-') === 0 && strpos($ch, ':') !== false ) {
                    $args['headers'][] = $ch;
                }
            }
        }

        return $args;
    }

    public static function throttle($rate) {
        if ( $rate <= 0 ) return;
        $key = 'ses_mailer_last_send';
        $last = (float) get_transient($key);
        $interval = 1.0 / max(1, $rate);
        $now = microtime(true);
        $wait = $last + $interval - $now;
        if ( $wait > 0 && $wait < 2.0 ) usleep((int)($wait * 1000000));
        set_transient($key, microtime(true), 60);
    }

    /**
     * pre_wp_mail handler. Returns a boolean like core wp_mail() and fires
     * wp_mail_succeeded / wp_mail_failed so other plugins see the outcome.
     */
    public function send($pre, $atts) {
        // Another plugin already decided (e.g. blocked the email): respect it.
        if ( null !== $pre ) return $pre;
        if ( empty($this->opts['enable_mailer']) ) return $pre;

        $result = $this->deliver($atts);

        $mail_data = array_intersect_key(
            wp_parse_args($atts, self::atts_defaults()),
            self::atts_defaults()
        );

        if ( is_wp_error($result) ) {
            $data = $result->get_error_data();
            do_action('wp_mail_failed', new WP_Error( // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook.
                $result->get_error_code(),
                $result->get_error_message(),
                array_merge($mail_data, is_array($data) ? $data : array())
            ));
            return false;
        }

        do_action('wp_mail_succeeded', $mail_data); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook.
        return true;
    }

    private static function atts_defaults() {
        return array(
            'to'          => array(),
            'subject'     => '',
            'message'     => '',
            'headers'     => array(),
            'attachments' => array(),
            'embeds'      => array(),
        );
    }

    /**
     * Parse wp_mail() recipients into validated "addr" or "Name <addr>" strings.
     *
     * @param string|array $to
     * @return string[]
     */
    public static function parse_recipients($to) {
        if ( ! is_array($to) ) $to = explode(',', (string) $to);
        $out = array();
        foreach ( $to as $raw ) {
            $raw = trim(str_replace(array("\r", "\n"), '', (string) $raw));
            if ( $raw === '' ) continue;
            list($email, $name) = self::split_address($raw);
            if ( ! is_email($email) ) continue;
            $out[] = $name !== '' ? $name . ' <' . $email . '>' : $email;
        }
        return $out;
    }

    /**
     * Split "Name <addr>" into [addr, name]; a bare address returns [addr, ''].
     */
    private static function split_address($raw) {
        if ( preg_match('/(.*)<(.+)>/', $raw, $m) ) {
            return array(trim($m[2]), trim($m[1], " \t\"'"));
        }
        return array(trim($raw), '');
    }

    /**
     * Turn wp_mail() headers (string or array) into a list of trimmed lines.
     */
    public static function header_lines($headers) {
        if ( ! is_array($headers) ) {
            $headers = explode("\n", str_replace("\r", "\n", (string) $headers));
        }
        return array_values(array_filter(array_map(function ($h) {
            return trim(str_replace(array("\r", "\n"), '', (string) $h));
        }, $headers), 'strlen'));
    }

    /**
     * Normalize wp_mail() attachments/embeds (string or array) into a list of
     * array('path' => ..., 'name' => ...). String keys are kept as the name
     * (attachments) or Content-ID (embeds), like core.
     */
    public static function normalize_files($files) {
        if ( ! is_array($files) ) {
            $files = explode("\n", str_replace("\r\n", "\n", (string) $files));
        }
        $out = array();
        foreach ( $files as $key => $file ) {
            if ( is_array($file) ) {
                $path = isset($file['path']) ? (string) $file['path'] : '';
                $name = isset($file['name']) ? (string) $file['name'] : '';
            } else {
                $path = (string) $file;
                $name = is_string($key) ? $key : '';
            }
            $path = trim($path);
            if ( $path === '' ) continue;
            $out[] = array('path' => $path, 'name' => $name);
        }
        return $out;
    }

    /**
     * Extract the X-SES-Mailer-Tag header value for logging.
     */
    public static function extract_tag($headers) {
        foreach ( (array) $headers as $h ) {
            $line = trim(str_replace(array("\r", "\n"), '', (string) $h));
            if ( stripos($line, 'x-ses-mailer-tag:') === 0 ) {
                return preg_replace('/[^A-Za-z0-9._-]/', '', trim(substr($line, strlen('x-ses-mailer-tag:'))));
            }
        }
        return '';
    }

    /**
     * Resolve everything that depends on request-time state (filters, settings)
     * into a self-contained mail array that build_mime() and the queue can use.
     *
     * @return array|WP_Error
     */
    private function prepare($atts) {
        $atts = wp_parse_args($atts, self::atts_defaults());

        $to = self::parse_recipients($atts['to']);
        if ( empty($to) ) return new WP_Error('ses_to_missing', 'No recipient.');

        $headers = self::header_lines($atts['headers']);

        $hdr_from_email = '';
        $hdr_from_name  = '';
        $content_type   = '';
        $charset        = '';
        foreach ( $headers as $line ) {
            if ( strpos($line, ':') === false ) continue;
            list($name, $value) = array_map('trim', explode(':', $line, 2));
            switch ( strtolower($name) ) {
                case 'from':
                    list($hdr_from_email, $hdr_from_name) = self::split_address($value);
                    break;
                case 'content-type':
                    $parts = array_map('trim', explode(';', $value));
                    $content_type = strtolower($parts[0]);
                    foreach ( array_slice($parts, 1) as $param ) {
                        if ( stripos($param, 'charset=') === 0 ) {
                            $charset = trim(substr($param, 8), " \t\"'");
                        }
                    }
                    break;
            }
        }

        list($from_email, $from_name, $replaced) = $this->resolve_from($hdr_from_email, $hdr_from_name);
        if ( ! is_email($from_email) ) return new WP_Error('ses_from_invalid', 'Configured From Email is invalid or missing.');

        // If the requested sender was swapped for the configured one, let replies still reach it.
        if ( $replaced !== '' ) {
            $has_reply_to = false;
            foreach ( $headers as $line ) { if ( stripos($line, 'reply-to:') === 0 ) { $has_reply_to = true; break; } }
            if ( ! $has_reply_to ) $headers[] = 'Reply-To: ' . $replaced;
        }

        // Only text/plain and text/html are built by this plugin; anything else falls back to text/plain.
        if ( $content_type !== 'text/html' ) $content_type = 'text/plain';
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core filter.
        $filtered = apply_filters('wp_mail_content_type', $content_type);
        if ( is_string($filtered) && stripos($filtered, 'text/html') !== false ) $content_type = 'text/html';

        $message = (string) $atts['message'];
        // Auto-detect a full HTML document when no type was set.
        if ( $content_type === 'text/plain' ) {
            $trimmed = ltrim($message);
            if ( stripos($trimmed, '<!doctype') === 0 || stripos($trimmed, '<html') === 0 ) {
                $content_type = 'text/html';
            }
        }

        if ( $charset === '' ) $charset = get_bloginfo('charset');
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core filter.
        $charset = (string) apply_filters('wp_mail_charset', $charset);

        return array(
            'to'           => $to,
            'subject'      => (string) $atts['subject'],
            'message'      => $message,
            'headers'      => $headers,
            'attachments'  => self::normalize_files($atts['attachments']),
            'embeds'       => self::normalize_files($atts['embeds']),
            'from_email'   => $from_email,
            'from_name'    => $from_name,
            'content_type' => $content_type,
            'charset'      => $charset !== '' ? $charset : 'UTF-8',
        );
    }

    /**
     * Pick the From name/address the way core does (From header, then the
     * wp_mail_from / wp_mail_from_name filters), with the configured sender
     * as the default. SES only sends from verified identities, so any other
     * address is replaced by the configured From Email unless the
     * ses_mailer_allow_from filter returns true for it.
     *
     * @return array [email, name, replaced address or '']
     */
    private function resolve_from($hdr_email, $hdr_name) {
        $default_email = isset($this->opts['from_email']) ? trim($this->opts['from_email']) : '';
        if ( ! is_email($default_email) ) $default_email = get_option('admin_email');
        $default_name = isset($this->opts['from_name']) ? trim($this->opts['from_name']) : '';
        if ( $default_name === '' ) $default_name = get_bloginfo('name');

        $email = is_email($hdr_email) ? $hdr_email : $default_email;
        $name  = $hdr_name !== '' ? $hdr_name : $default_name;
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core filter.
        $email = trim((string) apply_filters('wp_mail_from', $email));
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core filter.
        $name  = trim((string) apply_filters('wp_mail_from_name', $name));

        $replaced = '';
        if ( ! is_email($email) ) {
            $email = $default_email;
        } elseif ( ! self::from_allowed($email, $default_email) ) {
            $replaced = $email;
            $email = $default_email;
        }
        return array($email, $name, $replaced);
    }

    private static function from_allowed($email, $configured) {
        if ( strcasecmp((string) $email, (string) $configured) === 0 ) return true;
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- ses_mailer_ is this plugin's prefix (options, cron hooks).
        return (bool) apply_filters('ses_mailer_allow_from', false, $email);
    }

    /**
     * Send (or enqueue) the message.
     *
     * @return true|WP_Error
     */
    private function deliver($atts) {
        $mail = $this->prepare($atts);
        if ( is_wp_error($mail) ) return $mail;

        if ( ! empty($this->opts['background_send']) ) {
            if ( Queue::enqueue($mail) === false ) {
                return new WP_Error('ses_queue_failed', 'Failed to store email in background queue.');
            }
            return true;
        }

        $tag = self::extract_tag($mail['headers']);

        $rate = isset($this->opts['rate_limit']) ? max(0, intval($this->opts['rate_limit'])) : 10;
        self::throttle($rate);

        $mime = self::build_mime($mail);
        if ( is_wp_error($mime) ) return $mime;
        $send_size = strlen($mime);
        $result = (new SesClient())->send_raw_email($mime);

        $to_header = implode(', ', $mail['to']);
        $subject = $mail['subject'];

        if ( $result === true ) {
            $sub_log = mb_substr($subject, 0, 120);
            if ( $tag !== '' ) {
                LogViewer::log(sprintf('SUCCESS tag=%s to=%s subject="%s" bytes=%d', $tag, $to_header, $sub_log, $send_size));
            } else {
                LogViewer::log(sprintf('SUCCESS to=%s subject="%s" bytes=%d', $to_header, $sub_log, $send_size));
            }
            return true;
        }

        // Log failure details
        $err = is_wp_error($result) ? $result : new WP_Error('ses_unknown', 'SES send failed.');
        $code = $err->get_error_code();
        $msg  = $err->get_error_message();
        $data = $err->get_error_data();
        $status = is_array($data) && isset($data['status']) ? (string)$data['status'] : '';
        $hint = '';
        if ( ($code === 'ses_api_error' && (string)$status === '403') || $code === 'ses_creds_missing' || $code === 'ses_region_invalid' ) {
            $hint = ' hint=Check AWS Access Key/Secret and ensure the Region matches your SES setup.';
        }
        if ( $tag !== '' ) {
            LogViewer::log(sprintf('FAIL tag=%s to=%s subject="%s" code=%s status=%s msg="%s"%s', $tag, $to_header, mb_substr($subject, 0, 120), $code, $status, mb_substr($msg, 0, 200), $hint));
        } else {
            LogViewer::log(sprintf('FAIL to=%s subject="%s" code=%s status=%s msg="%s"%s', $to_header, mb_substr($subject, 0, 120), $code, $status, mb_substr($msg, 0, 200), $hint));
        }
        // Already logged above; tell log_failure() to skip it.
        return new WP_Error($code, $msg, array_merge(is_array($data) ? $data : array(), array('ses_mailer_logged' => true)));
    }

    /**
     * Build a complete MIME message using PHPMailer (bundled with WordPress).
     *
     * @param array $mail Prepared mail: to, subject, message, headers, attachments,
     *                    embeds, from_email, from_name, content_type, charset.
     * @return string|WP_Error Complete MIME message or error.
     */
    public static function build_mime($mail) {
        require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
        require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';

        $mail = wp_parse_args($mail, array(
            'to' => array(), 'subject' => '', 'message' => '', 'headers' => array(),
            'attachments' => array(), 'embeds' => array(), 'from_email' => '', 'from_name' => '',
            'content_type' => 'text/plain', 'charset' => 'UTF-8',
        ));

        try {
            $phpmailer = new \PHPMailer\PHPMailer\PHPMailer(true);
            $phpmailer->CharSet = $mail['charset'] !== '' ? $mail['charset'] : 'UTF-8';
            $phpmailer->XMailer = ' ';

            $phpmailer->setFrom($mail['from_email'], $mail['from_name']);

            foreach ( (array) $mail['to'] as $addr ) {
                self::add_address($phpmailer, 'to', $addr);
            }
            if ( empty($phpmailer->getToAddresses()) ) {
                return new WP_Error('ses_to_missing', 'No valid recipient.');
            }

            $phpmailer->Subject = (string) $mail['subject'];

            $message = (string) $mail['message'];
            if ( $mail['content_type'] === 'text/html' ) {
                $phpmailer->isHTML(true);
                $phpmailer->Body    = $message;
                $phpmailer->AltBody = self::html_to_text($message);
            } else {
                $phpmailer->isHTML(false);
                $phpmailer->Body = $message;
            }

            // Reply-To, CC, BCC, and every other header the caller set (List-Unsubscribe, Precedence, X-*...)
            foreach ( self::header_lines($mail['headers']) as $line ) {
                if ( strpos($line, ':') === false ) continue;
                list($name, $value) = array_map('trim', explode(':', $line, 2));
                $lname = strtolower($name);

                if ( in_array($lname, array('cc', 'bcc', 'reply-to'), true) ) {
                    foreach ( explode(',', $value) as $addr ) {
                        self::add_address($phpmailer, $lname, $addr);
                    }
                    continue;
                }
                if ( in_array($lname, self::RESERVED_HEADERS, true) ) continue;
                if ( ! preg_match('/^[A-Za-z0-9-]+$/', $name) || $value === '' ) continue;

                try {
                    $phpmailer->addCustomHeader($name, $value);
                } catch ( \PHPMailer\PHPMailer\Exception $e ) {
                    continue;
                }
            }

            $blocked = self::attach_files($phpmailer, $mail['attachments']);
            $blocked = array_merge($blocked, self::embed_files($phpmailer, $mail['embeds']));
            if ( ! empty($blocked) ) {
                LogViewer::log('ATTACH_BLOCKED paths=' . implode(', ', $blocked));
            }

            // Let plugins adjust the message (DKIM, extra headers) like core wp_mail().
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook.
            do_action_ref_array('phpmailer_init', array(&$phpmailer));

            // An SMTP plugin may have called isSMTP(); PHPMailer then omits the Bcc
            // header, and SES reads recipients from the headers. Restore it.
            $phpmailer->Mailer = 'mail';
            // A hook must not swap in an unverified sender.
            if ( ! self::from_allowed($phpmailer->From, $mail['from_email']) ) {
                $phpmailer->setFrom($mail['from_email'], $phpmailer->FromName);
            }

            $phpmailer->preSend();
            return $phpmailer->getSentMIMEMessage();
        } catch (\Throwable $e) {
            return new WP_Error('ses_mime_error', 'Failed to build MIME: ' . $e->getMessage());
        }
    }

    /**
     * Add one address; an invalid address is skipped (like core) instead of failing the message.
     */
    private static function add_address($phpmailer, $type, $raw) {
        $raw = trim((string) $raw);
        if ( $raw === '' ) return;
        list($email, $name) = self::split_address($raw);
        try {
            switch ( $type ) {
                case 'cc':       $phpmailer->addCC($email, $name); break;
                case 'bcc':      $phpmailer->addBCC($email, $name); break;
                case 'reply-to': $phpmailer->addReplyTo($email, $name); break;
                default:         $phpmailer->addAddress($email, $name); break;
            }
        } catch ( \PHPMailer\PHPMailer\Exception $e ) {
            return;
        }
    }

    /**
     * Resolve a path and check it is a readable file inside an allowed root
     * (uploads, wp-content, or the system temp dir). realpath() resolves
     * symlinks, so a link pointing outside these roots is rejected.
     *
     * @return string|false Real path, or false if not allowed.
     */
    public static function allowed_path($path) {
        $path = trim((string) $path);
        if ( $path === '' ) return false;

        $roots = array();
        $uploads = wp_get_upload_dir();
        if ( ! empty($uploads['basedir']) ) $roots[] = (string) $uploads['basedir'];
        if ( defined('WP_CONTENT_DIR') ) $roots[] = (string) WP_CONTENT_DIR;
        $roots[] = function_exists('get_temp_dir') ? get_temp_dir() : sys_get_temp_dir();
        $prefixes = array();
        foreach ( $roots as $root ) {
            $root_real = realpath($root);
            if ( $root_real !== false ) $prefixes[] = rtrim($root_real, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        }

        $real = realpath($path);
        if ( $real === false || ! is_file($real) || ! is_readable($real) ) return false;
        foreach ( $prefixes as $prefix ) {
            if ( strpos($real, $prefix) === 0 ) return $real;
        }
        return false;
    }

    /**
     * Attach files with strict path validation (see allowed_path()).
     *
     * @return array List of blocked path strings (empty if all OK).
     */
    public static function attach_files($phpmailer, $attachments) {
        $blocked = array();
        foreach ( self::normalize_files($attachments) as $file ) {
            $real = self::allowed_path($file['path']);
            if ( $real === false ) {
                $blocked[] = $file['path'];
                continue;
            }
            try {
                $phpmailer->addAttachment($real, $file['name']);
            } catch ( \PHPMailer\PHPMailer\Exception $e ) {
                $blocked[] = $file['path'];
            }
        }
        return $blocked;
    }

    /**
     * Embed inline images (wp_mail() $embeds, WP 6.9+). The name is the Content-ID.
     *
     * @return array List of blocked path strings.
     */
    public static function embed_files($phpmailer, $embeds) {
        $blocked = array();
        foreach ( self::normalize_files($embeds) as $i => $file ) {
            $real = self::allowed_path($file['path']);
            if ( $real === false ) {
                $blocked[] = $file['path'];
                continue;
            }
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core filter.
            $args = apply_filters('wp_mail_embed_args', array(
                'path'        => $real,
                'cid'         => $file['name'] !== '' ? $file['name'] : (string) $i,
                'name'        => basename($file['path']),
                'encoding'    => 'base64',
                'type'        => '',
                'disposition' => 'inline',
            ));
            try {
                $phpmailer->addEmbeddedImage($args['path'], $args['cid'], $args['name'], $args['encoding'], $args['type'], $args['disposition']);
            } catch ( \PHPMailer\PHPMailer\Exception $e ) {
                $blocked[] = $file['path'];
            }
        }
        return $blocked;
    }

    /**
     * Convert HTML email to readable plain text.
     */
    public static function html_to_text($html) {
        $html = preg_replace('/<head\b[^>]*>.*?<\/head>/is', '', $html);
        $html = preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $html);
        $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html);
        $html = preg_replace('/<img\b[^>]*\balt=["\']([^"\']*)["\'][^>]*>/i', '[$1]', $html);
        $html = preg_replace('/<img\b[^>]*>/i', '', $html);
        $html = preg_replace('/<a\b[^>]*\bhref=["\']([^"\']*)["\'][^>]*>(.*?)<\/a>/is', '$2 ($1)', $html);
        $html = preg_replace('/<\/(p|div|tr|table|h[1-6]|li|blockquote)>/i', "\n", $html);
        $html = preg_replace('/<(br|hr)\b[^>]*\/?>/i', "\n", $html);
        $html = wp_strip_all_tags($html);
        $text = html_entity_decode($html, ENT_QUOTES, 'UTF-8');
        $text = preg_replace('/[^\S\n]+/', ' ', $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        return trim($text);
    }

    public function log_failure($wp_error) {
        if ( is_wp_error($wp_error) ) {
            $code = $wp_error->get_error_code();
            $msg  = $wp_error->get_error_message();
            $data = $wp_error->get_error_data();
            if ( is_array($data) && ! empty($data['ses_mailer_logged']) ) return;
            $status = is_array($data) && isset($data['status']) ? (string)$data['status'] : '';
            LogViewer::log(sprintf('WP_MAIL_FAILED code=%s status=%s msg="%s"', $code, $status, mb_substr($msg, 0, 200)));
        }
    }
}
