<?php
/**
 * JSON shape of one submission for the admin API.
 *
 * @package Formstead
 */

namespace Formstead\Rest;

use Formstead\Forms\Form;
use Formstead\Submissions\Submission;
use Formstead\Submissions\SubmissionRows;

/**
 * Builds a submission document addressed by its receipt id.
 *
 * Field labels come from the schema snapshot, then the current form. The
 * database id is not included.
 */
final class SubmissionResource {

	/**
	 * One submission.
	 *
	 * @param Submission $submission Stored submission.
	 * @param Form|null  $form       Current form, when it still exists.
	 * @return array{receipt_id: string, form: array{public_id: string, name: string}, status: string, created_at: string, fields: list<array{name: string, label: string, value: string|list<string>}>}
	 */
	public static function from_submission( Submission $submission, ?Form $form ): array {
		$rows   = SubmissionRows::for_submission( $submission, $form );
		$fields = array();
		$index  = 0;
		foreach ( array_keys( $submission->payload() ) as $name ) {
			$row      = $rows[ $index ] ?? null;
			$fields[] = array(
				'name'  => (string) $name,
				'label' => is_array( $row ) ? $row['label'] : (string) $name,
				'value' => self::value( $row ),
			);
			++$index;
		}

		return array(
			'receipt_id' => $submission->receipt_id(),
			'form'       => array(
				'public_id' => $form instanceof Form ? $form->public_id() : '',
				'name'      => $form instanceof Form ? $form->name() : '',
			),
			'status'     => $submission->status(),
			'created_at' => $submission->created_at(),
			'fields'     => $fields,
		);
	}

	/**
	 * Display value for one labeled row.
	 *
	 * A choice list stays a list. Everything else is the text the inbox shows.
	 *
	 * @param array{label: string, value: string, multiline: bool, items: list<string>}|null $row Labeled row.
	 * @return string|list<string>
	 */
	private static function value( ?array $row ): string|array {
		if ( ! is_array( $row ) ) {
			return '';
		}

		if ( array() !== $row['items'] ) {
			return $row['items'];
		}

		return $row['value'];
	}
}
