<?php
/**
 * Validation for form settings saved from the admin.
 *
 * @package Formstead
 */

namespace Formstead\Forms;

use Formstead\Security\Limits;
use Formstead\Security\OriginPolicy;

/**
 * Validates admin input and builds a form, or returns field errors.
 */
final class FormInput {

	private const NAME_PATTERN = '/^[a-z][a-z0-9_]{0,63}$/';

	/**
	 * Days used when the editor does not send a retention period.
	 */
	private const DEFAULT_RETENTION_DAYS = 30;

	/**
	 * Longest retention period the editor accepts. Zero keeps submissions.
	 */
	private const MAX_RETENTION_DAYS = 3650;

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

		$fields         = self::fields( $input, $errors );
		$protection     = self::protection( $input, $errors );
		$notifications  = self::notifications( $input, $fields, $errors );
		$retention_days = self::retention_days( $input, $errors );

		if ( array() !== $errors ) {
			return new FormInputResult( null, $errors );
		}

		$form = new Form(
			$existing instanceof Form ? $existing->id() : 0,
			$existing instanceof Form ? $existing->public_id() : '',
			$name,
			self::is_checked( $input['is_enabled'] ?? null ),
			$fields,
			self::schema_version( $input, $existing ),
			$success_url,
			$existing instanceof Form ? $existing->created_at() : '',
			$existing instanceof Form ? $existing->updated_at() : '',
			$protection,
			$existing instanceof Form ? $existing->honeypot_rejections() : 0,
			$notifications,
			$retention_days
		);

		return new FormInputResult( $form, array() );
	}

	/**
	 * Schema version the editor opened.
	 *
	 * A new form starts at version 1. An edit uses the posted version, which
	 * the repository compares with the stored row. A missing value cannot
	 * match, so an edit without it is a conflict.
	 *
	 * @param array<string, mixed> $input    Posted form values.
	 * @param Form|null            $existing Form being edited, or null for a new form.
	 */
	private static function schema_version( array $input, ?Form $existing ): int {
		if ( ! $existing instanceof Form ) {
			return 1;
		}

		return isset( $input['schema_version'] ) ? (int) $input['schema_version'] : 0;
	}

	/**
	 * Recipients, the reply-to field, and whether the body includes the submission.
	 *
	 * An empty recipient list is valid: the form stores submissions and sends no mail.
	 *
	 * @param array<string, mixed>  $input  Posted form values.
	 * @param Field[]               $fields Valid fields.
	 * @param array<string, string> $errors Error map, updated in place.
	 */
	private static function notifications( array $input, array $fields, array &$errors ): FormNotifications {
		return new FormNotifications(
			self::recipients( $input, $errors ),
			self::reply_to_field( $input, $fields, $errors ),
			self::is_checked( $input['mail_include_content'] ?? null )
		);
	}

	/**
	 * Recipient addresses, one per non-empty line.
	 *
	 * @param array<string, mixed>  $input  Posted form values.
	 * @param array<string, string> $errors Error map, updated in place.
	 * @return list<string>
	 */
	private static function recipients( array $input, array &$errors ): array {
		$text  = isset( $input['notify_recipients'] ) && is_string( $input['notify_recipients'] ) ? $input['notify_recipients'] : '';
		$lines = preg_split( '/\R/', $text );
		$list  = array();
		$seen  = array();

		foreach ( is_array( $lines ) ? $lines : array() as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}

			$address = sanitize_email( $line );
			if ( ! is_string( $address ) || ! is_email( $line ) || ! is_email( $address ) ) {
				$errors['notify_recipients'] = __( 'Enter up to 10 valid email addresses, one per line.', 'formstead' );
				return array();
			}

			$key = strtolower( $address );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}

			$seen[ $key ] = true;
			$list[]       = $address;
			if ( count( $list ) > 10 ) {
				$errors['notify_recipients'] = __( 'Enter up to 10 valid email addresses, one per line.', 'formstead' );
				return array();
			}
		}

		return $list;
	}

	/**
	 * Email field used as Reply-To, or null when none is chosen.
	 *
	 * @param array<string, mixed>  $input  Posted form values.
	 * @param Field[]               $fields Valid fields.
	 * @param array<string, string> $errors Error map, updated in place.
	 */
	private static function reply_to_field( array $input, array $fields, array &$errors ): ?string {
		$name = isset( $input['reply_to_field'] ) && is_string( $input['reply_to_field'] ) ? trim( $input['reply_to_field'] ) : '';
		if ( '' === $name ) {
			return null;
		}

		foreach ( $fields as $field ) {
			if ( $field->name() === $name && FieldType::Email === $field->type() ) {
				return $name;
			}
		}

		$errors['reply_to_field'] = __( 'Choose an email field.', 'formstead' );
		return null;
	}

	/**
	 * Days to keep submissions.
	 *
	 * A missing value uses 30 days. An empty value, a number above 3650, or
	 * anything that is not a whole number is rejected. Zero keeps submissions
	 * until someone deletes them.
	 *
	 * @param array<string, mixed>  $input  Posted form values.
	 * @param array<string, string> $errors Error map, updated in place.
	 */
	private static function retention_days( array $input, array &$errors ): int {
		if ( ! array_key_exists( 'retention_days', $input ) ) {
			return self::DEFAULT_RETENTION_DAYS;
		}

		$text = is_string( $input['retention_days'] ) ? trim( $input['retention_days'] ) : '';
		if ( 1 !== preg_match( '/^[0-9]+$/', $text ) ) {
			$errors['retention_days'] = __( 'Enter a whole number of days from 0 to 3650.', 'formstead' );
			return self::DEFAULT_RETENTION_DAYS;
		}

		$days = (int) $text;
		if ( $days > self::MAX_RETENTION_DAYS ) {
			$errors['retention_days'] = __( 'Enter a whole number of days from 0 to 3650.', 'formstead' );
			return self::DEFAULT_RETENTION_DAYS;
		}

		return $days;
	}

	/**
	 * Origin allowlist and rate overrides from the editor.
	 *
	 * An empty rate uses the global limit. Invalid lines are rejected rather than dropped.
	 *
	 * @param array<string, mixed>  $input  Posted form values.
	 * @param array<string, string> $errors Error map, updated in place.
	 */
	private static function protection( array $input, array &$errors ): FormProtection {
		return new FormProtection(
			self::origins( $input, $errors ),
			self::rate_override( $input, 'rate_per_minute', Limits::CEILING_RATE_PER_MINUTE, __( 'Enter a per-minute limit from 1 to 60.', 'formstead' ), $errors ),
			self::rate_override( $input, 'rate_per_hour', Limits::CEILING_RATE_PER_HOUR, __( 'Enter a per-hour limit from 1 to 10000.', 'formstead' ), $errors )
		);
	}

	/**
	 * Normalized origins, one per non-empty line.
	 *
	 * @param array<string, mixed>  $input  Posted form values.
	 * @param array<string, string> $errors Error map, updated in place.
	 * @return list<string>
	 */
	private static function origins( array $input, array &$errors ): array {
		$text  = isset( $input['allowed_origins'] ) && is_string( $input['allowed_origins'] ) ? $input['allowed_origins'] : '';
		$lines = preg_split( '/\R/', $text );
		$list  = array();
		$seen  = array();
		$count = 0;

		foreach ( is_array( $lines ) ? $lines : array() as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}

			++$count;
			if ( $count > 20 ) {
				$errors['allowed_origins'] = __( 'Enter up to 20 origins.', 'formstead' );
				return array();
			}

			$origin = OriginPolicy::normalize( $line );
			if ( null === $origin ) {
				$errors['allowed_origins'] = __( 'Enter one http or https origin per line, without a path.', 'formstead' );
				return array();
			}

			if ( isset( $seen[ $origin ] ) ) {
				continue;
			}

			$seen[ $origin ] = true;
			$list[]          = $origin;
		}

		return $list;
	}

	/**
	 * A rate override, or null when the field is empty.
	 *
	 * @param array<string, mixed>  $input   Posted form values.
	 * @param string                $key     Input key.
	 * @param int                   $ceiling Hard ceiling.
	 * @param string                $message Error message.
	 * @param array<string, string> $errors  Error map, updated in place.
	 */
	private static function rate_override( array $input, string $key, int $ceiling, string $message, array &$errors ): ?int {
		if ( ! isset( $input[ $key ] ) || ! is_string( $input[ $key ] ) ) {
			return null;
		}

		$text = trim( $input[ $key ] );
		if ( '' === $text ) {
			return null;
		}

		if ( 1 !== preg_match( '/^[0-9]+$/', $text ) ) {
			$errors[ $key ] = $message;
			return null;
		}

		$number = (int) $text;
		if ( $number < 1 || $number > $ceiling ) {
			$errors[ $key ] = $message;
			return null;
		}

		return $number;
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

			$settings = FieldSettings::none();
			if ( FieldType::Number === $field_type ) {
				$settings = self::number_settings( $row, $prefix, $errors );
			} elseif ( FieldType::Choice === $field_type ) {
				$settings = self::choice_settings( $row, $prefix, $errors );
			}

			if ( isset( $errors[ $prefix . '.name' ] ) || isset( $errors[ $prefix . '.label' ] ) || isset( $errors[ $prefix . '.type' ] ) || isset( $errors[ $prefix . '.max_length' ] ) || isset( $errors[ $prefix . '.min' ] ) || isset( $errors[ $prefix . '.max' ] ) || isset( $errors[ $prefix . '.scale' ] ) || isset( $errors[ $prefix . '.options' ] ) ) {
				continue;
			}

			if ( ! $field_type instanceof FieldType || null === $max_length ) {
				continue;
			}

			$fields[] = new Field( $name, $label, $field_type, self::is_checked( $row['required'] ?? null ), $max_length, $settings );
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
		$name    = isset( $row['name'] ) && is_string( $row['name'] ) ? trim( $row['name'] ) : '';
		$label   = isset( $row['label'] ) && is_string( $row['label'] ) ? trim( $row['label'] ) : '';
		$type    = isset( $row['type'] ) && is_string( $row['type'] ) ? trim( $row['type'] ) : '';
		$max     = isset( $row['max_length'] ) ? trim( (string) $row['max_length'] ) : '';
		$min     = isset( $row['min'] ) ? trim( (string) $row['min'] ) : '';
		$upper   = isset( $row['max'] ) ? trim( (string) $row['max'] ) : '';
		$scale   = isset( $row['scale'] ) ? trim( (string) $row['scale'] ) : '';
		$options = isset( $row['options'] ) && is_string( $row['options'] ) ? trim( $row['options'] ) : '';

		return '' === $name && '' === $label && '' === $type && '' === $max && '' === $min && '' === $upper && '' === $scale && '' === $options;
	}

	/**
	 * Number limits from a posted row.
	 *
	 * @param array<mixed>          $row    Posted field row.
	 * @param string                $prefix Error key prefix.
	 * @param array<string, string> $errors Error map, updated in place.
	 */
	private static function number_settings( array $row, string $prefix, array &$errors ): FieldSettings {
		$min_text   = isset( $row['min'] ) ? trim( (string) $row['min'] ) : '';
		$max_text   = isset( $row['max'] ) ? trim( (string) $row['max'] ) : '';
		$scale_text = isset( $row['scale'] ) ? trim( (string) $row['scale'] ) : '';
		$min        = self::decimal_bound( $min_text, $prefix . '.min', __( 'Enter a minimum as a whole or decimal number.', 'formstead' ), $errors );
		$max        = self::decimal_bound( $max_text, $prefix . '.max', __( 'Enter a maximum as a whole or decimal number.', 'formstead' ), $errors );

		if ( '' === $scale_text ) {
			$scale = 0;
		} elseif ( 1 === preg_match( '/^[0-6]$/', $scale_text ) ) {
			$scale = (int) $scale_text;
		} else {
			$errors[ $prefix . '.scale' ] = __( 'Enter a number of decimal places from 0 to 6.', 'formstead' );
			$scale                        = 0;
		}

		if ( null !== $min && null !== $max && Decimal::compare( $min, $max ) > 0 ) {
			$errors[ $prefix . '.min' ] = __( 'Enter a minimum that is not greater than the maximum.', 'formstead' );
		}

		return new FieldSettings( $min, $max, $scale, array(), false );
	}

	/**
	 * A canonical bound, or null when the input is empty or invalid.
	 *
	 * @param string                $text   Posted bound.
	 * @param string                $key    Error key.
	 * @param string                $message Message stored when the bound is not a decimal.
	 * @param array<string, string> $errors Error map, updated in place.
	 */
	private static function decimal_bound( string $text, string $key, string $message, array &$errors ): ?string {
		if ( '' === $text ) {
			return null;
		}

		$canonical = Decimal::canonical( $text );
		if ( null === $canonical ) {
			$errors[ $key ] = $message;
			return null;
		}

		return $canonical;
	}

	/**
	 * Choice options from a posted row.
	 *
	 * @param array<mixed>          $row    Posted field row.
	 * @param string                $prefix Error key prefix.
	 * @param array<string, string> $errors Error map, updated in place.
	 */
	private static function choice_settings( array $row, string $prefix, array &$errors ): FieldSettings {
		$text = isset( $row['options'] ) && is_string( $row['options'] ) ? $row['options'] : '';

		return new FieldSettings( null, null, 0, self::options( $text, $prefix, $errors ), self::is_checked( $row['multiple'] ?? null ) );
	}

	/**
	 * Options from `value|Label` lines.
	 *
	 * A line without a label uses the value as the label. Empty lines are ignored.
	 *
	 * @param string                $text   Posted options.
	 * @param string                $prefix Error key prefix.
	 * @param array<string, string> $errors Error map, updated in place.
	 * @return list<array{value: string, label: string}>
	 */
	private static function options( string $text, string $prefix, array &$errors ): array {
		$lines   = preg_split( '/\R/', $text );
		$options = array();
		$seen    = array();
		$key     = $prefix . '.options';

		foreach ( is_array( $lines ) ? $lines : array() as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}

			$parts = explode( '|', $line, 2 );
			$value = trim( $parts[0] );
			$label = isset( $parts[1] ) ? trim( $parts[1] ) : '';
			if ( '' === $label ) {
				$label = $value;
			}

			if ( '' === $value || mb_strlen( $value ) > 190 || 1 === preg_match( '/[[:cntrl:]]/', $value ) ) {
				$errors[ $key ] = __( 'Enter an option value up to 190 characters.', 'formstead' );
				continue;
			}
			if ( mb_strlen( $label ) > 190 || 1 === preg_match( '/[[:cntrl:]]/', $label ) ) {
				$errors[ $key ] = __( 'Enter an option label up to 190 characters.', 'formstead' );
				continue;
			}
			if ( isset( $seen[ $value ] ) ) {
				$errors[ $key ] = __( 'Option values must be unique.', 'formstead' );
				continue;
			}

			$seen[ $value ] = true;
			$options[]      = array(
				'value' => $value,
				'label' => $label,
			);
		}

		if ( count( $options ) > 100 ) {
			$errors[ $key ] = __( 'Enter up to 100 options.', 'formstead' );
			return array();
		}

		if ( array() === $options && ! isset( $errors[ $key ] ) ) {
			$errors[ $key ] = __( 'Enter at least one option.', 'formstead' );
		}

		return $options;
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
