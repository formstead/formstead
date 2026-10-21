<?php
/**
 * Tests for the authenticated submissions API.
 *
 * @package Formstead
 */

namespace Formstead\Tests\Rest;

use Formstead\Database\Schema;
use Formstead\Rest\AdminAccess;
use Formstead\Delivery\DeliveryRepository;
use Formstead\Delivery\DeliveryStatus;
use Formstead\Forms\Field;
use Formstead\Forms\FieldType;
use Formstead\Forms\Form;
use Formstead\Forms\FormRepository;
use Formstead\Privacy\IdentityHasher;
use Formstead\Privacy\IdentityRepository;
use Formstead\Security\Capabilities;
use Formstead\Submissions\Submission;
use Formstead\Submissions\SubmissionRepository;
use Formstead\Submissions\SubmissionStatus;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;
use WP_User;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the test reads the audit row it caused.

/**
 * Covers capabilities, HTTPS, CORS, and deletion through the admin API.
 */
final class AdminSubmissionsTest extends WP_UnitTestCase {

	/**
	 * Load the routes.
	 */
	public function set_up(): void {
		parent::set_up();
		rest_get_server();
	}

	/**
	 * Sign out so a later test does not inherit this user.
	 */
	public function tear_down(): void {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * A reader can list and read. Opening a submission leaves it unread.
	 */
	public function test_get_does_not_mark_a_submission_read(): void {
		$this->sign_in( Capabilities::READ_SUBMISSIONS );
		$form       = $this->save_form();
		$submission = $this->store( $form, 'ada@example.com', 'Hello' );

		$shown = $this->dispatch( 'GET', '/formstead/v1/submissions/' . $submission->receipt_id() );
		$this->assertSame( 200, $shown->get_status() );
		$data = $shown->get_data();
		$this->assertIsArray( $data );
		$this->assertSame( $submission->receipt_id(), $data['receipt_id'] );
		$this->assertSame( 'new', $data['status'] );
		$this->assertSame( $form->public_id(), $data['form']['public_id'] );
		$this->assertSame( 'Email', $data['fields'][0]['label'] );
		$this->assertSame( 'ada@example.com', $data['fields'][0]['value'] );
		$this->assertArrayNotHasKey( 'id', $data );
		$this->assertSame( SubmissionStatus::NEW, ( new SubmissionRepository() )->find( $submission->id() )->status() );
	}

	/**
	 * The list filters by form, status, date, and an exact email address.
	 */
	public function test_list_filters_and_reports_the_total(): void {
		$this->sign_in( Capabilities::READ_SUBMISSIONS );
		$form  = $this->save_form();
		$other = $this->save_form( 'Other' );
		$ada   = $this->store( $form, 'Ada@Example.com', 'Hello' );
		$this->store( $form, 'bea@example.com', 'Other person' );
		$this->store( $other, 'ada@example.com', 'Wrong form' );

		$listed = $this->dispatch(
			'GET',
			'/formstead/v1/submissions',
			array(
				'form'     => $form->public_id(),
				'status'   => 'new',
				'email'    => 'ada@example.com',
				'after'    => '2020-01-01',
				'before'   => '2099-01-01',
				'per_page' => '1',
				'page'     => '1',
			)
		);
		$this->assertSame( 200, $listed->get_status() );
		$this->assertSame( '1', $listed->get_headers()['X-WP-Total'] );
		$this->assertSame( '1', $listed->get_headers()['X-WP-TotalPages'] );
		$rows = $listed->get_data();
		$this->assertCount( 1, $rows );
		$this->assertSame( $ada->receipt_id(), $rows[0]['receipt_id'] );

		$invalid = $this->dispatch( 'GET', '/formstead/v1/submissions', array( 'after' => 'yesterday' ) );
		$this->assertSame( 400, $invalid->get_status() );
		$this->assertSame( 'invalid_date', $invalid->get_data()['error']['code'] );
	}

	/**
	 * Reading does not allow deletion. A person with no capability cannot read.
	 */
	public function test_reader_without_delete_capability_cannot_delete(): void {
		$this->sign_in( Capabilities::READ_SUBMISSIONS );
		$submission = $this->store( $this->save_form(), 'ada@example.com', 'Kept' );

		$deleted = $this->dispatch( 'DELETE', '/formstead/v1/submissions/' . $submission->receipt_id() );
		$this->assertSame( 403, $deleted->get_status() );
		$this->assertSame( 'forbidden', $deleted->get_data()['error']['code'] );
		$this->assertNotNull( ( new SubmissionRepository() )->find( $submission->id() ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$hidden = $this->dispatch( 'GET', '/formstead/v1/submissions/' . $submission->receipt_id() );
		$this->assertSame( 403, $hidden->get_status() );
		$this->assertSame( 'forbidden', $hidden->get_data()['error']['code'] );
	}

	/**
	 * A missing user is 401.
	 */
	public function test_missing_user_is_unauthenticated(): void {
		wp_set_current_user( 0 );
		$submission = $this->store( $this->save_form(), 'ada@example.com', 'Hidden' );

		$response = $this->dispatch( 'GET', '/formstead/v1/submissions/' . $submission->receipt_id() );
		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 'unauthenticated', $response->get_data()['error']['code'] );
	}

	/**
	 * Plain HTTP is refused outside local and development.
	 *
	 * WordPress 7 reads the environment from a constant and does not filter it,
	 * so this passes the environment into the same check the route uses.
	 */
	public function test_plain_http_is_refused_in_production(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$denied = AdminAccess::denied( Capabilities::READ_SUBMISSIONS, false, 'production' );
		$this->assertInstanceOf( WP_REST_Response::class, $denied );
		$this->assertSame( 403, $denied->get_status() );
		$this->assertSame( 'https_required', $denied->get_data()['error']['code'] );

		$staging = AdminAccess::denied( Capabilities::READ_SUBMISSIONS, false, 'staging' );
		$this->assertInstanceOf( WP_REST_Response::class, $staging );
		$this->assertSame( 'https_required', $staging->get_data()['error']['code'] );

		$this->assertNull( AdminAccess::denied( Capabilities::READ_SUBMISSIONS, false, 'local' ) );
		$this->assertNull( AdminAccess::denied( Capabilities::READ_SUBMISSIONS, false, 'development' ) );
		$this->assertNull( AdminAccess::denied( Capabilities::READ_SUBMISSIONS, true, 'production' ) );
	}

	/**
	 * Admin routes send no CORS grant, including a preflight.
	 */
	public function test_admin_routes_send_no_cors_headers(): void {
		$this->sign_in( Capabilities::READ_SUBMISSIONS );
		$request = new WP_REST_Request( 'GET', '/formstead/v1/submissions' );
		$request->set_header( 'Origin', 'https://evil.example' );
		$response = rest_do_request( $request );
		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertArrayNotHasKey( 'Access-Control-Allow-Origin', $response->get_headers() );
		$this->assertArrayNotHasKey( 'Access-Control-Allow-Credentials', $response->get_headers() );

		$preflight = new WP_REST_Request( 'OPTIONS', '/formstead/v1/submissions' );
		$preflight->set_header( 'Origin', 'https://evil.example' );
		$preflight->set_header( 'Access-Control-Request-Method', 'GET' );
		$answered = rest_do_request( $preflight );
		$this->assertInstanceOf( WP_REST_Response::class, $answered );
		$this->assertArrayNotHasKey( 'Access-Control-Allow-Origin', $answered->get_headers() );
	}

	/**
	 * The public create route still has no GET.
	 */
	public function test_public_route_has_no_get(): void {
		$form     = $this->save_form();
		$request  = new WP_REST_Request( 'GET', '/formstead/v1/forms/' . $form->public_id() . '/submissions' );
		$response = rest_do_request( $request );
		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertContains( $response->get_status(), array( 404, 405 ) );
		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertContains( $data['code'] ?? '', array( 'rest_no_route', 'rest_no_method' ) );
		$this->assertArrayNotHasKey( 'receipt_id', $data );
	}

	/**
	 * An unknown receipt is 404. A status change does not require the delete capability.
	 */
	public function test_unknown_receipt_is_not_found_and_patch_changes_status(): void {
		$this->sign_in( Capabilities::READ_SUBMISSIONS );
		$missing = $this->dispatch( 'GET', '/formstead/v1/submissions/fsr_abcdefghijklmnop' );
		$this->assertSame( 404, $missing->get_status() );
		$this->assertSame( 'submission_not_found', $missing->get_data()['error']['code'] );

		$submission = $this->store( $this->save_form(), 'ada@example.com', 'Hello' );
		$request    = new WP_REST_Request( 'PATCH', '/formstead/v1/submissions/' . $submission->receipt_id() );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( '{"status":"spam"}' );
		$response = rest_do_request( $request );
		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'spam', $response->get_data()['status'] );
		$this->assertSame( SubmissionStatus::SPAM, ( new SubmissionRepository() )->find( $submission->id() )->status() );
	}

	/**
	 * Deleting cancels a pending delivery and records the source as the API.
	 */
	public function test_delete_cancels_deliveries_and_writes_an_audit_event(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$form       = $this->save_form();
		$submission = $this->store( $form, 'ada@example.com', 'Remove me' );
		$kept       = $this->store( $form, 'bea@example.com', 'Keep me' );
		$delivery   = ( new DeliveryRepository() )->create( $submission->id() );
		$this->assertIsInt( $delivery );

		$response = $this->dispatch( 'DELETE', '/formstead/v1/submissions/' . $submission->receipt_id() );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 1, $response->get_data()['deleted'] );
		$this->assertNull( ( new SubmissionRepository() )->find( $submission->id() ) );
		$this->assertNotNull( ( new SubmissionRepository() )->find( $kept->id() ) );

		$stored = ( new DeliveryRepository() )->find( $delivery );
		$this->assertNotNull( $stored );
		$this->assertSame( DeliveryStatus::CANCELLED, $stored->status() );

		global $wpdb;
		$table = Schema::audit_table();
		$row   = $wpdb->get_row( "SELECT event, context FROM {$table} ORDER BY id DESC LIMIT 1", ARRAY_A );
		$this->assertIsArray( $row );
		$this->assertSame( 'submissions_deleted', $row['event'] );
		$this->assertStringContainsString( '"source":"api"', (string) $row['context'] );
		$this->assertStringNotContainsString( 'ada@example.com', (string) $row['context'] );
	}

	/**
	 * Sign in as a subscriber with one Formstead capability.
	 *
	 * @param string $capability Capability to grant.
	 */
	private function sign_in( string $capability ): void {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$user    = new WP_User( $user_id );
		$user->add_cap( $capability );
		wp_set_current_user( $user_id );
	}

	/**
	 * Persist a form with an identifying email field.
	 *
	 * @param string $name Form name.
	 */
	private function save_form( string $name = 'Contact' ): Form {
		$saved = ( new FormRepository() )->insert(
			new Form(
				0,
				'',
				$name,
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
		$this->assertInstanceOf( Form::class, $saved );

		return $saved;
	}

	/**
	 * Store a submission and index its email address.
	 *
	 * @param Form   $form  Form that receives it.
	 * @param string $email Identifying address.
	 * @param string $note  Note field.
	 */
	private function store( Form $form, string $email, string $note ): Submission {
		$values = array(
			'email' => $email,
			'note'  => $note,
		);
		$saved  = ( new SubmissionRepository() )->insert( $form, $values );
		$this->assertInstanceOf( Submission::class, $saved );
		$indexed = ( new IdentityRepository() )->insert_for_submission(
			$saved->id(),
			$form->id(),
			IdentityHasher::hashes( $form, $values ),
			$saved->created_at()
		);
		$this->assertTrue( $indexed );

		return $saved;
	}

	/**
	 * Dispatch one admin request and require a response object.
	 *
	 * @param string               $method HTTP method.
	 * @param string               $route  Route, beginning with a slash.
	 * @param array<string, mixed> $query  Query arguments.
	 */
	private function dispatch( string $method, string $route, array $query = array() ): WP_REST_Response {
		$request = new WP_REST_Request( $method, $route );
		if ( array() !== $query ) {
			$request->set_query_params( $query );
		}
		$response = rest_do_request( $request );
		$this->assertInstanceOf( WP_REST_Response::class, $response );

		return $response;
	}
}
