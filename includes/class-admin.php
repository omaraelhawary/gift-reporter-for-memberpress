<?php
/**
 * Admin Class
 * 
 * @package MemberPressGiftReporter
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin functionality
 */
class MPGR_Admin {
    
    /**
     * Constructor
     */
	public function __construct() {
		// Add admin menu.
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );

		// Add plugin action links.
		add_filter( 'plugin_action_links_' . MPGR_PLUGIN_BASENAME, array( $this, 'add_plugin_links' ) );

		// Add admin notices.
		add_action( 'admin_notices', array( $this, 'admin_notices' ) );

		// Handle reminder settings save.
		add_action( 'admin_init', array( $this, 'handle_reminder_settings_save' ) );

		// Enqueue admin scripts and styles.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );

		// Handle AJAX test email
		add_action( 'wp_ajax_mpgr_send_test_reminder_email', array( $this, 'ajax_send_test_reminder_email' ) );
	}
    
    /**
     * Add admin menu
     */
	public function add_admin_menu() {
		add_submenu_page(
			'memberpress',
			__( 'Gift Report', 'memberpress-gift-reporter' ),
			__( 'Gift Report', 'memberpress-gift-reporter' ),
			'manage_options',
			'memberpress-gift-report',
			array( $this, 'admin_page' )
		);
	}
    

    
    /**
     * Add plugin action links
     */
	public function add_plugin_links( $links ) {
		$plugin_links = array(
			'<a href="' . esc_url( admin_url( 'admin.php?page=memberpress-gift-report' ) ) . '">' . esc_html__( 'View Report', 'memberpress-gift-reporter' ) . '</a>',
			'<a href="' . esc_url( 'https://wordpress.org/plugins/memberpress-gift-reporter/' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Plugin page', 'memberpress-gift-reporter' ) . '</a>',
		);
		return array_merge( $plugin_links, $links );
	}
    
    /**
     * Admin notices
     */
	public function admin_notices() {
		// Check if MemberPress Gifting is active.
		if ( ! $this->is_gifting_active() ) {
					echo '<div class="notice notice-warning is-dismissible">';
			echo '<p><strong>' . esc_html__( 'Gift Reporter for MemberPress:', 'memberpress-gift-reporter' ) . '</strong> ' . esc_html__( 'MemberPress Gifting add-on is not active. This plugin requires the MemberPress Gifting add-on to function properly.', 'memberpress-gift-reporter' ) . '</p>';
		echo '</div>';
		}
	}
    
    /**
     * Check if MemberPress Gifting is active
     */
	private function is_gifting_active() {
		return class_exists( 'memberpress\gifting\models\Gift' );
	}
	

    
    /**
     * Admin page
     */
	public function admin_page() {
		// Check permissions.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'memberpress-gift-reporter' ) );
		}

		echo '<div class="wrap">';

		// Check if MemberPress Gifting is active.
		if ( ! $this->is_gifting_active() ) {
			echo '<div class="notice notice-error">';
			echo '<p>' . esc_html__( 'MemberPress Gifting add-on is not active. Please activate it to use this report.', 'memberpress-gift-reporter' ) . '</p>';
			echo '</div>';
			echo '</div>';
			return;
		}

		// Get current tab (safe to access without nonce as it's just for display)
		$current_tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'report';

		// Check if any filter parameters are present (for report tab only)
		$has_filter_params = false;
		if ( 'report' === $current_tab ) {
			$has_filter_params = ! empty( $_GET['date_from'] ) || 
			                     ! empty( $_GET['date_to'] ) || 
			                     ! empty( $_GET['gift_status'] ) || 
			                     ! empty( $_GET['product'] ) || 
			                     ! empty( $_GET['gifter_email'] ) || 
			                     ! empty( $_GET['recipient_email'] ) ||
			                     ! empty( $_GET['redemption_from'] ) || 
			                     ! empty( $_GET['redemption_to'] ) || 
			                     ! empty( $_GET['transaction_id'] ) || 
			                     ! empty( $_GET['claim_transaction_id'] );
		}

		// Verify nonce for filter requests (must be done before processing any filter parameters)
		if ( $has_filter_params ) {
			$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
			if ( ! wp_verify_nonce( $nonce, 'mpgr_filter_nonce' ) ) {
				wp_die( esc_html__( 'Security check failed. Please try again.', 'memberpress-gift-reporter' ) );
			}
		}

		// Display tabs
		$this->display_tabs( $current_tab );

		// Display content based on tab
		if ( 'reminders' === $current_tab ) {
			$this->display_reminder_settings_tab();
		} else {
			// Get filter parameters for report tab (only after nonce verification)
			$filters = array();
			if ( ! empty( $_GET['date_from'] ) ) {
				$filters['date_from'] = sanitize_text_field( wp_unslash( $_GET['date_from'] ) );
			}
			if ( ! empty( $_GET['date_to'] ) ) {
				$filters['date_to'] = sanitize_text_field( wp_unslash( $_GET['date_to'] ) );
			}
			if ( ! empty( $_GET['gift_status'] ) ) {
				$filters['gift_status'] = sanitize_text_field( wp_unslash( $_GET['gift_status'] ) );
			}
			if ( ! empty( $_GET['product'] ) ) {
				$filters['product'] = intval( $_GET['product'] );
			}
			if ( ! empty( $_GET['gifter_email'] ) ) {
				$filters['gifter_email'] = sanitize_email( wp_unslash( $_GET['gifter_email'] ) );
			}
			if ( ! empty( $_GET['recipient_email'] ) ) {
				$filters['recipient_email'] = sanitize_email( wp_unslash( $_GET['recipient_email'] ) );
			}
			if ( ! empty( $_GET['redemption_from'] ) ) {
				$filters['redemption_from'] = sanitize_text_field( wp_unslash( $_GET['redemption_from'] ) );
			}
			if ( ! empty( $_GET['redemption_to'] ) ) {
				$filters['redemption_to'] = sanitize_text_field( wp_unslash( $_GET['redemption_to'] ) );
			}
			if ( ! empty( $_GET['transaction_id'] ) ) {
				$filters['transaction_id'] = sanitize_text_field( wp_unslash( $_GET['transaction_id'] ) );
			}
			if ( ! empty( $_GET['claim_transaction_id'] ) ) {
				$filters['claim_transaction_id'] = sanitize_text_field( wp_unslash( $_GET['claim_transaction_id'] ) );
			}

			// Display report.
			$gift_report = new MPGR_Gift_Report();
			$gift_report->display_report($filters);
		}

		echo '</div>';
	}

	/**
	 * Handle reminder settings form submission
	 */
	public function handle_reminder_settings_save() {
		// Only process on our admin page
		if ( ! isset( $_GET['page'] ) || 'memberpress-gift-report' !== $_GET['page'] ) {
			return;
		}

		// Check if form was submitted
		if ( ! isset( $_POST['mpgr_save_reminder_settings'] ) ) {
			return;
		}

		// Verify nonce
		if ( ! isset( $_POST['mpgr_reminder_settings_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mpgr_reminder_settings_nonce'] ) ), 'mpgr_save_reminder_settings' ) ) {
			wp_die( esc_html__( 'Security check failed. Please try again.', 'memberpress-gift-reporter' ) );
		}

		// Check permissions
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'memberpress-gift-reporter' ) );
		}

		// Get and sanitize settings
		$enabled = isset( $_POST['mpgr_reminder_enabled'] ) ? true : false;
		
		$settings = array(
			'enabled'                => $enabled,
			'delay_days'             => isset( $_POST['mpgr_reminder_delay_days'] ) ? absint( $_POST['mpgr_reminder_delay_days'] ) : 7,
			'max_reminders'          => isset( $_POST['mpgr_reminder_max_reminders'] ) ? absint( $_POST['mpgr_reminder_max_reminders'] ) : 2,
			// If reminders are enabled, automatically send to gifters (no separate checkbox needed)
			'send_to_gifter'         => $enabled ? true : false,
			'gifter_email_subject'  => isset( $_POST['mpgr_gifter_email_subject'] ) ? sanitize_text_field( wp_unslash( $_POST['mpgr_gifter_email_subject'] ) ) : '',
			'gifter_email_body'      => isset( $_POST['mpgr_gifter_email_body'] ) ? wp_kses_post( wp_unslash( $_POST['mpgr_gifter_email_body'] ) ) : '',
			// Backward compatibility
			'email_subject'          => isset( $_POST['mpgr_reminder_email_subject'] ) ? sanitize_text_field( wp_unslash( $_POST['mpgr_reminder_email_subject'] ) ) : '',
			'email_body'             => isset( $_POST['mpgr_reminder_email_body'] ) ? wp_kses_post( wp_unslash( $_POST['mpgr_reminder_email_body'] ) ) : '',
		);
		
		// Save weekly summary settings
		// Load weekly summary class if not already loaded
		if ( ! class_exists( 'MPGR_Weekly_Summary' ) && file_exists( MPGR_PLUGIN_PATH . 'includes/class-weekly-summary.php' ) ) {
			require_once MPGR_PLUGIN_PATH . 'includes/class-weekly-summary.php';
		}
		
		$weekly_summary_enabled = isset( $_POST['mpgr_weekly_summary_enabled'] ) ? true : false;
		$weekly_summary_settings = array(
			'enabled' => $weekly_summary_enabled,
		);
		update_option( 'mpgr_weekly_summary_settings', $weekly_summary_settings );
		
		// Manage weekly summary cron job based on enabled setting
		if ( class_exists( 'MPGR_Weekly_Summary' ) ) {
			$timestamp = wp_next_scheduled( 'mpgr_run_weekly_summary' );
			
			if ( $weekly_summary_enabled ) {
				// If weekly summary is enabled, schedule the cron if not already scheduled
				if ( ! $timestamp ) {
					// Schedule weekly summary (runs every Monday at 9 AM)
					$next_monday = strtotime( 'next Monday 9:00 AM' );
					wp_schedule_event( $next_monday, 'weekly', 'mpgr_run_weekly_summary' );
				}
			} else {
				// If weekly summary is disabled, unschedule the cron
				if ( $timestamp ) {
					wp_unschedule_event( $timestamp, 'mpgr_run_weekly_summary' );
				}
				// Also clear all occurrences just to be safe
				wp_clear_scheduled_hook( 'mpgr_run_weekly_summary' );
			}
		}

		// Process reminder schedules
		$reminder_schedules = array();
		if ( isset( $_POST['mpgr_reminder_schedules'] ) && is_array( $_POST['mpgr_reminder_schedules'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Array values are sanitized individually in the loop below
			$raw_schedules = wp_unslash( $_POST['mpgr_reminder_schedules'] );
			foreach ( $raw_schedules as $schedule ) {
				// Ensure schedule is an array
				if ( ! is_array( $schedule ) ) {
					continue;
				}

				$delay_value = null;
				$delay_unit = 'days';

				// Backward compatibility: check for old delay_days format
				if ( isset( $schedule['delay_days'] ) && $schedule['delay_days'] !== '' ) {
					// Sanitize and validate delay_days
					$delay_days_raw = sanitize_text_field( $schedule['delay_days'] );
					if ( is_numeric( $delay_days_raw ) ) {
						$delay_value = absint( $delay_days_raw );
						$delay_unit = 'days';
					}
				} elseif ( isset( $schedule['delay_value'] ) && $schedule['delay_value'] !== '' ) {
					// Sanitize and validate delay_value
					$delay_value_raw = sanitize_text_field( $schedule['delay_value'] );
					if ( is_numeric( $delay_value_raw ) ) {
						$delay_value = absint( $delay_value_raw );
					}

					// Sanitize and validate delay_unit - only allow 'hours' or 'days'
					if ( isset( $schedule['delay_unit'] ) ) {
						$delay_unit_raw = sanitize_text_field( $schedule['delay_unit'] );
						if ( in_array( $delay_unit_raw, array( 'hours', 'days' ), true ) ) {
							$delay_unit = $delay_unit_raw;
						}
					}
				}

				// Skip if delay_value is not set or invalid
				if ( null === $delay_value ) {
					continue;
				}

				// Validate based on unit with proper range checks
				$max_value = ( $delay_unit === 'hours' ) ? 8760 : 365; // 8760 hours = 365 days
				if ( $delay_value >= 0 && $delay_value <= $max_value ) {
					$reminder_schedules[] = array(
						'delay_value' => $delay_value,
						'delay_unit'  => $delay_unit,
					);
				}
			}
		}
		
		// If no schedules provided, use default
		if ( empty( $reminder_schedules ) ) {
			$reminder_schedules = array(
				array( 'delay_value' => 7, 'delay_unit' => 'days' ),
			);
		}
		
		// Sort by delay in seconds (convert to common unit for sorting)
		usort( $reminder_schedules, function( $a, $b ) {
			$a_seconds = ( $a['delay_unit'] === 'hours' ) ? $a['delay_value'] * HOUR_IN_SECONDS : $a['delay_value'] * DAY_IN_SECONDS;
			$b_seconds = ( $b['delay_unit'] === 'hours' ) ? $b['delay_value'] * HOUR_IN_SECONDS : $b['delay_value'] * DAY_IN_SECONDS;
			return $a_seconds - $b_seconds;
		} );
		
		$settings['reminder_schedules'] = $reminder_schedules;

		// Validate settings
		if ( $settings['delay_days'] < 1 ) {
			$settings['delay_days'] = 7;
		}
		// Note: max_reminders is kept for backward compatibility but no longer used

		// Save settings
		update_option( 'mpgr_reminder_settings', $settings );

		// Manage cron job based on enabled setting
		$timestamp = wp_next_scheduled( 'mpgr_run_gift_reminders' );
		
		if ( $enabled ) {
			// If reminders are enabled, schedule the cron if not already scheduled
			if ( ! $timestamp ) {
				wp_schedule_event( time(), 'daily', 'mpgr_run_gift_reminders' );
			}
		} else {
			// If reminders are disabled, unschedule the cron
			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, 'mpgr_run_gift_reminders' );
			}
			// Also clear all occurrences just to be safe
			wp_clear_scheduled_hook( 'mpgr_run_gift_reminders' );
		}

		// Show success message
		add_action( 'admin_notices', array( $this, 'reminder_settings_saved_notice' ) );
	}

	/**
	 * Show notice when reminder settings are saved
	 */
	public function reminder_settings_saved_notice() {
		echo '<div class="notice notice-success is-dismissible">';
		echo '<p>' . esc_html__( 'Reminder settings saved successfully.', 'memberpress-gift-reporter' ) . '</p>';
		echo '</div>';
	}

	/**
	 * Enqueue admin assets
	 */
	public function enqueue_admin_assets( $hook ) {
		// Only load on our admin page
		if ( 'memberpress_page_memberpress-gift-report' !== $hook ) {
			return;
		}

		// Enqueue TinyMCE for email editor
		wp_enqueue_editor();
		wp_enqueue_media();

		// Enqueue jQuery if not already enqueued
		wp_enqueue_script( 'jquery' );

		// Enqueue admin CSS (use minified version if available, fallback to unminified)
		$admin_css = file_exists( MPGR_PLUGIN_PATH . 'assets/css/admin.min.css' ) 
			? MPGR_PLUGIN_URL . 'assets/css/admin.min.css' 
			: MPGR_PLUGIN_URL . 'assets/css/admin.css';
		wp_enqueue_style(
			'mpgr-admin-styles',
			$admin_css,
			array(),
			MPGR_VERSION
		);

		// Enqueue report styles (use minified version if available, fallback to unminified)
		$report_css = file_exists( MPGR_PLUGIN_PATH . 'assets/css/style.min.css' ) 
			? MPGR_PLUGIN_URL . 'assets/css/style.min.css' 
			: MPGR_PLUGIN_URL . 'assets/css/style.css';
		wp_enqueue_style(
			'mpgr-styles',
			$report_css,
			array(),
			MPGR_VERSION
		);

		// Enqueue admin JS (use minified version if available, fallback to unminified)
		$admin_js = file_exists( MPGR_PLUGIN_PATH . 'assets/js/admin.min.js' ) 
			? MPGR_PLUGIN_URL . 'assets/js/admin.min.js' 
			: MPGR_PLUGIN_URL . 'assets/js/admin.js';
		wp_enqueue_script(
			'mpgr-admin-script',
			$admin_js,
			array( 'jquery' ),
			MPGR_VERSION,
			true
		);

		// Enqueue report script (use minified version if available, fallback to unminified)
		$report_js = file_exists( MPGR_PLUGIN_PATH . 'assets/js/script.min.js' ) 
			? MPGR_PLUGIN_URL . 'assets/js/script.min.js' 
			: MPGR_PLUGIN_URL . 'assets/js/script.js';
		wp_enqueue_script(
			'mpgr-script',
			$report_js,
			array( 'jquery' ),
			MPGR_VERSION,
			true
		);

		// Localize report script for AJAX
		wp_localize_script(
			'mpgr-script',
			'mpgr_ajax',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'mpgr_export_csv' ),
				'resend_email_nonce' => wp_create_nonce( 'mpgr_resend_gift_email' ),
				'copy_link_nonce' => wp_create_nonce( 'mpgr_copy_redemption_link' ),
				'bulk_resend_nonce' => wp_create_nonce( 'mpgr_bulk_resend_gift_emails' ),
			)
		);

		// Localize script for AJAX
		wp_localize_script(
			'mpgr-admin-script',
			'mpgr_reminder_ajax',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'mpgr_send_test_email' ),
			)
		);

		// Localize script for reminder settings (only on reminders tab)
		$tab_input = filter_input( INPUT_GET, 'tab', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		$current_tab = ( $tab_input && is_string( $tab_input ) ) ? sanitize_text_field( $tab_input ) : 'report';
		if ( 'reminders' === $current_tab ) {
			$settings = MPGR_Reminders::get_settings();
			$default_gifter_body = $this->get_default_email_body();
			$default_gifter_subject = __( 'Reminder: Your Gift Purchase - {$product_name}', 'memberpress-gift-reporter' );
			$schedules = ! empty( $settings['reminder_schedules'] ) ? $settings['reminder_schedules'] : array( array( 'delay_value' => 7, 'delay_unit' => 'days' ) );

			wp_localize_script(
				'mpgr-admin-script',
				'mpgrReminderSettings',
				array(
					'defaultTemplate'      => $default_gifter_body,
					'defaultSubject'       => $default_gifter_subject,
					'scheduleIndex'        => count( $schedules ),
					'resetConfirmMessage'  => esc_js( __( 'Are you sure you want to reset the email template to default?', 'memberpress-gift-reporter' ) ),
					'sendReminderText'     => esc_js( __( 'Send reminder after', 'memberpress-gift-reporter' ) ),
					'hoursText'            => esc_js( __( 'hours', 'memberpress-gift-reporter' ) ),
					'daysText'             => esc_js( __( 'days', 'memberpress-gift-reporter' ) ),
					'removeText'           => esc_js( __( 'Remove', 'memberpress-gift-reporter' ) ),
					'invalidEmailText'     => esc_js( __( 'Please enter a valid email address.', 'memberpress-gift-reporter' ) ),
					'sendingText'          => esc_js( __( 'Sending...', 'memberpress-gift-reporter' ) ),
					'successText'          => esc_js( __( 'Test email sent successfully!', 'memberpress-gift-reporter' ) ),
					'errorText'            => esc_js( __( 'Failed to send test email.', 'memberpress-gift-reporter' ) ),
					'errorSendingText'     => esc_js( __( 'Error sending test email.', 'memberpress-gift-reporter' ) ),
				)
			);
		}
	}

	/**
	 * Display tabs navigation
	 */
	private function display_tabs( $current_tab = 'report' ) {
		$tabs = array(
			'report'     => array(
				'label' => __( 'Gift Report', 'memberpress-gift-reporter' ),
				'icon'  => '📊',
			),
			'reminders'  => array(
				'label' => __( 'Reminders', 'memberpress-gift-reporter' ),
				'icon'  => '📧',
			),
		);
		?>
		<h1 class="wp-heading-inline"><?php esc_html_e( 'Gift Reporter for MemberPress', 'memberpress-gift-reporter' ); ?></h1>
		<hr class="wp-header-end">
		<nav class="nav-tab-wrapper mpgr-nav-tabs">
			<?php
			foreach ( $tabs as $tab_id => $tab ) {
				$url = add_query_arg( array( 'page' => 'memberpress-gift-report', 'tab' => $tab_id ), admin_url( 'admin.php' ) );
				$class = ( $current_tab === $tab_id ) ? 'nav-tab nav-tab-active' : 'nav-tab';
				?>
				<a href="<?php echo esc_url( $url ); ?>" class="<?php echo esc_attr( $class ); ?>">
					<span class="dashicons dashicons-<?php echo 'report' === $tab_id ? 'list-view' : 'email-alt'; ?>" style="vertical-align: middle; margin-top: 3px;"></span>
					<?php echo esc_html( $tab['label'] ); ?>
				</a>
				<?php
			}
			?>
		</nav>
		<?php
	}

	/**
	 * Display reminder settings tab
	 */
	private function display_reminder_settings_tab() {
		$settings = MPGR_Reminders::get_settings();
		
		// Get default email template (body only, no styles)
		$default_gifter_body = $this->get_default_email_body();
		
		// Get email body (use new field, fallback to old for backward compatibility)
		$gifter_email_body = ! empty( $settings['gifter_email_body'] ) ? $settings['gifter_email_body'] : ( ! empty( $settings['email_body'] ) ? $settings['email_body'] : $default_gifter_body );
		
		// Get email subject
		$default_gifter_subject = __( 'Reminder: Your Gift Purchase - {$product_name}', 'memberpress-gift-reporter' );
		$gifter_email_subject = ! empty( $settings['gifter_email_subject'] ) ? $settings['gifter_email_subject'] : ( ! empty( $settings['email_subject'] ) ? $settings['email_subject'] : $default_gifter_subject );
		?>
		<div class="mpgr-reminder-settings">
			<p class="description" style="margin: 20px 0;">
				<?php esc_html_e( 'Automatically send reminder emails for unclaimed gifts after a configurable delay. You can customize the email template below.', 'memberpress-gift-reporter' ); ?>
			</p>

			<form method="post" action="" id="mpgr-reminder-settings-form">
				<?php wp_nonce_field( 'mpgr_save_reminder_settings', 'mpgr_reminder_settings_nonce' ); ?>

				<table class="form-table">
					<tbody>
						<tr>
							<th scope="row">
								<label for="mpgr_reminder_enabled">
									<?php esc_html_e( 'Enable Automatic Reminders', 'memberpress-gift-reporter' ); ?>
								</label>
							</th>
							<td>
								<label for="mpgr_reminder_enabled" style="font-weight: normal;">
									<input type="checkbox" id="mpgr_reminder_enabled" name="mpgr_reminder_enabled" value="1" <?php checked( $settings['enabled'], true ); ?> style="margin-right: 5px;">
									<?php esc_html_e( 'Turn on the daily reminder process. Reminders will be sent to the person who purchased the gift.', 'memberpress-gift-reporter' ); ?>
								</label>
							</td>
						</tr>

						<?php
						// Get weekly summary settings
						if ( ! class_exists( 'MPGR_Weekly_Summary' ) && file_exists( MPGR_PLUGIN_PATH . 'includes/class-weekly-summary.php' ) ) {
							require_once MPGR_PLUGIN_PATH . 'includes/class-weekly-summary.php';
						}
						$weekly_summary_settings = class_exists( 'MPGR_Weekly_Summary' ) ? MPGR_Weekly_Summary::get_settings() : array( 'enabled' => false );
						?>
						<tr>
							<th scope="row">
								<label for="mpgr_weekly_summary_enabled">
									<?php esc_html_e( 'Enable Weekly Summary Email', 'memberpress-gift-reporter' ); ?>
								</label>
							</th>
							<td>
								<label for="mpgr_weekly_summary_enabled" style="font-weight: normal;">
									<input type="checkbox" id="mpgr_weekly_summary_enabled" name="mpgr_weekly_summary_enabled" value="1" <?php checked( $weekly_summary_settings['enabled'], true ); ?> style="margin-right: 5px;">
									<?php esc_html_e( 'Send a weekly summary email to the admin with gift activity statistics for the past 7 days.', 'memberpress-gift-reporter' ); ?>
								</label>
								<p class="description" style="margin-top: 10px;">
									<?php esc_html_e( 'The summary email includes total gifts, claimed/unclaimed statistics, revenue, and breakdowns by product and day.', 'memberpress-gift-reporter' ); ?>
								</p>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label>
									<?php esc_html_e( 'Reminder Schedules', 'memberpress-gift-reporter' ); ?>
								</label>
							</th>
							<td>
								<div id="mpgr-reminder-schedules">
									<?php
									$schedules = ! empty( $settings['reminder_schedules'] ) ? $settings['reminder_schedules'] : array( array( 'delay_value' => 7, 'delay_unit' => 'days' ) );
									foreach ( $schedules as $index => $schedule ) {
										// Backward compatibility: if delay_days exists, convert to new format
										if ( isset( $schedule['delay_days'] ) && ! isset( $schedule['delay_value'] ) ) {
											$delay_value = $schedule['delay_days'];
											$delay_unit = 'days';
										} else {
											$delay_value = isset( $schedule['delay_value'] ) ? $schedule['delay_value'] : 7;
											$delay_unit = isset( $schedule['delay_unit'] ) ? $schedule['delay_unit'] : 'days';
										}
										?>
										<div class="mpgr-schedule-row" style="margin-bottom: 10px; display: flex; align-items: center; gap: 10px;">
											<label>
												<?php esc_html_e( 'Send reminder after', 'memberpress-gift-reporter' ); ?>
												<input type="number" name="mpgr_reminder_schedules[<?php echo esc_attr( $index ); ?>][delay_value]" value="<?php echo esc_attr( $delay_value ); ?>" min="0" max="<?php echo esc_attr( $delay_unit === 'hours' ? 8760 : 365 ); ?>" class="small-text mpgr-delay-value" style="width: 60px;" required>
												<select name="mpgr_reminder_schedules[<?php echo esc_attr( $index ); ?>][delay_unit]" class="mpgr-delay-unit" style="margin-left: 5px;">
													<option value="hours" <?php selected( $delay_unit, 'hours' ); ?>><?php esc_html_e( 'hours', 'memberpress-gift-reporter' ); ?></option>
													<option value="days" <?php selected( $delay_unit, 'days' ); ?>><?php esc_html_e( 'days', 'memberpress-gift-reporter' ); ?></option>
												</select>
											</label>
											<button type="button" class="button button-small mpgr-remove-schedule" <?php echo count( $schedules ) <= 1 ? 'style="display:none;"' : ''; ?>>
												<?php esc_html_e( 'Remove', 'memberpress-gift-reporter' ); ?>
											</button>
										</div>
										<?php
									}
									?>
								</div>
								<button type="button" class="button button-small" id="mpgr-add-schedule" style="margin-top: 10px;">
									<?php esc_html_e( '+ Add Another Reminder', 'memberpress-gift-reporter' ); ?>
								</button>
								<p class="description">
									<?php esc_html_e( 'Configure multiple reminder schedules. Reminders will be sent at each specified interval after purchase.', 'memberpress-gift-reporter' ); ?>
								</p>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="mpgr_gifter_email_subject">
									<?php esc_html_e( 'Email Subject', 'memberpress-gift-reporter' ); ?>
								</label>
							</th>
							<td>
								<input type="text" id="mpgr_gifter_email_subject" name="mpgr_gifter_email_subject" value="<?php echo esc_attr( $gifter_email_subject ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'Reminder: Your Gift Purchase - {$product_name}', 'memberpress-gift-reporter' ); ?>">
								<p class="description">
									<?php esc_html_e( 'Email subject line. Available variables: {$product_name}, {$site_name}, {$blogname}, {$user_login}, {$user_email}, {$user_first_name}, {$user_last_name}', 'memberpress-gift-reporter' ); ?>
								</p>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="mpgr_gifter_email_body">
									<?php esc_html_e( 'Email Body', 'memberpress-gift-reporter' ); ?>
								</label>
							</td>
							<td>
								<?php
								$editor_id = 'mpgr_gifter_email_body';
								$editor_settings = array(
									'textarea_name' => $editor_id,
									'textarea_rows' => 15,
									'media_buttons' => true,
									'teeny'         => false,
									'tinymce'       => array(
										'toolbar1' => 'bold,italic,underline,strikethrough,|,bullist,numlist,|,link,unlink,|,forecolor,backcolor,|,alignleft,aligncenter,alignright,|,undo,redo',
										'toolbar2' => '',
									),
								);
								wp_editor( $gifter_email_body, $editor_id, $editor_settings );
								?>
								<p class="description">
									<?php esc_html_e( 'Customize the reminder email body. Available variables (MemberPress style): {$product_name}, {$redemption_link}, {$site_name}, {$blogname}, {$user_login}, {$user_email}, {$user_first_name}, {$user_last_name}', 'memberpress-gift-reporter' ); ?>
									<br>
									<button type="button" class="button button-small mpgr-reset-email-template" style="margin-top: 10px;">
										<?php esc_html_e( 'Reset to Default', 'memberpress-gift-reporter' ); ?>
									</button>
									<button type="button" class="button button-small" id="mpgr-send-test-email" style="margin-top: 10px; margin-left: 5px;">
										<?php esc_html_e( 'Send Test Email', 'memberpress-gift-reporter' ); ?>
									</button>
									<span id="mpgr-test-email-status" style="margin-left: 10px;"></span>
								</p>
								<div id="mpgr-test-email-input" style="margin-top: 10px; display: none;">
									<label>
										<?php esc_html_e( 'Test Email Address:', 'memberpress-gift-reporter' ); ?>
										<input type="email" id="mpgr-test-email-address" value="<?php echo esc_attr( wp_get_current_user()->user_email ); ?>" style="margin-left: 10px; width: 300px;">
										<button type="button" class="button button-primary" id="mpgr-send-test-email-confirm" style="margin-left: 10px;">
											<?php esc_html_e( 'Send', 'memberpress-gift-reporter' ); ?>
										</button>
										<button type="button" class="button" id="mpgr-cancel-test-email" style="margin-left: 5px;">
											<?php esc_html_e( 'Cancel', 'memberpress-gift-reporter' ); ?>
										</button>
									</label>
								</div>
							</td>
						</tr>
					</tbody>
				</table>

				<p class="submit">
					<input type="submit" name="mpgr_save_reminder_settings" class="button button-primary" value="<?php esc_attr_e( 'Save Settings', 'memberpress-gift-reporter' ); ?>">
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * Get default email body (includes footer with Best Regards - header is added automatically)
	 * 
	 * @return string Email body HTML
	 */
	private function get_default_email_body() {
		return '<div style="font-size: 18px; font-weight: bold; margin-bottom: 20px;">Hello!</div>
        
<p>You have purchased a gift membership for <strong>{$product_name}</strong>.</p>

<div style="background-color: #f3e5f5; padding: 15px; border-radius: 6px; border-left: 4px solid #9c27b0; margin: 20px 0;">
    <strong>The recipient can redeem this gift by visiting:</strong><br>
    <a href="{$redemption_link}" style="color: #9c27b0; text-decoration: none; font-weight: bold;">{$redemption_link}</a>
</div>

<p style="font-style: italic; color: #27ae60;">Thank you for your purchase!</p>

<div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #e9ecef; color: #6c757d; font-size: 14px;">
    <p>Best Regards,<br>
    <strong>{$blogname}</strong></p>
</div>';
	}

	/**
	 * AJAX handler for sending test reminder email
	 */
	public function ajax_send_test_reminder_email() {
		// Verify nonce
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'mpgr_send_test_email' ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'memberpress-gift-reporter' ) ) );
		}

		// Check permissions
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have sufficient permissions.', 'memberpress-gift-reporter' ) ) );
		}

		// Get email address
		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		if ( ! is_email( $email ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid email address.', 'memberpress-gift-reporter' ) ) );
		}

		// Get email body and subject
		$email_body = isset( $_POST['email_body'] ) ? wp_kses_post( wp_unslash( $_POST['email_body'] ) ) : '';
		$email_subject = isset( $_POST['email_subject'] ) ? sanitize_text_field( wp_unslash( $_POST['email_subject'] ) ) : '';
		if ( empty( $email_subject ) ) {
			$email_subject = __( 'Reminder: Your Gift Purchase - {$product_name}', 'memberpress-gift-reporter' );
		}

		// Replace variables with test data (MemberPress style: {$variable})
		// For test, use a generic URL format
		$test_redemption_link = home_url( '/register/?coupon=TEST-COUPON-CODE' );
		if ( class_exists( 'MeprProduct' ) ) {
			// Try to get a real product URL for testing
			$test_products = get_posts( array(
				'post_type' => 'memberpressproduct',
				'post_status' => 'publish',
				'numberposts' => 1,
			) );
			if ( ! empty( $test_products ) ) {
				$test_product = new \MeprProduct( $test_products[0]->ID );
				if ( $test_product && $test_product->ID ) {
					$test_redemption_link = add_query_arg( 'coupon', 'TEST-COUPON-CODE', $test_product->url() );
				}
			}
		}

		$test_variables = array(
			'product_name'    => __( 'Test Membership', 'memberpress-gift-reporter' ),
			'redemption_link' => esc_url( $test_redemption_link ),
			'site_name'       => get_bloginfo( 'name' ),
			'blogname'        => get_bloginfo( 'name' ),
			'user_login'      => wp_get_current_user()->user_login,
			'user_email'      => wp_get_current_user()->user_email,
			'user_first_name' => wp_get_current_user()->first_name ?: 'John',
			'user_last_name'  => wp_get_current_user()->last_name ?: 'Doe',
		);
		
		$email_body = MPGR_Reminders::replace_email_variables( $email_body, $test_variables );
		
		// Wrap test email body with header/footer (same as actual emails)
		$header_content = MPGR_Reminders::get_email_header( $test_variables );
		$footer_content = MPGR_Reminders::get_email_footer( $test_variables );
		$email_body = $header_content . $email_body . $footer_content;

		// Set headers for HTML email
		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			'From: ' . get_bloginfo( 'name' ) . ' <' . get_option( 'admin_email' ) . '>',
		);

		// Replace subject variables
		$subject = MPGR_Reminders::replace_email_variables( $email_subject, $test_variables );
		$subject = 'Test: ' . $subject;

		// Send test email
		$sent = wp_mail( $email, $subject, $email_body, $headers );

		if ( $sent ) {
			// translators: %s is the email address
			wp_send_json_success( array( 'message' => sprintf( __( 'Test email sent successfully to %s', 'memberpress-gift-reporter' ), $email ) ) );
		} else {
			wp_send_json_error( array( 'message' => __( 'Failed to send test email. Please check your email configuration.', 'memberpress-gift-reporter' ) ) );
		}
	}

    
}
