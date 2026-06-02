<?php
/**
 * Tests for MPGR_Onboarding (Phase 0).
 *
 * @package MemberPressGiftReporter
 */

/**
 * Onboarding test case.
 */
class OnboardingTest extends MPGR_TestCase {

	/**
	 * Admin user ID for capability tests.
	 *
	 * @var int
	 */
	private $admin_id = 0;

	/**
	 * Set up admin user and load onboarding class.
	 */
	public function set_up() {
		parent::set_up();

		if ( ! class_exists( 'MPGR_Onboarding' ) ) {
			require_once MPGR_PLUGIN_PATH . 'includes/class-onboarding.php';
		}

		$this->admin_id = self::factory()->user->create(
			array(
				'role' => 'administrator',
			)
		);
		wp_set_current_user( $this->admin_id );

		if ( ! defined( 'WP_ADMIN' ) ) {
			define( 'WP_ADMIN', true );
		}
	}

	/**
	 * Welcome banner shows until dismissed.
	 */
	public function test_should_show_welcome_until_dismissed() {
		$this->assertTrue( MPGR_Onboarding::should_show_welcome() );

		update_user_meta( $this->admin_id, MPGR_Onboarding::META_WELCOME, 1 );

		$this->assertFalse( MPGR_Onboarding::should_show_welcome() );
	}

	/**
	 * Admin bar pulse hidden when dismissed.
	 */
	public function test_should_show_admin_bar_false_when_dismissed() {
		set_transient(
			MPGR_Onboarding::PULSE_TRANSIENT,
			array(
				'unclaimed_count'            => 3,
				'revenue_at_risk'             => 99.0,
				'revenue_at_risk_formatted'   => '$99.00',
			),
			300
		);

		$this->assertTrue( MPGR_Onboarding::should_show_admin_bar() );

		update_user_meta( $this->admin_id, MPGR_Onboarding::META_ADMIN_BAR, 1 );

		$this->assertFalse( MPGR_Onboarding::should_show_admin_bar() );
	}

	/**
	 * Admin bar pulse hidden when unclaimed count is zero.
	 */
	public function test_should_show_admin_bar_false_when_no_unclaimed() {
		set_transient(
			MPGR_Onboarding::PULSE_TRANSIENT,
			array(
				'unclaimed_count'            => 0,
				'revenue_at_risk'             => 0.0,
				'revenue_at_risk_formatted'   => '$0.00',
			),
			300
		);

		$this->assertFalse( MPGR_Onboarding::should_show_admin_bar() );
	}

	/**
	 * invalidate_pulse_cache removes transient.
	 */
	public function test_invalidate_pulse_cache_deletes_transient() {
		set_transient( MPGR_Onboarding::PULSE_TRANSIENT, array( 'unclaimed_count' => 1 ), 300 );

		MPGR_Onboarding::invalidate_pulse_cache();

		$this->assertFalse( get_transient( MPGR_Onboarding::PULSE_TRANSIENT ) );
	}

	/**
	 * Dismiss welcome via AJAX handler sets user meta.
	 */
	public function test_ajax_dismiss_welcome_sets_user_meta() {
		$_REQUEST['nonce'] = wp_create_nonce( 'mpgr_onboarding_nonce' );

		try {
			MPGR_Onboarding::ajax_dismiss_welcome();
			$this->fail( 'Expected WPAjaxDieContinueException.' );
		} catch ( WPAjaxDieContinueException $e ) {
			unset( $e );
			$this->assertSame( '1', get_user_meta( $this->admin_id, MPGR_Onboarding::META_WELCOME, true ) );
		} finally {
			unset( $_REQUEST['nonce'] );
		}
	}

	/**
	 * get_report_url returns admin URL with filter nonce.
	 */
	public function test_get_report_url_includes_gift_status_and_nonce() {
		$report = MPGR_Gift_Report::get_instance();
		$url    = $report->get_report_url( array( 'gift_status' => 'unclaimed' ) );

		$this->assertStringContainsString( 'page=memberpress-gift-report', $url );
		$this->assertStringContainsString( 'gift_status=unclaimed', $url );
		$this->assertStringContainsString( '_wpnonce=', $url );
	}
}
