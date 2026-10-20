<?php
/**
 * Result of validating form input.
 *
 * @package Formstead
 */

namespace Formstead\Forms;

/**
 * A valid form, or the field errors that blocked it.
 */
final class FormInputResult {

	/**
	 * Valid form. Null when validation failed.
	 *
	 * @var Form|null
	 */
	private ?Form $form;

	/**
	 * Error messages keyed by field, such as `success_url` or `fields.0.name`.
	 *
	 * @var array<string, string>
	 */
	private array $errors;

	/**
	 * Create a validation result.
	 *
	 * @param Form|null             $form   Valid form, or null.
	 * @param array<string, string> $errors Field errors.
	 */
	public function __construct( ?Form $form, array $errors ) {
		$this->form   = $form;
		$this->errors = $errors;
	}

	/**
	 * Whether the input is valid.
	 */
	public function is_valid(): bool {
		return $this->form instanceof Form && array() === $this->errors;
	}

	/**
	 * The valid form, or null.
	 */
	public function form(): ?Form {
		return $this->form;
	}

	/**
	 * Field errors.
	 *
	 * @return array<string, string>
	 */
	public function errors(): array {
		return $this->errors;
	}
}
