<?php
/**
 * Runtime requirement checks.
 *
 * @package Formstead
 */

namespace Formstead\Lifecycle;

/**
 * Compares PHP and WordPress versions against plugin minimums.
 */
final class Requirements {

	public const REASON_PHP = 'php';
	public const REASON_WP  = 'wp';

	/**
	 * Return a reason code when requirements are not met.
	 *
	 * @param string $php_version PHP version string.
	 * @param string $wp_version  WordPress version string.
	 * @return string|null `php`, `wp`, or null when versions are sufficient.
	 */
	public static function unmet_reason( string $php_version, string $wp_version ): ?string {
		if ( version_compare( $php_version, FORMSTEAD_MIN_PHP, '<' ) ) {
			return self::REASON_PHP;
		}

		if ( version_compare( $wp_version, FORMSTEAD_MIN_WP, '<' ) ) {
			return self::REASON_WP;
		}

		return null;
	}

	/**
	 * Whether the runtime meets PHP and WordPress minimums.
	 *
	 * @param string $php_version PHP version string.
	 * @param string $wp_version  WordPress version string.
	 */
	public static function are_met( string $php_version, string $wp_version ): bool {
		return null === self::unmet_reason( $php_version, $wp_version );
	}
}
