<?php
/**
 * Base test case for Gift Reporter for MemberPress.
 *
 * @package MemberPressGiftReporter
 */

/**
 * Base test case.
 */
abstract class MPGR_TestCase extends WP_UnitTestCase {

	/**
	 * Reset plugin options before each test.
	 */
	public function set_up() {
		parent::set_up();

		delete_option( 'mpgr_reminder_settings' );
		delete_option( 'mpgr_weekly_summary_settings' );
		delete_option( 'mpgr_cron_migrated_v1_6_4' );
		delete_option( 'mpgr_last_report_snapshot' );
		delete_option( 'mpgr_activation_ts' );
		delete_transient( 'mpgr_pulse_stats' );
		delete_transient( 'mpgr_aging_arcs' );

		$user_id = get_current_user_id();
		if ( $user_id ) {
			delete_user_meta( $user_id, 'mpgr_welcome_dismissed' );
			delete_user_meta( $user_id, 'mpgr_admin_bar_dismissed' );
			delete_user_meta( $user_id, 'mpgr_cliffhanger_snooze' );
			delete_user_meta( $user_id, 'mpgr_monday_pulse_dismissed' );
			delete_user_meta( $user_id, 'mpgr_report_viewed' );
		}

		$this->ensure_memberpress_meta_table();
		$this->ensure_memberpress_transactions_table();
	}

	/**
	 * Create a minimal MemberPress transaction meta table for tests.
	 */
	protected function ensure_memberpress_meta_table() {
		global $wpdb;

		$table   = $wpdb->prefix . 'mepr_transaction_meta';
		$charset = $wpdb->get_charset_collate();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				transaction_id bigint(20) unsigned NOT NULL,
				meta_key varchar(255) DEFAULT NULL,
				meta_value longtext,
				PRIMARY KEY (id),
				KEY transaction_id (transaction_id),
				KEY meta_key (meta_key)
			) {$charset}"
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "TRUNCATE TABLE {$table}" );
	}

	/**
	 * Create a minimal MemberPress transactions table for tests.
	 *
	 * Only the columns the report queries touch are defined; MemberPress itself
	 * ships a wider schema.
	 */
	protected function ensure_memberpress_transactions_table() {
		global $wpdb;

		$table   = $wpdb->prefix . 'mepr_transactions';
		$charset = $wpdb->get_charset_collate();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				product_id bigint(20) unsigned NOT NULL DEFAULT 0,
				coupon_id bigint(20) unsigned DEFAULT NULL,
				amount decimal(14,2) NOT NULL DEFAULT 0.00,
				total decimal(14,2) NOT NULL DEFAULT 0.00,
				status varchar(32) NOT NULL DEFAULT 'pending',
				trans_num varchar(255) DEFAULT NULL,
				created_at datetime DEFAULT NULL,
				PRIMARY KEY (id),
				KEY user_id (user_id),
				KEY coupon_id (coupon_id),
				KEY status (status)
			) {$charset}"
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "TRUNCATE TABLE {$table}" );
	}

	/**
	 * Insert a gift purchase transaction plus the meta the report keys off.
	 *
	 * @param array $args {
	 *     @type int    $user_id     Gifter user ID.
	 *     @type int    $product_id  Membership post ID.
	 *     @type int    $coupon_id   Gift coupon post ID.
	 *     @type string $status      Transaction status ('complete', 'refunded', ...).
	 *     @type string $gift_status '_gift_status' meta value ('unclaimed'/'claimed').
	 *     @type float  $total       Transaction total.
	 *     @type string $created_at  MySQL datetime.
	 * }
	 * @return int Inserted transaction ID.
	 */
	protected function create_gift_transaction( array $args = array() ) {
		global $wpdb;

		$args = array_merge(
			array(
				'user_id'     => 0,
				'product_id'  => 0,
				'coupon_id'   => null,
				'status'      => 'complete',
				'gift_status' => 'unclaimed',
				'total'       => 100.00,
				'created_at'  => '2026-01-01 10:00:00',
			),
			$args
		);

		$wpdb->insert(
			$wpdb->prefix . 'mepr_transactions',
			array(
				'user_id'    => (int) $args['user_id'],
				'product_id' => (int) $args['product_id'],
				'coupon_id'  => $args['coupon_id'],
				'amount'     => (float) $args['total'],
				'total'      => (float) $args['total'],
				'status'     => $args['status'],
				'trans_num'  => 'TEST-' . wp_generate_password( 8, false ),
				'created_at' => $args['created_at'],
			)
		);

		$transaction_id = (int) $wpdb->insert_id;

		if ( null !== $args['gift_status'] ) {
			$this->add_transaction_meta( $transaction_id, '_gift_status', $args['gift_status'] );
		}

		if ( ! empty( $args['coupon_id'] ) ) {
			$this->add_transaction_meta( $transaction_id, '_gift_coupon_id', (string) $args['coupon_id'] );
		}

		return $transaction_id;
	}

	/**
	 * Insert one MemberPress transaction meta row.
	 *
	 * @param int    $transaction_id Transaction ID.
	 * @param string $meta_key       Meta key.
	 * @param string $meta_value     Meta value.
	 */
	protected function add_transaction_meta( $transaction_id, $meta_key, $meta_value ) {
		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . 'mepr_transaction_meta',
			array(
				'transaction_id' => (int) $transaction_id,
				'meta_key'       => $meta_key,
				'meta_value'     => $meta_value,
			)
		);
	}

	/**
	 * Invoke a private or protected method on an object.
	 *
	 * @param object $object     Object instance.
	 * @param string $methodName Method name.
	 * @param array  $args       Method arguments.
	 * @return mixed
	 */
	protected function invoke_private_method( $object, $methodName, array $args = array() ) {
		$reflection = new ReflectionClass( $object );
		$method     = $reflection->getMethod( $methodName );
		$method->setAccessible( true );

		return $method->invokeArgs( $object, $args );
	}

	/**
	 * Invoke a private or protected static method.
	 *
	 * @param string $className  Class name.
	 * @param string $methodName Method name.
	 * @param array  $args       Method arguments.
	 * @return mixed
	 */
	protected function invoke_private_static_method( $className, $methodName, array $args = array() ) {
		$reflection = new ReflectionClass( $className );
		$method     = $reflection->getMethod( $methodName );
		$method->setAccessible( true );

		return $method->invokeArgs( null, $args );
	}
}
