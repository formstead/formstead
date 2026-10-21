<?php
/**
 * Public route matching.
 *
 * @package Formstead
 */

namespace Formstead\Rest;

/**
 * Identifies Formstead REST routes without treating `formstead/v10` as `v1`.
 */
final class Routes {

	/**
	 * Whether the route is inside the `formstead/v1` namespace.
	 *
	 * @param string $route Request route.
	 */
	public static function is_namespace( string $route ): bool {
		return '/formstead/v1' === $route || str_starts_with( $route, '/formstead/v1/' );
	}

	/**
	 * Whether the route is the public submissions endpoint.
	 *
	 * @param string $route Request route.
	 */
	public static function is_submission( string $route ): bool {
		return 1 === preg_match( '#^/formstead/v1/forms/[^/]+/submissions$#', $route );
	}

	/**
	 * Public form id from a submissions route, or an empty string.
	 *
	 * @param string $route Request route.
	 */
	public static function public_form_id( string $route ): string {
		if ( 1 !== preg_match( '#^/formstead/v1/forms/([^/]+)/submissions$#', $route, $matches ) ) {
			return '';
		}

		return rawurldecode( $matches[1] );
	}

	/**
	 * Whether the route is the authenticated submissions API.
	 *
	 * The public create route lives under `/forms/{id}/submissions` and is not
	 * an admin route.
	 *
	 * @param string $route Request route.
	 */
	public static function is_admin_route( string $route ): bool {
		return 1 === preg_match( '#^/formstead/v1/submissions(?:/[^/]+)?$#', $route );
	}
}
