<?php
/**
 * Tests for MPGR_Gift_Report core behavior.
 *
 * @package MemberPressGiftReporter
 */

/**
 * Gift report test case.
 */
class GiftReportTest extends MPGR_TestCase {

	/**
	 * Reset singleton and any simulated auth cookie between tests.
	 */
	public function tear_down() {
		$reflection = new ReflectionClass( 'MPGR_Gift_Report' );
		$property   = $reflection->getProperty( 'instance' );
		$property->setAccessible( true );
		$property->setValue( null, null );

		unset( $_COOKIE[ LOGGED_IN_COOKIE ] );

		parent::tear_down();
	}

	/**
	 * get_instance should return a single shared object.
	 */
	public function test_get_instance_returns_singleton() {
		$first  = MPGR_Gift_Report::get_instance();
		$second = MPGR_Gift_Report::get_instance();

		$this->assertInstanceOf( MPGR_Gift_Report::class, $first );
		$this->assertSame( $first, $second );
	}

	/**
	 * REST filter args should include all report filter fields.
	 */
	public function test_get_rest_filter_args_includes_report_filters() {
		$args = MPGR_Gift_Report::get_rest_filter_args();

		$this->assertArrayHasKey( 'nonce', $args );
		$this->assertArrayHasKey( 'date_from', $args );
		$this->assertArrayHasKey( 'gift_status', $args );
		$this->assertArrayHasKey( 'product', $args );
		$this->assertSame( 'intval', $args['product']['sanitize_callback'] );
	}

	/**
	 * CSV cells that start with formula characters should be neutralized.
	 */
	public function test_csv_sanitize_cell_prefixes_formula_values() {
		$report = MPGR_Gift_Report::get_instance();

		$this->assertSame( "'=1+1", $this->invoke_private_method( $report, 'csv_sanitize_cell', array( '=1+1' ) ) );
		$this->assertSame( "'-100", $this->invoke_private_method( $report, 'csv_sanitize_cell', array( '-100' ) ) );
		$this->assertSame( 'safe-value', $this->invoke_private_method( $report, 'csv_sanitize_cell', array( 'safe-value' ) ) );
	}

	/**
	 * Cookie-authenticated requests still require the wp_rest nonce.
	 *
	 * This is the case CSRF protection exists for: the browser attaches the
	 * login cookie on its own, so the request needs to prove intent.
	 */
	public function test_rest_permission_check_requires_rest_nonce_for_cookie_auth() {
		$report  = MPGR_Gift_Report::get_instance();
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $user_id, time() + HOUR_IN_SECONDS, 'logged_in' );

		$request = new WP_REST_Request( 'GET', '/mpgr/v1/report' );
		$this->assertFalse( $report->rest_permission_check( $request ) );

		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$this->assertTrue( $report->rest_permission_check( $request ) );
	}

	/**
	 * An invalid nonce on a cookie request is still rejected.
	 */
	public function test_rest_permission_check_rejects_invalid_nonce_for_cookie_auth() {
		$report  = MPGR_Gift_Report::get_instance();
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $user_id, time() + HOUR_IN_SECONDS, 'logged_in' );

		$request = new WP_REST_Request( 'GET', '/mpgr/v1/report' );
		$request->set_header( 'X-WP-Nonce', 'not-a-real-nonce' );

		$this->assertFalse( $report->rest_permission_check( $request ) );
	}

	/**
	 * Application Password requests must be allowed without a nonce.
	 *
	 * Such a request carries no login cookie and has no way to obtain a
	 * wp_rest nonce, which is what previously locked every non-browser client
	 * out of the endpoint.
	 */
	public function test_rest_permission_check_allows_non_cookie_auth_without_nonce() {
		$report  = MPGR_Gift_Report::get_instance();
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		unset( $_COOKIE[ LOGGED_IN_COOKIE ] );

		$request = new WP_REST_Request( 'GET', '/mpgr/v1/report' );

		$this->assertTrue( $report->rest_permission_check( $request ) );
	}

	/**
	 * Dropping the nonce requirement must not drop the capability check.
	 */
	public function test_rest_permission_check_still_requires_manage_options() {
		$report  = MPGR_Gift_Report::get_instance();
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		unset( $_COOKIE[ LOGGED_IN_COOKIE ] );

		$request = new WP_REST_Request( 'GET', '/mpgr/v1/report' );

		$this->assertFalse( $report->rest_permission_check( $request ) );
	}
}
