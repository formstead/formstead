<?php
/**
 * Counts raw submission fields.
 *
 * @package Formstead
 */

namespace Formstead\Rest;

/**
 * Counts the fields in a parsed body before validation.
 *
 * A scalar counts as one. Each element of an array counts as one, including
 * nested elements. An empty array counts as one. Unknown names and reserved
 * `_fs_` names count, because the limit applies to the raw input.
 */
final class FieldCounter {

	/**
	 * Number of raw fields.
	 *
	 * @param array<mixed> $params Parsed body.
	 */
	public static function count( array $params ): int {
		$count = 0;
		foreach ( $params as $value ) {
			$count += self::count_value( $value );
		}

		return $count;
	}

	/**
	 * Field count of one value.
	 *
	 * @param mixed $value Field value.
	 */
	private static function count_value( mixed $value ): int {
		if ( ! is_array( $value ) ) {
			return 1;
		}

		if ( array() === $value ) {
			return 1;
		}

		$count = 0;
		foreach ( $value as $item ) {
			$count += self::count_value( $item );
		}

		return $count;
	}
}
