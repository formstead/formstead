<?php
/**
 * Tests for retention.
 *
 * @package Formstead
 */

namespace Formstead\Tests\Retention;

use Formstead\Cli\RetentionCommand;
use Formstead\Database\Schema;
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
use Formstead\Retention\RetentionPeriods;
use Formstead\Retention\RetentionRunner;
use Formstead\Retention\RetentionScheduler;
use Formstead\Submissions\IdempotencyKey;
use Formstead\Submissions\Submission;
use Formstead\Submissions\SubmissionIntake;
use Formstead\Submissions\SubmissionRepository;
use Formstead\Submissions\SubmissionStatus;
use Formstead\Tests\Delivery\RecordingChannel;
use WP_REST_Request;

/**
 * Retention deletes only the rows that have passed their period.
 *
 * @phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
 * @phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
 * @phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
 */
final class RetentionRunnerTest extends \WP_UnitTestCase {

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
	 * Built-in periods are seven days for spam and thirty for finished deliveries.
	 */
	public function test_default_periods(): void {
		$this->assertSame( 7, RetentionPeriods::spam_days() );
		$this->assertSame( 30, RetentionPeriods::delivery_days() );
	}

	/**
	 * A filtered period is clamped to 1–3650 days.
	 */
	public function test_periods_are_clamped(): void {
		add_filter(
			'formstead_retention_periods',
			static function () {
				return array(
					'spam_days'     => 99999,
					'delivery_days' => 0,
				);
			}
		);

		$this->assertSame( 3650, RetentionPeriods::spam_days() );
		$this->assertSame( 1, RetentionPeriods::delivery_days() );
	}

	/**
	 * Each form expires on its own period. A row stored at the cutoff stays.
	 */
	public function test_only_the_expired_rows_of_each_form_are_deleted(): void {
		$now   = time();
		$short = $this->form( 1 );
		$long  = $this->form( 30 );
		$kept  = $this->form( 0 );

		$exact  = $this->submission( $short );
		$older  = $this->submission( $short );
		$recent = $this->submission( $long );
		$held   = $this->submission( $kept );
		$this->age( $exact->id(), $now - DAY_IN_SECONDS );
		$this->age( $older->id(), $now - DAY_IN_SECONDS - 1 );
		$this->age( $recent->id(), $now - ( 2 * DAY_IN_SECONDS ) );
		$this->age( $held->id(), $now - ( 100 * DAY_IN_SECONDS ) );

		$report = ( new RetentionRunner() )->run( null, null, $now );

		$this->assertIsArray( $report );
		$this->assertSame( 1, $report['submissions'] );
		$this->assertNull( ( new SubmissionRepository() )->find( $older->id() ) );
		$this->assertInstanceOf( Submission::class, ( new SubmissionRepository() )->find( $exact->id() ) );
		$this->assertInstanceOf( Submission::class, ( new SubmissionRepository() )->find( $recent->id() ) );
		$this->assertInstanceOf( Submission::class, ( new SubmissionRepository() )->find( $held->id() ) );
		$this->assertFalse( $report['backlog'] );
		$this->assertStringNotContainsString( 'secret-body', (string) wp_json_encode( $report ) );
		$this->assertFalse( get_option( RetentionRunner::LOCK_OPTION ) );
	}

	/**
	 * Spam is deleted after the shorter of the form period and the global period.
	 */
	public function test_spam_uses_the_shorter_period(): void {
		add_filter(
			'formstead_retention_periods',
			static function ( $periods ) {
				if ( ! is_array( $periods ) ) {
					return $periods;
				}
				$periods['spam_days'] = 2;
				return $periods;
			}
		);

		$now        = time();
		$form       = $this->form( 30 );
		$spam       = $this->submission( $form, SubmissionStatus::SPAM );
		$read       = $this->submission( $form, SubmissionStatus::READ );
		$fresh_spam = $this->submission( $form, SubmissionStatus::SPAM );
		$this->age( $spam->id(), $now - ( 3 * DAY_IN_SECONDS ) );
		$this->age( $read->id(), $now - ( 3 * DAY_IN_SECONDS ) );
		$this->age( $fresh_spam->id(), $now - DAY_IN_SECONDS );

		$report = ( new RetentionRunner() )->run( null, null, $now );

		$this->assertIsArray( $report );
		$this->assertSame( 1, $report['spam'] );
		$this->assertSame( 0, $report['submissions'] );
		$this->assertNull( ( new SubmissionRepository() )->find( $spam->id() ) );
		$this->assertInstanceOf( Submission::class, ( new SubmissionRepository() )->find( $read->id() ) );
		$this->assertInstanceOf( Submission::class, ( new SubmissionRepository() )->find( $fresh_spam->id() ) );
	}

	/**
	 * One batch leaves the rest, and a second run finishes them.
	 */
	public function test_a_short_batch_leaves_a_backlog(): void {
		$now  = time();
		$form = $this->form( 1 );
		$ids  = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$saved = $this->submission( $form );
			$this->age( $saved->id(), $now - ( 2 * DAY_IN_SECONDS ) );
			$ids[] = $saved->id();
		}

		$first = ( new RetentionRunner( 2 ) )->run( 1, null, $now );
		$this->assertIsArray( $first );
		$this->assertSame( 2, $first['submissions'] );
		$this->assertTrue( $first['backlog'] );
		$this->assertInstanceOf( Submission::class, ( new SubmissionRepository() )->find( $ids[2] ) );

		$second = ( new RetentionRunner( 2 ) )->run( null, null, $now );
		$this->assertIsArray( $second );
		$this->assertSame( 1, $second['submissions'] );
		$this->assertFalse( $second['backlog'] );
		$this->assertNull( ( new SubmissionRepository() )->find( $ids[2] ) );
	}

	/**
	 * A deadline that has already passed deletes nothing and records the backlog.
	 */
	public function test_a_passed_deadline_deletes_nothing(): void {
		$now   = time();
		$form  = $this->form( 1 );
		$saved = $this->submission( $form );
		$this->age( $saved->id(), $now - ( 2 * DAY_IN_SECONDS ) );

		$report = ( new RetentionRunner() )->run( 10, $now - 1, $now );

		$this->assertIsArray( $report );
		$this->assertSame( 0, $report['submissions'] );
		$this->assertTrue( $report['backlog'] );
		$this->assertInstanceOf( Submission::class, ( new SubmissionRepository() )->find( $saved->id() ) );
	}

	/**
	 * A lock held by another run leaves the rows and the previous report alone.
	 */
	public function test_a_held_lock_skips_the_run(): void {
		$now   = time();
		$form  = $this->form( 1 );
		$saved = $this->submission( $form );
		$this->age( $saved->id(), $now - ( 2 * DAY_IN_SECONDS ) );
		add_option( RetentionRunner::LOCK_OPTION, $now, '', false );
		update_option( RetentionRunner::REPORT_OPTION, array( 'marker' => 'kept' ), false );

		$this->assertNull( ( new RetentionRunner() )->run( null, null, $now ) );
		$this->assertInstanceOf( Submission::class, ( new SubmissionRepository() )->find( $saved->id() ) );
		$this->assertSame( array( 'marker' => 'kept' ), get_option( RetentionRunner::REPORT_OPTION ) );
	}

	/**
	 * Deleting an expired submission cancels its delivery, and the worker does not send it.
	 */
	public function test_retention_cancels_a_pending_delivery(): void {
		$now    = time();
		$form   = $this->form( 1, array( 'owner@example.com' ) );
		$intake = new SubmissionIntake();
		$stored = $intake->accept( $form, array( 'note' => 'secret-body' ), null );
		$saved  = $stored->submission();
		$this->assertInstanceOf( Submission::class, $saved );
		$delivery_id = (int) $stored->delivery_id();
		$this->assertGreaterThan( 0, $delivery_id );
		$this->age( $saved->id(), $now - ( 2 * DAY_IN_SECONDS ) );

		( new RetentionRunner() )->run( null, null, $now );

		$this->assertNull( ( new SubmissionRepository() )->find( $saved->id() ) );
		$delivery = ( new DeliveryRepository() )->find( $delivery_id );
		$this->assertNotNull( $delivery );
		$this->assertSame( DeliveryStatus::CANCELLED, $delivery->status() );

		$channel = new RecordingChannel();
		( new DeliveryWorker( $channel ) )->send( $delivery_id );
		$this->assertSame( 0, $channel->calls );
	}

	/**
	 * After retention, the same idempotency key stores a new submission.
	 */
	public function test_the_same_key_can_be_used_after_retention(): void {
		$now    = time();
		$form   = $this->form( 1 );
		$intake = new SubmissionIntake();
		$key    = IdempotencyKey::read( $this->request( 'same-key' ), array() );
		$first  = $intake->accept( $form, array( 'note' => 'secret-body' ), $key );
		$saved  = $first->submission();
		$this->assertInstanceOf( Submission::class, $saved );
		$this->age( $saved->id(), $now - ( 2 * DAY_IN_SECONDS ) );

		( new RetentionRunner() )->run( null, null, $now );

		$again = $intake->accept( $form, array( 'note' => 'secret-body' ), IdempotencyKey::read( $this->request( 'same-key' ), array() ) );
		$this->assertTrue( $again->is_accepted() );
		$this->assertInstanceOf( Submission::class, $again->submission() );
		$this->assertNotSame( $saved->receipt_id(), $again->submission()->receipt_id() );
	}

	/**
	 * A second run that still has work keeps the time the backlog started.
	 */
	public function test_a_backlog_keeps_its_start_time(): void {
		$now   = time();
		$since = $now - ( 25 * HOUR_IN_SECONDS );
		update_option(
			RetentionRunner::REPORT_OPTION,
			array(
				'backlog'       => true,
				'backlog_since' => $since,
			),
			false
		);
		$form  = $this->form( 1 );
		$saved = $this->submission( $form );
		$this->age( $saved->id(), $now - ( 2 * DAY_IN_SECONDS ) );

		$report = ( new RetentionRunner() )->run( 10, $now - 1, $now );

		$this->assertIsArray( $report );
		$this->assertTrue( $report['backlog'] );
		$this->assertSame( $since, $report['backlog_since'] );
	}

	/**
	 * The command deletes every expired row when no batch limit is given.
	 */
	public function test_cli_runs_until_nothing_is_left(): void {
		$now   = time();
		$form  = $this->form( 1 );
		$saved = $this->submission( $form );
		$this->age( $saved->id(), $now - ( 2 * DAY_IN_SECONDS ) );

		$report = RetentionCommand::process( array() );

		$this->assertIsArray( $report );
		$this->assertSame( 1, $report['submissions'] );
		$this->assertNull( ( new SubmissionRepository() )->find( $saved->id() ) );
	}

	/**
	 * A failed run is an error, and a backlog is a warning. Counts stay in the output.
	 */
	public function test_cli_messages_report_errors_and_a_backlog(): void {
		$report = array(
			'started_at'    => 1,
			'finished_at'   => 2,
			'submissions'   => 3,
			'spam'          => 4,
			'deliveries'    => 5,
			'keys'          => 6,
			'rate_limits'   => 7,
			'backlog'       => false,
			'backlog_since' => 0,
			'errors'        => array( 'submission_delete_failed' ),
		);

		$failed = RetentionCommand::messages( $report );
		$texts  = array_column( $failed, 'text' );
		$this->assertContains( 'error', array_column( $failed, 'level' ) );
		$this->assertContains( 'Deleted 3 submissions.', $texts );
		$this->assertContains( 'Deleted 4 spam.', $texts );
		$this->assertContains( 'Deleted 5 deliveries.', $texts );
		$this->assertContains( 'Deleted 6 idempotency keys.', $texts );
		$this->assertContains( 'Deleted 7 rate-limit counters.', $texts );
		$this->assertContains( 'Retention failed: submission_delete_failed', $texts );

		$report['errors']  = array();
		$report['backlog'] = true;
		$waiting           = RetentionCommand::messages( $report );
		$this->assertContains( 'warning', array_column( $waiting, 'level' ) );
		$this->assertNotContains( 'error', array_column( $waiting, 'level' ) );
		$this->assertContains( 'Expired rows are still waiting.', array_column( $waiting, 'text' ) );
	}

	/**
	 * A lock older than the TTL is taken over, including a plain timestamp from 0.6.0.
	 */
	public function test_a_stale_lock_is_taken_over(): void {
		$now   = time();
		$form  = $this->form( 1 );
		$saved = $this->submission( $form );
		$this->age( $saved->id(), $now - ( 2 * DAY_IN_SECONDS ) );
		add_option( RetentionRunner::LOCK_OPTION, $now - RetentionRunner::LOCK_TTL - 1, '', false );

		$report = ( new RetentionRunner() )->run( null, null, $now );

		$this->assertIsArray( $report );
		$this->assertSame( 1, $report['submissions'] );
		$this->assertNull( ( new SubmissionRepository() )->find( $saved->id() ) );
		$this->assertFalse( get_option( RetentionRunner::LOCK_OPTION ) );
	}

	/**
	 * Replacing the lock during a batch leaves the new owner's lock in place.
	 */
	public function test_a_run_does_not_clear_a_lock_it_no_longer_holds(): void {
		$now   = time();
		$form  = $this->form( 1 );
		$saved = $this->submission( $form );
		$this->age( $saved->id(), $now - ( 2 * DAY_IN_SECONDS ) );

		$stolen = false;
		$filter = static function ( $query ) use ( &$stolen ) {
			if ( $stolen || ! is_string( $query ) || ! str_contains( $query, 'DELETE FROM' ) || ! str_contains( $query, 'formstead_submissions' ) ) {
				return $query;
			}

			$stolen = true;
			update_option( RetentionRunner::LOCK_OPTION, 'foreign-token|' . time(), false );
			return $query;
		};
		add_filter( 'query', $filter );

		try {
			$report = ( new RetentionRunner() )->run( null, null, $now );
		} finally {
			remove_filter( 'query', $filter );
		}

		$this->assertTrue( $stolen );
		$this->assertIsArray( $report );
		$lock = get_option( RetentionRunner::LOCK_OPTION );
		$this->assertIsString( $lock );
		$this->assertStringStartsWith( 'foreign-token|', $lock );
	}

	/**
	 * Each batch moves the lock time forward so a long run keeps the lock.
	 */
	public function test_a_batch_refreshes_the_lock_time(): void {
		$now   = time();
		$form  = $this->form( 1 );
		$saved = $this->submission( $form );
		$this->age( $saved->id(), $now - ( 2 * DAY_IN_SECONDS ) );

		$rewritten = false;
		$on_added  = static function ( $name, $value ) use ( &$rewritten ): void {
			if ( RetentionRunner::LOCK_OPTION !== $name || $rewritten || ! is_string( $value ) ) {
				return;
			}

			$id = strstr( $value, '|', true );
			if ( ! is_string( $id ) || '' === $id ) {
				return;
			}

			$rewritten = true;
			update_option( RetentionRunner::LOCK_OPTION, $id . '|' . ( time() - 100 ), false );
		};
		$pairs     = array();
		$on_update = static function ( $old, $updated ) use ( &$pairs ): void {
			$pairs[] = array( (string) $old, (string) $updated );
		};
		add_action( 'added_option', $on_added, 10, 2 );
		add_action( 'update_option_' . RetentionRunner::LOCK_OPTION, $on_update, 10, 2 );

		try {
			$report = ( new RetentionRunner() )->run( null, null, $now );
		} finally {
			remove_action( 'added_option', $on_added, 10 );
			remove_action( 'update_option_' . RetentionRunner::LOCK_OPTION, $on_update, 10 );
		}

		$this->assertIsArray( $report );
		$this->assertNull( ( new SubmissionRepository() )->find( $saved->id() ) );
		$refreshed = false;
		foreach ( $pairs as $pair ) {
			$old_id = strstr( $pair[0], '|', true );
			$new_id = strstr( $pair[1], '|', true );
			$old_at = (int) substr( $pair[0], (int) strrpos( $pair[0], '|' ) + 1 );
			$new_at = (int) substr( $pair[1], (int) strrpos( $pair[1], '|' ) + 1 );
			if ( is_string( $old_id ) && $old_id === $new_id && $new_at > $old_at ) {
				$refreshed = true;
			}
		}
		$this->assertTrue( $refreshed );
	}

	/**
	 * Deactivation removes the retention event and the delivery events.
	 */
	public function test_deactivation_clears_the_retention_event(): void {
		RetentionScheduler::ensure();
		Scheduler::ensure_sweep();
		$this->assertNotFalse( wp_next_scheduled( RetentionScheduler::HOOK ) );
		$this->assertNotFalse( wp_next_scheduled( Scheduler::SWEEP ) );

		Deactivator::deactivate();

		$this->assertFalse( wp_next_scheduled( RetentionScheduler::HOOK ) );
		$this->assertFalse( wp_next_scheduled( Scheduler::SWEEP ) );
	}

	/**
	 * Persist a form with a retention period.
	 *
	 * @param int      $days       Days to keep submissions. Zero keeps them.
	 * @param string[] $recipients Notification recipients.
	 */
	private function form( int $days, array $recipients = array() ): Form {
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
				new FormNotifications( $recipients ),
				$days
			)
		);
		$this->assertInstanceOf( Form::class, $saved );
		return $saved;
	}

	/**
	 * Store one submission.
	 *
	 * @param Form   $form   Form that received it.
	 * @param string $status Inbox status.
	 */
	private function submission( Form $form, string $status = SubmissionStatus::NEW ): Submission {
		$saved = ( new SubmissionRepository() )->insert( $form, array( 'note' => 'secret-body' ), $status );
		$this->assertInstanceOf( Submission::class, $saved );
		return $saved;
	}

	/**
	 * Move a submission to a UTC timestamp.
	 *
	 * @param int $id        Submission id.
	 * @param int $timestamp Unix time.
	 */
	private function age( int $id, int $timestamp ): void {
		global $wpdb;

		$wpdb->update(
			Schema::submissions_table(),
			array( 'created_at' => gmdate( 'Y-m-d H:i:s', $timestamp ) ),
			array( 'id' => $id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Request carrying an idempotency key header.
	 *
	 * @param string $key Raw key.
	 */
	private function request( string $key ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/formstead/v1/forms/fs_aaaaaaaaaaaaaaaa/submissions' );
		$request->set_header( 'Idempotency-Key', $key );
		return $request;
	}
}
