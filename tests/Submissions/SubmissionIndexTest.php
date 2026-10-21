<?php
/**
 * Tests that inbox queries use indexes.
 *
 * @package Formstead
 */

namespace Formstead\Tests\Submissions;

use Formstead\Database\Schema;
use Formstead\Forms\Field;
use Formstead\Forms\FieldType;
use Formstead\Forms\Form;
use Formstead\Forms\FormRepository;

/**
 * Confirms the inbox indexes on a 50,000 row table.
 *
 * Run with `vendor/bin/phpunit --group explain`. The default test run skips this group.
 *
 * @group explain
 *
 * @phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
 * @phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
 * @phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
 * @phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
 */
final class SubmissionIndexTest extends \WP_UnitTestCase {

	private const ROWS = 50000;

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
	 * Each inbox query uses one of the indexes added for it.
	 */
	public function test_inbox_queries_use_indexes(): void {
		global $wpdb;

		$this->remove_fixtures();
		$forms = $this->forms();
		$this->insert_rows( $forms[0]->id(), $forms[1]->id() );
		$table = Schema::submissions_table();
		$wpdb->query( "ANALYZE TABLE {$table}" );

		$hash = str_repeat( 'ab', 32 );
		$this->assert_index(
			array( 'status_created' ),
			"SELECT id FROM {$table} WHERE status IN ('new','read') ORDER BY created_at DESC, id DESC LIMIT 20"
		);
		$this->assert_index(
			array( 'form_created', 'form_status_created' ),
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE form_id = %d AND status IN ('new','read') ORDER BY created_at DESC, id DESC LIMIT 20",
				$forms[0]->id()
			)
		);
		$this->assert_index(
			array( 'form_status_created', 'form_created' ),
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE form_id = %d AND status = 'new' ORDER BY created_at DESC, id DESC LIMIT 20",
				$forms[0]->id()
			)
		);
		$this->assert_index(
			array( 'status_created' ),
			"SELECT COUNT(*) FROM {$table} WHERE status = 'new'"
		);
		$this->assert_index(
			array( 'form_created', 'form_status_created' ),
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE form_id = %d ORDER BY created_at DESC, id DESC LIMIT 20",
				$forms[0]->id()
			)
		);
		$this->assert_index(
			array( 'form_content' ),
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE form_id = %d AND content_hash = %s AND created_at >= %s LIMIT 1",
				$forms[0]->id(),
				$hash,
				'2020-01-01 00:00:00'
			)
		);
		$cursor = " AND ( created_at > '2020-01-01 00:00:00' OR ( created_at = '2020-01-01 00:00:00' AND id > 0 ) )";
		$this->assert_index(
			array( 'form_created' ),
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE form_id = %d AND status IN ('new','read'){$cursor} ORDER BY created_at ASC, id ASC LIMIT 500",
				$forms[0]->id()
			),
			true
		);
	}

	/**
	 * Assert EXPLAIN chose one of these indexes and did not scan the table.
	 *
	 * The optimizer may pick either index when both can serve the filter.
	 * `$presorted` is for a query whose ORDER BY matches that index, so the
	 * plan must not sort the rows again.
	 *
	 * @param string[] $indexes   Acceptable keys.
	 * @param string   $sql       Query without EXPLAIN. Placeholders are already prepared.
	 * @param bool     $presorted Whether the chosen index already provides the order.
	 */
	private function assert_index( array $indexes, string $sql, bool $presorted = false ): void {
		global $wpdb;

		$row = $wpdb->get_row( 'EXPLAIN ' . $sql, ARRAY_A );
		$this->assertIsArray( $row );
		$key = (string) ( $row['key'] ?? '' );
		$this->assertNotSame( 'ALL', (string) ( $row['type'] ?? '' ) );
		$this->assertContains( $key, $indexes, $key );
		if ( $presorted ) {
			$this->assertStringNotContainsString( 'Using filesort', (string) ( $row['Extra'] ?? '' ) );
		}
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
	private function insert_rows( int $first_form, int $second_form ): void {
		global $wpdb;

		$table    = Schema::submissions_table();
		$statuses = array( 'new', 'read', 'spam', 'archived' );
		$batch    = 1000;

		for ( $start = 0; $start < self::ROWS; $start += $batch ) {
			$values = array();
			$args   = array();
			for ( $offset = 0; $offset < $batch; $offset++ ) {
				$number   = $start + $offset;
				$values[] = '(%d,%d,%s,%s,%s,%s,%s)';
				$args[]   = 0 === $number % 2 ? $first_form : $second_form;
				$args[]   = 1;
				$args[]   = 'idx' . str_pad( (string) $number, 17, '0', STR_PAD_LEFT );
				$args[]   = '{}';
				$args[]   = $statuses[ $number % 4 ];
				$args[]   = gmdate( 'Y-m-d H:i:s', 1_700_000_000 + $number );
				$args[]   = hash( 'sha256', (string) $number );
			}

			$sql      = "INSERT INTO {$table} (form_id, schema_version, receipt_id, payload, status, created_at, content_hash) VALUES " . implode( ',', $values );
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
		$wpdb->query( "DELETE FROM {$submissions} WHERE receipt_id LIKE 'idx%'" );
		if ( array() === $this->form_ids ) {
			return;
		}

		$ids = implode( ',', array_map( 'intval', $this->form_ids ) );
		$wpdb->query( 'DELETE FROM ' . Schema::form_versions_table() . " WHERE form_id IN ({$ids})" );
		$wpdb->query( 'DELETE FROM ' . Schema::forms_table() . " WHERE id IN ({$ids})" );
	}
}
