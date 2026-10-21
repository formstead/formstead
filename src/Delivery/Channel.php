<?php
/**
 * A way to hand a submission to another system.
 *
 * @package Formstead
 */

namespace Formstead\Delivery;

use Formstead\Forms\Form;
use Formstead\Submissions\Submission;

/**
 * Sends one delivery.
 */
interface Channel {

	/**
	 * Stable channel name stored on a delivery.
	 */
	public function name(): string;

	/**
	 * Whether this form can send through the channel.
	 *
	 * A delivery whose channel is not configured is cancelled. `send()` is not called.
	 *
	 * @param Form $form Form that received the submission.
	 */
	public function is_configured( Form $form ): bool;

	/**
	 * Hand the submission off.
	 *
	 * Returns null when the other system accepted the message. An error code
	 * is stored on the delivery and must not contain addresses or the body.
	 *
	 * @param Submission $submission Submission to send.
	 * @param Form       $form       Form that received it.
	 */
	public function send( Submission $submission, Form $form ): ?string;
}
