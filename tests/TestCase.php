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
