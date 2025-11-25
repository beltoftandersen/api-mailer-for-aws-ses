<?php
namespace SesMailer\Admin;
if ( ! defined('ABSPATH') ) { exit; }

use SesMailer\Support\Options;
use SesMailer\Api\SesClient;
use SesMailer\Logging\LogViewer;

class AdminPage {
    const PAGE_SLUG   = 'ses-mailer';
    const SECTION_ID  = 'ses_mailer_main_section';
    const OPTION      = Options::OPTION;
    const SECRET_MASK = '__SES_MASK__';

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'add_menu']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
    }

    public static function add_menu() {
        add_options_page(
            __('API Mailer for AWS SES', 'api-mailer-for-aws-ses'),
            __('API Mailer for AWS SES', 'api-mailer-for-aws-ses'),
            'manage_options',
            self::PAGE_SLUG,
            [__CLASS__, 'render_page']
        );
    }

    public static function register_settings() {
        register_setting(
            'ses_mailer_settings_group',
            self::OPTION,
            array(
                'type'              => 'array',
                'sanitize_callback' => [__CLASS__, 'sanitize'],
                'default'           => Options::defaults(),
                'show_in_rest'      => false,
            )
        );

        add_settings_section(
            self::SECTION_ID,
            __('Amazon SES API Settings', 'api-mailer-for-aws-ses'),
            function () {
                echo '<p>' . esc_html__('This plugin sends email via Amazon SES API. SMTP is not used.', 'api-mailer-for-aws-ses') . '</p>';
            },
            self::PAGE_SLUG
        );

        self::add_checkbox('enable_mailer', __('Enable Mailer (use SES API)', 'api-mailer-for-aws-ses'));
        self::add_text('access_key', __('Access Key ID', 'api-mailer-for-aws-ses'), __('Your AWS Access Key ID', 'api-mailer-for-aws-ses'));
        self::add_secret('secret_key', __('Secret Access Key', 'api-mailer-for-aws-ses'));
        self::add_region('region', __('Region', 'api-mailer-for-aws-ses'));
        self::add_email('from_email', __('From Email (verified in SES)', 'api-mailer-for-aws-ses'), get_option('admin_email'));
        self::add_text('from_name', __('From Name', 'api-mailer-for-aws-ses'), get_bloginfo('name'));
        self::add_email('reply_to', __('Reply-To Email', 'api-mailer-for-aws-ses'), '');
        self::add_checkbox('force_from', __('Force From on all emails', 'api-mailer-for-aws-ses'));
        self::add_number('rate_limit', __('Rate Limit (per second)', 'api-mailer-for-aws-ses'), 10);
        self::add_textarea('custom_headers', __('Custom Headers (X-*, one per line)', 'api-mailer-for-aws-ses'), 'X-Tag: Value');
        self::add_checkbox('disable_logging', __('Disable logging in production', 'api-mailer-for-aws-ses'));
        self::add_checkbox('background_send', __('Send emails in background (queue)', 'api-mailer-for-aws-ses'));
        self::add_checkbox('cleanup_on_uninstall', __('Clean up plugin data on deletion', 'api-mailer-for-aws-ses'));
        self::add_checkbox('use_config_env', __('Read AWS credentials from wp-config', 'api-mailer-for-aws-ses'));
    }

    public static function sanitize($input) { return Options::sanitize($input, self::SECRET_MASK); }

    public static function render_page() {
        if ( ! current_user_can('manage_options') ) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'api-mailer-for-aws-ses'));
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view switcher, sanitized via sanitize_key
        $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'settings';

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('API Mailer for AWS SES', 'api-mailer-for-aws-ses') . '</h1>';
        echo '<h2 class="nav-tab-wrapper">';
        printf('<a href="%s" class="nav-tab %s">%s</a>',
            esc_url(admin_url('options-general.php?page=' . self::PAGE_SLUG . '&tab=settings')),
            $tab === 'settings' ? 'nav-tab-active' : '',
            esc_html__('Settings', 'api-mailer-for-aws-ses'));
        printf('<a href="%s" class="nav-tab %s">%s</a>',
            esc_url(admin_url('options-general.php?page=' . self::PAGE_SLUG . '&tab=test')),
            $tab === 'test' ? 'nav-tab-active' : '',
            esc_html__('Test', 'api-mailer-for-aws-ses'));
        printf('<a href="%s" class="nav-tab %s">%s</a>',
            esc_url(admin_url('options-general.php?page=' . self::PAGE_SLUG . '&tab=status')),
            $tab === 'status' ? 'nav-tab-active' : '',
            esc_html__('Status', 'api-mailer-for-aws-ses'));
        printf('<a href="%s" class="nav-tab %s">%s</a>',
            esc_url(admin_url('options-general.php?page=' . self::PAGE_SLUG . '&tab=logs')),
            $tab === 'logs' ? 'nav-tab-active' : '',
            esc_html__('Logs', 'api-mailer-for-aws-ses'));
        echo '</h2>';

        echo '<div class="tab-content">';
        switch ($tab) {
            case 'test':   self::render_test();   break;
            case 'status': self::render_status(); break;
            case 'logs':   self::render_logs();   break;
            default:       self::render_settings(); break;
        }
        echo '</div></div>';
    }

    private static function render_settings() {
        echo '<form method="post" action="options.php">';
        settings_fields('ses_mailer_settings_group');
        do_settings_sections(self::PAGE_SLUG);
        submit_button(__('Save Settings', 'api-mailer-for-aws-ses'));
        echo '</form>';
    }

    private static function render_test() {
        if ( isset($_POST['ses_test_email']) && check_admin_referer('ses_send_test') ) {
            $to   = sanitize_email(wp_unslash($_POST['ses_test_email']));
            $headers = array('X-Ses-Mailer-Tag: TEST');
            $sent = wp_mail($to, 'SES Mailer Test', 'This is a test email via SES API.', $headers);
            if ( is_wp_error($sent) ) {
                $msg = WP_DEBUG ? $sent->get_error_message() : __('An error occurred while sending. See Logs.', 'api-mailer-for-aws-ses');
                echo '<div class="notice notice-error"><p><strong>' . esc_html__('Failed to send test email:', 'api-mailer-for-aws-ses') . '</strong> ' . esc_html($msg) . '</p></div>';
            } elseif ( $sent === true ) {
                $opts = get_option(self::OPTION, Options::defaults());
                if ( ! empty($opts['background_send']) ) {
                    echo '<div class="notice notice-success"><p>' . esc_html__('Test email queued. It will be sent in the background.', 'api-mailer-for-aws-ses') . '</p></div>';
                } else {
                    echo '<div class="notice notice-success"><p>' . esc_html__('Test email sent!', 'api-mailer-for-aws-ses') . '</p></div>';
                }
            } else {
                echo '<div class="notice notice-error"><p>' . esc_html__('Failed to send test email. Check Logs tab for details.', 'api-mailer-for-aws-ses') . '</p></div>';
            }
        }
        echo '<form method="post">';
        wp_nonce_field('ses_send_test');
        echo '<p><label>' . esc_html__('Send a test email to:', 'api-mailer-for-aws-ses') . '</label> ';
        echo '<input type="email" name="ses_test_email" class="regular-text" placeholder="' . esc_attr(get_option('admin_email')) . '" required></p>';
        submit_button(__('Send Test', 'api-mailer-for-aws-ses'));
        echo '</form>';
    }

    private static function render_status() {
        $quota = null;
        if ( isset($_POST['ses_test_connection']) && check_admin_referer('ses_test_connection_action') ) {
            $client = new SesClient();
            $res = $client->get_send_quota();
            if ( is_wp_error($res) ) {
                $msg = WP_DEBUG ? $res->get_error_message() : __('Could not connect to SES. See Logs.', 'api-mailer-for-aws-ses');
                echo '<div class="notice notice-error"><p><strong>' . esc_html__('SES Connection Failed:', 'api-mailer-for-aws-ses') . '</strong> ' . esc_html($msg) . '</p></div>';
                $data = $res->get_error_data();
                if ( WP_DEBUG && ( ! empty($data['status']) || ! empty($data['body']) ) ) {
                    echo '<pre class="ses-debug">';
                    if ( ! empty($data['status']) ) echo 'HTTP Status: ' . esc_html($data['status']) . "\n\n";
                    if ( ! empty($data['body']) )   echo esc_html($data['body']);
                    echo '</pre>';
                }
            } else {
                echo '<div class="notice notice-success"><p><strong>' . esc_html__('SES Connection Successful', 'api-mailer-for-aws-ses') . '</strong></p></div>';
                $quota = array(
                    'Max24HourSend'   => number_format((float) $res['Max24HourSend'], 0, '.', ''),
                    'MaxSendRate'     => number_format((float) $res['MaxSendRate'], 0, '.', ''),
                    'SentLast24Hours' => number_format((float) $res['SentLast24Hours'], 0, '.', ''),
                );
            }
        }

        $opts = get_option(self::OPTION, Options::defaults());
        echo '<h3>' . esc_html__('Configuration Status', 'api-mailer-for-aws-ses') . '</h3>';
        echo '<ul>';
        $use_cfg = !empty($opts['use_config_env']);
        if ( $use_cfg ) {
            $ak = defined('SES_MAILER_ACCESS_KEY') ? constant('SES_MAILER_ACCESS_KEY') : getenv('SES_MAILER_ACCESS_KEY');
            $sk = defined('SES_MAILER_SECRET_KEY') ? constant('SES_MAILER_SECRET_KEY') : getenv('SES_MAILER_SECRET_KEY');
            $rg = defined('SES_MAILER_REGION') ? constant('SES_MAILER_REGION') : getenv('SES_MAILER_REGION');
            $ak_ok = is_string($ak) && $ak !== '';
            $sk_ok = is_string($sk) && $sk !== '';
            $rg_ok = is_string($rg) && Options::is_valid_region($rg);
            echo '<li>' . ($ak_ok ? '✅ ' : '❌ ') . esc_html__('Access Key (wp-config/env)', 'api-mailer-for-aws-ses') . '</li>';
            echo '<li>' . ($sk_ok ? '✅ ' : '❌ ') . esc_html__('Secret Key (wp-config/env)', 'api-mailer-for-aws-ses') . '</li>';
            echo '<li>' . ($rg_ok ? '✅ ' : '❌ ') . esc_html__('Region (wp-config/env)', 'api-mailer-for-aws-ses') . '</li>';
        } else {
            echo '<li>' . (!empty($opts['access_key']) ? '✅ ' : '❌ ') . esc_html__('Access Key', 'api-mailer-for-aws-ses') . '</li>';
            echo '<li>' . (!empty($opts['secret_key']) ? '✅ ' : '❌ ') . esc_html__('Secret Key', 'api-mailer-for-aws-ses') . '</li>';
            echo '<li>' . (Options::is_valid_region($opts['region']) ? '✅ ' : '❌ ') . esc_html__('Region', 'api-mailer-for-aws-ses') . '</li>';
        }
        $from = !empty($opts['from_email']) ? $opts['from_email'] : get_option('admin_email');
        echo '<li>' . (is_email($from) ? '✅ ' : '❌ ') . esc_html__('From Email', 'api-mailer-for-aws-ses') . '</li>';
        $bg = !empty($opts['background_send']);
        $using_as = $bg && \SesMailer\Background\Queue::is_action_scheduler_available();
        if ( $bg ) {
            echo '<li>✅ ' . esc_html__('Background Sending', 'api-mailer-for-aws-ses') . ': ' . ($using_as ? 'Action Scheduler' : 'wp_cron fallback') . '</li>';
        } else {
            echo '<li>❌ ' . esc_html__('Background Sending', 'api-mailer-for-aws-ses') . '</li>';
        }
        if ( ! empty($opts['disable_logging']) ) {
            echo '<li>⚠️ ' . esc_html__('Logging is disabled', 'api-mailer-for-aws-ses') . '</li>';
        }
        echo '</ul>';

        echo '<form method="post" style="margin-top:1em;">';
        wp_nonce_field('ses_test_connection_action');
        submit_button(__('Test SES Connection', 'api-mailer-for-aws-ses'), 'secondary', 'ses_test_connection', false);
        echo '</form>';
        if ( is_array($quota) ) {
            echo '<div style="margin-top:1em;max-width:600px">';
            echo '<table class="widefat striped"><tbody>';
            echo '<tr><td>' . esc_html__('Max 24 Hour Send', 'api-mailer-for-aws-ses') . '</td><td>' . esc_html($quota['Max24HourSend']) . '</td></tr>';
            echo '<tr><td>' . esc_html__('Max Send Rate (per sec)', 'api-mailer-for-aws-ses') . '</td><td>' . esc_html($quota['MaxSendRate']) . '</td></tr>';
            echo '<tr><td>' . esc_html__('Sent Last 24 Hours', 'api-mailer-for-aws-ses') . '</td><td>' . esc_html($quota['SentLast24Hours']) . '</td></tr>';
            echo '</tbody></table>';
            echo '</div>';
        }
    }

    private static function render_logs() { LogViewer::render(); }

    private static function add_checkbox($key, $label) {
        add_settings_field($key, esc_html($label), function () use ($key) {
            $opts = get_option(self::OPTION, Options::defaults());
            $val = isset($opts[$key]) ? $opts[$key] : '0';
            printf('<input type="checkbox" name="%1$s[%2$s]" value="1" %3$s />',
                esc_attr(self::OPTION), esc_attr($key), checked($val, '1', false));
        }, self::PAGE_SLUG, self::SECTION_ID);
    }
    private static function add_text($key, $label, $placeholder='') {
        add_settings_field($key, esc_html($label), function () use ($key, $placeholder) {
            $opts = get_option(self::OPTION, Options::defaults());
            $val = isset($opts[$key]) ? $opts[$key] : '';
            $disabled = (!empty($opts['use_config_env']) && in_array($key, array('access_key'), true));
            printf('<input type="text" class="regular-text" name="%1$s[%2$s]" value="%3$s" placeholder="%4$s" %5$s />',
                esc_attr(self::OPTION), esc_attr($key), esc_attr($val), esc_attr($placeholder), $disabled ? 'disabled' : '');
            if ( $disabled ) {
                echo '<p class="description">' . esc_html__('Using credentials from wp-config.', 'api-mailer-for-aws-ses') . '</p>';
            }
        }, self::PAGE_SLUG, self::SECTION_ID);
    }
    private static function add_secret($key, $label) {
        add_settings_field($key, esc_html($label), function () use ($key) {
            $opts = get_option(self::OPTION, Options::defaults());
            $stored = isset($opts[$key]) ? $opts[$key] : '';
            $value = ($stored !== '') ? self::SECRET_MASK : '';
            $disabled = (!empty($opts['use_config_env']) && in_array($key, array('secret_key'), true));
            printf('<input type="password" class="regular-text" name="%1$s[%2$s]" value="%3$s" placeholder="%4$s" autocomplete="new-password" %5$s />',
                esc_attr(self::OPTION), esc_attr($key), esc_attr($value), esc_attr(__('Your AWS Secret Access Key', 'api-mailer-for-aws-ses')), $disabled ? 'disabled' : '');
            if ( $disabled ) {
                echo '<p class="description">' . esc_html__('Using credentials from wp-config.', 'api-mailer-for-aws-ses') . '</p>';
            } else {
                echo '<p class="description">' . ($stored !== '' ? esc_html__('A secret is stored. Leave as •••• to keep, clear to remove.', 'api-mailer-for-aws-ses') : esc_html__('No secret stored yet.', 'api-mailer-for-aws-ses')) . '</p>';
            }
        }, self::PAGE_SLUG, self::SECTION_ID);
    }
    private static function add_email($key, $label, $placeholder='') {
        add_settings_field($key, esc_html($label), function () use ($key, $placeholder) {
            $opts = get_option(self::OPTION, Options::defaults());
            $val = isset($opts[$key]) ? $opts[$key] : '';
            printf('<input type="email" class="regular-text" name="%1$s[%2$s]" value="%3$s" placeholder="%4$s" />',
                esc_attr(self::OPTION), esc_attr($key), esc_attr($val), esc_attr($placeholder));
        }, self::PAGE_SLUG, self::SECTION_ID);
    }
    private static function add_number($key, $label, $placeholder=0) {
        add_settings_field($key, esc_html($label), function () use ($key, $placeholder) {
            $opts = get_option(self::OPTION, Options::defaults());
            $val = isset($opts[$key]) ? $opts[$key] : '';
            printf('<input type="number" class="small-text" name="%1$s[%2$s]" value="%3$s" placeholder="%4$s" min="0" step="1" />',
                esc_attr(self::OPTION), esc_attr($key), esc_attr($val), esc_attr($placeholder));
        }, self::PAGE_SLUG, self::SECTION_ID);
    }
    private static function add_textarea($key, $label, $placeholder='') {
        add_settings_field($key, esc_html($label), function () use ($key, $placeholder) {
            $opts = get_option(self::OPTION, Options::defaults());
            $val = isset($opts[$key]) ? $opts[$key] : '';
            printf('<textarea name="%1$s[%2$s]" rows="6" class="large-text code" placeholder="%3$s">%4$s</textarea>',
                esc_attr(self::OPTION), esc_attr($key), esc_attr($placeholder), esc_textarea($val));
        }, self::PAGE_SLUG, self::SECTION_ID);
    }
    private static function add_region($key, $label) {
        add_settings_field($key, esc_html($label), function () use ($key) {
            $opts = get_option(self::OPTION, Options::defaults());
            $val = isset($opts[$key]) ? $opts[$key] : '';
            $disabled = (!empty($opts['use_config_env']) && in_array($key, array('region'), true));
            echo '<select name="' . esc_attr(self::OPTION) . '[' . esc_attr($key) . ']" class="regular-text" ' . ($disabled ? 'disabled' : '') . '>';
            echo '<option value="">' . esc_html__('Select a region…', 'api-mailer-for-aws-ses') . '</option>';
            foreach ( Options::regions() as $code => $title ) {
                printf('<option value="%1$s" %2$s>%3$s</option>',
                    esc_attr($code), selected($val, $code, false),
                    esc_html($title . ' (' . $code . ')'));
            }
            echo '</select>';
            if ( $disabled ) {
                echo '<p class="description">' . esc_html__('Using region from wp-config.', 'api-mailer-for-aws-ses') . '</p>';
            }
        }, self::PAGE_SLUG, self::SECTION_ID);
    }
}
