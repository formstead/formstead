<?php
/**
 * Constants for PHPStan analysis of plugin sources.
 *
 * @package Formstead
 */

if ( ! defined( 'FORMSTEAD_VERSION' ) ) {
	define( 'FORMSTEAD_VERSION', '0.0.1-dev' );
}

if ( ! defined( 'FORMSTEAD_FILE' ) ) {
	define( 'FORMSTEAD_FILE', dirname( __DIR__ ) . '/formstead.php' );
}

if ( ! defined( 'FORMSTEAD_MIN_PHP' ) ) {
	define( 'FORMSTEAD_MIN_PHP', '8.3' );
}

if ( ! defined( 'FORMSTEAD_MIN_WP' ) ) {
	define( 'FORMSTEAD_MIN_WP', '7.0' );
}
