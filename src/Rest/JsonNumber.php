<?php
/**
 * A JSON number token.
 *
 * @package Formstead
 */

namespace Formstead\Rest;

/**
 * The original text of one JSON number.
 *
 * `json_decode()` turns numbers into ints and floats. Field validation uses
 * this token instead, so `0.10` stays exact and a number is not a string.
 */
final class JsonNumber {

	/**
	 * Original number token, such as `0.10` or `1e2`.
	 *
	 * @var string
	 */
	private string $token;

	/**
	 * Create a number token.
	 *
	 * @param string $token Original JSON number text.
	 */
	public function __construct( string $token ) {
		$this->token = $token;
	}

	/**
	 * Original JSON number text.
	 */
	public function token(): string {
		return $this->token;
	}
}
