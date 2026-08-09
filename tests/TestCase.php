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
	 * Create the MemberPress stand-in tables once, as real tables.
	 *
	 * This deliberately runs here rather than in set_up(). WP_UnitTestCase::
	 * start_transaction() installs a 'query' filter that rewrites anything
	 * starting with "CREATE TABLE" into "CREATE TEMPORARY TABLE" so per-test
	 * tables roll back, and "CREATE TABLE IF NOT EXISTS" matches that prefix.
	 *
	 * MySQL cannot reference a temporary table more than once in a single
	 * query, and the report joins mepr_transaction_meta five times (coupon_meta,
	 * gift_status, the two reminder meta joins, and the EXISTS subquery), so
	 * temporary fixture tables make every report query fail with
	 * "Can't reopen table: 'coupon_meta'". MariaDB allows the repeated
	 * reference, which is why this only appears on MySQL.
	 *
	 * wpSetUpBeforeClass() runs before any test's transaction starts, so no
	 * filter is active and the tables are created for real. Rows are cleared
	 * per test in set_up() with DELETE, which is transactional -- TRUNCATE
	 * would implicitly commit and break the rollback isolation of the test.
	 *
	 * @param WP_UnitTest_Factory $factory Shared fixture factory.
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		global $wpdb;

		$charset = $wpdb->get_charset_collate();
		$meta    = $wpdb->prefix . 'mepr_transaction_meta';
		$txns    = $wpdb->prefix . 'mepr_transactions';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$meta} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				transaction_id bigint(20) unsigned NOT NULL,
				meta_key varchar(255) DEFAULT NULL,
				meta_value longtext,
				PRIMARY KEY (id),
				KEY transaction_id (transaction_id),
				KEY meta_key (meta_key)
			) {$charset}"
		);

		// Only the columns the report queries touch; MemberPress ships more.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$txns} (
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
	}

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

		// Summaries are cached under a hash of their filters, so bump the
		// generation instead of trying to name each key. Without this a summary
		// computed in one test would leak into the next.
		if ( class_exists( 'MPGR_Gift_Report' ) ) {
			MPGR_Gift_Report::invalidate_summary_cache();
		}

		$user_id = get_current_user_id();
		if ( $user_id ) {
			delete_user_meta( $user_id, 'mpgr_welcome_dismissed' );
			delete_user_meta( $user_id, 'mpgr_admin_bar_dismissed' );
			delete_user_meta( $user_id, 'mpgr_cliffhanger_snooze' );
			delete_user_meta( $user_id, 'mpgr_monday_pulse_dismissed' );
			delete_user_meta( $user_id, 'mpgr_report_viewed' );
		}

		$this->reset_memberpress_tables();
	}

	/**
	 * Clear the MemberPress stand-in tables between tests.
	 *
	 * DELETE rather than TRUNCATE: TRUNCATE is DDL and would implicitly commit,
	 * ending the transaction WP_UnitTestCase relies on to roll each test back.
	 * The tables themselves are created in wpSetUpBeforeClass().
	 */
	protected function reset_memberpress_tables() {
		global $wpdb;

		foreach ( array( 'mepr_transaction_meta', 'mepr_transactions' ) as $suffix ) {
			$table = $wpdb->prefix . $suffix;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( "DELETE FROM {$table}" );
		}
	}

	/**
	 * Insert a gift purchase transaction plus the meta the report keys off.
	 *
	 * The coupon is linked through '_gift_coupon_id' meta only; the row's own
	 * coupon_id column stays NULL, matching how the Gifting add-on records a
	 * purchase. Setting it here would make the report's redemption_pick derived
	 * table select the gift itself as its own redemption, silently killing the
	 * recipient join.
	 *
	 * @param array $args {
	 *     @type int    $user_id     Gifter user ID.
	 *     @type int    $product_id  Membership post ID.
	 *     @type int    $coupon_id   Gift coupon post ID (stored as meta).
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
				'coupon_id'  => null,
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
	 * Insert the redemption transaction that marks a gift as claimed.
	 *
	 * Unlike the purchase, this row does carry coupon_id -- that is what the
	 * report's redemption_pick derived table matches on to find the recipient.
	 *
	 * @param int   $coupon_id Gift coupon post ID.
	 * @param array $args {
	 *     @type int    $user_id    Recipient user ID.
	 *     @type int    $product_id Membership post ID.
	 *     @type float  $total      Transaction total.
	 *     @type string $created_at MySQL datetime of the redemption.
	 * }
	 * @return int Inserted transaction ID.
	 */
	protected function create_redemption_transaction( $coupon_id, array $args = array() ) {
		global $wpdb;

		$args = array_merge(
			array(
				'user_id'    => 0,
				'product_id' => 0,
				'total'      => 100.00,
				'created_at' => '2026-01-05 10:00:00',
			),
			$args
		);

		$wpdb->insert(
			$wpdb->prefix . 'mepr_transactions',
			array(
				'user_id'    => (int) $args['user_id'],
				'product_id' => (int) $args['product_id'],
				'coupon_id'  => (int) $coupon_id,
				'amount'     => (float) $args['total'],
				'total'      => (float) $args['total'],
				'status'     => 'complete',
				'trans_num'  => 'REDEEM-' . wp_generate_password( 8, false ),
				'created_at' => $args['created_at'],
			)
		);

		return (int) $wpdb->insert_id;
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
	 * Run an AJAX handler that ends in wp_send_json_*() and capture its output.
	 *
	 * This class extends WP_UnitTestCase, which — unlike WP_Ajax_UnitTestCase —
	 * installs no wp_die() handler. Without one, a handler ending in
	 * wp_send_json_success() reaches the default handler and calls die(),
	 * terminating the whole PHPUnit process with exit code 0: the run stops
	 * mid-suite and still looks like a pass. Filtering the die handlers turns
	 * that exit into a catchable exception.
	 *
	 * @param callable $callback Handler to invoke.
	 * @return string The JSON body the handler emitted.
	 */
	protected function run_ajax_handler( callable $callback ) {
		$die_handler = static function () {
			return static function () {
				throw new WPAjaxDieContinueException( '' );
			};
		};

		$filters = array( 'wp_die_handler', 'wp_die_ajax_handler', 'wp_die_json_handler', 'wp_die_jsonp_handler' );

		add_filter( 'wp_doing_ajax', '__return_true' );
		foreach ( $filters as $filter ) {
			add_filter( $filter, $die_handler, 1 );
		}

		$died    = false;
		$response = '';

		ob_start();
		try {
			$callback();
		} catch ( WPAjaxDieContinueException $e ) {
			unset( $e );
			$died = true;
		} finally {
			$response = ob_get_clean();

			remove_filter( 'wp_doing_ajax', '__return_true' );
			foreach ( $filters as $filter ) {
				remove_filter( $filter, $die_handler, 1 );
			}
		}

		$this->assertTrue( $died, 'Expected the AJAX handler to terminate via wp_die().' );

		return $response;
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
