<?php
/**
 * Tests for MPGR_Gift_Report::sanitize_filters().
 *
 * @package MemberPressGiftReporter
 */

/**
 * Sanitize filters test case.
 */
class SanitizeFiltersTest extends MPGR_TestCase {

	/**
	 * Filter schema should sanitize expected fields only.
	 */
	public function test_sanitize_filters_from_array() {
		$source = array(
			'date_from'       => '2026-01-01',
			'date_to'         => '2026-01-31',
			'gift_status'     => 'unclaimed',
			'product'         => '42',
			'gifter_email'    => 'gifter@example.com',
			'recipient_email' => 'not-an-email',
			'unknown_field'   => 'ignored',
		);

		$filters = MPGR_Gift_Report::sanitize_filters( $source );

		$this->assertSame( '2026-01-01', $filters['date_from'] );
		$this->assertSame( '2026-01-31', $filters['date_to'] );
		$this->assertSame( 'unclaimed', $filters['gift_status'] );
		$this->assertSame( 42, $filters['product'] );
		$this->assertSame( 'gifter@example.com', $filters['gifter_email'] );
		$this->assertSame( '', $filters['recipient_email'] );
		$this->assertArrayNotHasKey( 'unknown_field', $filters );
	}

	/**
	 * Empty values should be omitted from the result.
	 */
	public function test_sanitize_filters_omits_empty_values() {
		$filters = MPGR_Gift_Report::sanitize_filters(
			array(
				'date_from'   => '',
				'gift_status' => 'claimed',
			)
		);

		$this->assertArrayNotHasKey( 'date_from', $filters );
		$this->assertSame( 'claimed', $filters['gift_status'] );
	}
}
