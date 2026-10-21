<?php
/**
 * Formstead
 *
 * @package           Formstead
 * @author            Formstead
 * @copyright         Formstead
 * @license           GPL-2.0-or-later
 *
 * @wordpress-plugin
 * Plugin Name:       Formstead
 * Plugin URI:        https://formstead.dev
 * Description:       Receive form submissions from static and headless sites on an existing WordPress install.
 * Version:           0.4.1
 * Requires at least: 7.0
 * Requires PHP:      8.3
 * Author:            Formstead
 * Author URI:        https://formstead.dev
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       formstead
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'FORMSTEAD_VERSION', '0.4.1' );
define( 'FORMSTEAD_FILE', __FILE__ );
define( 'FORMSTEAD_MIN_PHP', '8.3' );
define( 'FORMSTEAD_MIN_WP', '7.0' );

/**
 * Whether the current PHP version meets the plugin minimum.
 *
 * Kept procedural and free of PHP 8 syntax so this file still parses on older runtimes.
 * The legacy-php-lint CI job runs `php -l` on this file with PHP 7.4.
 *
 * @return bool
 */
function formstead_php_meets_minimum() {
	return version_compare( PHP_VERSION, FORMSTEAD_MIN_PHP, '>=' );
}

/**
 * Print an admin notice when PHP or WordPress is too old.
 *
 * @return void
 */
function formstead_requirements_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	global $wp_version;

	if ( ! formstead_php_meets_minimum() ) {
		$message = sprintf(
			/* translators: 1: required PHP version, 2: current PHP version */
			__( 'Formstead requires PHP %1$s or later. This site is running PHP %2$s.', 'formstead' ),
			FORMSTEAD_MIN_PHP,
			PHP_VERSION
		);
	} elseif ( isset( $wp_version ) && version_compare( $wp_version, FORMSTEAD_MIN_WP, '<' ) ) {
		$message = sprintf(
			/* translators: 1: required WordPress version, 2: current WordPress version */
			__( 'Formstead requires WordPress %1$s or later. This site is running WordPress %2$s.', 'formstead' ),
			FORMSTEAD_MIN_WP,
			$wp_version
		);
	} else {
		return;
	}

	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html( $message )
	);
}

/**
 * Print an admin notice when Composer autoload is missing.
 *
 * @return void
 */
function formstead_missing_autoload_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html__( 'Formstead could not load because vendor/autoload.php is missing. Run composer install.', 'formstead' )
	);
}

if ( ! formstead_php_meets_minimum() ) {
	add_action( 'admin_notices', 'formstead_requirements_notice' );
	return;
}

$formstead_autoload = __DIR__ . '/vendor/autoload.php';
if ( ! is_readable( $formstead_autoload ) ) {
	add_action( 'admin_notices', 'formstead_missing_autoload_notice' );
	return;
}

require $formstead_autoload;

register_activation_hook( FORMSTEAD_FILE, array( 'Formstead\\Lifecycle\\Activator', 'activate' ) );
register_deactivation_hook( FORMSTEAD_FILE, array( 'Formstead\\Lifecycle\\Deactivator', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'Formstead\\Plugin', 'boot' ) );
add_action( 'admin_notices', 'formstead_requirements_notice' );
