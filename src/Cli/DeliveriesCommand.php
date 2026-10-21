<?php
/**
 * WP-CLI command for deliveries.
 *
 * @package Formstead
 */

namespace Formstead\Cli;

use Formstead\Delivery\Scheduler;

/**
 * Runs due deliveries from the system cron.
 */
final class DeliveriesCommand {

	/**
	 * Register the command when WP-CLI is loaded.
	 */
	public static function register(): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}

		\WP_CLI::add_command( 'formstead deliveries', self::class );
	}

	/**
	 * Send due deliveries.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<n>]
	 * : How many deliveries to claim. Defaults to 20.
	 *
	 * @param string[]             $args       Positional arguments. Unused.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 */
	public function run( array $args, array $assoc_args ): void {
		unset( $args );

		$processed = self::process( $assoc_args );
		if ( class_exists( '\WP_CLI' ) ) {
			\WP_CLI::log( sprintf( 'Processed %d deliveries.', $processed ) );
		}
	}

	/**
	 * Claim and send due deliveries.
	 *
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 */
	public static function process( array $assoc_args ): int {
		$limit = isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : 20;

		return Scheduler::worker()->run_due( max( 1, $limit ) );
	}
}
