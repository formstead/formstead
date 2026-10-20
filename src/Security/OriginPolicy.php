<?php
/**
 * Origin allowlist for a public form.
 *
 * @package Formstead
 */

namespace Formstead\Security;

use Formstead\Forms\Form;
use Formstead\Forms\FormRepository;
use Formstead\Rest\Routes;
use WP_REST_Request;

/**
 * Decides whether a request origin may submit a form.
 *
 * A missing `Origin` header is allowed. `Origin: null` is not. An empty
 * allowlist means only this site's origin. Comparison uses the scheme, host,
 * and port, without the default port and without `esc_url_raw()`.
 */
final class OriginPolicy {

	/**
	 * Whether this request may submit the form.
	 *
	 * @param Form            $form    Form being submitted.
	 * @param WP_REST_Request $request Incoming request.
	 */
	public static function allows( Form $form, WP_REST_Request $request ): bool {
		$header = $request->get_header( 'origin' );
		if ( ! is_string( $header ) || '' === trim( $header ) ) {
			return true;
		}

		$origin = self::normalize( $header );
		if ( null === $origin ) {
			return false;
		}

		return in_array( $origin, self::allowlist( $form ), true );
	}

	/**
	 * Origin to echo in `Access-Control-Allow-Origin`, or null when no CORS grant is made.
	 *
	 * @param Form            $form    Form that owns the route, when it is enabled.
	 * @param WP_REST_Request $request Incoming request.
	 */
	public static function reflected( Form $form, WP_REST_Request $request ): ?string {
		$header = $request->get_header( 'origin' );
		if ( ! is_string( $header ) || '' === trim( $header ) || ! self::allows( $form, $request ) ) {
			return null;
		}

		return self::normalize( $header );
	}

	/**
	 * Enabled form addressed by a submissions route, or null.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 */
	public static function form_from_request( WP_REST_Request $request ): ?Form {
		$public_id = Routes::public_form_id( $request->get_route() );
		if ( '' === $public_id ) {
			return null;
		}

		$form = ( new FormRepository() )->find_by_public_id( $public_id );
		if ( ! $form instanceof Form || ! $form->is_enabled() ) {
			return null;
		}

		return $form;
	}

	/**
	 * Origins that may submit this form.
	 *
	 * @param Form $form Form being submitted.
	 * @return string[]
	 */
	public static function allowlist( Form $form ): array {
		$configured = array_values( $form->protection()->allowed_origins() );
		if ( array() !== $configured ) {
			return $configured;
		}

		return self::site_origins();
	}

	/**
	 * Origins of this WordPress site.
	 *
	 * @return list<string>
	 */
	public static function site_origins(): array {
		$origins = array();
		foreach ( array( home_url(), site_url() ) as $url ) {
			$origin = self::parse( $url, true );
			if ( is_string( $origin ) ) {
				$origins[] = $origin;
			}
		}

		return array_values( array_unique( $origins ) );
	}

	/**
	 * Normalize an origin that must not include a path.
	 *
	 * @param string $origin Candidate origin.
	 */
	public static function normalize( string $origin ): ?string {
		return self::parse( $origin, false );
	}

	/**
	 * Parse an origin or a site URL.
	 *
	 * Site URLs may include a path, which is ignored. An allowlist entry may not.
	 *
	 * @param string $value       Candidate value.
	 * @param bool   $ignore_path Whether a path is discarded instead of rejected.
	 */
	private static function parse( string $value, bool $ignore_path ): ?string {
		$value = trim( $value );
		if ( '' === $value || 'null' === strtolower( $value ) ) {
			return null;
		}

		$parts = wp_parse_url( $value );
		if ( ! is_array( $parts ) ) {
			return null;
		}

		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		$host   = strtolower( (string) ( $parts['host'] ?? '' ) );
		if ( ( 'http' !== $scheme && 'https' !== $scheme ) || '' === $host ) {
			return null;
		}

		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) ) {
			return null;
		}

		$path = (string) ( $parts['path'] ?? '' );
		if ( ! $ignore_path && '' !== $path && '/' !== $path ) {
			return null;
		}

		$port = isset( $parts['port'] ) ? (int) $parts['port'] : null;
		if ( ( 'https' === $scheme && 443 === $port ) || ( 'http' === $scheme && 80 === $port ) ) {
			$port = null;
		}

		$origin = $scheme . '://' . $host;
		if ( null !== $port ) {
			$origin .= ':' . $port;
		}

		return $origin;
	}
}
