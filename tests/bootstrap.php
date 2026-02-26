<?php
/**
 * PHPUnit bootstrap for API Mailer for AWS SES.
 *
 * Provides minimal WordPress stubs so unit tests can run without
 * a full WordPress installation.
 */

// Simulate ABSPATH
if ( ! defined('ABSPATH') ) {
    define('ABSPATH', sys_get_temp_dir() . '/wp-test-abspath/');
}
if ( ! defined('WPINC') ) {
    define('WPINC', 'wp-includes');
}
if ( ! defined('WP_CONTENT_DIR') ) {
    define('WP_CONTENT_DIR', sys_get_temp_dir() . '/wp-test-content');
}
if ( ! defined('DAY_IN_SECONDS') ) {
    define('DAY_IN_SECONDS', 86400);
}

// Stub global state for WordPress functions
global $_ses_test_options, $_ses_test_transients, $_ses_test_scheduled, $_ses_test_error_log;
global $_ses_test_fail_add_option, $_ses_test_fail_update_option, $_ses_test_fail_schedule_single;
$_ses_test_options    = array();
$_ses_test_transients = array();
$_ses_test_scheduled  = array();
$_ses_test_error_log  = array();
$_ses_test_fail_add_option      = false;
$_ses_test_fail_update_option   = false;
$_ses_test_fail_schedule_single = false;

// --- WordPress function stubs ---

function get_option($key, $default = false) {
    global $_ses_test_options;
    return array_key_exists($key, $_ses_test_options) ? $_ses_test_options[$key] : $default;
}
function add_option($key, $value, $deprecated = '', $autoload = 'yes') {
    global $_ses_test_options, $_ses_test_fail_add_option;
    if ( $_ses_test_fail_add_option ) return false;
    if ( array_key_exists($key, $_ses_test_options) ) return false;
    $_ses_test_options[$key] = $value;
    return true;
}
function update_option($key, $value, $autoload = null) {
    global $_ses_test_options, $_ses_test_fail_update_option;
    if ( $_ses_test_fail_update_option ) return false;
    $_ses_test_options[$key] = $value;
    return true;
}
function delete_option($key) {
    global $_ses_test_options;
    unset($_ses_test_options[$key]);
    return true;
}
function get_transient($key) {
    global $_ses_test_transients;
    return isset($_ses_test_transients[$key]) ? $_ses_test_transients[$key] : false;
}
function set_transient($key, $value, $expiration = 0) {
    global $_ses_test_transients;
    $_ses_test_transients[$key] = $value;
    return true;
}
function delete_transient($key) {
    global $_ses_test_transients;
    unset($_ses_test_transients[$key]);
    return true;
}
function get_bloginfo($show) { return 'Test Site'; }
function wp_get_upload_dir() {
    return array('basedir' => sys_get_temp_dir() . '/wp-test-uploads');
}
function wp_json_encode($data, $options = 0, $depth = 512) { return json_encode($data, $options, $depth); }
function wp_mkdir_p($path) { return @mkdir($path, 0755, true); }
function trailingslashit($str) { return rtrim($str, '/\\') . '/'; }
function wp_next_scheduled($hook, $args = array()) {
    global $_ses_test_scheduled;
    $key = $hook . '|' . serialize($args);
    return isset($_ses_test_scheduled[$key]) ? $_ses_test_scheduled[$key] : false;
}
function wp_schedule_single_event($timestamp, $hook, $args = array()) {
    global $_ses_test_scheduled, $_ses_test_fail_schedule_single;
    if ( $_ses_test_fail_schedule_single ) return false;
    $key = $hook . '|' . serialize($args);
    $_ses_test_scheduled[$key] = $timestamp;
    return true;
}
function wp_schedule_event($timestamp, $recurrence, $hook, $args = array()) { return true; }
function wp_generate_uuid4() { return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
    mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff),
    mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000,
    mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)); }
function sanitize_email($email) { return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : ''; }
function is_email($email) { return (bool) filter_var($email, FILTER_VALIDATE_EMAIL); }
function is_wp_error($thing) { return $thing instanceof \WP_Error; }
function wp_salt($scheme = 'auth') { return 'test-salt-' . $scheme; }
function wp_strip_all_tags($str) { return strip_tags($str); }
function wp_parse_args($args, $defaults = array()) {
    if (is_object($args)) $args = get_object_vars($args);
    elseif (!is_array($args)) parse_str($args, $args);
    return array_merge($defaults, $args);
}
function apply_filters($tag, $value) { return $value; }
// mb_substr is a PHP built-in when mbstring extension is loaded (no stub needed)

// WP_Error stub
if ( ! class_exists('WP_Error') ) {
    class WP_Error {
        private $code;
        private $message;
        private $data;
        public function __construct($code = '', $message = '', $data = '') {
            $this->code = $code; $this->message = $message; $this->data = $data;
        }
        public function get_error_code() { return $this->code; }
        public function get_error_message() { return $this->message; }
        public function get_error_data() { return $this->data; }
    }
}

// Autoloader
spl_autoload_register(function ($class) {
    if (strpos($class, 'SesMailer\\') !== 0) return;
    $rel = substr($class, strlen('SesMailer\\'));
    $rel = str_replace('\\', DIRECTORY_SEPARATOR, $rel) . '.php';
    $file = dirname(__DIR__) . '/src/' . $rel;
    if ( file_exists($file) ) { require_once $file; }
});
