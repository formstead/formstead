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
	 * Load translations. Domain logic is added in later stages.
	 */
	public static function boot(): void {
		global $wp_version;

		if ( ! Requirements::are_met( PHP_VERSION, (string) $wp_version ) ) {
			return;
		}

		load_plugin_textdomain(
			'formstead',
			false,
			dirname( plugin_basename( FORMSTEAD_FILE ) ) . '/languages'
		);
	}
}
