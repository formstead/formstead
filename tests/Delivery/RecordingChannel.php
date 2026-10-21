<?php
/**
 * Channel double for delivery tests.
 *
 * @package Formstead
 */

namespace Formstead\Tests\Delivery;

use Formstead\Delivery\Channel;
use Formstead\Forms\Form;
use Formstead\Submissions\Submission;

/**
 * Records send calls and returns a fixed error, or throws.
 */
final class RecordingChannel implements Channel {

	/**
	 * How many times send was called.
	 *
	 * @var int
	 */
	public int $calls = 0;

	/**
	 * Error code to return, or null for a hand-off.
	 *
	 * @var string|null
	 */
	public ?string $error = null;

	/**
	 * Whether send throws. The message stands in for a mailer error that must not be stored.
	 *
	 * @var bool
	 */
	public bool $throw = false;

	/**
	 * Channel name. Tests that inject this double are exercising email.
	 */
	public function name(): string {
		return 'email';
	}

	/**
	 * Whether the form still has recipients.
	 *
	 * @param Form $form Form that received the submission.
	 */
	public function is_configured( Form $form ): bool {
		return $form->notifications()->has_recipients();
	}

	/**
	 * Record the call.
	 *
	 * @param Submission $submission Submission to send.
	 * @param Form       $form       Form that received it.
	 * @throws \RuntimeException When the test asks the channel to fail like a mailer hook.
	 */
	public function send( Submission $submission, Form $form ): ?string {
		unset( $submission, $form );
		++$this->calls;
		if ( $this->throw ) {
			throw new \RuntimeException( 'smtp failed for secret@example.com' );
		}

		return $this->error;
	}
}
