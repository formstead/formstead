<?php
/**
 * Tests for retention of deliveries, keys, and counters.
 *
 * @package Formstead
 */

namespace Formstead\Tests\Retention;

use Formstead\Database\Schema;
use Formstead\Delivery\DeliveryRepository;
use Formstead\Delivery\DeliveryStatus;
use Formstead\Forms\Field;
use Formstead\Forms\FieldType;
use Formstead\Forms\Form;
use Formstead\Forms\FormRepository;
use Formstead\Retention\RetentionRunner;
use Formstead\Submissions\Submission;
use Formstead\Submissions\SubmissionRepository;

/**
 * Finished deliveries, expired keys, and expired counters are removed.
 * Pending and processing deliveries stay.
 *
 * @phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
 * @phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
 * @phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
 */
final class RetentionHousekeepingTest extends \WP_UnitTestCase {

	/**
	 * Drop filters and options this class writes.
	 */
	public function tear_down(): void {
		remove_all_filters( 'formstead_retention_periods' );
		delete_option( RetentionRunner::LOCK_OPTION );
		delete_option( RetentionRunner::REPORT_OPTION );
		parent::tear_down();
	}

	/**
	 * Only finished deliveries older than the period are deleted.
	 */
	public function test_finished_deliveries_expire_and_open_ones_stay(): void {
		add_filter(
			'formstead_retention_periods',
			static function ( $periods ) {
				if ( ! is_array( $periods ) ) {
					return $periods;
				}
				$periods['delivery_days'] = 2;
				return $periods;
			}
		);

		$now        = time();
		$submission = $this->submission();
		$repository = new DeliveryRepository();
		$pending    = $repository->create( $submission->id() );
		$processing = $repository->create( $submission->id() );
		$old_done   = $repository->create( $submission->id() );
		$exact      = $repository->create( $submission->id() );
		$recent     = $repository->create( $submission->id() );
		$failed     = $repository->create( $submission->id() );
		$cancelled  = $repository->create( $submission->id() );
		$this->assertIsInt( $pending );
		$this->assertIsInt( $processing );
		$this->assertIsInt( $old_done );
		$this->assertIsInt( $exact );
		$this->assertIsInt( $recent );
		$this->assertIsInt( $failed );
		$this->assertIsInt( $cancelled );

		$old = $now - ( 3 * DAY_IN_SECONDS );
		$this->stamp( $pending, DeliveryStatus::PENDING, $old );
		$this->stamp( $processing, DeliveryStatus::PROCESSING, $old );
		$this->stamp( $old_done, DeliveryStatus::HANDED_OFF, $old );
		$this->stamp( $exact, DeliveryStatus::HANDED_OFF, $now - ( 2 * DAY_IN_SECONDS ) );
		$this->stamp( $recent, DeliveryStatus::HANDED_OFF, $now - DAY_IN_SECONDS );
		$this->stamp( $failed, DeliveryStatus::FAILED, $old );
		$this->stamp( $cancelled, DeliveryStatus::CANCELLED, $old );

		$report = ( new RetentionRunner() )->run( null, null, $now );

		$this->assertIsArray( $report );
		$this->assertSame( 3, $report['deliveries'] );
		$this->assertNotNull( $repository->find( $pending ) );
		$this->assertNotNull( $repository->find( $processing ) );
		$this->assertNotNull( $repository->find( $exact ) );
		$this->assertNotNull( $repository->find( $recent ) );
		$this->assertNull( $repository->find( $old_done ) );
		$this->assertNull( $repository->find( $failed ) );
		$this->assertNull( $repository->find( $cancelled ) );
	}

	/**
	 * Expired idempotency keys are deleted and a current key stays.
	 */
	public function test_expired_idempotency_keys_are_deleted(): void {
		global $wpdb;

		$now        = time();
		$submission = $this->submission();
		$table      = Schema::idempotency_keys_table();
		$expired    = $this->key_row( $submission, $now - 10, 'a' );
		$current    = $this->key_row( $submission, $now + 1000, 'b' );

		$report = ( new RetentionRunner() )->run( null, null, $now );

		$this->assertIsArray( $report );
		$this->assertSame( 1, $report['keys'] );
		$this->assertNull( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE id = %d", $expired ) ) );
		$this->assertNotNull( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE id = %d", $current ) ) );
	}

	/**
	 * Expired rate-limit counters are deleted. A counter at the exact cutoff stays.
	 */
	public function test_expired_rate_limits_are_deleted(): void {
		global $wpdb;

		$now   = time();
		$table = Schema::rate_limits_table();
		$old   = hash( 'sha256', 'old-counter' );
		$exact = hash( 'sha256', 'exact-counter' );
		$wpdb->insert(
			$table,
			array(
				'bucket'     => $old,
				'hits'       => 1,
				'expires_at' => $now - 1,
			),
			array( '%s', '%d', '%d' )
		);
		$wpdb->insert(
			$table,
			array(
				'bucket'     => $exact,
				'hits'       => 1,
				'expires_at' => $now,
			),
			array( '%s', '%d', '%d' )
		);

		$report = ( new RetentionRunner() )->run( null, null, $now );

		$this->assertIsArray( $report );
		$this->assertSame( 1, $report['rate_limits'] );
		$this->assertNull( $wpdb->get_var( $wpdb->prepare( "SELECT bucket FROM {$table} WHERE bucket = %s", $old ) ) );
		$this->assertSame( $exact, $wpdb->get_var( $wpdb->prepare( "SELECT bucket FROM {$table} WHERE bucket = %s", $exact ) ) );
	}

	/**
	 * Store a submission on a form that keeps rows until someone deletes them.
	 */
	private function submission(): Submission {
		$saved = ( new FormRepository() )->insert(
			new Form(
				0,
				'',
				'Contact',
				true,
				array( new Field( 'note', 'Note', FieldType::Text, false, 20 ) ),
				1,
				'https://example.com/thanks',
				'',
				'',
				null,
				0,
				null,
				0
			)
		);
		$this->assertInstanceOf( Form::class, $saved );

		$submission = ( new SubmissionRepository() )->insert( $saved, array( 'note' => 'hello' ) );
		$this->assertInstanceOf( Submission::class, $submission );
		return $submission;
	}

	/**
	 * Set a delivery status and the time it was last changed.
	 *
	 * @param int    $id        Delivery id.
	 * @param string $status    Delivery status.
	 * @param int    $timestamp Unix time.
	 */
	private function stamp( int $id, string $status, int $timestamp ): void {
		global $wpdb;

		$wpdb->update(
			Schema::deliveries_table(),
			array(
				'status'     => $status,
				'updated_at' => gmdate( 'Y-m-d H:i:s', $timestamp ),
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Insert one idempotency key and return its id.
	 *
	 * @param Submission $submission Submission the key points at.
	 * @param int        $expires    Unix expiry.
	 * @param string     $mark       Character repeated into the hashes.
	 */
	private function key_row( Submission $submission, int $expires, string $mark ): int {
		global $wpdb;

		$wpdb->insert(
			Schema::idempotency_keys_table(),
			array(
				'form_id'       => $submission->form_id(),
				'key_hash'      => str_repeat( $mark, 64 ),
				'body_hash'     => str_repeat( $mark, 64 ),
				'submission_id' => $submission->id(),
				'expires_at'    => $expires,
			),
			array( '%d', '%s', '%s', '%d', '%d' )
		);

		return (int) $wpdb->insert_id;
	}
}
