<?php
/**
 * Stored idempotency keys.
 *
 * @package Formstead
 */

namespace Formstead\Submissions;

use Formstead\Database\Schema;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is an identifier.

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
	 * An expired row for this key is removed first. The insert uses
	 * `submission_id` 0 until {@see self::attach()} runs in the same transaction.
	 * The lookup after a duplicate uses a locking read so it can see a row
	 * committed by another connection after this transaction started.
	 *
	 * @param int    $form_id  Form id.
	 * @param string $key_hash SHA-256 of the raw key.
	 * @param string $body_hash SHA-256 of the canonical body.
	 */
	public function claim( int $form_id, string $key_hash, string $body_hash ): IdempotencyClaim {
		global $wpdb;

		$now    = time();
		$table  = Schema::idempotency_keys_table();
		$delete = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE form_id = %d AND key_hash = %s AND expires_at <= %d",
				$form_id,
				$key_hash,
				$now
			)
		);
		if ( false === $delete ) {
			return IdempotencyClaim::failed();
		}

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
		$duplicate = false !== stripos( (string) $wpdb->last_error, 'Duplicate' );
		$wpdb->suppress_errors( $previous );

		if ( false !== $saved ) {
			return IdempotencyClaim::inserted( (int) $wpdb->insert_id );
		}
		if ( ! $duplicate ) {
			$this->log_failure( $form_id );
			return IdempotencyClaim::failed();
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT body_hash, submission_id, expires_at FROM {$table} WHERE form_id = %d AND key_hash = %s FOR UPDATE",
				$form_id,
				$key_hash
			),
			ARRAY_A
		);
		if ( ! is_array( $row ) || (int) ( $row['expires_at'] ?? 0 ) <= $now ) {
			$this->log_failure( $form_id );
			return IdempotencyClaim::failed();
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
	 * @param int $now Unix time.
	 */
	public function prune_expired( int $now ): void {
		global $wpdb;

		$table = Schema::idempotency_keys_table();
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE expires_at <= %d LIMIT %d",
				$now,
				self::PRUNE_BATCH
			)
		);
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
