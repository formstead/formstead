<?php
/**
 * Tests for the public submissions endpoint.
 *
 * @package Formstead
 */

namespace Formstead\Tests\Rest;

use Formstead\Database\Schema;
use Formstead\Forms\Field;
use Formstead\Forms\FieldType;
use Formstead\Forms\Form;
use Formstead\Forms\FormRepository;
use Formstead\Rest\HtmlResponder;
use Formstead\Rest\HtmlResponse;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * Contract for the public POST route.
 *
 * @phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
 * @phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
 * @phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
 */
final class SubmissionsControllerTest extends WP_UnitTestCase {

	/**
	 * Forms repository.
	 *
	 * @var FormRepository
	 */
	private FormRepository $forms;

	/**
	 * Create the repository.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->forms = new FormRepository();
		rest_get_server();
	}

	/**
	 * A valid post redirects to the configured URL and stores the trimmed payload.
	 */
	public function test_valid_post_stores_the_payload_and_redirects(): void {
		$form = $this->save_form( $this->contact_form( true ) );

		$response = $this->post(
			$form->public_id(),
			'email=' . rawurlencode( '  a@b.co  ' ) . '&note=' . rawurlencode( 'Zażółć' ) . '&extra=drop-me&_fs_honeypot=&redirect_to=' . rawurlencode( 'https://evil.example' ),
			array( 'redirect_to' => 'https://evil.example' )
		);

		$this->assertSame( 303, $response->get_status() );
		$this->assertSame( 'https://example.com/thanks', $response->get_headers()['Location'] );
		$this->assertNull( $response->get_data() );

		$rows = $this->rows();
		$this->assertCount( 1, $rows );
		$this->assertSame( (string) $form->id(), (string) $rows[0]['form_id'] );
		$this->assertSame( (string) $form->schema_version(), (string) $rows[0]['schema_version'] );
		$this->assertSame( 'new', $rows[0]['status'] );
		$this->assertSame(
			array(
				'email' => 'a@b.co',
				'note'  => 'Zażółć',
			),
			json_decode( (string) $rows[0]['payload'], true )
		);
		$this->assertStringNotContainsString( 'drop-me', (string) $rows[0]['payload'] );
		$this->assertStringNotContainsString( '_fs_honeypot', (string) $rows[0]['payload'] );
		$this->assertStringNotContainsString( '\\u', (string) $rows[0]['payload'] );
	}

	/**
	 * A missing required field is rejected and nothing is stored.
	 */
	public function test_missing_required_field_returns_422(): void {
		$form = $this->save_form( $this->contact_form( true ) );

		$response = $this->post( $form->public_id(), 'note=Hello' );

		$this->assert_field_error( $response, 'Email', 'This field is required.' );
		$this->assertSame( array(), $this->rows() );
	}

	/**
	 * A value past the field limit is rejected.
	 */
	public function test_too_long_value_returns_422(): void {
		$form = $this->save_form( $this->contact_form( true, 5 ) );

		$response = $this->post( $form->public_id(), 'email=a@b.co&note=123456' );

		$this->assert_field_error( $response, 'Note', 'This value is too long.' );
		$this->assertSame( array(), $this->rows() );
	}

	/**
	 * An email field rejects a value that is not an email address.
	 */
	public function test_invalid_email_returns_422(): void {
		$form = $this->save_form( $this->contact_form( true ) );

		$response = $this->post( $form->public_id(), 'email=not-an-email' );

		$this->assert_field_error( $response, 'Email', 'Enter a valid email address.' );
		$this->assertSame( array(), $this->rows() );
	}

	/**
	 * A repeated field arrives as an array and is rejected.
	 */
	public function test_array_value_returns_422(): void {
		$form = $this->save_form( $this->contact_form( true ) );

		$response = $this->post( $form->public_id(), 'email[]=a@b.co' );

		$this->assert_field_error( $response, 'Email', 'This value is not valid.' );
		$this->assertSame( array(), $this->rows() );
		$this->assertStringNotContainsString( 'a@b.co', $response->html() );
	}

	/**
	 * An unknown, malformed, or disabled form gets the same HTML 404.
	 */
	public function test_unknown_malformed_and_disabled_forms_return_404(): void {
		$disabled = $this->save_form( $this->contact_form( false ) );

		foreach ( array( 'fs_aaaaaaaaaaaaaaaa', 'nope', $disabled->public_id() ) as $public_id ) {
			$response = $this->post( $public_id, 'email=a@b.co' );
			$this->assertInstanceOf( HtmlResponse::class, $response );
			$this->assertSame( 404, $response->get_status() );
			$this->assertStringContainsString( 'This form is not available.', $response->html() );
			$this->assertStringNotContainsString( 'a@b.co', $response->html() );
		}

		$this->assertSame( array(), $this->rows() );
	}

	/**
	 * A failed insert is a generic HTML 500 and the log omits the submission.
	 */
	public function test_failed_insert_returns_500_without_logging_the_submission(): void {
		$form   = $this->save_form( $this->contact_form( true ) );
		$table  = Schema::submissions_table();
		$secret = 'needle-secret-value';
		$filter = static function ( $query ) use ( $table ) {
			if ( ! is_string( $query ) ) {
				return $query;
			}
			if ( str_contains( $query, 'INSERT' ) && str_contains( $query, $table ) ) {
				return str_replace( $table, $table . '_missing', $query );
			}
			return $query;
		};

		$previous_log = ini_get( 'error_log' );
		$log_file     = tempnam( sys_get_temp_dir(), 'formstead' );
		$this->assertIsString( $log_file );
		// phpcs:ignore WordPress.PHP.IniSet.Risky -- capture this test's error_log line only.
		ini_set( 'error_log', $log_file );

		add_filter( 'query', $filter );
		try {
			$response = $this->post( $form->public_id(), 'email=a@b.co&note=' . rawurlencode( $secret ) );
		} finally {
			remove_filter( 'query', $filter );
			// phpcs:ignore WordPress.PHP.IniSet.Risky -- restore the previous error_log destination.
			ini_set( 'error_log', is_string( $previous_log ) ? $previous_log : '' );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local log file.
		$log = is_readable( $log_file ) ? (string) file_get_contents( $log_file ) : '';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- local log file.
		unlink( $log_file );

		$this->assertInstanceOf( HtmlResponse::class, $response );
		$this->assertSame( 500, $response->get_status() );
		$this->assertStringContainsString( 'could not be saved', $response->html() );
		$this->assertStringNotContainsString( $secret, $response->html() );
		$this->assertSame( array(), $this->rows() );
		$this->assertStringContainsString( 'form ' . $form->id(), $log );
		$this->assertStringNotContainsString( $secret, $log );
	}

	/**
	 * The HTML page escapes a field label and does not echo the submitted value.
	 */
	public function test_html_page_escapes_the_label_and_hides_the_value(): void {
		$saved = $this->forms->insert(
			new Form(
				0,
				'',
				'Contact',
				true,
				array(
					new Field( 'email', '<script>alert(1)</script>', FieldType::Email, true, 255 ),
				),
				1,
				'https://example.com/thanks',
				'',
				''
			)
		);
		$this->assertNotInstanceOf( \WP_Error::class, $saved );

		$request  = $this->request( $saved->public_id(), 'email=secret-submission-value' );
		$response = rest_do_request( $request );
		$this->assertInstanceOf( HtmlResponse::class, $response );

		ob_start();
		$served = HtmlResponder::serve( false, $response, $request, rest_get_server() );
		$html   = (string) ob_get_clean();

		$this->assertTrue( $served );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringNotContainsString( 'secret-submission-value', $html );
		$this->assertStringContainsString( 'lang="', $html );
		$this->assertStringContainsString( '<h1>', $html );
		$this->assertStringContainsString( '<ul>', $html );
	}

	/**
	 * Assert a 422 page names the field and message and hides the request body.
	 *
	 * @param WP_REST_Response $response Response under test.
	 * @param string           $label    Expected field label.
	 * @param string           $message  Expected error message.
	 */
	private function assert_field_error( WP_REST_Response $response, string $label, string $message ): void {
		$this->assertInstanceOf( HtmlResponse::class, $response );
		$this->assertSame( 422, $response->get_status() );
		$html = $response->html();
		$this->assertStringContainsString( $label, $html );
		$this->assertStringContainsString( $message, $html );
		$this->assertStringContainsString( '<ul>', $html );
	}

	/**
	 * Send a URL-encoded POST.
	 *
	 * @param string               $public_id Public form id.
	 * @param string               $body      Raw request body.
	 * @param array<string, mixed> $query     Query parameters.
	 */
	private function post( string $public_id, string $body, array $query = array() ): WP_REST_Response {
		$response = rest_do_request( $this->request( $public_id, $body, $query ) );
		$this->assertInstanceOf( WP_REST_Response::class, $response );
		return $response;
	}

	/**
	 * Build a URL-encoded POST request.
	 *
	 * @param string               $public_id Public form id.
	 * @param string               $body      Raw request body.
	 * @param array<string, mixed> $query     Query parameters.
	 */
	private function request( string $public_id, string $body, array $query = array() ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/formstead/v1/forms/' . $public_id . '/submissions' );
		$request->set_header( 'Content-Type', 'application/x-www-form-urlencoded' );
		$request->set_body( $body );
		// PHP fills $_POST for this content type, and the REST server copies it
		// into body params. POST does not parse the raw body again.
		parse_str( $body, $params );
		$request->set_body_params( $params );
		if ( array() !== $query ) {
			$request->set_query_params( $query );
		}
		return $request;
	}

	/**
	 * Persist a form and return the stored record.
	 *
	 * @param Form $form Form to store.
	 */
	private function save_form( Form $form ): Form {
		$saved = $this->forms->insert( $form );
		$this->assertNotInstanceOf( \WP_Error::class, $saved );
		return $saved;
	}

	/**
	 * A contact form with an email field and a short note.
	 *
	 * @param bool $enabled    Whether the endpoint accepts submissions.
	 * @param int  $note_limit Maximum length of the note.
	 */
	private function contact_form( bool $enabled, int $note_limit = 1000 ): Form {
		return new Form(
			0,
			'',
			'Contact',
			$enabled,
			array(
				new Field( 'email', 'Email', FieldType::Email, true, 255 ),
				new Field( 'note', 'Note', FieldType::Textarea, false, $note_limit ),
			),
			1,
			'https://example.com/thanks',
			'',
			''
		);
	}

	/**
	 * Submission rows in insertion order.
	 *
	 * @return array<int, array<string, string>>
	 */
	private function rows(): array {
		global $wpdb;

		$table = Schema::submissions_table();
		$rows  = $wpdb->get_results( "SELECT form_id, schema_version, payload, status FROM {$table} ORDER BY id ASC", ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$clean = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$clean[] = array(
				'form_id'        => (string) ( $row['form_id'] ?? '' ),
				'schema_version' => (string) ( $row['schema_version'] ?? '' ),
				'payload'        => (string) ( $row['payload'] ?? '' ),
				'status'         => (string) ( $row['status'] ?? '' ),
			);
		}

		return $clean;
	}
}
