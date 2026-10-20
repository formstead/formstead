<?php
/**
 * Tests for submission normalization.
 *
 * @package Formstead
 */

namespace Formstead\Tests\Submissions;

use Formstead\Forms\Field;
use Formstead\Forms\FieldSettings;
use Formstead\Forms\FieldType;
use Formstead\Forms\Form;
use Formstead\Rest\JsonNumber;
use Formstead\Submissions\FieldError;
use Formstead\Submissions\SubmissionValidator;
use WP_UnitTestCase;

/**
 * Covers field types and normalization rules.
 */
final class SubmissionValidatorTest extends WP_UnitTestCase {

	/**
	 * A value is stored in its normalized form.
	 *
	 * @dataProvider valid_values
	 *
	 * @param Field                         $field    Field schema.
	 * @param mixed                         $raw      Raw input.
	 * @param string|bool|list<string>|null $expected Stored value, or null when it is omitted.
	 */
	public function test_valid_values_are_normalized( Field $field, mixed $raw, string|bool|array|null $expected ): void {
		$result = ( new SubmissionValidator() )->validate( $this->form( $field ), array( $field->name() => $raw ) );

		$this->assertTrue( $result->is_valid() );
		if ( null === $expected ) {
			$this->assertArrayNotHasKey( $field->name(), $result->values() );
			return;
		}

		$this->assertSame( $expected, $result->values()[ $field->name() ] );
	}

	/**
	 * A value is rejected with a stable code.
	 *
	 * @dataProvider invalid_values
	 *
	 * @param Field      $field Field schema.
	 * @param mixed      $raw   Raw input. Null means the field is absent.
	 * @param FieldError $error Expected error.
	 */
	public function test_invalid_values_keep_their_code( Field $field, mixed $raw, FieldError $error ): void {
		$input  = null === $raw ? array() : array( $field->name() => $raw );
		$result = ( new SubmissionValidator() )->validate( $this->form( $field ), $input );

		$this->assertFalse( $result->is_valid() );
		$this->assertSame( $error, $result->errors()[ $field->name() ] );
		$this->assertSame( array(), $result->values() );
	}

	/**
	 * Values that can be stored.
	 *
	 * @return array<string, array{0: Field, 1: mixed, 2: string|bool|list<string>|null}>
	 */
	public function valid_values(): array {
		return array(
			'text trimmed'           => array( $this->text( false ), '  Ada  ', 'Ada' ),
			'optional text omitted'  => array( $this->text( false ), '   ', null ),
			'textarea newlines'      => array( $this->field( 'note', FieldType::Textarea, false, 100 ), "A\r\nB\rC", "A\nB\nC" ),
			'email'                  => array( $this->field( 'email', FieldType::Email, true, 255 ), ' A@B.co ', 'A@B.co' ),
			'integer number'         => array( $this->number( 0, null, null ), '007', '7' ),
			'decimal number'         => array( $this->number( 2, '0', '10' ), '01.50', '1.5' ),
			'json integer'           => array( $this->number( 0, null, null ), new JsonNumber( '12' ), '12' ),
			'json decimal'           => array( $this->number( 2, null, null ), new JsonNumber( '0.10' ), '0.1' ),
			'json text number'       => array( $this->text( false ), new JsonNumber( '12' ), '12' ),
			'checkbox on'            => array( $this->field( 'agree', FieldType::Boolean, true, 5 ), 'on', true ),
			'checkbox absent'        => array( $this->field( 'agree', FieldType::Boolean, false, 5 ), null, false ),
			'checkbox false'         => array( $this->field( 'agree', FieldType::Boolean, false, 5 ), false, false ),
			'choice'                 => array( $this->choice( false ), 'red', 'red' ),
			'choice multiple'        => array( $this->choice( true ), array( 'blue', 'red', 'red' ), array( 'red', 'blue' ) ),
			'choice multiple string' => array( $this->choice( true ), 'blue', array( 'blue' ) ),
		);
	}

	/**
	 * Values that are rejected.
	 *
	 * @return array<string, array{0: Field, 1: mixed, 2: FieldError}>
	 */
	public function invalid_values(): array {
		return array(
			'required text'     => array( $this->text( true ), null, FieldError::Required ),
			'text newline'      => array( $this->text( false ), "A\nB", FieldError::Invalid ),
			'too long'          => array( $this->text( false, 2 ), 'Ada', FieldError::TooLong ),
			'bad email'         => array( $this->field( 'email', FieldType::Email, true, 255 ), 'not-an-email', FieldError::InvalidEmail ),
			'bad number'        => array( $this->number( 0, null, null ), '1e2', FieldError::InvalidNumber ),
			'too precise'       => array( $this->number( 0, null, null ), '1.5', FieldError::TooPrecise ),
			'too small'         => array( $this->number( 0, '2', null ), '1', FieldError::TooSmall ),
			'too large'         => array( $this->number( 0, null, '2' ), '3', FieldError::TooLarge ),
			'json exponent'     => array( $this->number( 2, null, null ), new JsonNumber( '1e2' ), FieldError::InvalidNumber ),
			'json checkbox'     => array( $this->field( 'agree', FieldType::Boolean, false, 5 ), new JsonNumber( '1' ), FieldError::Invalid ),
			'json choice list'  => array( $this->choice( true ), array( new JsonNumber( '1' ) ), FieldError::Invalid ),
			'required checkbox' => array( $this->field( 'agree', FieldType::Boolean, true, 5 ), null, FieldError::Required ),
			'bad checkbox'      => array( $this->field( 'agree', FieldType::Boolean, false, 5 ), 'yes', FieldError::Invalid ),
			'bad choice'        => array( $this->choice( false ), 'green', FieldError::InvalidChoice ),
			'choice array'      => array( $this->choice( false ), array( 'red' ), FieldError::Invalid ),
			'bad multiple'      => array( $this->choice( true ), array( 'green' ), FieldError::InvalidChoice ),
		);
	}

	/**
	 * A one-field form.
	 *
	 * @param Field $field Field schema.
	 */
	private function form( Field $field ): Form {
		return new Form( 1, 'fs_abcdefghijklmnop', 'Contact', true, array( $field ), 1, 'https://example.com/thanks', '', '' );
	}

	/**
	 * An optional or required text field.
	 *
	 * @param bool $required Whether the field is required.
	 * @param int  $length   Maximum length.
	 */
	private function text( bool $required, int $length = 255 ): Field {
		return $this->field( 'name', FieldType::Text, $required, $length );
	}

	/**
	 * A number field.
	 *
	 * @param int         $scale Decimal places.
	 * @param string|null $min   Minimum.
	 * @param string|null $max   Maximum.
	 */
	private function number( int $scale, ?string $min, ?string $max ): Field {
		return new Field( 'qty', 'Quantity', FieldType::Number, false, 255, new FieldSettings( $min, $max, $scale, array(), false ) );
	}

	/**
	 * A choice field with red and blue.
	 *
	 * @param bool $multiple Whether several values are allowed.
	 */
	private function choice( bool $multiple ): Field {
		return new Field(
			'color',
			'Color',
			FieldType::Choice,
			false,
			190,
			new FieldSettings(
				null,
				null,
				0,
				array(
					array(
						'value' => 'red',
						'label' => 'Red',
					),
					array(
						'value' => 'blue',
						'label' => 'Blue',
					),
				),
				$multiple
			)
		);
	}

	/**
	 * A field without extra settings.
	 *
	 * @param string    $name     Field name.
	 * @param FieldType $type     Field type.
	 * @param bool      $required Whether the field is required.
	 * @param int       $length   Maximum length.
	 */
	private function field( string $name, FieldType $type, bool $required, int $length ): Field {
		return new Field( $name, $name, $type, $required, $length );
	}
}
