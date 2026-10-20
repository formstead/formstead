<?php
/**
 * Validation for a public form submission.
 *
 * @package Formstead
 */

namespace Formstead\Submissions;

use Formstead\Forms\Form;

/**
 * Checks posted values against the current field schema.
 *
 * Only fields on the form are read. Unknown names, including reserved `_fs_`
 * names, are ignored and are not stored. An optional empty value is omitted.
 * A boolean is always stored.
 */
final class SubmissionValidator {

	/**
	 * Field normalizer.
	 *
	 * @var ValueNormalizer
	 */
	private ValueNormalizer $normalizer;

	/**
	 * Create a validator.
	 *
	 * @param ValueNormalizer|null $normalizer Normalizer to use. Tests can pass their own.
	 */
	public function __construct( ?ValueNormalizer $normalizer = null ) {
		$this->normalizer = $normalizer instanceof ValueNormalizer ? $normalizer : new ValueNormalizer();
	}

	/**
	 * Validate input for a form.
	 *
	 * @param Form                 $form  Form that owns the schema.
	 * @param array<string, mixed> $input Body parameters or a decoded JSON object.
	 */
	public function validate( Form $form, array $input ): SubmissionValidation {
		$values = array();
		$errors = array();

		foreach ( $form->fields() as $field ) {
			$present    = array_key_exists( $field->name(), $input );
			$normalized = $this->normalizer->normalize( $field, $present, $present ? $input[ $field->name() ] : null );
			if ( ! $normalized->ok() ) {
				$error                    = $normalized->error();
				$errors[ $field->name() ] = $error instanceof FieldError ? $error : FieldError::Invalid;
				continue;
			}

			if ( $normalized->is_omitted() ) {
				continue;
			}

			$value = $normalized->value();
			if ( is_string( $value ) || is_bool( $value ) || is_array( $value ) ) {
				$values[ $field->name() ] = $value;
			}
		}

		if ( array() !== $errors ) {
			return new SubmissionValidation( array(), $errors );
		}

		return new SubmissionValidation( $values, array() );
	}
}
