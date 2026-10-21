<?php
/**
 * Tests for the suggested privacy-policy text.
 *
 * @package Formstead
 */

namespace Formstead\Tests\Privacy;

use Formstead\Privacy\PrivacyPolicy;
use WP_UnitTestCase;

/**
 * The guide text names the stored data and does not claim a legal result.
 */
class PrivacyPolicyTest extends WP_UnitTestCase {

	/**
	 * The suggested text is what a site can paste, and it stays descriptive.
	 */
	public function test_suggested_text_describes_storage_without_a_legal_claim(): void {
		$content = strtolower( PrivacyPolicy::content() );

		$this->assertStringContainsString( 'formstead stores form submissions', $content );
		$this->assertStringContainsString( 'hmac', $content );
		$this->assertStringContainsString( 'audit log', $content );
		$this->assertStringContainsString( 'browser history', $content );
		$this->assertStringContainsString( 'host backup', $content );
		$this->assertStringNotContainsString( 'gdpr', $content );
		$this->assertStringNotContainsString( 'compliant', $content );
	}

	/**
	 * WordPress shows the text from the privacy guide screen.
	 */
	public function test_the_text_is_added_on_admin_init(): void {
		PrivacyPolicy::register();

		$this->assertNotFalse( has_action( 'admin_init', array( PrivacyPolicy::class, 'add' ) ) );
	}
}
