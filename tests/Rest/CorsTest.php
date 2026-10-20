<?php
/**
 * Tests for origin checks and CORS.
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
use Formstead\Rest\Cors;
use Formstead\Rest\HtmlResponse;
use Formstead\Security\OriginPolicy;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * Covers the allowlist, preflight, and the absence of credentialed CORS.
 *
 * @phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
 * @phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
 * @phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
 */
final class CorsTest extends WP_UnitTestCase {

	/**
	 * Prepare the REST server.
	 */
	public function set_up(): void {
		parent::set_up();
		OriginPolicy::reset();
		rest_get_server();
	}

	/**
	 * An origin is scheme, host, and a non-default port.
	 */
	public function test_origin_comparison_ignores_case_and_the_default_port(): void {
		$this->assertSame( 'https://forms.example', OriginPolicy::normalize( 'https://Forms.Example:443' ) );
		$this->assertSame( 'http://forms.example:8080', OriginPolicy::normalize( 'http://forms.example:8080' ) );
		$this->assertNull( OriginPolicy::normalize( 'null' ) );
		$this->assertNull( OriginPolicy::normalize( 'https://forms.example/path' ) );
		$this->assertNotContains( 'https://forms.example.evil', array( 'https://forms.example' ) );
	}

	/**
	 * A missing origin is allowed. A foreign origin and `Origin: null` are not.
	 */
	public function test_missing_origin_is_allowed_and_a_foreign_origin_is_rejected(): void {
		$form = $this->save_form( array() );

		$missing = rest_do_request( $this->request( $form->public_id(), null ) );
		$this->assertSame( 303, $missing->get_status() );
		$this->assertArrayNotHasKey( 'Access-Control-Allow-Origin', $missing->get_headers() );
		$this->assert_no_credentials( $missing );

		$site = OriginPolicy::site_origins()[0];
		$same = rest_do_request( $this->request( $form->public_id(), $site ) );
		$this->assertSame( 303, $same->get_status() );
		$this->assertSame( $site, $same->get_headers()['Access-Control-Allow-Origin'] );
		$this->assert_no_credentials( $same );

		$stored  = count( $this->payloads() );
		$foreign = rest_do_request( $this->request( $form->public_id(), 'https://evil.example' ) );
		$this->assert_rejected( $foreign );
		$this->assertCount( $stored, $this->payloads() );

		$null = rest_do_request( $this->request( $form->public_id(), 'null' ) );
		$this->assert_rejected( $null );
		$this->assertCount( $stored, $this->payloads() );
	}

	/**
	 * An allowlist entry matches with or without the default port, and not a lookalike host.
	 */
	public function test_allowlist_matches_the_origin_exactly(): void {
		$form = $this->save_form( array( 'https://forms.example' ) );

		$allowed = rest_do_request( $this->request( $form->public_id(), 'https://forms.example:443', 'json' ) );
		$this->assertSame( 201, $allowed->get_status() );
		$this->assertSame( 'https://forms.example', $allowed->get_headers()['Access-Control-Allow-Origin'] );
		$this->assert_no_credentials( $allowed );

		$stored    = count( $this->payloads() );
		$lookalike = rest_do_request( $this->request( $form->public_id(), 'https://forms.example.evil' ) );
		$this->assert_rejected( $lookalike );

		$json = rest_do_request( $this->request( $form->public_id(), 'https://evil.example', 'json' ) );
		$this->assertSame( 403, $json->get_status() );
		$data = $json->get_data();
		$this->assertIsArray( $data );
		$this->assertSame( 'origin_not_allowed', $data['error']['code'] );
		$this->assert_no_credentials( $json );
		$this->assertCount( $stored, $this->payloads() );
	}

	/**
	 * Preflight grants CORS only for an allowed origin.
	 */
	public function test_preflight_grants_an_allowed_origin_and_not_a_rejected_one(): void {
		$form    = $this->save_form( array( 'https://forms.example' ) );
		$allowed = rest_do_request( $this->preflight( $form->public_id(), 'https://forms.example' ) );

		$this->assertSame( 204, $allowed->get_status() );
		$this->assertSame( 'https://forms.example', $allowed->get_headers()['Access-Control-Allow-Origin'] );
		$this->assertSame( 'POST, OPTIONS', $allowed->get_headers()['Access-Control-Allow-Methods'] );
		$this->assertStringContainsString( 'Idempotency-Key', $allowed->get_headers()['Access-Control-Allow-Headers'] );
		$this->assertSame( 'Accept, Origin', $allowed->get_headers()['Vary'] );
		$this->assert_no_credentials( $allowed );

		$rejected = rest_do_request( $this->preflight( $form->public_id(), 'https://evil.example' ) );
		$this->assertSame( 204, $rejected->get_status() );
		$this->assertArrayNotHasKey( 'Access-Control-Allow-Origin', $rejected->get_headers() );
		$this->assert_no_credentials( $rejected );

		$unknown = rest_do_request( $this->preflight( 'fs_aaaaaaaaaaaaaaaa', 'https://forms.example' ) );
		$this->assertSame( 204, $unknown->get_status() );
		$this->assertArrayNotHasKey( 'Access-Control-Allow-Origin', $unknown->get_headers() );
	}

	/**
	 * The form row is loaded once for the origin checks in a single dispatch.
	 */
	public function test_origin_lookup_is_cached_for_the_request(): void {
		$form    = $this->save_form( array( 'https://forms.example' ) );
		$table   = Schema::forms_table();
		$selects = 0;
		$filter  = static function ( $query ) use ( $table, &$selects ) {
			if ( is_string( $query ) && str_contains( $query, $table ) && str_contains( strtolower( $query ), 'select' ) ) {
				++$selects;
			}

			return $query;
		};

		add_filter( 'query', $filter );
		try {
			$response = rest_do_request( $this->request( $form->public_id(), 'https://forms.example' ) );
		} finally {
			remove_filter( 'query', $filter );
		}

		$this->assertSame( 303, $response->get_status() );
		$this->assertSame( 2, $selects );
	}

	/**
	 * Taking over CORS for v1 does not unregister core CORS for other routes.
	 */
	public function test_takeover_leaves_core_cors_registered(): void {
		$server = rest_get_server();
		if ( false === has_filter( 'rest_pre_serve_request', 'rest_send_cors_headers' ) ) {
			add_filter( 'rest_pre_serve_request', 'rest_send_cors_headers' );
		}

		Cors::take_over( false, null, new WP_REST_Request( 'GET', '/wp/v2/types' ), $server );
		$this->assertNotFalse( has_filter( 'rest_pre_serve_request', 'rest_send_cors_headers' ) );

		Cors::take_over( false, null, new WP_REST_Request( 'POST', '/formstead/v10/forms/x/submissions' ), $server );
		$this->assertNotFalse( has_filter( 'rest_pre_serve_request', 'rest_send_cors_headers' ) );

		Cors::take_over( false, null, new WP_REST_Request( 'POST', '/formstead/v1/forms/fs_abcdefghijklmnop/submissions' ), $server );
		$this->assertNotFalse( has_filter( 'rest_pre_serve_request', 'rest_send_cors_headers' ) );
	}

	/**
	 * Assert a 403 without a stored row and without credentialed CORS.
	 *
	 * @param mixed $response Response.
	 */
	private function assert_rejected( mixed $response ): void {
		$this->assertInstanceOf( HtmlResponse::class, $response );
		$this->assertSame( 403, $response->get_status() );
		$this->assertStringContainsString( 'does not accept submissions from that site', $response->html() );
		$this->assert_no_credentials( $response );
	}

	/**
	 * Credentialed CORS is never part of a Formstead response.
	 *
	 * @param WP_REST_Response $response Response.
	 */
	private function assert_no_credentials( WP_REST_Response $response ): void {
		foreach ( $response->get_headers() as $name => $value ) {
			$this->assertStringNotContainsStringIgnoringCase( 'allow-credentials', (string) $name );
			$this->assertStringNotContainsStringIgnoringCase( 'true', (string) $name );
			if ( is_string( $name ) && str_contains( strtolower( $name ), 'access-control' ) ) {
				$this->assertStringNotContainsStringIgnoringCase( 'credentials', (string) $value );
			}
		}
	}

	/**
	 * Store a form with an allowlist. An empty list means this site only.
	 *
	 * @param string[] $origins Allowed origins.
	 */
	private function save_form( array $origins ): Form {
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
				new FormProtection( $origins, null, null )
			)
		);
		$this->assertInstanceOf( Form::class, $saved );
		return $saved;
	}

	/**
	 * POST a valid body with an optional origin.
	 *
	 * @param string      $public_id Public form id.
	 * @param string|null $origin    Origin header, or null to omit it.
	 * @param string      $mode      `html` or `json`.
	 */
	private function request( string $public_id, ?string $origin, string $mode = 'html' ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/formstead/v1/forms/' . $public_id . '/submissions' );
		$request->set_header( 'Content-Type', 'application/x-www-form-urlencoded' );
		$request->set_body( 'email=a@b.co' );
		$request->set_body_params( array( 'email' => 'a@b.co' ) );
		if ( 'json' === $mode ) {
			$request->set_header( 'Accept', 'application/json' );
		}
		if ( null !== $origin ) {
			$request->set_header( 'Origin', $origin );
		}

		return $request;
	}

	/**
	 * OPTIONS preflight for a form.
	 *
	 * @param string $public_id Public form id.
	 * @param string $origin    Origin header.
	 */
	private function preflight( string $public_id, string $origin ): WP_REST_Request {
		$request = new WP_REST_Request( 'OPTIONS', '/formstead/v1/forms/' . $public_id . '/submissions' );
		$request->set_header( 'Origin', $origin );
		$request->set_header( 'Access-Control-Request-Method', 'POST' );
		return $request;
	}

	/**
	 * Stored payloads.
	 *
	 * @return list<string>
	 */
	private function payloads(): array {
		global $wpdb;

		$table = Schema::submissions_table();
		$rows  = $wpdb->get_col( "SELECT payload FROM {$table}" );
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
