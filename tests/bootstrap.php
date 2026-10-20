<?php
/**
 * PHPUnit bootstrap for Formstead.
 *
 * @package Formstead
 */

$formstead_polyfills = dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';
if ( ! is_readable( $formstead_polyfills ) ) {
	fwrite( STDERR, "Composer autoload is missing. Run composer install.\n" );
	exit( 1 );
}

require $formstead_polyfills;

$formstead_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! is_string( $formstead_tests_dir ) || '' === $formstead_tests_dir ) {
	$formstead_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! is_readable( $formstead_tests_dir . '/includes/functions.php' ) ) {
	fwrite(
		STDERR,
		sprintf(
			"Could not find the WordPress test library at %s.\n",
			$formstead_tests_dir
		)
	);
	exit( 1 );
}

require_once $formstead_tests_dir . '/includes/functions.php';

/**
 * Load Formstead as a must-use plugin during tests.
 */
function formstead_tests_load_plugin() {
	require dirname( __DIR__ ) . '/formstead.php';
}

tests_add_filter( 'muplugins_loaded', 'formstead_tests_load_plugin' );

require $formstead_tests_dir . '/includes/bootstrap.php';

// Install the schema before the suite transaction starts, so ordinary tests
// write real rows that the suite can roll back. DDL during a test commits.
$formstead_migration = Formstead\Database\Migrator::run();
if ( $formstead_migration instanceof WP_Error ) {
	fwrite( STDERR, 'Formstead test schema install failed: ' . $formstead_migration->get_error_code() . "\n" );
	exit( 1 );
}
