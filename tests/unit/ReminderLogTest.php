<?php
/**
 * Tests for the per-gift reminder send log.
 *
 * @package MemberPressGiftReporter
 */

/**
 * Reminder log test case.
 */
class ReminderLogTest extends MPGR_TestCase {

	/**
	 * Membership post ID.
	 *
	 * @var int
	 */
	private $product_id;

	/**
	 * Gift transaction ID.
	 *
	 * @var int
	 */
	private $gift_id;

	/**
	 * Set up one gift.
	 */
	public function set_up() {
		parent::set_up();

		$this->product_id = self::factory()->post->create(
			array(
				'post_title'  => 'Gold Membership',
				'post_type'   => 'memberpressproduct',
				'post_status' => 'publish',
			)
		);

		$this->gift_id = $this->create_gift_transaction(
			array(
				'user_id'    => self::factory()->user->create(),
				'product_id' => $this->product_id,
				'coupon_id'  => self::factory()->post->create(
					array(
						'post_title'  => 'GIFT-LOG',
						'post_type'   => 'memberpresscoupon',
						'post_status' => 'publish',
					)
				),
			)
		);
	}

	/**
	 * Reset singleton between tests.
	 */
	public function tear_down() {
		$reflection = new ReflectionClass( 'MPGR_Gift_Report' );
		$property   = $reflection->getProperty( 'instance' );
		$property->setAccessible( true );
		$property->setValue( null, null );

		parent::tear_down();
	}

	/**
	 * A gift with no history has an empty log rather than an error.
	 */
	public function test_log_starts_empty() {
		$this->assertSame( array(), MPGR_Reminders::get_reminder_log( $this->gift_id ) );
	}

	/**
	 * A successful send is recorded with its trigger and recipient.
	 */
	public function test_successful_send_is_recorded() {
		MPGR_Reminders::log_reminder_attempt( $this->gift_id, 'schedule:0', true, 'gifter@example.com' );

		$log = MPGR_Reminders::get_reminder_log( $this->gift_id );

		$this->assertCount( 1, $log );
		$this->assertSame( 'sent', $log[0]['result'] );
		$this->assertSame( 'schedule:0', $log[0]['trigger'] );
		$this->assertSame( 'gifter@example.com', $log[0]['to'] );
		$this->assertGreaterThan( 0, $log[0]['ts'] );
	}

	/**
	 * A failure is recorded too — this is the case that used to vanish.
	 */
	public function test_failed_send_is_recorded_with_reason() {
		MPGR_Reminders::log_reminder_attempt( $this->gift_id, 'schedule:1', false, 'gifter@example.com', 'mail_failed' );

		$log = MPGR_Reminders::get_reminder_log( $this->gift_id );

		$this->assertCount( 1, $log );
		$this->assertSame( 'failed', $log[0]['result'] );
		$this->assertSame( 'mail_failed', $log[0]['reason'] );
	}

	/**
	 * Attempts accumulate in order.
	 */
	public function test_attempts_accumulate_in_order() {
		MPGR_Reminders::log_reminder_attempt( $this->gift_id, 'schedule:0', true );
		MPGR_Reminders::log_reminder_attempt( $this->gift_id, 'manual', false, '', 'mail_failed' );
		MPGR_Reminders::log_reminder_attempt( $this->gift_id, 'bulk', true );

		$log = MPGR_Reminders::get_reminder_log( $this->gift_id );

		$this->assertCount( 3, $log );
		$this->assertSame( array( 'schedule:0', 'manual', 'bulk' ), wp_list_pluck( $log, 'trigger' ) );
	}

	/**
	 * The log is capped so one gift cannot grow an unbounded meta row.
	 */
	public function test_log_is_capped_keeping_the_newest_entries() {
		for ( $i = 0; $i < MPGR_Reminders::LOG_MAX_ENTRIES + 5; $i++ ) {
			MPGR_Reminders::log_reminder_attempt( $this->gift_id, 'manual-' . $i, true );
		}

		$log = MPGR_Reminders::get_reminder_log( $this->gift_id );

		$this->assertCount( MPGR_Reminders::LOG_MAX_ENTRIES, $log );
		// Oldest dropped, newest kept.
		$this->assertSame( 'manual-5', $log[0]['trigger'] );
		$this->assertSame( 'manual-' . ( MPGR_Reminders::LOG_MAX_ENTRIES + 4 ), $log[ count( $log ) - 1 ]['trigger'] );
	}

	/**
	 * Corrupt meta degrades to an empty log rather than a fatal.
	 */
	public function test_corrupt_log_meta_is_ignored() {
		$this->add_transaction_meta( $this->gift_id, MPGR_Reminders::LOG_META_KEY, 'not-json' );

		$this->assertSame( array(), MPGR_Reminders::get_reminder_log( $this->gift_id ) );
	}

	/**
	 * The report row carries the decoded log and a failure count.
	 */
	public function test_report_row_exposes_the_log() {
		MPGR_Reminders::log_reminder_attempt( $this->gift_id, 'schedule:0', true );
		MPGR_Reminders::log_reminder_attempt( $this->gift_id, 'schedule:1', false, '', 'mail_failed' );

		$rows = MPGR_Gift_Report::get_instance()->generate_report( 10, 0, array() );
		$row  = $rows[0];

		$this->assertCount( 2, $row['reminder_log'] );
		$this->assertSame( 1, $row['reminder_failures'] );
		$this->assertTrue( (bool) $row['reminder_last_failed'] );
		$this->assertArrayNotHasKey( 'reminder_log_raw', $row );
	}

	/**
	 * A gift with no reminders still reports a well-formed empty log.
	 */
	public function test_report_row_without_reminders() {
		$rows = MPGR_Gift_Report::get_instance()->generate_report( 10, 0, array() );
		$row  = $rows[0];

		$this->assertSame( array(), $row['reminder_log'] );
		$this->assertSame( 0, $row['reminder_failures'] );
		$this->assertFalse( (bool) $row['reminder_last_failed'] );
	}

	/**
	 * Counting since a timestamp powers the weekly summary figure.
	 */
	public function test_counts_since_a_timestamp() {
		MPGR_Reminders::log_reminder_attempt( $this->gift_id, 'schedule:0', true );
		MPGR_Reminders::log_reminder_attempt( $this->gift_id, 'schedule:1', false, '', 'mail_failed' );

		$since = time() - HOUR_IN_SECONDS;

		$this->assertSame( 1, MPGR_Reminders::count_reminders_since( $since ) );
		$this->assertSame( 1, MPGR_Reminders::count_reminders_since( $since, true ) );

		// Nothing is counted from a window that has not started yet.
		$this->assertSame( 0, MPGR_Reminders::count_reminders_since( time() + HOUR_IN_SECONDS ) );
	}

	/**
	 * Counting spans gifts, not just one.
	 */
	public function test_counts_across_multiple_gifts() {
		$other = $this->create_gift_transaction(
			array(
				'user_id'    => self::factory()->user->create(),
				'product_id' => $this->product_id,
				'coupon_id'  => self::factory()->post->create(
					array(
						'post_title'  => 'GIFT-LOG-2',
						'post_type'   => 'memberpresscoupon',
						'post_status' => 'publish',
					)
				),
			)
		);

		MPGR_Reminders::log_reminder_attempt( $this->gift_id, 'schedule:0', true );
		MPGR_Reminders::log_reminder_attempt( $other, 'schedule:0', true );

		$this->assertSame( 2, MPGR_Reminders::count_reminders_since( time() - HOUR_IN_SECONDS ) );
	}

	/**
	 * The weekly summary reports real reminder activity.
	 */
	public function test_weekly_summary_reports_reminder_activity() {
		MPGR_Reminders::log_reminder_attempt( $this->gift_id, 'schedule:0', true );
		MPGR_Reminders::log_reminder_attempt( $this->gift_id, 'schedule:1', false, '', 'mail_failed' );

		$data = $this->invoke_private_static_method( 'MPGR_Weekly_Summary', 'get_week_data' );

		$this->assertSame( 1, $data['reminders_sent'] );
		$this->assertSame( 1, $data['reminders_failed'] );
	}
}
