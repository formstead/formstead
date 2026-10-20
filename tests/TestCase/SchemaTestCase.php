<?php
/**
 * Base class for tests that create real tables.
 *
 * @package Formstead
 */

namespace Formstead\Tests\TestCase;

use WP_UnitTestCase;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- schema tests must CREATE/DROP tables.
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names cannot be placeholders.

/**
 * Disables the suite's CREATE/DROP TABLE rewrite so activation and migrations
 * can be tested against real tables in the tests database.
 *
 * WordPress PHPUnit rewrites `CREATE TABLE` to `CREATE TEMPORARY TABLE`.
 * `SHOW TABLES` then misses the table. Tests that need a durable schema opt
 * into this base class and drop the tables they create.
 *
 * MySQL commits the suite transaction on DDL. The first `CREATE TABLE`
 * commits writes made before it. `DROP TABLE` in `tear_down()` commits writes
 * made after that `CREATE`. `parent::tear_down()` rolls back only work that
 * happens after the last DDL statement, which is normally nothing. Do not
 * rely on transaction rollback in subclasses. Register every table this test
 * creates. Writes to other tables, including posts and options, leak into
 * later tests.
 *
 * `SAVEPOINT` and `ROLLBACK TO SAVEPOINT` keep the suite transaction open.
 * `START TRANSACTION` ends it immediately, before `COMMIT`: a second database
 * connection can already see the row.
 *
 * @group schema
 */
abstract class SchemaTestCase extends WP_UnitTestCase {

	/**
	 * Unprefixed-or-prefixed table names created during the test.
	 *
	 * @var list<string>
	 */
	private array $schema_tables = array();

	/**
	 * Remove the temporary-table rewrite after the suite starts its transaction.
	 */
	public function set_up(): void {
		parent::set_up();
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
	}

	/**
	 * Drop real tables created by the test, then restore suite isolation.
	 */
	public function tear_down(): void {
		global $wpdb;

		foreach ( $this->schema_tables as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		}

		$this->schema_tables = array();

		parent::tear_down();
	}

	/**
	 * Track a table so `tear_down()` drops it.
	 *
	 * @param string $table Full table name, including `$wpdb->prefix`.
	 */
	protected function register_schema_table( string $table ): void {
		$this->schema_tables[] = $table;
	}
}
