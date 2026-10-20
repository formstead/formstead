<?php
/**
 * Public submissions endpoint.
 *
 * @package Formstead
 */

namespace Formstead\Rest;

use Formstead\Forms\Field;
use Formstead\Forms\Form;
use Formstead\Forms\FormRepository;
use Formstead\Submissions\SubmissionRepository;
use Formstead\Submissions\SubmissionValidation;
use Formstead\Submissions\SubmissionValidator;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Accepts `application/x-www-form-urlencoded` submissions.
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
	}

	/**
	 * Register the public create route.
	 *
	 * The pattern accepts any single segment so a bad id reaches this callback
	 * and gets an HTML 404, instead of WordPress's JSON `rest_no_route`.
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
	 * Store a submission or return an HTML error page.
	 *
	 * Success redirects to the form's configured URL. Nothing in the request
	 * can choose that address.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 */
	public static function create( WP_REST_Request $request ): WP_REST_Response {
		$public_id = $request->get_param( 'public_form_id' );
		$form      = ( new FormRepository() )->find_by_public_id( is_string( $public_id ) ? $public_id : '' );

		if ( ! $form instanceof Form || ! $form->is_enabled() ) {
			return self::page(
				404,
				__( 'Form not found', 'formstead' ),
				__( 'Form not found', 'formstead' ),
				__( 'This form is not available.', 'formstead' ),
				array()
			);
		}

		$params = $request->get_body_params();
		$result = ( new SubmissionValidator() )->validate( $form, $params );

		if ( ! $result->is_valid() ) {
			return self::page(
				422,
				__( 'Check the form', 'formstead' ),
				__( 'Check the form and try again', 'formstead' ),
				'',
				self::field_errors( $form, $result )
			);
		}

		$saved = ( new SubmissionRepository() )->insert( $form, $result->values() );
		if ( $saved instanceof WP_Error ) {
			return self::page(
				500,
				__( 'Something went wrong', 'formstead' ),
				__( 'Something went wrong', 'formstead' ),
				__( 'The submission could not be saved. Please try again.', 'formstead' ),
				array()
			);
		}

		$response = new WP_REST_Response( null, 303 );
		$response->header( 'Location', $form->success_url() );
		return $response;
	}

	/**
	 * Labels and messages for the error page. Values from the request are omitted.
	 *
	 * @param Form                 $form   Form that was submitted.
	 * @param SubmissionValidation $result Failed validation.
	 * @return array<int, array{label: string, message: string}>
	 */
	private static function field_errors( Form $form, SubmissionValidation $result ): array {
		$items = array();

		foreach ( $result->errors() as $name => $code ) {
			$field   = $form->field( $name );
			$items[] = array(
				'label'   => $field instanceof Field ? $field->label() : $name,
				'message' => self::message( $code ),
			);
		}

		return $items;
	}

	/**
	 * Message for a field error code.
	 *
	 * @param string $code Error code from the validator.
	 */
	private static function message( string $code ): string {
		return match ( $code ) {
			SubmissionValidator::REQUIRED => __( 'This field is required.', 'formstead' ),
			SubmissionValidator::TOO_LONG => __( 'This value is too long.', 'formstead' ),
			SubmissionValidator::INVALID_EMAIL => __( 'Enter a valid email address.', 'formstead' ),
			default => __( 'This value is not valid.', 'formstead' ),
		};
	}

	// The included template reads these arguments from this scope.
	// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
	/**
	 * Render an HTML status page.
	 *
	 * @param int                                               $status  HTTP status.
	 * @param string                                            $title   Document title.
	 * @param string                                            $heading Visible heading.
	 * @param string                                            $message Paragraph for pages without field errors.
	 * @param array<int, array{label: string, message: string}> $errors  Field errors.
	 */
	private static function page( int $status, string $title, string $heading, string $message, array $errors ): HtmlResponse {
		$language = str_replace( '_', '-', determine_locale() );

		ob_start();
		include dirname( FORMSTEAD_FILE ) . '/templates/public/message.php';
		$html = ob_get_clean();

		return new HtmlResponse( is_string( $html ) ? $html : '', $status );
	}
	// phpcs:enable Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
}
