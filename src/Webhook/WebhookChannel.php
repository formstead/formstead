<?php
/**
 * Webhook delivery for a stored submission.
 *
 * @package Formstead
 */

namespace Formstead\Webhook;

use Formstead\Delivery\Channel;
use Formstead\Delivery\DeliveryRepository;
use Formstead\Delivery\DeliveryStatus;
use Formstead\Forms\Form;
use Formstead\Submissions\Submission;

/**
 * Posts a signed JSON body to the form's webhook URL.
 *
 * The webhook id comes from the delivery row and stays the same on every
 * attempt. The timestamp is the time of this attempt. The receiver's response
 * is not stored.
 */
final class WebhookChannel implements Channel {

	/**
	 * Request sender.
	 *
	 * @var PinnedRequest
	 */
	private PinnedRequest $requests;

	/**
	 * Create a channel.
	 *
	 * @param PinnedRequest|null $requests Sender. Tests pass one with a fixed resolver.
	 */
	public function __construct( ?PinnedRequest $requests = null ) {
		$this->requests = $requests instanceof PinnedRequest ? $requests : new PinnedRequest();
	}

	/**
	 * Channel name stored on a delivery.
	 */
	public function name(): string {
		return 'webhook';
	}

	/**
	 * Whether the form has a URL and a secret.
	 *
	 * @param Form $form Form that received the submission.
	 */
	public function is_configured( Form $form ): bool {
		return $form->webhook()->is_configured() && '' !== $form->webhook()->secret();
	}

	/**
	 * Post the signed body, or return an error code.
	 *
	 * @param Submission $submission Submission to send.
	 * @param Form       $form       Form that received it.
	 */
	public function send( Submission $submission, Form $form ): ?string {
		$event_id = $this->event_id( $submission );
		$body     = WebhookPayload::body( $submission, $form );
		if ( ! EventId::is_valid( $event_id ) || ! is_string( $body ) ) {
			return WebhookFailure::BLOCKED_URL;
		}

		$timestamp = time();
		$signature = WebhookSignature::header( $event_id, $timestamp, $body, $form->webhook()->secret() );
		if ( ! is_string( $signature ) ) {
			return WebhookFailure::BLOCKED_URL;
		}

		return $this->requests->post(
			$form->webhook()->url(),
			$body,
			array(
				'Content-Type'      => 'application/json',
				'webhook-id'        => $event_id,
				'webhook-timestamp' => (string) $timestamp,
				'webhook-signature' => $signature,
			)
		);
	}

	/**
	 * Event id of the webhook delivery that is being sent.
	 *
	 * The worker has already claimed that row, so it is the processing one.
	 *
	 * @param Submission $submission Submission being sent.
	 */
	private function event_id( Submission $submission ): string {
		foreach ( ( new DeliveryRepository() )->for_submission( $submission->id() ) as $delivery ) {
			if ( 'webhook' === $delivery->channel() && DeliveryStatus::PROCESSING === $delivery->status() ) {
				return $delivery->event_id();
			}
		}

		return '';
	}
}
