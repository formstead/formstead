<?php
/**
 * Standalone HTML integration contract.
 *
 * @package Formstead
 */

namespace Formstead\Tests\Integration;

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- native DOM textContent property.

use DOMDocument;
use DOMElement;
use DOMXPath;
use Formstead\Admin\FormsPage;
use Formstead\Database\Schema;
use Formstead\Forms\Field;
use Formstead\Forms\FieldType;
use Formstead\Forms\Form;
use Formstead\Forms\FormInput;
use Formstead\Forms\FormRepository;
use Formstead\Integration\CodeGenerator;
use WP_UnitTestCase;

/**
 * Checks parsed output and its embedding in the existing editor.
 */
final class CodeGeneratorTest extends WP_UnitTestCase {

	/**
	 * Restore the editor's request state.
	 */
	public function tear_down(): void {
		$_GET                      = array();
		$_POST                     = array();
		$_REQUEST                  = array();
		$_SERVER['REQUEST_METHOD'] = 'GET';
		FormsPage::reset_request();
		parent::tear_down();
	}

	/**
	 * Every saved field produces an accessible control without changing values.
	 */
	public function test_all_controls_match_the_persisted_schema(): void {
		$form  = $this->saved_form();
		$xpath = $this->parse( ( new CodeGenerator() )->generate( $form, $this->endpoint( $form ) ) );

		$this->assertSame( 1, $xpath->query( '//form' )->length );
		$this->assertSame( 0, $xpath->query( '//script' )->length );
		foreach ( $form->fields() as $field ) {
			$name = $field->name() . ( $field->settings()->multiple() ? '[]' : '' );
			$node = $xpath->query( '//*[@name="' . $name . '"]' )->item( 0 );
			$this->assertInstanceOf( DOMElement::class, $node );
			$this->assertSame( $field->required(), $node->hasAttribute( 'required' ) );
			$label = $xpath->query( '//label[@for="' . $node->getAttribute( 'id' ) . '"]' )->item( 0 );
			$this->assertNotNull( $label );
			$this->assertSame( $field->label(), $label->textContent );
			if ( in_array( $field->type(), array( FieldType::Text, FieldType::Email, FieldType::Textarea ), true ) ) {
				$this->assertSame( (string) $field->max_length(), $node->getAttribute( 'maxlength' ) );
			}
			if ( FieldType::Choice === $field->type() ) {
				$this->assertSame( $field->settings()->multiple(), $node->hasAttribute( 'multiple' ) );
				$options = $xpath->query( 'option[@value!=""]', $node );
				$this->assertSame( count( $field->settings()->options() ), $options->length );
				foreach ( $field->settings()->options() as $index => $option ) {
					$this->assertSame( $option['value'], $options->item( $index )->getAttribute( 'value' ) );
					$this->assertSame( $option['label'], $options->item( $index )->textContent );
				}
			}
		}
		$amount = $xpath->query( '//input[@name="amount"]' )->item( 0 );
		$this->assertSame( '9007199254740993.123456', $amount->getAttribute( 'max' ) );
		$this->assertSame( '-0.005', $amount->getAttribute( 'min' ) );
		$this->assertSame( 'any', $amount->getAttribute( 'step' ) );
		$this->assertSame( 1, $xpath->query( '//input[@name="agree" and @type="checkbox" and @value="1"]' )->length );
		$this->assertSame( 0, $xpath->query( '//*[@name="_fs_idempotency_key" or @name="_fs_elapsed"]' )->length );
		$this->assertSame( 1, $xpath->query( '//div[@aria-hidden="true"]/input[@name="_fs_honeypot" and @type="text" and @tabindex="-1"]' )->length );
	}

	/**
	 * Both permalink formats and subdirectories survive HTML escaping.
	 */
	public function test_endpoint_round_trip_in_both_permalink_modes(): void {
		$form = $this->saved_form();
		foreach ( array( '/%postname%/', '' ) as $structure ) {
			$this->set_permalink_structure( $structure );
			$endpoint = $this->endpoint( $form );
			$xpath    = $this->parse( ( new CodeGenerator() )->generate( $form, $endpoint ) );
			$this->assertSame( $endpoint, $xpath->query( '//form' )->item( 0 )->getAttribute( 'action' ) );
		}
		$endpoint = 'http://example.org/subdirectory/?rest_route=/formstead/v1/forms/' . $form->public_id() . '/submissions&test=1';
		$xpath    = $this->parse( ( new CodeGenerator() )->generate( $form, $endpoint ) );
		$this->assertSame( $endpoint, $xpath->query( '//form' )->item( 0 )->getAttribute( 'action' ) );
	}

	/**
	 * Legacy empty labels fall back to machine names without relaxing the editor.
	 */
	public function test_empty_label_falls_back_to_name(): void {
		$form  = new Form( 1, 'fs_abcdefghijklmnop', 'Legacy', true, array( new Field( 'legacy', '', FieldType::Text, false, 10 ) ), 1, 'http://example.org/thanks', '', '' );
		$xpath = $this->parse( ( new CodeGenerator() )->generate( $form, $this->endpoint( $form ) ) );
		$this->assertSame( 'legacy', $xpath->query( '//label' )->item( 0 )->textContent );
	}

	/**
	 * Enhanced controls preserve native HTML and have local error descriptions.
	 */
	public function test_javascript_variant_has_accessible_errors_and_one_inline_script(): void {
		$form  = $this->saved_form();
		$code  = ( new CodeGenerator() )->generate( $form, $this->endpoint( $form ), 'javascript' );
		$xpath = $this->parse( $code );
		$this->assertSame( 1, $xpath->query( '//script' )->length );
		$this->assertSame( 1, $xpath->query( '//form[@method="post"]' )->length );
		$this->assertSame( count( $form->fields() ), $xpath->query( '//*[@data-formstead-field]' )->length );
		foreach ( $xpath->query( '//*[@data-formstead-field]' ) as $field ) {
			foreach ( explode( ' ', $field->getAttribute( 'aria-describedby' ) ) as $id ) {
				$this->assertSame( 1, $xpath->query( '//form//*[@id="' . $id . '"]' )->length );
			}
		}
		$this->assertSame( 1, $xpath->query( '//form//*[@role="status"]' )->length );
	}

	/**
	 * Translations cannot close the generated inline script.
	 */
	public function test_inline_messages_are_safe_in_a_script_context(): void {
		$filter = static function ( string $translation, string $text, string $domain ): string {
			return 'formstead' === $domain && 'Thanks. Your submission was accepted.' === $text
				? '</script><script>alert("unsafe")</script>'
				: $translation;
		};
		add_filter( 'gettext', $filter, 10, 3 );
		try {
			$form = $this->saved_form();
			$code = ( new CodeGenerator() )->generate( $form, $this->endpoint( $form ), 'javascript' );
		} finally {
			remove_filter( 'gettext', $filter, 10 );
		}
		$this->assertSame( 1, $this->parse( $code )->query( '//script' )->length );
		$this->assertStringNotContainsString( '</script><script>', $code );
	}

	/**
	 * Framework source keeps hostile schema text and exact decimals as JSON strings.
	 */
	public function test_framework_config_round_trips_without_executable_schema_text(): void {
		$form = $this->saved_form();
		foreach ( array( 'astro', 'react' ) as $variant ) {
			$code = ( new CodeGenerator() )->generate( $form, $this->endpoint( $form ), $variant );
			$this->assertSame( 1, preg_match( '/const config = (.+);/', $code, $matches ) );
			$config = json_decode( $matches[1], true, 512, JSON_THROW_ON_ERROR );
			$this->assertSame( $this->endpoint( $form ), $config['endpoint'] );
			$this->assertSame( array_map( static fn( $field ) => $field->to_array(), $form->fields() ), $config['fields'] );
			$this->assertStringNotContainsString( '</script>', $code );
		}
	}

	/**
	 * The variant allowlist must prevent paths from selecting arbitrary templates.
	 */
	public function test_unknown_variant_is_rejected(): void {
		$form = $this->saved_form();
		$this->expectException( \InvalidArgumentException::class );
		( new CodeGenerator() )->generate( $form, $this->endpoint( $form ), '../admin/integration-code' );
	}

	/**
	 * No runtime form is injected into the editor, even with closing-tag labels.
	 */
	public function test_panel_contains_readonly_source_outside_the_editor_form(): void {
		$form = $this->saved_form();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_GET['form'] = (string) $form->id();
		ob_start();
		FormsPage::render();
		$html  = (string) ob_get_clean();
		$xpath = $this->parse( $html );
		$node  = $xpath->query( '//textarea[@id="formstead-integration-html"]' )->item( 0 );
		$this->assertInstanceOf( DOMElement::class, $node );
		$this->assertSame( 0, $xpath->query( '//form//textarea[@id="formstead-integration-html"]' )->length );
		$this->assertFalse( $node->hasAttribute( 'name' ) );
		$this->assertTrue( $node->hasAttribute( 'readonly' ) );
		$button = $xpath->query( '//button[@id="formstead-copy-code"]' )->item( 0 );
		$this->assertSame( 'button', $button->getAttribute( 'type' ) );
		$this->assertTrue( $xpath->query( '//*[@id="formstead-copy-controls"]' )->item( 0 )->hasAttribute( 'hidden' ) );
		$this->assertSame( 0, $xpath->query( '//form//button[@id="formstead-copy-code"]' )->length );
		$this->assertTrue( $xpath->query( '//*[@id="formstead-integration-unsaved"]' )->item( 0 )->hasAttribute( 'hidden' ) );
		$this->assertSame( ( new CodeGenerator() )->generate( $form, $this->endpoint( $form ) ), $node->textContent );
		$this->assertSame( 0, $xpath->query( '//form[@class="formstead-form"]' )->length );
	}

	/**
	 * Viewing the editor and generating every variant must remain read-only.
	 */
	public function test_rendering_and_generating_code_do_not_write_or_change_versions(): void {
		$form    = $this->saved_form();
		$before  = ( new FormRepository() )->find_by_id( $form->id() );
		$version = get_option( Schema::VERSION_OPTION );
		$writes  = array();
		$record  = static function ( string $query ) use ( &$writes ): string {
			if ( preg_match( '/^\s*(INSERT|UPDATE|DELETE|REPLACE|ALTER|CREATE|DROP|TRUNCATE)\b/i', $query ) && false !== strpos( $query, 'formstead_' ) ) {
				$writes[] = $query;
			}
			return $query;
		};
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_GET['form'] = (string) $form->id();
		add_filter( 'query', $record );
		ob_start();
		try {
			FormsPage::render();
			foreach ( array( 'html', 'javascript', 'astro', 'react' ) as $variant ) {
				$this->assertNotEmpty( ( new CodeGenerator() )->generate( $form, $this->endpoint( $form ), $variant ) );
			}
		} finally {
			ob_end_clean();
			remove_filter( 'query', $record );
		}
		$this->assertSame( array(), $writes );
		$this->assertEquals( $before, ( new FormRepository() )->find_by_id( $form->id() ) );
		$this->assertSame( $version, get_option( Schema::VERSION_OPTION ) );
	}

	/**
	 * Script loading and rendering agree on normalized editor parameters.
	 */
	public function test_script_selection_matches_the_rendered_screen(): void {
		$form = $this->saved_form();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$property = new \ReflectionProperty( FormsPage::class, 'hook' );
		$previous = $property->getValue();
		FormsPage::set_hook( 'formstead-test-forms' );
		try {
			foreach ( array( array(), array( 'form' => 'abc' ), array( 'form' => '0' ), array( 'form' => (string) $form->id() ), array( 'action' => 'NEW' ) ) as $query ) {
				$_GET = $query;
				wp_dequeue_script( 'formstead-integration-code' );
				FormsPage::enqueue( 'formstead-test-forms' );
				ob_start();
				FormsPage::render();
				$html = (string) ob_get_clean();
				$this->assertSame( false !== strpos( $html, 'id="formstead-form-editor"' ), wp_script_is( 'formstead-integration-code', 'enqueued' ) );
			}
		} finally {
			FormsPage::set_hook( $previous );
			wp_dequeue_script( 'formstead-integration-code' );
		}
	}

	/**
	 * Disabled forms remain copyable, while new forms have no fabricated code.
	 */
	public function test_new_and_disabled_panel_states(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_GET['action'] = 'new';
		ob_start();
		FormsPage::render();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'Save the form to generate integration code.', $html );
		$this->assertStringNotContainsString( 'id="formstead-integration-html"', $html );

		$form = $this->saved_form( false );
		$_GET = array( 'form' => (string) $form->id() );
		ob_start();
		FormsPage::render();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'This form is disabled and does not accept submissions.', $html );
		$this->assertStringContainsString( 'id="formstead-integration-html"', $html );
	}

	/**
	 * The capability protecting the editor also protects generated code.
	 */
	public function test_generated_code_requires_manage_forms(): void {
		$form = $this->saved_form();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$_GET['form'] = (string) $form->id();
		$this->expectException( \WPDieException::class );
		FormsPage::render();
	}

	/**
	 * Failed edits do not leak an unpersisted schema into the integration source.
	 */
	public function test_failed_save_keeps_the_persisted_code(): void {
		$form = $this->saved_form();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_REQUEST['_wpnonce']      = wp_create_nonce( 'formstead_save_form' );
		$_POST                     = array(
			'form_id'     => (string) $form->id(),
			'name'        => '',
			'success_url' => $form->success_url(),
			'fields'      => array(
				array(
					'name'  => 'unsaved_field',
					'label' => 'Unsaved',
					'type'  => 'text',
				),
			),
		);
		FormsPage::handle_load();
		ob_start();
		FormsPage::render();
		$html = (string) ob_get_clean();
		$node = $this->parse( $html )->query( '//textarea[@id="formstead-integration-html"]' )->item( 0 );
		$this->assertNotNull( $node );
		$this->assertSame( ( new CodeGenerator() )->generate( $form, $this->endpoint( $form ) ), $node->textContent );
		$this->assertStringContainsString( 'Unsaved changes. This code uses the last saved configuration.', $html );
		$this->assertFalse( $this->parse( $html )->query( '//*[@id="formstead-integration-unsaved"]' )->item( 0 )->hasAttribute( 'hidden' ) );
		$this->assertSame( $form->schema_version(), ( new FormRepository() )->find_by_id( $form->id() )->schema_version() );
	}

	/**
	 * Save the shared all-fields fixture through the real editor contract.
	 *
	 * @param bool $enabled Whether to accept submissions.
	 */
	private function saved_form( bool $enabled = true ): Form {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local test fixture.
		$fixture             = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/integration-code/all-fields.json' ), true, 512, JSON_THROW_ON_ERROR );
		$input               = $fixture['form_input'];
		$input['is_enabled'] = $enabled ? '1' : '';
		$form                = FormInput::validate( $input )->form();
		$this->assertInstanceOf( Form::class, $form );
		$saved = ( new FormRepository() )->insert( $form );
		$this->assertInstanceOf( Form::class, $saved );
		return $saved;
	}

	/**
	 * Resolve the same endpoint as the editor.
	 *
	 * @param Form $form Saved form.
	 */
	private function endpoint( Form $form ): string {
		return rest_url( 'formstead/v1/forms/' . $form->public_id() . '/submissions' );
	}

	/**
	 * Parse generated UTF-8 source, suppressing libxml's HTML5 tag warnings.
	 *
	 * @param string $html Source to inspect.
	 */
	private function parse( string $html ): DOMXPath {
		$document = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		try {
			$document->loadHTML( '<?xml encoding="UTF-8">' . $html );
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors( $previous );
		}
		return new DOMXPath( $document );
	}
}
