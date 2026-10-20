<?php
/**
 * Tests for form input validation.
 *
 * @package Formstead
 */

namespace Formstead\Tests\Forms;

use Formstead\Forms\FieldType;
use Formstead\Forms\FormInput;
use WP_UnitTestCase;

/**
 * Covers field rules and the success URL.
 */
final class FormInputTest extends WP_UnitTestCase {

	/**
	 * A complete form is accepted, with default lengths when the length is empty.
	 */
	public function test_valid_input_builds_a_form(): void {
		$result = FormInput::validate( $this->input() );

		$this->assertTrue( $result->is_valid() );
		$form = $result->form();
		$this->assertNotNull( $form );
		$this->assertSame( 'Contact', $form->name() );
		$this->assertTrue( $form->is_enabled() );
		$this->assertSame( 'https://example.com:8888/thanks', $form->success_url() );
		$this->assertCount( 2, $form->fields() );
		$this->assertSame( 255, $form->fields()[0]->max_length() );
		$this->assertSame( FieldType::Text, $form->fields()[0]->type() );
		$this->assertSame( 10000, $form->fields()[1]->max_length() );
		$this->assertTrue( $form->fields()[0]->required() );
	}

	/**
	 * Blank and removed rows are ignored.
	 */
	public function test_blank_and_removed_rows_are_ignored(): void {
		$input             = $this->input();
		$input['fields'][] = array(
			'name'       => '',
			'label'      => '',
			'type'       => '',
			'max_length' => '',
		);
		$input['fields'][] = array(
			'name'   => 'dropped',
			'label'  => 'Dropped',
			'type'   => 'text',
			'remove' => '1',
		);

		$result = FormInput::validate( $input );

		$this->assertTrue( $result->is_valid() );
		$this->assertCount( 2, $result->form()->fields() );
	}

	/**
	 * Unsafe or non-absolute success URLs are rejected.
	 *
	 * @dataProvider invalid_url_provider
	 * @param string $url Success URL.
	 */
	public function test_invalid_success_urls_are_rejected( string $url ): void {
		$input                = $this->input();
		$input['success_url'] = $url;

		$result = FormInput::validate( $input );

		$this->assertFalse( $result->is_valid() );
		$this->assertArrayHasKey( 'success_url', $result->errors() );
	}

	/**
	 * Success URLs that must not be stored.
	 *
	 * @return array<string, array{string}>
	 */
	public function invalid_url_provider(): array {
		return array(
			'javascript' => array( 'javascript:alert(1)' ),
			'relative'   => array( '/thanks' ),
			'userinfo'   => array( 'https://user:pass@example.com/' ),
			'ftp'        => array( 'ftp://example.com/' ),
			'space'      => array( 'https://example.com/a b' ),
		);
	}

	/**
	 * Duplicate names, reserved shapes, and bad lengths are rejected.
	 */
	public function test_field_rules_are_enforced(): void {
		$input             = $this->input();
		$input['fields'][] = array(
			'name'       => 'name',
			'label'      => 'Again',
			'type'       => 'text',
			'max_length' => '',
		);
		$input['fields'][] = array(
			'name'       => '_fs_honeypot',
			'label'      => 'Trap',
			'type'       => 'text',
			'max_length' => '',
		);
		$input['fields'][] = array(
			'name'       => '1bad',
			'label'      => 'Bad',
			'type'       => 'nope',
			'max_length' => '0',
		);

		$result = FormInput::validate( $input );

		$this->assertFalse( $result->is_valid() );
		$this->assertArrayHasKey( 'fields.2.name', $result->errors() );
		$this->assertArrayHasKey( 'fields.3.name', $result->errors() );
		$this->assertArrayHasKey( 'fields.4.name', $result->errors() );
		$this->assertArrayHasKey( 'fields.4.type', $result->errors() );
	}

	/**
	 * A form with no usable fields is rejected.
	 */
	public function test_at_least_one_field_is_required(): void {
		$input           = $this->input();
		$input['fields'] = array(
			array(
				'name'  => '',
				'label' => '',
				'type'  => '',
			),
		);

		$result = FormInput::validate( $input );

		$this->assertArrayHasKey( 'fields', $result->errors() );
	}

	/**
	 * Posted values for a valid contact form.
	 *
	 * @return array<string, mixed>
	 */
	private function input(): array {
		return array(
			'name'        => 'Contact',
			'is_enabled'  => '1',
			'success_url' => 'https://example.com:8888/thanks',
			'fields'      => array(
				array(
					'name'       => 'name',
					'label'      => 'Name',
					'type'       => 'text',
					'required'   => '1',
					'max_length' => '',
				),
				array(
					'name'       => 'message',
					'label'      => 'Message',
					'type'       => 'textarea',
					'required'   => '',
					'max_length' => '',
				),
			),
		);
	}
}
