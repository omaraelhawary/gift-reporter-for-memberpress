<?php
/**
 * Tests for the time-to-claim summary metric.
 *
 * @package MemberPressGiftReporter
 */

/**
 * Time-to-claim test case.
 */
class TimeToClaimTest extends MPGR_TestCase {

	/**
	 * Membership post ID.
	 *
	 * @var int
	 */
	private $product_id;

	/**
	 * Set up a product for the fixtures.
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
	 * Create a claimed gift purchased and redeemed at given times.
	 *
	 * @param string $purchased  MySQL datetime of the purchase.
	 * @param string $redeemed   MySQL datetime of the redemption.
	 * @param string $code       Coupon code.
	 * @return int Gift transaction ID.
	 */
	private function create_claimed_gift( $purchased, $redeemed, $code ) {
		$coupon_id = self::factory()->post->create(
			array(
				'post_title'  => $code,
				'post_type'   => 'memberpresscoupon',
				'post_status' => 'publish',
			)
		);

		$gift_id = $this->create_gift_transaction(
			array(
				'user_id'     => self::factory()->user->create(),
				'product_id'  => $this->product_id,
				'coupon_id'   => $coupon_id,
				'gift_status' => 'claimed',
				'created_at'  => $purchased,
			)
		);

		$this->create_redemption_transaction(
			$coupon_id,
			array(
				'user_id'    => self::factory()->user->create(),
				'product_id' => $this->product_id,
				'created_at' => $redeemed,
			)
		);

		return $gift_id;
	}

	/**
	 * The average is taken over claimed gifts only.
	 */
	public function test_average_time_to_claim_over_claimed_gifts() {
		// 24h and 72h -> 48h average.
		$this->create_claimed_gift( '2026-01-01 00:00:00', '2026-01-02 00:00:00', 'GIFT-1D' );
		$this->create_claimed_gift( '2026-01-01 00:00:00', '2026-01-04 00:00:00', 'GIFT-3D' );

		$summary = MPGR_Gift_Report::get_instance()->get_summary();

		$this->assertSame( 48.0, (float) $summary['avg_hours_to_claim'] );
		$this->assertSame( 2.0, (float) $summary['avg_days_to_claim'] );
	}

	/**
	 * Unclaimed gifts must not drag the average toward zero.
	 */
	public function test_unclaimed_gifts_are_excluded_from_the_average() {
		$this->create_claimed_gift( '2026-01-01 00:00:00', '2026-01-03 00:00:00', 'GIFT-2D' );

		$this->create_gift_transaction(
			array(
				'user_id'    => self::factory()->user->create(),
				'product_id' => $this->product_id,
				'coupon_id'  => self::factory()->post->create(
					array(
						'post_title'  => 'GIFT-OPEN',
						'post_type'   => 'memberpresscoupon',
						'post_status' => 'publish',
					)
				),
				'created_at' => '2026-01-01 00:00:00',
			)
		);

		$summary = MPGR_Gift_Report::get_instance()->get_summary();

		$this->assertSame( 48.0, (float) $summary['avg_hours_to_claim'] );
	}

	/**
	 * With nothing claimed the metric is null, not zero.
	 */
	public function test_no_claims_yields_null_rather_than_zero() {
		$this->create_gift_transaction(
			array(
				'user_id'    => self::factory()->user->create(),
				'product_id' => $this->product_id,
				'coupon_id'  => self::factory()->post->create(
					array(
						'post_title'  => 'GIFT-NONE',
						'post_type'   => 'memberpresscoupon',
						'post_status' => 'publish',
					)
				),
			)
		);

		$summary = MPGR_Gift_Report::get_instance()->get_summary();

		$this->assertNull( $summary['avg_hours_to_claim'] );
		$this->assertNull( $summary['avg_days_to_claim'] );
		$this->assertSame( '', $summary['avg_time_to_claim_formatted'] );
	}

	/**
	 * The metric respects the active filters.
	 */
	public function test_average_respects_filters() {
		$this->create_claimed_gift( '2026-01-01 00:00:00', '2026-01-02 00:00:00', 'GIFT-JAN' );
		$this->create_claimed_gift( '2026-03-01 00:00:00', '2026-03-06 00:00:00', 'GIFT-MAR' );

		$summary = MPGR_Gift_Report::get_instance()->get_summary(
			array(
				'date_from' => '2026-02-01',
				'date_to'   => '2026-03-31',
			)
		);

		// Only the March gift: 5 days.
		$this->assertSame( 120.0, (float) $summary['avg_hours_to_claim'] );
	}

	/**
	 * Sub-day averages read in hours; longer ones in days.
	 */
	public function test_duration_formatting() {
		$this->assertSame( '', MPGR_Gift_Report::format_duration( null ) );
		$this->assertSame( '1 hour', MPGR_Gift_Report::format_duration( 1.0 ) );
		$this->assertSame( '6 hours', MPGR_Gift_Report::format_duration( 6.0 ) );
		$this->assertSame( '23 hours', MPGR_Gift_Report::format_duration( 23.4 ) );
		$this->assertSame( '1 day', MPGR_Gift_Report::format_duration( 24.0 ) );
		$this->assertSame( '2.6 days', MPGR_Gift_Report::format_duration( 62.0 ) );
	}

	/**
	 * The weekly summary reports the same metric.
	 */
	public function test_weekly_summary_includes_time_to_claim() {
		$now       = current_time( 'mysql' );
		$purchased = gmdate( 'Y-m-d H:i:s', strtotime( $now ) - ( 3 * DAY_IN_SECONDS ) );
		$redeemed  = gmdate( 'Y-m-d H:i:s', strtotime( $now ) - ( 1 * DAY_IN_SECONDS ) );

		$this->create_claimed_gift( $purchased, $redeemed, 'GIFT-WEEK' );

		$data = $this->invoke_private_static_method( 'MPGR_Weekly_Summary', 'get_week_data' );

		$this->assertNotNull( $data['avg_hours_to_claim'] );
		// Two days, allowing for the seconds that elapse during the test.
		$this->assertEqualsWithDelta( 48.0, $data['avg_hours_to_claim'], 1.0 );
		$this->assertSame( '2 days', $data['avg_time_to_claim_formatted'] );
	}
}
