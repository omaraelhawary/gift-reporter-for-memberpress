<?php
/**
 * Tests for REST report pagination.
 *
 * @package MemberPressGiftReporter
 */

/**
 * REST pagination test case.
 */
class RestPaginationTest extends MPGR_TestCase {

	/**
	 * Number of gifts created for the fixtures.
	 */
	const GIFT_COUNT = 7;

	/**
	 * Set up an admin plus a handful of gifts.
	 */
	public function set_up() {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$product_id = self::factory()->post->create(
			array(
				'post_title'  => 'Gold Membership',
				'post_type'   => 'memberpressproduct',
				'post_status' => 'publish',
			)
		);

		for ( $i = 1; $i <= self::GIFT_COUNT; $i++ ) {
			$this->create_gift_transaction(
				array(
					'user_id'    => self::factory()->user->create(),
					'product_id' => $product_id,
					'coupon_id'  => self::factory()->post->create(
						array(
							'post_title'  => 'GIFT-' . $i,
							'post_type'   => 'memberpresscoupon',
							'post_status' => 'publish',
						)
					),
					'created_at' => sprintf( '2026-01-%02d 10:00:00', $i ),
				)
			);
		}
	}

	/**
	 * Reset singleton and cookie between tests.
	 */
	public function tear_down() {
		unset( $_COOKIE[ LOGGED_IN_COOKIE ] );

		$reflection = new ReflectionClass( 'MPGR_Gift_Report' );
		$property   = $reflection->getProperty( 'instance' );
		$property->setAccessible( true );
		$property->setValue( null, null );

		parent::tear_down();
	}

	/**
	 * Call the report endpoint directly.
	 *
	 * @param array $params Query params.
	 * @return WP_REST_Response
	 */
	private function request( array $params = array() ) {
		$request = new WP_REST_Request( 'GET', '/mpgr/v1/report' );

		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return MPGR_Gift_Report::get_instance()->rest_get_report( $request );
	}

	/**
	 * The response reports totals alongside the rows.
	 */
	public function test_response_includes_totals() {
		$data = $this->request( array( 'per_page' => 3, 'page' => 1 ) )->get_data();

		$this->assertTrue( $data['success'] );
		$this->assertSame( self::GIFT_COUNT, $data['total'] );
		$this->assertSame( 3, $data['per_page'] );
		$this->assertSame( 1, $data['page'] );
		$this->assertSame( 3, $data['total_pages'] );
		$this->assertCount( 3, $data['data'] );
	}

	/**
	 * Paging walks the whole result set without repeats or gaps.
	 */
	public function test_pages_cover_every_row_exactly_once() {
		$seen = array();

		for ( $page = 1; $page <= 3; $page++ ) {
			$data = $this->request( array( 'per_page' => 3, 'page' => $page ) )->get_data();

			foreach ( $data['data'] as $row ) {
				$seen[] = (int) $row['gift_transaction_id'];
			}
		}

		$this->assertCount( self::GIFT_COUNT, $seen );
		$this->assertCount( self::GIFT_COUNT, array_unique( $seen ) );
	}

	/**
	 * The final page holds the remainder, not a full page.
	 */
	public function test_last_page_holds_the_remainder() {
		$data = $this->request( array( 'per_page' => 3, 'page' => 3 ) )->get_data();

		$this->assertCount( 1, $data['data'] );
	}

	/**
	 * Paging past the end returns no rows rather than erroring.
	 */
	public function test_page_beyond_the_end_is_empty() {
		$data = $this->request( array( 'per_page' => 3, 'page' => 99 ) )->get_data();

		$this->assertSame( array(), $data['data'] );
		$this->assertSame( self::GIFT_COUNT, $data['total'] );
	}

	/**
	 * An oversized page size is clamped, and the response says so.
	 */
	public function test_per_page_is_clamped_and_reported() {
		$data = $this->request( array( 'per_page' => 5000 ) )->get_data();

		$this->assertSame( 200, $data['per_page'] );
	}

	/**
	 * Junk paging values fall back to sane defaults.
	 */
	public function test_invalid_paging_values_fall_back() {
		$data = $this->request( array( 'per_page' => 0, 'page' => 0 ) )->get_data();

		$this->assertSame( 100, $data['per_page'] );
		$this->assertSame( 1, $data['page'] );
	}

	/**
	 * Standard WordPress pagination headers are set.
	 */
	public function test_pagination_headers_are_sent() {
		$headers = $this->request( array( 'per_page' => 3 ) )->get_headers();

		$this->assertSame( (string) self::GIFT_COUNT, $headers['X-WP-Total'] );
		$this->assertSame( '3', $headers['X-WP-TotalPages'] );
	}

	/**
	 * Pagination respects filters, so totals match the filtered set.
	 */
	public function test_pagination_respects_filters() {
		$data = $this->request(
			array(
				'per_page'  => 10,
				'date_from' => '2026-01-01',
				'date_to'   => '2026-01-03',
			)
		)->get_data();

		$this->assertSame( 3, $data['total'] );
		$this->assertCount( 3, $data['data'] );
	}

	/**
	 * The route advertises the paging arguments.
	 */
	public function test_route_exposes_pagination_args() {
		$args = MPGR_Gift_Report::get_rest_pagination_args();

		$this->assertArrayHasKey( 'page', $args );
		$this->assertArrayHasKey( 'per_page', $args );
		$this->assertSame( 100, $args['per_page']['default'] );
	}
}
