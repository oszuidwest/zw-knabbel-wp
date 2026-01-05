<?php
/**
 * Uninstall script for Knabbel WP plugin.
 *
 * Cleans up temporary data when the plugin is deleted.
 * Settings and story data are preserved for potential reinstallation.
 *
 * @package KnabbelWP
 * @since   0.0.8
 */

// Exit if not called by WordPress uninstaller.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Clean up transients (session cache).
$wpdb->query(
	"DELETE FROM {$wpdb->options}
	WHERE option_name LIKE '_transient_knabbel_session_%'
	OR option_name LIKE '_transient_timeout_knabbel_session_%'"
);

// Clean up recent errors log (temporary debug data).
delete_option( 'knabbel_recent_errors' );

// Clean up any remaining Action Scheduler jobs.
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'knabbel_process_story', array(), 'zw-knabbel-wp' );
}

// Note: The following data is intentionally preserved:
// - Option: knabbel_settings (user configuration)
// - Post meta: _zw_knabbel_story_state (story processing history)
// - Post meta: _zw_knabbel_send_to_babbel (user selections)
