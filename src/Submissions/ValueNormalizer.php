<?php
/**
 * Normalization for one submitted field value.
 *
 * @package Formstead
 */

namespace Formstead\Submissions;

use Formstead\Forms\Decimal;
use Formstead\Forms\Field;
use Formstead\Forms\FieldType;
use Formstead\Rest\JsonNumber;

/**
 * Turns a raw input value into the value stored for its field type.
 */
final class ValueNormalizer {

	/**
	 * Normalize one field.
	 *
	 * An absent boolean becomes false. Any other absent optional field is omitted.
	 *
	 * @param Field $field   Field schema.
	 * @param bool  $present Whether the name was in the input.
	 * @param mixed $raw     Raw input value.
	 */
	public function normalize( Field $field, bool $present, mixed $raw ): NormalizedValue {
		if ( FieldType::Boolean === $field->type() ) {
			return $this->boolean_value( $field, $present, $raw );
		}

		if ( ! $present ) {
			return $field->required() ? NormalizedValue::invalid( FieldError::Required ) : NormalizedValue::omit();
		}

		if ( FieldType::Choice === $field->type() && $field->settings()->multiple() ) {
			return $this->multiple_choice( $field, $raw );
		}

		if ( $raw instanceof JsonNumber ) {
			if ( ! self::keeps_number_token( $field->type() ) ) {
				return NormalizedValue::invalid( FieldError::Invalid );
			}
			$raw = $raw->token();
		}

		if ( is_array( $raw ) || is_bool( $raw ) ) {
			return NormalizedValue::invalid( FieldError::Invalid );
		}

		if ( ! is_string( $raw ) || ! mb_check_encoding( $raw, 'UTF-8' ) ) {
			return NormalizedValue::invalid( FieldError::Invalid );
		}

		$value = trim( $raw );
		$text  = $this->normalize_text( $field->type(), $value );
		if ( $text instanceof FieldError ) {
			return NormalizedValue::invalid( $text );
		}

		if ( '' === $text ) {
			return $field->required() ? NormalizedValue::invalid( FieldError::Required ) : NormalizedValue::omit();
		}

		if ( mb_strlen( $text ) > $field->max_length() ) {
			return NormalizedValue::invalid( FieldError::TooLong );
		}

		return match ( $field->type() ) {
			FieldType::Email => $this->email_value( $text ),
			FieldType::Number => $this->number_value( $field, $text ),
			FieldType::Choice => $this->single_choice( $field, $text ),
			default => NormalizedValue::store( $text ),
		};
	}

	/**
	 * Whether a JSON number token is read as text for this type.
	 *
	 * Checkbox and choice values reject a JSON number. Text fields keep the
	 * token as text. Number fields parse it as a decimal.
	 *
	 * @param FieldType $type Field type.
	 */
	private static function keeps_number_token( FieldType $type ): bool {
		return match ( $type ) {
			FieldType::Text, FieldType::Email, FieldType::Textarea, FieldType::Number => true,
			default => false,
		};
	}

	/**
	 * Normalize line endings and reject control characters.
	 *
	 * @param FieldType $type  Field type.
	 * @param string    $value Trimmed text.
	 */
	private function normalize_text( FieldType $type, string $value ): string|FieldError {
		if ( FieldType::Textarea === $type ) {
			$value = str_replace( array( "\r\n", "\r" ), "\n", $value );
			if ( 1 === preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value ) ) {
				return FieldError::Invalid;
			}

			return $value;
		}

		if ( 1 === preg_match( '/[\x00-\x1F\x7F]/', $value ) ) {
			return FieldError::Invalid;
		}

		return $value;
	}

	/**
	 * An email address.
	 *
	 * @param string $value Normalized text.
	 */
	private function email_value( string $value ): NormalizedValue {
		if ( ! is_email( $value ) ) {
			return NormalizedValue::invalid( FieldError::InvalidEmail );
		}

		return NormalizedValue::store( $value );
	}

	/**
	 * A canonical decimal inside the configured bounds.
	 *
	 * @param Field  $field Field schema.
	 * @param string $value Normalized text.
	 */
	private function number_value( Field $field, string $value ): NormalizedValue {
		$digits = Decimal::fraction_digits( $value );
		if ( null === $digits ) {
			return NormalizedValue::invalid( FieldError::InvalidNumber );
		}
		if ( $digits > $field->settings()->scale() ) {
			return NormalizedValue::invalid( FieldError::TooPrecise );
		}

		$canonical = Decimal::canonical( $value );
		if ( ! is_string( $canonical ) ) {
			return NormalizedValue::invalid( FieldError::InvalidNumber );
		}

		$min = $field->settings()->min();
		if ( is_string( $min ) && Decimal::compare( $canonical, $min ) < 0 ) {
			return NormalizedValue::invalid( FieldError::TooSmall );
		}

		$max = $field->settings()->max();
		if ( is_string( $max ) && Decimal::compare( $canonical, $max ) > 0 ) {
			return NormalizedValue::invalid( FieldError::TooLarge );
		}

		return NormalizedValue::store( $canonical );
	}

	/**
	 * One selected choice.
	 *
	 * @param Field  $field Field schema.
	 * @param string $value Normalized text.
	 */
	private function single_choice( Field $field, string $value ): NormalizedValue {
		foreach ( $field->settings()->options() as $option ) {
			if ( $option['value'] === $value ) {
				return NormalizedValue::store( $value );
			}
		}

		return NormalizedValue::invalid( FieldError::InvalidChoice );
	}

	/**
	 * Several selected choices, in the order configured on the field.
	 *
	 * A single string is accepted as one selection.
	 *
	 * @param Field $field Field schema.
	 * @param mixed $raw   Raw input value.
	 */
	private function multiple_choice( Field $field, mixed $raw ): NormalizedValue {
		if ( is_string( $raw ) ) {
			$raw = array( $raw );
		}
		if ( ! is_array( $raw ) || ! array_is_list( $raw ) ) {
			return NormalizedValue::invalid( FieldError::Invalid );
		}

		$selected = array();
		foreach ( $raw as $item ) {
			if ( $item instanceof JsonNumber || ! is_string( $item ) || ! mb_check_encoding( $item, 'UTF-8' ) ) {
				return NormalizedValue::invalid( FieldError::Invalid );
			}
			$item = trim( $item );
			if ( '' === $item ) {
				continue;
			}
			if ( 1 === preg_match( '/[\x00-\x1F\x7F]/', $item ) ) {
				return NormalizedValue::invalid( FieldError::Invalid );
			}
			$selected[ $item ] = true;
		}

		if ( array() === $selected ) {
			return $field->required() ? NormalizedValue::invalid( FieldError::Required ) : NormalizedValue::omit();
		}

		$ordered = array();
		foreach ( $field->settings()->options() as $option ) {
			if ( isset( $selected[ $option['value'] ] ) ) {
				$ordered[] = $option['value'];
				unset( $selected[ $option['value'] ] );
			}
		}

		if ( array() !== $selected ) {
			return NormalizedValue::invalid( FieldError::InvalidChoice );
		}

		return NormalizedValue::store( $ordered );
	}

	/**
	 * A checkbox value. Required means the box must be checked.
	 *
	 * @param Field $field   Field schema.
	 * @param bool  $present Whether the name was in the input.
	 * @param mixed $raw     Raw input value.
	 */
	private function boolean_value( Field $field, bool $present, mixed $raw ): NormalizedValue {
		if ( ! $present || null === $raw || ( is_string( $raw ) && '' === trim( $raw ) ) ) {
			return $field->required() ? NormalizedValue::invalid( FieldError::Required ) : NormalizedValue::store( false );
		}

		if ( $raw instanceof JsonNumber ) {
			return NormalizedValue::invalid( FieldError::Invalid );
		}

		if ( true === $raw ) {
			return NormalizedValue::store( true );
		}
		if ( false === $raw ) {
			return $field->required() ? NormalizedValue::invalid( FieldError::Required ) : NormalizedValue::store( false );
		}
		if ( ! is_string( $raw ) || ! mb_check_encoding( $raw, 'UTF-8' ) ) {
			return NormalizedValue::invalid( FieldError::Invalid );
		}

		$value = strtolower( trim( $raw ) );
		if ( in_array( $value, array( '1', 'on', 'true' ), true ) ) {
			return NormalizedValue::store( true );
		}
		if ( in_array( $value, array( '0', 'off', 'false' ), true ) ) {
			return $field->required() ? NormalizedValue::invalid( FieldError::Required ) : NormalizedValue::store( false );
		}

		return NormalizedValue::invalid( FieldError::Invalid );
	}
}
