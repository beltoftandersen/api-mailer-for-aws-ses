<?php
if ( ! defined('WP_UNINSTALL_PLUGIN') ) { exit; }

function ses_mailer_uninstall() {
    $opts = get_option('ses_mailer_options');
    $cleanup = is_array($opts) && isset($opts['cleanup_on_uninstall']) && ($opts['cleanup_on_uninstall'] === '1' || $opts['cleanup_on_uninstall'] === 1);

    if ( ! $cleanup ) {
        return;
    }

    delete_option('ses_mailer_options');
    if ( is_multisite() ) { delete_site_option('ses_mailer_options'); }

    // Clean up any queued job options
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
            $wpdb->esc_like('ses_mailer_job_') . '%'
        )
    );

    // Clean up scheduled cron events
    wp_clear_scheduled_hook('ses_mailer_send_job');
    wp_clear_scheduled_hook('ses_mailer_cleanup_jobs');
}

ses_mailer_uninstall();
