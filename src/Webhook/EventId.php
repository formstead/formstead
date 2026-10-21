<?php
/**
 * Public webhook event identifiers.
 *
 * @package Formstead
 */

namespace Formstead\Webhook;

/**
 * Generates webhook ids.
 *
 * An event id is `fse_` plus 16 lowercase base32 characters. One submission
 * has one id, and every delivery attempt sends that same id.
 */
final class EventId {

	private const ALPHABET = 'abcdefghijklmnopqrstuvwxyz234567';

	/**
	 * Generate a new event id.
	 */
	public static function generate(): string {
		return 'fse_' . self::encode( random_bytes( 10 ) );
	}

	/**
	 * Whether the value is an event id.
	 *
	 * @param string $value Candidate id.
	 */
	public static function is_valid( string $value ): bool {
		return 1 === preg_match( '/^fse_[a-z2-7]{16}$/', $value );
	}

	/**
	 * Encode 10 bytes as 16 base32 characters.
	 *
	 * @param string $bytes Raw bytes from `random_bytes( 10 )`.
	 */
	private static function encode( string $bytes ): string {
		$bits = '';
		foreach ( str_split( $bytes ) as $byte ) {
			$bits .= str_pad( decbin( ord( $byte ) ), 8, '0', STR_PAD_LEFT );
		}

		$encoded = '';
		for ( $index = 0; $index < 16; $index++ ) {
			$encoded .= self::ALPHABET[ (int) bindec( substr( $bits, $index * 5, 5 ) ) ];
		}

		return $encoded;
	}
}
