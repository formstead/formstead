<?php
/**
 * Plain decimal strings.
 *
 * @package Formstead
 */

namespace Formstead\Forms;

/**
 * Parses and compares decimal text without floating point.
 *
 * A plain decimal matches `^-?\d+(\.\d+)?$`. The canonical form has no
 * leading zeros and no trailing fractional zeros.
 */
final class Decimal {

	/**
	 * Whether the text is a plain decimal.
	 *
	 * @param string $value Candidate text.
	 */
	public static function is_plain( string $value ): bool {
		return 1 === preg_match( '/^-?\d+(?:\.\d+)?$/', $value );
	}

	/**
	 * Digits after the decimal point, before canonicalizing.
	 *
	 * Returns null when the text is not a plain decimal.
	 *
	 * @param string $value Candidate text.
	 */
	public static function fraction_digits( string $value ): ?int {
		if ( 1 !== preg_match( '/^-?\d+(?:\.(\d+))?$/', $value, $matches ) ) {
			return null;
		}

		return isset( $matches[1] ) ? strlen( $matches[1] ) : 0;
	}

	/**
	 * Canonical decimal, or null when the text is not plain.
	 *
	 * @param string $value Candidate text.
	 */
	public static function canonical( string $value ): ?string {
		if ( 1 !== preg_match( '/^(-?)(\d+)(?:\.(\d+))?$/', $value, $matches ) ) {
			return null;
		}

		$sign     = $matches[1];
		$whole    = ltrim( $matches[2], '0' );
		$fraction = isset( $matches[3] ) ? rtrim( $matches[3], '0' ) : '';
		if ( '' === $whole ) {
			$whole = '0';
		}
		if ( '-' === $sign && '0' === $whole && '' === $fraction ) {
			$sign = '';
		}
		if ( '' === $fraction ) {
			return $sign . $whole;
		}

		return $sign . $whole . '.' . $fraction;
	}

	/**
	 * Compare two canonical decimals.
	 *
	 * @param string $left  Canonical decimal.
	 * @param string $right Canonical decimal.
	 */
	public static function compare( string $left, string $right ): int {
		$left_negative  = str_starts_with( $left, '-' );
		$right_negative = str_starts_with( $right, '-' );
		if ( $left_negative !== $right_negative ) {
			return $left_negative ? -1 : 1;
		}

		$absolute = self::compare_absolute( ltrim( $left, '-' ), ltrim( $right, '-' ) );
		if ( $left_negative ) {
			return -$absolute;
		}

		return $absolute;
	}

	/**
	 * Compare two non-negative canonical decimals.
	 *
	 * @param string $left  Absolute canonical decimal.
	 * @param string $right Absolute canonical decimal.
	 */
	private static function compare_absolute( string $left, string $right ): int {
		$left_parts  = explode( '.', $left, 2 );
		$right_parts = explode( '.', $right, 2 );
		$left_whole  = $left_parts[0];
		$right_whole = $right_parts[0];

		if ( strlen( $left_whole ) !== strlen( $right_whole ) ) {
			return strlen( $left_whole ) <=> strlen( $right_whole );
		}

		$whole = strcmp( $left_whole, $right_whole );
		if ( 0 !== $whole ) {
			return $whole;
		}

		$left_fraction  = $left_parts[1] ?? '';
		$right_fraction = $right_parts[1] ?? '';
		$scale          = max( strlen( $left_fraction ), strlen( $right_fraction ) );

		return strcmp( str_pad( $left_fraction, $scale, '0' ), str_pad( $right_fraction, $scale, '0' ) );
	}
}
