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
