<?php
/**
 * Stable field error codes.
 *
 * @package Formstead
 */

namespace Formstead\Submissions;

/**
 * Error codes stored in a validation result and returned by the JSON contract.
 */
enum FieldError: string {

	case Required      = 'required';
	case TooLong       = 'too_long';
	case InvalidEmail  = 'invalid_email';
	case InvalidNumber = 'invalid_number';
	case TooSmall      = 'too_small';
	case TooLarge      = 'too_large';
	case TooPrecise    = 'too_precise';
	case InvalidChoice = 'invalid_choice';
	case Invalid       = 'invalid';
}
