<?php
/**
 * Intake assumptions shared by the planned integration-code variants.
 *
 * @package Formstead
 */

namespace Formstead\Tests\Integration;

use Formstead\Forms\FieldType;
use Formstead\Forms\Form;
use Formstead\Forms\FormInput;
use Formstead\Forms\FormRepository;
use Formstead\Submissions\SubmissionValidator;
use WP_UnitTestCase;

/**
 * Validates the reusable schema and payload before adding code generation.
 */
final class IntakeContractTest extends WP_UnitTestCase {

	/**
	 * The editor accepts the representative schema, including DOM property names.
	 */
	public function test_fixture_survives_persistence_with_all_field_types(): void {
		$fixture    = $this->fixture();
		$form       = $this->form();
		$repository = new FormRepository();
		$saved      = $repository->insert( $form );

		$this->assertInstanceOf( Form::class, $saved );
		$loaded = $repository->find_by_id( $saved->id() );
		$this->assertInstanceOf( Form::class, $loaded );

		$types = array();
		foreach ( $loaded->fields() as $index => $field ) {
			$this->assertSame( $fixture['form_input']['fields'][ $index ]['name'], $field->name() );
			$this->assertSame( $fixture['form_input']['fields'][ $index ]['label'], $field->label() );
			$types[ $field->type()->value ] = true;
		}
		$this->assertCount( count( $fixture['form_input']['fields'] ), $loaded->fields() );
		foreach ( FieldType::cases() as $type ) {
			$this->assertArrayHasKey( $type->value, $types );
		}

		$result = ( new SubmissionValidator() )->validate( $loaded, $fixture['submission'] );
		$this->assertTrue( $result->is_valid() );
		$this->assertSame( $fixture['normalized'], $result->values() );
	}

	/**
	 * Browser-shaped form data preserves exact decimals and repeated choices.
	 */
	public function test_urlencoded_browser_values_match_the_expected_payload(): void {
		$fixture = $this->fixture();
		$body    = http_build_query( $fixture['submission'], '', '&', PHP_QUERY_RFC3986 );
		// Browsers emit repeated name[] entries rather than numbered PHP indexes.
		$body = preg_replace( '/%5B\d+%5D=/', '%5B%5D=', $body );
		$this->assertIsString( $body );
		parse_str( $body, $input );

		$result = ( new SubmissionValidator() )->validate( $this->form(), $input );

		$this->assertTrue( $result->is_valid() );
		$this->assertSame( $fixture['normalized'], $result->values() );
	}

	/**
	 * Invalid inputs retain stable field codes for every generated control shape.
	 *
	 * @dataProvider invalid_values
	 * @param string $field Field name.
	 * @param mixed  $value Replacement value; null omits the field.
	 * @param string $code  Expected public field error code.
	 */
	public function test_invalid_browser_values( string $field, mixed $value, string $code ): void {
		$input = $this->fixture()['submission'];
		if ( null === $value ) {
			unset( $input[ $field ] );
		} else {
			$input[ $field ] = $value;
		}

		$result = ( new SubmissionValidator() )->validate( $this->form(), $input );

		$this->assertFalse( $result->is_valid() );
		$this->assertSame( array( $field ), array_keys( $result->errors() ) );
		$this->assertSame( $code, $result->errors()[ $field ]->value );
		$this->assertSame( array(), $result->values() );
	}

	/**
	 * Boundary cases for the representative schema.
	 *
	 * @return array<string, array{string, mixed, string}>
	 */
	public function invalid_values(): array {
		return array(
			'missing text'          => array( 'name', null, 'required' ),
			'text length'           => array( 'name', str_repeat( '🌿', 81 ), 'too_long' ),
			'email'                 => array( 'email', 'invalid', 'invalid_email' ),
			'textarea length'       => array( 'message', str_repeat( 'x', 1001 ), 'too_long' ),
			'exact decimal maximum' => array( 'amount', '9007199254740993.123457', 'too_large' ),
			'decimal precision'     => array( 'amount', '0.1234567', 'too_precise' ),
			'integer precision'     => array( 'quantity', '1.5', 'too_precise' ),
			'integer minimum'       => array( 'quantity', '0', 'too_small' ),
			'unchecked required'    => array( 'agree', null, 'required' ),
			'missing single choice' => array( 'topic', '', 'required' ),
			'unknown choice'        => array( 'topic', 'unknown', 'invalid_choice' ),
			'empty multiple choice' => array( 'interests', array(), 'required' ),
			'unknown multiple'      => array( 'interests', array( 'plants', 'unknown' ), 'invalid_choice' ),
		);
	}

	/**
	 * Load synthetic data reusable by the generator and future browser tests.
	 *
	 * @return array<string, mixed>
	 */
	private function fixture(): array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local test fixture.
		$json = file_get_contents( dirname( __DIR__ ) . '/fixtures/integration-code/all-fields.json' );
		$this->assertIsString( $json );
		$data = json_decode( $json, true, 512, JSON_THROW_ON_ERROR );
		$this->assertIsArray( $data );
		return $data;
	}

	/**
	 * Build through the same validation contract as the editor.
	 */
	private function form(): Form {
		$result = FormInput::validate( $this->fixture()['form_input'] );
		$this->assertTrue( $result->is_valid(), (string) wp_json_encode( $result->errors() ) );
		$form = $result->form();
		$this->assertInstanceOf( Form::class, $form );
		return $form;
	}
}
