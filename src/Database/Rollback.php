<?php
/**
 * A transaction result that must not be committed.
 *
 * @package Formstead
 */

namespace Formstead\Database;

/**
 * Tells {@see Transaction::run()} to roll back and return a value.
 */
final class Rollback {

	/**
	 * Value returned to the caller after the rollback.
	 *
	 * @var mixed
	 */
	private mixed $value;

	/**
	 * Create a rollback result.
	 *
	 * @param mixed $value Value returned after the rollback.
	 */
	public function __construct( mixed $value ) {
		$this->value = $value;
	}

	/**
	 * Value returned to the caller after the rollback.
	 */
	public function value(): mixed {
		return $this->value;
	}
}
