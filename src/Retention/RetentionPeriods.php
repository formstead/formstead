<?php
/**
 * How long leftover rows are kept.
 *
 * @package Formstead
 */

namespace Formstead\Retention;

use Formstead\Security\Limits;

/**
 * Reads retention periods from constants and the `formstead_retention_periods` filter.
 *
 * The filter is not named `formstead_retention`. That string is the cron
 * event, and WordPress stores actions and filters on the same hook.
 *
 * A form has its own period for submissions. Spam and finished deliveries use
 * these global periods. Values outside 1 to 3650 days are clamped.
 */
final class RetentionPeriods {

	public const SPAM_DAYS = 7;

	public const DELIVERY_DAYS = 30;

	public const MIN_DAYS = 1;

	public const MAX_DAYS = 3650;

	/**
	 * Days before a spam submission is deleted, even when the form keeps other rows longer.
	 */
	public static function spam_days(): int {
		return self::configured()['spam_days'];
	}

	/**
	 * Days before a finished delivery row is deleted.
	 */
	public static function delivery_days(): int {
		return self::configured()['delivery_days'];
	}

	/**
	 * Effective periods after constants, the filter, and the bounds.
	 *
	 * @return array{spam_days: int, delivery_days: int}
	 */
	private static function configured(): array {
		$defaults = array(
			'spam_days'     => self::constant_int( 'FORMSTEAD_SPAM_RETENTION_DAYS', self::SPAM_DAYS ),
			'delivery_days' => self::constant_int( 'FORMSTEAD_DELIVERY_RETENTION_DAYS', self::DELIVERY_DAYS ),
		);

		/**
		 * Filters the global retention periods, in days.
		 *
		 * Keys: `spam_days`, `delivery_days`. Values are clamped to 1–3650.
		 * A form's own period is stored on the form and is not part of this filter.
		 *
		 * @param array{spam_days: int, delivery_days: int} $periods Periods from constants or the built-in defaults.
		 */
		$filtered = apply_filters( 'formstead_retention_periods', $defaults );
		if ( ! is_array( $filtered ) ) {
			$filtered = $defaults;
		}

		return array(
			'spam_days'     => Limits::clamp( $filtered['spam_days'] ?? $defaults['spam_days'], self::MIN_DAYS, self::MAX_DAYS, self::SPAM_DAYS ),
			'delivery_days' => Limits::clamp( $filtered['delivery_days'] ?? $defaults['delivery_days'], self::MIN_DAYS, self::MAX_DAYS, self::DELIVERY_DAYS ),
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
}
