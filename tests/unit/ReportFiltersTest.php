<?php
/**
 * Tests for report filtering.
 *
 * @package MemberPressGiftReporter
 */

/**
 * Report filter test case.
 */
class ReportFiltersTest extends MPGR_TestCase {

	/**
	 * Gift transaction IDs keyed by coupon code.
	 *
	 * @var array<string, int>
	 */
	private $gifts = array();

	/**
	 * Three gifts with distinguishable coupon codes.
	 */
	public function set_up() {
		parent::set_up();

		$product_id = self::factory()->post->create(
			array(
				'post_title'  => 'Gold Membership',
				'post_type'   => 'memberpressproduct',
				'post_status' => 'publish',
			)
		);

		foreach ( array( 'GIFT-A1B2', 'GIFT-C3D4', 'PROMO-XYZ' ) as $code ) {
			$coupon_id = self::factory()->post->create(
				array(
					'post_title'  => $code,
					'post_type'   => 'memberpresscoupon',
					'post_status' => 'publish',
				)
			);

			$this->gifts[ $code ] = $this->create_gift_transaction(
				array(
					'user_id'    => self::factory()->user->create(),
					'product_id' => $product_id,
					'coupon_id'  => $coupon_id,
				)
			);
		}
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
	 * Gift transaction IDs from a filtered report.
	 *
	 * @param array $filters Report filters.
	 * @return int[]
	 */
	private function ids_for( array $filters ) {
		$rows = MPGR_Gift_Report::get_instance()->generate_report( 100, 0, $filters );

		return array_map(
			static function ( $row ) {
				return (int) $row['gift_transaction_id'];
			},
			$rows
		);
	}

	/**
	 * An exact coupon code returns just that gift.
	 */
	public function test_filters_by_exact_coupon_code() {
		$this->assertSame(
			array( $this->gifts['GIFT-A1B2'] ),
			$this->ids_for( array( 'coupon_code' => 'GIFT-A1B2' ) )
		);
	}

	/**
	 * A partial code matches, so a half-copied code from an email still works.
	 */
	public function test_filters_by_partial_coupon_code() {
		$ids = $this->ids_for( array( 'coupon_code' => 'GIFT-' ) );

		$this->assertCount( 2, $ids );
		$this->assertContains( $this->gifts['GIFT-A1B2'], $ids );
		$this->assertContains( $this->gifts['GIFT-C3D4'], $ids );
		$this->assertNotContains( $this->gifts['PROMO-XYZ'], $ids );
	}

	/**
	 * A code that matches nothing returns nothing rather than everything.
	 */
	public function test_unmatched_coupon_code_returns_no_rows() {
		$this->assertSame( array(), $this->ids_for( array( 'coupon_code' => 'NOPE-0000' ) ) );
	}

	/**
	 * Wildcards in user input are escaped, not treated as LIKE syntax.
	 */
	public function test_coupon_code_wildcards_are_escaped() {
		$this->assertSame( array(), $this->ids_for( array( 'coupon_code' => 'GIFT%' ) ) );
	}

	/**
	 * The summary respects the coupon filter too.
	 */
	public function test_summary_respects_coupon_code_filter() {
		$summary = MPGR_Gift_Report::get_instance()->get_summary( array( 'coupon_code' => 'GIFT-' ) );

		$this->assertSame( 2, (int) $summary['total_gifts'] );
	}

	/**
	 * The filter is part of the shared schema, so REST and CSV get it free.
	 */
	public function test_coupon_code_is_in_the_shared_filter_schema() {
		$this->assertArrayHasKey( 'coupon_code', MPGR_Gift_Report::get_filter_schema() );

		$sanitized = MPGR_Gift_Report::sanitize_filters( array( 'coupon_code' => '  GIFT-A1B2  ' ) );
		$this->assertSame( 'GIFT-A1B2', $sanitized['coupon_code'] );

		$this->assertArrayHasKey( 'coupon_code', MPGR_Gift_Report::get_rest_filter_args() );
	}
}
