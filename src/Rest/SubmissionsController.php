<?php
/**
 * Public submissions endpoint.
 *
 * @package Formstead
 */

namespace Formstead\Rest;

use Formstead\Forms\Form;
use Formstead\Forms\FormRepository;
use Formstead\Security\Limits;
use Formstead\Security\RateLimiter;
use Formstead\Submissions\SubmissionRepository;
use Formstead\Submissions\SubmissionValidator;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Accepts URL-encoded, multipart, and JSON submissions.
 *
 * The route is public. It does not call `is_user_logged_in()` or
 * `get_current_user_id()`, because a cookie without a REST nonce is user 0
 * and an invalid nonce would reject a same-origin form.
 */
final class SubmissionsController {

	/**
	 * Hook route registration.
	 */
	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
		add_filter( 'rest_pre_dispatch', array( self::class, 'guard_size' ), 11, 3 );
		add_filter( 'rest_request_after_callbacks', array( self::class, 'replace_invalid_json' ), 10, 3 );
	}

	/**
	 * Register the public create route.
	 *
	 * The pattern accepts any single segment so a bad id reaches this callback
	 * and gets a Formstead 404, instead of WordPress's JSON `rest_no_route`.
	 */
	public static function register_routes(): void {
		register_rest_route(
			'formstead/v1',
			'/forms/(?P<public_form_id>[^/]+)/submissions',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'create' ),
				// Public on purpose. See the class comment.
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Store a submission or return the contract error.
	 *
	 * HTML success redirects to the form's configured URL. Nothing in the
	 * request can choose that address. JSON success returns the receipt id.
	 *
	 * WordPress rejects malformed JSON before this callback. The replacement
	 * lives in {@see self::replace_invalid_json()} because returning a normal
	 * response from `rest_request_before_callbacks` does not skip the callback.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 */
	public static function create( WP_REST_Request $request ): WP_REST_Response {
		$mode      = ResponseMode::from_request( $request );
		$public_id = $request->get_param( 'public_form_id' );
		$forms     = new FormRepository();
		$form      = $forms->find_by_public_id( is_string( $public_id ) ? $public_id : '' );

		if ( ! $form instanceof Form || ! $form->is_enabled() ) {
			return SubmissionResponder::failure( $mode, RequestError::FormNotFound );
		}

		$retry = ( new RateLimiter() )->consume( $form );
		if ( is_int( $retry ) ) {
			return SubmissionResponder::failure(
				$mode,
				RequestError::RateLimited,
				array(),
				array( 'Retry-After' => (string) $retry )
			);
		}

		$params = RequestPayload::read( $request );
		if ( $params instanceof WP_Error ) {
			$error = RequestError::tryFrom( $params->get_error_code() );
			return SubmissionResponder::failure( $mode, $error instanceof RequestError ? $error : RequestError::MalformedBody );
		}

		if ( FieldCounter::count( $params ) > Limits::max_fields() ) {
			return SubmissionResponder::failure( $mode, RequestError::PayloadTooLarge );
		}

		$result = ( new SubmissionValidator() )->validate( $form, $params );
		if ( ! $result->is_valid() ) {
			return SubmissionResponder::invalid( $mode, $form, $result );
		}

		$saved = ( new SubmissionRepository() )->insert( $form, $result->values() );
		if ( $saved instanceof WP_Error ) {
			return SubmissionResponder::failure( $mode, RequestError::StorageFailed );
		}

		return SubmissionResponder::accepted( $mode, $saved->receipt_id(), $form->success_url() );
	}

	/**
	 * Reject an oversized body before the route callback reads it.
	 *
	 * A request that already has a result, including `OPTIONS`, is left alone.
	 *
	 * @param mixed $result  Current dispatch result.
	 * @param mixed $server  REST server. Unused.
	 * @param mixed $request Incoming request.
	 */
	public static function guard_size( mixed $result, mixed $server, mixed $request ): mixed {
		unset( $server );

		if ( null !== $result || ! $request instanceof WP_REST_Request ) {
			return $result;
		}

		if ( 'POST' !== $request->get_method() || ! Routes::is_submission( $request->get_route() ) ) {
			return $result;
		}

		if ( ! self::body_too_large( $request ) ) {
			return $result;
		}

		return SubmissionResponder::failure( ResponseMode::from_request( $request ), RequestError::PayloadTooLarge );
	}

	/**
	 * Whether the declared or actual body is larger than the global limit.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 */
	private static function body_too_large( WP_REST_Request $request ): bool {
		$limit    = Limits::max_request_bytes();
		$declared = $request->get_header( 'content_length' );
		if ( is_string( $declared ) && is_numeric( trim( $declared ) ) && (int) trim( $declared ) > $limit ) {
			return true;
		}

		$body = $request->get_body();
		return is_string( $body ) && strlen( $body ) > $limit;
	}

	/**
	 * Turn WordPress's invalid-JSON error into the Formstead contract response.
	 *
	 * The callback has already been skipped, because the result is still a
	 * `WP_Error` when `rest_request_after_callbacks` runs.
	 *
	 * @param mixed $response Current dispatch result.
	 * @param mixed $handler  Route handler. Unused.
	 * @param mixed $request  Incoming request.
	 */
	public static function replace_invalid_json( mixed $response, mixed $handler, mixed $request ): mixed {
		unset( $handler );

		return SubmissionResponder::invalid_json( $response, $request );
	}
}
