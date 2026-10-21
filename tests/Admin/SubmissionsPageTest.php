<?php
/**
 * Tests for the submissions inbox.
 *
 * @package Formstead
 */

namespace Formstead\Tests\Admin;

// phpcs:disable WordPress.Security.NonceVerification -- the test sets the screen query the inbox reads.

use Formstead\Admin\SubmissionsPage;
use Formstead\Database\Schema;
use Formstead\Delivery\DeliveryRepository;
use Formstead\Forms\Field;
use Formstead\Forms\FieldType;
use Formstead\Forms\Form;
use Formstead\Forms\FormRepository;
use Formstead\Security\Capabilities;
use Formstead\Submissions\SubmissionIntake;
use Formstead\Submissions\SubmissionRepository;
use WP_REST_Request;
use WP_User;

/**
 * Covers who can read submissions and what a read does not change.
 */
final class SubmissionsPageTest extends \WP_UnitTestCase {

	/**
	 * Clear the screen query between tests.
	 */
	public function tear_down(): void {
		$_GET = array();
		parent::tear_down();
	}

	/**
	 * An editor cannot open the list or a submission.
	 */
	public function test_editor_cannot_read_submissions(): void {
		$this->assert_read_is_rejected( 'editor' );
	}

	/**
	 * A subscriber cannot open the list or a submission.
	 */
	public function test_subscriber_cannot_read_submissions(): void {
		$this->assert_read_is_rejected( 'subscriber' );
	}

	/**
	 * Managing forms does not grant access to submissions.
	 */
	public function test_manage_forms_does_not_grant_read_access(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$user    = new WP_User( $user_id );
		$user->add_cap( Capabilities::MANAGE_FORMS );
		wp_set_current_user( $user_id );

		$submission_id = $this->store_submission( 'hidden-from-forms-manager' );
		foreach ( array( 0, $submission_id ) as $requested ) {
			$_GET = $requested > 0 ? array( 'submission' => (string) $requested ) : array();
			try {
				SubmissionsPage::render();
				$this->fail( 'Expected the inbox to reject a forms manager.' );
			} catch ( \WPDieException $exception ) {
				$this->assertStringContainsString( 'not allowed', $exception->getMessage() );
			}
		}
	}

	/**
	 * An administrator sees the row, and opening a new submission marks it read.
	 */
	public function test_administrator_sees_a_submission_and_opening_it_marks_it_read(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$submission_id = $this->store_submission( "Line one\nLine two" );
		$repository    = new SubmissionRepository();

		$_GET = array();
		$html = $this->render();
		$this->assertStringContainsString( 'Line one Line two', $html );

		$_GET = array( 'submission' => (string) $submission_id );
		$html = $this->render();
		$this->assertStringContainsString( 'Note', $html );
		$this->assertStringContainsString( 'Line one<br', $html );
		$this->assertStringContainsString( 'Line two', $html );
		$this->assertStringContainsString( 'Mark as unread', $html );

		$loaded = $repository->find( $submission_id );
		$this->assertNotNull( $loaded );
		$this->assertSame( 'read', $loaded->status() );
	}

	/**
	 * Opening spam or an archived submission does not move it back to the inbox.
	 */
	public function test_opening_spam_or_archived_submission_keeps_its_status(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$repository = new SubmissionRepository();

		foreach ( array( 'spam', 'archived' ) as $status ) {
			$submission_id = $this->store_submission( $status . '-value' );
			$this->assertSame( 1, $repository->set_status( array( $submission_id ), $status ) );

			$_GET = array( 'submission' => (string) $submission_id );
			$this->render();

			$loaded = $repository->find( $submission_id );
			$this->assertNotNull( $loaded );
			$this->assertSame( $status, $loaded->status() );
		}
	}

	/**
	 * An old submission keeps the label from its snapshot. A later one uses the new label.
	 */
	public function test_detail_uses_the_snapshot_label_or_the_raw_name(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$forms      = new FormRepository();
		$form       = $this->save_form( 'Contact', 'note', 'Note', FieldType::Text, false );
		$repository = new SubmissionRepository();
		$saved      = $repository->insert(
			$form,
			array(
				'note'    => 'hello',
				'retired' => 'kept',
			)
		);
		$this->assertInstanceOf( \Formstead\Submissions\Submission::class, $saved );

		$updated = $forms->update(
			new Form(
				$form->id(),
				$form->public_id(),
				$form->name(),
				true,
				array( new Field( 'note', 'Message', FieldType::Text, false, 1000 ) ),
				$form->schema_version(),
				$form->success_url(),
				$form->created_at(),
				$form->updated_at()
			)
		);
		$this->assertNotInstanceOf( \WP_Error::class, $updated );

		$_GET = array( 'submission' => (string) $saved->id() );
		$html = $this->render();
		$this->assertStringContainsString( 'Note', $html );
		$this->assertStringNotContainsString( 'Message', $html );
		$this->assertStringContainsString( 'retired', $html );
		$this->assertStringContainsString( 'kept', $html );

		$later = $repository->insert( $updated, array( 'note' => 'after-the-change' ) );
		$this->assertInstanceOf( \Formstead\Submissions\Submission::class, $later );
		$_GET = array( 'submission' => (string) $later->id() );
		$html = $this->render();
		$this->assertStringContainsString( 'Message', $html );
		$this->assertStringContainsString( 'after-the-change', $html );
	}

	/**
	 * View links are on the list, and their counts follow the form filter.
	 */
	public function test_list_shows_view_links_with_counts_for_the_filtered_form(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$first  = $this->save_form( 'First', 'note', 'Note', FieldType::Text, false );
		$second = $this->save_form( 'Second', 'note', 'Note', FieldType::Text, false );
		$repo   = new SubmissionRepository();

		$visible = $repo->insert( $first, array( 'note' => 'visible-new' ) );
		$spam    = $repo->insert( $first, array( 'note' => 'visible-spam' ) );
		$this->assertInstanceOf( \Formstead\Submissions\Submission::class, $visible );
		$this->assertInstanceOf( \Formstead\Submissions\Submission::class, $spam );
		$this->assertSame( 1, $repo->set_status( array( $spam->id() ), 'spam' ) );
		for ( $index = 0; $index < 4; $index++ ) {
			$saved = $repo->insert( $second, array( 'note' => 'other-' . $index ) );
			$this->assertNotInstanceOf( \WP_Error::class, $saved );
		}

		$_GET = array( 'form_id' => (string) $first->id() );
		$html = $this->render();

		$this->assertMatchesRegularExpression( '/inbox_view=unread[^"]*"[^>]*>Unread <span class="count">\\(1\\)<\\/span>/', $html );
		$this->assertMatchesRegularExpression( '/inbox_view=archived[^"]*"[^>]*>Archived <span class="count">\\(0\\)<\\/span>/', $html );
		$this->assertMatchesRegularExpression( '/inbox_view=spam[^"]*"[^>]*>Spam <span class="count">\\(1\\)<\\/span>/', $html );
		$this->assertMatchesRegularExpression( '/inbox_view=all[^"]*"[^>]*>All <span class="count">\\(2\\)<\\/span>/', $html );
		$this->assertStringNotContainsString( 'other-0', $html );
	}

	/**
	 * Page two shows the older row, and the form filter hides the other form.
	 */
	public function test_pagination_and_form_filter(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$first  = $this->save_form( 'First', 'note', 'Note', FieldType::Text, false );
		$second = $this->save_form( 'Second', 'note', 'Note', FieldType::Text, false );
		$repo   = new SubmissionRepository();

		for ( $index = 1; $index <= 21; $index++ ) {
			$saved = $repo->insert( $first, array( 'note' => sprintf( 'row-%02d', $index ) ) );
			$this->assertNotInstanceOf( \WP_Error::class, $saved );
		}
		$other = $repo->insert( $second, array( 'note' => 'only-second' ) );
		$this->assertNotInstanceOf( \WP_Error::class, $other );

		$_GET = array(
			'paged'   => '2',
			'form_id' => (string) $first->id(),
		);
		$html = $this->render();
		$this->assertStringContainsString( 'row-01', $html );
		$this->assertStringNotContainsString( 'row-21', $html );
		$this->assertStringNotContainsString( 'only-second', $html );

		$_GET = array( 'form_id' => (string) $second->id() );
		$html = $this->render();
		$this->assertStringContainsString( 'only-second', $html );
		$this->assertStringNotContainsString( 'row-01', $html );
	}

	/**
	 * Search by email finds a different case and hides a different address.
	 */
	public function test_search_by_email_filters_the_list(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$form = ( new FormRepository() )->insert(
			new Form(
				0,
				'',
				'Contact',
				true,
				array(
					new Field( 'email', 'Email', FieldType::Email, true, 255 ),
					new Field( 'note', 'Note', FieldType::Text, false, 255 ),
				),
				1,
				'https://example.com/thanks',
				'',
				'',
				null,
				0,
				null,
				30,
				array( 'email' )
			)
		);
		$this->assertInstanceOf( Form::class, $form );
		$intake = new SubmissionIntake();
		$intake->accept(
			$form,
			array(
				'email' => 'Ada@Example.com',
				'note'  => 'from-ada',
			),
			null
		);
		$intake->accept(
			$form,
			array(
				'email' => 'bea@example.com',
				'note'  => 'from-bea',
			),
			null
		);

		$_GET = array( 's' => ' ada@example.com ' );
		$html = $this->render();

		$this->assertStringContainsString( 'Search by email', $html );
		$this->assertStringContainsString( 'value="ada@example.com"', $html );
		$this->assertStringContainsString( 'Ada@Example.com', $html );
		$this->assertStringNotContainsString( 'bea@example.com', $html );
		$this->assertStringContainsString( 's=ada@example.com', $html );

		$_GET = array( 's' => 'other@example.com' );
		$html = $this->render();
		$this->assertStringNotContainsString( 'Ada@Example.com', $html );
		$this->assertStringNotContainsString( 'bea@example.com', $html );
	}

	/**
	 * A long value is shortened in the list and left whole on the detail screen.
	 */
	public function test_list_excerpt_is_limited_to_80_characters(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$value         = str_repeat( 'a', 100 );
		$submission_id = $this->store_submission( $value );

		$_GET = array();
		$html = $this->render();
		$this->assertStringContainsString( str_repeat( 'a', 80 ) . '…', $html );
		$this->assertStringNotContainsString( str_repeat( 'a', 81 ), $html );

		$_GET = array( 'submission' => (string) $submission_id );
		$html = $this->render();
		$this->assertStringContainsString( $value, $html );
	}

	/**
	 * The submission screen shows the delivery status without calling it delivered.
	 */
	public function test_detail_shows_the_delivery_status(): void {
		global $wpdb;

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$submission_id = $this->store_submission( 'visible-note' );
		$repository    = new DeliveryRepository();
		$created       = $repository->create( $submission_id );
		$this->assertIsInt( $created );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			Schema::deliveries_table(),
			array(
				'last_error'      => 'mail_failed',
				'next_attempt_at' => '2026-10-21 00:00:00',
			),
			array( 'id' => $created )
		);

		$_GET = array( 'submission' => (string) $submission_id );
		$html = $this->render();
		$this->assertStringContainsString( 'Email', $html );
		$this->assertStringContainsString( 'Waiting to send', $html );
		$this->assertStringContainsString( 'mail_failed', $html );
		$this->assertStringContainsString( '2026-10-21 00:00:00', $html );
		$this->assertStringNotContainsString( 'Handed to the mail system', $html );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			Schema::deliveries_table(),
			array(
				'status'     => 'handed_off',
				'last_error' => null,
			),
			array( 'id' => $created )
		);
		$html = $this->render();
		$this->assertStringContainsString( 'Handed to the mail system', $html );
		$this->assertStringNotContainsString( '2026-10-21 00:00:00', $html );
	}

	/**
	 * A webhook delivery is named on the submission screen.
	 */
	public function test_detail_shows_the_webhook_channel(): void {
		global $wpdb;

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$submission_id = $this->store_submission( 'visible-note' );
		$created       = ( new DeliveryRepository() )->create( $submission_id, 'webhook' );
		$this->assertIsInt( $created );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			Schema::deliveries_table(),
			array( 'status' => 'handed_off' ),
			array( 'id' => $created )
		);

		$_GET = array( 'submission' => (string) $submission_id );
		$html = $this->render();
		$this->assertStringContainsString( 'Webhook', $html );
		$this->assertStringContainsString( 'Handed to the receiver', $html );
	}

	/**
	 * GET on the public route does not return a stored submission.
	 */
	public function test_get_on_the_public_route_returns_no_submission_data(): void {
		$form = $this->save_form( 'Contact', 'note', 'Note', FieldType::Text, false );
		$repo = new SubmissionRepository();
		$repo->insert( $form, array( 'note' => 'secret-inbox-value' ) );
		rest_get_server();

		$request  = new WP_REST_Request( 'GET', '/formstead/v1/forms/' . $form->public_id() . '/submissions' );
		$response = rest_do_request( $request );
		$this->assertContains( $response->get_status(), array( 404, 405 ) );
		$this->assertStringNotContainsString( 'secret-inbox-value', (string) wp_json_encode( $response->get_data() ) );
	}

	/**
	 * Assert the list and the detail both reject this role.
	 *
	 * @param string $role WordPress role.
	 */
	private function assert_read_is_rejected( string $role ): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => $role ) ) );
		$submission_id = $this->store_submission( 'hidden-value' );

		foreach ( array( 0, $submission_id ) as $requested ) {
			$_GET = $requested > 0 ? array( 'submission' => (string) $requested ) : array();
			try {
				SubmissionsPage::render();
				$this->fail( 'Expected the inbox to reject the user.' );
			} catch ( \WPDieException $exception ) {
				$this->assertStringContainsString( 'not allowed', $exception->getMessage() );
			}
		}

		$loaded = ( new SubmissionRepository() )->find( $submission_id );
		$this->assertNotNull( $loaded );
		$this->assertSame( 'new', $loaded->status() );
	}

	/**
	 * Store one note and return its id.
	 *
	 * @param string $note Note value.
	 */
	private function store_submission( string $note ): int {
		$form  = $this->save_form( 'Contact', 'note', 'Note', FieldType::Textarea, false );
		$saved = ( new SubmissionRepository() )->insert( $form, array( 'note' => $note ) );
		$this->assertInstanceOf( \Formstead\Submissions\Submission::class, $saved );
		return $saved->id();
	}

	/**
	 * Persist a one-field form.
	 *
	 * @param string    $name  Form name.
	 * @param string    $field Field name.
	 * @param string    $label Field label.
	 * @param FieldType $type  Field type.
	 * @param bool      $required Whether the field is required.
	 */
	private function save_form( string $name, string $field, string $label, FieldType $type, bool $required ): Form {
		$saved = ( new FormRepository() )->insert(
			new Form(
				0,
				'',
				$name,
				true,
				array( new Field( $field, $label, $type, $required, 1000 ) ),
				1,
				'https://example.com/thanks',
				'',
				''
			)
		);
		$this->assertNotInstanceOf( \WP_Error::class, $saved );
		return $saved;
	}

	/**
	 * Render the inbox and return the HTML.
	 */
	private function render(): string {
		ob_start();
		SubmissionsPage::render();
		return (string) ob_get_clean();
	}
}
