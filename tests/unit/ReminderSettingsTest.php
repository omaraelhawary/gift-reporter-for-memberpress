<?php
/**
 * Tests for reminder settings and tracking meta.
 *
 * @package MemberPressGiftReporter
 */

/**
 * Reminder settings test case.
 */
class ReminderSettingsTest extends MPGR_TestCase {

	/**
	 * Default settings should include reminder schedules.
	 */
	public function test_get_settings_includes_default_schedules() {
		$settings = MPGR_Reminders::get_settings();

		$this->assertFalse( $settings['enabled'] );
		$this->assertNotEmpty( $settings['reminder_schedules'] );
		$this->assertSame( 7, $settings['reminder_schedules'][0]['delay_value'] );
		$this->assertSame( 'days', $settings['reminder_schedules'][0]['delay_unit'] );
	}

	/**
	 * Manual reminder sends should increment tracking meta.
	 */
	public function test_record_manual_reminder_sent_updates_meta() {
		global $wpdb;

		MPGR_Reminders::record_manual_reminder_sent( 12345 );

		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT meta_value FROM {$wpdb->prefix}mepr_transaction_meta WHERE transaction_id = %d AND meta_key = %s",
				12345,
				'_mpgr_reminder_sent_count'
			)
		);
		$last_ts = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT meta_value FROM {$wpdb->prefix}mepr_transaction_meta WHERE transaction_id = %d AND meta_key = %s",
				12345,
				'_mpgr_last_reminder_ts'
			)
		);

		$this->assertSame( '1', $count );
		$this->assertNotEmpty( $last_ts );
		$this->assertGreaterThan( 0, (int) $last_ts );

		MPGR_Reminders::record_manual_reminder_sent( 12345 );
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT meta_value FROM {$wpdb->prefix}mepr_transaction_meta WHERE transaction_id = %d AND meta_key = %s",
				12345,
				'_mpgr_reminder_sent_count'
			)
		);
		$this->assertSame( '2', $count );
	}
}
