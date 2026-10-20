<?php
/**
 * Stable request error codes.
 *
 * @package Formstead
 */

namespace Formstead\Rest;

/**
 * Error codes returned for a submission request.
 */
enum RequestError: string {

	case FormNotFound         = 'form_not_found';
	case MalformedBody        = 'malformed_body';
	case UnsupportedMediaType = 'unsupported_media_type';
	case FilesNotSupported    = 'files_not_supported';
	case InvalidFields        = 'invalid_fields';
	case StorageFailed        = 'storage_failed';

	/**
	 * HTTP status for this error.
	 */
	public function status(): int {
		// Enum methods run in object context. This sniff does not treat enums as classes.
		// phpcs:ignore PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext
		return match ( $this ) {
			self::FormNotFound => 404,
			self::MalformedBody => 400,
			self::UnsupportedMediaType, self::FilesNotSupported => 415,
			self::InvalidFields => 422,
			self::StorageFailed => 500,
		};
	}
}
