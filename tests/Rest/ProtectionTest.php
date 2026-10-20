<?php
/**
 * Tests for submission limits, the honeypot, and rate limiting.
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
use Formstead\Security\IpHasher;
use Formstead\Security\RateLimiter;
use Formstead\Submissions\ReceiptId;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * Covers 413, 429, and the honeypot in both response modes.
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
	 * JSON deeper than WordPress can parse is too large, not malformed.
	 *
	 * @dataProvider modes
	 * @param string $mode `html` or `json`.
	 */
	public function test_json_past_the_parser_depth_returns_413( string $mode ): void {
		$form   = $this->save_form();
		$nested = '"a@b.co"';
		for ( $index = 0; $index < 600; $index++ ) {
			$nested = '[' . $nested . ']';
		}

		$request = $this->request( $form->public_id(), $mode, '{"email":' . $nested . '}' );
		$request->set_header( 'Content-Type', 'application/json' );

		$response = rest_do_request( $request );

		$this->assert_error( $response, $mode, 413, 'payload_too_large' );
		$this->assertSame( array(), $this->rows() );
	}

	/**
	 * A filled honeypot looks like success and stores nothing.
	 *
	 * @dataProvider modes
	 * @param string $mode `html` or `json`.
	 */
	public function test_filled_honeypot_pretends_to_succeed( string $mode ): void {
		$form = $this->save_form();
		if ( 'json' === $mode ) {
			$request = $this->request( $form->public_id(), 'json', '{"email":"a@b.co","_fs_honeypot":"bot"}' );
			$request->set_header( 'Content-Type', 'application/json' );
		} else {
			$request = $this->request( $form->public_id(), 'html', 'email=a@b.co&_fs_honeypot=bot' );
		}

		$response = rest_do_request( $request );

		$this->assertSame( 'json' === $mode ? 201 : 303, $response->get_status() );
		$this->assertSame( array(), $this->rows() );
		if ( 'json' === $mode ) {
			$data = $response->get_data();
			$this->assertIsArray( $data );
			$this->assertTrue( ReceiptId::is_valid( (string) $data['receipt_id'] ) );
		} else {
			$this->assertSame( 'https://example.com/thanks', $response->get_headers()['Location'] );
		}

		$loaded = ( new FormRepository() )->find_by_id( $form->id() );
		$this->assertInstanceOf( Form::class, $loaded );
		$this->assertSame( 1, $loaded->honeypot_rejections() );
	}

	/**
	 * The sixth submission from one address is told to wait.
	 */
	public function test_sixth_request_from_one_address_returns_429(): void {
		$form = $this->save_form();
		$this->set_remote( '203.0.113.10' );

		try {
			for ( $index = 0; $index < 5; $index++ ) {
				$response = rest_do_request( $this->request( $form->public_id(), 'json', 'email=a@b.co' ) );
				$this->assertSame( 201, $response->get_status() );
			}

			$limited = rest_do_request( $this->request( $form->public_id(), 'html', 'email=a@b.co' ) );
		} finally {
			$this->clear_remote();
		}

		$this->assert_error( $limited, 'html', 429, 'rate_limited' );
		$this->assertArrayHasKey( 'Retry-After', $limited->get_headers() );
		$this->assertGreaterThan( 0, (int) $limited->get_headers()['Retry-After'] );
		$this->assertCount( 5, $this->rows() );
		$this->assert_buckets_hide_address( '203.0.113.10' );
	}

	/**
	 * A forged forwarding header is ignored unless the direct address is trusted.
	 */
	public function test_spoofed_forwarded_for_stays_in_the_same_bucket(): void {
		$form = $this->save_form();
		$this->set_remote( '203.0.113.10' );

		try {
			for ( $index = 0; $index < 5; $index++ ) {
				$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.' . $index;
				$response                        = rest_do_request( $this->request( $form->public_id(), 'json', 'email=a@b.co' ) );
				$this->assertSame( 201, $response->get_status() );
			}

			$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.99';
			$limited                         = rest_do_request( $this->request( $form->public_id(), 'json', 'email=a@b.co' ) );
			$this->assertSame( 429, $limited->get_status() );

			$this->set_remote( '203.0.113.11' );
			$other = rest_do_request( $this->request( $form->public_id(), 'json', 'email=a@b.co' ) );
			$this->assertSame( 201, $other->get_status() );
		} finally {
			$this->clear_remote();
		}
	}

	/**
	 * A trusted proxy contributes the address at the right of the forwarding header.
	 */
	public function test_trusted_proxy_uses_the_forwarded_address(): void {
		$form = $this->save_form();
		add_filter( 'formstead_trusted_proxies', array( $this, 'trust_proxy' ) );
		$this->set_remote( '203.0.113.10' );

		try {
			for ( $index = 0; $index < 5; $index++ ) {
				$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.8';
				$response                        = rest_do_request( $this->request( $form->public_id(), 'json', 'email=a@b.co' ) );
				$this->assertSame( 201, $response->get_status() );
			}

			$limited = rest_do_request( $this->request( $form->public_id(), 'json', 'email=a@b.co' ) );
			$this->assertSame( 429, $limited->get_status() );

			$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.9';
			$other                           = rest_do_request( $this->request( $form->public_id(), 'json', 'email=a@b.co' ) );
			$this->assertSame( 201, $other->get_status() );
		} finally {
			remove_filter( 'formstead_trusted_proxies', array( $this, 'trust_proxy' ) );
			$this->clear_remote();
		}
	}

	/**
	 * Two IPv6 addresses in one /64 share a bucket. A different /64 does not.
	 */
	public function test_ipv6_is_counted_by_prefix(): void {
		$form = $this->save_form();
		$this->set_remote( '2001:db8:1:2::1' );

		try {
			for ( $index = 0; $index < 5; $index++ ) {
				$response = rest_do_request( $this->request( $form->public_id(), 'json', 'email=a@b.co' ) );
				$this->assertSame( 201, $response->get_status() );
			}

			$this->set_remote( '2001:db8:1:2::abcd' );
			$same = rest_do_request( $this->request( $form->public_id(), 'json', 'email=a@b.co' ) );
			$this->assertSame( 429, $same->get_status() );

			$this->set_remote( '2001:db8:1:3::1' );
			$other = rest_do_request( $this->request( $form->public_id(), 'json', 'email=a@b.co' ) );
			$this->assertSame( 201, $other->get_status() );
		} finally {
			$this->clear_remote();
		}
	}

	/**
	 * A per-form minute override replaces the global limit.
	 */
	public function test_form_rate_override_is_enforced(): void {
		$form = $this->save_form( new FormProtection( array(), 1, null ) );
		$this->set_remote( '203.0.113.20' );

		try {
			$first  = rest_do_request( $this->request( $form->public_id(), 'json', 'email=a@b.co' ) );
			$second = rest_do_request( $this->request( $form->public_id(), 'json', 'email=a@b.co' ) );
		} finally {
			$this->clear_remote();
		}

		$this->assertSame( 201, $first->get_status() );
		$this->assertSame( 429, $second->get_status() );
	}

	/**
	 * A failure while counting does not reject the submission or log the address.
	 */
	public function test_counter_failure_still_stores_the_submission(): void {
		$form   = $this->save_form();
		$table  = Schema::rate_limits_table();
		$filter = static function ( $query ) use ( $table ) {
			if ( is_string( $query ) && str_contains( $query, $table ) && str_contains( strtolower( $query ), 'insert' ) ) {
				return '';
			}
			return $query;
		};

		$previous_log = ini_get( 'error_log' );
		$log_file     = tempnam( sys_get_temp_dir(), 'formstead' );
		$this->assertIsString( $log_file );
		// phpcs:ignore WordPress.PHP.IniSet.Risky -- capture this test's error_log line only.
		ini_set( 'error_log', $log_file );
		$this->set_remote( '203.0.113.30' );
		add_filter( 'query', $filter );

		try {
			$response = rest_do_request( $this->request( $form->public_id(), 'html', 'email=a@b.co' ) );
		} finally {
			remove_filter( 'query', $filter );
			$this->clear_remote();
			// phpcs:ignore WordPress.PHP.IniSet.Risky -- restore the previous error_log destination.
			ini_set( 'error_log', is_string( $previous_log ) ? $previous_log : '' );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local log file.
		$log = is_readable( $log_file ) ? (string) file_get_contents( $log_file ) : '';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- local log file.
		unlink( $log_file );

		$this->assertSame( 303, $response->get_status() );
		$this->assertCount( 1, $this->rows() );
		$this->assertStringNotContainsString( '203.0.113.30', $log );
	}

	/**
	 * Cleanup deletes only expired counters.
	 */
	public function test_cleanup_deletes_expired_rows_only(): void {
		global $wpdb;

		$table = Schema::rate_limits_table();
		$wpdb->insert(
			$table,
			array(
				'bucket'     => str_repeat( 'a', 64 ),
				'hits'       => 1,
				'expires_at' => time() - 10,
			),
			array( '%s', '%d', '%d' )
		);
		$wpdb->insert(
			$table,
			array(
				'bucket'     => str_repeat( 'b', 64 ),
				'hits'       => 1,
				'expires_at' => time() + 60,
			),
			array( '%s', '%d', '%d' )
		);

		( new RateLimiter() )->prune_expired( time() );

		$left = $wpdb->get_col( "SELECT bucket FROM {$table}" );
		$this->assertSame( array( str_repeat( 'b', 64 ) ), $left );
	}

	/**
	 * The HMAC secret rotates after a day and is not autoloaded.
	 */
	public function test_ip_secret_rotates_lazily_and_is_not_autoloaded(): void {
		global $wpdb;

		update_option(
			IpHasher::OPTION,
			array(
				'secret'     => 'old-secret',
				'rotated_at' => time() - DAY_IN_SECONDS - 5,
			),
			false
		);

		$secret = IpHasher::secret();
		$this->assertNotSame( 'old-secret', $secret );
		$this->assertSame( $secret, IpHasher::secret() );

		$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", IpHasher::OPTION ) );
		$this->assertContains( (string) $autoload, array( 'off', 'no', 'auto-off' ) );
	}

	/**
	 * Trusted proxy used by the forwarding test.
	 *
	 * @return list<string>
	 */
	public function trust_proxy(): array {
		return array( '203.0.113.10' );
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
	 * Set the direct client address and drop a previous forwarding header.
	 *
	 * @param string $ip IP address.
	 */
	private function set_remote( string $ip ): void {
		$_SERVER['REMOTE_ADDR'] = $ip;
		unset( $_SERVER['HTTP_X_FORWARDED_FOR'] );
	}

	/**
	 * Forget the addresses set by a test.
	 */
	private function clear_remote(): void {
		unset( $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_X_FORWARDED_FOR'] );
	}

	/**
	 * Rate-limit keys are hashes, not the client address.
	 *
	 * @param string $ip Address that must not appear.
	 */
	private function assert_buckets_hide_address( string $ip ): void {
		global $wpdb;

		$table   = Schema::rate_limits_table();
		$buckets = $wpdb->get_col( "SELECT bucket FROM {$table}" );
		$this->assertIsArray( $buckets );
		$this->assertNotEmpty( $buckets );
		foreach ( $buckets as $bucket ) {
			$this->assertIsString( $bucket );
			$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $bucket );
			$this->assertStringNotContainsString( $ip, $bucket );
		}
	}

	/**
	 * Submission payloads.
	 *
	 * @return list<string>
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
