<?php
/**
 * Channel used until a mail channel is registered.
 *
 * @package Formstead
 */

namespace Formstead\Delivery;

use Formstead\Forms\Form;
use Formstead\Submissions\Submission;

/**
 * Refuses to send. The email channel replaces this through a filter.
 */
final class UnconfiguredChannel implements Channel {

	/**
	 * Report that no channel is configured.
	 *
	 * @param Submission $submission Submission to send. Unused.
	 * @param Form       $form       Form that received it. Unused.
	 */
	public function send( Submission $submission, Form $form ): string {
		unset( $submission, $form );

		return 'mail_failed';
	}
}
