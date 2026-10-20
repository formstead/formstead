<?php
/**
 * Result of validating a public submission.
 *
 * @package Formstead
 */

namespace Formstead\Submissions;

/**
 * Normalized field values, or the error code for each field that failed.
 */
final class SubmissionValidation {

	/**
	 * Normalized values for fields that passed. Empty when validation failed.
	 *
	 * @var array<string, string|bool|list<string>>
	 */
	private array $values;

	/**
	 * Error codes keyed by field name.
	 *
	 * @var array<string, FieldError>
	 */
	private array $errors;

	/**
	 * Create a validation result.
	 *
	 * @param array<string, string|bool|list<string>> $values Normalized values.
	 * @param array<string, FieldError>               $errors Error codes keyed by field name.
	 */
	public function __construct( array $values, array $errors ) {
		$this->values = $values;
		$this->errors = $errors;
	}

	/**
	 * Whether every known field passed.
	 */
	public function is_valid(): bool {
		return array() === $this->errors;
	}

	/**
	 * Normalized values to store. Empty when validation failed.
	 *
	 * @return array<string, string|bool|list<string>>
	 */
	public function values(): array {
		return $this->values;
	}

	/**
	 * Error codes keyed by field name.
	 *
	 * @return array<string, FieldError>
	 */
	public function errors(): array {
		return $this->errors;
	}
}
