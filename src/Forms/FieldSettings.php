<?php
/**
 * Type-specific field configuration.
 *
 * @package Formstead
 */

namespace Formstead\Forms;

/**
 * Limits and choices stored with a field.
 */
final class FieldSettings {

	/**
	 * Inclusive minimum for a number, already canonical.
	 *
	 * @var string|null
	 */
	private ?string $min;

	/**
	 * Inclusive maximum for a number, already canonical.
	 *
	 * @var string|null
	 */
	private ?string $max;

	/**
	 * Decimal places allowed for a number. Zero means an integer.
	 *
	 * @var int
	 */
	private int $scale;

	/**
	 * Choice options in display order.
	 *
	 * @var list<array{value: string, label: string}>
	 */
	private array $options;

	/**
	 * Whether a choice field accepts more than one value.
	 *
	 * @var bool
	 */
	private bool $multiple;

	/**
	 * Create settings.
	 *
	 * @param string|null                               $min      Canonical minimum, or null.
	 * @param string|null                               $max      Canonical maximum, or null.
	 * @param int                                       $scale    Decimal places, 0 through 6.
	 * @param list<array{value: string, label: string}> $options Choice options.
	 * @param bool                                      $multiple Whether several choices are allowed.
	 */
	public function __construct( ?string $min, ?string $max, int $scale, array $options, bool $multiple ) {
		$this->min      = $min;
		$this->max      = $max;
		$this->scale    = $scale;
		$this->options  = $options;
		$this->multiple = $multiple;
	}

	/**
	 * Settings for a field type that has none.
	 */
	public static function none(): self {
		return new self( null, null, 0, array(), false );
	}

	/**
	 * Canonical minimum, when one is configured.
	 */
	public function min(): ?string {
		return $this->min;
	}

	/**
	 * Canonical maximum, when one is configured.
	 */
	public function max(): ?string {
		return $this->max;
	}

	/**
	 * Allowed decimal places.
	 */
	public function scale(): int {
		return $this->scale;
	}

	/**
	 * Choice options in display order.
	 *
	 * @return list<array{value: string, label: string}>
	 */
	public function options(): array {
		return $this->options;
	}

	/**
	 * Whether several choices are allowed.
	 */
	public function multiple(): bool {
		return $this->multiple;
	}
}
