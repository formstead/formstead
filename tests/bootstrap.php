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
 *
 * Prefer the copy inside the WordPress plugins directory. `plugin_basename()`
 * only returns `formstead/formstead.php` for a file under that directory.
 * The MySQL job symlinks the checkout there. wp-env already keeps it there.
 */
function formstead_tests_load_plugin() {
	$installed = WP_PLUGIN_DIR . '/formstead/formstead.php';
	if ( is_readable( $installed ) ) {
		// __FILE__ resolves the symlink the MySQL job uses. Register that
		// path so plugin_basename() still returns formstead/formstead.php.
		wp_register_plugin_realpath( $installed );
		require $installed;
		return;
	}

	require dirname( __DIR__ ) . '/formstead.php';
}

tests_add_filter( 'muplugins_loaded', 'formstead_tests_load_plugin' );

require $formstead_tests_dir . '/includes/bootstrap.php';

// Only this test harness may exercise the explicitly allowed local webhook hosts.
if ( ! defined( 'FORMSTEAD_E2E' ) ) {
	define( 'FORMSTEAD_E2E', true );
}

// START TRANSACTION would commit the suite transaction. See notes/roadmap.md section 7a.
Formstead\Database\Transaction::use_savepoints( true );

// Install the schema before the suite transaction starts, so ordinary tests
// write real rows that the suite can roll back. DDL during a test commits.
// Core reinstalls its own tables, but leaves plugin tables from E2E intact.
// This is the same disposable tests database, never the development database.
global $wpdb;
foreach ( Formstead\Database\Schema::tables() as $formstead_test_table ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- reset plugin tables in the test installer only.
	$wpdb->query( "DROP TABLE IF EXISTS {$formstead_test_table}" );
}
delete_option( Formstead\Database\Schema::VERSION_OPTION );
delete_option( Formstead\Database\Schema::LOCK_OPTION );
delete_option( Formstead\Database\Migrator::IDENTITY_BACKFILL_OPTION );
$formstead_migration = Formstead\Database\Migrator::run();
if ( $formstead_migration instanceof WP_Error ) {
	fwrite( STDERR, 'Formstead test schema install failed: ' . $formstead_migration->get_error_code() . "\n" );
	exit( 1 );
}
