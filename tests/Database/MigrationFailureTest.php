<?php
/**
 * An incomplete upgrade must remain retryable after a database error.
 *
 * @package Formstead
 */

namespace Formstead\Tests\Database;

use Formstead\Database\Migrator;
use Formstead\Database\Schema;
use Formstead\Tests\TestCase\MigrationTestCase;
use WP_Error;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL -- test-only historical DDL and deliberate failures.

/**
 * Covers failed reads and partially applied DDL, not just successful upgrades.
 *
 * @group schema
 */
final class MigrationFailureTest extends MigrationTestCase {

	/**
	 * Backfill read shapes that can otherwise look like an empty table.
	 *
	 * @return array<string, array{int, string}>
	 */
	public function backfill_reads(): array {
		return array(
			'snapshots' => array( 1, 'SELECT id, schema_version, fields, updated_at FROM' ),
			'receipts'  => array( 1, 'WHERE receipt_id IS NULL ORDER BY' ),
			'identity'  => array( 6, 'SELECT id, fields FROM' ),
		);
	}

	/**
	 * A read error is not the successful end of a backfill.
	 *
	 * @dataProvider backfill_reads
	 * @param int    $installed Previous schema marker.
	 * @param string $needle    Read to interrupt.
	 */
	public function test_failed_backfill_read_does_not_advance_version( int $installed, string $needle ): void {
		global $wpdb;
		$this->assertNull( Migrator::run() );
		update_option( Schema::VERSION_OPTION, $installed );
		$break_read = static function ( string $sql ) use ( $needle ): string {
			return str_contains( $sql, $needle ) ? 'SELECT id FROM formstead_missing_upgrade_table' : $sql;
		};
		$previous   = $wpdb->suppress_errors( true );
		add_filter( 'query', $break_read );
		try {
			$result = Migrator::run();
		} finally {
			remove_filter( 'query', $break_read );
			$wpdb->suppress_errors( $previous );
		}
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( $installed, (int) get_option( Schema::VERSION_OPTION ) );
		$this->assertNull( Migrator::run() );
		$this->assertSame( Schema::VERSION, (int) get_option( Schema::VERSION_OPTION ) );
	}

	/**
	 * A single failed ALTER cannot be hidden by later successful DDL.
	 */
	public function test_partial_ddl_failure_keeps_the_old_version_until_retry(): void {
		global $wpdb;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- checked-in local fixture.
		$sql = file_get_contents( dirname( __DIR__ ) . '/fixtures/schemas/v7.sql' );
		$this->assertIsString( $sql );
		foreach ( explode( ';', str_replace( array( '{{prefix}}', '{{charset}}' ), array( $wpdb->prefix, $wpdb->get_charset_collate() ), $sql ) ) as $statement ) {
			if ( '' !== trim( $statement ) ) {
				$this->assertNotFalse( $wpdb->query( $statement ) );
			}
		}
		update_option( Schema::VERSION_OPTION, 7 );
		$calls        = 0;
		$break_column = static function ( string $query ) use ( &$calls ): string {
			if ( str_starts_with( $query, 'ALTER TABLE ' ) && str_contains( $query, 'ADD COLUMN webhook_url ' ) ) {
				++$calls;
				return 'ALTER TABLE formstead_missing_upgrade_table ADD COLUMN broken int';
			}
			return $query;
		};
		$previous     = $wpdb->suppress_errors( true );
		add_filter( 'query', $break_column );
		try {
			$result = Migrator::run();
		} finally {
			remove_filter( 'query', $break_column );
			$wpdb->suppress_errors( $previous );
		}
		$this->assertGreaterThan( 0, $calls );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 7, (int) get_option( Schema::VERSION_OPTION ) );
		$this->assertNull( Migrator::run() );
		$this->assertSame( Schema::VERSION, (int) get_option( Schema::VERSION_OPTION ) );
	}
}
