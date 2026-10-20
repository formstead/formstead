<?php
/**
 * Fixed-window submission rate limits.
 *
 * @package Formstead
 */

namespace Formstead\Security;

use Formstead\Database\Schema;
use Formstead\Forms\Form;

/**
 * Counts submissions in a per-minute IP bucket and a per-hour form bucket.
 *
 * The minute bucket is checked first. Once it is over its limit, the hourly
 * form bucket is left unchanged. The stored key is an HMAC. A database error
 * on one bucket skips that bucket and does not log the client address.
 */
final class RateLimiter {

	/**
	 * How many requests pass between expired-row cleanups.
	 */
	private const PRUNE_EVERY = 50;

	/**
	 * Expired rows deleted in one cleanup.
	 */
	private const PRUNE_BATCH = 500;

	/**
	 * Consume one submission from the form's windows.
	 *
	 * Returns the seconds until the caller may retry, or null when the
	 * submission may proceed. A bucket whose counter cannot be saved is
	 * skipped, so a database failure does not turn into a false rejection
	 * and does not erase a limit that another bucket already reached.
	 *
	 * @param Form $form Form being submitted.
	 */
	public function consume( Form $form ): ?int {
		$now     = time();
		$subject = ClientAddress::subject();
		$checks  = array();

		if ( null !== $subject ) {
			$checks[] = $this->window( 'minute', $form, $subject, $now, 60, Limits::rate_per_minute( $form->protection() ) );
		}

		$checks[] = $this->window( 'hour', $form, 'form', $now, 3600, Limits::rate_per_hour( $form->protection() ) );

		$retry = null;
		foreach ( $checks as $check ) {
			$hits = $this->increment( $check['bucket'], $check['expires'] );
			if ( null === $hits ) {
				continue;
			}

			if ( $hits > $check['limit'] ) {
				$retry = $check['retry'];
				break;
			}
		}

		$this->maybe_prune( $now );

		return $retry;
	}

	/**
	 * Delete a batch of expired counters on about one request in fifty.
	 *
	 * Runs after a rejection too, so a blocked address still ages old rows out.
	 *
	 * @param int $now Unix time.
	 */
	private function maybe_prune( int $now ): void {
		if ( 1 === wp_rand( 1, self::PRUNE_EVERY ) ) {
			$this->prune_expired( $now );
		}
	}

	/**
	 * Delete expired counters, up to one batch.
	 *
	 * @param int $now Unix time.
	 */
	public function prune_expired( int $now ): void {
		global $wpdb;

		$table = Schema::rate_limits_table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is an identifier.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE expires_at < %d LIMIT %d",
				$now,
				self::PRUNE_BATCH
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * One fixed window.
	 *
	 * @param string $scope   `minute` or `hour`.
	 * @param Form   $form    Form being submitted.
	 * @param string $subject IP network or `form`.
	 * @param int    $now     Unix time.
	 * @param int    $length  Window length in seconds.
	 * @param int    $limit   Allowed hits in the window.
	 * @return array{bucket: string, expires: int, retry: int, limit: int}
	 */
	private function window( string $scope, Form $form, string $subject, int $now, int $length, int $limit ): array {
		$start = intdiv( $now, $length ) * $length;

		return array(
			'bucket'  => hash_hmac( 'sha256', $scope . '|' . $form->id() . '|' . $subject . '|' . $start, IpHasher::secret() ),
			'expires' => $start + $length,
			'retry'   => max( 1, ( $start + $length ) - $now ),
			'limit'   => $limit,
		);
	}

	/**
	 * Increase one bucket and return the new count.
	 *
	 * Returns null when the statement fails. The query does not contain the
	 * client address.
	 *
	 * @param string $bucket  HMAC bucket key.
	 * @param int    $expires Unix time when the window ends.
	 */
	private function increment( string $bucket, int $expires ): ?int {
		global $wpdb;

		$table = Schema::rate_limits_table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is an identifier.
		$saved = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (bucket, hits, expires_at) VALUES (%s, LAST_INSERT_ID(1), %d) ON DUPLICATE KEY UPDATE hits = LAST_INSERT_ID(hits + 1)",
				$bucket,
				$expires
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( false === $saved ) {
			return null;
		}

		$hits = (int) $wpdb->insert_id;
		return $hits > 0 ? $hits : null;
	}
}
