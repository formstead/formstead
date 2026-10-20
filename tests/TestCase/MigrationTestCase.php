<?php
/**
 * Base class for tests that install and tear down the Formstead schema.
 *
 * @package Formstead
 */

namespace Formstead\Tests\TestCase;

use Formstead\Database\Migrator;
use Formstead\Database\Schema;
use RuntimeException;
use WP_Error;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- these tests must DROP real tables.
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names cannot be placeholders.

/**
 * Drops Formstead tables before each test and reinstalls them afterwards.
 *
 * DROP TABLE commits the suite transaction. `tear_down()` installs the schema
 * again after the parent teardown so later tests still have the tables.
 *
 * @group schema
 */
abstract class MigrationTestCase extends SchemaTestCase {

	/**
	 * Drop Formstead tables and forget the schema version and lock.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->reset_formstead_schema();
	}

	/**
	 * Reinstall the schema for tests that follow this one.
	 *
	 * @throws RuntimeException When the schema cannot be reinstalled.
	 */
	public function tear_down(): void {
		$this->reset_formstead_schema();
		parent::tear_down();

		$error = Migrator::run();
		if ( $error instanceof WP_Error ) {
			throw new RuntimeException( esc_html( $error->get_error_code() ) );
		}
	}

	/**
	 * Remove Formstead tables and the options that record migration state.
	 */
	private function reset_formstead_schema(): void {
		global $wpdb;

		foreach ( array( Schema::forms_table(), Schema::form_versions_table(), Schema::submissions_table() ) as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		}

		delete_option( Schema::VERSION_OPTION );
		delete_option( Schema::LOCK_OPTION );
	}
}
