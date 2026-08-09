<?php
/**
 * Uninstall Gift Reporter for MemberPress
 *
 * @package MemberPressGiftReporter
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( ! current_user_can( 'activate_plugins' ) ) {
	return;
}

global $wpdb;

// Delete plugin options.
delete_option( 'mpgr_version' );
delete_option( 'mpgr_reminder_settings' );
delete_option( 'mpgr_weekly_summary_settings' );
delete_option( 'mpgr_cron_migrated_v1_6_3' );
delete_option( 'mpgr_cron_migrated_v1_6_4' );
delete_option( 'mpgr_legacy_cron_cleaned_v1_6_3' );
delete_option( 'mpgr_activation_ts' );
delete_option( 'mpgr_last_report_snapshot' );

// Delete cached report data (onboarding pulse and aging arcs).
delete_transient( 'mpgr_pulse_stats' );
delete_transient( 'mpgr_aging_arcs' );

// Clear scheduled cron events created by this plugin.
wp_clear_scheduled_hook( 'mpgr_run_gift_reminders' );
wp_clear_scheduled_hook( 'mpgr_run_weekly_summary' );
wp_clear_scheduled_hook( 'mpgr_cleanup_cache' );

// Legacy hook names cleared defensively (from earlier versions).
wp_clear_scheduled_hook( 'mpgr_check_reminders' );
wp_clear_scheduled_hook( 'mpgr_send_reminder_emails' );
wp_clear_scheduled_hook( 'mpgr_send_reminders' );
wp_clear_scheduled_hook( 'mpgr_send_queued_gift_email' );

// Delete tracking meta rows from mepr_transaction_meta.
$mepr_meta_table = $wpdb->prefix . 'mepr_transaction_meta';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall cleanup of plugin-owned meta rows
$wpdb->query(
	"DELETE FROM {$mepr_meta_table}
	 WHERE meta_key IN ('_mpgr_reminder_sent_count', '_mpgr_last_reminder_ts', '_mpgr_reminder_sent')"
);

// Delete per-user onboarding meta rows. Keys are listed explicitly rather than
// matched with LIKE 'mpgr_%' so unrelated meta can never be caught by mistake.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall cleanup of plugin-owned meta rows
$wpdb->query(
	"DELETE FROM {$wpdb->usermeta}
	 WHERE meta_key IN (
		'mpgr_welcome_dismissed',
		'mpgr_admin_bar_dismissed',
		'mpgr_cliffhanger_snooze',
		'mpgr_monday_pulse_dismissed',
		'mpgr_report_viewed'
	 )"
);

// Delete per-user rate-limit transients.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall cleanup of plugin-owned transients
$wpdb->query(
	"DELETE FROM {$wpdb->options}
	 WHERE option_name LIKE '\_transient\_mpgr\_rate\_limit\_%'
		OR option_name LIKE '\_transient\_timeout\_mpgr\_rate\_limit\_%'"
);

// Remove custom capability if it was ever granted.
$role = get_role( 'administrator' );
if ( $role ) {
	$role->remove_cap( 'view_memberpress_gift_reports' );
}
