<?php
/**
 * Sends a webhook without letting DNS or a redirect change the target.
 *
 * @package Formstead
 */

namespace Formstead\Webhook;

use WP_Error;
use WpOrg\Requests\Exception as RequestsException;
use WpOrg\Requests\IdnaEncoder;
use WpOrg\Requests\Iri;
use WpOrg\Requests\Transport\Curl;

/**
 * Posts to an address the policy already checked.
 *
 * `CURLOPT_RESOLVE` pins that address for the name. Redirects are not followed.
 * The receiver's body is discarded.
 */
final class PinnedRequest {

	/**
	 * Seconds to wait for the receiver.
	 */
	public const TIMEOUT = 5;

	/**
	 * URL policy.
	 *
	 * @var WebhookUrlPolicy
	 */
	private WebhookUrlPolicy $policy;

	/**
	 * Create a sender.
	 *
	 * @param WebhookUrlPolicy|null $policy Policy to check with. Tests pass one with a fixed resolver.
	 */
	public function __construct( ?WebhookUrlPolicy $policy = null ) {
		$this->policy = $policy instanceof WebhookUrlPolicy ? $policy : new WebhookUrlPolicy();
	}

	/**
	 * POST a body. Returns null when the receiver answers 2xx, or an error code.
	 *
	 * A blocked URL is not requested.
	 *
	 * @param string                $url     Webhook URL.
	 * @param string                $body    Request body.
	 * @param array<string, string> $headers Request headers.
	 */
	public function post( string $url, string $body, array $headers = array() ): ?string {
		$target = $this->policy->inspect( $url );
		if ( $target instanceof WP_Error ) {
			return WebhookFailure::BLOCKED_URL;
		}
		if ( ! Curl::test( array( 'ssl' => true ) ) || ! function_exists( 'curl_setopt' ) || ! defined( 'CURLOPT_RESOLVE' ) ) {
			return WebhookFailure::UNSAFE_TRANSPORT;
		}

		$args = array(
			'timeout'             => self::TIMEOUT,
			'redirection'         => 0,
			'reject_unsafe_urls'  => true,
			'body'                => $body,
			'headers'             => $headers,
			'formstead_resolve'   => $target['resolve'],
			'limit_response_size' => 1024,
		);

		// Requests normalizes host case, IDNs and default ports before its hook.
		$iri       = new Iri( $target['url'] );
		$iri->host = IdnaEncoder::encode( $iri->ihost );
		$canonical = $iri->uri;
		// Select the transport for this request only. Never fall back to streams.
		$force_curl = static function ( &$url, &$request_headers, &$data, &$type, &$options ) use ( $canonical ): void {
			unset( $request_headers, $data, $type );
			if ( $url === $canonical && is_array( $options ) ) {
				$options['transport'] = new Curl();
			}
		};
		$pin        = static function ( $handle, $request_args, $url ) use ( $target ): void {
			if ( $url !== $target['url'] ) {
				return;
			}
			self::pin_address( $handle, $request_args, $url );
		};
		add_action( 'requests-requests.before_request', $force_curl, PHP_INT_MAX, 5 );
		add_action( 'http_api_curl', $pin, PHP_INT_MAX, 3 );
		try {
			$response = wp_safe_remote_post( $target['url'], $args );
		} finally {
			remove_action( 'requests-requests.before_request', $force_curl, PHP_INT_MAX );
			remove_action( 'http_api_curl', $pin, PHP_INT_MAX );
		}

		if ( $response instanceof WP_Error ) {
			return $this->transport_code( $response );
		}

		return $this->status_code( (int) wp_remote_retrieve_response_code( $response ) );
	}

	/**
	 * Point curl at the addresses the policy checked.
	 *
	 * @param mixed $handle Curl handle.
	 * @param mixed $args   Request arguments.
	 * @param mixed $url    Request URL. Unused.
	 * @throws RequestsException When cURL cannot enforce the checked destination.
	 */
	public static function pin_address( mixed $handle, mixed $args, mixed $url ): mixed {
		unset( $url );

		if ( ! is_array( $args ) || ! isset( $args['formstead_resolve'] ) || ! is_array( $args['formstead_resolve'] ) ) {
			throw new RequestsException( esc_html( WebhookFailure::UNSAFE_TRANSPORT ), 'formstead_transport' );
		}
		if ( ! $handle instanceof \CurlHandle ) {
			throw new RequestsException( esc_html( WebhookFailure::UNSAFE_TRANSPORT ), 'formstead_transport' );
		}

		// CURLOPT_RESOLVE has no WordPress wrapper. The request itself uses wp_safe_remote_post().
		// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt
		$pinned = curl_setopt( $handle, CURLOPT_RESOLVE, $args['formstead_resolve'] );
		// A proxy could resolve the host itself and bypass CURLOPT_RESOLVE.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt
		$direct = curl_setopt( $handle, CURLOPT_PROXY, '' );
		if ( ! $pinned || ! $direct ) {
			throw new RequestsException( esc_html( WebhookFailure::UNSAFE_TRANSPORT ), 'formstead_transport' );
		}

		return $handle;
	}

	/**
	 * Error code for a failed connection. The message is not stored.
	 *
	 * @param WP_Error $error Transport error.
	 */
	private function transport_code( WP_Error $error ): string {
		if ( WebhookFailure::UNSAFE_TRANSPORT === $error->get_error_message() ) {
			return WebhookFailure::UNSAFE_TRANSPORT;
		}
		$message = strtolower( $error->get_error_message() );
		if ( str_contains( $message, 'timed out' ) || str_contains( $message, 'timeout' ) ) {
			return WebhookFailure::TIMEOUT;
		}

		return WebhookFailure::CONNECT_FAILED;
	}

	/**
	 * Error code for an HTTP status, or null for success.
	 *
	 * @param int $status HTTP status. Zero when there was none.
	 */
	private function status_code( int $status ): ?string {
		if ( $status >= 200 && $status < 300 ) {
			return null;
		}
		if ( $status >= 300 && $status < 400 ) {
			return WebhookFailure::REDIRECT;
		}
		if ( 429 === $status ) {
			return WebhookFailure::HTTP_429;
		}
		if ( $status >= 500 && $status < 600 ) {
			return WebhookFailure::HTTP_5XX;
		}
		if ( $status >= 400 && $status < 500 ) {
			return WebhookFailure::HTTP_4XX;
		}

		return WebhookFailure::CONNECT_FAILED;
	}
}
