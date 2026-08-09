<?php
/**
 * Tests that report rows are localized at render time, not in SQL.
 *
 * 'Deleted User', 'Deleted Coupon' and the status labels used to be SQL string
 * literals, so the admin table showed English whatever the site locale — the
 * CSV export translated them on the way out, the table did not.
 *
 * @package MemberPressGiftReporter
 */

/**
 * Row localization test case.
 */
class RowLocalizationTest extends MPGR_TestCase {

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
	 * Reset singleton and any installed locale filter between tests.
	 */
	public function tear_down() {
		remove_all_filters( 'gettext' );

		$reflection = new ReflectionClass( 'MPGR_Gift_Report' );
		$property   = $reflection->getProperty( 'instance' );
		$property->setAccessible( true );
		$property->setValue( null, null );

		parent::tear_down();
	}

	/**
	 * Stand in for a translated locale by prefixing every translated string.
	 *
	 * Loading a real .mo file would tie the test to shipped translations; what
	 * matters here is only whether a string passes through the translation
	 * layer at all.
	 */
	private function pretend_locale_translates() {
		add_filter(
			'gettext',
			static function ( $translated, $text, $domain ) {
				return ( 'memberpress-gift-reporter' === $domain ) ? '[xx]' . $translated : $translated;
			},
			10,
			3
		);
	}

	/**
	 * Create one gift and return its report row.
	 *
	 * @param array $args Overrides for create_gift_transaction().
	 * @return array
	 */
	private function row_for( array $args = array() ) {
		$transaction_id = $this->create_gift_transaction(
			array_merge( array( 'product_id' => $this->product_id ), $args )
		);

		$rows = MPGR_Gift_Report::get_instance()->generate_report( 100, 0, array() );

		foreach ( $rows as $row ) {
			if ( (int) $row['gift_transaction_id'] === $transaction_id ) {
				return $row;
			}
		}

		$this->fail( 'Report row not found for transaction ' . $transaction_id );
	}

	/**
	 * A gift whose gifter no longer exists is labelled, and flagged.
	 */
	public function test_missing_gifter_is_labelled_and_flagged() {
		$coupon_id = self::factory()->post->create(
			array(
				'post_title'  => 'GIFT-GONE',
				'post_type'   => 'memberpresscoupon',
				'post_status' => 'publish',
			)
		);

		// user_id 0 stands in for a user row that no longer exists.
		$row = $this->row_for( array( 'user_id' => 0, 'coupon_id' => $coupon_id ) );

		$this->assertTrue( (bool) $row['gifter_deleted'] );
		$this->assertSame( 'Deleted User', $row['gifter_email'] );
	}

	/**
	 * A gift whose coupon was deleted is labelled, and flagged.
	 */
	public function test_missing_coupon_is_labelled_and_flagged() {
		$row = $this->row_for(
			array(
				'user_id'   => self::factory()->user->create(),
				'coupon_id' => 999999,
			)
		);

		$this->assertTrue( (bool) $row['coupon_deleted'] );
		$this->assertSame( 'Deleted Coupon', $row['coupon_code'] );
	}

	/**
	 * An unclaimed gift has no recipient — that is not a deleted user.
	 */
	public function test_unclaimed_gift_recipient_is_not_reported_as_deleted() {
		$coupon_id = self::factory()->post->create(
			array(
				'post_title'  => 'GIFT-OPEN',
				'post_type'   => 'memberpresscoupon',
				'post_status' => 'publish',
			)
		);

		$row = $this->row_for(
			array(
				'user_id'   => self::factory()->user->create(),
				'coupon_id' => $coupon_id,
			)
		);

		$this->assertFalse( (bool) $row['recipient_deleted'] );
	}

	/**
	 * The status label goes through translation.
	 */
	public function test_status_label_is_translated() {
		$this->pretend_locale_translates();

		$this->assertSame( '[xx]Claimed', MPGR_Gift_Report::gift_status_label( 'claimed' ) );
		$this->assertSame( '[xx]Unclaimed', MPGR_Gift_Report::gift_status_label( 'unclaimed' ) );
		$this->assertSame( '[xx]Invalid (Refunded)', MPGR_Gift_Report::gift_status_label( 'refunded' ) );
		$this->assertSame( '[xx]Unknown', MPGR_Gift_Report::gift_status_label( 'anything-else' ) );
	}

	/**
	 * Row labels honour the locale — this is the bug the issue reported.
	 */
	public function test_row_labels_honour_the_active_locale() {
		$coupon_id = self::factory()->post->create(
			array(
				'post_title'  => 'GIFT-LOCALE',
				'post_type'   => 'memberpresscoupon',
				'post_status' => 'publish',
			)
		);

		$this->pretend_locale_translates();

		$row = $this->row_for( array( 'user_id' => 0, 'coupon_id' => $coupon_id ) );

		$this->assertSame( '[xx]Deleted User', $row['gifter_email'] );
		$this->assertSame( '[xx]Unclaimed', $row['gift_status_display'] );
		$this->assertSame( '[xx]Deleted', $row['gifter_status'] );
	}

	/**
	 * The status column stays machine-readable for logic and filtering.
	 */
	public function test_raw_status_stays_machine_readable_under_translation() {
		$coupon_id = self::factory()->post->create(
			array(
				'post_title'  => 'GIFT-RAW',
				'post_type'   => 'memberpresscoupon',
				'post_status' => 'publish',
			)
		);

		$this->pretend_locale_translates();

		$row = $this->row_for(
			array(
				'user_id'   => self::factory()->user->create(),
				'coupon_id' => $coupon_id,
			)
		);

		$this->assertSame( 'unclaimed', $row['gift_status'] );
	}
}
