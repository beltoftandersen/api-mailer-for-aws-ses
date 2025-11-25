<?php
namespace SesMailer;
if ( ! defined('ABSPATH') ) { exit; }

use SesMailer\Admin\AdminPage;
use SesMailer\Logging\LogViewer;
use SesMailer\Mail\Mailer;
use SesMailer\Background\Queue;

class Plugin {
    public static function init() {
        AdminPage::init();
        LogViewer::init();
        Queue::init();
        add_action('plugins_loaded', function(){ new Mailer(); }, 20);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_admin_assets']);
    }
    public static function enqueue_admin_assets($hook) {
        if (strpos($hook, 'settings_page_ses-mailer') === false) return;
        wp_enqueue_style('ses-mailer-admin', SES_MAILER_URL . 'assets/css/admin.css', [], SES_MAILER_VER);
        wp_enqueue_script('ses-mailer-admin', SES_MAILER_URL . 'assets/js/admin.js', ['jquery'], SES_MAILER_VER, true);
    }
}
