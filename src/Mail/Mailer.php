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
        add_filter('wp_mail_from_name', [$self = $this, 'from_name'],  999);
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
        return $args;
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
            \SesMailer\Background\Queue::enqueue(array(
                'to'          => $to,
                'subject'     => (string) $atts['subject'],
                'message'     => (string) $atts['message'],
                'headers'     => $headers,
                'attachments' => (array) $atts['attachments'],
            ));
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
        if ( $rate > 0 ) { usleep(intval(1000000 / max(1, $rate))); }

        $mime = self::build_mime($to, $subject, $message, $headers, $attachments, $from_email, $from_name);
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
     * @return string Complete MIME message ready for SendRawEmail.
     */
    public static function build_mime($to, $subject, $message, $headers, $attachments, $from_email, $from_name) {
        // Load PHPMailer from WordPress core
        require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
        require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';

        $phpmailer = new \PHPMailer\PHPMailer\PHPMailer(true);
        $phpmailer->CharSet = \PHPMailer\PHPMailer\PHPMailer::CHARSET_UTF8;
        $phpmailer->XMailer = ' '; // Suppress X-Mailer header

        // From
        $phpmailer->setFrom($from_email, $from_name);

        // To recipients
        foreach ((array) $to as $addr) {
            $addr = trim($addr);
            if ( $addr !== '' ) {
                $phpmailer->addAddress($addr);
            }
        }

        // Subject
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

            // Skip headers PHPMailer handles via its own setters
            if ( preg_match('/^(from|to|subject|content-type|mime-version)\s*:/i', $line) ) continue;

            if ( stripos($line, 'reply-to:') === 0 ) {
                $value = trim(substr($line, strlen('reply-to:')));
                if ( $value !== '' ) {
                    // Parse "Name <email>" or plain "email"
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

            // Pass through custom headers (X-*, etc.)
            if ( stripos($line, 'x-') === 0 ) {
                $phpmailer->addCustomHeader($line);
            }
        }

        // Attachments
        foreach ((array) $attachments as $path) {
            $path = (string) $path;
            if ( $path !== '' && @is_readable($path) ) {
                $phpmailer->addAttachment($path);
            }
        }

        // Build the MIME message (like FluentSMTP: preSend + getSentMIMEMessage)
        $phpmailer->preSend();
        return $phpmailer->getSentMIMEMessage();
    }

    /**
     * Convert HTML email to readable plain text.
     */
    public static function html_to_text($html) {
        // Remove invisible elements entirely.
        $html = preg_replace('/<head\b[^>]*>.*?<\/head>/is', '', $html);
        $html = preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $html);
        $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html);

        // Preserve image alt text: <img alt="Logo"> → [Logo]
        $html = preg_replace('/<img\b[^>]*\balt=["\']([^"\']*)["\'][^>]*>/i', '[$1]', $html);
        $html = preg_replace('/<img\b[^>]*>/i', '', $html); // remove remaining img tags with no alt

        // Convert links: <a href="url">text</a> → text (url)
        $html = preg_replace('/<a\b[^>]*\bhref=["\']([^"\']*)["\'][^>]*>(.*?)<\/a>/is', '$2 ($1)', $html);

        // Insert line breaks before block-level closing tags.
        $html = preg_replace('/<\/(p|div|tr|table|h[1-6]|li|blockquote)>/i', "\n", $html);
        $html = preg_replace('/<(br|hr)\b[^>]*\/?>/i', "\n", $html);

        // Strip all remaining HTML tags.
        $html = wp_strip_all_tags($html);

        // Decode HTML entities.
        $text = html_entity_decode($html, ENT_QUOTES, 'UTF-8');

        // Collapse horizontal whitespace (spaces/tabs) on each line, preserving newlines.
        $text = preg_replace('/[^\S\n]+/', ' ', $text);

        // Collapse 3+ consecutive blank lines into 2.
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
