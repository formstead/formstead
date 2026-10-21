<?php
/**
 * Constants for PHPStan analysis of plugin sources.
 *
 * @package Formstead
 */

if ( ! defined( 'FORMSTEAD_VERSION' ) ) {
	define( 'FORMSTEAD_VERSION', '0.0.0-phpstan' );
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

if ( ! class_exists( 'WP_CLI' ) ) {
	/**
	 * Stand-in for the WP-CLI command class during analysis.
	 *
	 * phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound
	 */
	class WP_CLI {

		/**
		 * Register a command.
		 *
		 * @param string          $name    Command name.
		 * @param callable|string $command Command class or callback.
		 */
		public static function add_command( $name, $command ): void {
			unset( $name, $command );
		}

		/**
		 * Print a line.
		 *
		 * @param string $message Message.
		 */
		public static function log( $message ): void {
			unset( $message );
		}

		/**
		 * Print a warning.
		 *
		 * @param string $message Message.
		 */
		public static function warning( $message ): void {
			unset( $message );
		}

		/**
		 * Print a success line.
		 *
		 * @param string $message Message.
		 */
		public static function success( $message ): void {
			unset( $message );
		}

		/**
		 * Print an error and stop the process.
		 *
		 * @param string $message Message.
		 * @param bool   $stop    Whether to stop the process. Kept for the real signature.
		 */
		public static function error( $message, $stop = true ): void {
			unset( $message, $stop );
		}
	}
}
