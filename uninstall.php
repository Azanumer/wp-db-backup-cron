<?php
// Exit if accessed directly.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'wpdbc_options' );
delete_option( 'wpdbc_last_ok' );
delete_option( 'wpdbc_last_error' );
wp_clear_scheduled_hook( 'wpdbc_cron_hook' );

// Backup files are intentionally left in place (wp-content/uploads/wpdbc-backups)
// so uninstalling never destroys your only copy of the data.
