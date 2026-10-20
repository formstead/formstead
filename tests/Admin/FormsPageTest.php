<?php
/**
 * Tests for the forms admin screen.
 *
 * @package Formstead
 */

namespace Formstead\Tests\Admin;

// phpcs:disable WordPress.Security.NonceVerification -- the test supplies the nonce that the screen checks.
// phpcs:disable WordPress.Security.ValidatedSanitizedInput -- the screen sanitizes input; the test posts raw values.

use Formstead\Admin\FormsPage;
use Formstead\Forms\FormRepository;
use Formstead\Forms\PublicId;
use WP_UnitTestCase;

/**
 * Covers the nonce, the capability, and a successful save.
 */
final class FormsPageTest extends WP_UnitTestCase {

	/**
	 * Clear request globals between tests.
	 */
	public function tear_down(): void {
		$_POST                     = array();
		$_GET                      = array();
		$_REQUEST                  = array();
		$_SERVER['REQUEST_METHOD'] = 'GET';
		FormsPage::reset_request();
		parent::tear_down();
	}

	/**
	 * A save without a nonce is rejected.
	 */
	public function test_save_without_nonce_is_rejected(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST['name']             = 'Contact';

		$this->expectException( \WPDieException::class );
		FormsPage::handle_load();
	}

	/**
	 * A save without the manage-forms capability is rejected.
	 */
	public function test_save_without_capability_is_rejected(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_REQUEST['_wpnonce']      = wp_create_nonce( 'formstead_save_form' );

		$this->expectException( \WPDieException::class );
		FormsPage::handle_load();
	}

	/**
	 * A valid save creates one form and ignores the blank row.
	 */
	public function test_valid_save_creates_a_form(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->post_form();

		add_filter(
			'wp_redirect',
			static function ( $location ) {
				throw new \RuntimeException( esc_html( (string) $location ) );
			}
		);

		try {
			FormsPage::handle_load();
			$this->fail( 'Expected a redirect after saving.' );
		} catch ( \RuntimeException $exception ) {
			$this->assertStringContainsString( 'page=formstead-forms', $exception->getMessage() );
			$this->assertStringContainsString( 'updated=1', $exception->getMessage() );
		}

		$forms = ( new FormRepository() )->all();
		$this->assertCount( 1, $forms );
		$this->assertSame( 'Contact', $forms[0]->name() );
		$this->assertTrue( PublicId::is_valid( $forms[0]->public_id() ) );
		$this->assertSame( 'https://example.com/thanks', $forms[0]->success_url() );
		$this->assertCount( 1, $forms[0]->fields() );
		$this->assertSame( 'email', $forms[0]->fields()[0]->name() );
	}

	/**
	 * A percent-encoded success URL and form name are stored unchanged.
	 */
	public function test_encoded_text_is_stored_unchanged(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->post_form();
		$_POST['name']        = '100%ab';
		$_POST['success_url'] = 'https://example.pl/dzi%C4%99kujemy';
		$_REQUEST             = $_POST;

		$this->save_expecting_redirect();

		$forms = ( new FormRepository() )->all();
		$this->assertCount( 1, $forms );
		$this->assertSame( '100%ab', $forms[0]->name() );
		$this->assertSame( 'https://example.pl/dzi%C4%99kujemy', $forms[0]->success_url() );
	}

	/**
	 * A field name outside the pattern is rejected and nothing is stored.
	 */
	public function test_field_name_outside_the_pattern_is_rejected(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		foreach ( array( 'Email', 'first name' ) as $name ) {
			FormsPage::reset_request();
			$this->post_form();
			$_POST['fields'][0]['name'] = $name;
			$_REQUEST                   = $_POST;

			FormsPage::handle_load();
		}

		$this->assertSame( array(), ( new FormRepository() )->all() );

		ob_start();
		FormsPage::render();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'lowercase letters', $html );
	}

	/**
	 * A repeated max length arrives as an array and does not raise a warning.
	 */
	public function test_non_text_max_length_does_not_warn(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->post_form();
		$_POST['fields'][0]['max_length'] = array( '1' );
		$_REQUEST                         = $_POST;

		$warnings = array();
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- this test asserts that no warning is raised.
		set_error_handler(
			static function ( int $severity, string $message ) use ( &$warnings ): bool {
				if ( E_WARNING === $severity ) {
					$warnings[] = $message;
				}
				return true;
			},
			E_WARNING
		);

		try {
			$this->save_expecting_redirect();
		} finally {
			restore_error_handler();
		}

		foreach ( $warnings as $warning ) {
			$this->assertStringNotContainsString( 'Array to string conversion', $warning );
		}
	}

	/**
	 * Save the posted form and stop the redirect.
	 */
	private function save_expecting_redirect(): void {
		$filter = static function ( $location ) {
			throw new \RuntimeException( esc_html( (string) $location ) );
		};
		add_filter( 'wp_redirect', $filter );

		try {
			FormsPage::handle_load();
			$this->fail( 'Expected a redirect after saving.' );
		} catch ( \RuntimeException $exception ) {
			$this->assertStringContainsString( 'updated=1', $exception->getMessage() );
		} finally {
			remove_filter( 'wp_redirect', $filter );
		}
	}

	/**
	 * Post a valid form plus the blank row the screen always includes.
	 */
	private function post_form(): void {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array(
			'name'        => 'Contact',
			'is_enabled'  => '1',
			'success_url' => 'https://example.com/thanks',
			'fields'      => array(
				array(
					'name'       => 'email',
					'label'      => 'Email',
					'type'       => 'email',
					'required'   => '1',
					'max_length' => '255',
				),
				array(
					'name'       => '',
					'label'      => '',
					'type'       => '',
					'max_length' => '',
				),
			),
		);
		$nonce                     = wp_create_nonce( 'formstead_save_form' );
		$_POST['_wpnonce']         = $nonce;
		$_REQUEST                  = $_POST;
		$_REQUEST['_wpnonce']      = $nonce;
	}
}
