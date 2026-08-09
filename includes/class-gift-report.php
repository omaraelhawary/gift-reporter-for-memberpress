<?php
/**
 * Gift Report Class
 * 
 * @package MemberPressGiftReporter
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main gift report functionality
 */
class MPGR_Gift_Report {

    /**
     * SQL predicate matching a refunded gift purchase.
     *
     * Refunded purchases stay listed in the report so they can be found and
     * reconciled, but they are excluded from revenue totals, the claim rate,
     * and the unclaimed count, and they are never emailed a reminder. That is
     * the same rule MPGR_Reminders and MPGR_Weekly_Summary already apply by
     * selecting only 'complete' and 'confirmed' transactions.
     */
    private const SQL_IS_REFUNDED = "gifter_txn.status = 'refunded'";

    /**
     * Gift status value used for refunded purchases.
     *
     * Kept distinct from the '_gift_status' meta values ('claimed' /
     * 'unclaimed'), which the Gifting add-on does not change on refund.
     */
    private const STATUS_REFUNDED = 'refunded';

    /**
     * How long a cached summary stays valid, in seconds.
     *
     * Matches the aging-arcs cache. Short enough that a stale figure is never
     * interesting, long enough to absorb the repeated calls a single page view
     * makes (row count, filtered summary, all-time summary).
     */
    private const SUMMARY_CACHE_TTL = 300;

    /**
     * Option holding the summary cache generation.
     *
     * Summaries are keyed by a hash of their filters, so there is no way to
     * enumerate them for deletion. Bumping this number namespaces every key at
     * once; the orphaned transients expire on their own within the TTL.
     */
    private const SUMMARY_VERSION_OPTION = 'mpgr_summary_cache_version';

    /**
     * Default and maximum rows per REST page.
     *
     * The ceiling keeps one request from re-running the join set over an
     * unbounded row count; clients that want everything should page.
     */
    private const REST_DEFAULT_PER_PAGE = 100;
    private const REST_MAX_PER_PAGE     = 200;

    /**
     * Plugin instance.
     *
     * @var self|null
     */
    private static $instance = null;

    /**
     * Report data property
     */
    private $report_data = array();

    /**
     * Per-request cache for product dropdown query.
     *
     * @var array|null
     */
    private $products_cache = null;

    /**
     * Get plugin instance.
     *
     * @return self
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
		// Handle AJAX requests.
		add_action( 'wp_ajax_mpgr_export_csv', array( $this, 'ajax_export_csv' ) );
		add_action( 'wp_ajax_mpgr_resend_gift_email', array( $this, 'ajax_resend_gift_email' ) );
		add_action( 'wp_ajax_mpgr_copy_redemption_link', array( $this, 'ajax_copy_redemption_link' ) );
		add_action( 'wp_ajax_mpgr_bulk_resend_gift_emails', array( $this, 'ajax_bulk_resend_gift_emails' ) );
		add_action( 'mpgr_send_queued_gift_email', array( $this, 'process_queued_gift_email' ) );

		// Add REST API endpoint.
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

		// Drop cached summaries as soon as the underlying data changes.
		add_action( 'mpgft-gift-purchased', array( __CLASS__, 'invalidate_summary_cache' ) );
		add_action( 'mpgft-gift-claimed', array( __CLASS__, 'invalidate_summary_cache' ) );
	}
    
    /**
     * Locate email template with theme override support
     * 
     * Checks for template in theme directory first, then falls back to plugin directory.
     * Theme template path: your-theme/memberpress-gift-reporter/emails/{template-name}.php
     * Plugin template path: plugin/views/emails/{template-name}.php
     * 
     * @param string $template_name The template name (without .php extension)
     * @return string Full path to the template file
     */
    private function locate_email_template( $template_name ) {
        // Sanitize template name to prevent directory traversal
        $template_name = sanitize_file_name( $template_name );
        
        // Check in theme directory first (for overrides)
        $theme_template = get_stylesheet_directory() . '/memberpress-gift-reporter/emails/' . $template_name . '.php';
        if ( file_exists( $theme_template ) ) {
            return $theme_template;
        }
        
        // Check in parent theme directory (for child themes)
        $parent_template = get_template_directory() . '/memberpress-gift-reporter/emails/' . $template_name . '.php';
        if ( file_exists( $parent_template ) ) {
            return $parent_template;
        }
        
        // Fall back to plugin template
        $plugin_template = MPGR_PLUGIN_PATH . 'views/emails/' . $template_name . '.php';
        if ( file_exists( $plugin_template ) ) {
            return $plugin_template;
        }
        
        // Return plugin template path even if it doesn't exist (will show error)
        return $plugin_template;
    }
    
    /**
     * Render email template with variables
     * 
     * @param string $template_name The template name (without .php extension)
     * @param array $variables Associative array of variables to pass to template
     * @return string Rendered template content
     */
    private function render_email_template( $template_name, $variables = array() ) {
        $template_path = $this->locate_email_template( $template_name );
        
        if ( ! file_exists( $template_path ) ) {
            // Fallback to inline template if file doesn't exist
            return $this->get_fallback_email_template( $variables );
        }
        
        // Extract variables for template
        extract( $variables, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract -- Safe extraction for template variables
        
        // For reminder-email template, include header first, then template (body only), then footer
        if ( 'reminder-email' === $template_name && class_exists( 'MPGR_Reminders' ) ) {
            $header_content = MPGR_Reminders::get_email_header( $variables );
            ob_start();
            include $template_path;
            $body_content = ob_get_clean();
            $footer_content = MPGR_Reminders::get_email_footer( $variables );
            // Header opens <div class="content">, template provides body, footer closes content div and HTML
            return $header_content . $body_content . $footer_content;
        }
        
        // Start output buffering
        ob_start();
        include $template_path;
        return ob_get_clean();
    }
    
    /**
     * Fallback email template (used if template file doesn't exist)
     * 
     * Note: Inline <style> tags are used here because email clients require inline styles
     * for proper rendering. External stylesheets are not supported by most email clients.
     * This is the standard practice for HTML emails and is different from web pages.
     * 
     * @param array $variables Template variables
     * @return string HTML email content
     */
    private function get_fallback_email_template( $variables ) {
        $product_name = isset( $variables['product_name'] ) ? $variables['product_name'] : '';
        $redemption_link = isset( $variables['redemption_link'] ) ? $variables['redemption_link'] : '';
        $site_name = isset( $variables['site_name'] ) ? $variables['site_name'] : get_bloginfo( 'name' );
        
        return sprintf(
            '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>%1$s</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background-color: #f8f9fa; padding: 20px; border-radius: 8px; margin-bottom: 20px; }
        .content { background-color: #ffffff; padding: 20px; border-radius: 8px; border: 1px solid #e9ecef; }
        .coupon-code { background-color: #e3f2fd; padding: 15px; border-radius: 6px; border-left: 4px solid #2196f3; margin: 20px 0; font-family: monospace; font-size: 16px; font-weight: bold; }
        .redemption-link { background-color: #f3e5f5; padding: 15px; border-radius: 6px; border-left: 4px solid #9c27b0; margin: 20px 0; }
        .redemption-link a { color: #9c27b0; text-decoration: none; font-weight: bold; }
        .redemption-link a:hover { text-decoration: underline; }
        .footer { margin-top: 30px; padding-top: 20px; border-top: 1px solid #e9ecef; color: #6c757d; font-size: 14px; }
        .greeting { font-size: 18px; font-weight: bold; margin-bottom: 20px; }
        .product-name { font-weight: bold; color: #2c3e50; }
        .thank-you { font-style: italic; color: #27ae60; }
    </style>
</head>
<body>
    <div class="header">
        <h1 style="margin: 0; color: #2c3e50;">🎁 Gift Membership Purchase</h1>
    </div>
    
    <div class="content">
        <div class="greeting">Hello!</div>
        
        <p>You have purchased a gift membership for <span class="product-name">%1$s</span>.</p>
        
        <div class="redemption-link">
            <strong>The recipient can redeem this gift by visiting:</strong><br>
            <a href="%2$s">%2$s</a>
        </div>
        
        <p class="thank-you">Thank you for your purchase!</p>
        
    </div>
</body>
</html>',
            esc_html( $product_name ),
            esc_url( $redemption_link ),
            esc_html( $site_name )
        );
    }
    

    
    /**
     * AJAX export handler
     */
	public function ajax_export_csv() {
		// Verify nonce and permissions.
		$nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
		if ( ! wp_verify_nonce( $nonce, 'mpgr_export_csv' ) || ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Access denied', 'memberpress-gift-reporter' ) );
		}

		// Check rate limiting
		if ($this->is_rate_limited()) {
			wp_die( esc_html__( 'Rate limit exceeded. Please wait before trying again.', 'memberpress-gift-reporter' ) );
		}

		// Get and sanitize filter parameters - only extract specific expected fields
		$filters = $this->sanitize_ajax_filters();

		try {
			$this->export_csv( 'memberpress_gift_report.csv', $filters );
		} catch (Exception $e) {
			wp_die( esc_html__( 'Error generating export. Please try again.', 'memberpress-gift-reporter' ) );
		}
	}
    
    /**
     * Build and send a gift reminder email for one transaction.
     *
     * @param int  $gift_transaction_id Gift transaction ID.
     * @param bool $require_unclaimed   Only send when gift status is unclaimed.
     * @return array{success: bool, recipient?: string, error?: string}
     */
    private function send_gift_email_for_transaction( $gift_transaction_id, $require_unclaimed = false ) {
        global $wpdb;

        $gift_transaction_id = (int) $gift_transaction_id;
        if ( $gift_transaction_id <= 0 ) {
            return array( 'success' => false, 'error' => 'invalid_id' );
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $gift_transaction = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}mepr_transactions WHERE id = %d",
                $gift_transaction_id
            )
        );

        if ( ! $gift_transaction ) {
            return array( 'success' => false, 'error' => 'transaction_not_found' );
        }

        if ( $require_unclaimed ) {
            // A refund does not change '_gift_status', so the meta check below
            // would happily pass a refunded purchase through and email its
            // gifter about a gift they no longer own. Check the transaction too.
            if ( self::STATUS_REFUNDED === $gift_transaction->status ) {
                return array( 'success' => false, 'error' => 'refunded' );
            }

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $gift_status = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT meta_value FROM {$wpdb->prefix}mepr_transaction_meta
                    WHERE transaction_id = %d AND meta_key = '_gift_status'",
                    $gift_transaction_id
                )
            );
            if ( $gift_status !== 'unclaimed' && ! empty( $gift_status ) ) {
                return array( 'success' => false, 'error' => 'already_claimed' );
            }
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $gift_coupon_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT meta_value FROM {$wpdb->prefix}mepr_transaction_meta
                WHERE transaction_id = %d AND meta_key = '_gift_coupon_id'",
                $gift_transaction_id
            )
        );

        if ( ! $gift_coupon_id ) {
            return array( 'success' => false, 'error' => 'coupon_not_found' );
        }

        $coupon_code = get_post_field( 'post_title', $gift_coupon_id );
        if ( ! $coupon_code ) {
            return array( 'success' => false, 'error' => 'coupon_code_not_found' );
        }

        $gifter_user = get_userdata( $gift_transaction->user_id );
        if ( ! $gifter_user ) {
            return array( 'success' => false, 'error' => 'gifter_not_found' );
        }

        $gifter_email = sanitize_email( $gifter_user->user_email );
        if ( empty( $gifter_email ) || ! is_email( $gifter_email ) ) {
            return array( 'success' => false, 'error' => 'invalid_email' );
        }

        $product_name    = get_post_field( 'post_title', $gift_transaction->product_id );
        $redemption_link = $this->generate_redemption_url( $gift_transaction->product_id, $coupon_code );
        $blogname        = get_bloginfo( 'name' );

        $template_vars = array(
            'product_name'    => $product_name,
            'redemption_link' => $redemption_link,
            'site_name'       => $blogname,
            'blogname'        => $blogname,
            'user_login'      => $gifter_user->user_login,
            'user_email'      => $gifter_user->user_email,
            'user_first_name' => get_user_meta( $gifter_user->ID, 'first_name', true ),
            'user_last_name'  => get_user_meta( $gifter_user->ID, 'last_name', true ),
        );

        $settings          = MPGR_Reminders::get_settings();
        $gifter_email_body = ! empty( $settings['gifter_email_body'] ) ? $settings['gifter_email_body'] : ( ! empty( $settings['email_body'] ) ? $settings['email_body'] : '' );

        if ( ! empty( $gifter_email_body ) ) {
            $message        = MPGR_Reminders::replace_email_variables( $gifter_email_body, $template_vars );
            $header_content = MPGR_Reminders::get_email_header( $template_vars );
            $footer_content = MPGR_Reminders::get_email_footer( $template_vars );
            $message        = $header_content . $message . $footer_content;
        } else {
            $message = MPGR_Reminders::render_email_template( 'reminder-email', $template_vars );
        }

        $gifter_subject = ! empty( $settings['gifter_email_subject'] ) ? $settings['gifter_email_subject'] : ( ! empty( $settings['email_subject'] ) ? $settings['email_subject'] : '' );
        if ( ! empty( $gifter_subject ) ) {
            $subject = MPGR_Reminders::replace_email_variables( $gifter_subject, $template_vars );
        } else {
            $subject = sprintf(
                /* translators: %s: product name */
                __( 'Your Gift Purchase - %s', 'memberpress-gift-reporter' ),
                $product_name
            );
        }

        $sent = wp_mail( $gifter_email, $subject, $message, MPGR_Reminders::get_email_headers() );

        if ( ! $sent ) {
            return array( 'success' => false, 'error' => 'send_failed' );
        }

        return array(
            'success'   => true,
            'recipient' => $gifter_email,
        );
    }

    /**
     * Cron callback: send one queued bulk reminder email.
     *
     * @param int $gift_transaction_id Gift transaction ID.
     */
    public function process_queued_gift_email( $gift_transaction_id ) {
        $result = $this->send_gift_email_for_transaction( (int) $gift_transaction_id, true );
        $sent   = ! empty( $result['success'] );

        MPGR_Reminders::log_reminder_attempt(
            (int) $gift_transaction_id,
            'bulk',
            $sent,
            isset( $result['recipient'] ) ? $result['recipient'] : '',
            isset( $result['error'] ) ? $result['error'] : ''
        );

        if ( $sent ) {
            MPGR_Reminders::record_manual_reminder_sent( (int) $gift_transaction_id );
        }
    }

    /**
     * AJAX resend gift email handler
     */
	public function ajax_resend_gift_email() {
		// Verify nonce and permissions.
		$nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
		if ( ! wp_verify_nonce( $nonce, 'mpgr_resend_gift_email' ) || ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Access denied', 'memberpress-gift-reporter' ) );
		}

		$gift_transaction_id = isset($_POST['gift_transaction_id']) ? intval(sanitize_text_field(wp_unslash($_POST['gift_transaction_id']))) : 0;
		
		if (!$gift_transaction_id) {
			wp_send_json_error('Invalid gift transaction ID');
		}

		$result = $this->send_gift_email_for_transaction( $gift_transaction_id, false );

		MPGR_Reminders::log_reminder_attempt(
			$gift_transaction_id,
			'manual',
			! empty( $result['success'] ),
			isset( $result['recipient'] ) ? $result['recipient'] : '',
			isset( $result['error'] ) ? $result['error'] : ''
		);

		if ( ! empty( $result['success'] ) ) {
			MPGR_Reminders::record_manual_reminder_sent( $gift_transaction_id );
			wp_send_json_success(
				array(
					'message' => sprintf(
						/* translators: %s: gifter email address */
						__( 'Gift email resent successfully to %s', 'memberpress-gift-reporter' ),
						$result['recipient']
					),
				)
			);
		}

		wp_send_json_error( __( 'Failed to send gift email. Please check your email configuration.', 'memberpress-gift-reporter' ) );
	}
    
    /**
     * AJAX copy redemption link handler
     */
	public function ajax_copy_redemption_link() {
		// Verify nonce and permissions.
		$nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
		if ( ! wp_verify_nonce( $nonce, 'mpgr_copy_redemption_link' ) || ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Access denied', 'memberpress-gift-reporter' ) );
		}

		$gift_transaction_id = isset($_POST['gift_transaction_id']) ? intval(sanitize_text_field(wp_unslash($_POST['gift_transaction_id']))) : 0;
		
		if (!$gift_transaction_id) {
			wp_send_json_error('Invalid gift transaction ID');
		}

		// Get gift coupon ID
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Necessary for gift coupon lookup
		$gift_coupon_id = $wpdb->get_var($wpdb->prepare(
			"SELECT meta_value FROM {$wpdb->prefix}mepr_transaction_meta 
			WHERE transaction_id = %d AND meta_key = '_gift_coupon_id'",
			$gift_transaction_id
		));

		if (!$gift_coupon_id) {
			wp_send_json_error('Gift coupon not found');
		}

		// Get coupon code
		$coupon_code = get_post_field('post_title', $gift_coupon_id);
		
		if (!$coupon_code) {
			wp_send_json_error('Coupon code not found');
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$product_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT product_id FROM {$wpdb->prefix}mepr_transactions WHERE id = %d",
				$gift_transaction_id
			)
		);

		if (!$product_id) {
			wp_send_json_error('Product not found');
		}

		// Generate redemption link using product URL
		$redemption_link = $this->generate_redemption_url( $product_id, $coupon_code );

		wp_send_json_success(array(
			'redemption_link' => $redemption_link,
			'message' => __('Redemption link copied to clipboard', 'memberpress-gift-reporter')
		));
	}
    
    /**
     * AJAX bulk resend gift emails handler
     */
	public function ajax_bulk_resend_gift_emails() {
		// Verify nonce and permissions.
		$nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
		if ( ! wp_verify_nonce( $nonce, 'mpgr_bulk_resend_gift_emails' ) || ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Access denied', 'memberpress-gift-reporter' ) ) );
		}

		$raw_ids = isset( $_POST['gift_transaction_ids'] ) ? wp_unslash( $_POST['gift_transaction_ids'] ) : array();
		if ( ! is_array( $raw_ids ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Invalid input.', 'memberpress-gift-reporter' ) ) );
		}

		$gift_transaction_ids = array_filter( array_map( 'intval', $raw_ids ) );
		
		if (empty($gift_transaction_ids)) {
			wp_send_json_error( array( 'message' => esc_html__( 'No gifts selected', 'memberpress-gift-reporter' ) ) );
		}

		$max_bulk_limit = 100;
		if ( count( $gift_transaction_ids ) > $max_bulk_limit ) {
			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: %d: maximum gifts per batch */
						esc_html__( 'Too many gifts selected. Maximum %d gifts allowed per batch.', 'memberpress-gift-reporter' ),
						$max_bulk_limit
					),
				)
			);
		}

		global $wpdb;

		$queued_count  = 0;
		$skipped_count = 0;
		$delay_index   = 0;

		foreach ( $gift_transaction_ids as $gift_transaction_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$transaction_status = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT status FROM {$wpdb->prefix}mepr_transactions WHERE id = %d",
					$gift_transaction_id
				)
			);

			// A refund leaves '_gift_status' as 'unclaimed', so this has to be
			// checked separately or refunded gifts get reminder emails.
			if ( self::STATUS_REFUNDED === $transaction_status ) {
				++$skipped_count;
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$gift_status = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT meta_value FROM {$wpdb->prefix}mepr_transaction_meta
					WHERE transaction_id = %d AND meta_key = '_gift_status'",
					$gift_transaction_id
				)
			);

			if ( $gift_status !== 'unclaimed' && ! empty( $gift_status ) ) {
				++$skipped_count;
				continue;
			}

			if ( wp_next_scheduled( 'mpgr_send_queued_gift_email', array( $gift_transaction_id ) ) ) {
				++$skipped_count;
				continue;
			}

			wp_schedule_single_event(
				time() + $delay_index,
				'mpgr_send_queued_gift_email',
				array( $gift_transaction_id )
			);
			++$queued_count;
			++$delay_index;
		}

		if ( 0 === $queued_count ) {
			wp_send_json_error(
				array(
					'message' => esc_html__( 'No eligible unclaimed gifts to queue. Claimed or invalid gifts were skipped.', 'memberpress-gift-reporter' ),
				)
			);
		}

		if ( $queued_count > 0 && function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}

		wp_send_json_success(
			array(
				'message'       => sprintf(
					/* translators: %d: number of queued emails */
					esc_html__( '%d reminder email(s) queued. They will be sent over the next few minutes.', 'memberpress-gift-reporter' ),
					$queued_count
				),
				'queued_count'  => $queued_count,
				'skipped_count' => $skipped_count,
				'success_count' => $queued_count,
				'failed_count'  => $skipped_count,
			)
		);
	}
    
    /**
     * Generate gift redemption URL using product URL (like gifting plugin)
     * 
     * @param int    $product_id Product ID
     * @param string $coupon_code Coupon code
     * @return string Redemption URL
     */
    private function generate_redemption_url( $product_id, $coupon_code ) {
		if ( ! class_exists( 'MeprProduct' ) ) {
			// Fallback to hardcoded path if MemberPress not available
			return home_url( '/register/?coupon=' . urlencode( $coupon_code ) );
		}

		$product = new \MeprProduct( $product_id );
		if ( ! $product || ! $product->ID ) {
			// Fallback if product not found
			return home_url( '/register/?coupon=' . urlencode( $coupon_code ) );
		}

		// Use product URL and add coupon parameter (same as gifting plugin)
		$url = $product->url();
		if ( ! empty( $coupon_code ) ) {
			$url = add_query_arg( 'coupon', $coupon_code, $url );
		}

		return esc_url( $url );
	}

    /**
     * Register REST API routes
     */
    public function register_rest_routes() {
        register_rest_route('mpgr/v1', '/report', array(
            'methods' => 'GET',
            'callback' => array($this, 'rest_get_report'),
            'permission_callback' => array($this, 'rest_permission_check'),
            'args' => array_merge( self::get_rest_filter_args(), self::get_rest_pagination_args() ),
        ));
        
        register_rest_route('mpgr/v1', '/export', array(
            'methods' => 'POST',
            'callback' => array($this, 'rest_export_csv'),
            'permission_callback' => array($this, 'rest_permission_check'),
            'args' => self::get_rest_filter_args(),
        ));
    }

    /**
     * REST argument definitions for pagination params.
     *
     * @return array
     */
    public static function get_rest_pagination_args() {
        return array(
            'page'     => array(
                'required'          => false,
                'default'           => 1,
                'sanitize_callback' => 'absint',
            ),
            'per_page' => array(
                'required'          => false,
                'default'           => self::REST_DEFAULT_PER_PAGE,
                'sanitize_callback' => 'absint',
            ),
        );
    }

    /**
     * Clamp a requested page size into the supported range.
     *
     * Out-of-range values are clamped rather than rejected, and the response
     * reports the size actually used, so a client asking for 5000 gets a
     * working answer it can page through instead of a 400.
     *
     * @param mixed $per_page Requested page size.
     * @return int
     */
    private static function clamp_per_page( $per_page ) {
        $per_page = (int) $per_page;

        if ( $per_page < 1 ) {
            return self::REST_DEFAULT_PER_PAGE;
        }

        return min( $per_page, self::REST_MAX_PER_PAGE );
    }

    /**
     * REST argument definitions for report filter params.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function get_rest_filter_args() {
        $args = array(
            'nonce' => array(
                'required' => false,
                'sanitize_callback' => 'sanitize_text_field',
            ),
        );

        foreach ( self::get_filter_schema() as $field => $sanitize_function ) {
            $args[ $field ] = array(
                'required'          => false,
                'sanitize_callback' => $sanitize_function,
            );
        }

        return $args;
    }
    
    /**
     * REST API permission check
     */
    public function rest_permission_check($request) {
        // Check if user is logged in and has proper capabilities
        if (!is_user_logged_in() || !current_user_can('manage_options')) {
            return false;
        }

        /*
         * Verify the WordPress REST nonce, but only for cookie-authenticated
         * requests. The nonce is CSRF protection: it exists because a browser
         * attaches its login cookie to cross-site requests automatically. A
         * request authenticated by Application Password (or any other scheme
         * that carries its own credentials) has no ambient credential to abuse,
         * and no way to obtain a nonce in the first place. Requiring one there
         * blocked every non-browser client from the endpoint.
         *
         * Core makes the same distinction in rest_cookie_check_errors(), which
         * already drops a nonce-less cookie request to user 0 before this
         * callback runs; the check below is kept as defence in depth.
         */
        if ( self::request_has_auth_cookie() ) {
            $nonce = $request->get_header( 'X-WP-Nonce' ) ?: $request->get_param( 'nonce' );
            if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
                return false;
            }
        }

        // Add rate limiting check
        if ($this->is_rate_limited()) {
            return false;
        }

        return true;
    }

    /**
     * Whether the current request carries a WordPress login cookie.
     *
     * Presence of the cookie — not the identity of the authenticated user — is
     * what makes a request CSRF-able, so this deliberately errs toward
     * requiring a nonce: a client sending both a cookie and an Application
     * Password is still treated as a cookie request.
     *
     * @return bool
     */
    private static function request_has_auth_cookie() {
        return false !== wp_parse_auth_cookie( '', 'logged_in' );
    }
    
    /**
     * Rate limiting check
     */
    private function is_rate_limited() {
        $user_id = get_current_user_id();
        $rate_limit_key = 'mpgr_rate_limit_' . $user_id;
        $rate_limit_time = 60; // 1 minute
        $max_requests = 10; // Max 10 requests per minute
        
        $current_time = time();
        $requests = get_transient($rate_limit_key);
        
        if (!$requests) {
            $requests = array();
        }
        
        // Remove old requests
        $requests = array_filter($requests, function($time) use ($current_time, $rate_limit_time) {
            return ($current_time - $time) < $rate_limit_time;
        });
        
        // Check if limit exceeded
        if (count($requests) >= $max_requests) {
            return true;
        }
        
        // Add current request
        $requests[] = $current_time;
        set_transient($rate_limit_key, $requests, $rate_limit_time);
        
        return false;
    }
    
    /**
     * REST API get report
     */
    public function rest_get_report( $request ) {
        try {
            $filters  = self::sanitize_filters( $request );
            $per_page = self::clamp_per_page( $request->get_param( 'per_page' ) );
            $page     = max( 1, (int) $request->get_param( 'page' ) );

            // Cached, so asking for the total costs nothing beyond the first
            // page of a given filter set.
            $summary     = $this->get_summary( $filters );
            $total       = isset( $summary['total_gifts'] ) ? (int) $summary['total_gifts'] : 0;
            $total_pages = (int) ceil( $total / $per_page );

            $data = $this->generate_report(
                $per_page,
                ( $page - 1 ) * $per_page,
                $filters,
                $this->get_default_sort_clause()
            );

            $response = new WP_REST_Response(
                array(
                    'success'     => true,
                    'data'        => $data,
                    'summary'     => $summary,
                    'page'        => $page,
                    'per_page'    => $per_page,
                    'total'       => $total,
                    'total_pages' => $total_pages,
                )
            );

            // The conventional WordPress pagination headers, so generic REST
            // clients can page without reading the body.
            $response->header( 'X-WP-Total', (string) $total );
            $response->header( 'X-WP-TotalPages', (string) $total_pages );

            return $response;
        } catch ( Exception $e ) {
            return new WP_Error( 'report_error', 'Unable to generate report', array( 'status' => 500 ) );
        }
    }

    /**
     * REST API export CSV
     */
    public function rest_export_csv( $request ) {
        $filters = self::sanitize_filters( $request );
        $this->export_csv( 'memberpress_gift_report.csv', $filters );
        // export_csv() streams the file and exits.
    }

    /**
     * Filter field schema (single source of truth for admin, AJAX, and REST).
     *
     * @return array<string, callable>
     */
    public static function get_filter_schema() {
        return array(
            'date_from'            => 'sanitize_text_field',
            'date_to'              => 'sanitize_text_field',
            'gift_status'          => 'sanitize_text_field',
            'product'              => 'intval',
            'gifter_email'         => 'sanitize_email',
            'recipient_email'      => 'sanitize_email',
            'coupon_code'          => 'sanitize_text_field',
            'transaction_id'       => 'sanitize_text_field',
            'claim_transaction_id' => 'sanitize_text_field',
            'redemption_from'      => 'sanitize_text_field',
            'redemption_to'        => 'sanitize_text_field',
        );
    }

    /**
     * Sanitize report filters from GET/POST array or REST request.
     *
     * @param array|WP_REST_Request $source Raw input source.
     * @return array Sanitized filters.
     */
    public static function sanitize_filters( $source ) {
        $filters = array();

        foreach ( self::get_filter_schema() as $field => $sanitize_function ) {
            $value = null;

            if ( $source instanceof WP_REST_Request ) {
                $value = $source->get_param( $field );
            } elseif ( is_array( $source ) && isset( $source[ $field ] ) ) {
                $value = wp_unslash( $source[ $field ] );
            }

            if ( $value !== null && $value !== '' ) {
                $filters[ $field ] = call_user_func( $sanitize_function, $value );
            }
        }

        return $filters;
    }

    /**
     * Sanitize AJAX filter parameters from $_POST.
     *
     * @return array Sanitized filters.
     */
    private function sanitize_ajax_filters() {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in ajax_export_csv()
        return self::sanitize_filters( $_POST );
    }
    
    /**
     * Validate date format
     */
    private function is_valid_date($date_string) {
        if (empty($date_string)) {
            return false;
        }
        
        // Check if it's a valid date format (YYYY-MM-DD)
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_string)) {
            return false;
        }
        
        // Check if the date is actually valid
        $date = DateTime::createFromFormat('Y-m-d', $date_string);
        return $date && $date->format('Y-m-d') === $date_string;
    }
    

    
    /**
     * Build WHERE conditions for report queries (shared by list and summary).
     *
     * @param array $filters Filter values.
     * @return array Prepared SQL condition fragments.
     */
    private function build_where_conditions( $filters = array() ) {
        global $wpdb;

        $where_conditions   = array();
        $where_conditions[] = "gifter_txn.status IN ('complete', 'confirmed', 'refunded')";
        $where_conditions[] = "EXISTS (
            SELECT 1 FROM {$wpdb->prefix}mepr_transaction_meta AS gift_meta_check
            WHERE gift_meta_check.transaction_id = gifter_txn.id
            AND (
                (gift_meta_check.meta_key = '_gift_status' AND gift_meta_check.meta_value IN ('unclaimed', 'claimed'))
                OR (gift_meta_check.meta_key = '_gift_coupon_id' AND gift_meta_check.meta_value IS NOT NULL AND gift_meta_check.meta_value != '')
            )
        )";
        $where_conditions[] = 'gifter_txn.amount > 0';

        if ( ! empty( $filters['date_from'] ) ) {
            $date_from = sanitize_text_field( $filters['date_from'] );
            if ( $this->is_valid_date( $date_from ) ) {
                $date_from_formatted  = gmdate( 'Y-m-d 00:00:00', strtotime( $date_from ) );
                $where_conditions[]   = $wpdb->prepare( 'gifter_txn.created_at >= %s', $date_from_formatted );
            }
        }

        if ( ! empty( $filters['date_to'] ) ) {
            $date_to = sanitize_text_field( $filters['date_to'] );
            if ( $this->is_valid_date( $date_to ) ) {
                $date_to_formatted  = gmdate( 'Y-m-d 23:59:59', strtotime( $date_to ) );
                $where_conditions[] = $wpdb->prepare( 'gifter_txn.created_at <= %s', $date_to_formatted );
            }
        }

        if ( ! empty( $filters['gift_status'] ) ) {
            $gift_status = sanitize_text_field( $filters['gift_status'] );

            if ( self::STATUS_REFUNDED === $gift_status ) {
                $where_conditions[] = self::SQL_IS_REFUNDED;
            } else {
                // Refunded purchases keep their original '_gift_status' meta, so
                // they must be excluded here or they would show up under both
                // 'unclaimed' and 'refunded'. The constant is appended to the
                // prepared result so prepare() still receives a literal.
                $where_conditions[] = $wpdb->prepare(
                    "COALESCE(gift_status.meta_value, 'unclaimed') = %s",
                    $gift_status
                ) . ' AND NOT ( ' . self::SQL_IS_REFUNDED . ' )';
            }
        }

        if ( ! empty( $filters['product'] ) ) {
            $product_id         = intval( $filters['product'] );
            $where_conditions[] = $wpdb->prepare( 'gifter_txn.product_id = %d', $product_id );
        }

        if ( ! empty( $filters['gifter_email'] ) ) {
            $gifter_email       = sanitize_email( $filters['gifter_email'] );
            $where_conditions[] = $wpdb->prepare( 'gifter.user_email LIKE %s', '%' . $wpdb->esc_like( $gifter_email ) . '%' );
        }

        if ( ! empty( $filters['recipient_email'] ) ) {
            $recipient_email    = sanitize_email( $filters['recipient_email'] );
            $where_conditions[] = $wpdb->prepare( 'recipient.user_email LIKE %s', '%' . $wpdb->esc_like( $recipient_email ) . '%' );
        }

        if ( ! empty( $filters['coupon_code'] ) ) {
            // Partial match: support workflows usually start from a code pasted
            // out of a customer email, which may carry stray whitespace or only
            // be quoted in part.
            $coupon_code        = sanitize_text_field( $filters['coupon_code'] );
            $where_conditions[] = $wpdb->prepare( 'gift_coupon.post_title LIKE %s', '%' . $wpdb->esc_like( $coupon_code ) . '%' );
        }

        if ( ! empty( $filters['transaction_id'] ) ) {
            $transaction_id     = sanitize_text_field( $filters['transaction_id'] );
            $where_conditions[] = $wpdb->prepare( 'gifter_txn.trans_num LIKE %s', '%' . $wpdb->esc_like( $transaction_id ) . '%' );
        }

        if ( ! empty( $filters['claim_transaction_id'] ) ) {
            $claim_transaction_id = sanitize_text_field( $filters['claim_transaction_id'] );
            $where_conditions[]   = $wpdb->prepare( 'redemption_txn.trans_num LIKE %s', '%' . $wpdb->esc_like( $claim_transaction_id ) . '%' );
        }

        if ( ! empty( $filters['redemption_from'] ) ) {
            $redemption_from = sanitize_text_field( $filters['redemption_from'] );
            if ( $this->is_valid_date( $redemption_from ) ) {
                $redemption_from_formatted = gmdate( 'Y-m-d 00:00:00', strtotime( $redemption_from ) );
                $where_conditions[]        = $wpdb->prepare( 'redemption_txn.created_at >= %s', $redemption_from_formatted );
            }
        }

        if ( ! empty( $filters['redemption_to'] ) ) {
            $redemption_to = sanitize_text_field( $filters['redemption_to'] );
            if ( $this->is_valid_date( $redemption_to ) ) {
                $redemption_to_formatted = gmdate( 'Y-m-d 23:59:59', strtotime( $redemption_to ) );
                $where_conditions[]      = $wpdb->prepare( 'redemption_txn.created_at <= %s', $redemption_to_formatted );
            }
        }

        return $where_conditions;
    }

    /**
     * Shared JOIN clause for report and summary queries.
     *
     * @return string SQL JOIN fragment.
     */
    private function get_report_joins_sql() {
        global $wpdb;

        return "
            LEFT JOIN {$wpdb->users} AS gifter
                ON gifter_txn.user_id = gifter.ID

            LEFT JOIN {$wpdb->usermeta} AS gifter_fname
                ON gifter.ID = gifter_fname.user_id
                AND gifter_fname.meta_key = 'first_name'

            LEFT JOIN {$wpdb->usermeta} AS gifter_lname
                ON gifter.ID = gifter_lname.user_id
                AND gifter_lname.meta_key = 'last_name'

            INNER JOIN {$wpdb->posts} AS gift_product
                ON gifter_txn.product_id = gift_product.ID

            LEFT JOIN {$wpdb->prefix}mepr_transaction_meta AS coupon_meta
                ON gifter_txn.id = coupon_meta.transaction_id
                AND coupon_meta.meta_key = '_gift_coupon_id'
            LEFT JOIN {$wpdb->posts} AS gift_coupon
                ON coupon_meta.meta_value = gift_coupon.ID
                AND gift_coupon.post_status = 'publish'

            LEFT JOIN {$wpdb->prefix}mepr_transaction_meta AS gift_status
                ON gifter_txn.id = gift_status.transaction_id
                AND gift_status.meta_key = '_gift_status'

            LEFT JOIN (
                SELECT coupon_id, MIN(id) AS id
                FROM {$wpdb->prefix}mepr_transactions
                WHERE status = 'complete'
                AND coupon_id IS NOT NULL
                AND coupon_id > 0
                GROUP BY coupon_id
            ) AS redemption_pick
                ON coupon_meta.meta_value = redemption_pick.coupon_id
            LEFT JOIN {$wpdb->prefix}mepr_transactions AS redemption_txn
                ON redemption_txn.id = redemption_pick.id
                AND redemption_txn.id != gifter_txn.id

            LEFT JOIN {$wpdb->users} AS recipient
                ON redemption_txn.user_id = recipient.ID

            LEFT JOIN {$wpdb->usermeta} AS recipient_fname
                ON recipient.ID = recipient_fname.user_id
                AND recipient_fname.meta_key = 'first_name'

            LEFT JOIN {$wpdb->usermeta} AS recipient_lname
                ON recipient.ID = recipient_lname.user_id
                AND recipient_lname.meta_key = 'last_name'

            LEFT JOIN {$wpdb->prefix}mepr_transaction_meta AS reminder_count_meta
                ON gifter_txn.id = reminder_count_meta.transaction_id
                AND reminder_count_meta.meta_key = '_mpgr_reminder_sent_count'

            LEFT JOIN {$wpdb->prefix}mepr_transaction_meta AS reminder_ts_meta
                ON gifter_txn.id = reminder_ts_meta.transaction_id
                AND reminder_ts_meta.meta_key = '_mpgr_last_reminder_ts'

            LEFT JOIN {$wpdb->prefix}mepr_transaction_meta AS reminder_log_meta
                ON gifter_txn.id = reminder_log_meta.transaction_id
                AND reminder_log_meta.meta_key = '" . MPGR_Reminders::LOG_META_KEY . "'
        ";
    }

    /**
     * Default ORDER BY for report queries (CSV export and fallback).
     *
     * @return string SQL ORDER BY clause.
     */
    private function get_default_sort_clause() {
        return ' ORDER BY gifter_txn.created_at DESC ';
    }

    /**
     * ORDER BY from allow-listed admin sort parameters.
     *
     * @return string SQL ORDER BY clause.
     */
    private function get_sort_clause() {
        $allowed = array(
            'gift_transaction_id'   => 'gifter_txn.id',
            'gift_transaction_number' => 'gifter_txn.trans_num',
            'gift_purchase_date'    => 'gifter_txn.created_at',
            'gift_total'          => 'gifter_txn.total',
            'product_name'        => 'gift_product.post_title',
            'gift_status'         => "COALESCE(gift_status.meta_value, 'unclaimed')",
            'redemption_date'     => 'redemption_txn.created_at',
            'reminders_sent'      => 'CAST(COALESCE(reminder_count_meta.meta_value, 0) AS UNSIGNED)',
        );

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only sort on admin report screen.
        $orderby_key = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'gift_purchase_date';
        $order       = ( isset( $_GET['order'] ) && 'ASC' === strtoupper( sanitize_text_field( wp_unslash( $_GET['order'] ) ) ) ) ? 'ASC' : 'DESC';

        $orderby_sql = isset( $allowed[ $orderby_key ] ) ? $allowed[ $orderby_key ] : $allowed['gift_purchase_date'];

        return ' ORDER BY ' . $orderby_sql . ' ' . $order . ' ';
    }

    /**
     * Build admin URL for report list with filters, sort, and pagination preserved.
     *
     * @param array $filters     Active filters.
     * @param array $extra_args  Additional query args.
     * @return string Admin URL.
     */
    public function get_report_url( $filters = array(), $extra_args = array() ) {
        return $this->get_report_page_url( $filters, $extra_args );
    }

    /**
     * Build admin URL for report list with filters, sort, and pagination preserved.
     *
     * @param array $filters     Active filters.
     * @param array $extra_args  Additional query args.
     * @return string Admin URL.
     */
    private function get_report_page_url( $filters = array(), $extra_args = array() ) {
        $args = array_merge(
            array(
                'page'     => 'memberpress-gift-report',
                '_wpnonce' => wp_create_nonce( 'mpgr_filter_nonce' ),
            ),
            $filters,
            $extra_args
        );

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ! empty( $_GET['orderby'] ) ) {
            $args['orderby'] = sanitize_key( wp_unslash( $_GET['orderby'] ) );
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ! empty( $_GET['order'] ) ) {
            $args['order'] = sanitize_text_field( wp_unslash( $_GET['order'] ) );
        }

        return add_query_arg( $args, admin_url( 'admin.php' ) );
    }

    /**
     * Build admin URLs for entities shown in the report.
     *
     * @param string $type One of: transaction, user, product, coupon.
     * @param int    $id   Entity ID.
     * @return string Escaped URL, or empty string.
     */
    private function admin_link_url( $type, $id ) {
        $id = (int) $id;
        if ( $id <= 0 ) {
            return '';
        }

        switch ( $type ) {
            case 'transaction':
                return esc_url( admin_url( 'admin.php?page=memberpress-trans&action=edit&id=' . $id ) );
            case 'user':
                return esc_url( admin_url( 'user-edit.php?user_id=' . $id ) );
            case 'product':
            case 'coupon':
                return esc_url( admin_url( 'post.php?post=' . $id . '&action=edit' ) );
            default:
                return '';
        }
    }

    /**
     * Wrap a label in a link to the entity admin page.
     *
     * @param string $label Display text.
     * @param string $type  Entity type for admin_link_url().
     * @param int    $id    Entity ID.
     * @return string HTML (pre-escaped).
     */
    private function admin_link( $label, $type, $id ) {
        $url = $this->admin_link_url( $type, $id );
        if ( '' === $url ) {
            return esc_html( $label );
        }

        return '<a href="' . $url . '">' . esc_html( $label ) . '</a>';
    }

    /**
     * Render a sortable table column header.
     *
     * @param string $label          Column label.
     * @param string $column_key     Sort key.
     * @param array  $filters        Active filters.
     * @param string $current_orderby Active orderby key.
     * @param string $current_order   Active order (ASC|DESC).
     */
    private function render_sortable_column_header( $label, $column_key, $filters, $current_orderby, $current_order ) {
        $sortable = array(
            'gift_transaction_id',
            'gift_transaction_number',
            'gift_purchase_date',
            'gift_total',
            'product_name',
            'gift_status',
            'redemption_date',
            'reminders_sent',
        );

        $th_class   = $this->get_table_column_class( $column_key );
        $class_attr = '' !== $th_class ? ' class="' . esc_attr( $th_class ) . '"' : '';

        if ( ! in_array( $column_key, $sortable, true ) ) {
            echo '<th' . $class_attr . '>' . esc_html( $label ) . '</th>';
            return;
        }

        $is_active  = ( $current_orderby === $column_key );
        $next_order = ( $is_active && 'DESC' === $current_order ) ? 'ASC' : 'DESC';
        $url        = $this->get_report_page_url(
            $filters,
            array(
                'orderby' => $column_key,
                'order'   => $next_order,
                'paged'   => 1,
            )
        );
        $aria_sort  = $is_active ? ( 'ASC' === $current_order ? 'ascending' : 'descending' ) : 'none';

        echo '<th' . $class_attr . ' aria-sort="' . esc_attr( $aria_sort ) . '"><a href="' . esc_url( $url ) . '" class="mpgr-sort-link">' . esc_html( $label ) . '</a></th>';
    }

    /**
     * CSS class for table column layout by sort/filter key.
     *
     * @param string $column_key Column identifier.
     * @return string Space-separated class names.
     */
    private function get_table_column_class( $column_key ) {
        $map = array(
            'gift_transaction_id'     => 'mpgr-col-id',
            'gift_transaction_number' => 'mpgr-col-id',
            'gift_purchase_date'      => 'mpgr-col-nowrap',
            'gift_total'          => 'mpgr-col-nowrap',
            'gift_status'         => 'mpgr-col-nowrap',
            'redemption_date'     => 'mpgr-col-nowrap',
            'reminders_sent'      => 'mpgr-col-nowrap',
            'product_name'        => 'mpgr-col-product',
        );

        return isset( $map[ $column_key ] ) ? $map[ $column_key ] : '';
    }

    /**
     * Output quick-filter preset buttons.
     *
     * @param array $filters Active filters (unused; presets replace filters).
     */
    private function render_filter_presets() {
        $presets = array(
            array(
                'label' => __( 'Unclaimed > 7 days', 'memberpress-gift-reporter' ),
                'args'  => array(
                    'gift_status' => 'unclaimed',
                    'date_to'     => gmdate( 'Y-m-d', strtotime( '-7 days' ) ),
                ),
            ),
            array(
                'label' => __( 'This month', 'memberpress-gift-reporter' ),
                'args'  => array(
                    'date_from' => gmdate( 'Y-m-01' ),
                    'date_to'   => gmdate( 'Y-m-d' ),
                ),
            ),
            array(
                'label' => __( 'Claimed last 30 days', 'memberpress-gift-reporter' ),
                'args'  => array(
                    'gift_status'      => 'claimed',
                    'redemption_from'  => gmdate( 'Y-m-d', strtotime( '-30 days' ) ),
                ),
            ),
        );

        echo '<div class="mpgr-presets">';
        foreach ( $presets as $preset ) {
            $url = $this->get_report_page_url( $preset['args'] );
            echo '<a href="' . esc_url( $url ) . '" class="button">' . esc_html( $preset['label'] ) . '</a> ';
        }
        echo '</div>';
    }

    /**
     * Count rows matching filters (uses summary aggregation).
     *
     * @param array $filters Active filters.
     * @return int Total matching gifts.
     */
    private function count_report_rows( $filters = array() ) {
        $summary = $this->get_summary( $filters );
        return (int) $summary['total_gifts'];
    }

    /**
     * Generate the gift report data
     */
    public function generate_report( $limit = 1000, $offset = 0, $filters = array(), $sort_clause = null ) {
        global $wpdb;

        $where_conditions = $this->build_where_conditions( $filters );
        $where_clause     = implode( ' AND ', $where_conditions );
        
        // Build LIMIT clause safely using $wpdb->prepare()
        $limit_clause = '';
        if ($limit > 0) {
            $limit_clause = $wpdb->prepare(' LIMIT %d OFFSET %d', $limit, $offset);
        }
        
        // FIXED: Use a more precise approach to find only gift purchase transactions
        // This ensures we only count the original gift purchases, not the claim transactions
        // All user input is properly escaped via $wpdb->prepare() in WHERE conditions and LIMIT clause
        $query = "
        SELECT 
            gifter_txn.id AS gift_transaction_id,
            gifter_txn.created_at AS gift_purchase_date,
            gifter_txn.trans_num AS gift_transaction_number,
            gifter_txn.amount AS gift_amount,
            gifter_txn.total AS gift_total,
            gifter_txn.status AS transaction_status,
            
            gifter.ID AS gifter_user_id,
            gifter.user_login AS gifter_username,
            gifter.user_email AS gifter_email,
            COALESCE(gifter_fname.meta_value, '') AS gifter_first_name,
            COALESCE(gifter_lname.meta_value, '') AS gifter_last_name,
            
            gift_product.ID AS product_id,
            gift_product.post_title AS product_name,
            
            coupon_meta.meta_value AS coupon_id,
            gift_coupon.post_title AS coupon_code,
            
            CASE
                WHEN " . self::SQL_IS_REFUNDED . " THEN '" . self::STATUS_REFUNDED . "'
                ELSE COALESCE(gift_status.meta_value, 'unclaimed')
            END AS gift_status,

            redemption_txn.id AS redemption_transaction_id,
            redemption_txn.created_at AS redemption_date,
            redemption_txn.trans_num AS redemption_transaction_number,
            
            recipient.ID AS recipient_user_id,
            recipient.user_login AS recipient_username,
            recipient.user_email AS recipient_email,
            recipient_fname.meta_value AS recipient_first_name,
            recipient_lname.meta_value AS recipient_last_name,
            
            CASE
                WHEN gifter.ID IS NULL THEN 'deleted'
                ELSE 'active'
            END AS gifter_status,

            COALESCE(reminder_count_meta.meta_value, 0) AS reminders_sent,
            reminder_ts_meta.meta_value AS last_reminder_ts,
            reminder_log_meta.meta_value AS reminder_log_raw

        FROM 
            {$wpdb->prefix}mepr_transactions AS gifter_txn
            " . $this->get_report_joins_sql() . "
        WHERE 
            " . $where_clause . "
        " . ( null !== $sort_clause ? $sort_clause : $this->get_default_sort_clause() ) . $limit_clause . "
        ";
        
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Dynamic query with properly prepared WHERE conditions and LIMIT clause. All user inputs are sanitized and prepared via $wpdb->prepare() before being added to $where_conditions array and $limit_clause. This is a false positive - the query is safe because all dynamic values are properly escaped.
        $this->report_data = $this->localize_rows( $wpdb->get_results($query, ARRAY_A) );

        return $this->report_data;
    }

    /**
     * Attach translated labels to raw query rows.
     *
     * The query used to bake 'Deleted User', 'Deleted Coupon' and the status
     * labels in as SQL literals, so the admin table rendered them in English
     * whatever the site locale -- the CSV export translated them on the way
     * out, the table did not. The query now returns NULL for a missing user or
     * coupon and a machine-readable status, and the labels are applied here, in
     * one place, on every path (table, CSV, REST).
     *
     * The *_deleted flags let callers style a missing record without comparing
     * against a translated string.
     *
     * @param array $rows Raw report rows.
     * @return array Rows with display values filled in.
     */
    private function localize_rows( $rows ) {
        if ( empty( $rows ) || ! is_array( $rows ) ) {
            return array();
        }

        foreach ( $rows as $index => $row ) {
            $status = isset( $row['gift_status'] ) ? $row['gift_status'] : '';

            $gifter_missing = ! isset( $row['gifter_email'] ) || '' === $row['gifter_email'];
            $coupon_missing = ! isset( $row['coupon_code'] ) || '' === $row['coupon_code'];

            // A recipient only exists once a gift is claimed; an empty one on an
            // unclaimed row is normal, not a deleted user.
            $recipient_missing = ( 'claimed' === $status )
                && ( ! isset( $row['recipient_email'] ) || '' === $row['recipient_email'] );

            $rows[ $index ]['gifter_deleted']    = $gifter_missing;
            $rows[ $index ]['coupon_deleted']    = $coupon_missing;
            $rows[ $index ]['recipient_deleted'] = $recipient_missing;

            if ( $gifter_missing ) {
                $rows[ $index ]['gifter_email'] = __( 'Deleted User', 'memberpress-gift-reporter' );
            }

            if ( $coupon_missing ) {
                $rows[ $index ]['coupon_code'] = __( 'Deleted Coupon', 'memberpress-gift-reporter' );
            }

            if ( $recipient_missing ) {
                $rows[ $index ]['recipient_email'] = __( 'Deleted User', 'memberpress-gift-reporter' );
            }

            $rows[ $index ]['gift_status_display'] = self::gift_status_label( $status );

            if ( isset( $row['gifter_status'] ) ) {
                $rows[ $index ]['gifter_status'] = ( 'deleted' === $row['gifter_status'] )
                    ? __( 'Deleted', 'memberpress-gift-reporter' )
                    : __( 'Active', 'memberpress-gift-reporter' );
            }

            // Decode the reminder trail once here so the table, CSV and REST
            // all see structured entries rather than a JSON blob.
            $log = array();
            if ( ! empty( $row['reminder_log_raw'] ) ) {
                $decoded = json_decode( (string) $row['reminder_log_raw'], true );
                $log     = is_array( $decoded ) ? $decoded : array();
            }

            $failures = 0;
            foreach ( $log as $entry ) {
                if ( isset( $entry['result'] ) && 'failed' === $entry['result'] ) {
                    ++$failures;
                }
            }

            unset( $rows[ $index ]['reminder_log_raw'] );

            $rows[ $index ]['reminder_log']       = $log;
            $rows[ $index ]['reminder_failures']  = $failures;
            $rows[ $index ]['reminder_last_failed'] = ! empty( $log )
                && isset( $log[ count( $log ) - 1 ]['result'] )
                && 'failed' === $log[ count( $log ) - 1 ]['result'];
        }

        return $rows;
    }

    /**
     * One-line summary of a gift's reminder history, for a tooltip.
     *
     * @param array $log Decoded reminder log entries.
     * @return string
     */
    private function format_reminder_log( $log ) {
        if ( empty( $log ) ) {
            return __( 'No reminders sent yet.', 'memberpress-gift-reporter' );
        }

        $lines = array();

        // Most recent first: that is what someone chasing a support question wants.
        foreach ( array_reverse( $log ) as $entry ) {
            $when = isset( $entry['ts'] )
                ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $entry['ts'] )
                : '';

            $trigger = isset( $entry['trigger'] ) ? $entry['trigger'] : '';
            if ( 0 === strpos( $trigger, 'schedule:' ) ) {
                /* translators: %d: reminder schedule number, starting at 1 */
                $trigger = sprintf( __( 'automatic #%d', 'memberpress-gift-reporter' ), (int) substr( $trigger, 9 ) + 1 );
            } elseif ( 'manual' === $trigger ) {
                $trigger = __( 'manual', 'memberpress-gift-reporter' );
            } elseif ( 'bulk' === $trigger ) {
                $trigger = __( 'bulk', 'memberpress-gift-reporter' );
            }

            if ( isset( $entry['result'] ) && 'failed' === $entry['result'] ) {
                $outcome = isset( $entry['reason'] ) && '' !== $entry['reason']
                    /* translators: %s: machine-readable failure reason */
                    ? sprintf( __( 'FAILED (%s)', 'memberpress-gift-reporter' ), $entry['reason'] )
                    : __( 'FAILED', 'memberpress-gift-reporter' );
            } else {
                $outcome = __( 'sent', 'memberpress-gift-reporter' );
            }

            $lines[] = sprintf( '%s — %s — %s', $when, $trigger, $outcome );
        }

        return implode( "\n", $lines );
    }

    /**
     * Translated label for a machine-readable gift status.
     *
     * @param string $status One of 'claimed', 'unclaimed', 'refunded'.
     * @return string
     */
    public static function gift_status_label( $status ) {
        switch ( $status ) {
            case 'claimed':
                return __( 'Claimed', 'memberpress-gift-reporter' );

            case 'unclaimed':
                return __( 'Unclaimed', 'memberpress-gift-reporter' );

            case self::STATUS_REFUNDED:
                return __( 'Invalid (Refunded)', 'memberpress-gift-reporter' );

            default:
                return __( 'Unknown', 'memberpress-gift-reporter' );
        }
    }
    
    /**
     * Neutralize CSV/formula injection by prefixing values that begin with formula triggers.
     *
     * @param mixed $value Cell value.
     * @return string Safe cell value.
     */
    private function csv_sanitize_cell( $value ) {
        if ( ! is_scalar( $value ) ) {
            return '';
        }
        $value = (string) $value;
        if ( $value === '' ) {
            return $value;
        }
        $first = $value[0];
        if ( in_array( $first, array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
            return "'" . $value;
        }
        return $value;
    }

    /**
     * Export report to CSV with streaming for large datasets
     */
    public function export_csv($filename = 'memberpress_gift_report.csv', $filters = array()) {
        global $wpdb;
        
        // Sanitize filename to prevent directory traversal and ensure it's a CSV
        $filename = sanitize_file_name($filename);
        if (empty($filename) || !preg_match('/\.csv$/i', $filename)) {
            $filename = 'memberpress_gift_report.csv';
        }
        
        // Ensure filename doesn't contain path traversal attempts
        if (strpos($filename, '..') !== false || strpos($filename, '/') !== false || strpos($filename, '\\') !== false) {
            $filename = 'memberpress_gift_report.csv';
        }
        
        // Set headers for CSV download
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        
        // Create output stream
        $output = fopen('php://output', 'w');
        
        // Add BOM for Excel compatibility
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        
        // Get headers from first row
        $headers = array(
            __( 'Gift ID', 'memberpress-gift-reporter' ),
            __( 'Purchase Date', 'memberpress-gift-reporter' ), 
            __( 'Transaction Number', 'memberpress-gift-reporter' ),
            __( 'Amount', 'memberpress-gift-reporter' ),
            __( 'Total', 'memberpress-gift-reporter' ),
            __( 'Transaction Status', 'memberpress-gift-reporter' ),
            __( 'Gifter User ID', 'memberpress-gift-reporter' ),
            __( 'Gifter Username', 'memberpress-gift-reporter' ),
            __( 'Gifter Email', 'memberpress-gift-reporter' ),
            __( 'Gifter First Name', 'memberpress-gift-reporter' ),
            __( 'Gifter Last Name', 'memberpress-gift-reporter' ),
            __( 'Product ID', 'memberpress-gift-reporter' ),
            __( 'Product Name', 'memberpress-gift-reporter' ),
            __( 'Coupon ID', 'memberpress-gift-reporter' ),
            __( 'Coupon Code', 'memberpress-gift-reporter' ),
            __( 'Gift Status', 'memberpress-gift-reporter' ),
            __( 'Redemption Transaction ID', 'memberpress-gift-reporter' ),
            __( 'Redemption Date', 'memberpress-gift-reporter' ),
            __( 'Redemption Transaction Number', 'memberpress-gift-reporter' ),
            __( 'Recipient User ID', 'memberpress-gift-reporter' ),
            __( 'Recipient Username', 'memberpress-gift-reporter' ),
            __( 'Recipient Email', 'memberpress-gift-reporter' ),
            __( 'Recipient First Name', 'memberpress-gift-reporter' ),
            __( 'Recipient Last Name', 'memberpress-gift-reporter' ),
            __( 'Gift Status Display', 'memberpress-gift-reporter' ),
            __( 'Gifter Status', 'memberpress-gift-reporter' ),
            __( 'Reminders Sent', 'memberpress-gift-reporter' ),
            __( 'Last Reminder', 'memberpress-gift-reporter' ),
        );
        
        // Write headers
        $safe_headers = array_map( array( $this, 'csv_sanitize_cell' ), $headers );
        fputcsv( $output, $safe_headers, ',', '"', '\\' );
        
        // Stream data in chunks to avoid memory issues
        $chunk_size = 1000;
        $offset = 0;
        
        do {
            $data = $this->generate_report( $chunk_size, $offset, $filters, $this->get_default_sort_clause() );
            
            if (!empty($data)) {
                foreach ($data as $row) {
                    // Translate status values for CSV export
                    $translated_row = $row;
                    
                    // Format currency amounts for CSV export
                    if (isset($translated_row['gift_amount'])) {
                        $translated_row['gift_amount'] = $this->format_currency($translated_row['gift_amount']);
                    }
                    if (isset($translated_row['gift_total'])) {
                        $translated_row['gift_total'] = $this->format_currency($translated_row['gift_total']);
                    }
                    
                    // Deleted users/coupons and every status label are already
                    // translated by localize_rows(); only the CSV-specific
                    // placeholders are left to apply here.
                    if (isset($translated_row['gift_status']) && $translated_row['gift_status'] !== 'claimed') {
                        $translated_row['recipient_email'] = __( 'N/A', 'memberpress-gift-reporter' );
                        $translated_row['redemption_date'] = __( 'N/A', 'memberpress-gift-reporter' );
                    }

                    // The log is structured; flatten it to one cell so fputcsv()
                    // never receives an array.
                    $translated_row['reminder_log'] = str_replace(
                        "\n",
                        ' | ',
                        $this->format_reminder_log( isset( $translated_row['reminder_log'] ) ? $translated_row['reminder_log'] : array() )
                    );

                    // Internal flags, not CSV columns.
                    unset(
                        $translated_row['gifter_deleted'],
                        $translated_row['coupon_deleted'],
                        $translated_row['recipient_deleted'],
                        $translated_row['reminder_last_failed']
                    );

                    $safe_row = array_map( array( $this, 'csv_sanitize_cell' ), $translated_row );
                    fputcsv( $output, $safe_row, ',', '"', '\\' );
                }
            }
            
            $offset += $chunk_size;
        } while (count($data) === $chunk_size);
        
        // Close output stream - no need for WP_Filesystem for php://output
        exit;
    }
    
    /**
     * Get summary statistics
     *
     * Refunded purchases are counted in 'total_gifts' and broken out as
     * 'refunded_gifts', but are excluded from the claimed/unclaimed counts, the
     * revenue totals, and the claim rate. 'total_gifts' therefore equals
     * claimed + unclaimed + refunded, while 'claim_rate' is measured against
     * non-refunded gifts only.
     */
    public function get_summary($filters = array()) {
        global $wpdb;

        $cache_key = self::summary_cache_key( $filters );
        $cached    = get_transient( $cache_key );

        // A summary is always an array with total_gifts; anything else is a
        // value cached by an older version with a different shape.
        if ( is_array( $cached ) && isset( $cached['total_gifts'] ) ) {
            return $cached;
        }

        $where_conditions = $this->build_where_conditions( $filters );
        $where_clause     = implode( ' AND ', $where_conditions );

        $is_refunded  = self::SQL_IS_REFUNDED;
        $not_refunded = 'NOT ( ' . self::SQL_IS_REFUNDED . ' )';
        $is_claimed   = "COALESCE(gift_status.meta_value, 'unclaimed') = 'claimed'";

        $summary_query = "
        SELECT
            COUNT(DISTINCT gifter_txn.id) AS total_gifts,
            SUM(CASE WHEN {$not_refunded} AND {$is_claimed} THEN 1 ELSE 0 END) AS claimed_gifts,
            SUM(CASE WHEN {$not_refunded} AND NOT ( {$is_claimed} ) THEN 1 ELSE 0 END) AS unclaimed_gifts,
            SUM(CASE WHEN {$is_refunded} THEN 1 ELSE 0 END) AS refunded_gifts,
            SUM(CASE WHEN {$not_refunded} THEN gifter_txn.total ELSE 0 END) AS total_revenue,
            SUM(CASE WHEN {$not_refunded} AND {$is_claimed} THEN gifter_txn.total ELSE 0 END) AS claimed_revenue,
            SUM(CASE WHEN {$is_refunded} THEN gifter_txn.total ELSE 0 END) AS refunded_revenue,
            AVG(
                CASE
                    WHEN {$not_refunded}
                     AND {$is_claimed}
                     AND redemption_txn.created_at IS NOT NULL
                     AND redemption_txn.created_at >= gifter_txn.created_at
                    THEN TIMESTAMPDIFF(HOUR, gifter_txn.created_at, redemption_txn.created_at)
                END
            ) AS avg_hours_to_claim
        FROM {$wpdb->prefix}mepr_transactions AS gifter_txn
        " . $this->get_report_joins_sql() . "
        WHERE {$where_clause}
        ";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- WHERE values prepared in build_where_conditions()
        $row = $wpdb->get_row( $summary_query, ARRAY_A );

        $total     = isset( $row['total_gifts'] ) ? (int) $row['total_gifts'] : 0;
        $claimed   = isset( $row['claimed_gifts'] ) ? (int) $row['claimed_gifts'] : 0;
        $unclaimed = isset( $row['unclaimed_gifts'] ) ? (int) $row['unclaimed_gifts'] : 0;
        $refunded  = isset( $row['refunded_gifts'] ) ? (int) $row['refunded_gifts'] : 0;
        $revenue          = isset( $row['total_revenue'] ) ? (float) $row['total_revenue'] : 0;
        $claimed_revenue  = isset( $row['claimed_revenue'] ) ? (float) $row['claimed_revenue'] : 0;
        $refunded_revenue = isset( $row['refunded_revenue'] ) ? (float) $row['refunded_revenue'] : 0;

        // Claim rate measures how many *valid* gifts got claimed; a refunded
        // purchase was never claimable, so counting it would understate the rate.
        $countable = max( 0, $total - $refunded );

        // NULL when nothing in range has been claimed yet -- distinct from a
        // genuine average of zero, so it is kept nullable rather than cast to 0.
        $avg_hours = isset( $row['avg_hours_to_claim'] ) && null !== $row['avg_hours_to_claim']
            ? (float) $row['avg_hours_to_claim']
            : null;

        $summary = array(
            'total_gifts'                  => $total,
            'claimed_gifts'                => $claimed,
            'unclaimed_gifts'              => $unclaimed,
            'refunded_gifts'               => $refunded,
            'claim_rate'                   => $countable > 0 ? round( ( $claimed / $countable ) * 100, 2 ) : 0,
            'total_revenue'                => $revenue,
            'total_revenue_formatted'      => $this->format_currency( $revenue ),
            'claimed_revenue'              => $claimed_revenue,
            'claimed_revenue_formatted'    => $this->format_currency( $claimed_revenue ),
            'refunded_revenue'             => $refunded_revenue,
            'refunded_revenue_formatted'   => $this->format_currency( $refunded_revenue ),
            'avg_hours_to_claim'           => $avg_hours,
            'avg_days_to_claim'            => null === $avg_hours ? null : round( $avg_hours / 24, 1 ),
            'avg_time_to_claim_formatted'  => self::format_duration( $avg_hours ),
        );

        set_transient( $cache_key, $summary, self::SUMMARY_CACHE_TTL );

        return $summary;
    }

    /**
     * Transient key for a filter set.
     *
     * @param array $filters Active filters.
     * @return string
     */
    private static function summary_cache_key( $filters ) {
        $filters = is_array( $filters ) ? $filters : array();

        // Sort so the same filters in a different order share one entry.
        ksort( $filters );

        return 'mpgr_summary_' . md5( self::summary_cache_version() . '|' . wp_json_encode( $filters ) );
    }

    /**
     * Current summary cache generation.
     *
     * @return int
     */
    private static function summary_cache_version() {
        return (int) get_option( self::SUMMARY_VERSION_OPTION, 0 );
    }

    /**
     * Invalidate every cached summary.
     *
     * Hooked to the gifting add-on's purchase and claim actions, so the report
     * reflects a new gift immediately rather than up to the TTL later.
     */
    public static function invalidate_summary_cache() {
        update_option( self::SUMMARY_VERSION_OPTION, self::summary_cache_version() + 1, false );
    }

    /**
     * Human-readable duration for the time-to-claim stat.
     *
     * Sub-day averages are reported in hours: a site whose gifts are claimed
     * within a few hours is badly served by "0.2 days", and this number exists
     * to make reminder delays tunable.
     *
     * @param float|null $hours Average hours, or null when nothing is claimed.
     * @return string Empty string when there is nothing to report.
     */
    public static function format_duration( $hours ) {
        if ( null === $hours ) {
            return '';
        }

        if ( $hours < 24 ) {
            $rounded = max( 0, (int) round( $hours ) );

            /* translators: %s: number of hours */
            return sprintf( _n( '%s hour', '%s hours', $rounded, 'memberpress-gift-reporter' ), number_format_i18n( $rounded ) );
        }

        $days = round( $hours / 24, 1 );

        // Whole numbers read better without a trailing ".0".
        $decimals  = ( (float) (int) $days === $days ) ? 0 : 1;
        $formatted = number_format_i18n( $days, $decimals );

        /* translators: %s: number of days */
        return sprintf( _n( '%s day', '%s days', (int) ceil( $days ), 'memberpress-gift-reporter' ), $formatted );
    }

    /**
     * Unclaimed gift aging buckets for the stuck-gifts strip.
     *
     * @return array<string, array{label:string,count:int,revenue:float,revenue_formatted:string,filter_url:string,bulk_remind_url:string}>
     */
    public function get_unclaimed_aging_arcs() {
        $transient_key = class_exists( 'MPGR_Onboarding' ) ? MPGR_Onboarding::AGING_TRANSIENT : 'mpgr_aging_arcs';
        $cached = get_transient( $transient_key );
        if ( is_array( $cached ) ) {
            $sample = reset( $cached );
            if ( is_array( $sample ) && isset( $sample['bulk_remind_url'] ) ) {
                return $cached;
            }
            delete_transient( $transient_key );
        }

        $windows = array(
            '7-14'  => array(
                'label'     => __( '7–14 days', 'memberpress-gift-reporter' ),
                'date_from' => gmdate( 'Y-m-d', strtotime( '-14 days' ) ),
                'date_to'   => gmdate( 'Y-m-d', strtotime( '-7 days' ) ),
            ),
            '14-30' => array(
                'label'     => __( '14–30 days', 'memberpress-gift-reporter' ),
                'date_from' => gmdate( 'Y-m-d', strtotime( '-30 days' ) ),
                'date_to'   => gmdate( 'Y-m-d', strtotime( '-14 days' ) ),
            ),
            '30plus' => array(
                'label'   => __( '30+ days', 'memberpress-gift-reporter' ),
                'date_to' => gmdate( 'Y-m-d', strtotime( '-30 days' ) ),
            ),
        );

        $arcs = array();

        foreach ( $windows as $key => $window ) {
            $filters = array( 'gift_status' => 'unclaimed' );
            if ( ! empty( $window['date_from'] ) ) {
                $filters['date_from'] = $window['date_from'];
            }
            if ( ! empty( $window['date_to'] ) ) {
                $filters['date_to'] = $window['date_to'];
            }

            $summary = $this->get_summary( $filters );

            $arcs[ $key ] = array(
                'label'              => $window['label'],
                'count'              => isset( $summary['total_gifts'] ) ? (int) $summary['total_gifts'] : 0,
                'revenue'            => isset( $summary['total_revenue'] ) ? (float) $summary['total_revenue'] : 0.0,
                'revenue_formatted'  => isset( $summary['total_revenue_formatted'] ) ? $summary['total_revenue_formatted'] : '',
                'filter_url'         => $this->get_report_url( $filters ),
                'bulk_remind_url'    => $this->get_report_url( $filters, array( 'mpgr_bulk_remind' => '1' ) ),
            );
        }

        set_transient( $transient_key, $arcs, 300 );

        return $arcs;
    }
    
    /**
     * Format currency using MemberPress settings
     * 
     * @param float $amount The amount to format
     * @param bool $show_symbol Whether to show currency symbol
     * @return string Formatted currency string
     */
    public function format_currency($amount, $show_symbol = true) {
        // Use MemberPress's currency formatting function
        if (class_exists('MeprAppHelper')) {
            return MeprAppHelper::format_currency($amount, $show_symbol);
        }

        // Fallback if MemberPress helper is not available
        if ( ! class_exists( 'MeprOptions' ) ) {
            return '$' . number_format( (float) $amount, 2 );
        }

        $mepr_options = MeprOptions::fetch();
        $symbol = $mepr_options->currency_symbol;
        $symbol_after = $mepr_options->currency_symbol_after;

        // Format the number
        if ( class_exists( 'MeprUtils' ) && MeprUtils::is_zero_decimal_currency() ) {
            $formatted_amount = number_format($amount, 0);
        } else {
            $formatted_amount = number_format($amount, 2);
        }
        
        // Add currency symbol
        if ($show_symbol) {
            if ($symbol_after) {
                return $formatted_amount . $symbol;
            } else {
                return $symbol . $formatted_amount;
            }
        }
        
        return $formatted_amount;
    }
    
    /**
     * Get all available products for filtering
     */
    private function get_available_products() {
        if ( null !== $this->products_cache ) {
            return $this->products_cache;
        }

        global $wpdb;

        $query = "
        SELECT DISTINCT 
            p.ID,
            p.post_title
        FROM 
            {$wpdb->posts} AS p
            INNER JOIN {$wpdb->prefix}mepr_transactions AS t ON p.ID = t.product_id
            INNER JOIN {$wpdb->prefix}mepr_transaction_meta AS tm ON t.id = tm.transaction_id
        WHERE 
            p.post_type = 'memberpressproduct'
            AND p.post_status = 'publish'
            AND t.amount > 0
            AND tm.meta_key IN ('_gift_status', '_gift_coupon_id')
        ORDER BY 
            p.post_title ASC
        ";
        
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $this->products_cache = $wpdb->get_results( $query, ARRAY_A );

        return $this->products_cache;
    }
    
    /**
     * Display the report
     */
    public function display_report($filters = array()) {
        $per_page     = 50;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $current_page = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
        $offset       = ( $current_page - 1 ) * $per_page;
        $sort_clause  = $this->get_sort_clause();
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $current_orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'gift_purchase_date';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $current_order   = ( isset( $_GET['order'] ) && 'ASC' === strtoupper( sanitize_text_field( wp_unslash( $_GET['order'] ) ) ) ) ? 'ASC' : 'DESC';

        $total_rows  = $this->count_report_rows( $filters );
        $total_pages = $total_rows > 0 ? (int) ceil( $total_rows / $per_page ) : 1;

        $this->generate_report( $per_page, $offset, $filters, $sort_clause );
        $summary = $this->get_summary( $filters );
        $all_time_summary = $this->get_summary( array() );
        $prior_snapshot   = class_exists( 'MPGR_Onboarding' )
            ? get_option( MPGR_Onboarding::SNAPSHOT_OPTION, array() )
            : array();
        
        // Styles are enqueued via admin_enqueue_scripts hook in class-admin.php
        // Note: Inline styles in email templates (get_fallback_email_template, get_email_header, etc.)
        // are intentional and correct - email clients require inline styles for proper rendering
        
        		echo '<div class="mpgr-gift-report">';
		echo '<h2>🎁 ' . esc_html__( 'MemberPress Gift Report', 'memberpress-gift-reporter' ) . '</h2>';

		if ( class_exists( 'MPGR_Onboarding' ) ) {
			MPGR_Onboarding::render_welcome_banner();
			MPGR_Onboarding::render_monday_pulse();
			MPGR_Onboarding::render_stuck_gifts_arcs();
		}
		
		$this->render_filter_presets();

		// Filter form
		echo '<div class="mpgr-filters">';
		echo '<h3>🔍 ' . esc_html__( 'Filters', 'memberpress-gift-reporter' ) . '</h3>';
        
        // Show active filters
        $active_filters = array();
        if (!empty($filters['date_from'])) {
			$active_filters[] = esc_html__( 'Date From:', 'memberpress-gift-reporter' ) . ' ' . esc_html($filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
			$active_filters[] = esc_html__( 'Date To:', 'memberpress-gift-reporter' ) . ' ' . esc_html($filters['date_to']);
        }
        if (!empty($filters['gift_status'])) {
            $status_display = ucfirst($filters['gift_status']);
			$active_filters[] = esc_html__( 'Gift Status:', 'memberpress-gift-reporter' ) . ' ' . esc_html($status_display);
        }
        if (!empty($filters['product'])) {
            $products = $this->get_available_products();
            $product_name = __( 'Unknown Product', 'memberpress-gift-reporter' );
            foreach ($products as $product) {
                if ($product['ID'] == $filters['product']) {
                    $product_name = $product['post_title'];
                    break;
                }
            }
			$active_filters[] = esc_html__( 'Membership:', 'memberpress-gift-reporter' ) . ' ' . esc_html($product_name);
        }
        if (!empty($filters['gifter_email'])) {
			$active_filters[] = esc_html__( 'Gifter Email:', 'memberpress-gift-reporter' ) . ' ' . esc_html($filters['gifter_email']);
        }
        if (!empty($filters['recipient_email'])) {
			$active_filters[] = esc_html__( 'Recipient Email:', 'memberpress-gift-reporter' ) . ' ' . esc_html($filters['recipient_email']);
        }
        if (!empty($filters['coupon_code'])) {
			$active_filters[] = esc_html__( 'Coupon Code:', 'memberpress-gift-reporter' ) . ' ' . esc_html($filters['coupon_code']);
        }
        if (!empty($filters['transaction_id'])) {
			$active_filters[] = esc_html__( 'Transaction ID:', 'memberpress-gift-reporter' ) . ' ' . esc_html($filters['transaction_id']);
        }
        if (!empty($filters['claim_transaction_id'])) {
			$active_filters[] = esc_html__( 'Claim Transaction ID:', 'memberpress-gift-reporter' ) . ' ' . esc_html($filters['claim_transaction_id']);
        }
        if (!empty($filters['redemption_from'])) {
			$active_filters[] = esc_html__( 'Redemption From:', 'memberpress-gift-reporter' ) . ' ' . esc_html($filters['redemption_from']);
        }
        if (!empty($filters['redemption_to'])) {
			$active_filters[] = esc_html__( 'Redemption To:', 'memberpress-gift-reporter' ) . ' ' . esc_html($filters['redemption_to']);
        }
        
        		if (!empty($active_filters)) {
			echo '<div class="mpgr-active-filters">';
			echo '<strong>' . esc_html__( 'Active Filters:', 'memberpress-gift-reporter' ) . '</strong> ';
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Each filter fragment is escaped when built.
			echo implode( ', ', $active_filters );
			echo '</div>';
		}
        

        
        echo '<form method="GET" action="">';
        echo '<input type="hidden" name="page" value="memberpress-gift-report">';
		echo '<input type="hidden" name="_wpnonce" value="' . esc_attr(wp_create_nonce('mpgr_filter_nonce')) . '">';
        
        echo '<div class="mpgr-filter-grid">';
        
        		// Date From filter
		echo '<div class="mpgr-filter-group">';
		echo '<label for="date_from">' . esc_html__( 'Date From', 'memberpress-gift-reporter' ) . '</label>';
		echo '<input type="date" id="date_from" name="date_from" value="' . esc_attr($filters['date_from'] ?? '') . '">';
		echo '</div>';
		
		// Date To filter
		echo '<div class="mpgr-filter-group">';
		echo '<label for="date_to">' . esc_html__( 'Date To', 'memberpress-gift-reporter' ) . '</label>';
		echo '<input type="date" id="date_to" name="date_to" value="' . esc_attr($filters['date_to'] ?? '') . '">';
		echo '</div>';
        
        		// Gift Status filter
		echo '<div class="mpgr-filter-group">';
		echo '<label for="gift_status">' . esc_html__( 'Gift Status', 'memberpress-gift-reporter' ) . '</label>';
		echo '<select id="gift_status" name="gift_status">';
		echo '<option value="">' . esc_html__( 'All Statuses', 'memberpress-gift-reporter' ) . '</option>';
		echo '<option value="claimed"' . selected($filters['gift_status'] ?? '', 'claimed', false) . '>' . esc_html__( 'Claimed', 'memberpress-gift-reporter' ) . '</option>';
		echo '<option value="unclaimed"' . selected($filters['gift_status'] ?? '', 'unclaimed', false) . '>' . esc_html__( 'Unclaimed', 'memberpress-gift-reporter' ) . '</option>';
		echo '<option value="refunded"' . selected($filters['gift_status'] ?? '', 'refunded', false) . '>' . esc_html__( 'Refunded', 'memberpress-gift-reporter' ) . '</option>';
		echo '</select>';
		echo '</div>';
        
        		// Product/Membership filter
		echo '<div class="mpgr-filter-group">';
		echo '<label for="product">' . esc_html__( 'Membership', 'memberpress-gift-reporter' ) . '</label>';
		echo '<select id="product" name="product">';
		echo '<option value="">' . esc_html__( 'All Memberships', 'memberpress-gift-reporter' ) . '</option>';
        
        $products = $this->get_available_products();
        foreach ($products as $product) {
            $selected = selected($filters['product'] ?? '', $product['ID'], false);
            echo '<option value="' . esc_attr($product['ID']) . '"' . esc_attr($selected) . '>' . esc_html($product['post_title']) . '</option>';
        }
        echo '</select>';
        echo '</div>';
        
        		// Gifter Email filter
		echo '<div class="mpgr-filter-group">';
		echo '<label for="gifter_email">' . esc_html__( 'Gifter Email', 'memberpress-gift-reporter' ) . '</label>';
		echo '<input type="email" id="gifter_email" name="gifter_email" value="' . esc_attr($filters['gifter_email'] ?? '') . '" placeholder="' . esc_attr__( 'Enter gifter email', 'memberpress-gift-reporter' ) . '">';
		echo '</div>';
		
        		// Recipient Email filter
		echo '<div class="mpgr-filter-group">';
		echo '<label for="recipient_email">' . esc_html__( 'Recipient Email', 'memberpress-gift-reporter' ) . '</label>';
		echo '<input type="email" id="recipient_email" name="recipient_email" value="' . esc_attr($filters['recipient_email'] ?? '') . '" placeholder="' . esc_attr__( 'Enter recipient email', 'memberpress-gift-reporter' ) . '">';
		echo '</div>';
        
		// Coupon Code filter
		echo '<div class="mpgr-filter-group">';
		echo '<label for="coupon_code">' . esc_html__( 'Coupon Code', 'memberpress-gift-reporter' ) . '</label>';
		echo '<input type="text" id="coupon_code" name="coupon_code" value="' . esc_attr($filters['coupon_code'] ?? '') . '" placeholder="' . esc_attr__( 'e.g. GIFT-A1B2', 'memberpress-gift-reporter' ) . '">';
		echo '</div>';

		// Transaction ID filter
		echo '<div class="mpgr-filter-group">';
		echo '<label for="transaction_id">' . esc_html__( 'Transaction ID', 'memberpress-gift-reporter' ) . '</label>';
		echo '<input type="text" id="transaction_id" name="transaction_id" value="' . esc_attr($filters['transaction_id'] ?? '') . '" placeholder="' . esc_attr__( 'Enter transaction ID', 'memberpress-gift-reporter' ) . '">';
		echo '</div>';
		
		// Claim Transaction ID filter
		echo '<div class="mpgr-filter-group">';
		echo '<label for="claim_transaction_id">' . esc_html__( 'Claim Transaction ID', 'memberpress-gift-reporter' ) . '</label>';
		echo '<input type="text" id="claim_transaction_id" name="claim_transaction_id" value="' . esc_attr($filters['claim_transaction_id'] ?? '') . '" placeholder="' . esc_attr__( 'Enter claim transaction ID', 'memberpress-gift-reporter' ) . '">';
		echo '</div>';

        
        		// Redemption From filter
		echo '<div class="mpgr-filter-group">';
		echo '<label for="redemption_from">' . esc_html__( 'Redemption From', 'memberpress-gift-reporter' ) . '</label>';
		echo '<input type="date" id="redemption_from" name="redemption_from" value="' . esc_attr($filters['redemption_from'] ?? '') . '">';
		echo '</div>';
		
		// Redemption To filter
		echo '<div class="mpgr-filter-group">';
		echo '<label for="redemption_to">' . esc_html__( 'Redemption To', 'memberpress-gift-reporter' ) . '</label>';
		echo '<input type="date" id="redemption_to" name="redemption_to" value="' . esc_attr($filters['redemption_to'] ?? '') . '">';
		echo '</div>';
        
        echo '</div>';
        
        		echo '<div class="mpgr-filter-actions">';
		echo '<button type="submit" class="button button-primary">' . esc_html__( 'Apply Filters', 'memberpress-gift-reporter' ) . '</button>';
		echo '<a href="' . esc_url(admin_url('admin.php?page=memberpress-gift-report')) . '" class="button">' . esc_html__( 'Clear Filters', 'memberpress-gift-reporter' ) . '</a>';
		echo '</div>';
        echo '</form>';
        echo '</div>';
        
        if ( class_exists( 'MPGR_Onboarding' ) ) {
            MPGR_Onboarding::render_recovery_reel( $all_time_summary, $prior_snapshot );
        }

        echo '<div class="mpgr-summary">';
        
        // Determine if filters are applied
        $has_filters = !empty($filters['date_from']) || 
                      !empty($filters['date_to']) || 
                      !empty($filters['gift_status']) || 
                      !empty($filters['product']) || 
                      !empty($filters['gifter_email']) || 
                      !empty($filters['recipient_email']) || 
                      !empty($filters['transaction_id']) || 
                      !empty($filters['claim_transaction_id']) || 
                      !empty($filters['redemption_from']) || 
                      !empty($filters['redemption_to']);
        
        		if ($has_filters) {
			echo '<h3>📊 ' . esc_html__( 'Summary (Filtered)', 'memberpress-gift-reporter' ) . '</h3>';
		} else {
			echo '<h3>📊 ' . esc_html__( 'All-time Summary', 'memberpress-gift-reporter' ) . '</h3>';
		}
		echo '<div class="mpgr-summary-row">';
		echo '<span class="mpgr-summary-item"><strong>' . esc_html__( 'Total Gifts:', 'memberpress-gift-reporter' ) . '</strong> ' . esc_html($summary['total_gifts']) . '</span>';
		echo '<span class="mpgr-summary-item"><strong>' . esc_html__( 'Claimed:', 'memberpress-gift-reporter' ) . '</strong> ' . esc_html($summary['claimed_gifts']) . '</span>';
		echo '<span class="mpgr-summary-item"><strong>' . esc_html__( 'Unclaimed:', 'memberpress-gift-reporter' ) . '</strong> ' . esc_html($summary['unclaimed_gifts']) . '</span>';
		// Only shown when there are refunds, so the counts still add up to the
		// total without adding a permanent zero to every site's summary.
		if ( ! empty( $summary['refunded_gifts'] ) ) {
			echo '<span class="mpgr-summary-item"><strong>' . esc_html__( 'Refunded:', 'memberpress-gift-reporter' ) . '</strong> ' . esc_html($summary['refunded_gifts']) . '</span>';
		}
		echo '<span class="mpgr-summary-item"><strong>' . esc_html__( 'Claim Rate:', 'memberpress-gift-reporter' ) . '</strong> ' . esc_html($summary['claim_rate']) . '%</span>';
		// Only meaningful once something has been claimed in the filtered range.
		if ( ! empty( $summary['avg_time_to_claim_formatted'] ) ) {
			echo '<span class="mpgr-summary-item" title="' . esc_attr__( 'Average time between purchase and redemption. Use it to tune your reminder delays.', 'memberpress-gift-reporter' ) . '"><strong>' . esc_html__( 'Avg. Time to Claim:', 'memberpress-gift-reporter' ) . '</strong> ' . esc_html($summary['avg_time_to_claim_formatted']) . '</span>';
		}
		echo '</div>';
        echo '</div>';

		if ( $total_rows > 0 ) {
			echo '<p class="mpgr-result-count">';
			printf(
				/* translators: 1: first row number, 2: last row number, 3: total rows */
				esc_html__( 'Showing %1$d–%2$d of %3$d gifts', 'memberpress-gift-reporter' ),
				min( $offset + 1, $total_rows ),
				min( $offset + $per_page, $total_rows ),
				$total_rows
			);
			echo '</p>';
		}
        
        		// Export button
		echo '<a href="#" class="mpgr-export-btn">&#128229; ' . esc_html__( 'Download CSV Report', 'memberpress-gift-reporter' ) . '</a>';
        
        if (!empty($this->report_data)) {
            // Count unclaimed gifts for bulk action
            $unclaimed_count = 0;
            foreach ($this->report_data as $row) {
                // Check if gift is unclaimed (status is 'unclaimed' or empty/defaults to unclaimed)
                $is_unclaimed = ($row['gift_status'] === 'unclaimed' || empty($row['gift_status']));
                if ($is_unclaimed) {
                    $unclaimed_count++;
                }
            }
            
            // Bulk action button (only show if there are unclaimed gifts)
            if ($unclaimed_count > 0) {
                echo '<div class="mpgr-bulk-actions">';
                echo '<button type="button" id="mpgr-select-all-unclaimed" class="button">' . esc_html__( 'Select All Unclaimed', 'memberpress-gift-reporter' ) . '</button>';
                echo '<button type="button" id="mpgr-deselect-all" class="button" style="display:none;">' . esc_html__( 'Deselect All', 'memberpress-gift-reporter' ) . '</button>';
                echo '<button type="button" id="mpgr-bulk-send-emails" class="button button-primary" style="display:none;">' . esc_html__( '📧 Send Reminder Emails to Selected', 'memberpress-gift-reporter' ) . '</button>';
                echo '<span id="mpgr-selected-count" class="mpgr-selected-count" style="display:none;"></span>';
                echo '</div>';
            }
            
			echo '<div class="mpgr-table-wrap">';
			echo '<div class="mpgr-table-scroll">';
			echo '<table class="mpgr-table">';
			echo '<thead>';
			echo '<tr>';
			if ($unclaimed_count > 0) {
				echo '<th class="mpgr-checkbox-col"><input type="checkbox" id="mpgr-select-all-header" title="' . esc_attr__( 'Select all unclaimed gifts', 'memberpress-gift-reporter' ) . '"></th>';
			}
			$this->render_sortable_column_header( __( 'Gift ID', 'memberpress-gift-reporter' ), 'gift_transaction_id', $filters, $current_orderby, $current_order );
			$this->render_sortable_column_header( __( 'Transaction ID', 'memberpress-gift-reporter' ), 'gift_transaction_number', $filters, $current_orderby, $current_order );
			$this->render_sortable_column_header( __( 'Purchase Date', 'memberpress-gift-reporter' ), 'gift_purchase_date', $filters, $current_orderby, $current_order );
			echo '<th class="mpgr-col-email">' . esc_html__( 'Gifter Email', 'memberpress-gift-reporter' ) . '</th>';
			$this->render_sortable_column_header( __( 'Product', 'memberpress-gift-reporter' ), 'product_name', $filters, $current_orderby, $current_order );
			echo '<th class="mpgr-col-coupon">' . esc_html__( 'Coupon Code', 'memberpress-gift-reporter' ) . '</th>';
			$this->render_sortable_column_header( __( 'Status', 'memberpress-gift-reporter' ), 'gift_status', $filters, $current_orderby, $current_order );
			echo '<th class="mpgr-col-email">' . esc_html__( 'Recipient Email', 'memberpress-gift-reporter' ) . '</th>';
			echo '<th class="mpgr-col-id">' . esc_html__( 'Claim Transaction ID', 'memberpress-gift-reporter' ) . '</th>';
			$this->render_sortable_column_header( __( 'Redemption Date', 'memberpress-gift-reporter' ), 'redemption_date', $filters, $current_orderby, $current_order );
			$this->render_sortable_column_header( __( 'Amount', 'memberpress-gift-reporter' ), 'gift_total', $filters, $current_orderby, $current_order );
			$this->render_sortable_column_header( __( 'Reminders Sent', 'memberpress-gift-reporter' ), 'reminders_sent', $filters, $current_orderby, $current_order );
			echo '<th class="mpgr-col-actions">' . esc_html__( 'Actions', 'memberpress-gift-reporter' ) . '</th>';
			echo '</tr>';
			echo '</thead>';
            echo '<tbody>';
            
            foreach ($this->report_data as $row) {
                $status_class = '';
                switch ($row['gift_status']) {
                    case 'claimed':
                        $status_class = 'mpgr-claimed';
                        break;
                    case 'unclaimed':
                        $status_class = 'mpgr-unclaimed';
                        break;
                    case self::STATUS_REFUNDED:
                    default:
                        $status_class = 'mpgr-refunded';
                }
                
                // Check if gift is unclaimed (status is 'unclaimed' or empty/defaults to unclaimed)
                $is_unclaimed = ($row['gift_status'] === 'unclaimed' || empty($row['gift_status']));
                
                echo '<tr' . ($is_unclaimed ? ' class="mpgr-unclaimed-row"' : '') . '>';
                
                // Checkbox column (only for unclaimed gifts)
                if ($unclaimed_count > 0) {
                    if ($is_unclaimed) {
                        echo '<td class="mpgr-checkbox-col"><input type="checkbox" class="mpgr-gift-checkbox" value="' . esc_attr($row['gift_transaction_id']) . '" data-gift-id="' . esc_attr($row['gift_transaction_id']) . '"></td>';
                    } else {
                        echo '<td class="mpgr-checkbox-col"></td>';
                    }
                }
                
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- admin_link() returns pre-escaped HTML.
                echo '<td class="mpgr-col-id">' . $this->admin_link( $row['gift_transaction_id'], 'transaction', $row['gift_transaction_id'] ) . '</td>';
                echo '<td class="mpgr-col-id">' . $this->admin_link( $row['gift_transaction_number'], 'transaction', $row['gift_transaction_id'] ) . '</td>';
                echo '<td class="mpgr-col-nowrap">' . esc_html( $row['gift_purchase_date'] ) . '</td>';
                if ( ! empty( $row['gifter_deleted'] ) ) {
                    echo '<td class="mpgr-col-email"><span class="mpgr-deleted-user">' . esc_html( $row['gifter_email'] ) . '</span></td>';
                } else {
                    echo '<td class="mpgr-col-email">' . $this->admin_link( $row['gifter_email'], 'user', $row['gifter_user_id'] ) . '</td>';
                }
                echo '<td class="mpgr-col-product">' . $this->admin_link( $row['product_name'], 'product', $row['product_id'] ) . '</td>';
                if ( ! empty( $row['coupon_deleted'] ) ) {
                    echo '<td class="mpgr-col-coupon"><span class="mpgr-deleted-coupon">' . esc_html( $row['coupon_code'] ) . '</span></td>';
                } else {
                    echo '<td class="mpgr-col-coupon">' . $this->admin_link( $row['coupon_code'], 'coupon', (int) $row['coupon_id'] ) . '</td>';
                }
                // Already translated by localize_rows().
                echo '<td class="mpgr-col-nowrap ' . esc_attr( $status_class ) . '">' . esc_html( $row['gift_status_display'] ) . '</td>';
                if ( $row['gift_status'] === 'claimed' ) {
                    if ( ! empty( $row['recipient_deleted'] ) ) {
                        echo '<td class="mpgr-col-email"><span class="mpgr-deleted-user">' . esc_html( $row['recipient_email'] ) . '</span></td>';
                    } else {
                        echo '<td class="mpgr-col-email">' . $this->admin_link( $row['recipient_email'], 'user', $row['recipient_user_id'] ) . '</td>';
                    }
                    $claim_label = $row['redemption_transaction_number'] ? $row['redemption_transaction_number'] : __( 'N/A', 'memberpress-gift-reporter' );
                    echo '<td class="mpgr-col-id">' . $this->admin_link( $claim_label, 'transaction', (int) $row['redemption_transaction_id'] ) . '</td>';
                    echo '<td class="mpgr-col-nowrap">' . esc_html( $row['redemption_date'] ? $row['redemption_date'] : __( 'N/A', 'memberpress-gift-reporter' ) ) . '</td>';
                } else {
                    echo '<td class="mpgr-col-email">' . esc_html__( 'N/A', 'memberpress-gift-reporter' ) . '</td>';
                    echo '<td class="mpgr-col-id">' . esc_html__( 'N/A', 'memberpress-gift-reporter' ) . '</td>';
                    echo '<td class="mpgr-col-nowrap">' . esc_html__( 'N/A', 'memberpress-gift-reporter' ) . '</td>';
                }
                echo '<td class="mpgr-col-nowrap">' . esc_html( $this->format_currency( $row['gift_total'] ) ) . '</td>';

                $reminders_sent = (int) $row['reminders_sent'];
                $log            = isset( $row['reminder_log'] ) ? $row['reminder_log'] : array();
                $failures       = isset( $row['reminder_failures'] ) ? (int) $row['reminder_failures'] : 0;

                // The full trail answers "did the reminder actually go out?"
                // without leaving the report.
                $tooltip = $this->format_reminder_log( $log );

                $cell = esc_html( $reminders_sent );

                if ( $failures > 0 ) {
                    $cell .= ' <span class="mpgr-reminder-failed" aria-label="' . esc_attr(
                        sprintf(
                            /* translators: %d: number of failed reminder attempts */
                            _n( '%d failed attempt', '%d failed attempts', $failures, 'memberpress-gift-reporter' ),
                            $failures
                        )
                    ) . '">&#9888;</span>';
                }

                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $cell is assembled from escaped parts.
                echo '<td class="mpgr-col-nowrap" title="' . esc_attr( $tooltip ) . '">' . $cell . '</td>';

                // Actions column
                echo '<td class="mpgr-actions mpgr-col-actions">';
                // Show resend email button
                echo '<button class="mpgr-action-btn mpgr-resend-email" data-gift-id="' . esc_attr($row['gift_transaction_id']) . '" title="' . esc_attr__( 'Resend gift email to gifter', 'memberpress-gift-reporter' ) . '">📧</button>';
                // Show copy link button - include redemption link as data attribute for Safari compatibility
                $redemption_link = '';
                if ( empty( $row['coupon_deleted'] ) && ! empty( $row['coupon_code'] ) && ! empty( $row['product_id'] ) ) {
                    $redemption_link = $this->generate_redemption_url( $row['product_id'], $row['coupon_code'] );
                }
                echo '<button class="mpgr-action-btn mpgr-copy-link" data-gift-id="' . esc_attr($row['gift_transaction_id']) . '" data-redemption-link="' . esc_attr( $redemption_link ) . '" title="' . esc_attr__( 'Copy redemption link', 'memberpress-gift-reporter' ) . '">🔗</button>';
                echo '</td>';
                
                echo '</tr>';
            }
            
            echo '</tbody>';
            echo '</table>';
			echo '</div>';
			echo '</div>';

            if ( $total_pages > 1 ) {
                $pagination = paginate_links(
                    array(
                        'base'      => $this->get_report_page_url( $filters, array( 'paged' => '%#%' ) ),
                        'format'    => '',
                        'current'   => $current_page,
                        'total'     => $total_pages,
                        'prev_text' => __( '&laquo; Previous', 'memberpress-gift-reporter' ),
                        'next_text' => __( 'Next &raquo;', 'memberpress-gift-reporter' ),
                        'type'      => 'list',
                    )
                );
                if ( $pagination ) {
                    echo '<div class="mpgr-pagination tablenav"><div class="tablenav-pages">' . wp_kses_post( $pagination ) . '</div></div>';
                }
            }
        } else {
            // Check if there are any gift transactions at all (without filters)
            $all_gifts = $this->generate_report( 1, 0, array(), $this->get_default_sort_clause() );
            
            if (!empty($all_gifts)) {
                // There are gift transactions, but filters are too restrictive
                echo '<div class="mpgr-no-data mpgr-filtered-no-data">';
                echo '<h3>' . esc_html__( 'No Results Match Your Filters', 'memberpress-gift-reporter' ) . '</h3>';
                echo '<p>' . esc_html__( 'We found gift transactions in your database, but none match your current filter criteria. Try:', 'memberpress-gift-reporter' ) . '</p>';
                echo '<ul>';
                echo '<li>' . esc_html__( 'Broadening your date range', 'memberpress-gift-reporter' ) . '</li>';
                echo '<li>' . esc_html__( 'Selecting "All Statuses" instead of a specific status', 'memberpress-gift-reporter' ) . '</li>';
                echo '<li>' . esc_html__( 'Choosing "All Memberships" instead of a specific product', 'memberpress-gift-reporter' ) . '</li>';
                echo '<li>' . esc_html__( 'Clearing email filters if they\'re too specific', 'memberpress-gift-reporter' ) . '</li>';
                echo '<li>' . esc_html__( 'Adjusting redemption date filters', 'memberpress-gift-reporter' ) . '</li>';
                echo '</ul>';
                echo '<div class="mpgr-help-links">';
                echo '<a href="' . esc_url( admin_url( 'admin.php?page=memberpress-gift-report' ) ) . '" class="mpgr-clear-filters-btn">' . esc_html__( 'Clear All Filters', 'memberpress-gift-reporter' ) . '</a>';
                echo '<a href="' . esc_url(admin_url('admin.php?page=memberpress-trans')) . '">' . esc_html__( 'View All Transactions', 'memberpress-gift-reporter' ) . '</a>';
                echo '</div>';
                echo '</div>';
            			} else {
				// No gift transactions exist at all
				echo '<div class="mpgr-no-data">';
				echo '<h3>' . esc_html__( 'No Gift Transactions Found', 'memberpress-gift-reporter' ) . '</h3>';
				echo '<p>' . esc_html__( 'We couldn\'t find any gift transactions in your database. This could be because:', 'memberpress-gift-reporter' ) . '</p>';
				echo '<ul>';
				echo '<li>' . esc_html__( 'MemberPress Gifting add-on is not activated', 'memberpress-gift-reporter' ) . '</li>';
				echo '<li>' . esc_html__( 'No gift purchases have been completed yet', 'memberpress-gift-reporter' ) . '</li>';
				echo '<li>' . esc_html__( 'Database permissions need to be configured', 'memberpress-gift-reporter' ) . '</li>';
				echo '<li>' . esc_html__( 'Gift transactions are in a different status', 'memberpress-gift-reporter' ) . '</li>';
				echo '</ul>';
				echo '<div class="mpgr-help-links">';
				echo '<a href="https://memberpress.com/gifting/" target="_blank">' . esc_html__( 'Learn About Gifting', 'memberpress-gift-reporter' ) . '</a>';
				echo '<a href="' . esc_url(admin_url('admin.php?page=memberpress-addons')) . '">' . esc_html__( 'Check Add-ons', 'memberpress-gift-reporter' ) . '</a>';
				echo '<a href="' . esc_url(admin_url('admin.php?page=memberpress-trans')) . '">' . esc_html__( 'View All Transactions', 'memberpress-gift-reporter' ) . '</a>';
				echo '</div>';
				echo '</div>';
			}
        }
        
        if ( class_exists( 'MPGR_Onboarding' ) ) {
            MPGR_Onboarding::render_cliffhanger();
            MPGR_Onboarding::save_report_snapshot( $all_time_summary );
            MPGR_Onboarding::mark_report_viewed();
        }

        echo '</div>';
        
        // JavaScript is enqueued via admin_enqueue_scripts hook in class-admin.php
    }
}
