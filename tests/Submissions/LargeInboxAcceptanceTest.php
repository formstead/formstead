<?php
/**
 * Repeatable acceptance measurements against a 100,000-row inbox.
 *
 * @package Formstead
 */

namespace Formstead\Tests\Submissions;

use Formstead\Database\Schema;
use Formstead\Forms\Field;
use Formstead\Forms\FieldType;
use Formstead\Forms\Form;
use Formstead\Forms\FormRepository;
use Formstead\Privacy\IdentityHasher;
use Formstead\Retention\RetentionRunner;
use Formstead\Submissions\ReceiptId;
use Formstead\Submissions\SubmissionQuery;
use Formstead\Submissions\SubmissionRepository;
use WP_UnitTestCase;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL -- bulk synthetic fixtures, trusted table names.

/**
 * Run on both MariaDB and MySQL; normal checks skip this heavy group.
 *
 * @group explain
 */
final class LargeInboxAcceptanceTest extends WP_UnitTestCase {

	/**
	 * Forms owned by this fixture.
	 *
	 * @var list<int>
	 */
	private array $forms = array();

	/**
	 * First submission owned by the fixture.
	 *
	 * @var int
	 */
	private int $first_submission = 0;

	/**
	 * Last submission owned by the fixture.
	 *
	 * @var int
	 */
	private int $last_submission = 0;

	/**
	 * ANALYZE commits, so explicitly remove every fixture and restart suite isolation.
	 */
	public function tear_down(): void {
		global $wpdb;
		if ( array() !== $this->forms ) {
			$ids         = implode( ',', $this->forms );
			$submissions = Schema::submissions_table();
			foreach ( array( Schema::deliveries_table(), Schema::idempotency_keys_table() ) as $table ) {
				$wpdb->query( "DELETE d FROM {$table} d INNER JOIN {$submissions} s ON s.id = d.submission_id WHERE s.form_id IN ({$ids})" );
			}
			// Cancelled deliveries intentionally outlive their removed submission.
			$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Schema::deliveries_table() . ' WHERE submission_id BETWEEN %d AND %d', $this->first_submission, $this->last_submission ) );
			foreach ( array( Schema::identities_table(), Schema::form_versions_table(), $submissions ) as $table ) {
				$wpdb->query( "DELETE FROM {$table} WHERE form_id IN ({$ids})" );
			}
			$wpdb->query( 'DELETE FROM ' . Schema::forms_table() . " WHERE id IN ({$ids})" );
		}
		delete_option( RetentionRunner::REPORT_OPTION );
		$wpdb->query( 'COMMIT' );
		$wpdb->query( 'START TRANSACTION' );
		parent::tear_down();
	}

	/**
	 * Measure hydrated lists, counts, indexed email search, and real retention batches.
	 */
	public function test_one_hundred_thousand_submissions_remain_operable(): void {
		global $wpdb;

		$this->seed();
		foreach ( array( Schema::submissions_table(), Schema::identities_table(), Schema::deliveries_table() ) as $table ) {
			$wpdb->query( "ANALYZE TABLE {$table}" );
		}
		$repository = new SubmissionRepository();
		$queries    = array(
			'first' => array( SubmissionQuery::from_values( 0, 'inbox', '', '' ), 1, 1.0 ),
			'deep'  => array( SubmissionQuery::from_values( 0, 'inbox', '', '' ), 1000, 2.0 ),
			'form'  => array( SubmissionQuery::from_values( $this->forms[0], 'unread', gmdate( 'Y-m-d', time() - 90 * DAY_IN_SECONDS ), gmdate( 'Y-m-d' ) ), 1, 1.0 ),
			'email' => array( SubmissionQuery::from_values( 0, 'all', '', '', 'rare@example.com' ), 1, 2.0 ),
		);
		$report     = array(
			'rows'               => 100000,
			'forms'              => 10,
			'payload_note_bytes' => 480,
			'php'                => PHP_VERSION,
			'database'           => $wpdb->db_version(),
			'measurements'       => array(),
		);
		foreach ( $queries as $name => $case ) {
			list( $query, $page, $budget ) = $case;
			$read                          = static function () use ( $repository, $query, $page ): void {
				$repository->paginate( $query, $page, 20 );
				$repository->count_by_status( $query );
			};
				$read();
				$stats                           = $this->measure( $read );
				$report['measurements'][ $name ] = $stats;
				$this->assertLessThanOrEqual( $budget, $stats['p95_seconds'], $name );
		}
		$stats                                   = $this->measure(
			function (): void {
				$result = ( new RetentionRunner() )->run( 1, null );
				$this->assertIsArray( $result );
				$this->assertSame( array(), $result['errors'] );
				$this->assertSame( 500, $result['submissions'] + $result['spam'] );
			}
		);
		$report['measurements']['retention_500'] = $stats;
		$this->assertLessThanOrEqual( 2.0, $stats['p95_seconds'] );
		$started                        = microtime( true );
		$complete                       = ( new RetentionRunner() )->run( null, null, null, 'cli' );
		$report['full_cleanup_seconds'] = microtime( true ) - $started;
		$this->assertIsArray( $complete );
		$this->assertFalse( $complete['backlog'] );
		$this->assertSame( array(), $complete['errors'] );
		$submissions = Schema::submissions_table();
		$identities  = Schema::identities_table();
		$keys        = Schema::idempotency_keys_table();
		$this->assertSame( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$identities} i LEFT JOIN {$submissions} s ON s.id = i.submission_id WHERE s.id IS NULL" ) );
		$this->assertSame( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$keys} k LEFT JOIN {$submissions} s ON s.id = k.submission_id WHERE s.id IS NULL" ) );
		$this->assertGreaterThan( 0, $repository->paginate( SubmissionQuery::from_values( $this->forms[9], 'all', '', '' ), 1, 20 )->total() );
		$report['peak_memory_bytes'] = memory_get_peak_usage( true );
		$this->assertLessThanOrEqual( 256 * 1024 * 1024, $report['peak_memory_bytes'] );
		// STDERR keeps the structured measurement outside PHPUnit's buffered test output.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		fwrite( STDERR, 'FORMSTEAD_BENCHMARK ' . wp_json_encode( $report ) . "\n" );
	}

	/**
	 * Thirty samples of a bounded operation. Fixture ages are identical across batches.
	 *
	 * @param callable(): void $operation Operation to time.
	 * @return array{median_seconds: float, p95_seconds: float, queries: int}
	 */
	private function measure( callable $operation ): array {
		global $wpdb;
		$times  = array();
		$before = $wpdb->num_queries;
		for ( $i = 0; $i < 30; ++$i ) {
			$start = microtime( true );
			$operation();
			$times[] = microtime( true ) - $start;
		}
		sort( $times );
		return array(
			'median_seconds' => $times[14],
			'p95_seconds'    => $times[28],
			'queries'        => $wpdb->num_queries - $before,
		);
	}

	/**
	 * Deterministic distribution, synthetic addresses, no scheduled external delivery.
	 */
	private function seed(): void {
		global $wpdb;
		for ( $i = 0; $i < 10; ++$i ) {
			$form = ( new FormRepository() )->insert( new Form( 0, '', 'Performance ' . $i, true, array( new Field( 'email', 'Email', FieldType::Email, true, 255 ), new Field( 'note', 'Note', FieldType::Text, false, 512 ) ), 1, 'https://example.com/thanks', '', '', null, 0, null, 9 === $i ? 0 : 30, array( 'email' ) ) );
			$this->assertInstanceOf( Form::class, $form );
			$this->forms[] = $form->id();
		}
		$table = Schema::submissions_table();
		$old   = gmdate( 'Y-m-d H:i:s', time() - 45 * DAY_IN_SECONDS );
		$fresh = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		for ( $batch = 0; $batch < 100; ++$batch ) {
			$values = array();
			for ( $i = 0; $i < 1000; ++$i ) {
				$n        = $batch * 1000 + $i;
				$form_id  = $this->forms[ $n % 10 < 7 ? 0 : 1 + $n % 9 ];
				$payload  = wp_json_encode(
					array(
						'email' => 0 === $n % 100 ? 'rare@example.com' : 'common@example.com',
						'note'  => str_repeat( 's', 480 ),
					)
				);
				$values[] = $wpdb->prepare( '(%d,1,%s,%s,%s,%s)', $form_id, ReceiptId::generate(), $payload, array( 'new', 'read', 'spam', 'archived' )[ $n % 4 ], $n % 10 < 6 ? $old : $fresh );
			}
			$this->assertSame( 1000, $wpdb->query( "INSERT INTO {$table} (form_id,schema_version,receipt_id,payload,status,created_at) VALUES " . implode( ',', $values ) ) );
		}
		$ids                    = implode( ',', $this->forms );
		$this->first_submission = (int) $wpdb->get_var( "SELECT MIN(id) FROM {$table} WHERE form_id IN ({$ids})" );
		$this->last_submission  = (int) $wpdb->get_var( "SELECT MAX(id) FROM {$table} WHERE form_id IN ({$ids})" );
		$rare                   = IdentityHasher::hash( 'rare@example.com' );
		$common                 = IdentityHasher::hash( 'common@example.com' );
		$wpdb->query( $wpdb->prepare( 'INSERT INTO ' . Schema::identities_table() . " (submission_id,form_id,field,value_hash,created_at) SELECT id,form_id,'email',CASE WHEN payload LIKE %s THEN %s ELSE %s END,created_at FROM {$table} WHERE form_id IN ({$ids})", '%' . $wpdb->esc_like( 'rare@example.com' ) . '%', $rare, $common ) );
		$wpdb->query( 'INSERT INTO ' . Schema::deliveries_table() . " (submission_id,channel,status,next_attempt_at,created_at,updated_at) SELECT id,'email','pending',created_at,created_at,created_at FROM {$table} WHERE form_id IN ({$ids})" );
		$wpdb->query( 'INSERT INTO ' . Schema::idempotency_keys_table() . " (form_id,key_hash,body_hash,submission_id,expires_at) SELECT form_id,SHA2(CONCAT('key',id),256),SHA2(payload,256),id,UNIX_TIMESTAMP(created_at)+86400 FROM {$table} WHERE form_id IN ({$ids})" );
	}
}
