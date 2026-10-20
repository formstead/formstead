<?php
/**
 * HTML or JSON response selection.
 *
 * @package Formstead
 */

namespace Formstead\Rest;

use WP_REST_Request;

/**
 * Chooses the response mode from the Accept header.
 *
 * JSON is used only when `application/json` is listed with `q > 0` and
 * `text/html` does not have a higher `q`. Equal quality prefers JSON.
 * `* / *` alone stays HTML.
 */
final class ResponseMode {

	/**
	 * Whether the response is JSON.
	 *
	 * @var bool
	 */
	private bool $json;

	/**
	 * Create a mode.
	 *
	 * @param bool $json Whether the response is JSON.
	 */
	private function __construct( bool $json ) {
		$this->json = $json;
	}

	/**
	 * Mode for a request.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 */
	public static function from_request( WP_REST_Request $request ): self {
		$header = $request->get_header( 'accept' );
		return self::from_header( is_string( $header ) ? $header : null );
	}

	/**
	 * Mode for an Accept header.
	 *
	 * @param string|null $header Raw Accept header.
	 */
	public static function from_header( ?string $header ): self {
		if ( ! is_string( $header ) || '' === trim( $header ) ) {
			return new self( false );
		}

		$json_quality = null;
		$html_quality = 0.0;

		foreach ( explode( ',', $header ) as $part ) {
			$bits    = explode( ';', $part );
			$mime    = strtolower( trim( (string) array_shift( $bits ) ) );
			$quality = 1.0;
			foreach ( $bits as $parameter ) {
				if ( 1 === preg_match( '/^\s*q\s*=\s*([0-9.]+)/', $parameter, $matches ) ) {
					$quality = (float) $matches[1];
				}
			}

			if ( 'application/json' === $mime ) {
				$json_quality = null === $json_quality ? $quality : max( $json_quality, $quality );
			}
			if ( 'text/html' === $mime ) {
				$html_quality = max( $html_quality, $quality );
			}
		}

		return new self( null !== $json_quality && $json_quality > 0 && $html_quality <= $json_quality );
	}

	/**
	 * Whether the response is JSON.
	 */
	public function is_json(): bool {
		return $this->json;
	}
}
