<?php
namespace SesMailer\Support;
if ( ! defined('ABSPATH') ) { exit; }

class Options {
    const OPTION = 'ses_mailer_options';

    public static function defaults() {
        return array(
            'enable_mailer'  => '0',
            'force_from'     => '0',
            'access_key'     => '',
            'secret_key'     => '',
            'region'         => '',
            'from_email'     => get_option('admin_email'),
            'from_name'      => get_bloginfo('name'),
            'reply_to'       => '',
            'rate_limit'     => '10',
            'custom_headers' => '',
            'disable_logging'=> '0',
            'background_send'=> '0',
            'cleanup_on_uninstall' => '0',
            'use_config_env' => '0',
        );
    }

    public static function constants_in_use() {
        // Deprecated meaning: this now only reflects presence of constants/env,
        // not whether the plugin will use them. Usage is controlled by use_config_env.
        return ( defined('SES_MAILER_ACCESS_KEY') || getenv('SES_MAILER_ACCESS_KEY') )
            || ( defined('SES_MAILER_SECRET_KEY') || getenv('SES_MAILER_SECRET_KEY') )
            || ( defined('SES_MAILER_REGION')     || getenv('SES_MAILER_REGION') )
            || ( defined('SES_MAILER_SESSION_TOKEN') || getenv('SES_MAILER_SESSION_TOKEN') );
    }

    public static function sanitize($input, $secret_mask='__SES_MASK__') {
        $input = is_array($input) ? wp_unslash($input) : array();
        $out   = array();
        $prev  = get_option(self::OPTION, self::defaults());
        // Use config/env only if explicitly enabled via setting
        $use_config_env = isset($input['use_config_env'])
            ? (($input['use_config_env'] === '1' || $input['use_config_env'] === 1 || $input['use_config_env'] === true) ? '1' : '0')
            : (isset($prev['use_config_env']) ? $prev['use_config_env'] : '0');
        $using_constants = ($use_config_env === '1');

        $fields = array(
            'enable_mailer'  => 'bool',
            'force_from'     => 'bool',
            'access_key'     => 'text',
            'secret_key'     => 'password',
            'region'         => 'text',
            'from_email'     => 'email',
            'from_name'      => 'text',
            'reply_to'       => 'email',
            'rate_limit'     => 'int',
            'custom_headers' => 'textarea',
            'disable_logging'=> 'bool',
            'background_send'=> 'bool',
            'cleanup_on_uninstall' => 'bool',
            'use_config_env' => 'bool',
        );

        foreach ($fields as $key => $type) {
            $has = array_key_exists($key, $input);
            $val = $has ? $input[$key] : null;

            switch ($type) {
                case 'bool':
                    $out[$key] = ($has && ($val === '1' || $val === 1 || $val === true || $val === 'on')) ? '1' : '0';
                    break;
                case 'int':
                    $out[$key] = is_numeric($val) ? (string) absint($val) : (isset($prev[$key]) ? $prev[$key] : '10');
                    break;
                case 'email':
                    $clean = $has ? sanitize_email((string) $val) : '';
                    $out[$key] = ($clean !== '') ? $clean : '';
                    break;
                case 'password':
                    if ( $using_constants ) { $out[$key] = ''; }
                    else {
                        if ( $has ) {
                            $raw = (string) $val;
                            if ( $raw === $secret_mask ) { $out[$key] = isset($prev[$key]) ? (string) $prev[$key] : ''; }
                            else {
                                $clean = sanitize_text_field($raw);
                                $clean = preg_replace('/\s+/', '', $clean); // remove any whitespace users might paste
                                $out[$key] = $clean;
                            }
                        } else { $out[$key] = ''; }
                    }
                    break;
                default:
                    if ( $using_constants && in_array($key, array('access_key','region'), true) ) {
                        $out[$key] = '';
                    } else {
                        if ( $key === 'region' ) {
                            $clean = $has ? sanitize_text_field((string)$val) : '';
                            $clean = preg_replace('/\s+/', '', $clean);
                            $out[$key] = self::is_valid_region($clean) ? $clean : '';
                        } elseif ( $type === 'textarea' ) {
                            $lines = array();
                            if ( $has ) {
                                $raw_lines = explode("\n", str_replace("\r", "\n", (string) $val));
                                $count = 0;
                                foreach ($raw_lines as $line) {
                                    $line = trim(str_replace(array("\r","\n"), '', $line));
                                    if ( $line === '' ) continue;
                                    if ( stripos($line, 'x-') !== 0 ) continue;
                                    if ( strlen($line) > 256 ) $line = substr($line, 0, 256);
                                    $lines[] = $line;
                                    $count++; if ( $count >= 10 ) break;
                                }
                            }
                            $out[$key] = implode("\n", $lines);
                        } else {
                            $tmp = $has ? sanitize_text_field((string) $val) : '';
                            if ( in_array($key, array('access_key'), true) ) { $tmp = preg_replace('/\s+/', '', $tmp); }
                            $out[$key] = $tmp;
                        }
                    }
                    break;
            }
        }

        if ( empty($out['rate_limit']) ) $out['rate_limit'] = '10';
        if ( empty($out['from_name']) )  $out['from_name']  = get_bloginfo('name');
        if ( empty($out['from_email']) ) $out['from_email'] = get_option('admin_email');

        return $out;
    }

    public static function regions() {
        return array(
            'us-east-1'      => 'US East (N. Virginia)',
            'us-east-2'      => 'US East (Ohio)',
            'us-west-2'      => 'US West (Oregon)',
            'ca-central-1'   => 'Canada (Central)',
            'sa-east-1'      => 'South America (São Paulo)',
            'eu-west-1'      => 'Europe (Ireland)',
            'eu-west-2'      => 'Europe (London)',
            'eu-west-3'      => 'Europe (Paris)',
            'eu-central-1'   => 'Europe (Frankfurt)',
            'eu-north-1'     => 'Europe (Stockholm)',
            'eu-central-2'   => 'Europe (Zurich)',
            'ap-south-1'     => 'Asia Pacific (Mumbai)',
            'ap-south-2'     => 'Asia Pacific (Hyderabad)',
            'ap-southeast-1' => 'Asia Pacific (Singapore)',
            'ap-southeast-2' => 'Asia Pacific (Sydney)',
            'ap-southeast-3' => 'Asia Pacific (Jakarta)',
            'ap-southeast-4' => 'Asia Pacific (Melbourne)',
            'ap-northeast-1' => 'Asia Pacific (Tokyo)',
            'ap-northeast-2' => 'Asia Pacific (Seoul)',
            'ap-northeast-3' => 'Asia Pacific (Osaka)',
            'me-south-1'     => 'Middle East (Bahrain)',
            'me-central-1'   => 'Middle East (UAE)',
            'af-south-1'     => 'Africa (Cape Town)',
        );
    }
    public static function is_valid_region($region) {
        $region = (string) $region;
        if ( $region === '' ) return false;
        return array_key_exists($region, self::regions());
    }
}
