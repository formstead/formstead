<?php
/**
 * Plugin activation.
 *
 * @package Formstead
 */

namespace Formstead\Lifecycle;

use WP_Error;

/**
 * Guards activation. Schema creation belongs to a later stage.
 */
final class Activator {

	/**
	 * Run on plugin activation.
	 *
	 * @param bool $network_wide Whether the plugin is network-activated.
	 */
	public static function activate( bool $network_wide = false ): void {
		$error = self::activation_error( $network_wide, is_multisite() );

		if ( $error instanceof WP_Error ) {
			wp_die(
				esc_html( $error->get_error_message() ),
				esc_html__( 'Formstead activation failed', 'formstead' ),
				array(
					'response'  => 403,
					'back_link' => true,
				)
			);
		}
	}

	/**
	 * Return an error when activation must not continue.
	 *
	 * Extracted so tests can cover the Multisite refusal without a network.
	 *
	 * @param bool $network_wide Whether network activation was requested.
	 * @param bool $is_multisite Whether the site is a Multisite network.
	 */
	public static function activation_error( bool $network_wide, bool $is_multisite ): ?WP_Error {
		if ( $network_wide && $is_multisite ) {
			return new WP_Error(
				'formstead_network_activation',
				__( 'Network activation is not supported. Activate Formstead on each site separately.', 'formstead' )
			);
		}

		global $wp_version;

		$reason = Requirements::unmet_reason( PHP_VERSION, (string) $wp_version );

		if ( Requirements::REASON_PHP === $reason ) {
			return new WP_Error(
				'formstead_php_version',
				sprintf(
					/* translators: 1: required PHP version, 2: current PHP version */
					__( 'Formstead requires PHP %1$s or later. This site is running PHP %2$s.', 'formstead' ),
					FORMSTEAD_MIN_PHP,
					PHP_VERSION
				)
			);
		}

		if ( Requirements::REASON_WP === $reason ) {
			return new WP_Error(
				'formstead_wp_version',
				sprintf(
					/* translators: 1: required WordPress version, 2: current WordPress version */
					__( 'Formstead requires WordPress %1$s or later. This site is running WordPress %2$s.', 'formstead' ),
					FORMSTEAD_MIN_WP,
					(string) $wp_version
				)
			);
		}

		return null;
	}
}
