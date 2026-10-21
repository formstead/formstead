<?php
/**
 * Uninstall Formstead.
 *
 * Submissions stay unless the site opted in on the settings screen.
 * WordPress does not load the main plugin file before this one.
 *
 * @package Formstead
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( version_compare( PHP_VERSION, '8.3', '<' ) ) {
	return;
}

$formstead_autoload = __DIR__ . '/vendor/autoload.php';
if ( ! is_readable( $formstead_autoload ) ) {
	return;
}

require $formstead_autoload;

if ( ! is_multisite() && ! get_option( 'formstead_delete_data_on_uninstall' ) ) {
	return;
}

Formstead\Lifecycle\Uninstaller::wipe();
