<?php
/**
 * PHPUnit bootstrap: loads the WordPress test library, WooCommerce and this plugin.
 *
 * Inside wp-env the tests-cli container sets WP_TESTS_DIR to a test library that matches
 * the installed WordPress version. Elsewhere the wp-phpunit Composer package is used.
 */

use Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer;

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';

$ctdflow_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $ctdflow_tests_dir ) {
	$ctdflow_tests_dir = getenv( 'WP_PHPUNIT__DIR' );
}

if ( ! $ctdflow_tests_dir || ! file_exists( $ctdflow_tests_dir . '/includes/functions.php' ) ) {
	fwrite( STDERR, "Could not find the WordPress test library. Run the tests inside wp-env, see README.md.\n" );
	exit( 1 );
}

define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills' );

require_once $ctdflow_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		$plugins_dir = dirname( __DIR__, 2 );

		require_once ctdflow_tests_woocommerce_file( $plugins_dir );
		require_once dirname( __DIR__ ) . '/connect-the-dots.php';
	}
);

// WooCommerce creates its tables on activation, which never runs in the test install. Its tables
// survive between runs, and older WooCommerce versions then log harmless "Multiple primary key"
// errors, which fail tests that run in a separate process. So database errors are silenced here.
tests_add_filter(
	'setup_theme',
	static function (): void {
		global $wpdb;

		$suppressed = $wpdb->suppress_errors();

		try {
			WC_Install::install();

			// Before woocommerce_init, WC_Install leaves out the order tables of HPOS, so on an empty
			// test database they are created here.
			wc_get_container()->get( DataSynchronizer::class )->create_database_tables();
		} finally {
			$wpdb->suppress_errors( $suppressed );
		}

		foreach ( array( 'woocommerce_order_items', 'wc_orders' ) as $table ) {
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . $table ) ) !== $wpdb->prefix . $table ) {
				fwrite( STDERR, "WooCommerce did not install: the table {$wpdb->prefix}{$table} is missing.\n" );
				exit( 1 );
			}
		}
	}
);

/**
 * Finds WooCommerce next to this plugin, whatever its directory is called.
 */
function ctdflow_tests_woocommerce_file( string $plugins_dir ): string {
	$candidates = glob( $plugins_dir . '/*/woocommerce.php' ) ?: array();

	foreach ( $candidates as $candidate ) {
		if ( str_contains( (string) file_get_contents( $candidate, false, null, 0, 2048 ), 'Plugin Name: WooCommerce' ) ) {
			return $candidate;
		}
	}

	fwrite( STDERR, "WooCommerce is not installed next to this plugin.\n" );
	exit( 1 );
}

require $ctdflow_tests_dir . '/includes/bootstrap.php';
