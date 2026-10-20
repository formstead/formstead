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
	 * Trimmed values for fields that passed. Empty when validation failed.
	 *
	 * @var array<string, string>
	 */
	private array $values;

	/**
	 * Error codes keyed by field name.
	 *
	 * @var array<string, string>
	 */
	private array $errors;

	/**
	 * Create a validation result.
	 *
	 * @param array<string, string> $values Trimmed values.
	 * @param array<string, string> $errors Error codes keyed by field name.
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
	 * Trimmed values to store. Empty when validation failed.
	 *
	 * @return array<string, string>
	 */
	public function values(): array {
		return $this->values;
	}

	/**
	 * Error codes keyed by field name.
	 *
	 * @return array<string, string>
	 */
	public function errors(): array {
		return $this->errors;
	}
}
