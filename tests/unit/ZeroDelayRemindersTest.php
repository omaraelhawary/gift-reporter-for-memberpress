<?php
/**
 * Tests for the 0-delay branching in MPGR_Reminders::run_scheduled_reminders_work().
 *
 * A 0-delay schedule takes a different path at three points: the query cutoff,
 * the "have we already sent this one" check, and the duplicate-suppression
 * window. Those special cases are pinned here so the logic can be refactored
 * safely.
 *
 * @package MemberPressGiftReporter
 */

/**
 * Zero-delay reminder test case.
 */
class ZeroDelayRemindersTest extends MPGR_TestCase {

	/**
	 * Membership post ID.
	 *
	 * @var int
	 */
	private $product_id;

	/**
	 * Mails intercepted during a run.
	 *
	 * @var array<int, array>
	 */
	private $mails = array();

	/**
	 * Whether the intercepted mail should report success.
	 *
	 * @var bool
	 */
	private $mail_succeeds = true;

	/**
	 * Intercept wp_mail so no real delivery is attempted.
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

		$this->mails         = array();
		$this->mail_succeeds = true;

		// pre_wp_mail short-circuits delivery when it returns non-null.
		add_filter(
			'pre_wp_mail',
			function ( $short_circuit, $atts ) {
				$this->mails[] = $atts;
				return $this->mail_succeeds;
			},
			10,
			2
		);
	}

	/**
	 * Remove the mail interceptor.
	 */
	public function tear_down() {
		remove_all_filters( 'pre_wp_mail' );

		parent::tear_down();
	}

	/**
	 * Enable reminders with the given schedules.
	 *
	 * @param array $schedules Reminder schedules.
	 */
	private function enable_reminders( array $schedules ) {
		update_option(
			'mpgr_reminder_settings',
			array(
				'enabled'            => true,
				'send_to_gifter'     => true,
				'max_reminders'      => count( $schedules ),
				'reminder_schedules' => $schedules,
			)
		);
	}

	/**
	 * Create an unclaimed gift purchased a given number of hours ago.
	 *
	 * @param int $hours_ago How long ago the gift was purchased.
	 * @return int Gift transaction ID.
	 */
	private function create_gift( $hours_ago ) {
		$coupon_id = self::factory()->post->create(
			array(
				'post_title'  => 'GIFT-ZERO-' . $hours_ago . '-' . wp_generate_password( 4, false ),
				'post_type'   => 'memberpresscoupon',
				'post_status' => 'publish',
			)
		);

		return $this->create_gift_transaction(
			array(
				'user_id'    => self::factory()->user->create(),
				'product_id' => $this->product_id,
				'coupon_id'  => $coupon_id,
				'created_at' => gmdate( 'Y-m-d H:i:s', time() - ( $hours_ago * HOUR_IN_SECONDS ) ),
			)
		);
	}

	/**
	 * Run one cron pass.
	 */
	private function run_reminders() {
		$this->invoke_private_static_method( 'MPGR_Reminders', 'run_scheduled_reminders_work' );
	}

	/**
	 * Reminders sent so far for a gift.
	 *
	 * @param int $gift_id Gift transaction ID.
	 * @return int
	 */
	private function sent_count( $gift_id ) {
		return (int) $this->invoke_private_static_method(
			'MPGR_Reminders',
			'get_reminder_meta',
			array( $gift_id, '_mpgr_reminder_sent_count', 0 )
		);
	}

	/**
	 * A 0-delay schedule sends on the first run for an eligible gift.
	 */
	public function test_zero_delay_sends_on_the_first_run() {
		$this->enable_reminders( array( array( 'delay_value' => 0, 'delay_unit' => 'hours' ) ) );
		$gift_id = $this->create_gift( 2 );

		$this->run_reminders();

		$this->assertCount( 1, $this->mails );
		$this->assertSame( 1, $this->sent_count( $gift_id ) );
	}

	/**
	 * With 0 delay the query cutoff is still an hour back.
	 *
	 * The inline comment claims gifts purchased "in the last hour" are
	 * included, but get_unclaimed_gifts() filters on created_at <= now - 1 hour,
	 * so a gift bought minutes ago waits for the next hour's run. This pins the
	 * behaviour as it stands.
	 */
	public function test_zero_delay_skips_a_gift_purchased_within_the_last_hour() {
		$this->enable_reminders( array( array( 'delay_value' => 0, 'delay_unit' => 'hours' ) ) );
		$gift_id = $this->create_gift( 0 );

		$this->run_reminders();

		$this->assertSame( array(), $this->mails );
		$this->assertSame( 0, $this->sent_count( $gift_id ) );
	}

	/**
	 * One 0-delay schedule sends once and then stops.
	 */
	public function test_single_zero_delay_schedule_does_not_repeat() {
		$this->enable_reminders( array( array( 'delay_value' => 0, 'delay_unit' => 'hours' ) ) );
		$gift_id = $this->create_gift( 2 );

		$this->run_reminders();
		$this->run_reminders();
		$this->run_reminders();

		$this->assertCount( 1, $this->mails );
		$this->assertSame( 1, $this->sent_count( $gift_id ) );
	}

	/**
	 * Only one reminder goes out per gift per run.
	 */
	public function test_only_one_reminder_per_gift_per_run() {
		$this->enable_reminders(
			array(
				array( 'delay_value' => 0, 'delay_unit' => 'hours' ),
				array( 'delay_value' => 0, 'delay_unit' => 'hours' ),
			)
		);
		$this->create_gift( 2 );

		$this->run_reminders();

		$this->assertCount( 1, $this->mails );
	}

	/**
	 * Successive runs walk through each 0-delay schedule in turn.
	 */
	public function test_multiple_zero_delay_schedules_advance_one_run_at_a_time() {
		$this->enable_reminders(
			array(
				array( 'delay_value' => 0, 'delay_unit' => 'hours' ),
				array( 'delay_value' => 0, 'delay_unit' => 'hours' ),
			)
		);
		$gift_id = $this->create_gift( 2 );

		$this->run_reminders();
		$this->assertSame( 1, $this->sent_count( $gift_id ) );

		$this->run_reminders();
		$this->assertSame( 2, $this->sent_count( $gift_id ) );

		// Both schedules used up: the gift drops out of the query entirely.
		$this->run_reminders();
		$this->assertSame( 2, $this->sent_count( $gift_id ) );
		$this->assertCount( 2, $this->mails );
	}

	/**
	 * The per-schedule count check, not the hour window, is what stops a repeat.
	 *
	 * Reaching the 1-hour duplicate-suppression branch requires
	 * sent_count === schedule_index + 1, which already satisfies the earlier
	 * sent_count > schedule_index check, so that branch cannot be reached. A
	 * recent send is suppressed either way; this pins which rule does it.
	 */
	public function test_recent_send_is_suppressed_by_the_count_check() {
		$this->enable_reminders( array( array( 'delay_value' => 0, 'delay_unit' => 'hours' ) ) );
		$gift_id = $this->create_gift( 2 );

		$this->run_reminders();
		$this->mails = array();

		// Pretend the send happened well outside the 1-hour window.
		$this->invoke_private_static_method(
			'MPGR_Reminders',
			'upsert_transaction_meta',
			array( $gift_id, '_mpgr_last_reminder_ts', (string) ( time() - ( 5 * HOUR_IN_SECONDS ) ) )
		);

		$this->run_reminders();

		$this->assertSame( array(), $this->mails, 'The sent-count check should stop this regardless of elapsed time.' );
	}

	/**
	 * A non-zero delay still waits for the gift to age.
	 */
	public function test_non_zero_delay_waits_for_the_delay_to_elapse() {
		$this->enable_reminders( array( array( 'delay_value' => 7, 'delay_unit' => 'days' ) ) );
		$this->create_gift( 2 );

		$this->run_reminders();

		$this->assertSame( array(), $this->mails );
	}

	/**
	 * A gift older than a non-zero delay does get its reminder.
	 */
	public function test_non_zero_delay_sends_once_due() {
		$this->enable_reminders( array( array( 'delay_value' => 1, 'delay_unit' => 'hours' ) ) );
		$gift_id = $this->create_gift( 3 );

		$this->run_reminders();

		$this->assertCount( 1, $this->mails );
		$this->assertSame( 1, $this->sent_count( $gift_id ) );
	}

	/**
	 * Disabled reminders send nothing, whatever the schedule.
	 */
	public function test_disabled_reminders_send_nothing() {
		update_option(
			'mpgr_reminder_settings',
			array(
				'enabled'            => false,
				'reminder_schedules' => array( array( 'delay_value' => 0, 'delay_unit' => 'hours' ) ),
			)
		);
		$this->create_gift( 2 );

		$this->run_reminders();

		$this->assertSame( array(), $this->mails );
	}

	/**
	 * A failed send leaves the counter alone but is recorded in the log.
	 */
	public function test_failed_send_is_logged_and_not_counted() {
		$this->enable_reminders( array( array( 'delay_value' => 0, 'delay_unit' => 'hours' ) ) );
		$gift_id = $this->create_gift( 2 );

		$this->mail_succeeds = false;

		$this->run_reminders();

		$this->assertCount( 1, $this->mails, 'The send should still be attempted.' );
		$this->assertSame( 0, $this->sent_count( $gift_id ), 'A failed send must not count as delivered.' );

		$log = MPGR_Reminders::get_reminder_log( $gift_id );
		$this->assertCount( 1, $log );
		$this->assertSame( 'failed', $log[0]['result'] );
		$this->assertSame( 'schedule:0', $log[0]['trigger'] );
	}

	/**
	 * A successful automatic send records which schedule fired.
	 */
	public function test_successful_send_records_its_schedule() {
		$this->enable_reminders(
			array(
				array( 'delay_value' => 0, 'delay_unit' => 'hours' ),
				array( 'delay_value' => 0, 'delay_unit' => 'hours' ),
			)
		);
		$gift_id = $this->create_gift( 2 );

		$this->run_reminders();
		$this->run_reminders();

		$log = MPGR_Reminders::get_reminder_log( $gift_id );

		$this->assertSame( array( 'schedule:0', 'schedule:1' ), wp_list_pluck( $log, 'trigger' ) );
	}
}
