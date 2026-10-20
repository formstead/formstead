<?php
/**
 * One configured form field.
 *
 * @package Formstead
 */

namespace Formstead\Forms;

/**
 * Immutable field definition stored in the form schema.
 */
final class Field {

	/**
	 * Machine name, unique within the form.
	 *
	 * @var string
	 */
	private string $name;

	/**
	 * Label shown to people managing submissions.
	 *
	 * @var string
	 */
	private string $label;

	/**
	 * Field type.
	 *
	 * @var FieldType
	 */
	private FieldType $type;

	/**
	 * Whether an empty value is rejected.
	 *
	 * @var bool
	 */
	private bool $required;

	/**
	 * Maximum length in characters.
	 *
	 * @var int
	 */
	private int $max_length;

	/**
	 * Create a field.
	 *
	 * @param string    $name       Machine name.
	 * @param string    $label      Human label.
	 * @param FieldType $type       Field type.
	 * @param bool      $required   Whether the field is required.
	 * @param int       $max_length Maximum length in characters.
	 */
	public function __construct( string $name, string $label, FieldType $type, bool $required, int $max_length ) {
		$this->name       = $name;
		$this->label      = $label;
		$this->type       = $type;
		$this->required   = $required;
		$this->max_length = $max_length;
	}

	/**
	 * Machine name.
	 */
	public function name(): string {
		return $this->name;
	}

	/**
	 * Human label.
	 */
	public function label(): string {
		return $this->label;
	}

	/**
	 * Field type.
	 */
	public function type(): FieldType {
		return $this->type;
	}

	/**
	 * Whether the field is required.
	 */
	public function required(): bool {
		return $this->required;
	}

	/**
	 * Maximum length in characters.
	 */
	public function max_length(): int {
		return $this->max_length;
	}

	/**
	 * Values stored in the form's fields JSON.
	 *
	 * @return array{name: string, label: string, type: string, required: bool, max_length: int}
	 */
	public function to_array(): array {
		return array(
			'name'       => $this->name,
			'label'      => $this->label,
			'type'       => $this->type->value,
			'required'   => $this->required,
			'max_length' => $this->max_length,
		);
	}

	/**
	 * Build a field from stored JSON, or null when the row is not a field.
	 *
	 * @param array<string, mixed> $data Stored field values.
	 */
	public static function from_array( array $data ): ?self {
		$name  = $data['name'] ?? null;
		$label = $data['label'] ?? null;
		$type  = $data['type'] ?? null;
		$max   = $data['max_length'] ?? null;

		if ( ! is_string( $name ) || ! is_string( $label ) || ! is_string( $type ) || ! is_int( $max ) ) {
			return null;
		}

		$field_type = FieldType::tryFrom( $type );
		if ( ! $field_type instanceof FieldType ) {
			return null;
		}

		return new self( $name, $label, $field_type, (bool) ( $data['required'] ?? false ), $max );
	}
}
