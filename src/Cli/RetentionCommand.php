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

		if ( ! class_exists( '\WP_CLI' ) ) {
			self::process( $assoc_args );
			return;
		}

		foreach ( self::messages( self::process( $assoc_args ) ) as $message ) {
			self::emit( $message );
		}
	}

	/**
	 * Lines the command prints for a report.
	 *
	 * Null means another run holds the lock. An error is last, because WP-CLI
	 * stops the process there and the system cron can see the failure.
	 *
	 * @param array{started_at: int, finished_at: int, submissions: int, spam: int, deliveries: int, keys: int, rate_limits: int, backlog: bool, backlog_since: int, errors: list<string>}|null $report Retention report.
	 * @return list<array{level: string, text: string}>
	 */
	public static function messages( ?array $report ): array {
		if ( null === $report ) {
			return array(
				self::line( 'warning', 'Retention is already running.' ),
			);
		}

		$lines = array(
			self::line( 'log', sprintf( 'Deleted %d submissions.', $report['submissions'] ) ),
			self::line( 'log', sprintf( 'Deleted %d spam.', $report['spam'] ) ),
			self::line( 'log', sprintf( 'Deleted %d deliveries.', $report['deliveries'] ) ),
			self::line( 'log', sprintf( 'Deleted %d idempotency keys.', $report['keys'] ) ),
			self::line( 'log', sprintf( 'Deleted %d rate-limit counters.', $report['rate_limits'] ) ),
		);

		if ( $report['backlog'] ) {
			$lines[] = self::line( 'warning', 'Expired rows are still waiting.' );
		}

		if ( array() !== $report['errors'] ) {
			$lines[] = self::line( 'error', sprintf( 'Retention failed: %s', implode( ', ', $report['errors'] ) ) );
			return $lines;
		}

		if ( ! $report['backlog'] ) {
			$lines[] = self::line( 'success', 'Retention finished.' );
		}

		return $lines;
	}

	/**
	 * Print one line at its level. An error stops the process.
	 *
	 * @param array{level: string, text: string} $message Level and text.
	 */
	private static function emit( array $message ): void {
		if ( 'error' === $message['level'] ) {
			\WP_CLI::error( $message['text'] );
			return;
		}

		if ( 'warning' === $message['level'] ) {
			\WP_CLI::warning( $message['text'] );
			return;
		}

		if ( 'success' === $message['level'] ) {
			\WP_CLI::success( $message['text'] );
			return;
		}

		\WP_CLI::log( $message['text'] );
	}

	/**
	 * One printed line.
	 *
	 * @param string $level `log`, `warning`, `success`, or `error`.
	 * @param string $text  Message.
	 * @return array{level: string, text: string}
	 */
	private static function line( string $level, string $text ): array {
		return array(
			'level' => $level,
			'text'  => $text,
		);
	}

	/**
	 * Run retention and return its report.
	 *
	 * Null means another run holds the lock.
	 *
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @return array{started_at: int, finished_at: int, submissions: int, spam: int, deliveries: int, keys: int, rate_limits: int, backlog: bool, backlog_since: int, errors: list<string>}|null
	 */
	public static function process( array $assoc_args ): ?array {
		$batches = null;
		if ( isset( $assoc_args['batches'] ) ) {
			$batches = max( 1, (int) $assoc_args['batches'] );
		}

		return ( new RetentionRunner() )->run( $batches, null );
	}
}
