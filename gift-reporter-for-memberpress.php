<?php
/**
 * Plugin Name: Gift Reporter for MemberPress
 * Plugin URI: https://wordpress.org/plugins/memberpress-gift-reporter/
 * Description: Generate comprehensive reports for MemberPress Gifting add-on, showing the linkage between gift givers and recipients. This is an independent plugin (not affiliated with MemberPress) developed by Omar ElHawary.
 * Version: 1.6.3
 * Author: Omar ElHawary
 * Author URI: https://www.linkedin.com/in/omaraelhawary/
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: memberpress-gift-reporter
 * Domain Path: /languages
 * Requires at least: 5.0
 * Tested up to: 6.9
 * Requires PHP: 7.4
 * 
 * @package MemberPressGiftReporter
 * @version 1.6.3
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define plugin constants.
define( 'MPGR_VERSION', '1.6.3' );
define( 'MPGR_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'MPGR_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'MPGR_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Main plugin class
 */
class MPGR_MemberPressGiftReporter {
    
    /**
     * Plugin instance
     */
    private static $instance = null;
    
    /**
     * Get plugin instance
     */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}
    
    /**
     * Constructor
     */
	private function __construct() {
		$this->init();
	}
    
    /**
     * Initialize the plugin
     */
	private function init() {
		// Register activation/deactivation hooks.
		register_activation_hook( __FILE__, array( $this, 'activate' ) );
		register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );

		// Load reminders class and register hooks immediately
		// This ensures the hooks are always available, even before plugins_loaded
		if ( file_exists( MPGR_PLUGIN_PATH . 'includes/class-reminders.php' ) ) {
			require_once MPGR_PLUGIN_PATH . 'includes/class-reminders.php';
			
			// Register cron hook immediately if class exists
			if ( class_exists( 'MPGR_Reminders' ) ) {
				add_action( 'mpgr_run_gift_reminders', array( 'MPGR_Reminders', 'run_scheduled_reminders' ) );
			}
		}

		// Load weekly summary class and register hooks immediately
		if ( file_exists( MPGR_PLUGIN_PATH . 'includes/class-weekly-summary.php' ) ) {
			require_once MPGR_PLUGIN_PATH . 'includes/class-weekly-summary.php';
			
			// Register cron hook immediately if class exists
			if ( class_exists( 'MPGR_Weekly_Summary' ) ) {
				add_action( 'mpgr_run_weekly_summary', array( 'MPGR_Weekly_Summary', 'run_weekly_summary' ) );
			}
		}

		// Register custom cron schedules
		add_filter( 'cron_schedules', array( $this, 'add_weekly_cron_schedule' ) );

		// Load translations at init — required for WordPress 6.7+ (avoid JIT load before init).
		add_action( 'init', array( $this, 'load_textdomain' ), 0 );

		// Check dependencies after plugins are loaded.
		add_action( 'plugins_loaded', array( $this, 'check_dependencies' ) );
	}
    
    /**
     * Add custom weekly cron schedule
     * 
     * @param array $schedules Existing cron schedules
     * @return array Modified schedules
     */
	public function add_weekly_cron_schedule( $schedules ) {
		// Add weekly schedule if it doesn't exist
		if ( ! isset( $schedules['weekly'] ) ) {
			$schedules['weekly'] = array(
				'interval' => WEEK_IN_SECONDS,
				// Plain string: this filter can run before init; do not call __() here (WP 6.7+).
				'display'  => 'Once Weekly',
			);
		}
		return $schedules;
	}

	/**
	 * Load plugin text domain (must run on init or later).
	 */
	public function load_textdomain() {
		load_plugin_textdomain(
			'memberpress-gift-reporter',
			false,
			dirname( MPGR_PLUGIN_BASENAME ) . '/languages'
		);
	}
    
    /**
     * Check plugin dependencies
     */
	public function check_dependencies() {
		// Check if MemberPress is active.
		if ( ! $this->is_memberpress_active() ) {
			add_action( 'admin_notices', array( $this, 'memberpress_notice' ) );
			return;
		}

		// Load plugin.
		add_action( 'init', array( $this, 'load_plugin' ) );
	}
    
    /**
     * Check if MemberPress is active
     */
	private function is_memberpress_active() {
		// Check multiple ways to detect MemberPress.
		$checks = array(
			'MeprTransaction class' => class_exists( 'MeprTransaction' ),
			'MeprProduct class' => class_exists( 'MeprProduct' ),
			'mepr_get_plugin_name function' => function_exists( 'mepr_get_plugin_name' ),
			'MEPR_VERSION constant' => defined( 'MEPR_VERSION' ),
			'MeprOptions class' => class_exists( 'MeprOptions' ),
			'MeprUser class' => class_exists( 'MeprUser' ),
		);

		return in_array( true, $checks, true );
	}
    
    /**
     * Show notice if MemberPress is not active
     */
	public function memberpress_notice() {
		echo '<div class="notice notice-error">';
		echo '<p><strong>' . esc_html__( 'Gift Reporter for MemberPress', 'memberpress-gift-reporter' ) . '</strong> ' . esc_html__( 'requires MemberPress to be installed and activated.', 'memberpress-gift-reporter' ) . '</p>';
		echo '<p>' . esc_html__( 'Please ensure that:', 'memberpress-gift-reporter' ) . '</p>';
		echo '<ul style="margin-left: 20px;">';
		echo '<li>' . esc_html__( 'MemberPress plugin is installed and activated', 'memberpress-gift-reporter' ) . '</li>';
		echo '<li>' . esc_html__( 'MemberPress is properly configured', 'memberpress-gift-reporter' ) . '</li>';
		echo '<li>' . esc_html__( 'You have a valid MemberPress license', 'memberpress-gift-reporter' ) . '</li>';
		echo '</ul>';
		echo '<p><a href="' . esc_url( admin_url( 'plugins.php' ) ) . '">' . esc_html__( 'Go to Plugins', 'memberpress-gift-reporter' ) . '</a> | <a href="https://memberpress.com/" target="_blank">' . esc_html__( 'Get MemberPress', 'memberpress-gift-reporter' ) . '</a></p>';
		echo '</div>';
	}
    
    /**
     * Load the plugin
     */
	public function load_plugin() {
		$this->maybe_migrate_cron_state();

		// Load the main report class.
		require_once MPGR_PLUGIN_PATH . 'includes/class-gift-report.php';

		// Initialize the report functionality.
		new MPGR_Gift_Report();

		// Load admin functionality.
		if ( is_admin() ) {
			require_once MPGR_PLUGIN_PATH . 'includes/class-admin.php';
			new MPGR_Admin();
		}
	}
    
    /**
     * Plugin activation
     */
	public function activate() {
		// Create any necessary database tables or options.
		add_option( 'mpgr_version', MPGR_VERSION );

		// Load reminders class to ensure class exists
		require_once MPGR_PLUGIN_PATH . 'includes/class-reminders.php';
		
		// Register the cron hook (ensuring it's registered during activation)
		if ( class_exists( 'MPGR_Reminders' ) ) {
			add_action( 'mpgr_run_gift_reminders', array( 'MPGR_Reminders', 'run_scheduled_reminders' ) );
		}

		// Load weekly summary class to ensure class exists
		require_once MPGR_PLUGIN_PATH . 'includes/class-weekly-summary.php';
		
		// Register the weekly summary cron hook
		if ( class_exists( 'MPGR_Weekly_Summary' ) ) {
			add_action( 'mpgr_run_weekly_summary', array( 'MPGR_Weekly_Summary', 'run_weekly_summary' ) );
		}

		// Clean up any old/incorrect cron hooks
		$old_hooks = array( 'mpgr_check_reminders', 'mpgr_send_reminder_emails', 'mpgr_send_reminders' );
		foreach ( $old_hooks as $hook ) {
			$timestamp = wp_next_scheduled( $hook );
			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, $hook );
			}
			// Also unschedule all occurrences if multiple exist
			wp_clear_scheduled_hook( $hook );
		}

		// Unschedule existing event if it exists
		$timestamp = wp_next_scheduled( 'mpgr_run_gift_reminders' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'mpgr_run_gift_reminders' );
		}

		$settings = MPGR_Reminders::get_settings();
		if ( ! empty( $settings['enabled'] ) ) {
			wp_schedule_event( time(), 'daily', 'mpgr_run_gift_reminders' );
		}

		// Schedule weekly summary cron event only if enabled (runs every Monday at 9 AM)
		// By default, weekly summary is disabled, so we don't schedule it on activation
		// It will be scheduled when the user enables it in the settings
	}
    
    /**
     * Plugin deactivation
     */
	public function deactivate() {
		// Unschedule reminder cron event.
		$timestamp = wp_next_scheduled( 'mpgr_run_gift_reminders' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'mpgr_run_gift_reminders' );
		}

		// Unschedule weekly summary cron event.
		$timestamp = wp_next_scheduled( 'mpgr_run_weekly_summary' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'mpgr_run_weekly_summary' );
		}
		wp_clear_scheduled_hook( 'mpgr_run_weekly_summary' );
		wp_clear_scheduled_hook( 'mpgr_send_queued_gift_email' );
	}

	/**
	 * One-time cron reconciliation for upgrades (replaces per-request scheduling).
	 */
	private function maybe_migrate_cron_state() {
		if ( get_option( 'mpgr_cron_migrated_v1_6_4' ) ) {
			return;
		}

		foreach ( array( 'mpgr_check_reminders', 'mpgr_send_reminder_emails', 'mpgr_send_reminders' ) as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}

		if ( class_exists( 'MPGR_Reminders' ) ) {
			$settings  = MPGR_Reminders::get_settings();
			$timestamp = wp_next_scheduled( 'mpgr_run_gift_reminders' );

			if ( empty( $settings['enabled'] ) && $timestamp ) {
				wp_unschedule_event( $timestamp, 'mpgr_run_gift_reminders' );
				wp_clear_scheduled_hook( 'mpgr_run_gift_reminders' );
			} elseif ( ! empty( $settings['enabled'] ) && ! $timestamp ) {
				wp_schedule_event( time(), 'daily', 'mpgr_run_gift_reminders' );
			}
		}

		if ( ! class_exists( 'MPGR_Weekly_Summary' ) && file_exists( MPGR_PLUGIN_PATH . 'includes/class-weekly-summary.php' ) ) {
			require_once MPGR_PLUGIN_PATH . 'includes/class-weekly-summary.php';
		}

		if ( class_exists( 'MPGR_Weekly_Summary' ) ) {
			$weekly_settings = MPGR_Weekly_Summary::get_settings();
			$timestamp       = wp_next_scheduled( 'mpgr_run_weekly_summary' );

			if ( ! empty( $weekly_settings['enabled'] ) && ! $timestamp ) {
				$next_monday = strtotime( 'next Monday 9:00 AM' );
				wp_schedule_event( $next_monday, 'weekly', 'mpgr_run_weekly_summary' );
			} elseif ( empty( $weekly_settings['enabled'] ) && $timestamp ) {
				wp_unschedule_event( $timestamp, 'mpgr_run_weekly_summary' );
				wp_clear_scheduled_hook( 'mpgr_run_weekly_summary' );
			}
		}

		update_option( 'mpgr_cron_migrated_v1_6_4', 1, false );
	}
}

// Initialize the plugin.
MPGR_MemberPressGiftReporter::get_instance();
