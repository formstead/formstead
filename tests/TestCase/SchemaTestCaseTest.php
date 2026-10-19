<?php
/**
 * Confirms SchemaTestCase creates real tables.
 *
 * @package Formstead
 */

namespace Formstead\Tests\TestCase;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- schema tests must CREATE tables and SHOW TABLES.
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names cannot be placeholders.

/**
 * Probe-3 finding: without the rewrite, SHOW TABLES lists the table.
 *
 * @group schema
 */
final class SchemaTestCaseTest extends SchemaTestCase {

	/**
	 * CREATE TABLE is not rewritten to TEMPORARY, and SHOW TABLES lists it.
	 */
	public function test_create_table_is_not_temporary_and_is_listed(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'formstead_schema_probe';
		$this->register_schema_table( $table );

		$seen    = array();
		$capture = static function ( $sql ) use ( &$seen ) {
			$seen[] = $sql;
			return $sql;
		};

		add_filter( 'query', $capture, 99 );

		$charset = $wpdb->get_charset_collate();
		$created = $wpdb->query(
			"CREATE TABLE {$table} (
				id bigint unsigned NOT NULL AUTO_INCREMENT,
				PRIMARY KEY  (id)
			) {$charset}"
		);

		remove_filter( 'query', $capture, 99 );

		$this->assertNotFalse( $created );

		$temporary = array_filter(
			$seen,
			static function ( $sql ) {
				return false !== stripos( $sql, 'CREATE TEMPORARY TABLE' );
			}
		);
		$this->assertEmpty( $temporary );

		$bare = array_filter(
			$seen,
			static function ( $sql ) {
				return 0 === stripos( ltrim( $sql ), 'CREATE TABLE' );
			}
		);
		$this->assertNotEmpty( $bare );

		$listed = $wpdb->get_col( 'SHOW TABLES' );
		$this->assertContains( $table, $listed );
	}
}
