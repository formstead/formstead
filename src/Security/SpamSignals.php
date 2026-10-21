<?php
/**
 * Spam signals that still accept the submission.
 *
 * @package Formstead
 */

namespace Formstead\Security;

use Formstead\Rest\JsonNumber;

/**
 * Decides whether a validated submission should be stored as spam.
 *
 * Neither signal rejects the request. A form without JavaScript omits
 * `_fs_elapsed` and is judged only by the content duplicate, when one exists.
 */
final class SpamSignals {

	/**
	 * Reserved field carrying milliseconds since the form was shown.
	 */
	public const FIELD = '_fs_elapsed';

	/**
	 * Whether the client reported a fill time below the threshold.
	 *
	 * A missing or non-integer value is not a signal.
	 *
	 * @param array<string, mixed> $params Parsed body.
	 */
	public function too_fast( array $params ): bool {
		$minimum = Limits::min_fill_ms();
		if ( $minimum < 1 || ! array_key_exists( self::FIELD, $params ) ) {
			return false;
		}

		$text = self::elapsed_text( $params[ self::FIELD ] );
		if ( null === $text ) {
			return false;
		}

		return (int) $text < $minimum;
	}

	/**
	 * Integer text of an elapsed value, or null when it is not a signal.
	 *
	 * @param mixed $value Raw field value.
	 */
	private static function elapsed_text( mixed $value ): ?string {
		if ( $value instanceof JsonNumber ) {
			$value = $value->token();
		}

		if ( is_int( $value ) ) {
			$value = (string) $value;
		}

		if ( ! is_string( $value ) || 1 !== preg_match( '/\A\d{1,12}\z/', $value ) ) {
			return null;
		}

		return $value;
	}
}
