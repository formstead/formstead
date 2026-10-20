<?php
/**
 * Validation for a public form submission.
 *
 * @package Formstead
 */

namespace Formstead\Submissions;

use Formstead\Forms\Field;
use Formstead\Forms\FieldType;
use Formstead\Forms\Form;

/**
 * Checks posted values against the current field schema.
 *
 * Only fields on the form are read. Unknown names, including reserved `_fs_`
 * names, are ignored and are not stored.
 */
final class SubmissionValidator {

	public const REQUIRED = 'required';

	public const TOO_LONG = 'too_long';

	public const INVALID_EMAIL = 'invalid_email';

	public const INVALID = 'invalid';

	/**
	 * Validate body parameters for a form.
	 *
	 * Values are trimmed before the required and length checks, and the trimmed
	 * value is what would be stored.
	 *
	 * @param Form                 $form  Form that owns the schema.
	 * @param array<string, mixed> $input Body parameters.
	 */
	public function validate( Form $form, array $input ): SubmissionValidation {
		$values = array();
		$errors = array();

		foreach ( $form->fields() as $field ) {
			$error = $this->field_error( $field, $input );
			if ( null !== $error ) {
				$errors[ $field->name() ] = $error;
				continue;
			}

			$values[ $field->name() ] = $this->text_value( $field, $input );
		}

		if ( array() !== $errors ) {
			return new SubmissionValidation( array(), $errors );
		}

		return new SubmissionValidation( $values, array() );
	}

	/**
	 * Error code for one field, or null when the value can be stored.
	 *
	 * @param Field                $field Field schema.
	 * @param array<string, mixed> $input Body parameters.
	 */
	private function field_error( Field $field, array $input ): ?string {
		if ( ! array_key_exists( $field->name(), $input ) ) {
			return $field->required() ? self::REQUIRED : null;
		}

		$raw = $input[ $field->name() ];
		if ( ! is_string( $raw ) ) {
			return self::INVALID;
		}

		$value = trim( $raw );
		if ( $field->required() && '' === $value ) {
			return self::REQUIRED;
		}

		if ( mb_strlen( $value ) > $field->max_length() ) {
			return self::TOO_LONG;
		}

		if ( FieldType::Email === $field->type() && '' !== $value && ! is_email( $value ) ) {
			return self::INVALID_EMAIL;
		}

		return null;
	}

	/**
	 * Trimmed text for a field that has already passed validation.
	 *
	 * @param Field                $field Field schema.
	 * @param array<string, mixed> $input Body parameters.
	 */
	private function text_value( Field $field, array $input ): string {
		$raw = $input[ $field->name() ] ?? '';
		return is_string( $raw ) ? trim( $raw ) : '';
	}
}
