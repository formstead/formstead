<?php
/**
 * CORS for the public Formstead namespace.
 *
 * @package Formstead
 */

namespace Formstead\Rest;

use Formstead\Security\OriginPolicy;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Replaces WordPress CORS on `formstead/v1`.
 *
 * Core reflects any origin and sends `Access-Control-Allow-Credentials`.
 * Formstead never sends that header. A preflight is `204`. An unknown form
 * gets the same status without grant headers.
 */
final class Cors {

	/**
	 * Hook preflight handling and the header takeover.
	 */
	public static function register(): void {
		add_filter( 'rest_pre_dispatch', array( self::class, 'preflight' ), 9, 3 );
		add_filter( 'rest_request_after_callbacks', array( self::class, 'after_callbacks' ), 20, 3 );
		add_filter( 'rest_post_dispatch', array( self::class, 'decorate' ), 10, 3 );
		add_filter( 'rest_pre_serve_request', array( self::class, 'take_over' ), 11, 4 );
	}

	/**
	 * Add a CORS grant after the route callback, including a replaced JSON error.
	 *
	 * `rest_do_request()` returns this response directly. `serve_request()`
	 * sends the same headers before the core CORS filter, which `take_over()` removes.
	 *
	 * @param mixed $response Dispatch result.
	 * @param mixed $handler  Route handler. Unused.
	 * @param mixed $request  Incoming request.
	 */
	public static function after_callbacks( mixed $response, mixed $handler, mixed $request ): mixed {
		unset( $handler );

		return self::decorate( $response, null, $request );
	}

	/**
	 * Answer `OPTIONS` before WordPress builds its own preflight response.
	 *
	 * @param mixed $result  Current dispatch result.
	 * @param mixed $server  REST server. Unused.
	 * @param mixed $request Incoming request.
	 */
	public static function preflight( mixed $result, mixed $server, mixed $request ): mixed {
		unset( $server );

		if ( null !== $result || ! $request instanceof WP_REST_Request ) {
			return $result;
		}

		if ( 'OPTIONS' !== $request->get_method() || ! Routes::is_namespace( $request->get_route() ) ) {
			return $result;
		}

		$response = new WP_REST_Response( null, 204 );
		$response->header( 'Vary', 'Accept, Origin' );
		// The admin API is not callable from a browser on another origin.
		if ( ! Routes::is_admin_route( $request->get_route() ) ) {
			self::grant( $response, $request );
		}

		return $response;
	}

	/**
	 * Copy a CORS grant onto the response object so tests and `serve_request` see it.
	 *
	 * @param mixed $result  Dispatch result.
	 * @param mixed $server  REST server. Unused.
	 * @param mixed $request Incoming request.
	 */
	public static function decorate( mixed $result, mixed $server, mixed $request ): mixed {
		unset( $server );

		if ( ! $result instanceof WP_REST_Response || ! $request instanceof WP_REST_Request ) {
			return $result;
		}

		if ( ! Routes::is_namespace( $request->get_route() ) || Routes::is_admin_route( $request->get_route() ) ) {
			return $result;
		}

		self::grant( $result, $request );
		return $result;
	}

	/**
	 * Remove core CORS headers and send the Formstead grant, if there is one.
	 *
	 * Priority 11 runs after `rest_send_cors_headers`. The core callback stays
	 * registered: removing it would disable CORS for every later request in
	 * this process, including `wp/v2`. Core has also sent `Allow-Headers` and
	 * `Expose-Headers` before dispatch.
	 *
	 * @param mixed $served  Whether an earlier filter already sent the body.
	 * @param mixed $result  Dispatch result. Unused.
	 * @param mixed $request Incoming request.
	 * @param mixed $server  REST server used to send headers.
	 */
	public static function take_over( mixed $served, mixed $result, mixed $request, mixed $server ): mixed {
		unset( $result );

		if ( ! $request instanceof WP_REST_Request || ! Routes::is_namespace( $request->get_route() ) ) {
			return $served;
		}

		if ( ! headers_sent() ) {
			header_remove( 'Access-Control-Allow-Origin' );
			header_remove( 'Access-Control-Allow-Methods' );
			header_remove( 'Access-Control-Allow-Credentials' );
			header_remove( 'Access-Control-Allow-Headers' );
			header_remove( 'Access-Control-Expose-Headers' );
			header_remove( 'Access-Control-Max-Age' );
		}

		if ( Routes::is_admin_route( $request->get_route() ) ) {
			return $served;
		}

		$origin = self::origin( $request );
		if ( null !== $origin && $server instanceof WP_REST_Server && ! headers_sent() ) {
			$server->send_header( 'Access-Control-Allow-Origin', $origin );
			$server->send_header( 'Access-Control-Allow-Methods', 'POST, OPTIONS' );
			$server->send_header( 'Access-Control-Allow-Headers', 'Content-Type, Idempotency-Key' );
			$server->send_header( 'Access-Control-Expose-Headers', 'Retry-After, Idempotent-Replayed' );
			$server->send_header( 'Access-Control-Max-Age', '600' );
		}

		return $served;
	}

	/**
	 * Add a CORS grant to a response produced before the route callback.
	 *
	 * @param WP_REST_Response $response Response to update.
	 * @param WP_REST_Request  $request  Incoming request.
	 */
	public static function apply( WP_REST_Response $response, WP_REST_Request $request ): WP_REST_Response {
		if ( Routes::is_namespace( $request->get_route() ) && ! Routes::is_admin_route( $request->get_route() ) ) {
			self::grant( $response, $request );
		}

		return $response;
	}

	/**
	 * Add grant headers when the request origin is allowed for the form.
	 *
	 * @param WP_REST_Response $response Response to update.
	 * @param WP_REST_Request  $request  Incoming request.
	 */
	private static function grant( WP_REST_Response $response, WP_REST_Request $request ): void {
		$origin = self::origin( $request );
		if ( null === $origin ) {
			return;
		}

		$response->header( 'Access-Control-Allow-Origin', $origin );
		$response->header( 'Access-Control-Allow-Methods', 'POST, OPTIONS' );
		$response->header( 'Access-Control-Allow-Headers', 'Content-Type, Idempotency-Key' );
		$response->header( 'Access-Control-Expose-Headers', 'Retry-After, Idempotent-Replayed' );
		$response->header( 'Access-Control-Max-Age', '600' );
	}

	/**
	 * Allowed origin for this request, or null.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 */
	private static function origin( WP_REST_Request $request ): ?string {
		$form = OriginPolicy::form_from_request( $request );
		if ( null === $form ) {
			return null;
		}

		return OriginPolicy::reflected( $form, $request );
	}
}
