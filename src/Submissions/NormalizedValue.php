<?php
/**
 * One normalized field value.
 *
 * @package Formstead
 */

namespace Formstead\Submissions;

/**
 * A value to store, a value to omit, or a field error.
 */
final class NormalizedValue {

	/**
	 * Whether the value can be stored.
	 *
	 * @var bool
	 */
	private bool $ok;

	/**
	 * Whether an optional empty value should be left out of the payload.
	 *
	 * @var bool
	 */
	private bool $omit;

	/**
	 * Normalized value. Unused when the result is an error or an omission.
	 *
	 * @var string|bool|list<string>|null
	 */
	private string|bool|array|null $value;

	/**
	 * Field error, when normalization failed.
	 *
	 * @var FieldError|null
	 */
	private ?FieldError $error;

	/**
	 * Create a result.
	 *
	 * @param bool                          $ok    Whether the value can be stored.
	 * @param bool                          $omit  Whether the value is left out of the payload.
	 * @param string|bool|list<string>|null $value Normalized value.
	 * @param FieldError|null               $error Field error.
	 */
	private function __construct( bool $ok, bool $omit, string|bool|array|null $value, ?FieldError $error ) {
		$this->ok    = $ok;
		$this->omit  = $omit;
		$this->value = $value;
		$this->error = $error;
	}

	/**
	 * An optional empty value that is not stored.
	 */
	public static function omit(): self {
		return new self( true, true, null, null );
	}

	/**
	 * A value to store.
	 *
	 * @param string|bool|list<string> $value Normalized value.
	 */
	public static function store( string|bool|array $value ): self {
		return new self( true, false, $value, null );
	}

	/**
	 * A field that failed validation.
	 *
	 * @param FieldError $error Error code.
	 */
	public static function invalid( FieldError $error ): self {
		return new self( false, false, null, $error );
	}

	/**
	 * Whether the value can be stored or omitted.
	 */
	public function ok(): bool {
		return $this->ok;
	}

	/**
	 * Whether the value is left out of the payload.
	 */
	public function is_omitted(): bool {
		return $this->omit;
	}

	/**
	 * Normalized value.
	 *
	 * @return string|bool|list<string>|null
	 */
	public function value(): string|bool|array|null {
		return $this->value;
	}

	/**
	 * Field error, when normalization failed.
	 */
	public function error(): ?FieldError {
		return $this->error;
	}
}
