<?php
/**
 * Tests for delivery tasks.
 *
 * @package Formstead
 */

namespace Formstead\Tests\Delivery;

use Formstead\Cli\DeliveriesCommand;
use Formstead\Database\Schema;
use Formstead\Delivery\Channel;
use Formstead\Delivery\DeliveryCanceller;
use Formstead\Delivery\DeliveryRepository;
use Formstead\Delivery\DeliveryStatus;
use Formstead\Delivery\DeliveryWorker;
use Formstead\Delivery\Scheduler;
use Formstead\Forms\Field;
use Formstead\Forms\FieldType;
use Formstead\Forms\Form;
use Formstead\Forms\FormNotifications;
use Formstead\Forms\FormRepository;
use Formstead\Lifecycle\Deactivator;
use Formstead\Security\OriginPolicy;
use Formstead\Submissions\Submission;
use Formstead\Submissions\SubmissionRepository;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * Claims, retries, and cancellation of email deliveries.
 *
 * @phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
 * @phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
 * @phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
 */
final class DeliveryWorkerTest extends WP_UnitTestCase {

	/**
	 * Forms repository.
	 *
	 * @var FormRepository
	 */
	private FormRepository $forms;

	/**
	 * Create the repository.
	 */
	public function set_up(): void {
		parent::set_up();
		OriginPolicy::reset();
		$this->forms = new FormRepository();
		rest_get_server();
	}

	/**
	 * A form with recipients stores the submission and one pending delivery.
	 */
	public function test_submission_and_delivery_are_stored_together(): void {
		$form     = $this->save_form( array( 'owner@example.com' ) );
		$response = $this->post( $form->public_id() );

		$this->assertSame( 303, $response->get_status() );
		$submission = $this->only_submission();
		$deliveries = ( new DeliveryRepository() )->for_submission( $submission->id() );
		$this->assertCount( 1, $deliveries );
		$this->assertSame( DeliveryStatus::PENDING, $deliveries[0]->status() );
		$this->assertSame( 'email', $deliveries[0]->channel() );
		$this->assertNotFalse( wp_next_scheduled( Scheduler::SEND, array( $deliveries[0]->id() ) ) );
	}

	/**
	 * A failed delivery insert leaves no submission.
	 */
	public function test_delivery_failure_rolls_back_the_submission(): void {
		$form  = $this->save_form( array( 'owner@example.com' ) );
		$table = Schema::deliveries_table();
		$block = static function ( $query ) use ( $table ) {
			if ( is_string( $query ) && str_contains( $query, 'INSERT' ) && str_contains( $query, $table ) ) {
				return str_replace( $table, $table . '_missing', $query );
			}
			return $query;
		};

		add_filter( 'query', $block );
		try {
			$response = $this->post( $form->public_id() );
		} finally {
			remove_filter( 'query', $block );
		}

		$this->assertSame( 500, $response->get_status() );
		$this->assertSame( 0, $this->submission_count() );
		$this->assertSame( 0, $this->delivery_count() );
	}

	/**
	 * A form with no recipients stores the submission and no delivery.
	 */
	public function test_form_without_recipients_stores_no_delivery(): void {
		$form = $this->save_form( array() );
		$this->post( $form->public_id() );

		$this->assertSame( 1, $this->submission_count() );
		$this->assertSame( 0, $this->delivery_count() );
	}

	/**
	 * A second claim does not take a lock that is still current.
	 */
	public function test_second_claim_leaves_a_live_lock(): void {
		$delivery   = $this->pending_delivery();
		$repository = new DeliveryRepository();

		$first = $repository->claim( $delivery );
		$again = $repository->claim( $delivery );

		$this->assertNotNull( $first );
		$this->assertNull( $again );
		$loaded = $repository->find( $delivery );
		$this->assertNotNull( $loaded );
		$this->assertSame( 1, $loaded->attempts() );
		$this->assertSame( DeliveryStatus::PROCESSING, $loaded->status() );
	}

	/**
	 * A lock that has expired can be claimed again.
	 */
	public function test_abandoned_lock_is_claimed(): void {
		global $wpdb;

		$delivery   = $this->pending_delivery();
		$repository = new DeliveryRepository();
		$channel    = new RecordingChannel();
		$wpdb->update(
			Schema::deliveries_table(),
			array(
				'status'       => DeliveryStatus::PROCESSING,
				'attempts'     => 1,
				'locked_until' => '2000-01-01 00:00:00',
				'lock_token'   => 'stale',
			),
			array( 'id' => $delivery ),
			array( '%s', '%d', '%s', '%s' ),
			array( '%d' )
		);

		( new DeliveryWorker( $channel, $repository ) )->send( $delivery );

		$loaded = $repository->find( $delivery );
		$this->assertNotNull( $loaded );
		$this->assertSame( DeliveryStatus::HANDED_OFF, $loaded->status() );
		$this->assertSame( 2, $loaded->attempts() );
		$this->assertSame( 1, $channel->calls );
	}

	/**
	 * An abandoned lock on the last allowed attempt is still sent once.
	 */
	public function test_abandoned_lock_on_the_last_attempt_is_sent(): void {
		global $wpdb;

		$delivery   = $this->pending_delivery();
		$repository = new DeliveryRepository();
		$channel    = new RecordingChannel();
		$wpdb->update(
			Schema::deliveries_table(),
			array(
				'status'       => DeliveryStatus::PROCESSING,
				'attempts'     => DeliveryRepository::MAX_ATTEMPTS - 1,
				'locked_until' => '2000-01-01 00:00:00',
				'lock_token'   => 'stale',
			),
			array( 'id' => $delivery ),
			array( '%s', '%d', '%s', '%s' ),
			array( '%d' )
		);

		( new DeliveryWorker( $channel, $repository ) )->send( $delivery );

		$loaded = $repository->find( $delivery );
		$this->assertNotNull( $loaded );
		$this->assertSame( DeliveryStatus::HANDED_OFF, $loaded->status() );
		$this->assertSame( DeliveryRepository::MAX_ATTEMPTS, $loaded->attempts() );
		$this->assertSame( 1, $channel->calls );
	}

	/**
	 * A live claim at the attempt limit stays with the worker that holds it.
	 */
	public function test_live_lock_at_the_attempt_limit_is_not_closed(): void {
		global $wpdb;

		$delivery   = $this->pending_delivery();
		$repository = new DeliveryRepository();
		$channel    = new RecordingChannel();
		$wpdb->update(
			Schema::deliveries_table(),
			array(
				'status'       => DeliveryStatus::PROCESSING,
				'attempts'     => DeliveryRepository::MAX_ATTEMPTS,
				'locked_until' => gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ),
				'lock_token'   => 'held',
			),
			array( 'id' => $delivery ),
			array( '%s', '%d', '%s', '%s' ),
			array( '%d' )
		);

		( new DeliveryWorker( $channel, $repository ) )->send( $delivery );

		$loaded = $repository->find( $delivery );
		$this->assertNotNull( $loaded );
		$this->assertSame( DeliveryStatus::PROCESSING, $loaded->status() );
		$this->assertSame( 'held', $loaded->lock_token() );
		$this->assertSame( DeliveryRepository::MAX_ATTEMPTS, $loaded->attempts() );
		$this->assertSame( 0, $channel->calls );
	}

	/**
	 * A claim abandoned after the attempt limit is failed and not sent again.
	 */
	public function test_abandoned_claim_past_the_limit_is_failed_without_sending(): void {
		global $wpdb;

		$delivery   = $this->pending_delivery();
		$repository = new DeliveryRepository();
		$channel    = new RecordingChannel();
		$wpdb->update(
			Schema::deliveries_table(),
			array(
				'status'       => DeliveryStatus::PROCESSING,
				'attempts'     => DeliveryRepository::MAX_ATTEMPTS,
				'locked_until' => '2000-01-01 00:00:00',
				'lock_token'   => 'stale',
			),
			array( 'id' => $delivery ),
			array( '%s', '%d', '%s', '%s' ),
			array( '%d' )
		);

		( new DeliveryWorker( $channel, $repository ) )->send( $delivery );

		$loaded = $repository->find( $delivery );
		$this->assertNotNull( $loaded );
		$this->assertSame( DeliveryStatus::FAILED, $loaded->status() );
		$this->assertSame( 'abandoned', $loaded->last_error() );
		$this->assertSame( DeliveryRepository::MAX_ATTEMPTS, $loaded->attempts() );
		$this->assertSame( '', $loaded->lock_token() );
		$this->assertSame( 0, $channel->calls );

		( new DeliveryWorker( $channel, $repository ) )->send( $delivery );
		$this->assertSame( 0, $channel->calls );
	}

	/**
	 * The fifth failure is final, and a later run does not send again.
	 */
	public function test_retries_stop_after_five_failures(): void {
		global $wpdb;

		$delivery       = $this->pending_delivery();
		$repository     = new DeliveryRepository();
		$channel        = new RecordingChannel();
		$channel->error = 'mail_failed';
		$worker         = new DeliveryWorker( $channel, $repository );
		$table          = Schema::deliveries_table();

		for ( $attempt = 0; $attempt < 5; $attempt++ ) {
			$wpdb->update(
				$table,
				array( 'next_attempt_at' => '2000-01-01 00:00:00' ),
				array( 'id' => $delivery ),
				array( '%s' ),
				array( '%d' )
			);
			$worker->send( $delivery );
		}

		$loaded = $repository->find( $delivery );
		$this->assertNotNull( $loaded );
		$this->assertSame( DeliveryStatus::FAILED, $loaded->status() );
		$this->assertSame( 'mail_failed', $loaded->last_error() );
		$this->assertSame( 5, $loaded->attempts() );
		$this->assertSame( 5, $channel->calls );

		$worker->send( $delivery );
		$this->assertSame( 5, $channel->calls );
	}

	/**
	 * The first failure waits a minute and keeps the delivery pending.
	 */
	public function test_first_failure_retries_after_one_minute(): void {
		$delivery       = $this->pending_delivery();
		$repository     = new DeliveryRepository();
		$channel        = new RecordingChannel();
		$channel->error = 'mail_failed';

		( new DeliveryWorker( $channel, $repository ) )->send( $delivery );

		$loaded = $repository->find( $delivery );
		$this->assertNotNull( $loaded );
		$this->assertSame( DeliveryStatus::PENDING, $loaded->status() );
		$this->assertSame( 'mail_failed', $loaded->last_error() );
		$next = strtotime( $loaded->next_attempt_at() . ' UTC' );
		$this->assertIsInt( $next );
		$this->assertEqualsWithDelta( time() + MINUTE_IN_SECONDS, $next, 5 );
	}

	/**
	 * An exception from the channel is stored as a code, not the message.
	 */
	public function test_channel_exception_is_stored_as_a_code(): void {
		$delivery       = $this->pending_delivery();
		$repository     = new DeliveryRepository();
		$channel        = new RecordingChannel();
		$channel->throw = true;

		( new DeliveryWorker( $channel, $repository ) )->send( $delivery );

		$loaded = $repository->find( $delivery );
		$this->assertNotNull( $loaded );
		$this->assertSame( DeliveryStatus::PENDING, $loaded->status() );
		$this->assertSame( 'mail_exception', $loaded->last_error() );
		$this->assertStringNotContainsString( 'secret@example.com', $loaded->last_error() );
	}

	/**
	 * A delivery whose submission is gone is cancelled and not sent.
	 */
	public function test_missing_submission_is_cancelled_without_sending(): void {
		$repository = new DeliveryRepository();
		$created    = $repository->create( 999999 );
		$this->assertIsInt( $created );
		$channel = new RecordingChannel();

		( new DeliveryWorker( $channel, $repository ) )->send( $created );

		$loaded = $repository->find( $created );
		$this->assertNotNull( $loaded );
		$this->assertSame( DeliveryStatus::CANCELLED, $loaded->status() );
		$this->assertSame( 'submission_missing', $loaded->last_error() );
		$this->assertSame( 0, $channel->calls );
	}

	/**
	 * A form that no longer has recipients cancels the delivery without sending.
	 */
	public function test_form_without_recipients_cancels_without_sending(): void {
		global $wpdb;

		$submission = $this->store_submission();
		$repository = new DeliveryRepository();
		$created    = $repository->create( $submission->id() );
		$this->assertIsInt( $created );
		$wpdb->update(
			Schema::forms_table(),
			array( 'notify_recipients' => null ),
			array( 'id' => $submission->form_id() )
		);
		$channel = new RecordingChannel();

		( new DeliveryWorker( $channel, $repository ) )->send( $created );

		$loaded = $repository->find( $created );
		$this->assertNotNull( $loaded );
		$this->assertSame( DeliveryStatus::CANCELLED, $loaded->status() );
		$this->assertSame( 'no_recipients', $loaded->last_error() );
		$this->assertSame( 0, $channel->calls );
	}

	/**
	 * Open deliveries are cancelled. A handed-off delivery is left alone.
	 */
	public function test_cancel_for_submissions_cancels_open_deliveries(): void {
		global $wpdb;

		$submission = $this->store_submission();
		$repository = new DeliveryRepository();
		$pending    = $repository->create( $submission->id() );
		$processing = $repository->create( $submission->id() );
		$done       = $repository->create( $submission->id() );
		$this->assertIsInt( $pending );
		$this->assertIsInt( $processing );
		$this->assertIsInt( $done );

		$table = Schema::deliveries_table();
		$wpdb->update( $table, array( 'status' => DeliveryStatus::PROCESSING ), array( 'id' => $processing ) );
		$wpdb->update( $table, array( 'status' => DeliveryStatus::HANDED_OFF ), array( 'id' => $done ) );

		( new DeliveryCanceller() )->cancel_for_submissions( array( $submission->id() ) );

		$this->assertSame( DeliveryStatus::CANCELLED, $repository->find( $pending )->status() );
		$this->assertSame( DeliveryStatus::CANCELLED, $repository->find( $processing )->status() );
		$this->assertSame( DeliveryStatus::HANDED_OFF, $repository->find( $done )->status() );
	}

	/**
	 * Deactivation removes the immediate send and the sweep.
	 */
	public function test_deactivation_clears_scheduled_events(): void {
		Scheduler::ensure_sweep();
		Scheduler::schedule( 4 );
		$this->assertNotFalse( wp_next_scheduled( Scheduler::SWEEP ) );
		$this->assertNotFalse( wp_next_scheduled( Scheduler::SEND, array( 4 ) ) );

		Deactivator::deactivate();

		$this->assertFalse( wp_next_scheduled( Scheduler::SWEEP ) );
		$this->assertFalse( wp_next_scheduled( Scheduler::SEND, array( 4 ) ) );
	}

	/**
	 * The command claims due deliveries through the filtered channel.
	 */
	public function test_cli_processes_due_deliveries(): void {
		$delivery = $this->pending_delivery();
		$channel  = new RecordingChannel();
		$filter   = static function () use ( $channel ): Channel {
			return $channel;
		};

		add_filter( 'formstead_delivery_channel', $filter );
		try {
			$processed = DeliveriesCommand::process( array( 'limit' => '20' ) );
		} finally {
			remove_filter( 'formstead_delivery_channel', $filter );
		}

		$this->assertSame( 1, $processed );
		$this->assertSame( 1, $channel->calls );
		$loaded = ( new DeliveryRepository() )->find( $delivery );
		$this->assertNotNull( $loaded );
		$this->assertSame( DeliveryStatus::HANDED_OFF, $loaded->status() );
	}

	/**
	 * Store a submission and a pending delivery.
	 */
	private function pending_delivery(): int {
		$submission = $this->store_submission();
		$created    = ( new DeliveryRepository() )->create( $submission->id() );
		$this->assertIsInt( $created );
		return $created;
	}

	/**
	 * Store one submission.
	 */
	private function store_submission(): Submission {
		$form  = $this->save_form( array( 'owner@example.com' ) );
		$saved = ( new SubmissionRepository() )->insert( $form, array( 'email' => 'a@b.co' ) );
		$this->assertInstanceOf( Submission::class, $saved );
		return $saved;
	}

	/**
	 * Persist a contact form.
	 *
	 * @param string[] $recipients Notification recipients.
	 */
	private function save_form( array $recipients ): Form {
		$saved = $this->forms->insert(
			new Form(
				0,
				'',
				'Contact',
				true,
				array( new Field( 'email', 'Email', FieldType::Email, true, 255 ) ),
				1,
				'https://example.com/thanks',
				'',
				'',
				null,
				0,
				new FormNotifications( $recipients, 'email', false )
			)
		);
		$this->assertInstanceOf( Form::class, $saved );
		return $saved;
	}

	/**
	 * Post one valid submission.
	 *
	 * @param string $public_id Public form id.
	 */
	private function post( string $public_id ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/formstead/v1/forms/' . $public_id . '/submissions' );
		$request->set_header( 'Content-Type', 'application/x-www-form-urlencoded' );
		$request->set_body( 'email=a@b.co' );
		$request->set_body_params( array( 'email' => 'a@b.co' ) );
		$response = rest_do_request( $request );
		$this->assertInstanceOf( WP_REST_Response::class, $response );
		return $response;
	}

	/**
	 * The only stored submission.
	 */
	private function only_submission(): Submission {
		global $wpdb;

		$table = Schema::submissions_table();
		$id    = (int) $wpdb->get_var( "SELECT id FROM {$table} ORDER BY id ASC LIMIT 1" );
		$found = ( new SubmissionRepository() )->find( $id );
		$this->assertInstanceOf( Submission::class, $found );
		return $found;
	}

	/**
	 * How many submissions are stored.
	 */
	private function submission_count(): int {
		global $wpdb;

		$table = Schema::submissions_table();
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	/**
	 * How many deliveries are stored.
	 */
	private function delivery_count(): int {
		global $wpdb;

		$table = Schema::deliveries_table();
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}
}
