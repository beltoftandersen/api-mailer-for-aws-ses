<?php
namespace SesMailer\Api;
if ( ! defined('ABSPATH') ) { exit; }

use WP_Error;
use SesMailer\Support\Options;

class SesClient {
    private $access_key;
    private $secret_key;
    private $session_token;
    private $region;
    private $service = 'ses';
    private $endpoint;

    public function __construct() {
        $opts = get_option(Options::OPTION, Options::defaults());
        $use_config_env = isset($opts['use_config_env']) && $opts['use_config_env'] === '1';

        if ( $use_config_env ) {
            $ak = defined('SES_MAILER_ACCESS_KEY') ? constant('SES_MAILER_ACCESS_KEY') : getenv('SES_MAILER_ACCESS_KEY');
            $sk = defined('SES_MAILER_SECRET_KEY') ? constant('SES_MAILER_SECRET_KEY') : getenv('SES_MAILER_SECRET_KEY');
            $rg = defined('SES_MAILER_REGION') ? constant('SES_MAILER_REGION') : getenv('SES_MAILER_REGION');
            $st = defined('SES_MAILER_SESSION_TOKEN') ? constant('SES_MAILER_SESSION_TOKEN') : getenv('SES_MAILER_SESSION_TOKEN');
            $this->access_key = is_string($ak) ? trim($ak) : '';
            $this->secret_key = is_string($sk) ? trim($sk) : '';
            $this->region     = is_string($rg) ? trim($rg) : '';
            $this->session_token = is_string($st) ? trim($st) : '';
        } else {
            $this->access_key = isset($opts['access_key']) ? trim($opts['access_key']) : '';
            $this->secret_key = isset($opts['secret_key']) ? trim($opts['secret_key']) : '';
            $this->region     = isset($opts['region']) ? trim($opts['region']) : '';
            $this->session_token = '';
        }

        // Extra normalization: remove any whitespace users might have pasted
        $this->access_key = preg_replace('/\s+/', '', (string) $this->access_key);
        $this->secret_key = preg_replace('/\s+/', '', (string) $this->secret_key);
        $this->region     = preg_replace('/\s+/', '', (string) $this->region);
        $this->session_token = preg_replace('/\s+/', '', (string) $this->session_token);

        if ( ! Options::is_valid_region($this->region) ) { $this->region = ''; }
        $this->endpoint = $this->region !== '' ? sprintf('https://email.%s.amazonaws.com', $this->region) : '';
    }

    public function send_raw_email($raw) {
        if ( empty($this->access_key) || empty($this->secret_key) ) {
            return new WP_Error('ses_creds_missing', 'SES credentials missing.');
        }
        if ( empty($this->endpoint) ) {
            return new WP_Error('ses_region_invalid', 'SES region missing or invalid.');
        }
        $params = array(
            'Action'          => 'SendRawEmail',
            'Version'         => '2010-12-01',
            'RawMessage.Data' => base64_encode($raw),
        );
        $response = $this->request($params);
        if ( is_wp_error($response) ) return $response;
        $code = wp_remote_retrieve_response_code($response);
        if ( $code === 200 ) return true;
        return new WP_Error('ses_api_error', 'SES API error', array(
            'status' => $code,
            'body'   => wp_remote_retrieve_body($response),
        ));
    }

    public function get_send_quota() {
        if ( empty($this->access_key) || empty($this->secret_key) ) {
            return new WP_Error('ses_creds_missing', 'SES credentials missing.');
        }
        if ( empty($this->endpoint) ) {
            return new WP_Error('ses_region_invalid', 'SES region missing or invalid.');
        }
        $params = array('Action' => 'GetSendQuota', 'Version' => '2010-12-01');
        $response = $this->request($params);
        if ( is_wp_error($response) ) return $response;
        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        if ( $code !== 200 ) {
            return new WP_Error('ses_api_error', 'SES API error', array('status' => $code, 'body' => $body));
        }
        $xml = @simplexml_load_string($body);
        if ( ! $xml ) return new WP_Error('ses_parse_error', 'Unable to parse SES response.', array('body' => $body));
        $resNode = $xml->GetSendQuotaResult ?? null;
        if ( ! $resNode ) return new WP_Error('ses_parse_error', 'Unexpected SES response.', array('body' => $body));
        return array(
            'Max24HourSend'   => (string) ($resNode->Max24HourSend   ?? ''),
            'MaxSendRate'     => (string) ($resNode->MaxSendRate     ?? ''),
            'SentLast24Hours' => (string) ($resNode->SentLast24Hours ?? ''),
        );
    }

    private function request($params) {
        $body = $this->build_query($params);

        $host_arr    = wp_parse_url($this->endpoint);
        $host        = is_array($host_arr) && isset($host_arr['host']) ? $host_arr['host'] : '';
        $uri         = '/';
        $method      = 'POST';
        $contentType = 'application/x-www-form-urlencoded; charset=utf-8';
        $amzDate     = gmdate('Ymd\THis\Z');
        $dateStamp   = gmdate('Ymd');
        $payloadHash = hash('sha256', $body);

        $canonical_headers = "content-type:{$contentType}\n" .
                             "host:{$host}\n" .
                             "x-amz-content-sha256:{$payloadHash}\n" .
                             "x-amz-date:{$amzDate}\n";
        $signed_headers = 'content-type;host;x-amz-content-sha256;x-amz-date';
        if ( $this->session_token !== '' ) {
            $canonical_headers .= 'x-amz-security-token:' . $this->session_token . "\n";
            $signed_headers    .= ';x-amz-security-token';
        }

        $canonical_request = $method . "\n" . $uri . "\n\n" . $canonical_headers . "\n" . $signed_headers . "\n" . $payloadHash;

        $algorithm       = 'AWS4-HMAC-SHA256';
        $credentialScope = $dateStamp . "/" . $this->region . "/" . $this->service . "/aws4_request";
        $string_to_sign  = $algorithm . "\n" . $amzDate . "\n" . $credentialScope . "\n" . hash('sha256', $canonical_request);

        $kDate    = hash_hmac('sha256', $dateStamp, 'AWS4' . $this->secret_key, true);
        $kRegion  = hash_hmac('sha256', $this->region, $kDate, true);
        $kService = hash_hmac('sha256', $this->service, $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = hash_hmac('sha256', $string_to_sign, $kSigning);

        $authorization = $algorithm . ' ' . sprintf(
            'Credential=%s/%s, SignedHeaders=%s, Signature=%s',
            $this->access_key, $credentialScope, $signed_headers, $signature
        );

        $headers = array(
            'Content-Type'         => $contentType,
            'Host'                 => $host,
            'X-Amz-Date'           => $amzDate,
            'X-Amz-Content-Sha256' => $payloadHash,
            'Authorization'        => $authorization,
        );
        if ( $this->session_token !== '' ) {
            $headers['X-Amz-Security-Token'] = $this->session_token;
        }

        return wp_remote_post($this->endpoint, array(
            'headers' => $headers,
            'body'    => $body,
            'timeout' => 30,
        ));
    }

    private function build_query($params) {
        ksort($params);
        $pairs = array();
        foreach ($params as $k => $v) {
            $pairs[] = rawurlencode($k). '=' . rawurlencode($v);
        }
        return str_replace('%7E','~', implode('&', $pairs));
    }
}
