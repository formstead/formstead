<?php
/**
 * HMAC of an identifying email address.
 *
 * @package Formstead
 */

namespace Formstead\Privacy;

use Formstead\Forms\Form;

/**
 * Hashes a normalized email with a secret that does not rotate.
 *
 * Rotating the secret would make every stored hash unusable. The secret is
 * not the rate-limit secret, and it is not autoloaded.
 */
final class IdentityHasher {

	public const OPTION = 'formstead_identity_secret';

	/**
	 * HMAC secret, created once.
	 */
	public static function secret(): string {
		$stored = get_option( self::OPTION, null );
		if ( is_string( $stored ) && 1 === preg_match( '/\A[a-f0-9]{64}\z/', $stored ) ) {
			return $stored;
		}

		$secret = bin2hex( random_bytes( 32 ) );
		if ( add_option( self::OPTION, $secret, '', false ) ) {
			return $secret;
		}

		$stored = get_option( self::OPTION, null );
		if ( is_string( $stored ) && 1 === preg_match( '/\A[a-f0-9]{64}\z/', $stored ) ) {
			return $stored;
		}

		update_option( self::OPTION, $secret, false );
		return $secret;
	}

	/**
	 * Lower case, with surrounding whitespace removed.
	 *
	 * Plus-tags and dots stay. An empty value stays empty.
	 *
	 * @param string $email Address as submitted or typed into search.
	 */
	public static function normalize( string $email ): string {
		return strtolower( trim( $email ) );
	}

	/**
	 * HMAC-SHA256 of a normalized address, or null when it is empty.
	 *
	 * @param string $email Address as submitted or typed into search.
	 */
	public static function hash( string $email ): ?string {
		$normalized = self::normalize( $email );
		if ( '' === $normalized ) {
			return null;
		}

		return hash_hmac( 'sha256', $normalized, self::secret() );
	}

	/**
	 * Hashes for the identifying email values in one submission.
	 *
	 * @param Form                                    $form   Form that received the submission.
	 * @param array<string, string|bool|list<string>> $values Normalized field values.
	 * @return array<string, string> Field name to HMAC.
	 */
	public static function hashes( Form $form, array $values ): array {
		$hashes = array();
		foreach ( $form->identity_fields() as $name ) {
			$value = $values[ $name ] ?? null;
			if ( ! is_string( $value ) ) {
				continue;
			}

			$hash = self::hash( $value );
			if ( ! is_string( $hash ) ) {
				continue;
			}

			$hashes[ $name ] = $hash;
		}

		return $hashes;
	}
}
