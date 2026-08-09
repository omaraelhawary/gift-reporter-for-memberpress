<?php
/**
 * Tests for the intended-recipient lookup.
 *
 * The Gifting add-on's post-purchase popup records who a gift is for, but the
 * meta keys it writes are not public and the add-on is commercial, so the
 * lookup ships with no default keys. These tests register keys of their own to
 * prove the plumbing works whatever the real key turns out to be.
 *
 * @package MemberPressGiftReporter
 */

/**
 * Intended recipient test case.
 */
class IntendedRecipientTest extends MPGR_TestCase {

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
	 * Set up one unclaimed gift.
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
						'post_title'  => 'GIFT-FOR',
						'post_type'   => 'memberpresscoupon',
						'post_status' => 'publish',
					)
				),
			)
		);
	}

	/**
	 * Clear filters and the singleton between tests.
	 */
	public function tear_down() {
		remove_all_filters( 'mpgr_intended_recipient_meta_keys' );
		remove_all_filters( 'mpgr_intended_recipient' );

		$reflection = new ReflectionClass( 'MPGR_Gift_Report' );
		$property   = $reflection->getProperty( 'instance' );
		$property->setAccessible( true );
		$property->setValue( null, null );

		parent::tear_down();
	}

	/**
	 * Point the lookup at meta keys of our choosing.
	 *
	 * @param array $name_keys  Candidate name keys.
	 * @param array $email_keys Candidate email keys.
	 */
	private function register_keys( array $name_keys, array $email_keys ) {
		add_filter(
			'mpgr_intended_recipient_meta_keys',
			static function () use ( $name_keys, $email_keys ) {
				return array(
					'name'  => $name_keys,
					'email' => $email_keys,
				);
			}
		);
	}

	/**
	 * First report row.
	 *
	 * @return array
	 */
	private function row() {
		$rows = MPGR_Gift_Report::get_instance()->generate_report( 10, 0, array() );

		return $rows[0];
	}

	/**
	 * With no keys registered nothing changes — the shipped default.
	 */
	public function test_no_keys_registered_yields_no_recipient() {
		$this->add_transaction_meta( $this->gift_id, '_some_addon_recipient_email', 'friend@example.com' );

		$row = $this->row();

		$this->assertSame( '', $row['intended_recipient_email'] );
		$this->assertSame( '', $row['intended_recipient_name'] );
	}

	/**
	 * Registered keys are read off the purchase transaction.
	 */
	public function test_registered_keys_are_read() {
		$this->add_transaction_meta( $this->gift_id, '_gift_to_email', 'friend@example.com' );
		$this->add_transaction_meta( $this->gift_id, '_gift_to_name', 'Alex Friend' );

		$this->register_keys( array( '_gift_to_name' ), array( '_gift_to_email' ) );

		$row = $this->row();

		$this->assertSame( 'friend@example.com', $row['intended_recipient_email'] );
		$this->assertSame( 'Alex Friend', $row['intended_recipient_name'] );
	}

	/**
	 * Candidate keys are tried in order, so a preferred key wins.
	 */
	public function test_first_non_empty_candidate_key_wins() {
		$this->add_transaction_meta( $this->gift_id, '_new_key', '' );
		$this->add_transaction_meta( $this->gift_id, '_old_key', 'legacy@example.com' );

		$this->register_keys( array(), array( '_new_key', '_old_key' ) );

		$this->assertSame( 'legacy@example.com', $this->row()['intended_recipient_email'] );
	}

	/**
	 * A gift with no popup data is left blank rather than guessed at.
	 */
	public function test_missing_popup_data_stays_empty() {
		$this->register_keys( array( '_gift_to_name' ), array( '_gift_to_email' ) );

		$row = $this->row();

		$this->assertSame( '', $row['intended_recipient_email'] );
		$this->assertSame( '', $row['intended_recipient_name'] );
	}

	/**
	 * The whole lookup can be replaced for storage this cannot model.
	 */
	public function test_recipient_can_be_supplied_by_filter() {
		add_filter(
			'mpgr_intended_recipient',
			static function ( $recipient ) {
				return array(
					'name'  => 'From Filter',
					'email' => 'filter@example.com',
				);
			}
		);

		$row = $this->row();

		$this->assertSame( 'filter@example.com', $row['intended_recipient_email'] );
		$this->assertSame( 'From Filter', $row['intended_recipient_name'] );
	}

	/**
	 * The filter receives the transaction it is resolving.
	 */
	public function test_filter_receives_the_transaction_id() {
		$seen = 0;

		add_filter(
			'mpgr_intended_recipient',
			static function ( $recipient, $transaction_id ) use ( &$seen ) {
				$seen = $transaction_id;
				return $recipient;
			},
			10,
			2
		);

		$this->row();

		$this->assertSame( $this->gift_id, $seen );
	}

	/**
	 * The intended recipient is separate from the confirmed one.
	 */
	public function test_intended_recipient_is_not_the_claimed_recipient() {
		$this->add_transaction_meta( $this->gift_id, '_gift_to_email', 'intended@example.com' );
		$this->register_keys( array(), array( '_gift_to_email' ) );

		$row = $this->row();

		// Unclaimed: an intended recipient exists, a confirmed one does not.
		$this->assertSame( 'unclaimed', $row['gift_status'] );
		$this->assertSame( 'intended@example.com', $row['intended_recipient_email'] );
		$this->assertEmpty( $row['redemption_transaction_id'] );
	}

	/**
	 * Lookups for a page of rows cost one query, not one per row.
	 */
	public function test_lookup_is_batched_across_rows() {
		for ( $i = 0; $i < 4; $i++ ) {
			$id = $this->create_gift_transaction(
				array(
					'user_id'    => self::factory()->user->create(),
					'product_id' => $this->product_id,
					'coupon_id'  => self::factory()->post->create(
						array(
							'post_title'  => 'GIFT-FOR-' . $i,
							'post_type'   => 'memberpresscoupon',
							'post_status' => 'publish',
						)
					),
				)
			);
			$this->add_transaction_meta( $id, '_gift_to_email', 'friend' . $i . '@example.com' );
		}

		$this->register_keys( array(), array( '_gift_to_email' ) );

		$queries = 0;
		$counter = static function ( $query ) use ( &$queries ) {
			if ( false !== strpos( $query, '_gift_to_email' ) ) {
				$queries++;
			}
			return $query;
		};

		add_filter( 'query', $counter );
		$rows = MPGR_Gift_Report::get_instance()->generate_report( 10, 0, array() );
		remove_filter( 'query', $counter );

		$this->assertCount( 5, $rows );
		$this->assertSame( 1, $queries, 'Expected one batched lookup for the whole page.' );
	}
}
