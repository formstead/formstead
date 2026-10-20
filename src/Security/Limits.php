<?php
/**
 * Global submission limits.
 *
 * @package Formstead
 */

namespace Formstead\Security;

use Formstead\Forms\FormProtection;

/**
 * Reads request and rate limits from constants and the `formstead_limits` filter.
 *
 * Values outside the hard ceilings are clamped. A form may override only the
 * two rate limits, and only inside those same ceilings.
 */
final class Limits {

	public const MAX_REQUEST_BYTES = 65536;

	public const CEILING_REQUEST_BYTES = 1048576;

	public const MAX_FIELDS = 50;

	public const CEILING_FIELDS = 500;

	public const RATE_PER_MINUTE = 5;

	public const CEILING_RATE_PER_MINUTE = 60;

	public const RATE_PER_HOUR = 100;

	public const CEILING_RATE_PER_HOUR = 10000;

	/**
	 * Maximum JSON nesting. Not configurable.
	 */
	public const JSON_DEPTH = 8;

	/**
	 * Maximum request body size in bytes.
	 */
	public static function max_request_bytes(): int {
		return self::configured()['max_request_bytes'];
	}

	/**
	 * Maximum number of raw input fields, including array elements.
	 */
	public static function max_fields(): int {
		return self::configured()['max_fields'];
	}

	/**
	 * Submissions allowed for one IP hash and form each minute.
	 *
	 * @param FormProtection $protection Form overrides.
	 */
	public static function rate_per_minute( FormProtection $protection ): int {
		$override = $protection->rate_per_minute();
		if ( null === $override ) {
			return self::configured()['rate_per_minute'];
		}

		return self::clamp( $override, 1, self::CEILING_RATE_PER_MINUTE, self::RATE_PER_MINUTE );
	}

	/**
	 * Submissions allowed for one form each hour.
	 *
	 * @param FormProtection $protection Form overrides.
	 */
	public static function rate_per_hour( FormProtection $protection ): int {
		$override = $protection->rate_per_hour();
		if ( null === $override ) {
			return self::configured()['rate_per_hour'];
		}

		return self::clamp( $override, 1, self::CEILING_RATE_PER_HOUR, self::RATE_PER_HOUR );
	}

	/**
	 * Effective limits after constants, the filter, and the ceilings.
	 *
	 * @return array{max_request_bytes: int, max_fields: int, rate_per_minute: int, rate_per_hour: int}
	 */
	private static function configured(): array {
		$defaults = array(
			'max_request_bytes' => self::constant_int( 'FORMSTEAD_MAX_REQUEST_BYTES', self::MAX_REQUEST_BYTES ),
			'max_fields'        => self::constant_int( 'FORMSTEAD_MAX_FIELDS', self::MAX_FIELDS ),
			'rate_per_minute'   => self::constant_int( 'FORMSTEAD_RATE_PER_MINUTE', self::RATE_PER_MINUTE ),
			'rate_per_hour'     => self::constant_int( 'FORMSTEAD_RATE_PER_HOUR', self::RATE_PER_HOUR ),
		);

		/**
		 * Filters the global submission limits.
		 *
		 * Keys: `max_request_bytes`, `max_fields`, `rate_per_minute`, `rate_per_hour`.
		 * Values are clamped to the hard ceilings.
		 *
		 * @param array{max_request_bytes: int, max_fields: int, rate_per_minute: int, rate_per_hour: int} $limits Limits from constants or the built-in defaults.
		 */
		$filtered = apply_filters( 'formstead_limits', $defaults );
		if ( ! is_array( $filtered ) ) {
			$filtered = $defaults;
		}

		return array(
			'max_request_bytes' => self::clamp( $filtered['max_request_bytes'] ?? $defaults['max_request_bytes'], 1, self::CEILING_REQUEST_BYTES, self::MAX_REQUEST_BYTES ),
			'max_fields'        => self::clamp( $filtered['max_fields'] ?? $defaults['max_fields'], 1, self::CEILING_FIELDS, self::MAX_FIELDS ),
			'rate_per_minute'   => self::clamp( $filtered['rate_per_minute'] ?? $defaults['rate_per_minute'], 1, self::CEILING_RATE_PER_MINUTE, self::RATE_PER_MINUTE ),
			'rate_per_hour'     => self::clamp( $filtered['rate_per_hour'] ?? $defaults['rate_per_hour'], 1, self::CEILING_RATE_PER_HOUR, self::RATE_PER_HOUR ),
		);
	}

	/**
	 * Integer constant, or the default when it is missing or not numeric.
	 *
	 * @param string $name     Constant name.
	 * @param int    $fallback Built-in value.
	 */
	private static function constant_int( string $name, int $fallback ): int {
		if ( ! defined( $name ) ) {
			return $fallback;
		}

		$value = constant( $name );
		return is_numeric( $value ) ? (int) $value : $fallback;
	}

	/**
	 * Clamp a numeric value. A non-numeric value uses the fallback, then the bounds.
	 *
	 * @param mixed $value    Candidate value.
	 * @param int   $min      Lower bound.
	 * @param int   $max      Upper bound.
	 * @param int   $fallback Value used when the candidate is not numeric.
	 */
	public static function clamp( mixed $value, int $min, int $max, int $fallback ): int {
		$number = is_numeric( $value ) ? (int) $value : $fallback;
		return min( $max, max( $min, $number ) );
	}
}
