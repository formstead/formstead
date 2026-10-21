<?php
/**
 * Labels for one stored submission.
 *
 * @package Formstead
 */

namespace Formstead\Submissions;

use Formstead\Forms\Field;
use Formstead\Forms\FieldType;
use Formstead\Forms\Form;
use Formstead\Forms\FormVersion;
use Formstead\Forms\FormVersionRepository;

/**
 * Turns a stored payload into labeled rows.
 *
 * Labels and types come from the schema snapshot for the submission's
 * version. When that snapshot does not exist, the current form is used.
 * A field in neither place keeps its stored name. The inbox and the privacy
 * export share this so the two screens name a field the same way.
 */
final class SubmissionRows {

	/**
	 * Labeled rows for one submission.
	 *
	 * @param Submission $submission Stored submission.
	 * @param Form|null  $form       Current form, when it still exists.
	 * @return list<array{label: string, value: string, multiline: bool, items: list<string>}>
	 */
	public static function for_submission( Submission $submission, ?Form $form ): array {
		$snapshot = ( new FormVersionRepository() )->find( $submission->form_id(), $submission->schema_version() );
		$fields   = $snapshot instanceof FormVersion ? $snapshot->fields() : array();
		if ( array() === $fields && $form instanceof Form ) {
			$fields = $form->fields();
		}

		$by_name = array();
		foreach ( $fields as $field ) {
			$by_name[ $field->name() ] = $field;
		}

		$rows = array();
		foreach ( $submission->payload() as $name => $value ) {
			$field   = $by_name[ $name ] ?? null;
			$items   = array();
			$display = self::display_value( $field, $value );
			if ( is_array( $display ) ) {
				$items   = $display;
				$display = '';
			}
			$rows[] = array(
				'label'     => $field instanceof Field ? $field->label() : (string) $name,
				'value'     => $display,
				'multiline' => $field instanceof Field && FieldType::Textarea === $field->type(),
				'items'     => $items,
			);
		}

		return $rows;
	}

	/**
	 * Text, a yes/no answer, or a list of choice labels.
	 *
	 * @param Field|null               $field Field from the snapshot, when it is known.
	 * @param string|bool|list<string> $value Stored value.
	 * @return string|list<string>
	 */
	private static function display_value( ?Field $field, string|bool|array $value ): string|array {
		if ( is_bool( $value ) ) {
			return $value ? __( 'Yes', 'formstead' ) : __( 'No', 'formstead' );
		}

		if ( is_array( $value ) ) {
			return self::choice_labels( $field, $value );
		}

		if ( $field instanceof Field && FieldType::Choice === $field->type() ) {
			$labels = self::choice_labels( $field, array( $value ) );
			return $labels[0] ?? $value;
		}

		return $value;
	}

	/**
	 * Choice labels in the order configured on the field.
	 *
	 * A stored value that is no longer an option keeps the raw value.
	 *
	 * @param Field|null $field    Choice field, when it is known.
	 * @param string[]   $selected Stored values.
	 * @return list<string>
	 */
	private static function choice_labels( ?Field $field, array $selected ): array {
		if ( ! $field instanceof Field ) {
			return array_values( $selected );
		}

		$labels  = array();
		$pending = array_fill_keys( $selected, true );
		foreach ( $field->settings()->options() as $option ) {
			if ( isset( $pending[ $option['value'] ] ) ) {
				$labels[] = $option['label'];
				unset( $pending[ $option['value'] ] );
			}
		}
		foreach ( $selected as $value ) {
			if ( isset( $pending[ $value ] ) ) {
				$labels[] = $value;
			}
		}

		return $labels;
	}
}
