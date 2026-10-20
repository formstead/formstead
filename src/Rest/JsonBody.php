<?php
/**
 * JSON object parsing that keeps decimal tokens.
 *
 * @package Formstead
 */

namespace Formstead\Rest;

/**
 * Decodes a JSON object and preserves number tokens.
 *
 * `json_decode()` turns decimals into floats. Each JSON number becomes a
 * {@see JsonNumber} carrying the original token, so `0.10` stays exact and
 * is not confused with the string `"0.10"`. An empty object is `array()`.
 */
final class JsonBody {

	/**
	 * Prefix that marks a number token inside the decoded marker document.
	 */
	private const NUMBER_MARK = "\0";

	/**
	 * Decode a JSON object.
	 *
	 * Returns null when the body is not a JSON object. The caller maps that
	 * to `malformed_body`. `{}` and `{ }` are objects. `[]` is not.
	 *
	 * @param string $raw   Raw request body.
	 * @param int    $depth Maximum nesting depth. Deeper documents are too large.
	 * @return array<string, mixed>|RequestError|null `PayloadTooLarge` when the document is too deep. Null when it is not an object.
	 */
	public static function object( string $raw, int $depth = 8 ): array|RequestError|null {
		if ( '' === $raw || ! mb_check_encoding( $raw, 'UTF-8' ) ) {
			return null;
		}

		if ( '{' !== self::first_structural( $raw ) ) {
			return null;
		}

		if ( $depth < 1 ) {
			return null;
		}

		$decoded = json_decode( $raw, true, $depth );
		if ( JSON_ERROR_DEPTH === json_last_error() ) {
			return RequestError::PayloadTooLarge;
		}
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
			return null;
		}

		$marked = json_decode( self::mark_numbers( $raw ), true, $depth );
		if ( JSON_ERROR_DEPTH === json_last_error() ) {
			return RequestError::PayloadTooLarge;
		}
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $marked ) ) {
			return $decoded;
		}

		$lifted = self::lift( $decoded, $marked );

		return is_array( $lifted ) ? $lifted : $decoded;
	}

	/**
	 * First character that is not JSON whitespace.
	 *
	 * @param string $raw Raw JSON.
	 */
	private static function first_structural( string $raw ): string {
		$length = strlen( $raw );
		for ( $index = 0; $index < $length; $index++ ) {
			if ( ! ctype_space( $raw[ $index ] ) ) {
				return $raw[ $index ];
			}
		}

		return '';
	}

	/**
	 * Replace number tokens outside strings with marked JSON strings.
	 *
	 * Exponents are marked too. Validation rejects them for number fields
	 * instead of storing a float.
	 *
	 * @param string $json Raw JSON.
	 */
	private static function mark_numbers( string $json ): string {
		$result    = '';
		$length    = strlen( $json );
		$in_string = false;
		$escaped   = false;

		for ( $index = 0; $index < $length; $index++ ) {
			$character = $json[ $index ];

			if ( $in_string ) {
				$result .= $character;
				if ( $escaped ) {
					$escaped = false;
					continue;
				}
				if ( '\\' === $character ) {
					$escaped = true;
					continue;
				}
				if ( '"' === $character ) {
					$in_string = false;
				}
				continue;
			}

			if ( '"' === $character ) {
				$in_string = true;
				$result   .= $character;
				continue;
			}

			if ( '-' === $character || ctype_digit( $character ) ) {
				if ( 1 === preg_match( '/\G-?(?:0|[1-9]\d*)(?:\.\d+)?(?:[eE][+-]?\d+)?/', $json, $matches, 0, $index ) ) {
					$token   = $matches[0];
					$result .= '"\\u0000' . $token . '"';
					$index  += strlen( $token ) - 1;
					continue;
				}
			}

			$result .= $character;
		}

		return $result;
	}

	/**
	 * Replace marked number strings with {@see JsonNumber} values.
	 *
	 * A real string that happens to start with the mark stays a string,
	 * because the original decode is a string only for quoted JSON text.
	 *
	 * @param mixed $decoded Value from the original document.
	 * @param mixed $marked  Value from the marked document.
	 */
	private static function lift( mixed $decoded, mixed $marked ): mixed {
		if ( is_string( $marked ) && str_starts_with( $marked, self::NUMBER_MARK ) && ( is_int( $decoded ) || is_float( $decoded ) ) ) {
			return new JsonNumber( substr( $marked, 1 ) );
		}

		if ( ! is_array( $decoded ) || ! is_array( $marked ) ) {
			return $decoded;
		}

		$lifted = array();
		foreach ( $decoded as $key => $value ) {
			$lifted[ $key ] = self::lift( $value, $marked[ $key ] ?? null );
		}

		return $lifted;
	}
}
