<?php
if ( ! defined('WP_UNINSTALL_PLUGIN') ) { exit; }

( static function () {
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

    // Clean up scheduled events and Action Scheduler actions
    wp_unschedule_hook('ses_mailer_send_job');
    wp_unschedule_hook('ses_mailer_cleanup_jobs');
    if ( function_exists('as_unschedule_all_actions') ) {
        as_unschedule_all_actions('ses_mailer_send_job');
    }

    // Remove copied queue attachments
    $uploads = wp_get_upload_dir();
    $queue_dir = ! empty($uploads['basedir']) ? trailingslashit($uploads['basedir']) . 'ses-mailer-queue' : '';
    if ( $queue_dir !== '' && is_dir($queue_dir) ) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        if ( WP_Filesystem() ) {
            global $wp_filesystem;
            $wp_filesystem->delete($queue_dir, true);
        }
    }
} )();
