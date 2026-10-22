<?php
/**
 * Claims deliveries and hands them to a channel.
 *
 * @package Formstead
 */

namespace Formstead\Delivery;

use Formstead\Forms\Form;
use Formstead\Forms\FormRepository;
use Formstead\Submissions\SubmissionRepository;
use Formstead\Webhook\WebhookChannel;
use Formstead\Webhook\WebhookFailure;

/**
 * Sends due deliveries and records the outcome.
 *
 * A missing submission or a channel that is not configured is cancelled. The
 * channel is not called. A blocked webhook URL or a redirect fails without a
 * retry. Logs name the delivery id and nothing from the submission.
 */
final class DeliveryWorker {

	/**
	 * Delivery store.
	 *
	 * @var DeliveryRepository
	 */
	private DeliveryRepository $deliveries;

	/**
	 * Channel forced by a test, or null when each delivery picks its own.
	 *
	 * @var Channel|null
	 */
	private ?Channel $channel;

	/**
	 * Create a worker.
	 *
	 * @param Channel|null            $channel    Channel to send through. Null selects by the delivery's channel.
	 * @param DeliveryRepository|null $deliveries Store to use. Tests can pass their own.
	 */
	public function __construct( ?Channel $channel = null, ?DeliveryRepository $deliveries = null ) {
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

		$form    = ( new FormRepository() )->find_by_id( $submission->form_id() );
		$channel = $this->channel_for( $delivery );
		if ( ! $form instanceof Form || ! $channel->is_configured( $form ) ) {
			$code = 'webhook' === $channel->name() ? 'no_webhook' : 'no_recipients';
			$this->deliveries->cancel( $delivery->id(), $delivery->lock_token(), $code );
			return;
		}

		try {
			$error = $channel->send( $submission, $form );
		} catch ( \Throwable $caught ) {
			unset( $caught );
			$this->log_failure( $delivery->id() );
			$error = 'webhook' === $delivery->channel() ? WebhookFailure::CONNECT_FAILED : 'mail_exception';
		}

		if ( null === $error || '' === $error ) {
			$this->deliveries->mark_handed_off( $delivery->id(), $delivery->lock_token() );
			return;
		}

		if ( $this->is_terminal( $error ) || $delivery->attempts() >= DeliveryRepository::MAX_ATTEMPTS ) {
			$this->deliveries->mark_failed( $delivery->id(), $delivery->lock_token(), $error );
			$this->log_failure( $delivery->id() );
			return;
		}

		$this->deliveries->schedule_retry( $delivery->id(), $delivery->lock_token(), $error, $this->delay( $delivery->attempts() ) );
	}

	/**
	 * Channel for this delivery.
	 *
	 * A worker constructed with a channel uses that channel, which is how tests
	 * replace mail. Otherwise the delivery's channel name selects email or a
	 * webhook, and `formstead_delivery_channel` can replace either one.
	 *
	 * @param Delivery $delivery Claimed delivery.
	 */
	private function channel_for( Delivery $delivery ): Channel {
		if ( $this->channel instanceof Channel ) {
			return $this->channel;
		}

		$channel = apply_filters( 'formstead_delivery_channel', null, $delivery->channel() );
		if ( $channel instanceof Channel ) {
			return $channel;
		}

		if ( 'webhook' === $delivery->channel() ) {
			return new WebhookChannel();
		}

		return new EmailChannel();
	}

	/**
	 * Whether this error is not worth another attempt.
	 *
	 * A blocked address and a redirect do not change on the next try. A 4xx
	 * other than 429 means the receiver rejected this event.
	 *
	 * @param string $error Error code from the channel.
	 */
	private function is_terminal( string $error ): bool {
		return in_array(
			$error,
			array(
				WebhookFailure::UNSAFE_TRANSPORT,
				WebhookFailure::BLOCKED_URL,
				WebhookFailure::REDIRECT,
				WebhookFailure::HTTP_4XX,
			),
			true
		);
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
