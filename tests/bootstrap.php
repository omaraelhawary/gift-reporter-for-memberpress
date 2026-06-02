<?php
/**
 * PHPUnit bootstrap for Gift Reporter for MemberPress.
 *
 * @package MemberPressGiftReporter
 */

define( 'MPGR_TESTS', true );

$_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	echo "Could not find WordPress test suite in {$_tests_dir}.\n";
	echo "Run: bash bin/install-wp-tests.sh wordpress_test root '' localhost latest\n";
	exit( 1 );
}

require_once $_tests_dir . '/includes/functions.php';

/**
 * Minimal MemberPress stubs so plugin classes can load in isolation.
 */
function mpgr_tests_register_memberpress_stubs() {
	if ( ! class_exists( 'MeprTransaction' ) ) {
		// phpcs:ignore Generic.Classes.DuplicateClassName.Found
		class MeprTransaction {}
	}

	if ( ! class_exists( 'MeprProduct' ) ) {
		// phpcs:ignore Generic.Classes.DuplicateClassName.Found
		class MeprProduct {
			/** @var int */
			public $ID = 0;

			/**
			 * @param int $id Product ID.
			 */
			public function __construct( $id = 0 ) {
				$this->ID = (int) $id;
			}

			/**
			 * @return string
			 */
			public function url() {
				return home_url( '/register/' );
			}
		}
	}

	if ( ! class_exists( 'memberpress\gifting\models\Gift' ) ) {
		require_once dirname( __FILE__ ) . '/stubs/class-gift-stub.php';
	}
}

tests_add_filter(
	'muplugins_loaded',
	function () {
		mpgr_tests_register_memberpress_stubs();

		if ( ! defined( 'MPGR_VERSION' ) ) {
			define( 'MPGR_VERSION', '1.7.0' );
		}
		if ( ! defined( 'MPGR_PLUGIN_URL' ) ) {
			define( 'MPGR_PLUGIN_URL', 'http://example.org/wp-content/plugins/memberpress-gift-reporter/' );
		}
		if ( ! defined( 'MPGR_PLUGIN_PATH' ) ) {
			define( 'MPGR_PLUGIN_PATH', dirname( __DIR__ ) . '/' );
		}
		if ( ! defined( 'MPGR_PLUGIN_BASENAME' ) ) {
			define( 'MPGR_PLUGIN_BASENAME', 'memberpress-gift-reporter/gift-reporter-for-memberpress.php' );
		}

		require_once MPGR_PLUGIN_PATH . 'includes/class-reminders.php';
		require_once MPGR_PLUGIN_PATH . 'includes/class-weekly-summary.php';
		require_once MPGR_PLUGIN_PATH . 'includes/class-gift-report.php';
		require_once MPGR_PLUGIN_PATH . 'includes/class-onboarding.php';
	}
);

require $_tests_dir . '/includes/bootstrap.php';

require_once dirname( __FILE__ ) . '/TestCase.php';
