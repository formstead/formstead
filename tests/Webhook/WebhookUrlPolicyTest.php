<?php
/**
 * Tests for webhook address checks.
 *
 * @package Formstead
 */

namespace Formstead\Tests\Webhook;

use Formstead\Webhook\PinnedRequest;
use Formstead\Webhook\WebhookFailure;
use Formstead\Webhook\WebhookUrlPolicy;
use WP_Error;
use WP_UnitTestCase;

/**
 * Covers blocked networks, literal spellings, pinning, and redirects.
 */
final class WebhookUrlPolicyTest extends WP_UnitTestCase {

	/**
	 * Arguments of the redirect request.
	 *
	 * @var array<string, mixed>|null
	 */
	private static ?array $request_args = null;

	/**
	 * URL of the redirect request.
	 *
	 * @var string
	 */
	private static string $request_url = '';

	/**
	 * Drop the test allowlist.
	 */
	public function tear_down(): void {
		remove_all_filters( WebhookUrlPolicy::ALLOWED_HOSTS );
		remove_all_filters( 'pre_http_request' );
		self::$request_args = null;
		self::$request_url  = '';
		parent::tear_down();
	}

	/**
	 * Private, loopback, metadata, and non-HTTPS URLs are rejected.
	 *
	 * @dataProvider blocked_urls
	 * @param string $url URL that must not be called.
	 */
	public function test_blocked_addresses_are_rejected( string $url ): void {
		$result = ( new WebhookUrlPolicy() )->inspect( $url );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( WebhookFailure::BLOCKED_URL, $result->get_error_code() );
		$this->assertStringNotContainsString( 'secret', $result->get_error_message() );
	}

	/**
	 * URLs that must fail the policy.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function blocked_urls(): array {
		return array(
			'localhost'        => array( 'https://localhost/hook' ),
			'loopback v4'      => array( 'https://127.0.0.1/hook' ),
			'loopback v6'      => array( 'https://[::1]/hook' ),
			'metadata'         => array( 'https://169.254.169.254/latest' ),
			'private 10'       => array( 'https://10.1.2.3/hook' ),
			'private 172'      => array( 'https://172.16.5.5/hook' ),
			'private 192.168'  => array( 'https://192.168.1.9/hook' ),
			'cgnat'            => array( 'https://100.64.1.1/hook' ),
			'ula'              => array( 'https://[fc00::1]/hook' ),
			'link local v6'    => array( 'https://[fe80::1]/hook' ),
			'ipv4 mapped'      => array( 'https://[::ffff:127.0.0.1]/hook' ),
			'decimal loopback' => array( 'https://2130706433/hook' ),
			'hex dotted'       => array( 'https://0x7f.1/hook' ),
			'http'             => array( 'http://93.184.216.34/hook' ),
			'userinfo'         => array( 'https://user:secret@93.184.216.34/hook' ),
			'other port'       => array( 'https://93.184.216.34:8443/hook' ),
		);
	}

	/**
	 * A public address is accepted and a name that resolves privately is not.
	 */
	public function test_a_name_is_judged_by_the_addresses_it_resolves_to(): void {
		$policy = new WebhookUrlPolicy(
			static function ( string $host ): array {
				if ( 'hooks.example' === $host ) {
					return array( '10.0.0.8' );
				}
				return array( '93.184.216.34' );
			}
		);

		$blocked = $policy->inspect( 'https://hooks.example/hook' );
		$this->assertInstanceOf( WP_Error::class, $blocked );

		$allowed = $policy->inspect( 'https://public.example/hook' );
		$this->assertIsArray( $allowed );
		$this->assertSame( array( 'public.example:443:93.184.216.34' ), $allowed['resolve'] );

		$literal = ( new WebhookUrlPolicy() )->inspect( 'https://93.184.216.34/hook' );
		$this->assertIsArray( $literal );
		$this->assertSame( array( '93.184.216.34:443:93.184.216.34' ), $literal['resolve'] );
	}

	/**
	 * One private address among public ones rejects the name.
	 */
	public function test_one_private_address_rejects_the_name(): void {
		$policy = new WebhookUrlPolicy(
			static function (): array {
				return array( '93.184.216.34', '192.168.0.2' );
			}
		);

		$this->assertInstanceOf( WP_Error::class, $policy->inspect( 'https://mixed.example/hook' ) );
	}

	/**
	 * An allowed host may use a private address and a port other than 443.
	 */
	public function test_an_allowed_host_may_be_private(): void {
		add_filter(
			WebhookUrlPolicy::ALLOWED_HOSTS,
			static function (): array {
				return array( '127.0.0.1' );
			}
		);

		$result = ( new WebhookUrlPolicy( null, 'local' ) )->inspect( 'https://127.0.0.1:8443/hook' );
		$this->assertIsArray( $result );
		$this->assertSame( 8443, $result['port'] );
	}

	/**
	 * A test filter cannot open a private destination in staging or production.
	 */
	public function test_the_host_exception_is_ignored_outside_local_development(): void {
		add_filter( WebhookUrlPolicy::ALLOWED_HOSTS, static fn(): array => array( '127.0.0.1' ) );
		foreach ( array( 'production', 'staging' ) as $environment ) {
			$this->assertInstanceOf( WP_Error::class, ( new WebhookUrlPolicy( null, $environment ) )->inspect( 'http://127.0.0.1:8443/hook' ) );
		}
	}

	/**
	 * A redirect is not followed, including one that points at the metadata address.
	 */
	public function test_a_redirect_to_a_private_network_is_not_followed(): void {
		$calls  = 0;
		$policy = new WebhookUrlPolicy(
			static function (): array {
				return array( '93.184.216.34' );
			}
		);
		add_filter(
			'pre_http_request',
			static function ( $pre, $args, $url ) use ( &$calls ) {
				unset( $pre );
				++$calls;
				if ( 1 === $calls ) {
					self::$request_args = is_array( $args ) ? $args : null;
					self::$request_url  = is_string( $url ) ? $url : '';
				}
				return array(
					'headers'  => array( 'location' => 'http://169.254.169.254/latest' ),
					'body'     => 'receiver-secret-body',
					'response' => array(
						'code'    => 302,
						'message' => 'Found',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			},
			10,
			3
		);

		$result = ( new PinnedRequest( $policy ) )->post( 'https://hooks.example/hook', '{"ok":true}' );

		$this->assertSame( WebhookFailure::REDIRECT, $result );
		$this->assertSame( 1, $calls );
		$this->assertSame( 'https://hooks.example/hook', self::$request_url );
		$this->assertIsArray( self::$request_args );
		$this->assertSame( 0, self::$request_args['redirection'] );
		$this->assertSame( 5, self::$request_args['timeout'] );
		$this->assertSame( array( 'hooks.example:443:93.184.216.34' ), self::$request_args['formstead_resolve'] );
	}

	/**
	 * A blocked URL does not open a request.
	 */
	public function test_a_blocked_url_is_not_requested(): void {
		$calls = 0;
		add_filter(
			'pre_http_request',
			static function () use ( &$calls ) {
				++$calls;
				return new WP_Error( 'formstead_test', 'should not run' );
			}
		);

		$result = ( new PinnedRequest() )->post( 'https://127.0.0.1/hook', '{}' );
		$this->assertSame( WebhookFailure::BLOCKED_URL, $result );
		$this->assertSame( 0, $calls );
	}
}
