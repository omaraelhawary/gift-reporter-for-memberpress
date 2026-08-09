<?php
/**
 * Tests that uninstall.php removes everything the plugin writes.
 *
 * The 1.8.0 onboarding/retention features added options, transients, and user
 * meta that the uninstall routine did not know about, so uninstalling left
 * rows behind. This seeds every key the plugin writes, runs the real uninstall
 * script, and asserts nothing survives.
 *
 * @package MemberPressGiftReporter
 */

/**
 * Uninstall cleanup test case.
 */
class UninstallTest extends MPGR_TestCase {

	/**
	 * Options the plugin writes.
	 *
	 * @var string[]
	 */
	private static $options = array(
		'mpgr_version',
		'mpgr_reminder_settings',
		'mpgr_weekly_summary_settings',
		'mpgr_cron_migrated_v1_6_3',
		'mpgr_cron_migrated_v1_6_4',
		'mpgr_legacy_cron_cleaned_v1_6_3',
		'mpgr_activation_ts',
		'mpgr_last_report_snapshot',
		'mpgr_summary_cache_version',
	);

	/**
	 * Transients the plugin writes.
	 *
	 * @var string[]
	 */
	private static $transients = array(
		'mpgr_pulse_stats',
		'mpgr_aging_arcs',
	);

	/**
	 * Per-user meta keys the plugin writes.
	 *
	 * @var string[]
	 */
	private static $user_meta_keys = array(
		'mpgr_welcome_dismissed',
		'mpgr_admin_bar_dismissed',
		'mpgr_cliffhanger_snooze',
		'mpgr_monday_pulse_dismissed',
		'mpgr_report_viewed',
	);

	/**
	 * Transaction meta keys the plugin writes.
	 *
	 * @var string[]
	 */
	private static $transaction_meta_keys = array(
		'_mpgr_reminder_sent_count',
		'_mpgr_last_reminder_ts',
		'_mpgr_reminder_sent',
	);

	/**
	 * Run the plugin's uninstall script in-process.
	 *
	 * The cache is flushed afterwards because the script deletes user meta and
	 * rate-limit transients with direct SQL, which leaves WordPress's in-memory
	 * caches stale. A real uninstall runs in its own request with a cold cache,
	 * so flushing here reproduces what a site actually sees.
	 */
	private function run_uninstall() {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', MPGR_PLUGIN_BASENAME );
		}

		require MPGR_PLUGIN_PATH . 'uninstall.php';

		wp_cache_flush();
	}

	/**
	 * Every option, transient, and meta row the plugin writes is removed.
	 */
	public function test_uninstall_removes_all_plugin_data() {
		global $wpdb;

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$other_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $admin_id );

		foreach ( self::$options as $option ) {
			update_option( $option, 'seeded' );
		}

		foreach ( self::$transients as $transient ) {
			set_transient( $transient, array( 'seeded' => true ), HOUR_IN_SECONDS );
		}

		// Meta is seeded on two users to prove the cleanup is not per-user.
		foreach ( self::$user_meta_keys as $meta_key ) {
			update_user_meta( $admin_id, $meta_key, '1' );
			update_user_meta( $other_id, $meta_key, '1' );
		}

		$transaction_id = $this->create_gift_transaction();
		foreach ( self::$transaction_meta_keys as $meta_key ) {
			$this->add_transaction_meta( $transaction_id, $meta_key, '1' );
		}

		// Unrelated data that must survive.
		update_option( 'unrelated_plugin_option', 'keep me' );
		update_user_meta( $admin_id, 'unrelated_user_meta', 'keep me' );

		$this->run_uninstall();

		foreach ( self::$options as $option ) {
			$this->assertFalse( get_option( $option, false ), "Option {$option} survived uninstall" );
		}

		foreach ( self::$transients as $transient ) {
			$this->assertFalse( get_transient( $transient ), "Transient {$transient} survived uninstall" );
		}

		foreach ( self::$user_meta_keys as $meta_key ) {
			$this->assertSame( '', get_user_meta( $admin_id, $meta_key, true ), "User meta {$meta_key} survived uninstall" );
			$this->assertSame( '', get_user_meta( $other_id, $meta_key, true ), "User meta {$meta_key} survived for a second user" );
		}

		$remaining_transaction_meta = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}mepr_transaction_meta
			 WHERE meta_key LIKE '\_mpgr\_%'"
		);
		$this->assertSame( 0, $remaining_transaction_meta, 'Reminder tracking meta survived uninstall' );

		$this->assertSame( 'keep me', get_option( 'unrelated_plugin_option' ) );
		$this->assertSame( 'keep me', get_user_meta( $admin_id, 'unrelated_user_meta', true ) );
	}

	/**
	 * Rate-limit transients are per-user and must be cleaned up too.
	 */
	public function test_uninstall_removes_rate_limit_transients() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		set_transient( 'mpgr_rate_limit_' . $user_id, array( time() ), MINUTE_IN_SECONDS );

		$this->run_uninstall();

		$this->assertFalse( get_transient( 'mpgr_rate_limit_' . $user_id ) );
	}

	/**
	 * Scheduled cron events are cleared.
	 */
	public function test_uninstall_clears_scheduled_events() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'mpgr_run_gift_reminders' );
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'mpgr_run_weekly_summary' );

		$this->run_uninstall();

		$this->assertFalse( wp_next_scheduled( 'mpgr_run_gift_reminders' ) );
		$this->assertFalse( wp_next_scheduled( 'mpgr_run_weekly_summary' ) );
	}
}
