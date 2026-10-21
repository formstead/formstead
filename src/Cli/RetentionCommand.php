<?php
/**
 * WP-CLI command for retention.
 *
 * @package Formstead
 */

namespace Formstead\Cli;

use Formstead\Retention\RetentionRunner;

/**
 * Deletes expired rows from the system cron.
 */
final class RetentionCommand {

	/**
	 * Register the command when WP-CLI is loaded.
	 */
	public static function register(): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}

		\WP_CLI::add_command( 'formstead retention', self::class );
	}

	/**
	 * Delete expired submissions and spam.
	 *
	 * ## OPTIONS
	 *
	 * [--batches=<n>]
	 * : How many batches to delete. Omit this to continue until nothing is left.
	 *
	 * @param string[]             $args       Positional arguments. Unused.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 */
	public function run( array $args, array $assoc_args ): void {
		unset( $args );

		$report = self::process( $assoc_args );
		if ( ! class_exists( '\WP_CLI' ) ) {
			return;
		}

		if ( null === $report ) {
			\WP_CLI::warning( 'Retention is already running.' );
			return;
		}

		$deleted = (int) $report['submissions'] + (int) $report['spam'];
		\WP_CLI::log( sprintf( 'Deleted %d submissions.', $deleted ) );
	}

	/**
	 * Run retention and return its report.
	 *
	 * Null means another run holds the lock.
	 *
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @return array{started_at: int, finished_at: int, submissions: int, spam: int, deliveries: int, keys: int, rate_limits: int, backlog: bool, errors: list<string>}|null
	 */
	public static function process( array $assoc_args ): ?array {
		$batches = null;
		if ( isset( $assoc_args['batches'] ) ) {
			$batches = max( 1, (int) $assoc_args['batches'] );
		}

		return ( new RetentionRunner() )->run( $batches, null );
	}
}
