<?php
/**
 * Claims deliveries and hands them to a channel.
 *
 * @package Formstead
 */

namespace Formstead\Delivery;

use Formstead\Forms\FormRepository;
use Formstead\Submissions\SubmissionRepository;

/**
 * Sends due deliveries and records the outcome.
 *
 * A missing submission or a form with no recipients is cancelled. The channel
 * is not called. Logs name the delivery id and nothing from the submission.
 */
final class DeliveryWorker {

	/**
	 * Delivery store.
	 *
	 * @var DeliveryRepository
	 */
	private DeliveryRepository $deliveries;

	/**
	 * Channel that hands a message off.
	 *
	 * @var Channel
	 */
	private Channel $channel;

	/**
	 * Create a worker.
	 *
	 * @param Channel                 $channel    Channel to send through.
	 * @param DeliveryRepository|null $deliveries Store to use. Tests can pass their own.
	 */
	public function __construct( Channel $channel, ?DeliveryRepository $deliveries = null ) {
		$this->channel    = $channel;
		$this->deliveries = $deliveries instanceof DeliveryRepository ? $deliveries : new DeliveryRepository();
	}

	/**
	 * Claim and send one delivery. A delivery that cannot be claimed is skipped.
	 *
	 * @param int $id Delivery id.
	 */
	public function send( int $id ): void {
		$delivery = $this->deliveries->claim( $id );
		if ( ! $delivery instanceof Delivery ) {
			return;
		}

		$this->dispatch( $delivery );
	}

	/**
	 * Send due deliveries, up to the limit.
	 *
	 * @param int $limit Maximum deliveries to claim.
	 */
	public function run_due( int $limit ): int {
		$processed = 0;
		foreach ( $this->deliveries->due( $limit ) as $id ) {
			$this->send( $id );
			++$processed;
		}

		return $processed;
	}

	/**
	 * Send a delivery that this worker already claimed.
	 *
	 * @param Delivery $delivery Claimed delivery.
	 */
	private function dispatch( Delivery $delivery ): void {
		$submission = ( new SubmissionRepository() )->find( $delivery->submission_id() );
		if ( null === $submission ) {
			$this->deliveries->cancel( $delivery->id(), $delivery->lock_token(), 'submission_missing' );
			return;
		}

		$form = ( new FormRepository() )->find_by_id( $submission->form_id() );
		if ( null === $form || ! $form->notifications()->has_recipients() ) {
			$this->deliveries->cancel( $delivery->id(), $delivery->lock_token(), 'no_recipients' );
			return;
		}

		try {
			$error = $this->channel->send( $submission, $form );
		} catch ( \Throwable $caught ) {
			unset( $caught );
			$this->log_failure( $delivery->id() );
			$error = 'mail_exception';
		}

		if ( null === $error || '' === $error ) {
			$this->deliveries->mark_handed_off( $delivery->id(), $delivery->lock_token() );
			return;
		}

		if ( $delivery->attempts() >= DeliveryRepository::MAX_ATTEMPTS ) {
			$this->deliveries->mark_failed( $delivery->id(), $delivery->lock_token(), $error );
			$this->log_failure( $delivery->id() );
			return;
		}

		$this->deliveries->schedule_retry( $delivery->id(), $delivery->lock_token(), $error, $this->delay( $delivery->attempts() ) );
	}

	/**
	 * Seconds to wait after this failed attempt.
	 *
	 * Attempt 1 waits a minute, then 5 minutes, 30 minutes, and 2 hours.
	 *
	 * @param int $attempts Attempts already counted, including the one that just failed.
	 */
	private function delay( int $attempts ): int {
		return match ( $attempts ) {
			1 => MINUTE_IN_SECONDS,
			2 => 5 * MINUTE_IN_SECONDS,
			3 => 30 * MINUTE_IN_SECONDS,
			default => 2 * HOUR_IN_SECONDS,
		};
	}

	/**
	 * Record a failed send without the submission text.
	 *
	 * @param int $delivery_id Delivery id.
	 */
	private function log_failure( int $delivery_id ): void {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- delivery id only.
		error_log( sprintf( 'Formstead could not send delivery %d.', $delivery_id ) );
	}
}
