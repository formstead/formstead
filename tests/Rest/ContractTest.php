<?php
/**
 * Contract tests for the public submissions endpoint.
 *
 * @package Formstead
 */

namespace Formstead\Tests\Rest;

use Formstead\Database\Schema;
use Formstead\Forms\Field;
use Formstead\Forms\FieldSettings;
use Formstead\Forms\FieldType;
use Formstead\Forms\Form;
use Formstead\Forms\FormRepository;
use Formstead\Rest\HtmlResponse;
use Formstead\Submissions\ReceiptId;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * Every response code in the stage 2 contract, in HTML and JSON.
 *
 * @phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
 * @phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
 * @phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
 */
final class ContractTest extends WP_UnitTestCase {

	/**
	 * Prepare the REST server.
	 */
	public function set_up(): void {
		parent::set_up();
		rest_get_server();
	}

	/**
	 * Success and error responses match the contract in both modes.
	 *
	 * @dataProvider cases
	 *
	 * @param string $mode   `html` or `json`.
	 * @param int    $status Expected HTTP status.
	 * @param string $code   Expected JSON error code, or `accepted` for success.
	 * @param string $type   Content type.
	 * @param string $body   Raw body. The token SECRET stands in for a submitted value.
	 * @param bool   $files  Whether the request includes a file part.
	 */
	public function test_contract( string $mode, int $status, string $code, string $type, string $body, bool $files ): void {
		$form    = $this->save_form();
		$secret  = 'contract-secret-value';
		$target  = 'form_not_found' === $code ? 'fs_aaaaaaaaaaaaaaaa' : $form->public_id();
		$request = $this->request( $target, $mode, $type, str_replace( 'SECRET', $secret, $body ), $files );
		$filter  = null;

		if ( 'storage_failed' === $code ) {
			$table  = Schema::submissions_table();
			$filter = static function ( $query ) use ( $table ) {
				if ( is_string( $query ) && str_contains( $query, 'INSERT' ) && str_contains( $query, $table ) ) {
					return str_replace( $table, $table . '_missing', $query );
				}
				return $query;
			};
			add_filter( 'query', $filter );
		}

		try {
			$response = rest_do_request( $request );
		} finally {
			if ( null !== $filter ) {
				remove_filter( 'query', $filter );
			}
		}

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( $status, $response->get_status() );
		$this->assertSame( 'Accept, Origin', $response->get_headers()['Vary'] );
		$this->assertSame( 'no-store', $response->get_headers()['Cache-Control'] );

		if ( 'html' === $mode && 'accepted' !== $code ) {
			$this->assertInstanceOf( HtmlResponse::class, $response );
			$this->assertStringContainsString( 'text/html', (string) $response->get_headers()['Content-Type'] );
			$this->assertStringNotContainsString( $secret, $response->html() );
		} else {
			$this->assertStringNotContainsString( $secret, (string) wp_json_encode( $response->get_data() ) );
		}

		if ( 'accepted' === $code ) {
			$this->assertCount( 1, $this->rows() );
			if ( 'json' === $mode ) {
				$data = $response->get_data();
				$this->assertIsArray( $data );
				$this->assertSame( array( 'receipt_id' ), array_keys( $data ) );
				$this->assertTrue( ReceiptId::is_valid( (string) $data['receipt_id'] ) );
				$this->assertArrayNotHasKey( 'Location', $response->get_headers() );
			} else {
				$this->assertSame( 'https://example.com/thanks', $response->get_headers()['Location'] );
			}
			return;
		}

		$this->assertSame( array(), $this->rows() );
		if ( 'json' === $mode ) {
			$data = $response->get_data();
			$this->assertIsArray( $data );
			$this->assertIsArray( $data['error'] );
			$this->assertSame( $code, $data['error']['code'] );
			$this->assertArrayNotHasKey( 'data', $data );
			if ( 'invalid_fields' === $code ) {
				$this->assertSame( array( 'required' ), $data['error']['fields']['email'] );
			}
		}
	}

	/**
	 * Number, checkbox, and choice values survive a JSON submission.
	 */
	public function test_typed_json_values_are_stored_canonically(): void {
		$form    = $this->save_form();
		$request = $this->request(
			$form->public_id(),
			'json',
			'application/json',
			'{"email":"a@b.co","qty":0.10,"agree":true,"color":"red"}',
			false
		);

		$response = rest_do_request( $request );

		$this->assertSame( 201, $response->get_status() );
		$payload = json_decode( (string) $this->rows()[0]['payload'], true );
		$this->assertSame(
			array(
				'email' => 'a@b.co',
				'qty'   => '0.1',
				'agree' => true,
				'color' => 'red',
			),
			$payload
		);
	}

	/**
	 * A browser Accept header stays on the HTML contract even when the body is JSON.
	 */
	public function test_browser_accept_stays_html(): void {
		$form    = $this->save_form();
		$request = $this->request( $form->public_id(), 'html', 'application/json', '{"email":"a@b.co"}', false );
		$request->set_header( 'Accept', 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8' );

		$response = rest_do_request( $request );

		$this->assertSame( 303, $response->get_status() );
		$this->assertSame( 'https://example.com/thanks', $response->get_headers()['Location'] );
	}

	/**
	 * Contract cases: mode, status, code, content type, body, files.
	 *
	 * @return array<string, array{0: string, 1: int, 2: string, 3: string, 4: string, 5: bool}>
	 */
	public function cases(): array {
		$valid = 'email=a@b.co&note=Hi';
		$json  = '{"email":"a@b.co","note":"Hi"}';

		return array(
			'html empty object'  => array( 'html', 422, 'invalid_fields', 'application/json', '{}', false ),
			'json empty object'  => array( 'json', 422, 'invalid_fields', 'application/json', '{}', false ),
			'html plus broken'   => array( 'html', 415, 'unsupported_media_type', 'application/vnd.api+json', '{', false ),
			'json plus broken'   => array( 'json', 415, 'unsupported_media_type', 'application/vnd.api+json', '{', false ),
			'html success'       => array( 'html', 303, 'accepted', 'application/x-www-form-urlencoded', $valid, false ),
			'json success'       => array( 'json', 201, 'accepted', 'application/json', $json, false ),
			'html missing field' => array( 'html', 422, 'invalid_fields', 'application/x-www-form-urlencoded', 'note=SECRET', false ),
			'json missing field' => array( 'json', 422, 'invalid_fields', 'application/json', '{"note":"SECRET"}', false ),
			'html unknown type'  => array( 'html', 415, 'unsupported_media_type', 'text/plain', 'SECRET', false ),
			'json unknown type'  => array( 'json', 415, 'unsupported_media_type', 'text/plain', 'SECRET', false ),
			'html plus json'     => array( 'html', 415, 'unsupported_media_type', 'application/vnd.api+json', '{}', false ),
			'json plus json'     => array( 'json', 415, 'unsupported_media_type', 'application/vnd.api+json', '{}', false ),
			'html file'          => array( 'html', 415, 'files_not_supported', 'multipart/form-data', '', true ),
			'json file'          => array( 'json', 415, 'files_not_supported', 'multipart/form-data', '', true ),
			'html malformed'     => array( 'html', 400, 'malformed_body', 'application/json', '{"email":', false ),
			'json malformed'     => array( 'json', 400, 'malformed_body', 'application/json', '{"email":', false ),
			'html json array'    => array( 'html', 400, 'malformed_body', 'application/json', '["SECRET"]', false ),
			'json json array'    => array( 'json', 400, 'malformed_body', 'application/json', '["SECRET"]', false ),
			'html missing form'  => array( 'html', 404, 'form_not_found', 'application/x-www-form-urlencoded', $valid, false ),
			'json missing form'  => array( 'json', 404, 'form_not_found', 'application/json', $json, false ),
			'html storage'       => array( 'html', 500, 'storage_failed', 'application/x-www-form-urlencoded', 'email=a@b.co&note=SECRET', false ),
			'json storage'       => array( 'json', 500, 'storage_failed', 'application/json', '{"email":"a@b.co","note":"SECRET"}', false ),
		);
	}

	/**
	 * Every field error code is returned in both response modes.
	 *
	 * @dataProvider field_errors
	 *
	 * @param string $mode  `html` or `json`.
	 * @param string $field Field name.
	 * @param string $code  Expected field error code.
	 * @param string $type  Content type.
	 * @param string $body  Raw body. The token SECRET stands in for a submitted value.
	 * @param string $label Field label expected on the HTML page.
	 */
	public function test_field_error( string $mode, string $field, string $code, string $type, string $body, string $label ): void {
		$form    = $this->save_contract_form();
		$secret  = 'contract-secret-value';
		$request = $this->request( $form->public_id(), $mode, $type, str_replace( 'SECRET', $secret, $body ), false );

		$response = rest_do_request( $request );

		$this->assertSame( 422, $response->get_status() );
		$this->assertSame( array(), $this->rows() );

		if ( 'json' === $mode ) {
			$data = $response->get_data();
			$this->assertIsArray( $data );
			$this->assertIsArray( $data['error'] );
			$this->assertSame( array( $code ), $data['error']['fields'][ $field ] );
			$this->assertStringNotContainsString( $secret, (string) wp_json_encode( $data ) );
			return;
		}

		$this->assertInstanceOf( HtmlResponse::class, $response );
		$this->assertStringContainsString( $label, $response->html() );
		$this->assertStringNotContainsString( $secret, $response->html() );
	}

	/**
	 * Field error cases: mode, field, code, content type, body, label.
	 *
	 * Invalid UTF-8 cannot be a JSON string, so that case uses a form body
	 * in both response modes.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string}>
	 */
	public function field_errors(): array {
		$cases = array();

		foreach ( array( 'html', 'json' ) as $mode ) {
			$cases[ $mode . ' too_long' ]          = array( $mode, 'name', 'too_long', ...$this->field_body( $mode, array( 'name' => 'SECRET' ) ), 'Name' );
			$cases[ $mode . ' invalid_email' ]     = array( $mode, 'email', 'invalid_email', ...$this->field_body( $mode, array( 'email' => 'SECRET' ) ), 'Email' );
			$cases[ $mode . ' invalid_number' ]    = array( $mode, 'qty', 'invalid_number', ...$this->field_body( $mode, array( 'qty' => '1e2' ) ), 'Quantity' );
			$cases[ $mode . ' too_small' ]         = array( $mode, 'qty', 'too_small', ...$this->field_body( $mode, array( 'qty' => '-1' ) ), 'Quantity' );
			$cases[ $mode . ' too_large' ]         = array( $mode, 'qty', 'too_large', ...$this->field_body( $mode, array( 'qty' => '11' ) ), 'Quantity' );
			$cases[ $mode . ' too_precise' ]       = array( $mode, 'qty', 'too_precise', ...$this->field_body( $mode, array( 'qty' => '1.234' ) ), 'Quantity' );
			$cases[ $mode . ' invalid_choice' ]    = array( $mode, 'color', 'invalid_choice', ...$this->field_body( $mode, array( 'color' => 'green' ) ), 'Color' );
			$cases[ $mode . ' required checkbox' ] = array( $mode, 'agree', 'required', ...$this->field_body( $mode, array(), array( 'agree' ) ), 'Agree' );
			$cases[ $mode . ' required choices' ]  = array( $mode, 'colors', 'required', ...$this->field_body( $mode, array( 'colors' => array() ) ), 'Colors' );
			$cases[ $mode . ' invalid control' ]   = array( $mode, 'note', 'invalid', ...$this->field_body( $mode, array( 'note' => "SECRET\x01" ) ), 'Note' );
			$cases[ $mode . ' invalid array' ]     = array( $mode, 'color', 'invalid', ...$this->field_body( $mode, array( 'color' => array( 'red' ) ) ), 'Color' );
			$cases[ $mode . ' invalid utf8' ]      = array( $mode, 'name', 'invalid', ...$this->field_body( $mode, array( 'name' => "SECRET\xC3\x28" ), array(), true ), 'Name' );
		}

		$cases['json boolean number'] = array(
			'json',
			'agree',
			'invalid',
			'application/json',
			'{"email":"a@b.co","colors":["red"],"agree":1,"note":"SECRET"}',
			'Agree',
		);

		return $cases;
	}

	/**
	 * Body for one field-error case, with the other required fields filled in.
	 *
	 * @param string               $mode      `html` or `json`.
	 * @param array<string, mixed> $overrides Values that replace the valid defaults.
	 * @param string[]             $omit      Field names to leave out.
	 * @param bool                 $as_form   Whether to send form encoding even in JSON mode.
	 * @return array{0: string, 1: string}
	 */
	private function field_body( string $mode, array $overrides = array(), array $omit = array(), bool $as_form = false ): array {
		$values = array(
			'email'  => 'a@b.co',
			'agree'  => 'json' === $mode && ! $as_form ? true : 'on',
			'colors' => array( 'red' ),
			'note'   => 'SECRET',
		);

		foreach ( $omit as $name ) {
			unset( $values[ $name ] );
		}
		foreach ( $overrides as $name => $value ) {
			$values[ $name ] = $value;
		}

		if ( 'json' === $mode && ! $as_form ) {
			$json = wp_json_encode( $values );
			return array( 'application/json', is_string( $json ) ? $json : '{}' );
		}

		return array( 'application/x-www-form-urlencoded', $this->form_body( $values ) );
	}

	/**
	 * URL-encoded body. A list uses `name[]` fields.
	 *
	 * @param array<string, mixed> $values Field values.
	 */
	private function form_body( array $values ): string {
		$parts = array();
		foreach ( $values as $name => $value ) {
			$key = rawurlencode( (string) $name );
			if ( is_array( $value ) ) {
				if ( array() === $value ) {
					$parts[] = $key . '[]=';
					continue;
				}
				foreach ( $value as $item ) {
					$parts[] = $key . '[]=' . rawurlencode( (string) $item );
				}
				continue;
			}

			$parts[] = $key . '=' . rawurlencode( (string) $value );
		}

		return implode( '&', $parts );
	}

	/**
	 * Build a request for one contract case.
	 *
	 * @param string $public_id Public form id.
	 * @param string $mode      `html` or `json`.
	 * @param string $type      Content type.
	 * @param string $body      Raw body.
	 * @param bool   $files     Whether to attach a file part.
	 */
	private function request( string $public_id, string $mode, string $type, string $body, bool $files ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/formstead/v1/forms/' . $public_id . '/submissions' );
		$request->set_header( 'Content-Type', $type );
		if ( 'json' === $mode ) {
			$request->set_header( 'Accept', 'application/json' );
		}
		$request->set_body( $body );

		if ( 'application/x-www-form-urlencoded' === $type ) {
			parse_str( $body, $params );
			$request->set_body_params( is_array( $params ) ? $params : array() );
		}
		if ( $files ) {
			$request->set_body_params( array( 'email' => 'a@b.co' ) );
			$request->set_file_params(
				array(
					'upload' => array(
						'name'     => 'secret.txt',
						'type'     => 'text/plain',
						'tmp_name' => '/tmp/formstead-secret',
						'error'    => 0,
						'size'     => 3,
					),
				)
			);
		}

		return $request;
	}

	/**
	 * Form whose fields can produce every field error code.
	 */
	private function save_contract_form(): Form {
		$choice = new FieldSettings(
			null,
			null,
			0,
			array(
				array(
					'value' => 'red',
					'label' => 'Red',
				),
				array(
					'value' => 'blue',
					'label' => 'Blue',
				),
			),
			false
		);

		$saved = ( new FormRepository() )->insert(
			new Form(
				0,
				'',
				'Contract',
				true,
				array(
					new Field( 'email', 'Email', FieldType::Email, true, 255 ),
					new Field( 'name', 'Name', FieldType::Text, false, 3 ),
					new Field( 'note', 'Note', FieldType::Textarea, false, 1000 ),
					new Field( 'qty', 'Quantity', FieldType::Number, false, 255, new FieldSettings( '0', '10', 2, array(), false ) ),
					new Field( 'agree', 'Agree', FieldType::Boolean, true, 5 ),
					new Field( 'color', 'Color', FieldType::Choice, false, 190, $choice ),
					new Field( 'colors', 'Colors', FieldType::Choice, true, 190, new FieldSettings( null, null, 0, $choice->options(), true ) ),
				),
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
	 * Persist the contact form used by every case.
	 */
	private function save_form(): Form {
		$saved = ( new FormRepository() )->insert(
			new Form(
				0,
				'',
				'Contact',
				true,
				array(
					new Field( 'email', 'Email', FieldType::Email, true, 255 ),
					new Field( 'note', 'Note', FieldType::Textarea, false, 1000 ),
					new Field( 'qty', 'Quantity', FieldType::Number, false, 255, new FieldSettings( null, null, 2, array(), false ) ),
					new Field( 'agree', 'Agree', FieldType::Boolean, false, 5 ),
					new Field(
						'color',
						'Color',
						FieldType::Choice,
						false,
						190,
						new FieldSettings(
							null,
							null,
							0,
							array(
								array(
									'value' => 'red',
									'label' => 'Red',
								),
							),
							false
						)
					),
				),
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
	 * Submission rows in insertion order.
	 *
	 * @return array<int, array<string, string>>
	 */
	private function rows(): array {
		global $wpdb;

		$table = Schema::submissions_table();
		$rows  = $wpdb->get_results( "SELECT payload FROM {$table} ORDER BY id ASC", ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$clean = array();
		foreach ( $rows as $row ) {
			if ( is_array( $row ) ) {
				$clean[] = array( 'payload' => (string) ( $row['payload'] ?? '' ) );
			}
		}

		return $clean;
	}
}
