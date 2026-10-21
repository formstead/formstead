<?php
/**
 * JSON body of a submission webhook.
 *
 * @package Formstead
 */

namespace Formstead\Webhook;

use Formstead\Forms\Form;
use Formstead\Rest\SubmissionResource;
use Formstead\Submissions\Submission;

/**
 * Builds the body that is signed and sent.
 *
 * The body always names the event, the receipt, the form, the status, and
 * the time. Submitted fields are included only when the form asks for them,
 * in the same shape as the admin API.
 */
final class WebhookPayload {

	/**
	 * JSON body, or null when it cannot be encoded.
	 *
	 * @param Submission $submission Stored submission.
	 * @param Form       $form       Form that received it.
	 */
	public static function body( Submission $submission, Form $form ): ?string {
		$resource = SubmissionResource::from_submission( $submission, $form );
		$document = array(
			'type'       => 'submission.created',
			'receipt_id' => $resource['receipt_id'],
			'form'       => $resource['form'],
			'status'     => $resource['status'],
			'created_at' => $resource['created_at'],
		);
		if ( $form->webhook()->includes_content() ) {
			$document['fields'] = $resource['fields'];
		}

		$encoded = wp_json_encode( $document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return is_string( $encoded ) ? $encoded : null;
	}
}
