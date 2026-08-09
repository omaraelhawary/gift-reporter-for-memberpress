<?php
/**
 * Tests for summary query caching.
 *
 * @package MemberPressGiftReporter
 */

/**
 * Summary cache test case.
 */
class SummaryCacheTest extends MPGR_TestCase {

	/**
	 * Membership post ID.
	 *
	 * @var int
	 */
	private $product_id;

	/**
	 * Queries captured while counting.
	 *
	 * @var int
	 */
	private $summary_queries = 0;

	/**
	 * Set up a product and one gift.
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

		$this->add_gift( 'GIFT-CACHE-1' );
	}

	/**
	 * Reset singleton between tests.
	 */
	public function tear_down() {
		remove_all_filters( 'query' );

		$reflection = new ReflectionClass( 'MPGR_Gift_Report' );
		$property   = $reflection->getProperty( 'instance' );
		$property->setAccessible( true );
		$property->setValue( null, null );

		parent::tear_down();
	}

	/**
	 * Add one unclaimed gift.
	 *
	 * @param string $code Coupon code.
	 * @return int Gift transaction ID.
	 */
	private function add_gift( $code ) {
		return $this->create_gift_transaction(
			array(
				'user_id'    => self::factory()->user->create(),
				'product_id' => $this->product_id,
				'coupon_id'  => self::factory()->post->create(
					array(
						'post_title'  => $code,
						'post_type'   => 'memberpresscoupon',
						'post_status' => 'publish',
					)
				),
			)
		);
	}

	/**
	 * Count summary aggregate queries run by a callback.
	 *
	 * @param callable $callback Work to measure.
	 * @return int
	 */
	private function count_summary_queries( callable $callback ) {
		$this->summary_queries = 0;

		$counter = function ( $query ) {
			if ( false !== strpos( $query, 'AS refunded_gifts' ) ) {
				$this->summary_queries++;
			}
			return $query;
		};

		add_filter( 'query', $counter );
		$callback();
		remove_filter( 'query', $counter );

		return $this->summary_queries;
	}

	/**
	 * The first call queries; the second is served from cache.
	 */
	public function test_repeated_summaries_hit_the_cache() {
		$report = MPGR_Gift_Report::get_instance();

		$first = $this->count_summary_queries(
			static function () use ( $report ) {
				$report->get_summary();
			}
		);

		$second = $this->count_summary_queries(
			static function () use ( $report ) {
				$report->get_summary();
				$report->get_summary();
			}
		);

		$this->assertSame( 1, $first, 'Expected the first summary to run its query.' );
		$this->assertSame( 0, $second, 'Expected later summaries to be served from cache.' );
	}

	/**
	 * A cached summary returns the same figures, not just any array.
	 */
	public function test_cached_summary_matches_the_computed_one() {
		$report = MPGR_Gift_Report::get_instance();

		$computed = $report->get_summary();
		$cached   = $report->get_summary();

		$this->assertSame( $computed, $cached );
	}

	/**
	 * Different filters must not share a cache entry.
	 */
	public function test_different_filters_are_cached_separately() {
		$report = MPGR_Gift_Report::get_instance();

		$all       = $report->get_summary();
		$unclaimed = $report->get_summary( array( 'gift_status' => 'claimed' ) );

		$this->assertSame( 1, (int) $all['total_gifts'] );
		$this->assertSame( 0, (int) $unclaimed['total_gifts'] );
	}

	/**
	 * Filter order must not produce a second entry for the same query.
	 */
	public function test_filter_order_does_not_change_the_cache_key() {
		$report = MPGR_Gift_Report::get_instance();

		$report->get_summary(
			array(
				'date_from'   => '2026-01-01',
				'gift_status' => 'unclaimed',
			)
		);

		$queries = $this->count_summary_queries(
			static function () use ( $report ) {
				$report->get_summary(
					array(
						'gift_status' => 'unclaimed',
						'date_from'   => '2026-01-01',
					)
				);
			}
		);

		$this->assertSame( 0, $queries );
	}

	/**
	 * A new gift must not be hidden behind a stale summary.
	 */
	public function test_purchase_hook_invalidates_the_cache() {
		$report = MPGR_Gift_Report::get_instance();

		$this->assertSame( 1, (int) $report->get_summary()['total_gifts'] );

		$this->add_gift( 'GIFT-CACHE-2' );

		// Without invalidation the stale entry would still say 1.
		do_action( 'mpgft-gift-purchased' );

		$this->assertSame( 2, (int) $report->get_summary()['total_gifts'] );
	}

	/**
	 * Claiming a gift refreshes the summary too.
	 */
	public function test_claim_hook_invalidates_the_cache() {
		$report = MPGR_Gift_Report::get_instance();
		$report->get_summary();

		$queries = $this->count_summary_queries(
			static function () use ( $report ) {
				do_action( 'mpgft-gift-claimed' );
				$report->get_summary();
			}
		);

		$this->assertSame( 1, $queries, 'Expected the claim hook to force a fresh query.' );
	}

	/**
	 * The row count reuses the summary rather than running its own query.
	 */
	public function test_row_count_reuses_the_cached_summary() {
		$report = MPGR_Gift_Report::get_instance();
		$report->get_summary();

		$queries = $this->count_summary_queries(
			function () use ( $report ) {
				$this->invoke_private_method( $report, 'count_report_rows', array( array() ) );
			}
		);

		$this->assertSame( 0, $queries );
	}
}
