<?php
/**
 * Short database transactions.
 *
 * @package Formstead
 */

namespace Formstead\Database;

use WP_Error;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- transaction control cannot use placeholders.

/**
 * Commits a callback, or rolls it back when the callback asks for that.
 *
 * Tests switch this to savepoints. `START TRANSACTION` would commit the
 * PHPUnit transaction and make the row visible to other connections.
 */
final class Transaction {

	/**
	 * Whether to nest inside the caller's transaction with a savepoint.
	 *
	 * @var bool
	 */
	private static bool $savepoints = false;

	/**
	 * Savepoint depth, so nested calls get distinct names.
	 *
	 * @var int
	 */
	private static int $level = 0;

	/**
	 * Use savepoints instead of starting a new transaction.
	 *
	 * The test bootstrap turns this on. Production leaves it off.
	 *
	 * @param bool $enabled Whether savepoints are used.
	 */
	public static function use_savepoints( bool $enabled ): void {
		self::$savepoints = $enabled;
	}

	/**
	 * Run a callback and commit it, unless it returns an error or a rollback.
	 *
	 * @param callable(): mixed $callback Work to commit or roll back.
	 */
	public static function run( callable $callback ): mixed {
		if ( self::$savepoints ) {
			return self::in_savepoint( $callback );
		}

		return self::in_transaction( $callback );
	}

	/**
	 * Run inside a real transaction.
	 *
	 * @param callable(): mixed $callback Work to commit or roll back.
	 * @throws \Throwable When the callback throws. The transaction is rolled back first.
	 */
	private static function in_transaction( callable $callback ): mixed {
		global $wpdb;

		$wpdb->query( 'START TRANSACTION' );

		try {
			$result = $callback();
			if ( self::should_roll_back( $result ) ) {
				$wpdb->query( 'ROLLBACK' );
				return self::unwrap( $result );
			}

			$wpdb->query( 'COMMIT' );
			return $result;
		} catch ( \Throwable $error ) {
			$wpdb->query( 'ROLLBACK' );
			throw $error;
		}
	}

	/**
	 * Run inside a savepoint so an outer transaction stays open.
	 *
	 * @param callable(): mixed $callback Work to commit or roll back.
	 * @throws \Throwable When the callback throws. The savepoint is rolled back first.
	 */
	private static function in_savepoint( callable $callback ): mixed {
		global $wpdb;

		++self::$level;
		$name = 'formstead_tx_' . self::$level;
		$wpdb->query( "SAVEPOINT {$name}" );

		try {
			$result = $callback();
			if ( self::should_roll_back( $result ) ) {
				$wpdb->query( "ROLLBACK TO SAVEPOINT {$name}" );
				return self::unwrap( $result );
			}

			return $result;
		} catch ( \Throwable $error ) {
			$wpdb->query( "ROLLBACK TO SAVEPOINT {$name}" );
			throw $error;
		} finally {
			$wpdb->query( "RELEASE SAVEPOINT {$name}" );
			--self::$level;
		}
	}

	/**
	 * Whether this result must undo the callback's writes.
	 *
	 * @param mixed $result Callback result.
	 */
	private static function should_roll_back( mixed $result ): bool {
		return $result instanceof WP_Error || $result instanceof Rollback;
	}

	/**
	 * Value the caller receives after a rollback.
	 *
	 * @param mixed $result Callback result.
	 */
	private static function unwrap( mixed $result ): mixed {
		if ( $result instanceof Rollback ) {
			return $result->value();
		}

		return $result;
	}
}
