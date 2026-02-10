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
        // Detect desired content type from headers or filters (e.g., WooCommerce sets HTML)
        $content_type = 'text/plain; charset=UTF-8';
        foreach ((array)$headers as $hline) {
            $l = trim((string)$hline);
            if ( stripos($l, 'content-type:') === 0 ) {
                if ( stripos($l, 'text/html') !== false ) { $content_type = 'text/html; charset=UTF-8'; }
                break;
            }
        }
        // Allow other plugins to influence content type similar to core's wp_mail
        if ( function_exists('apply_filters') ) {
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core wp_mail_content_type filter
            $filtered = apply_filters('wp_mail_content_type', $content_type);
            if ( is_string($filtered) && stripos($filtered, 'text/html') !== false ) {
                $content_type = 'text/html; charset=UTF-8';
            }
        }
        $sanitized = array();
        $count = 0;
        foreach ($headers as $h) {
            $line = trim(str_replace(array("\r","\n"), '', (string)$h));
            if ( $line === '' ) continue;
            if ( preg_match('/^(from|to|subject|content-type)\s*:/i', $line) ) continue;
            if ( stripos($line, 'x-') !== 0 && stripos($line, 'reply-to:') !== 0 ) continue;
            if ( strlen($line) > 256 ) $line = substr($line, 0, 256);
            $sanitized[] = $line;
            $count++; if ( $count >= 10 ) break;
        }
        $tag = '';
        foreach ($sanitized as $h) {
            if ( stripos($h, 'x-ses-mailer-tag:') === 0 ) {
                $tag = trim(substr($h, strlen('x-ses-mailer-tag:')));
                $tag = preg_replace('/[^A-Za-z0-9._-]/', '', $tag);
                break;
            }
        }

        $from_email = isset($this->opts['from_email']) ? trim($this->opts['from_email']) : '';
        if ( ! is_email($from_email) ) $from_email = get_option('admin_email');
        $from_name  = isset($this->opts['from_name'])  ? trim($this->opts['from_name'])  : '';
        if ( $from_name === '' ) $from_name = get_bloginfo('name');
        if ( ! is_email($from_email) ) return new WP_Error('ses_from_invalid', 'Configured From Email is invalid or missing.');

        $to_header = implode(', ', $to);
        $subject   = (string) $atts['subject'];
        $message   = (string) $atts['message'];
        $from_header = $from_name !== '' ? sprintf('"%s" <%s>', self::q($from_name), $from_email) : $from_email;

        $attachments = (array) $atts['attachments'];
        $has_attachments = ! empty($attachments);
        $is_html = stripos($content_type, 'text/html') !== false;

        $rate = isset($this->opts['rate_limit']) ? max(0, intval($this->opts['rate_limit'])) : 10;
        if ( $rate > 0 ) { usleep(intval(1000000 / max(1, $rate))); }

        $client = new SesClient();
        $send_size = strlen($message);

        if ( $has_attachments ) {
            $mime = self::build_raw_mime($from_header, $to_header, $subject, $message, $sanitized, $is_html, $attachments);
            $send_size = strlen($mime);
            $result = $client->send_raw_email($mime);
        } else {
            $text_body = self::html_to_text($message);
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
            // Log success with minimal metadata (no message body)
            $to_log = $to_header;
            $sub_log = mb_substr($subject, 0, 120);
            if ( $tag !== '' ) {
                LogViewer::log(sprintf('SUCCESS tag=%s to=%s subject="%s" bytes=%d', $tag, $to_log, $sub_log, $send_size));
            } else {
                LogViewer::log(sprintf('SUCCESS to=%s subject="%s" bytes=%d', $to_log, $sub_log, $send_size));
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

    public static function q($text) {
        if ($text === '' || preg_match('/^[\x20-\x7E]+$/', $text)) return $text;
        return '=?UTF-8?B?' . base64_encode($text) . '?=';
    }
    public static function qs($subject) {
        if ($subject === '' || preg_match('/^[\x20-\x7E]+$/', $subject)) return $subject;
        return '=?UTF-8?B?' . base64_encode($subject) . '?=';
    }

    public static function build_raw_mime($from_header, $to_header, $subject, $message, $sanitized_headers, $is_html, $attachments) {
        $boundary_mixed = '=_SesMailer_m_' . md5(uniqid('', true));
        $boundary_alt   = '=_SesMailer_a_' . md5(uniqid('', true));

        $mime  = "";
        $mime .= "From: {$from_header}\r\n";
        $mime .= "To: {$to_header}\r\n";
        $mime .= "Subject: " . self::qs($subject) . "\r\n";
        $mime .= "MIME-Version: 1.0\r\n";

        $has_attachments = ! empty($attachments);

        if ( $has_attachments ) {
            $mime .= "Content-Type: multipart/mixed; boundary=\"{$boundary_mixed}\"\r\n";
        } elseif ( $is_html ) {
            $mime .= "Content-Type: multipart/alternative; boundary=\"{$boundary_alt}\"\r\n";
        } else {
            $mime .= "Content-Type: text/plain; charset=UTF-8\r\n";
        }
        foreach ($sanitized_headers as $h) { $mime .= $h . "\r\n"; }
        $mime .= "\r\n";

        $text_body = self::html_to_text($message);
        $html_body = $is_html ? $message : '';

        if ( $has_attachments ) {
            if ( $is_html ) {
                $mime .= "--{$boundary_mixed}\r\n";
                $mime .= "Content-Type: multipart/alternative; boundary=\"{$boundary_alt}\"\r\n\r\n";
                $mime .= "--{$boundary_alt}\r\n";
                $mime .= "Content-Type: text/plain; charset=UTF-8\r\n";
                $mime .= "Content-Transfer-Encoding: base64\r\n\r\n";
                $mime .= chunk_split(base64_encode($text_body)) . "\r\n";
                $mime .= "--{$boundary_alt}\r\n";
                $mime .= "Content-Type: text/html; charset=UTF-8\r\n";
                $mime .= "Content-Transfer-Encoding: base64\r\n\r\n";
                $mime .= chunk_split(base64_encode($html_body)) . "\r\n";
                $mime .= "--{$boundary_alt}--\r\n";
            } else {
                $mime .= "--{$boundary_mixed}\r\n";
                $mime .= "Content-Type: text/plain; charset=UTF-8\r\n";
                $mime .= "Content-Transfer-Encoding: base64\r\n\r\n";
                $mime .= chunk_split(base64_encode($text_body)) . "\r\n";
            }
            foreach ($attachments as $path) {
                $path = (string) $path;
                if ( $path === '' || ! @is_readable($path) ) continue;
                $filename = basename($path);
                $ctype = function_exists('wp_check_filetype') ? (wp_check_filetype($filename)['type'] ?? '') : '';
                if ( ! $ctype || $ctype === '' ) { $ctype = function_exists('mime_content_type') ? @mime_content_type($path) : ''; }
                if ( ! $ctype || $ctype === '' ) { $ctype = 'application/octet-stream'; }
                $data = @file_get_contents($path);
                if ( $data === false ) continue;
                $mime .= "--{$boundary_mixed}\r\n";
                $mime .= "Content-Type: {$ctype}; name=\"{$filename}\"\r\n";
                $mime .= "Content-Transfer-Encoding: base64\r\n";
                $mime .= "Content-Disposition: attachment; filename=\"{$filename}\"\r\n\r\n";
                $mime .= chunk_split(base64_encode($data)) . "\r\n";
            }
            $mime .= "--{$boundary_mixed}--\r\n";
        } else {
            if ( $is_html ) {
                $mime .= "--{$boundary_alt}\r\n";
                $mime .= "Content-Type: text/plain; charset=UTF-8\r\n";
                $mime .= "Content-Transfer-Encoding: base64\r\n\r\n";
                $mime .= chunk_split(base64_encode($text_body)) . "\r\n";
                $mime .= "--{$boundary_alt}\r\n";
                $mime .= "Content-Type: text/html; charset=UTF-8\r\n";
                $mime .= "Content-Transfer-Encoding: base64\r\n\r\n";
                $mime .= chunk_split(base64_encode($html_body)) . "\r\n";
                $mime .= "--{$boundary_alt}--\r\n";
            } else {
                $mime .= $text_body;
            }
        }

        return $mime;
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
