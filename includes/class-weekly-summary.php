<?php
/**
 * Weekly Summary Email Class
 * 
 * Handles weekly summary emails sent to admin with gift activity overview
 * 
 * @package MemberPressGiftReporter
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Weekly summary email functionality
 */
class MPGR_Weekly_Summary {

	/**
	 * Get weekly summary settings with defaults
	 * 
	 * @return array Settings array
	 */
	public static function get_settings() {
		$defaults = array(
			'enabled' => false,
		);
		$settings = get_option( 'mpgr_weekly_summary_settings', array() );
		$settings = wp_parse_args( $settings, $defaults );
		return $settings;
	}

	/**
	 * Get test data for weekly summary email
	 * 
	 * @return array Test week data with sample statistics
	 */
	public static function get_test_data() {
		// Generate sample dates for the past week
		$end_date = current_time( 'mysql' );
		$start_date = date( 'Y-m-d H:i:s', strtotime( '-7 days', strtotime( $end_date ) ) ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date -- MySQL date format required
		
		// Sample test data
		$test_data = array(
			'start_date' => $start_date,
			'end_date' => $end_date,
			'total_gifts' => 15,
			'claimed_gifts' => 10,
			'unclaimed_gifts' => 5,
			'claim_rate' => 66.67,
			'total_revenue' => 1497.50,
			'claimed_revenue' => 998.00,
			'products' => array(
				'Premium Membership' => array(
					'total' => 8,
					'claimed' => 6,
					'unclaimed' => 2,
					'revenue' => 799.00,
				),
				'Basic Membership' => array(
					'total' => 5,
					'claimed' => 3,
					'unclaimed' => 2,
					'revenue' => 499.50,
				),
				'Pro Membership' => array(
					'total' => 2,
					'claimed' => 1,
					'unclaimed' => 1,
					'revenue' => 199.00,
				),
			),
			'daily_stats' => array(
				date( 'Y-m-d', strtotime( '-6 days' ) ) => array( // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date -- Date formatting for grouping
					'total' => 2,
					'claimed' => 1,
					'unclaimed' => 1,
					'revenue' => 199.00,
				),
				date( 'Y-m-d', strtotime( '-5 days' ) ) => array( // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date -- Date formatting for grouping
					'total' => 3,
					'claimed' => 2,
					'unclaimed' => 1,
					'revenue' => 299.50,
				),
				date( 'Y-m-d', strtotime( '-4 days' ) ) => array( // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date -- Date formatting for grouping
					'total' => 1,
					'claimed' => 1,
					'unclaimed' => 0,
					'revenue' => 99.50,
				),
				date( 'Y-m-d', strtotime( '-3 days' ) ) => array( // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date -- Date formatting for grouping
					'total' => 4,
					'claimed' => 3,
					'unclaimed' => 1,
					'revenue' => 399.00,
				),
				date( 'Y-m-d', strtotime( '-2 days' ) ) => array( // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date -- Date formatting for grouping
					'total' => 2,
					'claimed' => 1,
					'unclaimed' => 1,
					'revenue' => 199.50,
				),
				date( 'Y-m-d', strtotime( '-1 day' ) ) => array( // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date -- Date formatting for grouping
					'total' => 2,
					'claimed' => 1,
					'unclaimed' => 1,
					'revenue' => 199.00,
				),
				date( 'Y-m-d', strtotime( 'today' ) ) => array( // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date -- Date formatting for grouping
					'total' => 1,
					'claimed' => 1,
					'unclaimed' => 0,
					'revenue' => 101.00,
				),
			),
		);
		
		return $test_data;
	}

	/**
	 * Run weekly summary email (called by WP-Cron)
	 */
	public static function run_weekly_summary() {
		// Check if weekly summary is enabled
		$settings = self::get_settings();
		if ( empty( $settings['enabled'] ) ) {
			return;
		}

		// Get admin email
		$admin_email = get_option( 'admin_email' );
		
		if ( ! $admin_email || ! is_email( $admin_email ) ) {
			return;
		}

		// Get data for the past week
		$week_data = self::get_week_data();
		
		// Generate email content
		$email_content = self::generate_email_content( $week_data );
		
		// Send email
		$subject = sprintf(
			/* translators: %s is the site name */
			__( 'Weekly Gift Summary - %s', 'memberpress-gift-reporter' ),
			get_bloginfo( 'name' )
		);
		
		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			'From: ' . get_bloginfo( 'name' ) . ' <' . $admin_email . '>',
		);
		
		wp_mail( $admin_email, $subject, $email_content, $headers );
	}

	/**
	 * Get gift data for the past week
	 * 
	 * @return array Week data with statistics
	 */
	private static function get_week_data() {
		global $wpdb;
		
		// Calculate date range (past 7 days)
		$end_date = current_time( 'mysql' );
		$start_date = date( 'Y-m-d H:i:s', strtotime( '-7 days', strtotime( $end_date ) ) ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date -- MySQL date format required
		
		// Query for gifts purchased in the past week
		$query = "
		SELECT 
			gifter_txn.id AS gift_transaction_id,
			gifter_txn.created_at AS gift_purchase_date,
			gifter_txn.amount AS gift_amount,
			gifter_txn.total AS gift_total,
			gifter.user_email AS gifter_email,
			gift_product.post_title AS product_name,
			COALESCE(gift_status.meta_value, 'unclaimed') AS gift_status,
			redemption_txn.created_at AS redemption_date
		FROM 
			{$wpdb->prefix}mepr_transactions AS gifter_txn
			
			INNER JOIN {$wpdb->prefix}mepr_transaction_meta AS coupon_meta 
				ON gifter_txn.id = coupon_meta.transaction_id 
				AND coupon_meta.meta_key = '_gift_coupon_id'
			
			LEFT JOIN {$wpdb->users} AS gifter 
				ON gifter_txn.user_id = gifter.ID
			
			INNER JOIN {$wpdb->posts} AS gift_product 
				ON gifter_txn.product_id = gift_product.ID
			
			LEFT JOIN {$wpdb->prefix}mepr_transaction_meta AS gift_status 
				ON gifter_txn.id = gift_status.transaction_id 
				AND gift_status.meta_key = '_gift_status'
			
			LEFT JOIN {$wpdb->prefix}mepr_transactions AS redemption_txn 
				ON coupon_meta.meta_value = redemption_txn.coupon_id 
				AND redemption_txn.status = 'complete'
				AND redemption_txn.id != gifter_txn.id
			
		WHERE 
			gifter_txn.status IN ('complete', 'confirmed')
			AND gifter_txn.amount > 0
			AND gifter_txn.created_at >= %s
			AND gifter_txn.created_at <= %s

		ORDER BY 
			gifter_txn.created_at DESC
		";
		
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Dynamic query with properly prepared values
		$gifts = $wpdb->get_results( $wpdb->prepare( $query, $start_date, $end_date ), ARRAY_A );
		
		// Calculate statistics
		$total_gifts = count( $gifts );
		$claimed_gifts = 0;
		$unclaimed_gifts = 0;
		$total_revenue = 0;
		$claimed_revenue = 0;
		$products = array();
		$daily_stats = array();
		
		foreach ( $gifts as $gift ) {
			// Count claimed/unclaimed
			if ( $gift['gift_status'] === 'claimed' ) {
				$claimed_gifts++;
				$claimed_revenue += floatval( $gift['gift_total'] );
			} else {
				$unclaimed_gifts++;
			}
			
			$total_revenue += floatval( $gift['gift_total'] );
			
			// Track products
			$product_name = $gift['product_name'];
			if ( ! isset( $products[ $product_name ] ) ) {
				$products[ $product_name ] = array(
					'total' => 0,
					'claimed' => 0,
					'unclaimed' => 0,
					'revenue' => 0,
				);
			}
			$products[ $product_name ]['total']++;
			if ( $gift['gift_status'] === 'claimed' ) {
				$products[ $product_name ]['claimed']++;
			} else {
				$products[ $product_name ]['unclaimed']++;
			}
			$products[ $product_name ]['revenue'] += floatval( $gift['gift_total'] );
			
			// Track daily stats
			$purchase_date = date( 'Y-m-d', strtotime( $gift['gift_purchase_date'] ) ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date -- Date formatting for grouping
			if ( ! isset( $daily_stats[ $purchase_date ] ) ) {
				$daily_stats[ $purchase_date ] = array(
					'total' => 0,
					'claimed' => 0,
					'unclaimed' => 0,
					'revenue' => 0,
				);
			}
			$daily_stats[ $purchase_date ]['total']++;
			if ( $gift['gift_status'] === 'claimed' ) {
				$daily_stats[ $purchase_date ]['claimed']++;
			} else {
				$daily_stats[ $purchase_date ]['unclaimed']++;
			}
			$daily_stats[ $purchase_date ]['revenue'] += floatval( $gift['gift_total'] );
		}
		
		// Calculate claim rate
		$claim_rate = $total_gifts > 0 ? round( ( $claimed_gifts / $total_gifts ) * 100, 2 ) : 0;
		
		return array(
			'start_date' => $start_date,
			'end_date' => $end_date,
			'total_gifts' => $total_gifts,
			'claimed_gifts' => $claimed_gifts,
			'unclaimed_gifts' => $unclaimed_gifts,
			'claim_rate' => $claim_rate,
			'total_revenue' => $total_revenue,
			'claimed_revenue' => $claimed_revenue,
			'products' => $products,
			'daily_stats' => $daily_stats,
		);
	}

	/**
	 * Generate email HTML content
	 * 
	 * Note: Inline <style> tags are used here because email clients require inline styles
	 * for proper rendering. External stylesheets are not supported by most email clients.
	 * This is the standard practice for HTML emails and is different from web pages.
	 * 
	 * @param array $data Week data
	 * @return string HTML email content
	 */
	public static function generate_email_content( $data ) {
		$site_name = get_bloginfo( 'name' );
		$start_date_formatted = date_i18n( get_option( 'date_format' ), strtotime( $data['start_date'] ) );
		$end_date_formatted = date_i18n( get_option( 'date_format' ), strtotime( $data['end_date'] ) );
		
		// Format currency
		$total_revenue_formatted = self::format_currency( $data['total_revenue'] );
		$claimed_revenue_formatted = self::format_currency( $data['claimed_revenue'] );
		
		ob_start();
		?>
<!DOCTYPE html>
<html>
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title><?php echo esc_html__( 'Weekly Gift Summary', 'memberpress-gift-reporter' ); ?></title>
	<style>
		body { 
			font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif; 
			line-height: 1.6; 
			color: #333; 
			max-width: 800px; 
			margin: 0 auto; 
			padding: 20px; 
			background-color: #f5f5f5;
		}
		.container {
			background-color: #ffffff;
			border-radius: 8px;
			padding: 30px;
			box-shadow: 0 2px 4px rgba(0,0,0,0.1);
		}
		.header {
			background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
			color: white;
			padding: 30px;
			border-radius: 8px 8px 0 0;
			margin: -30px -30px 30px -30px;
		}
		.header h1 {
			margin: 0;
			font-size: 28px;
		}
		.header .period {
			margin-top: 10px;
			opacity: 0.9;
			font-size: 16px;
		}
		.stats-grid {
			display: grid;
			grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
			gap: 20px;
			margin: 30px 0;
		}
		.stat-card {
			background-color: #f8f9fa;
			border-radius: 8px;
			padding: 20px;
			text-align: center;
			border-left: 4px solid #667eea;
		}
		.stat-card.claimed {
			border-left-color: #28a745;
		}
		.stat-card.unclaimed {
			border-left-color: #ffc107;
		}
		.stat-card.revenue {
			border-left-color: #17a2b8;
		}
		.stat-value {
			font-size: 32px;
			font-weight: bold;
			color: #667eea;
			margin-bottom: 5px;
		}
		.stat-card.claimed .stat-value {
			color: #28a745;
		}
		.stat-card.unclaimed .stat-value {
			color: #ffc107;
		}
		.stat-card.revenue .stat-value {
			color: #17a2b8;
		}
		.stat-label {
			font-size: 14px;
			color: #6c757d;
			text-transform: uppercase;
			letter-spacing: 0.5px;
		}
		.section {
			margin: 30px 0;
		}
		.section h2 {
			color: #2c3e50;
			border-bottom: 2px solid #667eea;
			padding-bottom: 10px;
			margin-bottom: 20px;
		}
		table {
			width: 100%;
			border-collapse: collapse;
			margin: 20px 0;
		}
		table th {
			background-color: #667eea;
			color: white;
			padding: 12px;
			text-align: left;
			font-weight: 600;
		}
		table td {
			padding: 12px;
			border-bottom: 1px solid #e9ecef;
		}
		table tr:hover {
			background-color: #f8f9fa;
		}
		.badge {
			display: inline-block;
			padding: 4px 8px;
			border-radius: 4px;
			font-size: 12px;
			font-weight: 600;
		}
		.badge.claimed {
			background-color: #d4edda;
			color: #155724;
		}
		.badge.unclaimed {
			background-color: #fff3cd;
			color: #856404;
		}
		.footer {
			margin-top: 40px;
			padding-top: 20px;
			border-top: 1px solid #e9ecef;
			color: #6c757d;
			font-size: 14px;
			text-align: center;
		}
		.no-data {
			text-align: center;
			padding: 40px;
			color: #6c757d;
		}
		.no-data-icon {
			font-size: 48px;
			margin-bottom: 10px;
		}
	</style>
</head>
<body>
	<div class="container">
		<div class="header">
			<h1>🎁 <?php echo esc_html__( 'Weekly Gift Summary', 'memberpress-gift-reporter' ); ?></h1>
			<div class="period">
				<?php
				/* translators: %1$s is start date, %2$s is end date */
				printf( esc_html__( '%1$s to %2$s', 'memberpress-gift-reporter' ), esc_html( $start_date_formatted ), esc_html( $end_date_formatted ) );
				?>
			</div>
		</div>

		<?php if ( $data['total_gifts'] > 0 ) : ?>
			<div class="stats-grid">
				<div class="stat-card">
					<div class="stat-value"><?php echo esc_html( number_format( $data['total_gifts'] ) ); ?></div>
					<div class="stat-label"><?php echo esc_html__( 'Total Gifts', 'memberpress-gift-reporter' ); ?></div>
				</div>
				<div class="stat-card claimed">
					<div class="stat-value"><?php echo esc_html( number_format( $data['claimed_gifts'] ) ); ?></div>
					<div class="stat-label"><?php echo esc_html__( 'Claimed', 'memberpress-gift-reporter' ); ?></div>
				</div>
				<div class="stat-card unclaimed">
					<div class="stat-value"><?php echo esc_html( number_format( $data['unclaimed_gifts'] ) ); ?></div>
					<div class="stat-label"><?php echo esc_html__( 'Unclaimed', 'memberpress-gift-reporter' ); ?></div>
				</div>
				<div class="stat-card revenue">
					<div class="stat-value"><?php echo esc_html( $total_revenue_formatted ); ?></div>
					<div class="stat-label"><?php echo esc_html__( 'Total Revenue', 'memberpress-gift-reporter' ); ?></div>
				</div>
			</div>

			<div class="section">
				<h2>📊 <?php echo esc_html__( 'Key Metrics', 'memberpress-gift-reporter' ); ?></h2>
				<table>
					<tr>
						<td><strong><?php echo esc_html__( 'Claim Rate', 'memberpress-gift-reporter' ); ?></strong></td>
						<td><?php echo esc_html( $data['claim_rate'] ); ?>%</td>
					</tr>
					<tr>
						<td><strong><?php echo esc_html__( 'Total Revenue', 'memberpress-gift-reporter' ); ?></strong></td>
						<td><?php echo esc_html( $total_revenue_formatted ); ?></td>
					</tr>
					<tr>
						<td><strong><?php echo esc_html__( 'Revenue from Claimed Gifts', 'memberpress-gift-reporter' ); ?></strong></td>
						<td><?php echo esc_html( $claimed_revenue_formatted ); ?></td>
					</tr>
					<tr>
						<td><strong><?php echo esc_html__( 'Average Gift Value', 'memberpress-gift-reporter' ); ?></strong></td>
						<td><?php echo esc_html( self::format_currency( $data['total_gifts'] > 0 ? $data['total_revenue'] / $data['total_gifts'] : 0 ) ); ?></td>
					</tr>
				</table>
			</div>

			<?php if ( ! empty( $data['products'] ) ) : ?>
				<div class="section">
					<h2>📦 <?php echo esc_html__( 'Gifts by Product', 'memberpress-gift-reporter' ); ?></h2>
					<table>
						<thead>
							<tr>
								<th><?php echo esc_html__( 'Product', 'memberpress-gift-reporter' ); ?></th>
								<th><?php echo esc_html__( 'Total', 'memberpress-gift-reporter' ); ?></th>
								<th><?php echo esc_html__( 'Claimed', 'memberpress-gift-reporter' ); ?></th>
								<th><?php echo esc_html__( 'Unclaimed', 'memberpress-gift-reporter' ); ?></th>
								<th><?php echo esc_html__( 'Revenue', 'memberpress-gift-reporter' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $data['products'] as $product_name => $product_data ) : ?>
								<tr>
									<td><strong><?php echo esc_html( $product_name ); ?></strong></td>
									<td><?php echo esc_html( $product_data['total'] ); ?></td>
									<td><span class="badge claimed"><?php echo esc_html( $product_data['claimed'] ); ?></span></td>
									<td><span class="badge unclaimed"><?php echo esc_html( $product_data['unclaimed'] ); ?></span></td>
									<td><?php echo esc_html( self::format_currency( $product_data['revenue'] ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>

			<?php if ( ! empty( $data['daily_stats'] ) && count( $data['daily_stats'] ) > 1 ) : ?>
				<div class="section">
					<h2>📅 <?php echo esc_html__( 'Daily Breakdown', 'memberpress-gift-reporter' ); ?></h2>
					<table>
						<thead>
							<tr>
								<th><?php echo esc_html__( 'Date', 'memberpress-gift-reporter' ); ?></th>
								<th><?php echo esc_html__( 'Total', 'memberpress-gift-reporter' ); ?></th>
								<th><?php echo esc_html__( 'Claimed', 'memberpress-gift-reporter' ); ?></th>
								<th><?php echo esc_html__( 'Unclaimed', 'memberpress-gift-reporter' ); ?></th>
								<th><?php echo esc_html__( 'Revenue', 'memberpress-gift-reporter' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php
							// Sort daily stats by date (newest first)
							krsort( $data['daily_stats'] );
							foreach ( $data['daily_stats'] as $date => $daily_data ) :
								$date_formatted = date_i18n( get_option( 'date_format' ), strtotime( $date ) );
								?>
								<tr>
									<td><strong><?php echo esc_html( $date_formatted ); ?></strong></td>
									<td><?php echo esc_html( $daily_data['total'] ); ?></td>
									<td><span class="badge claimed"><?php echo esc_html( $daily_data['claimed'] ); ?></span></td>
									<td><span class="badge unclaimed"><?php echo esc_html( $daily_data['unclaimed'] ); ?></span></td>
									<td><?php echo esc_html( self::format_currency( $daily_data['revenue'] ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>

		<?php else : ?>
			<div class="no-data">
				<div class="no-data-icon">📭</div>
				<h3><?php echo esc_html__( 'No Gift Activity This Week', 'memberpress-gift-reporter' ); ?></h3>
				<p><?php echo esc_html__( 'There were no gift purchases during this period.', 'memberpress-gift-reporter' ); ?></p>
			</div>
		<?php endif; ?>

		<div class="footer">
			<p><?php echo esc_html__( 'This is an automated weekly summary from', 'memberpress-gift-reporter' ); ?> <strong><?php echo esc_html( $site_name ); ?></strong></p>
			<p style="margin-top: 10px; font-size: 12px;">
				<?php
				$report_url = admin_url( 'admin.php?page=memberpress-gift-report' );
				/* translators: %s is the report URL */
				printf( esc_html__( 'View full report: %s', 'memberpress-gift-reporter' ), '<a href="' . esc_url( $report_url ) . '">' . esc_html__( 'Gift Report', 'memberpress-gift-reporter' ) . '</a>' );
				?>
			</p>
		</div>
	</div>
</body>
</html>
		<?php
		return ob_get_clean();
	}

	/**
	 * Format currency using MemberPress settings
	 * 
	 * @param float $amount The amount to format
	 * @return string Formatted currency string
	 */
	private static function format_currency( $amount ) {
		// Use MemberPress's currency formatting function
		if ( class_exists( 'MeprAppHelper' ) ) {
			return MeprAppHelper::format_currency( $amount, true );
		}
		
		// Fallback if MemberPress helper is not available
		if ( class_exists( 'MeprOptions' ) ) {
			$mepr_options = MeprOptions::fetch();
			$symbol = $mepr_options->currency_symbol;
			$symbol_after = $mepr_options->currency_symbol_after;
			
			// Format the number
			if ( class_exists( 'MeprUtils' ) && MeprUtils::is_zero_decimal_currency() ) {
				$formatted_amount = number_format( $amount, 0 );
			} else {
				$formatted_amount = number_format( $amount, 2 );
			}
			
			// Add currency symbol
			if ( $symbol_after ) {
				return $formatted_amount . $symbol;
			} else {
				return $symbol . $formatted_amount;
			}
		}
		
		// Final fallback
		return '$' . number_format( $amount, 2 );
	}
}

