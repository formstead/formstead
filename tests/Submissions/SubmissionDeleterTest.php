<?php
/**
 * Tests for permanent deletion.
 *
 * @package Formstead
 */

namespace Formstead\Tests\Submissions;

use Formstead\Database\Schema;
use Formstead\Delivery\DeliveryRepository;
use Formstead\Delivery\DeliveryStatus;
use Formstead\Delivery\DeliveryWorker;
use Formstead\Forms\Field;
use Formstead\Forms\FieldType;
use Formstead\Forms\Form;
use Formstead\Forms\FormNotifications;
use Formstead\Forms\FormRepository;
use Formstead\Submissions\IdempotencyKey;
use Formstead\Submissions\Submission;
use Formstead\Submissions\SubmissionDeleter;
use Formstead\Submissions\SubmissionIntake;
use Formstead\Submissions\SubmissionRepository;
use Formstead\Tests\Delivery\RecordingChannel;
use WP_Error;

/**
 * Deletion cancels open deliveries and frees the idempotency key.
 *
 * @phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
 * @phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
 * @phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
 */
final class SubmissionDeleterTest extends \WP_UnitTestCase {

	/**
	 * Deleting a submission cancels its pending delivery and removes its idempotency key.
	 */
	public function test_delete_cancels_the_delivery_and_frees_the_key(): void {
		$form   = $this->form( array( 'owner@example.com' ) );
		$intake = new SubmissionIntake();
		$first  = $intake->accept( $form, array( 'note' => 'hello' ), IdempotencyKey::read( $this->request( 'same-key' ), array() ) );
		$saved  = $first->submission();
		$this->assertInstanceOf( Submission::class, $saved );
		$this->assertNotNull( $first->delivery_id() );

		$deleted = ( new SubmissionDeleter() )->delete( array( $saved->id() ) );
		$this->assertSame( 1, $deleted );
		$this->assertNull( ( new SubmissionRepository() )->find( $saved->id() ) );

		$delivery = ( new DeliveryRepository() )->find( (int) $first->delivery_id() );
		$this->assertNotNull( $delivery );
		$this->assertSame( DeliveryStatus::CANCELLED, $delivery->status() );

		$worker = new DeliveryWorker( new RecordingChannel() );
		$worker->send( (int) $first->delivery_id() );
		$delivery = ( new DeliveryRepository() )->find( (int) $first->delivery_id() );
		$this->assertNotNull( $delivery );
		$this->assertSame( DeliveryStatus::CANCELLED, $delivery->status() );

		$again = $intake->accept( $form, array( 'note' => 'hello' ), IdempotencyKey::read( $this->request( 'same-key' ), array() ) );
		$this->assertTrue( $again->is_accepted() );
		$this->assertNotSame( $saved->receipt_id(), $again->submission()->receipt_id() );
	}

	/**
	 * A key whose submission row is already gone is reused instead of failing.
	 */
	public function test_replay_of_a_missing_submission_stores_a_new_one(): void {
		global $wpdb;

		$form   = $this->form( array() );
		$intake = new SubmissionIntake();
		$key    = IdempotencyKey::read( $this->request( 'race-key' ), array() );
		$first  = $intake->accept( $form, array( 'note' => 'hello' ), $key );
		$saved  = $first->submission();
		$this->assertInstanceOf( Submission::class, $saved );

		$table = Schema::submissions_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is an identifier.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id = %d", $saved->id() ) );

		$again = $intake->accept( $form, array( 'note' => 'hello' ), IdempotencyKey::read( $this->request( 'race-key' ), array() ) );
		$this->assertTrue( $again->is_accepted() );
		$this->assertInstanceOf( Submission::class, $again->submission() );
		$this->assertNotSame( $saved->id(), $again->submission()->id() );
	}

	/**
	 * A failed delete rolls back the delivery cancellation.
	 */
	public function test_failed_delete_keeps_the_delivery_pending(): void {
		global $wpdb;

		$form   = $this->form( array( 'owner@example.com' ) );
		$intake = new SubmissionIntake();
		$first  = $intake->accept( $form, array( 'note' => 'hello' ), null );
		$saved  = $first->submission();
		$this->assertInstanceOf( Submission::class, $saved );

		$table    = Schema::submissions_table();
		$previous = $wpdb->suppress_errors( true );
		$filter   = static function ( $query ) use ( $table ) {
			if ( is_string( $query ) && str_contains( $query, 'DELETE FROM' ) && str_contains( $query, $table ) ) {
				return 'DELETE FROM ' . $table . '_missing WHERE id = 1';
			}
			return $query;
		};
		add_filter( 'query', $filter );
		$deleted = ( new SubmissionDeleter() )->delete( array( $saved->id() ) );
		remove_filter( 'query', $filter );
		$wpdb->suppress_errors( $previous );

		$this->assertInstanceOf( WP_Error::class, $deleted );
		$this->assertNotNull( ( new SubmissionRepository() )->find( $saved->id() ) );
		$delivery = ( new DeliveryRepository() )->find( (int) $first->delivery_id() );
		$this->assertNotNull( $delivery );
		$this->assertSame( DeliveryStatus::PENDING, $delivery->status() );
	}

	/**
	 * Persist a one-field form.
	 *
	 * @param string[] $recipients Notification recipients.
	 */
	private function form( array $recipients ): Form {
		$saved = ( new FormRepository() )->insert(
			new Form(
				0,
				'',
				'Contact',
				true,
				array( new Field( 'note', 'Note', FieldType::Textarea, false, 1000 ) ),
				1,
				'https://example.com/thanks',
				'',
				'',
				null,
				0,
				new FormNotifications( $recipients )
			)
		);
		$this->assertInstanceOf( Form::class, $saved );
		return $saved;
	}

	/**
	 * Request carrying an idempotency key header.
	 *
	 * @param string $key Raw key.
	 */
	private function request( string $key ): \WP_REST_Request {
		$request = new \WP_REST_Request( 'POST', '/formstead/v1/forms/fs_aaaaaaaaaaaaaaaa/submissions' );
		$request->set_header( 'Idempotency-Key', $key );
		return $request;
	}
}
