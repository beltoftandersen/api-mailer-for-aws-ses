<?php
if ( ! defined('WP_UNINSTALL_PLUGIN') ) { exit; }

function ses_mailer_uninstall() {
    // Only clean up if the user enabled the toggle in settings
    $opts = get_option('ses_mailer_options');
    $cleanup = is_array($opts) && isset($opts['cleanup_on_uninstall']) && ($opts['cleanup_on_uninstall'] === '1' || $opts['cleanup_on_uninstall'] === 1);

    if ( ! $cleanup ) {
        return;
    }

    // Delete options
    delete_option('ses_mailer_options');
    if ( is_multisite() ) { delete_site_option('ses_mailer_options'); }

    // Remove logs directory (default location). Avoid using plugin constants/classes here.
    if ( defined('SES_MAILER_LOG_DIR') ) {
        $base = rtrim(SES_MAILER_LOG_DIR, "/\\ ");
    } else {
        $uploads = wp_get_upload_dir();
        $base = ( isset($uploads['basedir']) && $uploads['basedir'] )
            ? trailingslashit($uploads['basedir']) . 'ses-mailer-logs'
            : ( defined('WP_CONTENT_DIR') ? trailingslashit(WP_CONTENT_DIR) . 'ses-mailer-logs' : trailingslashit(ABSPATH) . 'wp-content/ses-mailer-logs' );
    }
    if ( is_dir($base) ) {
        // Use WP_Filesystem only; avoid direct filesystem calls per WordPress guidelines.
        global $wp_filesystem;
        if ( ! function_exists('WP_Filesystem') ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        if ( WP_Filesystem() && $wp_filesystem ) {
            // Recursively delete directory
            $wp_filesystem->delete($base, true);
        }
        // If WP_Filesystem could not be initialized, skip removal to adhere to coding standards.
    }
}

ses_mailer_uninstall();