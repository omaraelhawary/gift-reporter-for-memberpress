<?php
/**
 * Tests for refunded gift accounting across the report surfaces.
 *
 * Refunded purchases used to be counted three different ways: the report added
 * their revenue and counted them as unclaimed, the status column rendered them
 * as plain "Unclaimed", and the reminder/weekly-summary queries excluded them.
 * These tests pin the single rule — refunded gifts are listed, broken out, and
 * excluded from revenue, claim rate, and reminders.
 *
 * @package MemberPressGiftReporter
 */

/**
 * Refunded gift accounting test case.
 */
class RefundedGiftAccountingTest extends MPGR_TestCase {

	/**
	 * Membership post ID used by the fixtures.
	 *
	 * @var int
	 */
	private $product_id;

	/**
	 * Gift transaction IDs keyed by scenario.
	 *
	 * @var array<string, int>
	 */
	private $gifts = array();

	/**
	 * Four $100 gift purchases: one claimed, two unclaimed, one refunded.
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

		$claimed_coupon = $this->create_coupon( 'GIFT-CLAIMED' );

		$this->gifts['claimed'] = $this->create_gift_transaction(
			array(
				'user_id'     => self::factory()->user->create(),
				'product_id'  => $this->product_id,
				'coupon_id'   => $claimed_coupon,
				'status'      => 'complete',
				'gift_status' => 'claimed',
			)
		);

		$this->gifts['unclaimed_complete'] = $this->create_gift_transaction(
			array(
				'user_id'     => self::factory()->user->create(),
				'product_id'  => $this->product_id,
				'coupon_id'   => $this->create_coupon( 'GIFT-UNCLAIMED-1' ),
				'status'      => 'complete',
				'gift_status' => 'unclaimed',
			)
		);

		$this->gifts['unclaimed_confirmed'] = $this->create_gift_transaction(
			array(
				'user_id'     => self::factory()->user->create(),
				'product_id'  => $this->product_id,
				'coupon_id'   => $this->create_coupon( 'GIFT-UNCLAIMED-2' ),
				'status'      => 'confirmed',
				'gift_status' => 'unclaimed',
			)
		);

		// The bug: a refund leaves '_gift_status' as 'unclaimed'.
		$this->gifts['refunded'] = $this->create_gift_transaction(
			array(
				'user_id'     => self::factory()->user->create(),
				'product_id'  => $this->product_id,
				'coupon_id'   => $this->create_coupon( 'GIFT-REFUNDED' ),
				'status'      => 'refunded',
				'gift_status' => 'unclaimed',
			)
		);

		// The redemption transaction that makes the claimed gift claimed.
		$this->create_redemption_transaction(
			$claimed_coupon,
			array(
				'user_id'    => self::factory()->user->create(),
				'product_id' => $this->product_id,
				'created_at' => '2026-01-05 10:00:00',
			)
		);
	}

	/**
	 * The claimed fixture really does join to its redemption.
	 *
	 * Guards the fixture itself: an earlier version set coupon_id on the gift
	 * purchase, so redemption_pick selected the gift as its own redemption and
	 * the recipient join was silently dead in every test.
	 */
	public function test_claimed_gift_joins_to_its_redemption() {
		$row = $this->rows_by_transaction_id()[ $this->gifts['claimed'] ];

		$this->assertNotEmpty( $row['redemption_transaction_id'] );
		$this->assertNotEmpty( $row['redemption_date'] );
		$this->assertFalse( (bool) $row['recipient_deleted'] );
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
	 * Create a published gift coupon post.
	 *
	 * @param string $code Coupon code.
	 * @return int Coupon post ID.
	 */
	private function create_coupon( $code ) {
		return self::factory()->post->create(
			array(
				'post_title'  => $code,
				'post_type'   => 'memberpresscoupon',
				'post_status' => 'publish',
			)
		);
	}

	/**
	 * The fixture tables must be real tables, not temporary ones.
	 *
	 * WP_UnitTestCase rewrites "CREATE TABLE" into "CREATE TEMPORARY TABLE",
	 * and MySQL refuses to reference a temporary table more than once in a
	 * single query -- which every report query does, joining
	 * mepr_transaction_meta five times. That made the whole suite fail on CI's
	 * MySQL while passing locally on MariaDB, which permits the repeated
	 * reference. Asserting the table is real fails the same way on both
	 * engines, so the divergence cannot come back unnoticed.
	 *
	 * information_schema lists base tables only; temporary tables never appear.
	 */
	public function test_fixture_tables_are_real_not_temporary() {
		global $wpdb;

		foreach ( array( 'mepr_transactions', 'mepr_transaction_meta' ) as $suffix ) {
			$table = $wpdb->prefix . $suffix;

			$found = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT table_name FROM information_schema.tables
					 WHERE table_schema = DATABASE() AND table_name = %s',
					$table
				)
			);

			$this->assertSame(
				$table,
				$found,
				"{$table} is not a real table; a temporary table breaks the report query on MySQL."
			);
		}
	}

	/**
	 * Refunded revenue must not inflate the totals.
	 */
	public function test_summary_excludes_refunded_from_revenue() {
		$summary = MPGR_Gift_Report::get_instance()->get_summary();

		// Three non-refunded gifts at $100; the refunded $100 is broken out.
		$this->assertSame( 300.0, (float) $summary['total_revenue'] );
		$this->assertSame( 100.0, (float) $summary['claimed_revenue'] );
		$this->assertSame( 100.0, (float) $summary['refunded_revenue'] );
	}

	/**
	 * Refunded gifts get their own count instead of padding "unclaimed".
	 */
	public function test_summary_breaks_out_refunded_count() {
		$summary = MPGR_Gift_Report::get_instance()->get_summary();

		$this->assertSame( 4, (int) $summary['total_gifts'] );
		$this->assertSame( 1, (int) $summary['claimed_gifts'] );
		$this->assertSame( 2, (int) $summary['unclaimed_gifts'] );
		$this->assertSame( 1, (int) $summary['refunded_gifts'] );
	}

	/**
	 * The three status counts must add up to the total.
	 */
	public function test_summary_counts_reconcile_with_total() {
		$summary = MPGR_Gift_Report::get_instance()->get_summary();

		$this->assertSame(
			(int) $summary['total_gifts'],
			(int) $summary['claimed_gifts'] + (int) $summary['unclaimed_gifts'] + (int) $summary['refunded_gifts']
		);
	}

	/**
	 * A refunded gift was never claimable, so it must not drag the rate down.
	 */
	public function test_claim_rate_ignores_refunded_gifts() {
		$summary = MPGR_Gift_Report::get_instance()->get_summary();

		// 1 claimed of 3 valid gifts, not 1 of 4.
		$this->assertSame( 33.33, (float) $summary['claim_rate'] );
	}

	/**
	 * The status column must say "refunded", not "unclaimed".
	 */
	public function test_report_row_reports_refunded_status() {
		$rows = $this->rows_by_transaction_id();

		$this->assertSame( 'refunded', $rows[ $this->gifts['refunded'] ]['gift_status'] );
		$this->assertSame( 'claimed', $rows[ $this->gifts['claimed'] ]['gift_status'] );
		$this->assertSame( 'unclaimed', $rows[ $this->gifts['unclaimed_complete'] ]['gift_status'] );
	}

	/**
	 * The 'Invalid (Refunded)' display branch used to be unreachable.
	 */
	public function test_report_row_reaches_refunded_display_branch() {
		$rows = $this->rows_by_transaction_id();

		$this->assertSame( 'Invalid (Refunded)', $rows[ $this->gifts['refunded'] ]['gift_status_display'] );
		$this->assertSame( 'Claimed', $rows[ $this->gifts['claimed'] ]['gift_status_display'] );
		$this->assertSame( 'Unclaimed', $rows[ $this->gifts['unclaimed_complete'] ]['gift_status_display'] );
	}

	/**
	 * Refunded gifts stay findable but stop appearing under "unclaimed".
	 */
	public function test_status_filter_separates_refunded_from_unclaimed() {
		$report = MPGR_Gift_Report::get_instance();

		$unclaimed_ids = $this->transaction_ids( $report->generate_report( 100, 0, array( 'gift_status' => 'unclaimed' ) ) );
		$this->assertCount( 2, $unclaimed_ids );
		$this->assertNotContains( $this->gifts['refunded'], $unclaimed_ids );

		$refunded_ids = $this->transaction_ids( $report->generate_report( 100, 0, array( 'gift_status' => 'refunded' ) ) );
		$this->assertSame( array( $this->gifts['refunded'] ), $refunded_ids );
	}

	/**
	 * Refunded gifts remain listed in the unfiltered report for reconciliation.
	 */
	public function test_refunded_gift_still_listed_without_filters() {
		$this->assertArrayHasKey( $this->gifts['refunded'], $this->rows_by_transaction_id() );
	}

	/**
	 * The reminder query already excluded refunded gifts; keep it that way.
	 *
	 * This is the rule the report now matches, so a regression on either side
	 * shows up as a disagreement between these two surfaces.
	 */
	public function test_reminder_query_excludes_refunded_gifts() {
		$unclaimed = $this->invoke_private_static_method(
			'MPGR_Reminders',
			'get_unclaimed_gifts',
			array( time(), 100, 0 )
		);

		$ids = array_map(
			static function ( $row ) {
				return (int) $row->gift_transaction_id;
			},
			$unclaimed
		);

		$this->assertNotContains( $this->gifts['refunded'], $ids );
		$this->assertContains( $this->gifts['unclaimed_complete'], $ids );
	}

	/**
	 * Report rows keyed by gift transaction ID.
	 *
	 * @return array<int, array>
	 */
	private function rows_by_transaction_id() {
		$rows = MPGR_Gift_Report::get_instance()->generate_report( 100, 0, array() );

		$keyed = array();
		foreach ( $rows as $row ) {
			$keyed[ (int) $row['gift_transaction_id'] ] = $row;
		}

		return $keyed;
	}

	/**
	 * Gift transaction IDs from a report result set.
	 *
	 * @param array $rows Report rows.
	 * @return int[]
	 */
	private function transaction_ids( $rows ) {
		return array_map(
			static function ( $row ) {
				return (int) $row['gift_transaction_id'];
			},
			$rows
		);
	}
}
