<?php
/**
 * Automatic Gift Reminders Class
 * 
 * Handles automatic reminder emails for unclaimed gifts via WP-Cron
 * 
 * @package MemberPressGiftReporter
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Automatic gift reminders functionality
 */
class MPGR_Reminders {

	/**
	 * Transaction meta key holding the per-gift reminder send log.
	 */
	const LOG_META_KEY = '_mpgr_reminder_log';

	/**
	 * How many log entries to keep per gift.
	 *
	 * The log lives in a single meta row, so it is capped to keep that row
	 * small; the oldest entries are dropped first. Twenty covers far more
	 * sends than any sane schedule produces for one gift.
	 */
	const LOG_MAX_ENTRIES = 20;

	/**
	 * Append an attempt to a gift's reminder log.
	 *
	 * Every attempt is recorded, successful or not. A wp_mail() failure inside
	 * cron was previously silent: nothing was written, so when a site owner
	 * asked "did the reminder go out?" the plugin had no answer.
	 *
	 * @param int    $transaction_id Gift transaction ID.
	 * @param string $trigger        What caused the send: 'schedule:N', 'manual' or 'bulk'.
	 * @param bool   $sent           Whether the mail was handed off successfully.
	 * @param string $recipient      Address the mail was addressed to.
	 * @param string $reason         Short machine-readable cause when not sent.
	 */
	public static function log_reminder_attempt( $transaction_id, $trigger, $sent, $recipient = '', $reason = '' ) {
		$transaction_id = (int) $transaction_id;

		if ( $transaction_id <= 0 ) {
			return;
		}

		$log = self::get_reminder_log( $transaction_id );

		$entry = array(
			'ts'      => time(),
			'trigger' => (string) $trigger,
			'result'  => $sent ? 'sent' : 'failed',
		);

		if ( '' !== $recipient ) {
			$entry['to'] = (string) $recipient;
		}

		if ( ! $sent && '' !== $reason ) {
			$entry['reason'] = (string) $reason;
		}

		$log[] = $entry;

		if ( count( $log ) > self::LOG_MAX_ENTRIES ) {
			$log = array_slice( $log, -self::LOG_MAX_ENTRIES );
		}

		self::upsert_transaction_meta( $transaction_id, self::LOG_META_KEY, wp_json_encode( $log ) );
	}

	/**
	 * Reminder log for one gift, oldest first.
	 *
	 * @param int $transaction_id Gift transaction ID.
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_reminder_log( $transaction_id ) {
		$raw = self::get_reminder_meta( (int) $transaction_id, self::LOG_META_KEY, '' );

		if ( empty( $raw ) ) {
			return array();
		}

		$decoded = json_decode( $raw, true );

		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Count reminder attempts across all gifts since a timestamp.
	 *
	 * Backs the weekly summary's "reminders sent" figure with what actually
	 * happened rather than a running per-gift counter.
	 *
	 * @param int  $since_ts    Unix timestamp to count from.
	 * @param bool $failed_only Count failures instead of successful sends.
	 * @return int
	 */
	public static function count_reminders_since( $since_ts, $failed_only = false ) {
		global $wpdb;

		$table = $wpdb->prefix . 'mepr_transaction_meta';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$logs = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT meta_value FROM ' . esc_sql( $table ) . ' WHERE meta_key = %s',
				self::LOG_META_KEY
			)
		);

		$wanted = $failed_only ? 'failed' : 'sent';
		$count  = 0;

		foreach ( $logs as $raw ) {
			$entries = json_decode( (string) $raw, true );

			if ( ! is_array( $entries ) ) {
				continue;
			}

			foreach ( $entries as $entry ) {
				if ( ! isset( $entry['ts'], $entry['result'] ) ) {
					continue;
				}

				if ( (int) $entry['ts'] >= (int) $since_ts && $wanted === $entry['result'] ) {
					++$count;
				}
			}
		}

		return $count;
	}

	/**
	 * Run scheduled reminders (called by WP-Cron)
	 */
	public static function run_scheduled_reminders() {
		$lock_key = 'mpgr_reminders_lock';
		if ( get_transient( $lock_key ) ) {
			return;
		}
		set_transient( $lock_key, time(), 5 * MINUTE_IN_SECONDS );

		try {
			self::run_scheduled_reminders_work();
		} finally {
			delete_transient( $lock_key );
		}
	}

	/**
	 * Reminder cron worker (called under lock from run_scheduled_reminders).
	 */
	private static function run_scheduled_reminders_work() {
		if ( ! class_exists( 'MeprTransaction' ) ) {
			return;
		}

		$settings = self::get_settings();
		
		if ( empty( $settings['enabled'] ) ) {
			return;
		}

		// If reminders are enabled, we always send to gifters (send_to_gifter is automatically true when enabled)
		// This check is kept for backward compatibility with old settings
		if ( empty( $settings['send_to_gifter'] ) ) {
			// Auto-enable send_to_gifter if reminders are enabled (for backward compatibility)
			$settings['send_to_gifter'] = true;
		}

		// Get all reminder schedules
		$reminder_schedules = ! empty( $settings['reminder_schedules'] ) ? $settings['reminder_schedules'] : array();
		
		if ( empty( $reminder_schedules ) ) {
			// Fallback to old format for backward compatibility
			$reminder_schedules = array(
				array( 'delay_value' => isset( $settings['delay_days'] ) ? $settings['delay_days'] : 7, 'delay_unit' => 'days' ),
			);
		}

		// Convert old format to new format for backward compatibility
		foreach ( $reminder_schedules as &$schedule ) {
			if ( isset( $schedule['delay_days'] ) && ! isset( $schedule['delay_value'] ) ) {
				$schedule['delay_value'] = (int) $schedule['delay_days'];
				$schedule['delay_unit'] = 'days';
				unset( $schedule['delay_days'] );
			}
		}
		unset( $schedule );

		// Get all unclaimed gifts (we'll filter by schedule inside the loop)
		// Query for gifts that could be due for ANY reminder schedule
		// Calculate max and min delays in seconds for accurate comparison
		$max_delay_seconds = 0;
		$min_delay_seconds = PHP_INT_MAX;
		foreach ( $reminder_schedules as $schedule ) {
			$delay_value = isset( $schedule['delay_value'] ) ? (int) $schedule['delay_value'] : 7;
			$delay_unit = isset( $schedule['delay_unit'] ) ? $schedule['delay_unit'] : 'days';
			$delay_seconds = ( $delay_unit === 'hours' ) ? $delay_value * HOUR_IN_SECONDS : $delay_value * DAY_IN_SECONDS;
			
			if ( $delay_seconds > $max_delay_seconds ) {
				$max_delay_seconds = $delay_seconds;
			}
			if ( $delay_seconds < $min_delay_seconds ) {
				$min_delay_seconds = $delay_seconds;
			}
		}
		
		// Query for gifts that are at least min_delay old (could be due for first reminder)
		// For 0 delay, we want gifts purchased in the last hour (to catch newly purchased gifts)
		// For > 0, we want gifts at least that old
		if ( $min_delay_seconds === 0 ) {
			// For 0 delay, include gifts purchased in the last hour plus any older unclaimed gifts
			// This ensures we catch newly purchased gifts on the next cron run
			$cutoff_ts = time() - HOUR_IN_SECONDS;
		} else {
			$cutoff_ts = time() - $min_delay_seconds;
		}
		$gifts = self::get_unclaimed_gifts( $cutoff_ts, 500, count( $reminder_schedules ) );

		foreach ( $gifts as $gift ) {
			$sent_count = (int) self::get_reminder_meta( $gift->gift_transaction_id, '_mpgr_reminder_sent_count', 0 );
			$last_ts = (int) self::get_reminder_meta( $gift->gift_transaction_id, '_mpgr_last_reminder_ts', 0 );
			
			// Get purchase timestamp
			$purchase_ts = strtotime( $gift->gift_purchase_date );
			
			if ( ! $purchase_ts ) {
				continue;
			}

			// Check each reminder schedule
			foreach ( $reminder_schedules as $schedule_index => $schedule ) {
				// Backward compatibility: check for old delay_days format
				if ( isset( $schedule['delay_days'] ) && ! isset( $schedule['delay_value'] ) ) {
					$delay_value = (int) $schedule['delay_days'];
					$delay_unit = 'days';
				} else {
					$delay_value = isset( $schedule['delay_value'] ) ? (int) $schedule['delay_value'] : 7;
					$delay_unit = isset( $schedule['delay_unit'] ) ? $schedule['delay_unit'] : 'days';
				}
				
				// Calculate delay in seconds
				$delay_seconds = ( $delay_unit === 'hours' ) ? $delay_value * HOUR_IN_SECONDS : $delay_value * DAY_IN_SECONDS;
				
				// Calculate when this reminder should be sent
				$reminder_due_ts = $purchase_ts + $delay_seconds;
				
				// For 0 delay (0 hours or 0 days): Send immediately for any unclaimed gift
				// This means send on the next cron run, regardless of when the gift was purchased
				if ( $delay_seconds === 0 ) {
					// For 0 delay, we always send (as long as other conditions are met)
					// No need to check if reminder is due - it's always due for 0 delay
				} else {
					// For > 0 delay: Skip if this reminder hasn't come due yet
					if ( time() < $reminder_due_ts ) {
						continue;
					}
				}
				
				// Skip if we've already sent this reminder (check if we've sent at least schedule_index + 1 reminders)
				// For 0 delay, we check more carefully: if sent_count is exactly schedule_index + 1, we've sent this one
				// If sent_count > schedule_index, we've already sent this or a later reminder
				if ( $delay_seconds === 0 ) {
					// For 0 delay, check if we've sent this specific reminder
					// sent_count should be schedule_index + 1 if we've sent this reminder
					if ( $sent_count > $schedule_index ) {
						continue;
					}
				} else {
					if ( $sent_count > $schedule_index ) {
						continue;
					}
				}
				
				// Skip if we've sent this exact reminder recently (within 1 day to prevent duplicates)
				// For 0 delay, use a shorter window (1 hour) to prevent spam, but still allow sending
				if ( $delay_seconds === 0 ) {
					// For 0 delay, only skip if we sent this exact reminder within the last hour
					if ( $last_ts && ( time() - $last_ts ) < HOUR_IN_SECONDS && $sent_count === $schedule_index + 1 ) {
						continue;
					}
				} else {
					// For > 0 delay, check if we sent this exact reminder recently (within 1 day)
					$reminder_due_ts = $purchase_ts + $delay_seconds;
					if ( $last_ts && abs( $last_ts - $reminder_due_ts ) < DAY_IN_SECONDS && $sent_count === $schedule_index + 1 ) {
						continue;
					}
				}
				
				// Send this reminder
				$sent = self::send_reminder( $gift, $settings );

				self::log_reminder_attempt(
					$gift->gift_transaction_id,
					'schedule:' . $schedule_index,
					$sent,
					isset( $gift->gifter_email ) ? $gift->gifter_email : '',
					$sent ? '' : 'mail_failed'
				);

				if ( ! $sent ) {
					continue; // Don't update meta if email failed
				}

				// Update tracking meta
				self::update_reminder_tracking( $gift->gift_transaction_id, $schedule_index + 1 );
				
				// Only send one reminder per run per gift
				break;
			}
		}
	}

	/**
	 * Get reminder settings with defaults
	 * 
	 * @return array Settings array
	 */
	public static function get_settings() {
		$defaults = array(
			'enabled'           => false,
			'delay_days'        => 7,
			'max_reminders'     => 2,
			'send_to_gifter'    => true,
			'from_name'         => '',
			'from_email'        => '',
			'email_subject'          => '',
			'email_body'             => '',
			'gifter_email_subject'  => '',
			'gifter_email_body'     => '',
			'reminder_schedules' => array(
				array( 'delay_value' => 7, 'delay_unit' => 'days' ),
				array( 'delay_value' => 14, 'delay_unit' => 'days' ),
			),
		);
		$settings = get_option( 'mpgr_reminder_settings', array() );
		$settings = wp_parse_args( $settings, $defaults );
		
		// Ensure reminder_schedules is an array and has proper structure
		if ( empty( $settings['reminder_schedules'] ) || ! is_array( $settings['reminder_schedules'] ) ) {
			$settings['reminder_schedules'] = $defaults['reminder_schedules'];
		}
		
		return $settings;
	}

	/**
	 * HTML email headers including From name and address from settings.
	 *
	 * @return array Email headers for wp_mail().
	 */
	public static function get_email_headers() {
		$settings   = self::get_settings();
		$from_name  = ! empty( $settings['from_name'] ) ? $settings['from_name'] : get_bloginfo( 'name' );
		// Truncate at the first line break rather than deleting it: removing the
		// newline from "Evil\r\nBcc: attacker@example.com" would prevent header
		// injection but leave "EvilBcc: attacker@example.com" as the display
		// name. Anything after a line break was never a legitimate from name.
		$from_name  = sanitize_text_field( preg_replace( '/[\r\n].*$/s', '', $from_name ) );
		$from_email = ( ! empty( $settings['from_email'] ) && is_email( $settings['from_email'] ) )
			? sanitize_email( $settings['from_email'] )
			: get_option( 'admin_email' );

		return array(
			'Content-Type: text/html; charset=UTF-8',
			'From: ' . $from_name . ' <' . $from_email . '>',
		);
	}

	/**
	 * Record a manual or queued resend in reminder tracking meta.
	 *
	 * @param int $transaction_id Gift transaction ID.
	 */
	public static function record_manual_reminder_sent( $transaction_id ) {
		$transaction_id = (int) $transaction_id;
		if ( $transaction_id <= 0 ) {
			return;
		}

		$current_count = (int) self::get_reminder_meta( $transaction_id, '_mpgr_reminder_sent_count', 0 );
		self::update_reminder_tracking( $transaction_id, $current_count + 1 );
	}

	/**
	 * Persist reminder sent count and last-sent timestamp for a gift transaction.
	 *
	 * @param int $transaction_id Gift transaction ID.
	 * @param int $sent_count     Reminder count to store.
	 */
	private static function update_reminder_tracking( $transaction_id, $sent_count ) {
		self::upsert_transaction_meta( $transaction_id, '_mpgr_reminder_sent_count', (string) (int) $sent_count );
		self::upsert_transaction_meta( $transaction_id, '_mpgr_last_reminder_ts', (string) time() );
	}

	/**
	 * Insert or update a mepr_transaction_meta row.
	 *
	 * @param int    $transaction_id Transaction ID.
	 * @param string $meta_key       Meta key.
	 * @param string $meta_value     Meta value.
	 */
	private static function upsert_transaction_meta( $transaction_id, $meta_key, $meta_value ) {
		global $wpdb;
		$table = $wpdb->prefix . 'mepr_transaction_meta';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . esc_sql( $table ) . ' WHERE transaction_id = %d AND meta_key = %s',
				$transaction_id,
				$meta_key
			)
		);

		if ( $exists ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			$wpdb->update(
				$table,
				array( 'meta_value' => $meta_value ),
				array(
					'transaction_id' => $transaction_id,
					'meta_key'       => $meta_key,
				),
				array( '%s' ),
				array( '%d', '%s' )
			);
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		$wpdb->insert(
			$table,
			array(
				'transaction_id' => $transaction_id,
				'meta_key'       => $meta_key,
				'meta_value'     => $meta_value,
			),
			array( '%d', '%s', '%s' )
		);
	}

	/**
	 * Get reminder meta value (from transaction meta table)
	 * 
	 * @param int    $transaction_id Transaction ID
	 * @param string $meta_key Meta key
	 * @param mixed  $default Default value if not found
	 * @return mixed Meta value
	 */
	private static function get_reminder_meta( $transaction_id, $meta_key, $default = '' ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Necessary for reminder meta lookup
		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT meta_value FROM {$wpdb->prefix}mepr_transaction_meta 
				WHERE transaction_id = %d AND meta_key = %s",
				$transaction_id,
				$meta_key
			)
		);
		return $value !== null ? $value : $default;
	}

	/**
	 * Get unclaimed gifts older than cutoff timestamp
	 * 
	 * @param int $cutoff_ts Unix timestamp cutoff
	 * @param int $limit Maximum number of gifts to process per run
	 * @return array Array of gift objects
	 */
	protected static function get_unclaimed_gifts( $cutoff_ts, $limit = 100, $max_reminders = 0 ) {
		global $wpdb;

		$cutoff_date = gmdate( 'Y-m-d H:i:s', $cutoff_ts );

		$reminder_eligibility_sql = '';
		if ( $max_reminders > 0 ) {
			$reminder_eligibility_sql = $wpdb->prepare(
				' AND ( sent_count_meta.meta_value IS NULL OR CAST(sent_count_meta.meta_value AS UNSIGNED) < %d )',
				$max_reminders
			);
		}

		$query = "
		SELECT 
			gifter_txn.id AS gift_transaction_id,
			gifter_txn.created_at AS gift_purchase_date,
			gifter_txn.user_id AS gifter_user_id,
			gifter_txn.product_id AS product_id,
			gifter.user_email AS gifter_email,
			coupon_meta.meta_value AS coupon_id,
			gift_coupon.post_title AS coupon_code,
			COALESCE(gift_status.meta_value, 'unclaimed') AS gift_status
		FROM 
			{$wpdb->prefix}mepr_transactions AS gifter_txn
			
			INNER JOIN {$wpdb->prefix}mepr_transaction_meta AS coupon_meta 
				ON gifter_txn.id = coupon_meta.transaction_id 
				AND coupon_meta.meta_key = '_gift_coupon_id'
			
			LEFT JOIN {$wpdb->users} AS gifter 
				ON gifter_txn.user_id = gifter.ID
			
			LEFT JOIN {$wpdb->posts} AS gift_coupon 
				ON coupon_meta.meta_value = gift_coupon.ID
			
			LEFT JOIN {$wpdb->prefix}mepr_transaction_meta AS gift_status 
				ON gifter_txn.id = gift_status.transaction_id 
				AND gift_status.meta_key = '_gift_status'

			LEFT JOIN {$wpdb->prefix}mepr_transaction_meta AS sent_count_meta
				ON gifter_txn.id = sent_count_meta.transaction_id
				AND sent_count_meta.meta_key = '_mpgr_reminder_sent_count'
			
		WHERE 
			gifter_txn.status IN ('complete', 'confirmed')
			AND gifter_txn.amount > 0
			AND gifter_txn.created_at <= %s
			AND COALESCE(gift_status.meta_value, 'unclaimed') = 'unclaimed'
			AND gifter.user_email IS NOT NULL
			AND gifter.user_email != ''
			{$reminder_eligibility_sql}

		ORDER BY 
			gifter_txn.created_at ASC

		LIMIT %d
		";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Dynamic query with properly prepared values
		return $wpdb->get_results( $wpdb->prepare( $query, $cutoff_date, $limit ) );
	}

	/**
	 * Generate gift redemption URL using product URL (like gifting plugin)
	 * 
	 * @param int    $product_id Product ID
	 * @param string $coupon_code Coupon code
	 * @return string Redemption URL
	 */
	protected static function generate_redemption_url( $product_id, $coupon_code ) {
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
	 * Send reminder email(s) for a gift
	 * 
	 * @param object $gift Gift object from database
	 * @param array  $settings Reminder settings
	 */
	protected static function send_reminder( $gift, $settings ) {
		// Get product name
		$product_name = get_post_field( 'post_title', $gift->product_id );
		if ( ! $product_name ) {
			return false;
		}

		// Get coupon code
		$coupon_code = $gift->coupon_code;
		if ( ! $coupon_code ) {
			// Try to get it from post if meta didn't work
			if ( $gift->coupon_id ) {
				$coupon_code = get_post_field( 'post_title', $gift->coupon_id );
			}
		}

		if ( ! $coupon_code ) {
			return false;
		}

		// Generate redemption link using product URL
		$redemption_link = self::generate_redemption_url( $gift->product_id, $coupon_code );

		// Get user data (MemberPress style)
		$user = get_userdata( $gift->gifter_user_id );
		$user_login      = $user ? $user->user_login : '';
		$user_email      = $user ? $user->user_email : '';
		$user_first_name = $user ? get_user_meta( $user->ID, 'first_name', true ) : '';
		$user_last_name  = $user ? get_user_meta( $user->ID, 'last_name', true ) : '';
		$blogname        = get_bloginfo( 'name' );

		// Prepare email template variables (MemberPress style)
		$template_vars = array(
			'product_name'    => $product_name,
			'redemption_link' => $redemption_link,
			'site_name'       => $blogname,
			'blogname'        => $blogname,
			'user_login'      => $user_login,
			'user_email'      => $user_email,
			'user_first_name' => $user_first_name,
			'user_last_name'  => $user_last_name,
		);

		$headers = self::get_email_headers();

		// Send to gifter if enabled
		if ( ! empty( $settings['send_to_gifter'] ) && ! empty( $gift->gifter_email ) && is_email( $gift->gifter_email ) ) {
			// Get gifter email body
			$gifter_email_body = ! empty( $settings['gifter_email_body'] ) ? $settings['gifter_email_body'] : ( ! empty( $settings['email_body'] ) ? $settings['email_body'] : '' );
			
			if ( ! empty( $gifter_email_body ) ) {
				// Use custom email body with variable replacement (MemberPress style: {$variable})
				$message = $gifter_email_body;
				$message = self::replace_email_variables( $message, $template_vars );
				
				// Wrap custom body with header/footer templates
				$header_content = self::get_email_header( $template_vars );
				$footer_content = self::get_email_footer( $template_vars );
				$message = $header_content . $message . $footer_content;
			} else {
				// Render email template (includes header/footer automatically)
				$message = self::render_email_template( 'reminder-email', $template_vars );
			}

			// Get gifter email subject
			$gifter_subject = ! empty( $settings['gifter_email_subject'] ) ? $settings['gifter_email_subject'] : ( ! empty( $settings['email_subject'] ) ? $settings['email_subject'] : '' );
			
			if ( ! empty( $gifter_subject ) ) {
				$subject = self::replace_email_variables( $gifter_subject, $template_vars );
			} else {
				// translators: %s is the product name
				$subject = sprintf( __( 'Reminder: Your Gift Purchase - %s', 'memberpress-gift-reporter' ), $product_name );
			}
			
			return wp_mail( $gift->gifter_email, $subject, $message, $headers );
		}
		
		return false;

	}

	/**
	 * Replace email variables in template (MemberPress style: {$variable})
	 * 
	 * @param string $content Content with variables
	 * @param array  $variables Variables to replace
	 * @return string Content with variables replaced
	 */
	public static function replace_email_variables( $content, $variables ) {
		// Replace {$variable} format (MemberPress style) - primary format
		foreach ( $variables as $key => $value ) {
			// Escape HTML for text variables (except redemption_link which is a URL)
			if ( 'redemption_link' !== $key ) {
				$escaped_value = esc_html( $value );
				// Replace {$variable} format
				$content = str_replace( '{$' . $key . '}', $escaped_value, $content );
				// Support {variable} format for backward compatibility
				$content = str_replace( '{' . $key . '}', $escaped_value, $content );
			} else {
				// For redemption_link, use esc_url for href but esc_html for display
				$escaped_url = esc_url( $value );
				$escaped_text = esc_html( $value );
				
				// Replace in href attributes (both {$redemption_link} and {redemption_link})
				$content = preg_replace( '/href=["\']\{\$?redemption_link\}["\']/', 'href="' . $escaped_url . '"', $content );
				// Also handle href without quotes (edge case)
				$content = preg_replace( '/href=(\{?\$?redemption_link\}?)/', 'href="' . $escaped_url . '"', $content );
				
				// Replace as text (both formats)
				$content = str_replace( '{$redemption_link}', $escaped_text, $content );
				$content = str_replace( '{redemption_link}', $escaped_text, $content );
			}
		}
		
		return $content;
	}

	/**
	 * Render email template with variables (template includes header and footer)
	 * 
	 * @param string $template_name Template name
	 * @param array  $variables Template variables
	 * @return string Rendered template
	 */
	public static function render_email_template( $template_name, $variables = array() ) {
		$template_path = self::locate_email_template( $template_name );
		
		if ( ! file_exists( $template_path ) ) {
			return self::get_fallback_email_template( $variables );
		}
		
		// Extract variables for template (MemberPress style)
		extract( $variables, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract -- Safe extraction for template variables
		
		// For reminder-email template, include header first, then template (body only), then footer
		if ( 'reminder-email' === $template_name ) {
			$header_content = self::get_email_header( $variables );
			ob_start();
			include $template_path;
			$body_content = ob_get_clean();
			$footer_content = self::get_email_footer( $variables );
			// Header opens <div class="content">, template provides body, footer closes content div and HTML
			return $header_content . $body_content . $footer_content;
		}
		
		// For other templates, just include the template
		ob_start();
		include $template_path;
		return ob_get_clean();
	}

	/**
	 * Get email header template
	 * 
	 * Note: Inline <style> tags are used here because email clients require inline styles
	 * for proper rendering. External stylesheets are not supported by most email clients.
	 * This is the standard practice for HTML emails and is different from web pages.
	 * 
	 * @param array $variables Template variables
	 * @return string Header HTML
	 */
	public static function get_email_header( $variables = array() ) {
		// Extract variables
		extract( $variables, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract -- Safe extraction for template variables
		
		$header_path = MPGR_PLUGIN_PATH . 'views/emails/reminder-email-header.php';
		$theme_header = get_stylesheet_directory() . '/memberpress-gift-reporter/emails/reminder-email-header.php';
		
		if ( file_exists( $theme_header ) ) {
			ob_start();
			include $theme_header;
			return ob_get_clean();
		} elseif ( file_exists( $header_path ) ) {
			ob_start();
			include $header_path;
			return ob_get_clean();
		}
		
		// Fallback header
		return '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>' . esc_html( isset( $product_name ) ? $product_name : '' ) . '</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background-color: #f8f9fa; padding: 20px; border-radius: 8px; margin-bottom: 20px; }
        .content { background-color: #ffffff; padding: 20px; border-radius: 8px; border: 1px solid #e9ecef; }
        .footer { margin-top: 30px; padding-top: 20px; border-top: 1px solid #e9ecef; color: #6c757d; font-size: 14px; }
    </style>
</head>
<body>
    <div class="header">
        <h1 style="margin: 0; color: #2c3e50;">🎁 Gift Membership Purchase</h1>
    </div>
    <div class="content">';
	}

	/**
	 * Get email footer template
	 * 
	 * @param array $variables Template variables
	 * @return string Footer HTML
	 */
	public static function get_email_footer( $variables = array() ) {
		// Extract variables
		extract( $variables, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract -- Safe extraction for template variables
		
		// Check for theme override first
		$theme_footer = get_stylesheet_directory() . '/memberpress-gift-reporter/emails/reminder-email-footer.php';
		
		if ( file_exists( $theme_footer ) ) {
			ob_start();
			include $theme_footer;
			return ob_get_clean();
		}
		
		// Return just closing tags (no footer content)
		return '    </div>
</body>
</html>';
	}

	/**
	 * Locate email template with theme override support
	 * 
	 * @param string $template_name The template name (without .php extension)
	 * @return string Full path to the template file
	 */
	private static function locate_email_template( $template_name ) {
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
	 * Fallback email template (used if template file doesn't exist)
	 * 
	 * @param array $variables Template variables
	 * @return string HTML email content
	 */
	private static function get_fallback_email_template( $variables ) {
		// Include header template if available
		$header_path = MPGR_PLUGIN_PATH . 'views/emails/reminder-email-header.php';
		$theme_header = get_stylesheet_directory() . '/memberpress-gift-reporter/emails/reminder-email-header.php';
		$header_content = '';
		
		if ( file_exists( $theme_header ) || file_exists( $header_path ) ) {
			$header_file = file_exists( $theme_header ) ? $theme_header : $header_path;
			ob_start();
			include $header_file;
			$header_content = ob_get_clean();
		}
		
		$product_name    = isset( $variables['product_name'] ) ? esc_html( $variables['product_name'] ) : '';
		$redemption_link = isset( $variables['redemption_link'] ) ? esc_url( $variables['redemption_link'] ) : '';
		$site_name       = isset( $variables['site_name'] ) ? esc_html( $variables['site_name'] ) : esc_html( get_bloginfo( 'name' ) );
		
		$body_content = '<div style="font-size: 18px; font-weight: bold; margin-bottom: 20px;">Hello!</div>
        
<p>This is a reminder about your gift purchase for <strong>' . $product_name . '</strong>.</p>

<div style="background-color: #f3e5f5; padding: 15px; border-radius: 6px; border-left: 4px solid #9c27b0; margin: 20px 0;">
    <strong>The recipient can redeem this gift by visiting:</strong><br>
    <a href="' . $redemption_link . '" style="color: #9c27b0; text-decoration: none; font-weight: bold;">' . esc_html( $redemption_link ) . '</a>
</div>

<p style="font-style: italic; color: #27ae60;">Thank you for your purchase!</p>';
		
		// Footer - just closing tags (no footer content)
		$footer_content = '    </div>
</body>
</html>';
		
		return $header_content . $body_content . $footer_content;
	}
}

