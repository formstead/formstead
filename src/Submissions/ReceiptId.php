<?php
/**
 * Public receipt identifiers.
 *
 * @package Formstead
 */

namespace Formstead\Submissions;

/**
 * Generates and checks receipt ids.
 *
 * A receipt id is `fsr_` plus 16 lowercase base32 characters (80 bits).
 * It identifies an accepted submission without exposing the table id.
 */
final class ReceiptId {

	private const ALPHABET = 'abcdefghijklmnopqrstuvwxyz234567';

	/**
	 * Generate a new receipt id.
	 */
	public static function generate(): string {
		return 'fsr_' . self::encode( random_bytes( 10 ) );
	}

	/**
	 * Whether the value is a receipt id.
	 *
	 * @param string $value Candidate id.
	 */
	public static function is_valid( string $value ): bool {
		return 1 === preg_match( '/^fsr_[a-z2-7]{16}$/', $value );
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
