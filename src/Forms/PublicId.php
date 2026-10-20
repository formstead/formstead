<?php
/**
 * Public form identifiers.
 *
 * @package Formstead
 */

namespace Formstead\Forms;

/**
 * Generates and checks public form ids.
 *
 * An id is `fs_` plus 16 lowercase base32 characters (80 bits). It is not a secret.
 */
final class PublicId {

	private const ALPHABET = 'abcdefghijklmnopqrstuvwxyz234567';

	/**
	 * Generate a new public id.
	 */
	public static function generate(): string {
		return 'fs_' . self::encode( random_bytes( 10 ) );
	}

	/**
	 * Whether the value is a public id.
	 *
	 * @param string $value Candidate id.
	 */
	public static function is_valid( string $value ): bool {
		return 1 === preg_match( '/^fs_[a-z2-7]{16}$/', $value );
	}

	/**
	 * Encode 10 bytes as 16 base32 characters.
	 *
	 * 80 bits split into 5-bit groups, so the result is exactly 16 characters.
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
