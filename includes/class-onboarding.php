<?php
/**
 * Onboarding and retention UX (Phase 0).
 *
 * @package MemberPressGiftReporter
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Welcome banner, admin-bar pulse, and dismiss handlers.
 */
class MPGR_Onboarding {

	const PULSE_TRANSIENT     = 'mpgr_pulse_stats';
	const PULSE_TTL           = 300;
	const META_WELCOME        = 'mpgr_welcome_dismissed';
	const META_ADMIN_BAR      = 'mpgr_admin_bar_dismissed';

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'admin_bar_menu', array( __CLASS__, 'register_admin_bar_node' ), 100 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_global_assets' ) );
		add_action( 'wp_ajax_mpgr_dismiss_welcome', array( __CLASS__, 'ajax_dismiss_welcome' ) );
		add_action( 'wp_ajax_mpgr_dismiss_admin_bar', array( __CLASS__, 'ajax_dismiss_admin_bar' ) );
		add_action( 'mpgft-gift-purchased', array( __CLASS__, 'invalidate_pulse_cache' ) );
		add_action( 'mpgft-gift-claimed', array( __CLASS__, 'invalidate_pulse_cache' ) );
	}

	/**
	 * Whether the current user should see onboarding UI.
	 *
	 * @return bool
	 */
	public static function user_can_see() {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		return class_exists( 'memberpress\gifting\models\Gift' );
	}

	/**
	 * Clear cached pulse stats.
	 */
	public static function invalidate_pulse_cache() {
		delete_transient( self::PULSE_TRANSIENT );
	}

	/**
	 * Cached unclaimed gift pulse for admin bar.
	 *
	 * @return array{unclaimed_count:int,revenue_at_risk:float,revenue_at_risk_formatted:string}
	 */
	public static function get_pulse_stats() {
		$cached = get_transient( self::PULSE_TRANSIENT );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$empty = array(
			'unclaimed_count'            => 0,
			'revenue_at_risk'             => 0.0,
			'revenue_at_risk_formatted'   => '',
		);

		if ( ! class_exists( 'MPGR_Gift_Report' ) ) {
			return $empty;
		}

		$report  = MPGR_Gift_Report::get_instance();
		$summary = $report->get_summary( array( 'gift_status' => 'unclaimed' ) );

		$stats = array(
			'unclaimed_count'          => isset( $summary['total_gifts'] ) ? (int) $summary['total_gifts'] : 0,
			'revenue_at_risk'          => isset( $summary['total_revenue'] ) ? (float) $summary['total_revenue'] : 0.0,
			'revenue_at_risk_formatted' => isset( $summary['total_revenue_formatted'] ) ? $summary['total_revenue_formatted'] : '',
		);

		set_transient( self::PULSE_TRANSIENT, $stats, self::PULSE_TTL );

		return $stats;
	}

	/**
	 * Whether the welcome banner should display.
	 *
	 * @return bool
	 */
	public static function should_show_welcome() {
		if ( ! self::user_can_see() ) {
			return false;
		}

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return false;
		}

		return ! (bool) get_user_meta( $user_id, self::META_WELCOME, true );
	}

	/**
	 * Whether the admin-bar pulse should display.
	 *
	 * @return bool
	 */
	public static function should_show_admin_bar() {
		if ( ! self::user_can_see() ) {
			return false;
		}

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return false;
		}

		if ( get_user_meta( $user_id, self::META_ADMIN_BAR, true ) ) {
			return false;
		}

		$pulse = self::get_pulse_stats();

		return $pulse['unclaimed_count'] > 0;
	}

	/**
	 * Output welcome banner on Gift Report tab.
	 */
	public static function render_welcome_banner() {
		if ( ! self::should_show_welcome() ) {
			return;
		}

		$report = MPGR_Gift_Report::get_instance();

		$unclaimed_url  = $report->get_report_url( array( 'gift_status' => 'unclaimed' ) );
		$reminders_url  = admin_url( 'admin.php?page=memberpress-gift-report&tab=reminders' );

		echo '<div class="notice notice-info is-dismissible mpgr-welcome-banner" id="mpgr-welcome-banner">';
		echo '<div class="mpgr-welcome-banner__content">';
		echo '<p class="mpgr-welcome-banner__message">';
		echo esc_html__( 'Track unclaimed gifts and recover revenue before they expire.', 'memberpress-gift-reporter' );
		echo '</p>';
		echo '<div class="mpgr-welcome-banner__actions">';
		echo '<a href="' . esc_url( $unclaimed_url ) . '" class="button button-primary">';
		echo esc_html__( 'View unclaimed', 'memberpress-gift-reporter' );
		echo '</a> ';
		echo '<a href="' . esc_url( $reminders_url ) . '" class="button">';
		echo esc_html__( 'Set up reminders', 'memberpress-gift-reporter' );
		echo '</a> ';
		echo '<button type="button" class="button mpgr-welcome-export">';
		echo esc_html__( 'Export CSV', 'memberpress-gift-reporter' );
		echo '</button>';
		echo '</div>';
		echo '</div>';
		echo '<button type="button" class="notice-dismiss mpgr-welcome-dismiss" aria-label="' . esc_attr__( 'Dismiss welcome message', 'memberpress-gift-reporter' ) . '">';
		echo '<span class="screen-reader-text">' . esc_html__( 'Dismiss', 'memberpress-gift-reporter' ) . '</span>';
		echo '</button>';
		echo '</div>';
	}

	/**
	 * Add admin-bar unclaimed pulse node.
	 *
	 * @param WP_Admin_Bar $wp_admin_bar Admin bar instance.
	 */
	public static function register_admin_bar_node( $wp_admin_bar ) {
		if ( ! self::should_show_admin_bar() ) {
			return;
		}

		$pulse = self::get_pulse_stats();
		$report = MPGR_Gift_Report::get_instance();
		$url    = $report->get_report_url( array( 'gift_status' => 'unclaimed' ) );

		$title = sprintf(
			/* translators: 1: unclaimed gift count, 2: formatted revenue at risk */
			__( 'Gift Reporter · %1$d unclaimed (%2$s at risk)', 'memberpress-gift-reporter' ),
			(int) $pulse['unclaimed_count'],
			$pulse['revenue_at_risk_formatted']
		);

		$wp_admin_bar->add_node(
			array(
				'id'    => 'mpgr-gift-pulse',
				'title' => $title,
				'href'  => $url,
				'meta'  => array(
					'class' => 'mpgr-gift-pulse',
					'title' => esc_attr__( 'View unclaimed gifts in Gift Report', 'memberpress-gift-reporter' ),
				),
			)
		);
	}

	/**
	 * Enqueue onboarding assets on admin screens.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public static function enqueue_global_assets( $hook ) {
		if ( ! self::user_can_see() ) {
			return;
		}

		// admin.css is already enqueued as mpgr-admin-styles on the Gift Report page.
		if ( 'memberpress_page_memberpress-gift-report' !== $hook ) {
			$css = file_exists( MPGR_PLUGIN_PATH . 'assets/css/admin.min.css' )
				? MPGR_PLUGIN_URL . 'assets/css/admin.min.css'
				: MPGR_PLUGIN_URL . 'assets/css/admin.css';

			wp_enqueue_style(
				'mpgr-admin-styles',
				$css,
				array(),
				MPGR_VERSION
			);
		}

		$js = file_exists( MPGR_PLUGIN_PATH . 'assets/js/onboarding.min.js' )
			? MPGR_PLUGIN_URL . 'assets/js/onboarding.min.js'
			: MPGR_PLUGIN_URL . 'assets/js/onboarding.js';

		wp_enqueue_script(
			'mpgr-onboarding-script',
			$js,
			array( 'jquery' ),
			MPGR_VERSION,
			true
		);

		wp_localize_script(
			'mpgr-onboarding-script',
			'mpgr_onboarding',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'mpgr_onboarding_nonce' ),
			)
		);
	}

	/**
	 * AJAX: dismiss welcome banner.
	 */
	public static function ajax_dismiss_welcome() {
		if ( ! check_ajax_referer( 'mpgr_onboarding_nonce', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'memberpress-gift-reporter' ) ), 403 );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'memberpress-gift-reporter' ) ), 403 );
		}

		$user_id = get_current_user_id();
		if ( $user_id ) {
			update_user_meta( $user_id, self::META_WELCOME, 1 );
		}

		wp_send_json_success();
	}

	/**
	 * AJAX: dismiss admin-bar pulse.
	 */
	public static function ajax_dismiss_admin_bar() {
		if ( ! check_ajax_referer( 'mpgr_onboarding_nonce', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'memberpress-gift-reporter' ) ), 403 );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'memberpress-gift-reporter' ) ), 403 );
		}

		$user_id = get_current_user_id();
		if ( $user_id ) {
			update_user_meta( $user_id, self::META_ADMIN_BAR, 1 );
		}

		wp_send_json_success();
	}
}
