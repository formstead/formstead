<?php
/**
 * Plugin bootstrap.
 *
 * @package Formstead
 */

namespace Formstead;

use Formstead\Lifecycle\Requirements;

/**
 * Registers plugin runtime hooks after WordPress has loaded plugins.
 */
final class Plugin {

	/**
	 * Boot the plugin after WordPress has loaded plugins.
	 *
	 * Domain logic is added in later stages. WordPress 4.6+ loads translations
	 * for the `formstead` text domain automatically when they are present.
	 */
	public static function boot(): void {
		global $wp_version;

		if ( ! Requirements::are_met( PHP_VERSION, (string) $wp_version ) ) {
			return;
		}
	}
}
