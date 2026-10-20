<?php
/**
 * Validation for form settings saved from the admin.
 *
 * @package Formstead
 */

namespace Formstead\Forms;

/**
 * Validates admin input and builds a form, or returns field errors.
 */
final class FormInput {

	private const NAME_PATTERN = '/^[a-z][a-z0-9_]{0,63}$/';

	/**
	 * Validate a form save.
	 *
	 * An empty trailing field row is ignored. A row marked for removal is ignored.
	 * When `$existing` is given, the id, public id, schema version, and dates are kept.
	 * The repository bumps the schema version if the fields change.
	 *
	 * @param array<string, mixed> $input    Posted form values.
	 * @param Form|null            $existing Form being edited, or null for a new form.
	 */
	public static function validate( array $input, ?Form $existing = null ): FormInputResult {
		$errors = array();

		$name = isset( $input['name'] ) && is_string( $input['name'] ) ? trim( $input['name'] ) : '';
		if ( '' === $name ) {
			$errors['name'] = __( 'Enter a name.', 'formstead' );
		} elseif ( mb_strlen( $name ) > 190 ) {
			$errors['name'] = __( 'Enter a name up to 190 characters.', 'formstead' );
		}

		$success_url = isset( $input['success_url'] ) && is_string( $input['success_url'] ) ? trim( $input['success_url'] ) : '';
		if ( ! self::is_success_url( $success_url ) ) {
			$errors['success_url'] = __( 'Enter an absolute http or https URL.', 'formstead' );
		}

		$fields = self::fields( $input, $errors );

		if ( array() !== $errors ) {
			return new FormInputResult( null, $errors );
		}

		$form = new Form(
			$existing instanceof Form ? $existing->id() : 0,
			$existing instanceof Form ? $existing->public_id() : '',
			$name,
			self::is_checked( $input['is_enabled'] ?? null ),
			$fields,
			$existing instanceof Form ? $existing->schema_version() : 1,
			$success_url,
			$existing instanceof Form ? $existing->created_at() : '',
			$existing instanceof Form ? $existing->updated_at() : ''
		);

		return new FormInputResult( $form, array() );
	}

	/**
	 * Whether a success URL is an absolute http(s) URL we can redirect to.
	 *
	 * `wp_http_validate_url()` rejects local ports such as 8888, so the parts
	 * are checked directly. `esc_url_raw()` must leave the URL unchanged.
	 *
	 * @param string $url Candidate success URL.
	 */
	private static function is_success_url( string $url ): bool {
		if ( '' === $url || strlen( $url ) > 2048 ) {
			return false;
		}

		if ( 1 === preg_match( '/[[:space:][:cntrl:]]/', $url ) ) {
			return false;
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) ) {
			return false;
		}

		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		if ( 'http' !== $scheme && 'https' !== $scheme ) {
			return false;
		}

		if ( '' === (string) ( $parts['host'] ?? '' ) ) {
			return false;
		}

		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return false;
		}

		return esc_url_raw( $url ) === $url;
	}

	/**
	 * Collect valid fields and record row errors on `$errors`.
	 *
	 * @param array<string, mixed>  $input  Posted form values.
	 * @param array<string, string> $errors Error map, updated in place.
	 * @return list<Field>
	 */
	private static function fields( array $input, array &$errors ): array {
		$raw = $input['fields'] ?? array();
		if ( ! is_array( $raw ) ) {
			$errors['fields'] = __( 'Add at least one field.', 'formstead' );
			return array();
		}

		$fields = array();
		$seen   = array();

		foreach ( $raw as $index => $row ) {
			if ( ! is_array( $row ) || self::is_checked( $row['remove'] ?? null ) || self::is_blank_row( $row ) ) {
				continue;
			}

			$prefix = 'fields.' . $index;
			$name   = isset( $row['name'] ) && is_string( $row['name'] ) ? trim( $row['name'] ) : '';
			$label  = isset( $row['label'] ) && is_string( $row['label'] ) ? trim( $row['label'] ) : '';
			$type   = isset( $row['type'] ) && is_string( $row['type'] ) ? $row['type'] : '';

			if ( 1 !== preg_match( self::NAME_PATTERN, $name ) ) {
				$errors[ $prefix . '.name' ] = __( 'Enter a field name using lowercase letters, numbers, and underscores.', 'formstead' );
			} elseif ( isset( $seen[ $name ] ) ) {
				$errors[ $prefix . '.name' ] = __( 'Field names must be unique.', 'formstead' );
			} else {
				$seen[ $name ] = true;
			}

			if ( '' === $label || mb_strlen( $label ) > 190 ) {
				$errors[ $prefix . '.label' ] = __( 'Enter a label up to 190 characters.', 'formstead' );
			}

			$field_type = FieldType::tryFrom( $type );
			if ( ! $field_type instanceof FieldType ) {
				$errors[ $prefix . '.type' ] = __( 'Choose a field type.', 'formstead' );
			}

			$max_length = self::max_length( $row['max_length'] ?? '', $field_type );
			if ( $field_type instanceof FieldType && null === $max_length ) {
				$errors[ $prefix . '.max_length' ] = __( 'Enter a length from 1 to 10000.', 'formstead' );
			}

			if ( isset( $errors[ $prefix . '.name' ] ) || isset( $errors[ $prefix . '.label' ] ) || isset( $errors[ $prefix . '.type' ] ) || isset( $errors[ $prefix . '.max_length' ] ) ) {
				continue;
			}

			if ( ! $field_type instanceof FieldType || null === $max_length ) {
				continue;
			}

			$fields[] = new Field( $name, $label, $field_type, self::is_checked( $row['required'] ?? null ), $max_length );
		}

		if ( array() === $fields && ! isset( $errors['fields'] ) ) {
			$has_row_error = false;
			foreach ( array_keys( $errors ) as $key ) {
				if ( str_starts_with( $key, 'fields.' ) ) {
					$has_row_error = true;
					break;
				}
			}
			if ( ! $has_row_error ) {
				$errors['fields'] = __( 'Add at least one field.', 'formstead' );
			}
		}

		return $fields;
	}

	/**
	 * Whether a posted row has no field values.
	 *
	 * @param array<mixed> $row Posted field row.
	 */
	private static function is_blank_row( array $row ): bool {
		$name  = isset( $row['name'] ) && is_string( $row['name'] ) ? trim( $row['name'] ) : '';
		$label = isset( $row['label'] ) && is_string( $row['label'] ) ? trim( $row['label'] ) : '';
		$type  = isset( $row['type'] ) && is_string( $row['type'] ) ? trim( $row['type'] ) : '';
		$max   = isset( $row['max_length'] ) ? trim( (string) $row['max_length'] ) : '';

		return '' === $name && '' === $label && '' === $type && '' === $max;
	}

	/**
	 * Maximum length, the type default when empty, or null when the value is invalid.
	 *
	 * @param mixed          $value Posted length.
	 * @param FieldType|null $type  Chosen type, when it is valid.
	 */
	private static function max_length( mixed $value, ?FieldType $type ): ?int {
		$text = trim( (string) $value );
		if ( '' === $text ) {
			return $type instanceof FieldType ? $type->default_max_length() : null;
		}

		if ( 1 !== preg_match( '/^[0-9]+$/', $text ) ) {
			return null;
		}

		$length = (int) $text;
		if ( $length < 1 || $length > 10000 ) {
			return null;
		}

		return $length;
	}

	/**
	 * Whether a checkbox-style value is on.
	 *
	 * @param mixed $value Posted value.
	 */
	private static function is_checked( mixed $value ): bool {
		return true === $value || 1 === $value || '1' === $value;
	}
}
