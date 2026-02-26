<?php
namespace SesMailer\Mail;
if ( ! defined('ABSPATH') ) { exit; }

use WP_Error;
use SesMailer\Api\SesClient;
use SesMailer\Support\Options;
use SesMailer\Logging\LogViewer;

class Mailer {
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
        $args = wp_parse_args($args, array(
            'to'          => array(),
            'subject'     => '',
            'message'     => '',
            'headers'     => array(),
            'attachments' => array(),
        ));
        if ( ! is_array($args['headers']) ) {
            $headers = array();
            foreach ( explode("\n", str_replace("\r", "\n", (string) $args['headers'])) as $line ) {
                $line = trim($line);
                if ($line !== '') $headers[] = $line;
            }
            $args['headers'] = $headers;
        }
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

    public function send($pre, $atts) {
        if ( empty($this->opts['enable_mailer']) ) return $pre;

        // If background sending is enabled, enqueue and short-circuit
        if ( ! empty($this->opts['background_send']) ) {
            $atts = wp_parse_args($atts, array(
                'to'          => array(),
                'subject'     => '',
                'message'     => '',
                'headers'     => array(),
                'attachments' => array(),
            ));
            $to = $atts['to'];
            if ( is_string($to) ) $to = array_map('trim', explode(',', $to));
            $to = array_filter(array_map('sanitize_email', (array) $to));
            if ( empty($to) ) return new WP_Error('ses_to_missing', 'No recipient.');
            $headers = is_array($atts['headers']) ? $atts['headers'] : array_filter(
                array_map('trim', explode("\n", str_replace("\r", "\n", (string) $atts['headers'])))
            );
            $queued = \SesMailer\Background\Queue::enqueue(array(
                'to'          => $to,
                'subject'     => (string) $atts['subject'],
                'message'     => (string) $atts['message'],
                'headers'     => $headers,
                'attachments' => (array) $atts['attachments'],
            ));
            if ( $queued === false ) {
                return new WP_Error('ses_queue_failed', 'Failed to store email in background queue.');
            }
            return true;
        }

        $atts = wp_parse_args($atts, array(
            'to'          => array(),
            'subject'     => '',
            'message'     => '',
            'headers'     => array(),
            'attachments' => array(),
        ));

        $to = $atts['to'];
        if ( is_string($to) ) $to = array_map('trim', explode(',', $to));
        $to = array_filter(array_map('sanitize_email', (array) $to));
        if ( empty($to) ) return new WP_Error('ses_to_missing', 'No recipient.');

        $headers = is_array($atts['headers']) ? $atts['headers'] : array_filter(
            array_map('trim', explode("\n", str_replace("\r", "\n", (string) $atts['headers'])))
        );

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

        $from_email = isset($this->opts['from_email']) ? trim($this->opts['from_email']) : '';
        if ( ! is_email($from_email) ) $from_email = get_option('admin_email');
        $from_name  = isset($this->opts['from_name'])  ? trim($this->opts['from_name'])  : '';
        if ( $from_name === '' ) $from_name = get_bloginfo('name');
        if ( ! is_email($from_email) ) return new WP_Error('ses_from_invalid', 'Configured From Email is invalid or missing.');

        $subject = (string) $atts['subject'];
        $message = (string) $atts['message'];
        $attachments = (array) $atts['attachments'];

        $rate = isset($this->opts['rate_limit']) ? max(0, intval($this->opts['rate_limit'])) : 10;
        self::throttle($rate);

        $mime = self::build_mime($to, $subject, $message, $headers, $attachments, $from_email, $from_name);
        if ( is_wp_error($mime) ) return $mime;
        $send_size = strlen($mime);
        $result = (new SesClient())->send_raw_email($mime);

        $to_header = implode(', ', $to);

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
        return $err;
    }

    /**
     * Build a complete MIME message using PHPMailer (bundled with WordPress).
     *
     * @param array  $to          Recipient email addresses.
     * @param string $subject     Email subject.
     * @param string $message     Email body (HTML or plain text).
     * @param array  $headers     Raw header lines from wp_mail().
     * @param array  $attachments File paths to attach.
     * @param string $from_email  Sender email address.
     * @param string $from_name   Sender display name.
     * @return string|WP_Error Complete MIME message or error.
     */
    public static function build_mime($to, $subject, $message, $headers, $attachments, $from_email, $from_name) {
        require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
        require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';

        try {
            $phpmailer = new \PHPMailer\PHPMailer\PHPMailer(true);
            $phpmailer->CharSet = \PHPMailer\PHPMailer\PHPMailer::CHARSET_UTF8;
            $phpmailer->XMailer = ' ';

            $phpmailer->setFrom($from_email, $from_name);

            foreach ((array) $to as $addr) {
                $addr = trim($addr);
                if ( $addr !== '' ) {
                    $phpmailer->addAddress($addr);
                }
            }

            $phpmailer->Subject = $subject;

            // Determine content type from headers and filters
            $content_type = 'text/plain';
            foreach ((array) $headers as $hline) {
                $l = trim((string) $hline);
                if ( stripos($l, 'content-type:') === 0 ) {
                    if ( stripos($l, 'text/html') !== false ) {
                        $content_type = 'text/html';
                    }
                    break;
                }
            }
            if ( function_exists('apply_filters') ) {
                // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core wp_mail_content_type filter
                $filtered = apply_filters('wp_mail_content_type', $content_type);
                if ( is_string($filtered) && stripos($filtered, 'text/html') !== false ) {
                    $content_type = 'text/html';
                }
            }

            // Auto-detect HTML content when Content-Type is not explicitly set.
            if ( $content_type === 'text/plain' ) {
                $trimmed = ltrim($message);
                if ( stripos($trimmed, '<!doctype') === 0 || stripos($trimmed, '<html') === 0 ) {
                    $content_type = 'text/html';
                }
            }

            $is_html = ($content_type === 'text/html');
            if ( $is_html ) {
                $phpmailer->isHTML(true);
                $phpmailer->Body    = $message;
                $phpmailer->AltBody = self::html_to_text($message);
            } else {
                $phpmailer->isHTML(false);
                $phpmailer->Body = $message;
            }

            // Parse headers for Reply-To, CC, BCC, and custom X-* headers
            foreach ((array) $headers as $hline) {
                $line = trim(str_replace(array("\r", "\n"), '', (string) $hline));
                if ( $line === '' ) continue;

                if ( preg_match('/^(from|to|subject|content-type|mime-version)\s*:/i', $line) ) continue;

                if ( stripos($line, 'reply-to:') === 0 ) {
                    $value = trim(substr($line, strlen('reply-to:')));
                    if ( $value !== '' ) {
                        if ( preg_match('/^(.+)<([^>]+)>$/', $value, $m) ) {
                            $phpmailer->addReplyTo(trim($m[2]), trim($m[1], " \t\""));
                        } else {
                            $phpmailer->addReplyTo($value);
                        }
                    }
                    continue;
                }

                if ( stripos($line, 'cc:') === 0 ) {
                    $value = trim(substr($line, strlen('cc:')));
                    if ( $value !== '' ) {
                        foreach ( array_map('trim', explode(',', $value)) as $cc_addr ) {
                            if ( $cc_addr !== '' ) {
                                if ( preg_match('/^(.+)<([^>]+)>$/', $cc_addr, $m) ) {
                                    $phpmailer->addCC(trim($m[2]), trim($m[1], " \t\""));
                                } else {
                                    $phpmailer->addCC($cc_addr);
                                }
                            }
                        }
                    }
                    continue;
                }

                if ( stripos($line, 'bcc:') === 0 ) {
                    $value = trim(substr($line, strlen('bcc:')));
                    if ( $value !== '' ) {
                        foreach ( array_map('trim', explode(',', $value)) as $bcc_addr ) {
                            if ( $bcc_addr !== '' ) {
                                if ( preg_match('/^(.+)<([^>]+)>$/', $bcc_addr, $m) ) {
                                    $phpmailer->addBCC(trim($m[2]), trim($m[1], " \t\""));
                                } else {
                                    $phpmailer->addBCC($bcc_addr);
                                }
                            }
                        }
                    }
                    continue;
                }

                if ( stripos($line, 'x-') === 0 ) {
                    $phpmailer->addCustomHeader($line);
                }
            }

            // Attachments — strict allowlist: uploads dir and wp-content only
            $attach_errors = self::attach_files($phpmailer, $attachments);
            if ( ! empty($attach_errors) ) {
                LogViewer::log('ATTACH_BLOCKED paths=' . implode(', ', $attach_errors));
            }

            $phpmailer->preSend();
            return $phpmailer->getSentMIMEMessage();
        } catch (\PHPMailer\PHPMailer\Exception $e) {
            return new WP_Error('ses_mime_error', 'Failed to build MIME: ' . $e->getMessage());
        }
    }

    /**
     * Attach files with strict path validation.
     *
     * Only allows files inside the uploads directory or wp-content.
     * Rejects symlink escapes by comparing realpath against allowed roots.
     *
     * @return array List of blocked path strings (empty if all OK).
     */
    public static function attach_files($phpmailer, $attachments) {
        $uploads_dir = wp_get_upload_dir();
        $uploads_base = isset($uploads_dir['basedir']) ? (string) $uploads_dir['basedir'] : '';
        $content_base = defined('WP_CONTENT_DIR') ? (string) WP_CONTENT_DIR : '';
        $cache_key = $uploads_base . '|' . $content_base;

        static $allowed_cache = array();
        if ( ! isset($allowed_cache[$cache_key]) ) {
            $allowed_bases = array();
            if ( $uploads_base !== '' ) {
                $real = realpath($uploads_base);
                if ( $real !== false ) $allowed_bases[] = $real;
            }
            if ( $content_base !== '' ) {
                $real = realpath($content_base);
                if ( $real !== false ) $allowed_bases[] = $real;
            }
            $allowed_bases = array_values(array_unique($allowed_bases));
            $allowed_prefixes = array();
            foreach ( $allowed_bases as $base ) {
                $allowed_prefixes[] = $base . DIRECTORY_SEPARATOR;
            }
            $allowed_cache[$cache_key] = array(
                'bases'    => $allowed_bases,
                'prefixes' => $allowed_prefixes,
            );
        }
        $allowed_prefixes = $allowed_cache[$cache_key]['prefixes'];

        $blocked = array();
        foreach ((array) $attachments as $path) {
            $path = (string) $path;
            if ( $path === '' ) continue;

            $real = realpath($path);

            // Reject if realpath fails (broken symlink, non-existent)
            if ( $real === false || ! is_file($real) || ! is_readable($real) ) {
                $blocked[] = $path;
                continue;
            }

            // Reject symlink escapes: if the given path contains a symlink
            // that resolves outside allowed roots, realpath will reveal it
            $allowed = false;
            foreach ( $allowed_prefixes as $prefix ) {
                if ( strpos($real, $prefix) === 0 ) {
                    $allowed = true;
                    break;
                }
            }
            if ( ! $allowed ) {
                $blocked[] = $path;
                continue;
            }

            $phpmailer->addAttachment($real);
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
            $status = is_array($data) && isset($data['status']) ? (string)$data['status'] : '';
            LogViewer::log(sprintf('WP_MAIL_FAILED code=%s status=%s msg="%s"', $code, $status, mb_substr($msg, 0, 200)));
        }
    }
}
