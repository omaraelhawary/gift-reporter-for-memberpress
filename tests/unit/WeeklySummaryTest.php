<?php
/**
 * Tests for weekly summary settings.
 *
 * @package MemberPressGiftReporter
 */

/**
 * Weekly summary test case.
 */
class WeeklySummaryTest extends MPGR_TestCase {

	/**
	 * Weekly summary should be disabled by default.
	 */
	public function test_get_settings_defaults_to_disabled() {
		$settings = MPGR_Weekly_Summary::get_settings();

		$this->assertFalse( $settings['enabled'] );
	}

	/**
	 * Test data helper should return a complete sample payload.
	 */
	public function test_get_test_data_contains_expected_keys() {
		$data = MPGR_Weekly_Summary::get_test_data();

		$this->assertArrayHasKey( 'total_gifts', $data );
		$this->assertArrayHasKey( 'claimed_gifts', $data );
		$this->assertArrayHasKey( 'unclaimed_gifts', $data );
		$this->assertArrayHasKey( 'products', $data );
		$this->assertArrayHasKey( 'daily_stats', $data );
		$this->assertGreaterThan( 0, $data['total_gifts'] );
	}
}
