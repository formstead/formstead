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
		$this->assertSame( 30, $form->retention_days() );
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
	 * A number field keeps its canonical bounds and scale.
	 */
	public function test_number_field_stores_bounds_and_scale(): void {
		$input             = $this->input();
		$input['fields'][] = array(
			'name'  => 'qty',
			'label' => 'Quantity',
			'type'  => 'number',
			'min'   => '01.50',
			'max'   => '10',
			'scale' => '2',
		);

		$result = FormInput::validate( $input );
		$this->assertTrue( $result->is_valid() );
		$field = $result->form()->fields()[2];
		$this->assertSame( FieldType::Number, $field->type() );
		$this->assertSame( '1.5', $field->settings()->min() );
		$this->assertSame( '10', $field->settings()->max() );
		$this->assertSame( 2, $field->settings()->scale() );
	}

	/**
	 * A choice field parses value|Label lines.
	 */
	public function test_choice_field_parses_options(): void {
		$input             = $this->input();
		$input['fields'][] = array(
			'name'     => 'color',
			'label'    => 'Color',
			'type'     => 'choice',
			'options'  => "red|Red\nblue\n",
			'multiple' => '1',
		);

		$result = FormInput::validate( $input );
		$this->assertTrue( $result->is_valid() );
		$field = $result->form()->fields()[2];
		$this->assertSame(
			array(
				array(
					'value' => 'red',
					'label' => 'Red',
				),
				array(
					'value' => 'blue',
					'label' => 'blue',
				),
			),
			$field->settings()->options()
		);
		$this->assertTrue( $field->settings()->multiple() );
	}

	/**
	 * A choice without options is rejected.
	 */
	public function test_choice_field_requires_an_option(): void {
		$input             = $this->input();
		$input['fields'][] = array(
			'name'    => 'color',
			'label'   => 'Color',
			'type'    => 'choice',
			'options' => '   ',
		);

		$result = FormInput::validate( $input );

		$this->assertArrayHasKey( 'fields.2.options', $result->errors() );
	}

	/**
	 * Origins are normalized and an empty rate uses the global limit.
	 */
	public function test_protection_settings_are_normalized(): void {
		$input                    = $this->input();
		$input['allowed_origins'] = "https://Forms.Example:443\nhttp://forms.example:8080\n";
		$input['rate_per_minute'] = '2';
		$input['rate_per_hour']   = '';

		$result = FormInput::validate( $input );

		$this->assertTrue( $result->is_valid() );
		$protection = $result->form()->protection();
		$this->assertSame( array( 'https://forms.example', 'http://forms.example:8080' ), $protection->allowed_origins() );
		$this->assertSame( 2, $protection->rate_per_minute() );
		$this->assertNull( $protection->rate_per_hour() );
	}

	/**
	 * A path, a literal null origin, and a rate above the ceiling are rejected.
	 */
	public function test_invalid_protection_settings_are_rejected(): void {
		$input                    = $this->input();
		$input['allowed_origins'] = "https://forms.example/path\nnull";
		$input['rate_per_minute'] = '61';
		$input['rate_per_hour']   = '0';

		$result = FormInput::validate( $input );

		$this->assertArrayHasKey( 'allowed_origins', $result->errors() );
		$this->assertArrayHasKey( 'rate_per_minute', $result->errors() );
		$this->assertArrayHasKey( 'rate_per_hour', $result->errors() );
	}

	/**
	 * Recipients are stored, and a reply-to value must name an email field.
	 */
	public function test_notification_recipients_are_validated(): void {
		$input                         = $this->input();
		$input['fields'][]             = array(
			'name'       => 'email',
			'label'      => 'Email',
			'type'       => 'email',
			'required'   => '',
			'max_length' => '',
		);
		$input['notify_recipients']    = "Owner@Example.com\nowner@example.com\nsecond@example.com";
		$input['reply_to_field']       = 'email';
		$input['mail_include_content'] = '1';

		$result = FormInput::validate( $input );
		$form   = $result->form();

		$this->assertTrue( $result->is_valid() );
		$this->assertNotNull( $form );
		$this->assertSame( array( 'Owner@Example.com', 'second@example.com' ), $form->notifications()->recipients() );
		$this->assertSame( 'email', $form->notifications()->reply_to_field() );
		$this->assertTrue( $form->notifications()->includes_content() );

		$input['notify_recipients'] = "owner@example.com\nnot-an-email";
		$input['reply_to_field']    = 'name';
		$rejected                   = FormInput::validate( $input );

		$this->assertArrayHasKey( 'notify_recipients', $rejected->errors() );
		$this->assertArrayHasKey( 'reply_to_field', $rejected->errors() );
		$this->assertNull( $rejected->form() );
	}

	/**
	 * Zero keeps submissions, and 3650 is the longest period.
	 */
	public function test_retention_period_accepts_zero_and_the_ceiling(): void {
		$forever                   = $this->input();
		$forever['retention_days'] = '0';
		$kept                      = FormInput::validate( $forever );
		$this->assertTrue( $kept->is_valid() );
		$this->assertSame( 0, $kept->form()->retention_days() );

		$longest                   = $this->input();
		$longest['retention_days'] = '3650';
		$limited                   = FormInput::validate( $longest );
		$this->assertTrue( $limited->is_valid() );
		$this->assertSame( 3650, $limited->form()->retention_days() );
	}

	/**
	 * A blank period and a period past ten years are rejected.
	 */
	public function test_retention_period_rejects_blank_and_oversized_values(): void {
		foreach ( array( '', '3651', '30 days' ) as $days ) {
			$input                   = $this->input();
			$input['retention_days'] = $days;
			$result                  = FormInput::validate( $input );
			$this->assertArrayHasKey( 'retention_days', $result->errors(), $days );
		}
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
