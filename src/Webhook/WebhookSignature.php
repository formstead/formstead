<?php
/**
 * Standard Webhooks signatures.
 *
 * @package Formstead
 */

namespace Formstead\Webhook;

/**
 * Signs one webhook attempt.
 *
 * The signed content is `{id}.{timestamp}.{body}`. The key is the secret
 * after the `whsec_` prefix, decoded from base64. The header value is
 * `v1,` plus the base64 HMAC-SHA256.
 */
final class WebhookSignature {

	/**
	 * Header value, or null when the secret cannot be decoded.
	 *
	 * @param string $id        Stable webhook id.
	 * @param int    $timestamp Unix time of this attempt.
	 * @param string $body      Exact request body.
	 * @param string $secret    `whsec_` secret stored on the form.
	 */
	public static function header( string $id, int $timestamp, string $body, string $secret ): ?string {
		$key = self::key( $secret );
		if ( ! is_string( $key ) ) {
			return null;
		}

		$signed = $id . '.' . $timestamp . '.' . $body;
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Standard Webhooks requires the HMAC as base64.
		return 'v1,' . base64_encode( hash_hmac( 'sha256', $signed, $key, true ) );
	}

	/**
	 * Raw key bytes, or null when the secret is not `whsec_` plus base64.
	 *
	 * @param string $secret Stored secret.
	 */
	private static function key( string $secret ): ?string {
		if ( ! str_starts_with( $secret, 'whsec_' ) ) {
			return null;
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- the prefix is followed by the Standard Webhooks key.
		$decoded = base64_decode( substr( $secret, 6 ), true );
		if ( ! is_string( $decoded ) || '' === $decoded ) {
			return null;
		}

		return $decoded;
	}
}
