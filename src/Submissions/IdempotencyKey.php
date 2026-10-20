<?php
/**
 * Client-supplied idempotency key.
 *
 * @package Formstead
 */

namespace Formstead\Submissions;

use Formstead\Rest\RequestError;
use WP_REST_Request;

/**
 * Reads an idempotency key and hashes it for storage.
 *
 * The raw key is never written to the database. A header wins over the
 * reserved form field when both are present.
 */
final class IdempotencyKey {

	/**
	 * Reserved form field for browsers that cannot set a header.
	 */
	public const FIELD = '_fs_idempotency_key';

	/**
	 * How long a stored key is reused, in seconds.
	 */
	public const WINDOW = 86400;

	/**
	 * Printable ASCII, excluding space, from 1 to 255 characters.
	 */
	private const PATTERN = '/^[\x21-\x7E]{1,255}$/';

	/**
	 * Raw key. Kept only in memory for hashing.
	 *
	 * @var string
	 */
	private string $value;

	/**
	 * Create a key that has already passed the format check.
	 *
	 * @param string $value Raw key.
	 */
	private function __construct( string $value ) {
		$this->value = $value;
	}

	/**
	 * Read a key from the header or the reserved field.
	 *
	 * Returns null when the client did not send a key. An empty field is
	 * treated as absent so a blank HTML input does not fail the request.
	 *
	 * @param WP_REST_Request      $request Incoming request.
	 * @param array<string, mixed> $params  Parsed body.
	 */
	public static function read( WP_REST_Request $request, array $params ): self|RequestError|null {
		$header = $request->get_header( 'idempotency_key' );
		if ( is_string( $header ) && '' !== $header ) {
			return self::parse( $header );
		}

		if ( ! array_key_exists( self::FIELD, $params ) ) {
			return null;
		}

		$field = $params[ self::FIELD ];
		if ( ! is_string( $field ) ) {
			return RequestError::InvalidIdempotencyKey;
		}
		if ( '' === $field ) {
			return null;
		}

		return self::parse( $field );
	}

	/**
	 * SHA-256 of the canonical JSON for these normalized values.
	 *
	 * Field order is the order the validator produced, which follows the schema.
	 *
	 * @param array<string, string|bool|list<string>> $values Normalized values.
	 */
	public static function body_hash( array $values ): ?string {
		$json = wp_json_encode( $values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $json ) ) {
			return null;
		}

		return hash( 'sha256', $json );
	}

	/**
	 * SHA-256 of the raw key.
	 */
	public function hash(): string {
		return hash( 'sha256', $this->value );
	}

	/**
	 * Accept a key or return the contract error.
	 *
	 * @param string $value Raw key.
	 */
	private static function parse( string $value ): self|RequestError {
		if ( 1 !== preg_match( self::PATTERN, $value ) ) {
			return RequestError::InvalidIdempotencyKey;
		}

		return new self( $value );
	}
}
