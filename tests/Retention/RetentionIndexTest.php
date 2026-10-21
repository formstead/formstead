<?php
/**
 * Tests that retention queries use indexes.
 *
 * @package Formstead
 */

namespace Formstead\Tests\Retention;

use Formstead\Database\Schema;
use Formstead\Forms\Field;
use Formstead\Forms\FieldType;
use Formstead\Forms\Form;
use Formstead\Forms\FormRepository;

/**
 * Confirms the retention indexes on large tables.
 *
 * Run with `vendor/bin/phpunit --group explain`. The default test run skips this group.
 * The MySQL 8.0 job runs this group on its scheduled pass.
 *
 * @group explain
 *
 * @phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
 * @phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
 * @phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
 * @phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
 */
final class RetentionIndexTest extends \WP_UnitTestCase {

	private const ROWS = 50000;

	/**
	 * Submission id used only by the delivery fixtures, so cleanup can find them.
	 */
	private const FIXTURE_SUBMISSION = 900000001;

	/**
	 * Forms created for this run.
	 *
	 * @var int[]
	 */
	private array $form_ids = array();

	/**
	 * Remove the rows this test inserted.
	 *
	 * ANALYZE TABLE commits the PHPUnit transaction, which would keep the
	 * inserted rows. Commit the cleanup, then open a transaction for the
	 * parent rollback.
	 */
	public function tear_down(): void {
		global $wpdb;

		$this->remove_fixtures();
		$wpdb->query( 'COMMIT' );
		$wpdb->query( 'START TRANSACTION' );
		parent::tear_down();
	}

	/**
	 * The three retention lookups use the indexes added for them.
	 */
	public function test_retention_queries_use_indexes(): void {
		global $wpdb;

		$this->remove_fixtures();
		$forms = $this->forms();
		$this->insert_submissions( $forms[0]->id(), $forms[1]->id() );
		$submissions = Schema::submissions_table();
		$wpdb->query( "ANALYZE TABLE {$submissions}" );

		$this->assert_index(
			array( 'form_created' ),
			$wpdb->prepare(
				"SELECT id FROM {$submissions} WHERE form_id = %d AND created_at < %s ORDER BY created_at ASC, id ASC LIMIT 500",
				$forms[0]->id(),
				'2030-01-01 00:00:00'
			)
		);
		$this->assert_index(
			array( 'status_created' ),
			$wpdb->prepare(
				"SELECT id FROM {$submissions} WHERE status = 'spam' AND created_at < %s ORDER BY created_at ASC, id ASC LIMIT 500",
				'2030-01-01 00:00:00'
			)
		);

		$this->insert_deliveries();
		$deliveries = Schema::deliveries_table();
		$wpdb->query( "ANALYZE TABLE {$deliveries}" );
		$this->assert_index(
			array( 'status_updated' ),
			$wpdb->prepare(
				"SELECT id FROM {$deliveries} WHERE status IN ('handed_off','failed','cancelled') AND updated_at < %s ORDER BY updated_at ASC, id ASC LIMIT 500",
				'2030-01-01 00:00:00'
			)
		);
	}

	/**
	 * Assert EXPLAIN chose one of these indexes and did not scan the table.
	 *
	 * @param string[] $indexes Acceptable keys.
	 * @param string   $sql     Query without EXPLAIN. Placeholders are already prepared.
	 */
	private function assert_index( array $indexes, string $sql ): void {
		global $wpdb;

		$row = $wpdb->get_row( 'EXPLAIN ' . $sql, ARRAY_A );
		$this->assertIsArray( $row );
		$key = (string) ( $row['key'] ?? '' );
		$this->assertNotSame( 'ALL', (string) ( $row['type'] ?? '' ), (string) wp_json_encode( $row ) );
		$this->assertContains( $key, $indexes, (string) wp_json_encode( $row ) );
	}

	/**
	 * Two forms to spread the rows across.
	 *
	 * @return array{Form, Form}
	 */
	private function forms(): array {
		$repository = new FormRepository();
		$forms      = array();
		foreach ( array( 'One', 'Two' ) as $name ) {
			$saved = $repository->insert(
				new Form(
					0,
					'',
					$name,
					true,
					array( new Field( 'note', 'Note', FieldType::Text, false, 20 ) ),
					1,
					'https://example.com/thanks',
					'',
					''
				)
			);
			$this->assertInstanceOf( Form::class, $saved );
			$this->form_ids[] = $saved->id();
			$forms[]          = $saved;
		}

		return array( $forms[0], $forms[1] );
	}

	/**
	 * Insert ROWS submissions with a bulk statement.
	 *
	 * @param int $first_form  First form id.
	 * @param int $second_form Second form id.
	 */
	private function insert_submissions( int $first_form, int $second_form ): void {
		global $wpdb;

		$table    = Schema::submissions_table();
		$statuses = array( 'new', 'read', 'spam', 'archived' );
		$batch    = 1000;

		for ( $start = 0; $start < self::ROWS; $start += $batch ) {
			$values = array();
			$args   = array();
			for ( $offset = 0; $offset < $batch; $offset++ ) {
				$number   = $start + $offset;
				$values[] = '(%d,%d,%s,%s,%s,%s)';
				$args[]   = 0 === $number % 2 ? $first_form : $second_form;
				$args[]   = 1;
				$args[]   = 'ret' . str_pad( (string) $number, 17, '0', STR_PAD_LEFT );
				$args[]   = '{}';
				$args[]   = $statuses[ $number % 4 ];
				$args[]   = gmdate( 'Y-m-d H:i:s', 1_700_000_000 + $number );
			}

			$sql      = "INSERT INTO {$table} (form_id, schema_version, receipt_id, payload, status, created_at) VALUES " . implode( ',', $values );
			$previous = $wpdb->suppress_errors( true );
			$inserted = $wpdb->query( $wpdb->prepare( $sql, ...$args ) );
			$wpdb->suppress_errors( $previous );
			$this->assertSame( $batch, $inserted, (string) $wpdb->last_error );
		}
	}

	/**
	 * Insert ROWS deliveries with a bulk statement.
	 */
	private function insert_deliveries(): void {
		global $wpdb;

		$table    = Schema::deliveries_table();
		$statuses = array( 'pending', 'processing', 'handed_off', 'failed', 'cancelled' );
		$batch    = 1000;

		for ( $start = 0; $start < self::ROWS; $start += $batch ) {
			$values = array();
			$args   = array();
			for ( $offset = 0; $offset < $batch; $offset++ ) {
				$number   = $start + $offset;
				$stamp    = gmdate( 'Y-m-d H:i:s', 1_700_000_000 + $number );
				$values[] = '(%d,%s,%s,%d,%s,%s,%s)';
				$args[]   = self::FIXTURE_SUBMISSION;
				$args[]   = 'email';
				$args[]   = $statuses[ $number % 5 ];
				$args[]   = 0;
				$args[]   = $stamp;
				$args[]   = $stamp;
				$args[]   = $stamp;
			}

			$sql      = "INSERT INTO {$table} (submission_id, channel, status, attempts, next_attempt_at, created_at, updated_at) VALUES " . implode( ',', $values );
			$previous = $wpdb->suppress_errors( true );
			$inserted = $wpdb->query( $wpdb->prepare( $sql, ...$args ) );
			$wpdb->suppress_errors( $previous );
			$this->assertSame( $batch, $inserted, (string) $wpdb->last_error );
		}
	}

	/**
	 * Delete rows left by an earlier run that ANALYZE TABLE committed.
	 */
	private function remove_fixtures(): void {
		global $wpdb;

		$submissions = Schema::submissions_table();
		$wpdb->query( "DELETE FROM {$submissions} WHERE receipt_id LIKE 'ret%'" );

		$deliveries = Schema::deliveries_table();
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$deliveries} WHERE submission_id = %d", self::FIXTURE_SUBMISSION ) );

		if ( array() === $this->form_ids ) {
			return;
		}

		$ids = implode( ',', array_map( 'intval', $this->form_ids ) );
		$wpdb->query( 'DELETE FROM ' . Schema::form_versions_table() . " WHERE form_id IN ({$ids})" );
		$wpdb->query( 'DELETE FROM ' . Schema::forms_table() . " WHERE id IN ({$ids})" );
	}
}
