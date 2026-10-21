<?php
/**
 * Stored idempotency keys.
 *
 * @package Formstead
 */

namespace Formstead\Submissions;

use Formstead\Database\Schema;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- table name is an identifier. Dynamic IN lists are unpacked.

/**
 * Reserves a key inside the caller's transaction.
 *
 * Only the SHA-256 of the key is stored. A duplicate insert is the lock that
 * serializes two requests using the same key.
 */
final class IdempotencyRepository {

	/**
	 * How many requests pass between expired-row cleanups.
	 */
	private const PRUNE_EVERY = 50;

	/**
	 * Expired rows deleted in one cleanup.
	 */
	private const PRUNE_BATCH = 500;

	/**
	 * Reserve a key, or report that it is already in use.
	 *
	 * The insert runs first. Deleting a missing expired row first takes a gap
	 * lock, and two such deletes deadlock on MySQL when both then insert.
	 * After a duplicate, a locking read waits for the transaction that owns
	 * the row. An expired row is overwritten in place. The insert uses
	 * `submission_id` 0 until {@see self::attach()} runs in the same transaction.
	 *
	 * A deadlock or a lock-wait timeout is a contended claim. The intake
	 * retries the transaction. The client receives a storage failure only
	 * when those retries are used up.
	 *
	 * @param int    $form_id   Form id.
	 * @param string $key_hash  SHA-256 of the raw key.
	 * @param string $body_hash SHA-256 of the canonical body.
	 */
	public function claim( int $form_id, string $key_hash, string $body_hash ): IdempotencyClaim {
		global $wpdb;

		$now       = time();
		$table     = Schema::idempotency_keys_table();
		$previous  = $wpdb->suppress_errors( true );
		$saved     = $wpdb->insert(
			$table,
			array(
				'form_id'       => $form_id,
				'key_hash'      => $key_hash,
				'body_hash'     => $body_hash,
				'submission_id' => 0,
				'expires_at'    => $now + IdempotencyKey::WINDOW,
			),
			array( '%d', '%s', '%s', '%d', '%d' )
		);
		$error     = (string) $wpdb->last_error;
		$duplicate = false !== stripos( $error, 'Duplicate' );
		$wpdb->suppress_errors( $previous );

		if ( false !== $saved ) {
			return IdempotencyClaim::inserted( (int) $wpdb->insert_id );
		}
		if ( ! $duplicate ) {
			return $this->claim_failure( $form_id, $error );
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, body_hash, submission_id, expires_at FROM {$table} WHERE form_id = %d AND key_hash = %s FOR UPDATE",
				$form_id,
				$key_hash
			),
			ARRAY_A
		);
		if ( ! is_array( $row ) ) {
			return $this->claim_failure( $form_id, (string) $wpdb->last_error );
		}
		if ( (int) ( $row['expires_at'] ?? 0 ) <= $now ) {
			return $this->reuse_expired( $table, (int) ( $row['id'] ?? 0 ), $body_hash, $now, $form_id );
		}
		if ( ! hash_equals( (string) ( $row['body_hash'] ?? '' ), $body_hash ) ) {
			return IdempotencyClaim::conflict();
		}

		$submission_id = (int) ( $row['submission_id'] ?? 0 );
		if ( $submission_id < 1 ) {
			$this->log_failure( $form_id );
			return IdempotencyClaim::failed();
		}

		return IdempotencyClaim::replay( $submission_id );
	}

	/**
	 * Overwrite an expired key row and treat it as a fresh insert.
	 *
	 * The caller already holds the row lock. `submission_id` goes back to 0
	 * so {@see self::attach()} can point the row at the new submission.
	 *
	 * @param string $table     Idempotency table name.
	 * @param int    $id        Key row id.
	 * @param string $body_hash SHA-256 of the canonical body.
	 * @param int    $now       Unix time.
	 * @param int    $form_id   Form id, used only in the failure log.
	 */
	private function reuse_expired( string $table, int $id, string $body_hash, int $now, int $form_id ): IdempotencyClaim {
		global $wpdb;

		if ( $id < 1 ) {
			$this->log_failure( $form_id );
			return IdempotencyClaim::failed();
		}

		$previous = $wpdb->suppress_errors( true );
		$saved    = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET body_hash = %s, submission_id = 0, expires_at = %d WHERE id = %d AND expires_at <= %d",
				$body_hash,
				$now + IdempotencyKey::WINDOW,
				$id,
				$now
			)
		);
		$error    = (string) $wpdb->last_error;
		$wpdb->suppress_errors( $previous );

		if ( 1 !== $saved ) {
			return $this->claim_failure( $form_id, $error );
		}

		return IdempotencyClaim::inserted( $id );
	}

	/**
	 * Treat a key whose submission is gone as a fresh insert.
	 *
	 * The caller holds the row lock from {@see self::claim()}. The same key
	 * can then store a new submission instead of failing the request.
	 *
	 * @param int    $form_id   Form id.
	 * @param string $key_hash  SHA-256 of the raw key.
	 * @param string $body_hash SHA-256 of the canonical body.
	 */
	public function reopen( int $form_id, string $key_hash, string $body_hash ): IdempotencyClaim {
		global $wpdb;

		$table = Schema::idempotency_keys_table();
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE form_id = %d AND key_hash = %s FOR UPDATE",
				$form_id,
				$key_hash
			),
			ARRAY_A
		);
		if ( ! is_array( $row ) ) {
			return $this->claim_failure( $form_id, (string) $wpdb->last_error );
		}

		$id    = (int) ( $row['id'] ?? 0 );
		$saved = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET body_hash = %s, submission_id = 0, expires_at = %d WHERE id = %d",
				$body_hash,
				time() + IdempotencyKey::WINDOW,
				$id
			)
		);
		if ( false === $saved || $id < 1 ) {
			return $this->claim_failure( $form_id, (string) $wpdb->last_error );
		}

		return IdempotencyClaim::inserted( $id );
	}

	/**
	 * Delete keys that point at these submissions.
	 *
	 * Called inside the deletion transaction, before the submissions go away.
	 *
	 * @param int[] $submission_ids Submission ids.
	 * @return bool False when the delete query fails.
	 */
	public function delete_for_submissions( array $submission_ids ): bool {
		global $wpdb;

		$ids = array();
		foreach ( $submission_ids as $id ) {
			if ( is_int( $id ) && $id > 0 ) {
				$ids[ $id ] = $id;
			}
		}
		if ( array() === $ids ) {
			return true;
		}

		$ids          = array_values( $ids );
		$table        = Schema::idempotency_keys_table();
		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		$deleted      = $wpdb->query(
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- the IN list is unpacked.
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE submission_id IN ( {$placeholders} )",
				...$ids
			)
		);

		return false !== $deleted;
	}

	/**
	 * Point a newly inserted key at its submission.
	 *
	 * @param int $id            Key row id.
	 * @param int $submission_id Submission id.
	 */
	public function attach( int $id, int $submission_id ): bool {
		global $wpdb;

		if ( $id < 1 || $submission_id < 1 ) {
			return false;
		}

		$saved = $wpdb->update(
			Schema::idempotency_keys_table(),
			array( 'submission_id' => $submission_id ),
			array(
				'id'            => $id,
				'submission_id' => 0,
			),
			array( '%d' ),
			array( '%d', '%d' )
		);

		return 1 === $saved;
	}

	/**
	 * Delete a batch of expired keys on about one request in fifty.
	 *
	 * @param int $now Unix time.
	 */
	public function maybe_prune( int $now ): void {
		if ( 1 === wp_rand( 1, self::PRUNE_EVERY ) ) {
			$this->prune_expired( $now );
		}
	}

	/**
	 * Delete expired keys, up to one batch.
	 *
	 * @param int $now   Unix time.
	 * @param int $limit Maximum rows. A full batch means older keys may remain.
	 */
	public function prune_expired( int $now, int $limit = self::PRUNE_BATCH ): int {
		global $wpdb;

		$limit   = max( 1, $limit );
		$table   = Schema::idempotency_keys_table();
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE expires_at <= %d LIMIT %d",
				$now,
				$limit
			)
		);

		return is_int( $deleted ) ? $deleted : 0;
	}

	/**
	 * Whether an expired key is still stored.
	 *
	 * @param int $now Unix time.
	 */
	public function has_expired( int $now ): bool {
		global $wpdb;

		$table = Schema::idempotency_keys_table();
		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE expires_at <= %d LIMIT 1",
				$now
			)
		);

		return is_numeric( $found );
	}

	/**
	 * A deadlock or a lock-wait timeout can be retried. Anything else is a failure.
	 *
	 * Deadlock (1213) and lock-wait timeout are expected when two requests
	 * meet. The log stays quiet for those two. Other failures name the form id.
	 *
	 * @param int    $form_id Form id.
	 * @param string $error   Database error text.
	 */
	private function claim_failure( int $form_id, string $error ): IdempotencyClaim {
		if ( $this->is_lock_contention( $error ) ) {
			return IdempotencyClaim::contended();
		}

		$this->log_failure( $form_id );
		return IdempotencyClaim::failed();
	}

	/**
	 * Whether the database aborted the statement because of a lock.
	 *
	 * @param string $error Database error text.
	 */
	private function is_lock_contention( string $error ): bool {
		return false !== stripos( $error, 'Deadlock' ) || false !== stripos( $error, 'Lock wait timeout' );
	}

	/**
	 * Record a failed claim without the key or the submission text.
	 *
	 * @param int $form_id Form id.
	 */
	private function log_failure( int $form_id ): void {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- form id only, never the key.
		error_log( sprintf( 'Formstead could not store an idempotency key for form %d.', $form_id ) );
	}
}
