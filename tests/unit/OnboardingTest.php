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

	/**
	 * Cliffhanger hidden when reminders are enabled.
	 */
	public function test_cliffhanger_hidden_when_reminders_enabled() {
		update_option(
			'mpgr_reminder_settings',
			array(
				'enabled' => true,
			)
		);

		set_transient(
			MPGR_Onboarding::PULSE_TRANSIENT,
			array(
				'unclaimed_count'          => 5,
				'revenue_at_risk'          => 100.0,
				'revenue_at_risk_formatted' => '$100.00',
			),
			300
		);

		$this->assertFalse( MPGR_Onboarding::should_show_cliffhanger() );
	}

	/**
	 * Cliffhanger hidden when snoozed.
	 */
	public function test_cliffhanger_hidden_when_snoozed() {
		set_transient(
			MPGR_Onboarding::PULSE_TRANSIENT,
			array(
				'unclaimed_count'          => 5,
				'revenue_at_risk'          => 100.0,
				'revenue_at_risk_formatted' => '$100.00',
			),
			300
		);

		update_user_meta( $this->admin_id, MPGR_Onboarding::META_CLIFFHANGER_SNOOZE, time() + WEEK_IN_SECONDS );

		$this->assertFalse( MPGR_Onboarding::should_show_cliffhanger() );
	}

	/**
	 * Cliffhanger hidden when no unclaimed gifts.
	 */
	public function test_cliffhanger_hidden_when_zero_unclaimed() {
		set_transient(
			MPGR_Onboarding::PULSE_TRANSIENT,
			array(
				'unclaimed_count'          => 0,
				'revenue_at_risk'          => 0.0,
				'revenue_at_risk_formatted' => '$0.00',
			),
			300
		);

		$this->assertFalse( MPGR_Onboarding::should_show_cliffhanger() );
	}

	/**
	 * Minimum reminder delay defaults to 7 days.
	 */
	public function test_get_minimum_reminder_delay_days() {
		$this->assertSame( 7, MPGR_Onboarding::get_minimum_reminder_delay_days() );
	}

	/**
	 * Recovery reel hidden without prior snapshot.
	 */
	public function test_recovery_reel_hidden_without_prior_snapshot() {
		ob_start();
		MPGR_Onboarding::render_recovery_reel(
			array(
				'claimed_gifts'   => 5,
				'claim_rate'      => 50.0,
				'claimed_revenue' => 100.0,
			),
			array()
		);
		$output = ob_get_clean();

		$this->assertSame( '', $output );
	}

	/**
	 * Recovery reel shows delta when prior snapshot exists.
	 */
	public function test_recovery_reel_shows_delta_with_prior_snapshot() {
		ob_start();
		MPGR_Onboarding::render_recovery_reel(
			array(
				'claimed_gifts'   => 5,
				'claim_rate'      => 50.0,
				'claimed_revenue' => 200.0,
			),
			array(
				'claimed_gifts'   => 3,
				'claim_rate'      => 40.0,
				'claimed_revenue' => 100.0,
			)
		);
		$output = ob_get_clean();

		$this->assertStringContainsString( 'mpgr-recovery-reel', $output );
		$this->assertStringContainsString( '2 gifts claimed', $output );
	}

	/**
	 * save_report_snapshot persists summary fields.
	 */
	public function test_save_report_snapshot() {
		MPGR_Onboarding::save_report_snapshot(
			array(
				'unclaimed_gifts' => 2,
				'claimed_gifts'   => 8,
				'claim_rate'      => 80.0,
				'total_revenue'   => 500.0,
				'claimed_revenue' => 400.0,
			)
		);

		$snapshot = get_option( MPGR_Onboarding::SNAPSHOT_OPTION );
		$this->assertSame( 8, $snapshot['claimed_gifts'] );
		$this->assertSame( 400.0, $snapshot['claimed_revenue'] );
	}

	/**
	 * Aging arcs returns three buckets.
	 */
	public function test_get_unclaimed_aging_arcs_returns_buckets() {
		$report = MPGR_Gift_Report::get_instance();
		$arcs   = $report->get_unclaimed_aging_arcs();

		$this->assertArrayHasKey( '7-14', $arcs );
		$this->assertArrayHasKey( '14-30', $arcs );
		$this->assertArrayHasKey( '30plus', $arcs );
		$this->assertArrayHasKey( 'filter_url', $arcs['7-14'] );
		$this->assertArrayHasKey( 'bulk_remind_url', $arcs['7-14'] );
		$this->assertStringContainsString( 'mpgr_bulk_remind=1', $arcs['7-14']['bulk_remind_url'] );
		$this->assertNotSame( $arcs['7-14']['filter_url'], $arcs['7-14']['bulk_remind_url'] );
	}

	/**
	 * invalidate_pulse_cache clears aging arcs transient.
	 */
	public function test_invalidate_pulse_cache_clears_aging_arcs() {
		set_transient( MPGR_Onboarding::AGING_TRANSIENT, array( '7-14' => array() ), 300 );

		MPGR_Onboarding::invalidate_pulse_cache();

		$this->assertFalse( get_transient( MPGR_Onboarding::AGING_TRANSIENT ) );
	}

	/**
	 * Monday Pulse hidden until report has been viewed once.
	 */
	public function test_monday_pulse_hidden_before_first_view() {
		$this->assertFalse( MPGR_Onboarding::should_show_monday_pulse() );
	}

	/**
	 * Monday Pulse shows after first view when weekly summary disabled.
	 */
	public function test_monday_pulse_shows_after_first_view() {
		update_user_meta( $this->admin_id, MPGR_Onboarding::META_REPORT_VIEWED, time() );

		$this->assertTrue( MPGR_Onboarding::should_show_monday_pulse() );
	}

	/**
	 * send_preview_email uses wp_mail.
	 */
	public function test_send_preview_email_sends_mail() {
		if ( ! class_exists( 'MPGR_Weekly_Summary' ) ) {
			require_once MPGR_PLUGIN_PATH . 'includes/class-weekly-summary.php';
		}

		$sent = false;
		add_filter(
			'pre_wp_mail',
			function ( $null, $atts ) use ( &$sent ) {
				unset( $null );
				$sent = true;
				return true;
			},
			10,
			2
		);

		$result = MPGR_Weekly_Summary::send_preview_email( 'admin@example.com' );

		$this->assertTrue( $result );
		$this->assertTrue( $sent );
	}

	/**
	 * AJAX enable weekly summary schedules cron.
	 */
	public function test_ajax_enable_weekly_summary_schedules_cron() {
		if ( ! class_exists( 'MPGR_Weekly_Summary' ) ) {
			require_once MPGR_PLUGIN_PATH . 'includes/class-weekly-summary.php';
		}

		wp_clear_scheduled_hook( 'mpgr_run_weekly_summary' );
		$_REQUEST['nonce'] = wp_create_nonce( 'mpgr_onboarding_nonce' );

		try {
			MPGR_Onboarding::ajax_enable_weekly_summary();
			$this->fail( 'Expected WPAjaxDieContinueException.' );
		} catch ( WPAjaxDieContinueException $e ) {
			unset( $e );
			$this->assertNotFalse( wp_next_scheduled( 'mpgr_run_weekly_summary' ) );
			$settings = get_option( 'mpgr_weekly_summary_settings' );
			$this->assertTrue( $settings['enabled'] );
		} finally {
			unset( $_REQUEST['nonce'] );
			wp_clear_scheduled_hook( 'mpgr_run_weekly_summary' );
		}
	}

	/**
	 * New installs default weekly summary to enabled when activation ts set.
	 */
	public function test_weekly_summary_defaults_on_for_new_install() {
		if ( ! class_exists( 'MPGR_Weekly_Summary' ) ) {
			require_once MPGR_PLUGIN_PATH . 'includes/class-weekly-summary.php';
		}

		add_option( 'mpgr_activation_ts', time() );
		delete_option( 'mpgr_weekly_summary_settings' );

		$settings = MPGR_Weekly_Summary::get_settings();

		$this->assertTrue( $settings['enabled'] );
	}
}
