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
use Formstead\Audit\AuditEvent;
use Formstead\Database\Schema;
use Formstead\Forms\Field;
use Formstead\Forms\FieldType;
use Formstead\Forms\Form;
use Formstead\Forms\FormProtection;
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
	 * An edit that still has the opened schema version is stored.
	 */
	public function test_edit_with_the_opened_version_saves(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$saved = $this->insert_contact_form();

		$this->post_form();
		$_POST['form_id']        = (string) $saved->id();
		$_POST['schema_version'] = (string) $saved->schema_version();
		$_POST['name']           = 'Support';
		$_REQUEST                = $_POST;

		$this->save_expecting_redirect();

		$loaded = ( new FormRepository() )->find_by_id( $saved->id() );
		$this->assertInstanceOf( Form::class, $loaded );
		$this->assertSame( 'Support', $loaded->name() );
		$this->assertSame( 1, $loaded->schema_version() );
	}

	/**
	 * A save from an older editor version changes nothing and asks for a reload.
	 */
	public function test_stale_schema_version_keeps_the_saved_form(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$saved  = $this->insert_contact_form();
		$bumped = ( new FormRepository() )->update(
			new Form(
				$saved->id(),
				$saved->public_id(),
				$saved->name(),
				true,
				array( new Field( 'email', 'Work email', FieldType::Email, true, 255 ) ),
				$saved->schema_version(),
				$saved->success_url(),
				$saved->created_at(),
				$saved->updated_at()
			)
		);
		$this->assertInstanceOf( Form::class, $bumped );

		$this->post_form();
		$_POST['form_id']            = (string) $saved->id();
		$_POST['schema_version']     = '1';
		$_POST['name']               = 'Renamed';
		$_POST['fields'][0]['label'] = 'Other';
		$_REQUEST                    = $_POST;

		FormsPage::handle_load();

		$loaded = ( new FormRepository() )->find_by_id( $saved->id() );
		$this->assertInstanceOf( Form::class, $loaded );
		$this->assertSame( 'Contact', $loaded->name() );
		$this->assertSame( 2, $loaded->schema_version() );
		$this->assertSame( 'Work email', $loaded->fields()[0]->label() );

		ob_start();
		FormsPage::render();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'Reload the screen', $html );
		$this->assertStringContainsString( 'name="schema_version" value="1"', $html );
	}

	/**
	 * A retention period is stored without a new field schema.
	 */
	public function test_retention_period_is_saved(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$saved = $this->insert_contact_form();

		$this->post_form();
		$_POST['form_id']        = (string) $saved->id();
		$_POST['schema_version'] = (string) $saved->schema_version();
		$_POST['retention_days'] = '14';
		$_REQUEST                = $_POST;

		$this->save_expecting_redirect();

		$loaded = ( new FormRepository() )->find_by_id( $saved->id() );
		$this->assertInstanceOf( Form::class, $loaded );
		$this->assertSame( 14, $loaded->retention_days() );
		$this->assertSame( 1, $loaded->schema_version() );
	}

	/**
	 * A period outside 0 to 3650 changes nothing and is shown again.
	 */
	public function test_invalid_retention_period_keeps_the_saved_form(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$saved = $this->insert_contact_form();

		$this->post_form();
		$_POST['form_id']        = (string) $saved->id();
		$_POST['schema_version'] = (string) $saved->schema_version();
		$_POST['name']           = 'Renamed';
		$_POST['retention_days'] = '3651';
		$_REQUEST                = $_POST;

		FormsPage::handle_load();

		$loaded = ( new FormRepository() )->find_by_id( $saved->id() );
		$this->assertInstanceOf( Form::class, $loaded );
		$this->assertSame( 'Contact', $loaded->name() );
		$this->assertSame( 30, $loaded->retention_days() );

		$html = $this->render();
		$this->assertStringContainsString( 'from 0 to 3650', $html );
		$this->assertStringContainsString( 'value="3651"', $html );
	}

	/**
	 * An allowlist and both rate overrides are stored without a new schema version.
	 */
	public function test_protection_settings_are_saved(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$saved = $this->insert_contact_form();

		$this->post_form();
		$_POST['form_id']         = (string) $saved->id();
		$_POST['schema_version']  = (string) $saved->schema_version();
		$_POST['allowed_origins'] = "https://Forms.Example:443\nhttp://forms.example:8080";
		$_POST['rate_per_minute'] = '2';
		$_POST['rate_per_hour']   = '20';
		$_REQUEST                 = $_POST;

		$this->save_expecting_redirect();

		$loaded = ( new FormRepository() )->find_by_id( $saved->id() );
		$this->assertInstanceOf( Form::class, $loaded );
		$this->assertSame( array( 'https://forms.example', 'http://forms.example:8080' ), $loaded->protection()->allowed_origins() );
		$this->assertSame( 2, $loaded->protection()->rate_per_minute() );
		$this->assertSame( 20, $loaded->protection()->rate_per_hour() );
		$this->assertSame( 1, $loaded->schema_version() );
	}

	/**
	 * A bad origin and a rate outside the ceiling change nothing and are shown.
	 */
	public function test_invalid_protection_settings_keep_the_saved_form(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$saved = $this->insert_contact_form();

		$this->post_form();
		$_POST['form_id']         = (string) $saved->id();
		$_POST['schema_version']  = (string) $saved->schema_version();
		$_POST['name']            = 'Renamed';
		$_POST['allowed_origins'] = 'https://forms.example/path';
		$_POST['rate_per_minute'] = '61';
		$_REQUEST                 = $_POST;

		FormsPage::handle_load();

		$loaded = ( new FormRepository() )->find_by_id( $saved->id() );
		$this->assertInstanceOf( Form::class, $loaded );
		$this->assertSame( 'Contact', $loaded->name() );
		$this->assertSame( array(), $loaded->protection()->allowed_origins() );
		$this->assertNull( $loaded->protection()->rate_per_minute() );

		ob_start();
		FormsPage::render();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'without a path', $html );
		$this->assertStringContainsString( 'per-minute limit', $html );
	}

	/**
	 * Empty protection fields clear a stored allowlist and rate override.
	 */
	public function test_empty_protection_settings_use_the_global_limits(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$saved = ( new FormRepository() )->insert(
			new Form(
				0,
				'',
				'Contact',
				true,
				array( new Field( 'email', 'Email', FieldType::Email, true, 255 ) ),
				1,
				'https://example.com/thanks',
				'',
				'',
				new FormProtection( array( 'https://forms.example' ), 2, 20 )
			)
		);
		$this->assertInstanceOf( Form::class, $saved );

		$this->post_form();
		$_POST['form_id']         = (string) $saved->id();
		$_POST['schema_version']  = (string) $saved->schema_version();
		$_POST['allowed_origins'] = '';
		$_POST['rate_per_minute'] = '';
		$_POST['rate_per_hour']   = '';
		$_REQUEST                 = $_POST;

		$this->save_expecting_redirect();

		$loaded = ( new FormRepository() )->find_by_id( $saved->id() );
		$this->assertInstanceOf( Form::class, $loaded );
		$this->assertSame( array(), $loaded->protection()->allowed_origins() );
		$this->assertNull( $loaded->protection()->rate_per_minute() );
		$this->assertNull( $loaded->protection()->rate_per_hour() );
	}

	/**
	 * A new form shows the site admin address. A saved form keeps an empty list.
	 */
	public function test_new_form_prefills_the_admin_email_and_an_existing_form_does_not(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		update_option( 'admin_email', 'owner@example.com' );

		$_GET['action'] = 'new';
		$html           = $this->render();
		$this->assertStringContainsString( 'name="notify_recipients"', $html );
		$this->assertStringContainsString( 'owner@example.com', $html );

		$saved         = $this->insert_contact_form();
		$_GET          = array( 'form' => (string) $saved->id() );
		$existing_html = $this->render();
		$this->assertStringNotContainsString( 'owner@example.com', $existing_html );
	}

	/**
	 * Notification settings are saved without changing the field schema.
	 */
	public function test_notification_settings_are_saved(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$saved = $this->insert_contact_form();
		$this->post_form();
		$_POST['form_id']              = (string) $saved->id();
		$_POST['schema_version']       = (string) $saved->schema_version();
		$_POST['notify_recipients']    = "owner@example.com\nsecond@example.com";
		$_POST['reply_to_field']       = 'email';
		$_POST['mail_include_content'] = '1';
		$_REQUEST                      = $_POST;

		$this->save_expecting_redirect();

		$loaded = ( new FormRepository() )->find_by_id( $saved->id() );
		$this->assertInstanceOf( Form::class, $loaded );
		$this->assertSame( array( 'owner@example.com', 'second@example.com' ), $loaded->notifications()->recipients() );
		$this->assertSame( 'email', $loaded->notifications()->reply_to_field() );
		$this->assertTrue( $loaded->notifications()->includes_content() );
		$this->assertSame( $saved->schema_version(), $loaded->schema_version() );
	}

	/**
	 * A public https URL is stored with a generated secret and does not change the schema.
	 */
	public function test_webhook_settings_are_saved_without_the_posted_secret(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$saved = $this->insert_contact_form();
		$this->post_form();
		$_POST['form_id']                 = (string) $saved->id();
		$_POST['schema_version']          = (string) $saved->schema_version();
		$_POST['webhook_url']             = 'https://93.184.216.34/hook';
		$_POST['webhook_include_content'] = '1';
		$_POST['webhook_secret']          = 'whsec_posted-by-the-browser';
		$_REQUEST                         = $_POST;

		$this->save_expecting_redirect();

		$loaded = ( new FormRepository() )->find_by_id( $saved->id() );
		$this->assertInstanceOf( Form::class, $loaded );
		$this->assertSame( 'https://93.184.216.34/hook', $loaded->webhook()->url() );
		$this->assertTrue( $loaded->webhook()->includes_content() );
		$this->assertSame( $saved->schema_version(), $loaded->schema_version() );
		$this->assertNotSame( 'whsec_posted-by-the-browser', $loaded->webhook()->secret() );
		$this->assertSame( 1, preg_match( '/\Awhsec_[A-Za-z0-9+\/]{43}=\z/', $loaded->webhook()->secret() ) );
		$context = $this->audit_context( AuditEvent::FORM_UPDATED, $saved->id() );
		$this->assertStringContainsString( 'webhook_url', $context );
		$this->assertStringContainsString( 'webhook_include_content', $context );
		$this->assertStringNotContainsString( '93.184.216.34', $context );
		$this->assertStringNotContainsString( 'whsec_', $context );

		$_GET['form'] = (string) $saved->id();
		$html         = $this->render();
		$this->assertStringContainsString( $loaded->webhook()->secret(), $html );
		$this->assertStringContainsString( 'Rotate secret', $html );
		$this->assertStringNotContainsString( 'name="webhook_secret"', $html );
	}

	/**
	 * A private address is shown again and the stored webhook stays empty.
	 */
	public function test_private_webhook_url_is_rejected(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$saved = $this->insert_contact_form();
		$this->post_form();
		$_POST['form_id']        = (string) $saved->id();
		$_POST['schema_version'] = (string) $saved->schema_version();
		$_POST['webhook_url']    = 'https://127.0.0.1/hook';
		$_REQUEST                = $_POST;

		FormsPage::handle_load();

		$loaded = ( new FormRepository() )->find_by_id( $saved->id() );
		$this->assertInstanceOf( Form::class, $loaded );
		$this->assertSame( '', $loaded->webhook()->url() );
		$this->assertSame( '', $loaded->webhook()->secret() );
		$this->assertStringContainsString( 'Enter an https URL that is not a private address', $this->render() );
	}

	/**
	 * Clearing the URL removes the secret.
	 */
	public function test_clearing_the_webhook_url_removes_the_secret(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$saved = $this->save_webhook();
		$this->assertNotSame( '', $saved->webhook()->secret() );

		$this->post_form();
		$_POST['form_id']        = (string) $saved->id();
		$_POST['schema_version'] = (string) $saved->schema_version();
		$_POST['webhook_url']    = '';
		$_REQUEST                = $_POST;

		$this->save_expecting_redirect();

		$loaded = ( new FormRepository() )->find_by_id( $saved->id() );
		$this->assertInstanceOf( Form::class, $loaded );
		$this->assertSame( '', $loaded->webhook()->url() );
		$this->assertSame( '', $loaded->webhook()->secret() );
		$this->assertFalse( $loaded->webhook()->includes_content() );
	}

	/**
	 * Rotation without its nonce is rejected and the secret stays.
	 */
	public function test_rotation_without_nonce_is_rejected(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$saved                             = $this->save_webhook();
		$_SERVER['REQUEST_METHOD']         = 'POST';
		$_POST['formstead_rotate_webhook'] = '1';
		$_POST['form_id']                  = (string) $saved->id();
		$_REQUEST                          = $_POST;

		try {
			FormsPage::handle_load();
			$this->fail( 'Expected the missing nonce to be rejected.' );
		} catch ( \WPDieException $exception ) {
			$loaded = ( new FormRepository() )->find_by_id( $saved->id() );
			$this->assertInstanceOf( Form::class, $loaded );
			$this->assertSame( $saved->webhook()->secret(), $loaded->webhook()->secret() );
		}
	}

	/**
	 * Rotation replaces the secret, keeps the URL, and writes an audit row without either value.
	 */
	public function test_rotation_replaces_the_secret_and_audits_without_it(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$saved  = $this->save_webhook();
		$before = $saved->webhook()->secret();
		$this->post_rotation( $saved );

		$this->save_expecting_redirect();

		$loaded = ( new FormRepository() )->find_by_id( $saved->id() );
		$this->assertInstanceOf( Form::class, $loaded );
		$this->assertSame( 'https://93.184.216.34/hook', $loaded->webhook()->url() );
		$this->assertNotSame( $before, $loaded->webhook()->secret() );
		$this->assertSame( $saved->schema_version(), $loaded->schema_version() );
		$context = $this->audit_context( AuditEvent::WEBHOOK_SECRET_ROTATED, $saved->id() );
		$this->assertNotSame( '', $context );
		$this->assertStringNotContainsString( $before, $context );
		$this->assertStringNotContainsString( $loaded->webhook()->secret(), $context );
		$this->assertStringNotContainsString( '93.184.216.34', $context );
	}

	/**
	 * Rotation without the manage-forms capability is rejected.
	 */
	public function test_rotation_without_capability_is_rejected(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		$saved = $this->save_webhook();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$before = $saved->webhook()->secret();
		$this->post_rotation( $saved );

		try {
			FormsPage::handle_load();
			$this->fail( 'Expected the missing capability to be rejected.' );
		} catch ( \WPDieException $exception ) {
			$loaded = ( new FormRepository() )->find_by_id( $saved->id() );
			$this->assertInstanceOf( Form::class, $loaded );
			$this->assertSame( $before, $loaded->webhook()->secret() );
		}
	}

	/**
	 * The edit screen shows the secret only to someone who can manage forms.
	 */
	public function test_webhook_secret_is_hidden_without_capability(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$saved        = $this->save_webhook();
		$_GET['form'] = (string) $saved->id();
		$this->assertStringContainsString( $saved->webhook()->secret(), $this->render() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$level = ob_get_level();
		try {
			$this->render();
			$this->fail( 'Expected the forms screen to reject a subscriber.' );
		} catch ( \WPDieException $exception ) {
			while ( ob_get_level() > $level ) {
				ob_end_clean();
			}
			$this->assertStringNotContainsString( $saved->webhook()->secret(), $exception->getMessage() );
		}
	}

	/**
	 * An invalid recipient is shown again and nothing is stored.
	 */
	public function test_invalid_recipient_is_rejected(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->post_form();
		$_POST['notify_recipients'] = "owner@example.com\nnot-an-email";
		$_REQUEST                   = $_POST;

		FormsPage::handle_load();

		$this->assertSame( array(), ( new FormRepository() )->all() );
		$this->assertStringContainsString( 'Enter up to 10 valid email addresses', $this->render() );
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
	 * Save a public webhook on the contact form.
	 */
	private function save_webhook(): Form {
		$saved = $this->insert_contact_form();
		$this->post_form();
		$_POST['form_id']        = (string) $saved->id();
		$_POST['schema_version'] = (string) $saved->schema_version();
		$_POST['webhook_url']    = 'https://93.184.216.34/hook';
		$_REQUEST                = $_POST;
		$this->save_expecting_redirect();

		$loaded = ( new FormRepository() )->find_by_id( $saved->id() );
		$this->assertInstanceOf( Form::class, $loaded );

		return $loaded;
	}

	/**
	 * Post the rotation form for a saved webhook.
	 *
	 * @param Form $form Form whose secret is rotated.
	 */
	private function post_rotation( Form $form ): void {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array(
			'formstead_rotate_webhook' => '1',
			'form_id'                  => (string) $form->id(),
		);
		$_POST['_wpnonce']         = wp_create_nonce( 'formstead_rotate_webhook_' . $form->id() );
		$_REQUEST                  = $_POST;
	}

	/**
	 * Latest audit context for one event on a form.
	 *
	 * @param string $event   Event code.
	 * @param int    $form_id Form id.
	 */
	private function audit_context( string $event, int $form_id ): string {
		global $wpdb;

		$table = Schema::audit_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the test reads the audit row it caused.
		$context = $wpdb->get_var( $wpdb->prepare( "SELECT context FROM {$table} WHERE event = %s AND object_id = %d ORDER BY id DESC LIMIT 1", $event, $form_id ) );

		return is_string( $context ) ? $context : '';
	}

	/**
	 * Render the forms screen.
	 */
	private function render(): string {
		ob_start();
		FormsPage::render();
		return (string) ob_get_clean();
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
	 * Store the contact form the edit tests start from.
	 */
	private function insert_contact_form(): Form {
		$saved = ( new FormRepository() )->insert(
			new Form(
				0,
				'',
				'Contact',
				true,
				array( new Field( 'email', 'Email', FieldType::Email, true, 255 ) ),
				1,
				'https://example.com/thanks',
				'',
				''
			)
		);
		$this->assertInstanceOf( Form::class, $saved );

		return $saved;
	}

	/**
	 * Post a valid form plus the blank row the screen always includes.
	 */
	private function post_form(): void {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array(
			'name'           => 'Contact',
			'is_enabled'     => '1',
			'success_url'    => 'https://example.com/thanks',
			'retention_days' => '30',
			'fields'         => array(
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
