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
	 * Type-specific limits and choices.
	 *
	 * @var FieldSettings
	 */
	private FieldSettings $settings;

	/**
	 * Create a field.
	 *
	 * @param string             $name       Machine name.
	 * @param string             $label      Human label.
	 * @param FieldType          $type       Field type.
	 * @param bool               $required   Whether the field is required.
	 * @param int                $max_length Maximum length in characters.
	 * @param FieldSettings|null $settings   Type-specific settings. Omitted settings use the empty set.
	 */
	public function __construct( string $name, string $label, FieldType $type, bool $required, int $max_length, ?FieldSettings $settings = null ) {
		$this->name       = $name;
		$this->label      = $label;
		$this->type       = $type;
		$this->required   = $required;
		$this->max_length = $max_length;
		$this->settings   = $settings instanceof FieldSettings ? $settings : FieldSettings::none();
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
	 * Type-specific settings.
	 */
	public function settings(): FieldSettings {
		return $this->settings;
	}

	/**
	 * Values stored in the form's fields JSON.
	 *
	 * Text fields keep the stage 1 shape. Number and choice fields add only
	 * the settings that belong to that type.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		$row = array(
			'name'       => $this->name,
			'label'      => $this->label,
			'type'       => $this->type->value,
			'required'   => $this->required,
			'max_length' => $this->max_length,
		);

		if ( FieldType::Number === $this->type ) {
			if ( null !== $this->settings->min() ) {
				$row['min'] = $this->settings->min();
			}
			if ( null !== $this->settings->max() ) {
				$row['max'] = $this->settings->max();
			}
			$row['scale'] = $this->settings->scale();
		}

		if ( FieldType::Choice === $this->type ) {
			$row['options']  = $this->settings->options();
			$row['multiple'] = $this->settings->multiple();
		}

		return $row;
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

		return new self( $name, $label, $field_type, (bool) ( $data['required'] ?? false ), $max, self::settings_from_array( $field_type, $data ) );
	}

	/**
	 * Settings stored beside the common field columns.
	 *
	 * @param FieldType            $type Field type.
	 * @param array<string, mixed> $data Stored field values.
	 */
	private static function settings_from_array( FieldType $type, array $data ): FieldSettings {
		if ( FieldType::Number === $type ) {
			$min   = isset( $data['min'] ) && is_string( $data['min'] ) ? $data['min'] : null;
			$max   = isset( $data['max'] ) && is_string( $data['max'] ) ? $data['max'] : null;
			$scale = isset( $data['scale'] ) && is_int( $data['scale'] ) ? $data['scale'] : 0;

			return new FieldSettings( $min, $max, $scale, array(), false );
		}

		if ( FieldType::Choice === $type ) {
			return new FieldSettings( null, null, 0, self::options_from_array( $data['options'] ?? null ), (bool) ( $data['multiple'] ?? false ) );
		}

		return FieldSettings::none();
	}

	/**
	 * Choice options from stored JSON. Invalid rows are skipped.
	 *
	 * @param mixed $options Stored options.
	 * @return list<array{value: string, label: string}>
	 */
	private static function options_from_array( mixed $options ): array {
		if ( ! is_array( $options ) ) {
			return array();
		}

		$rows = array();
		foreach ( $options as $option ) {
			if ( ! is_array( $option ) ) {
				continue;
			}
			$value = $option['value'] ?? null;
			$label = $option['label'] ?? null;
			if ( ! is_string( $value ) || '' === $value || ! is_string( $label ) || '' === $label ) {
				continue;
			}
			$rows[] = array(
				'value' => $value,
				'label' => $label,
			);
		}

		return $rows;
	}
}
