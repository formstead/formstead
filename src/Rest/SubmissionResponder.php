<?php
/**
 * HTML and JSON responses for a public submission.
 *
 * @package Formstead
 */

namespace Formstead\Rest;

use Formstead\Forms\Field;
use Formstead\Forms\Form;
use Formstead\Submissions\FieldError;
use Formstead\Submissions\SubmissionValidation;
use WP_Error;
use WP_REST_Response;

/**
 * Builds the contract response for either response mode.
 */
final class SubmissionResponder {

	/**
	 * A stored submission.
	 *
	 * @param ResponseMode $mode       Response mode.
	 * @param string       $receipt_id Public receipt id.
	 * @param string       $location   Configured success URL, used by the HTML mode.
	 */
	public static function accepted( ResponseMode $mode, string $receipt_id, string $location ): WP_REST_Response {
		if ( $mode->is_json() ) {
			return self::json(
				array( 'receipt_id' => $receipt_id ),
				201
			);
		}

		$response = new WP_REST_Response( null, 303 );
		$response->header( 'Location', $location );
		self::mark( $response );
		return $response;
	}

	/**
	 * A request that failed before or during validation.
	 *
	 * @param ResponseMode                $mode   Response mode.
	 * @param RequestError                $error  Request error.
	 * @param array<string, list<string>> $fields Field error codes for a 422 response.
	 */
	public static function failure( ResponseMode $mode, RequestError $error, array $fields = array() ): WP_REST_Response {
		if ( $mode->is_json() ) {
			$body = array(
				'error' => array(
					'code'    => $error->value,
					'message' => RequestPayload::message( $error ),
				),
			);
			if ( RequestError::InvalidFields === $error ) {
				$body['error']['fields'] = $fields;
			}

			return self::json( $body, $error->status() );
		}

		if ( RequestError::InvalidFields === $error ) {
			return self::page(
				$error->status(),
				__( 'Check the form', 'formstead' ),
				__( 'Check the form and try again', 'formstead' ),
				'',
				self::html_fields( $fields )
			);
		}

		$message = RequestPayload::message( $error );
		$heading = match ( $error ) {
			RequestError::FormNotFound => __( 'Form not found', 'formstead' ),
			RequestError::StorageFailed => __( 'Something went wrong', 'formstead' ),
			default => $message,
		};

		return self::page( $error->status(), $heading, $heading, $message, array() );
	}

	/**
	 * Field errors for either mode.
	 *
	 * HTML pages use the field label. JSON pages use the field name and the
	 * stable error code. Neither includes the submitted value.
	 *
	 * @param ResponseMode         $mode   Response mode.
	 * @param Form                 $form   Form that was submitted.
	 * @param SubmissionValidation $result Failed validation.
	 */
	public static function invalid( ResponseMode $mode, Form $form, SubmissionValidation $result ): WP_REST_Response {
		$fields = array();
		foreach ( $result->errors() as $name => $error ) {
			$fields[ $name ] = array( $error->value );
		}

		if ( ! $mode->is_json() ) {
			$items = array();
			foreach ( $result->errors() as $name => $error ) {
				$field   = $form->field( $name );
				$items[] = array(
					'label'   => $field instanceof Field ? $field->label() : $name,
					'message' => self::field_message( $error ),
				);
			}

			return self::page(
				422,
				__( 'Check the form', 'formstead' ),
				__( 'Check the form and try again', 'formstead' ),
				'',
				$items
			);
		}

		return self::failure( $mode, RequestError::InvalidFields, $fields );
	}

	/**
	 * Replace a WordPress JSON parse error when it belongs to this route.
	 *
	 * @param mixed $response Current dispatch result.
	 * @param mixed $request  Incoming request. The type is checked because filters pass mixed values.
	 */
	public static function invalid_json( mixed $response, mixed $request ): mixed {
		if ( ! $response instanceof WP_Error || 'rest_invalid_json' !== $response->get_error_code() ) {
			return $response;
		}
		if ( ! $request instanceof \WP_REST_Request || ! self::is_submission_route( $request->get_route() ) ) {
			return $response;
		}

		// WordPress parses every +json body before the route callback. Only
		// application/json is a Formstead body, so another type is 415.
		$error = 'application/json' === RequestPayload::media_type( $request )
			? RequestError::MalformedBody
			: RequestError::UnsupportedMediaType;

		return self::failure( ResponseMode::from_request( $request ), $error );
	}

	/**
	 * Whether the route is the public submissions endpoint.
	 *
	 * @param string $route Matched route.
	 */
	private static function is_submission_route( string $route ): bool {
		return 1 === preg_match( '#^/formstead/v1/forms/[^/]+/submissions$#', $route );
	}

	/**
	 * Translated message for a field error.
	 *
	 * @param FieldError $error Field error.
	 */
	public static function field_message( FieldError $error ): string {
		return match ( $error ) {
			FieldError::Required => __( 'This field is required.', 'formstead' ),
			FieldError::TooLong => __( 'This value is too long.', 'formstead' ),
			FieldError::InvalidEmail => __( 'Enter a valid email address.', 'formstead' ),
			FieldError::InvalidNumber => __( 'Enter a valid number.', 'formstead' ),
			FieldError::TooSmall => __( 'This value is too small.', 'formstead' ),
			FieldError::TooLarge => __( 'This value is too large.', 'formstead' ),
			FieldError::TooPrecise => __( 'This value has too many decimal places.', 'formstead' ),
			FieldError::InvalidChoice => __( 'Choose a valid option.', 'formstead' ),
			FieldError::Invalid => __( 'This value is not valid.', 'formstead' ),
		};
	}

	/**
	 * JSON response with the contract headers.
	 *
	 * @param array<string, mixed> $data   Response body.
	 * @param int                  $status HTTP status.
	 */
	private static function json( array $data, int $status ): WP_REST_Response {
		$response = new WP_REST_Response( $data, $status );
		self::mark( $response );
		return $response;
	}

	/**
	 * Headers shared by every Formstead response.
	 *
	 * @param WP_REST_Response $response Response to mark.
	 */
	private static function mark( WP_REST_Response $response ): void {
		$response->header( 'Vary', 'Accept' );
		$response->header( 'Cache-Control', 'no-store' );
	}

	/**
	 * HTML field rows from coded errors when the form is not available.
	 *
	 * @param array<string, list<string>> $fields Field error codes.
	 * @return array<int, array{label: string, message: string}>
	 */
	private static function html_fields( array $fields ): array {
		$items = array();
		foreach ( $fields as $name => $codes ) {
			$code    = $codes[0] ?? FieldError::Invalid->value;
			$error   = FieldError::tryFrom( $code );
			$items[] = array(
				'label'   => $name,
				'message' => self::field_message( $error instanceof FieldError ? $error : FieldError::Invalid ),
			);
		}

		return $items;
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
