<?php
/**
 * Serves HTML REST responses as documents instead of JSON.
 *
 * @package Formstead
 */

namespace Formstead\Rest;

use WP_REST_Server;

/**
 * Sends an {@see HtmlResponse} from `rest_pre_serve_request`.
 *
 * Priority 12 runs after WordPress core's CORS headers on priority 10.
 */
final class HtmlResponder {

	/**
	 * Hook the responder.
	 */
	public static function register(): void {
		add_filter( 'rest_pre_serve_request', array( self::class, 'serve' ), 12, 4 );
	}

	/**
	 * Print the HTML document and stop JSON encoding.
	 *
	 * @param mixed $served  Whether an earlier filter already sent the body.
	 * @param mixed $result  Dispatch result.
	 * @param mixed $request Request. Unused.
	 * @param mixed $server  REST server used to send headers.
	 */
	public static function serve( mixed $served, mixed $result, mixed $request, mixed $server ): bool {
		unset( $request );

		if ( $served || ! $result instanceof HtmlResponse ) {
			return (bool) $served;
		}

		if ( $server instanceof WP_REST_Server && ! headers_sent() ) {
			$server->send_header( 'Content-Type', 'text/html; charset=utf-8' );
			$server->send_header( 'Cache-Control', 'no-store' );
			$server->send_header( 'Vary', 'Accept, Origin' );
		}

		// The template escaped the document before it was stored on the response.
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $result->html();

		return true;
	}
}
