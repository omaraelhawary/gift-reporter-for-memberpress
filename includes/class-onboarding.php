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

	const PULSE_TRANSIENT        = 'mpgr_pulse_stats';
	const AGING_TRANSIENT        = 'mpgr_aging_arcs';
	const PULSE_TTL              = 300;
	const META_WELCOME           = 'mpgr_welcome_dismissed';
	const META_ADMIN_BAR         = 'mpgr_admin_bar_dismissed';
	const META_CLIFFHANGER_SNOOZE = 'mpgr_cliffhanger_snooze';
	const META_MONDAY_PULSE      = 'mpgr_monday_pulse_dismissed';
	const META_REPORT_VIEWED     = 'mpgr_report_viewed';
	const SNAPSHOT_OPTION        = 'mpgr_last_report_snapshot';

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'admin_bar_menu', array( __CLASS__, 'register_admin_bar_node' ), 100 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_global_assets' ) );
		add_action( 'wp_ajax_mpgr_dismiss_welcome', array( __CLASS__, 'ajax_dismiss_welcome' ) );
		add_action( 'wp_ajax_mpgr_dismiss_admin_bar', array( __CLASS__, 'ajax_dismiss_admin_bar' ) );
		add_action( 'wp_ajax_mpgr_snooze_cliffhanger', array( __CLASS__, 'ajax_snooze_cliffhanger' ) );
		add_action( 'wp_ajax_mpgr_dismiss_monday_pulse', array( __CLASS__, 'ajax_dismiss_monday_pulse' ) );
		add_action( 'wp_ajax_mpgr_enable_weekly_summary', array( __CLASS__, 'ajax_enable_weekly_summary' ) );
		add_action( 'wp_ajax_mpgr_send_weekly_preview', array( __CLASS__, 'ajax_send_weekly_preview' ) );
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
		delete_transient( self::AGING_TRANSIENT );
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
				'ajax_url'      => admin_url( 'admin-ajax.php' ),
				'nonce'         => wp_create_nonce( 'mpgr_onboarding_nonce' ),
				'reminders_url' => admin_url( 'admin.php?page=memberpress-gift-report&tab=reminders#mpgr_reminder_enabled' ),
				'i18n'          => array(
					'preview_sent'   => __( 'Preview email sent.', 'memberpress-gift-reporter' ),
					'preview_failed' => __( 'Could not send preview email.', 'memberpress-gift-reporter' ),
					'enable_failed'  => __( 'Could not enable weekly summary.', 'memberpress-gift-reporter' ),
				),
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

	/**
	 * Record first report view; return true if this is a return visit.
	 *
	 * @return bool
	 */
	public static function mark_report_viewed() {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return false;
		}

		$viewed = get_user_meta( $user_id, self::META_REPORT_VIEWED, true );
		if ( ! $viewed ) {
			update_user_meta( $user_id, self::META_REPORT_VIEWED, time() );
			return false;
		}

		return true;
	}

	/**
	 * Minimum first-reminder delay in whole days from reminder schedules.
	 *
	 * @return int
	 */
	public static function get_minimum_reminder_delay_days() {
		if ( ! class_exists( 'MPGR_Reminders' ) ) {
			return 7;
		}

		$settings  = MPGR_Reminders::get_settings();
		$schedules = ! empty( $settings['reminder_schedules'] ) ? $settings['reminder_schedules'] : array();
		$min_days  = null;

		foreach ( $schedules as $schedule ) {
			if ( ! is_array( $schedule ) ) {
				continue;
			}

			$value = isset( $schedule['delay_value'] ) ? (int) $schedule['delay_value'] : ( isset( $schedule['delay_days'] ) ? (int) $schedule['delay_days'] : 0 );
			$unit  = isset( $schedule['delay_unit'] ) ? $schedule['delay_unit'] : 'days';

			if ( $value <= 0 ) {
				continue;
			}

			$days = ( 'hours' === $unit ) ? (int) ceil( $value / 24 ) : $value;
			if ( null === $min_days || $days < $min_days ) {
				$min_days = $days;
			}
		}

		return $min_days ? max( 1, (int) $min_days ) : 7;
	}

	/**
	 * Count unclaimed gifts approaching the first scheduled reminder.
	 *
	 * @return int
	 */
	public static function count_gifts_approaching_first_reminder() {
		if ( ! class_exists( 'MPGR_Gift_Report' ) ) {
			return 0;
		}

		$delay   = self::get_minimum_reminder_delay_days();
		$window  = min( 7, $delay );
		$report  = MPGR_Gift_Report::get_instance();
		$summary = $report->get_summary(
			array(
				'gift_status' => 'unclaimed',
				'date_from'   => gmdate( 'Y-m-d', strtotime( '-' . $delay . ' days' ) ),
				'date_to'     => gmdate( 'Y-m-d', strtotime( '-' . max( 0, $delay - $window ) . ' days' ) ),
			)
		);

		return isset( $summary['total_gifts'] ) ? (int) $summary['total_gifts'] : 0;
	}

	/**
	 * Whether the day-7 cliffhanger card should display.
	 *
	 * @return bool
	 */
	public static function should_show_cliffhanger() {
		if ( ! self::user_can_see() ) {
			return false;
		}

		if ( ! class_exists( 'MPGR_Reminders' ) ) {
			return false;
		}

		$settings = MPGR_Reminders::get_settings();
		if ( ! empty( $settings['enabled'] ) ) {
			return false;
		}

		$user_id = get_current_user_id();
		if ( $user_id ) {
			$snooze_until = (int) get_user_meta( $user_id, self::META_CLIFFHANGER_SNOOZE, true );
			if ( $snooze_until && $snooze_until > time() ) {
				return false;
			}
		}

		$pulse = self::get_pulse_stats();

		return $pulse['unclaimed_count'] > 0;
	}

	/**
	 * Output day-7 cliffhanger card at the bottom of the report.
	 */
	public static function render_cliffhanger() {
		if ( ! self::should_show_cliffhanger() ) {
			return;
		}

		$pulse       = self::get_pulse_stats();
		$approaching = self::count_gifts_approaching_first_reminder();
		$reminders_url = admin_url( 'admin.php?page=memberpress-gift-report&tab=reminders#mpgr_reminder_enabled' );

		echo '<div class="mpgr-cliffhanger notice notice-warning inline">';
		echo '<p class="mpgr-cliffhanger__message">';
		if ( $approaching > 0 ) {
			printf(
				/* translators: 1: unclaimed count, 2: gifts approaching first reminder */
				esc_html__( 'You have %1$d unclaimed gifts. %2$d will hit day 7 this week. Turn on automatic reminders to gifters?', 'memberpress-gift-reporter' ),
				(int) $pulse['unclaimed_count'],
				(int) $approaching
			);
		} else {
			printf(
				/* translators: %d: unclaimed gift count */
				esc_html__( 'You have %d unclaimed gifts. Turn on automatic reminders to gifters?', 'memberpress-gift-reporter' ),
				(int) $pulse['unclaimed_count']
			);
		}
		echo '</p>';
		echo '<div class="mpgr-cliffhanger__actions">';
		echo '<a href="' . esc_url( $reminders_url ) . '" class="button button-primary mpgr-cliffhanger-enable">';
		echo esc_html__( 'Enable reminders', 'memberpress-gift-reporter' );
		echo '</a> ';
		echo '<button type="button" class="button mpgr-cliffhanger-snooze">';
		echo esc_html__( 'Remind me in 7 days', 'memberpress-gift-reporter' );
		echo '</button>';
		echo '</div>';
		echo '</div>';
	}

	/**
	 * Whether the Monday Pulse prompt should display.
	 *
	 * @return bool
	 */
	public static function should_show_monday_pulse() {
		if ( ! self::user_can_see() ) {
			return false;
		}

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return false;
		}

		if ( ! get_user_meta( $user_id, self::META_REPORT_VIEWED, true ) ) {
			return false;
		}

		if ( get_user_meta( $user_id, self::META_MONDAY_PULSE, true ) ) {
			return false;
		}

		if ( ! class_exists( 'MPGR_Weekly_Summary' ) ) {
			return false;
		}

		$settings = MPGR_Weekly_Summary::get_settings();

		return empty( $settings['enabled'] );
	}

	/**
	 * Output Monday Pulse weekly summary onboarding card.
	 */
	public static function render_monday_pulse() {
		if ( ! self::should_show_monday_pulse() ) {
			return;
		}

		echo '<div class="mpgr-monday-pulse notice notice-info inline" id="mpgr-monday-pulse">';
		echo '<p class="mpgr-monday-pulse__message">';
		echo esc_html__( 'Get a Monday morning email with unclaimed gift counts and claim rate — no need to open WordPress.', 'memberpress-gift-reporter' );
		echo '</p>';
		echo '<div class="mpgr-monday-pulse__actions">';
		echo '<button type="button" class="button button-primary mpgr-monday-pulse-enable">';
		echo esc_html__( 'Enable weekly summary', 'memberpress-gift-reporter' );
		echo '</button> ';
		echo '<button type="button" class="button mpgr-monday-pulse-preview">';
		echo esc_html__( 'Send preview now', 'memberpress-gift-reporter' );
		echo '</button> ';
		echo '<button type="button" class="button-link mpgr-monday-pulse-dismiss">';
		echo esc_html__( 'Not now', 'memberpress-gift-reporter' );
		echo '</button>';
		echo '<span class="mpgr-monday-pulse__status" aria-live="polite"></span>';
		echo '</div>';
		echo '</div>';
	}

	/**
	 * Output stuck-gifts aging arc strip above filters.
	 */
	public static function render_stuck_gifts_arcs() {
		if ( ! self::user_can_see() || ! class_exists( 'MPGR_Gift_Report' ) ) {
			return;
		}

		$report = MPGR_Gift_Report::get_instance();
		$arcs   = $report->get_unclaimed_aging_arcs();
		$has_any = false;

		foreach ( $arcs as $arc ) {
			if ( ! empty( $arc['count'] ) ) {
				$has_any = true;
				break;
			}
		}

		if ( ! $has_any ) {
			return;
		}

		echo '<div class="mpgr-stuck-gifts">';
		echo '<h3 class="mpgr-stuck-gifts__title">' . esc_html__( 'Stuck gifts', 'memberpress-gift-reporter' ) . '</h3>';
		echo '<div class="mpgr-stuck-gifts__arcs">';

		foreach ( $arcs as $arc ) {
			if ( empty( $arc['count'] ) ) {
				continue;
			}

			echo '<div class="mpgr-stuck-gifts__arc">';
			echo '<span class="mpgr-stuck-gifts__label">' . esc_html( $arc['label'] ) . '</span> ';
			echo '<span class="mpgr-stuck-gifts__count">';
			printf(
				/* translators: 1: gift count, 2: formatted revenue */
				esc_html__( '%1$d unclaimed · %2$s at risk', 'memberpress-gift-reporter' ),
				(int) $arc['count'],
				esc_html( $arc['revenue_formatted'] )
			);
			echo '</span> ';
			echo '<a href="' . esc_url( $arc['filter_url'] ) . '" class="button button-small mpgr-stuck-gifts__view">';
			echo esc_html__( 'View', 'memberpress-gift-reporter' );
			echo '</a> ';
			$bulk_remind_url = ! empty( $arc['bulk_remind_url'] )
				? $arc['bulk_remind_url']
				: add_query_arg( 'mpgr_bulk_remind', '1', $arc['filter_url'] );
			echo '<a href="' . esc_url( $bulk_remind_url ) . '" class="button button-primary button-small mpgr-stuck-gifts__bulk-remind" data-mpgr-bulk-remind="1" title="' . esc_attr__( 'Filter this bucket, select unclaimed gifts, and send reminders', 'memberpress-gift-reporter' ) . '">';
			echo esc_html__( 'Bulk remind', 'memberpress-gift-reporter' );
			echo '</a>';
			echo '</div>';
		}

		echo '</div>';
		echo '</div>';
	}

	/**
	 * Output recovery reel comparing current stats to last visit snapshot.
	 *
	 * @param array $current Current all-time summary.
	 * @param array $prior   Prior snapshot from site option.
	 */
	public static function render_recovery_reel( $current, $prior ) {
		if ( empty( $prior ) || ! is_array( $prior ) ) {
			return;
		}

		$claimed_delta = (int) $current['claimed_gifts'] - (int) ( $prior['claimed_gifts'] ?? 0 );
		$prior_claimed_revenue = isset( $prior['claimed_revenue'] ) ? (float) $prior['claimed_revenue'] : 0.0;
		$current_claimed_revenue = isset( $current['claimed_revenue'] ) ? (float) $current['claimed_revenue'] : 0.0;
		$recovered     = max( 0, $current_claimed_revenue - $prior_claimed_revenue );
		$prior_rate    = isset( $prior['claim_rate'] ) ? (float) $prior['claim_rate'] : 0.0;
		$current_rate  = isset( $current['claim_rate'] ) ? (float) $current['claim_rate'] : 0.0;

		if ( $claimed_delta <= 0 && $recovered <= 0 && abs( $current_rate - $prior_rate ) < 0.01 ) {
			return;
		}

		$recovered_formatted = class_exists( 'MPGR_Gift_Report' )
			? MPGR_Gift_Report::get_instance()->format_currency( $recovered )
			: number_format( $recovered, 2 );

		echo '<div class="mpgr-recovery-reel notice notice-success inline">';
		echo '<p class="mpgr-recovery-reel__message">';
		printf(
			/* translators: 1: gifts claimed since last visit, 2: recovered revenue, 3: prior claim rate, 4: current claim rate */
			esc_html__( 'Since your last visit: %1$d gifts claimed · %2$s recovered · claim rate %3$s%% → %4$s%%', 'memberpress-gift-reporter' ),
			max( 0, $claimed_delta ),
			esc_html( $recovered_formatted ),
			esc_html( number_format( $prior_rate, 2 ) ),
			esc_html( number_format( $current_rate, 2 ) )
		);
		echo '</p>';
		echo '</div>';
	}

	/**
	 * Persist all-time summary snapshot for recovery reel.
	 *
	 * @param array $summary All-time summary from get_summary().
	 */
	public static function save_report_snapshot( $summary ) {
		if ( ! is_array( $summary ) ) {
			return;
		}

		update_option(
			self::SNAPSHOT_OPTION,
			array(
				'unclaimed'       => isset( $summary['unclaimed_gifts'] ) ? (int) $summary['unclaimed_gifts'] : 0,
				'claimed'         => isset( $summary['claimed_gifts'] ) ? (int) $summary['claimed_gifts'] : 0,
				'claim_rate'      => isset( $summary['claim_rate'] ) ? (float) $summary['claim_rate'] : 0.0,
				'total_revenue'   => isset( $summary['total_revenue'] ) ? (float) $summary['total_revenue'] : 0.0,
				'claimed_gifts'   => isset( $summary['claimed_gifts'] ) ? (int) $summary['claimed_gifts'] : 0,
				'claimed_revenue' => isset( $summary['claimed_revenue'] ) ? (float) $summary['claimed_revenue'] : 0.0,
			),
			false
		);
	}

	/**
	 * AJAX: snooze cliffhanger for 7 days.
	 */
	public static function ajax_snooze_cliffhanger() {
		if ( ! check_ajax_referer( 'mpgr_onboarding_nonce', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'memberpress-gift-reporter' ) ), 403 );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'memberpress-gift-reporter' ) ), 403 );
		}

		$user_id = get_current_user_id();
		if ( $user_id ) {
			update_user_meta( $user_id, self::META_CLIFFHANGER_SNOOZE, time() + WEEK_IN_SECONDS );
		}

		wp_send_json_success();
	}

	/**
	 * AJAX: dismiss Monday Pulse prompt.
	 */
	public static function ajax_dismiss_monday_pulse() {
		if ( ! check_ajax_referer( 'mpgr_onboarding_nonce', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'memberpress-gift-reporter' ) ), 403 );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'memberpress-gift-reporter' ) ), 403 );
		}

		$user_id = get_current_user_id();
		if ( $user_id ) {
			update_user_meta( $user_id, self::META_MONDAY_PULSE, 1 );
		}

		wp_send_json_success();
	}

	/**
	 * AJAX: enable weekly summary email and schedule cron.
	 */
	public static function ajax_enable_weekly_summary() {
		if ( ! check_ajax_referer( 'mpgr_onboarding_nonce', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'memberpress-gift-reporter' ) ), 403 );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'memberpress-gift-reporter' ) ), 403 );
		}

		if ( ! class_exists( 'MPGR_Weekly_Summary' ) ) {
			wp_send_json_error( array( 'message' => __( 'Weekly summary is unavailable.', 'memberpress-gift-reporter' ) ), 500 );
		}

		update_option( 'mpgr_weekly_summary_settings', array( 'enabled' => true ) );
		MPGR_Weekly_Summary::schedule_cron();

		$user_id = get_current_user_id();
		if ( $user_id ) {
			update_user_meta( $user_id, self::META_MONDAY_PULSE, 1 );
		}

		wp_send_json_success();
	}

	/**
	 * AJAX: send weekly summary preview to current admin.
	 */
	public static function ajax_send_weekly_preview() {
		if ( ! check_ajax_referer( 'mpgr_onboarding_nonce', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'memberpress-gift-reporter' ) ), 403 );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'memberpress-gift-reporter' ) ), 403 );
		}

		if ( ! class_exists( 'MPGR_Weekly_Summary' ) ) {
			wp_send_json_error( array( 'message' => __( 'Weekly summary is unavailable.', 'memberpress-gift-reporter' ) ), 500 );
		}

		$sent = MPGR_Weekly_Summary::send_preview_email();
		if ( ! $sent ) {
			wp_send_json_error( array( 'message' => __( 'Could not send preview email.', 'memberpress-gift-reporter' ) ) );
		}

		wp_send_json_success();
	}
}
