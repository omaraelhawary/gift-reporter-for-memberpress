<?php
/**
 * Tests for the per-membership breakdown and the purchases-vs-claims trend.
 *
 * @package MemberPressGiftReporter
 */

/**
 * Product breakdown and trend test case.
 */
class ProductBreakdownTest extends MPGR_TestCase {

	/**
	 * Membership post IDs keyed by name.
	 *
	 * @var array<string, int>
	 */
	private $products = array();

	/**
	 * Coupon counter, to keep codes unique.
	 *
	 * @var int
	 */
	private $coupon_seq = 0;

	/**
	 * Two memberships with differing claim behaviour.
	 */
	public function set_up() {
		parent::set_up();

		foreach ( array( 'Gold', 'Silver' ) as $name ) {
			$this->products[ $name ] = self::factory()->post->create(
				array(
					'post_title'  => $name . ' Membership',
					'post_type'   => 'memberpressproduct',
					'post_status' => 'publish',
				)
			);
		}

		// Gold: 2 gifts, 1 claimed.
		$this->add_gift( 'Gold', '2026-01-01 10:00:00', '2026-01-03 10:00:00' );
		$this->add_gift( 'Gold', '2026-01-02 10:00:00', null );

		// Silver: 1 gift, unclaimed.
		$this->add_gift( 'Silver', '2026-01-02 10:00:00', null );
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
	 * Add a gift, optionally claimed on a given date.
	 *
	 * @param string      $product   Product key.
	 * @param string      $purchased MySQL datetime.
	 * @param string|null $redeemed  MySQL datetime, or null to leave unclaimed.
	 * @return int Gift transaction ID.
	 */
	private function add_gift( $product, $purchased, $redeemed ) {
		$coupon_id = self::factory()->post->create(
			array(
				'post_title'  => 'GIFT-' . ( ++$this->coupon_seq ),
				'post_type'   => 'memberpresscoupon',
				'post_status' => 'publish',
			)
		);

		$gift_id = $this->create_gift_transaction(
			array(
				'user_id'     => self::factory()->user->create(),
				'product_id'  => $this->products[ $product ],
				'coupon_id'   => $coupon_id,
				'gift_status' => $redeemed ? 'claimed' : 'unclaimed',
				'created_at'  => $purchased,
			)
		);

		if ( $redeemed ) {
			$this->create_redemption_transaction(
				$coupon_id,
				array(
					'user_id'    => self::factory()->user->create(),
					'product_id' => $this->products[ $product ],
					'created_at' => $redeemed,
				)
			);
		}

		return $gift_id;
	}

	/**
	 * Breakdown rows keyed by product name.
	 *
	 * @param array $filters Active filters.
	 * @return array<string, array>
	 */
	private function breakdown_by_name( array $filters = array() ) {
		$rows   = MPGR_Gift_Report::get_instance()->get_product_breakdown( $filters );
		$keyed  = array();

		foreach ( $rows as $row ) {
			$keyed[ $row['product_name'] ] = $row;
		}

		return $keyed;
	}

	/**
	 * Each membership is counted separately.
	 */
	public function test_breakdown_splits_by_membership() {
		$rows = $this->breakdown_by_name();

		$this->assertCount( 2, $rows );
		$this->assertSame( 2, $rows['Gold Membership']['total_gifts'] );
		$this->assertSame( 1, $rows['Silver Membership']['total_gifts'] );
	}

	/**
	 * Claimed/unclaimed counts and claim rate are per membership.
	 */
	public function test_breakdown_reports_claim_rate_per_membership() {
		$rows = $this->breakdown_by_name();

		$this->assertSame( 1, $rows['Gold Membership']['claimed_gifts'] );
		$this->assertSame( 1, $rows['Gold Membership']['unclaimed_gifts'] );
		$this->assertSame( 50.0, (float) $rows['Gold Membership']['claim_rate'] );

		$this->assertSame( 0, $rows['Silver Membership']['claimed_gifts'] );
		$this->assertSame( 0.0, (float) $rows['Silver Membership']['claim_rate'] );
	}

	/**
	 * Revenue is attributed to the right membership.
	 */
	public function test_breakdown_reports_revenue_per_membership() {
		$rows = $this->breakdown_by_name();

		$this->assertSame( 200.0, (float) $rows['Gold Membership']['revenue'] );
		$this->assertSame( 100.0, (float) $rows['Silver Membership']['revenue'] );
		$this->assertNotEmpty( $rows['Gold Membership']['revenue_formatted'] );
	}

	/**
	 * The breakdown honours the active filters.
	 */
	public function test_breakdown_respects_filters() {
		$rows = $this->breakdown_by_name( array( 'product' => $this->products['Silver'] ) );

		$this->assertCount( 1, $rows );
		$this->assertArrayHasKey( 'Silver Membership', $rows );
	}

	/**
	 * Rows come back most-gifted first.
	 */
	public function test_breakdown_is_ordered_by_gift_count() {
		$rows = MPGR_Gift_Report::get_instance()->get_product_breakdown();

		$this->assertSame( 'Gold Membership', $rows[0]['product_name'] );
	}

	/**
	 * Totals across the breakdown reconcile with the summary.
	 */
	public function test_breakdown_totals_match_the_summary() {
		$summary = MPGR_Gift_Report::get_instance()->get_summary();
		$rows    = MPGR_Gift_Report::get_instance()->get_product_breakdown();

		$this->assertSame(
			(int) $summary['total_gifts'],
			array_sum( wp_list_pluck( $rows, 'total_gifts' ) )
		);
		$this->assertSame(
			(float) $summary['total_revenue'],
			(float) array_sum( wp_list_pluck( $rows, 'revenue' ) )
		);
	}

	/**
	 * Purchases are keyed by purchase date.
	 */
	public function test_trend_reports_purchases_by_day() {
		$trend = MPGR_Gift_Report::get_instance()->get_trend();

		$byday = array();
		foreach ( $trend as $point ) {
			$byday[ $point['date'] ] = $point;
		}

		$this->assertSame( 1, $byday['2026-01-01']['purchases'] );
		$this->assertSame( 2, $byday['2026-01-02']['purchases'] );
	}

	/**
	 * Claims are keyed by redemption date, not by purchase date.
	 */
	public function test_trend_reports_claims_on_the_day_they_happened() {
		$trend = MPGR_Gift_Report::get_instance()->get_trend();

		$byday = array();
		foreach ( $trend as $point ) {
			$byday[ $point['date'] ] = $point;
		}

		// The gift was bought on the 1st and claimed on the 3rd.
		$this->assertSame( 0, $byday['2026-01-01']['claims'] );
		$this->assertArrayHasKey( '2026-01-03', $byday );
		$this->assertSame( 1, $byday['2026-01-03']['claims'] );
		$this->assertSame( 0, $byday['2026-01-03']['purchases'] );
	}

	/**
	 * The series comes back in date order.
	 */
	public function test_trend_is_ordered_by_date() {
		$dates = wp_list_pluck( MPGR_Gift_Report::get_instance()->get_trend(), 'date' );

		$sorted = $dates;
		sort( $sorted );

		$this->assertSame( $sorted, $dates );
	}

	/**
	 * Breakdown and trend are cached separately from the summary.
	 */
	public function test_aggregates_do_not_share_a_cache_entry() {
		$report = MPGR_Gift_Report::get_instance();

		$summary   = $report->get_summary();
		$breakdown = $report->get_product_breakdown();
		$trend     = $report->get_trend();

		$this->assertArrayHasKey( 'total_gifts', $summary );
		$this->assertCount( 2, $breakdown );
		$this->assertNotEmpty( $trend );

		// Second pass comes from cache and must be identical, not crossed over.
		$this->assertSame( $breakdown, $report->get_product_breakdown() );
		$this->assertSame( $trend, $report->get_trend() );
	}
}
