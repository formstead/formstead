<?php
/**
 * Access checks for the authenticated submissions API.
 *
 * @package Formstead
 */

namespace Formstead\Rest;

use WP_REST_Response;

/**
 * Requires HTTPS, a logged-in user, and one capability.
 *
 * Local and development environments may use plain HTTP, matching where
 * WordPress allows application passwords. Anywhere else a request without
 * TLS is refused before the user is considered.
 */
final class AdminAccess {

	/**
	 * A response when the request may not continue, or null when it may.
	 *
	 * Tests pass TLS and the environment because WordPress 7 reads
	 * `WP_ENVIRONMENT_TYPE` from a constant and does not filter it.
	 *
	 * @param string      $capability  Capability required for this operation.
	 * @param bool|null   $ssl         TLS state. Null reads `is_ssl()`.
	 * @param string|null $environment Environment name. Null reads `wp_get_environment_type()`.
	 */
	public static function denied( string $capability, ?bool $ssl = null, ?string $environment = null ): ?WP_REST_Response {
		if ( ! self::https_ok( $ssl, $environment ) ) {
			return self::error( 'https_required', 403 );
		}

		if ( ! is_user_logged_in() ) {
			return self::error( 'unauthenticated', 401 );
		}

		if ( ! current_user_can( $capability ) ) {
			return self::error( 'forbidden', 403 );
		}

		return null;
	}

	/**
	 * Contract error. The body is not a `WP_Error`.
	 *
	 * @param string $code    Stable error code.
	 * @param int    $status  HTTP status.
	 * @param string $message Translated message. Empty uses the message for the code.
	 */
	public static function error( string $code, int $status, string $message = '' ): WP_REST_Response {
		if ( '' === $message ) {
			$message = self::message( $code );
		}

		$response = new WP_REST_Response(
			array(
				'error' => array(
					'code'    => $code,
					'message' => $message,
				),
			),
			$status
		);
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/**
	 * Whether this request may skip HTTPS.
	 *
	 * Plain HTTP is limited to local and development. Production and staging
	 * need TLS. `is_ssl()` is false behind some proxies; those hosts set the
	 * environment or terminate TLS so WordPress sees HTTPS.
	 *
	 * @param bool|null   $ssl         TLS state. Null reads `is_ssl()`.
	 * @param string|null $environment Environment name. Null reads `wp_get_environment_type()`.
	 */
	private static function https_ok( ?bool $ssl = null, ?string $environment = null ): bool {
		if ( $ssl ?? is_ssl() ) {
			return true;
		}

		$environment = $environment ?? wp_get_environment_type();

		return 'local' === $environment || 'development' === $environment;
	}

	/**
	 * Translated message for a stable code.
	 *
	 * @param string $code Error code.
	 */
	private static function message( string $code ): string {
		return match ( $code ) {
			'https_required' => __( 'The admin API requires HTTPS.', 'formstead' ),
			'unauthenticated' => __( 'Authentication is required.', 'formstead' ),
			'forbidden' => __( 'You are not allowed to do that.', 'formstead' ),
			'submission_not_found' => __( 'That submission was not found.', 'formstead' ),
			'form_not_found' => __( 'That form was not found.', 'formstead' ),
			'invalid_status' => __( 'Choose a valid status.', 'formstead' ),
			'invalid_date' => __( 'Enter a valid date.', 'formstead' ),
			default => __( 'The submission could not be saved.', 'formstead' ),
		};
	}
}
