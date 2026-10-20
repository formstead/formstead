<?php
/**
 * Reads the body of a public submission.
 *
 * @package Formstead
 */

namespace Formstead\Rest;

use WP_Error;
use WP_REST_Request;

/**
 * Selects the input map from the Content-Type header.
 */
final class RequestPayload {

	/**
	 * Read a flat input map, or an error describing why the body was refused.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function read( WP_REST_Request $request ): array|WP_Error {
		$media = self::media_type( $request );

		if ( 'multipart/form-data' === $media && array() !== $request->get_file_params() ) {
			return self::error( RequestError::FilesNotSupported );
		}

		if ( 'application/x-www-form-urlencoded' === $media || 'multipart/form-data' === $media ) {
			$params = $request->get_body_params();
			return is_array( $params ) ? $params : array();
		}

		if ( 'application/json' === $media ) {
			$object = JsonBody::object( $request->get_body() );
			if ( ! is_array( $object ) ) {
				return self::error( RequestError::MalformedBody );
			}

			return $object;
		}

		return self::error( RequestError::UnsupportedMediaType );
	}

	/**
	 * Media type without parameters.
	 *
	 * `+json` subtypes stay intact. Callers compare this with `application/json`.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 */
	public static function media_type( WP_REST_Request $request ): string {
		$content_type = $request->get_content_type();
		if ( ! is_array( $content_type ) ) {
			return '';
		}

		$value = $content_type['value'] ?? '';
		return is_string( $value ) ? strtolower( $value ) : '';
	}

	/**
	 * Request error with its HTTP status.
	 *
	 * @param RequestError $error Error code.
	 */
	private static function error( RequestError $error ): WP_Error {
		return new WP_Error(
			$error->value,
			self::message( $error ),
			array( 'status' => $error->status() )
		);
	}

	/**
	 * Translated message for a request error.
	 *
	 * @param RequestError $error Error code.
	 */
	public static function message( RequestError $error ): string {
		return match ( $error ) {
			RequestError::FormNotFound => __( 'This form is not available.', 'formstead' ),
			RequestError::MalformedBody => __( 'The request body could not be read.', 'formstead' ),
			RequestError::UnsupportedMediaType => __( 'This form does not accept that content type.', 'formstead' ),
			RequestError::FilesNotSupported => __( 'File uploads are not supported.', 'formstead' ),
			RequestError::InvalidFields => __( 'Check the form and try again.', 'formstead' ),
			RequestError::StorageFailed => __( 'The submission could not be saved. Please try again.', 'formstead' ),
		};
	}
}
