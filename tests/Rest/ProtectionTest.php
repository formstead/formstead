<?php
/**
 * Tests for submission size and field limits.
 *
 * @package Formstead
 */

namespace Formstead\Tests\Rest;

use Formstead\Database\Schema;
use Formstead\Forms\Field;
use Formstead\Forms\FieldType;
use Formstead\Forms\Form;
use Formstead\Forms\FormProtection;
use Formstead\Forms\FormRepository;
use Formstead\Rest\HtmlResponse;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * Covers 413 in both response modes.
 *
 * @phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
 * @phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
 * @phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
 */
final class ProtectionTest extends WP_UnitTestCase {

	/**
	 * Prepare the REST server.
	 */
	public function set_up(): void {
		parent::set_up();
		rest_get_server();
	}

	/**
	 * A body over the size limit is refused before it is stored.
	 *
	 * @dataProvider modes
	 * @param string $mode `html` or `json`.
	 */
	public function test_oversized_body_returns_413( string $mode ): void {
		$form     = $this->save_form();
		$request  = $this->request( $form->public_id(), $mode, 'email=' . str_repeat( 'a', 70000 ) );
		$response = rest_do_request( $request );

		$this->assert_error( $response, $mode, 413, 'payload_too_large' );
		$this->assertSame( array(), $this->rows() );
	}

	/**
	 * A declared Content-Length over the limit is refused even when the body is short.
	 */
	public function test_declared_content_length_returns_413(): void {
		$form    = $this->save_form();
		$request = $this->request( $form->public_id(), 'html', 'email=a@b.co' );
		$request->set_header( 'Content-Length', '999999' );

		$response = rest_do_request( $request );

		$this->assert_error( $response, 'html', 413, 'payload_too_large' );
		$this->assertSame( array(), $this->rows() );
	}

	/**
	 * More than 50 raw fields, including array elements, is refused.
	 */
	public function test_too_many_fields_returns_413(): void {
		$form  = $this->save_form();
		$parts = array( 'email=a@b.co' );
		for ( $index = 0; $index < 50; $index++ ) {
			$parts[] = 'extra' . $index . '=1';
		}

		$response = rest_do_request( $this->request( $form->public_id(), 'json', implode( '&', $parts ) ) );

		$this->assert_error( $response, 'json', 413, 'payload_too_large' );
		$this->assertSame( array(), $this->rows() );
	}

	/**
	 * JSON nested deeper than the limit is too large, not malformed.
	 *
	 * @dataProvider modes
	 * @param string $mode `html` or `json`.
	 */
	public function test_deep_json_returns_413( string $mode ): void {
		$form  = $this->save_form();
		$value = 'a@b.co';
		for ( $index = 0; $index < 20; $index++ ) {
			$value = array( $value );
		}
		$body = (string) wp_json_encode( array( 'email' => $value ) );

		$request = $this->request( $form->public_id(), $mode, $body );
		$request->set_header( 'Content-Type', 'application/json' );

		$response = rest_do_request( $request );

		$this->assert_error( $response, $mode, 413, 'payload_too_large' );
		$this->assertSame( array(), $this->rows() );
	}

	/**
	 * Response modes.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function modes(): array {
		return array(
			'html' => array( 'html' ),
			'json' => array( 'json' ),
		);
	}

	/**
	 * Assert an error page in either mode.
	 *
	 * @param mixed  $response Response.
	 * @param string $mode     `html` or `json`.
	 * @param int    $status   HTTP status.
	 * @param string $code     Error code.
	 */
	private function assert_error( mixed $response, string $mode, int $status, string $code ): void {
		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( $status, $response->get_status() );
		$this->assertArrayNotHasKey( 'Access-Control-Allow-Credentials', $response->get_headers() );
		if ( 'json' === $mode ) {
			$data = $response->get_data();
			$this->assertIsArray( $data );
			$this->assertSame( $code, $data['error']['code'] );
			return;
		}

		$this->assertInstanceOf( HtmlResponse::class, $response );
	}

	/**
	 * Store a contact form.
	 *
	 * @param FormProtection|null $protection Protection settings.
	 */
	private function save_form( ?FormProtection $protection = null ): Form {
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
				$protection
			)
		);
		$this->assertInstanceOf( Form::class, $saved );
		return $saved;
	}

	/**
	 * Build a URL-encoded request unless the caller replaces the content type.
	 *
	 * @param string $public_id Public form id.
	 * @param string $mode      `html` or `json`.
	 * @param string $body      Raw body.
	 */
	private function request( string $public_id, string $mode, string $body ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/formstead/v1/forms/' . $public_id . '/submissions' );
		$request->set_header( 'Content-Type', 'application/x-www-form-urlencoded' );
		if ( 'json' === $mode ) {
			$request->set_header( 'Accept', 'application/json' );
		}
		$request->set_body( $body );
		if ( ! str_starts_with( trim( $body ), '{' ) ) {
			parse_str( $body, $params );
			$request->set_body_params( is_array( $params ) ? $params : array() );
		}

		return $request;
	}

	/**
	 * Submission payloads.
	 *
	 * @return string[]
	 */
	private function rows(): array {
		global $wpdb;

		$table = Schema::submissions_table();
		$rows  = $wpdb->get_col( "SELECT payload FROM {$table} ORDER BY id ASC" );
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$payloads = array();
		foreach ( $rows as $row ) {
			$payloads[] = (string) $row;
		}

		return $payloads;
	}
}
