<?php
/**
 * One CSV cell.
 *
 * @package Formstead
 */

namespace Formstead\Submissions;

/**
 * Formats a stored value and keeps it from being read as a spreadsheet formula.
 */
final class CsvRow {

	/**
	 * Characters that a spreadsheet treats as the start of a formula.
	 */
	private const FORMULA = '/\A[=+\-@\t\r]/';

	/**
	 * Text for one cell, with a quote prefix when the value would be a formula.
	 *
	 * @param string $value Cell text.
	 */
	public static function guard( string $value ): string {
		if ( '' !== $value && 1 === preg_match( self::FORMULA, $value ) ) {
			return "'" . $value;
		}

		return $value;
	}

	/**
	 * Cell text for a stored field value.
	 *
	 * A boolean is `1` or `0`. A multiple choice is joined with `; `.
	 *
	 * @param string|bool|list<string>|null $value Stored value, or null when the field was absent.
	 */
	public static function field( string|bool|array|null $value ): string {
		if ( null === $value ) {
			return '';
		}

		if ( is_bool( $value ) ) {
			return $value ? '1' : '0';
		}

		if ( is_array( $value ) ) {
			$items = array();
			foreach ( $value as $item ) {
				if ( is_string( $item ) ) {
					$items[] = $item;
				}
			}

			return self::guard( implode( '; ', $items ) );
		}

		return self::guard( $value );
	}
}
