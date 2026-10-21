<?php
/**
 * Delivery persistence.
 *
 * @package Formstead
 */

namespace Formstead\Delivery;

use Formstead\Database\Schema;
use WP_Error;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is an identifier.

/**
 * Stores delivery tasks and claims them for a worker.
 */
final class DeliveryRepository {

	/**
	 * How long a claim is held before another worker may take it, in seconds.
	 */
	public const LOCK_TTL = 300;

	/**
	 * How many times a delivery is claimed before it is failed.
	 */
	public const MAX_ATTEMPTS = 5;

	/**
	 * Create a pending email delivery for a submission.
	 *
	 * Called inside the submission transaction. A failure rolls that transaction back.
	 *
	 * @param int $submission_id Submission id.
	 */
	public function create( int $submission_id ): int|WP_Error {
		global $wpdb;

		if ( $submission_id < 1 ) {
			return new WP_Error(
				'formstead_delivery_save_failed',
				__( 'The submission could not be saved.', 'formstead' )
			);
		}

		$now      = $this->now();
		$previous = $wpdb->suppress_errors( true );
		$saved    = $wpdb->insert(
			Schema::deliveries_table(),
			array(
				'submission_id'   => $submission_id,
				'channel'         => 'email',
				'status'          => DeliveryStatus::PENDING,
				'attempts'        => 0,
				'next_attempt_at' => $now,
				'created_at'      => $now,
				'updated_at'      => $now,
			),
			array( '%d', '%s', '%s', '%d', '%s', '%s', '%s' )
		);
		$wpdb->suppress_errors( $previous );

		if ( false === $saved ) {
			$this->log_failure( 0 );
			return new WP_Error(
				'formstead_delivery_save_failed',
				__( 'The submission could not be saved.', 'formstead' )
			);
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Claim one due delivery, or return null when another worker holds it.
	 *
	 * The update is the lock. A processing row whose lock has expired can be claimed again.
	 *
	 * @param int $id Delivery id.
	 */
	public function claim( int $id ): ?Delivery {
		global $wpdb;

		if ( $id < 1 ) {
			return null;
		}

		$now   = $this->now();
		$until = gmdate( 'Y-m-d H:i:s', time() + self::LOCK_TTL );
		$token = bin2hex( random_bytes( 16 ) );
		$table = Schema::deliveries_table();
		$saved = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = %s, lock_token = %s, locked_until = %s, attempts = attempts + 1, updated_at = %s WHERE id = %d AND ( ( status = %s AND next_attempt_at <= %s ) OR ( status = %s AND locked_until < %s ) )",
				DeliveryStatus::PROCESSING,
				$token,
				$until,
				$now,
				$id,
				DeliveryStatus::PENDING,
				$now,
				DeliveryStatus::PROCESSING,
				$now
			)
		);

		if ( 1 !== $saved ) {
			return null;
		}

		return $this->find_token( $id, $token );
	}

	/**
	 * Ids that are due, oldest first.
	 *
	 * @param int $limit Maximum rows.
	 * @return int[]
	 */
	public function due( int $limit ): array {
		global $wpdb;

		$limit = max( 1, $limit );
		$now   = $this->now();
		$table = Schema::deliveries_table();
		$ids   = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE ( status = %s AND next_attempt_at <= %s ) OR ( status = %s AND locked_until < %s ) ORDER BY next_attempt_at ASC, id ASC LIMIT %d",
				DeliveryStatus::PENDING,
				$now,
				DeliveryStatus::PROCESSING,
				$now,
				$limit
			)
		);

		if ( ! is_array( $ids ) ) {
			return array();
		}

		return array_map( 'intval', $ids );
	}

	/**
	 * Deliveries for one submission, oldest first.
	 *
	 * @param int $submission_id Submission id.
	 * @return Delivery[]
	 */
	public function for_submission( int $submission_id ): array {
		global $wpdb;

		$table = Schema::deliveries_table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, submission_id, channel, status, attempts, next_attempt_at, locked_until, lock_token, last_error FROM {$table} WHERE submission_id = %d ORDER BY id ASC",
				$submission_id
			),
			ARRAY_A
		);

		return $this->hydrate_rows( $rows );
	}

	/**
	 * Delete finished deliveries last changed before this time.
	 *
	 * Pending and processing rows are left alone. A row changed at exactly
	 * `$before` is kept.
	 *
	 * @param string $before UTC `Y-m-d H:i:s` cutoff.
	 * @param int    $limit  Maximum rows.
	 */
	public function delete_finished_before( string $before, int $limit ): int {
		global $wpdb;

		$limit   = max( 1, $limit );
		$table   = Schema::deliveries_table();
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE status IN (%s, %s, %s) AND updated_at < %s ORDER BY updated_at ASC, id ASC LIMIT %d",
				DeliveryStatus::HANDED_OFF,
				DeliveryStatus::FAILED,
				DeliveryStatus::CANCELLED,
				$before,
				$limit
			)
		);
		if ( ! is_int( $deleted ) ) {
			$this->log_failure( 0 );
			return 0;
		}

		return $deleted;
	}

	/**
	 * Whether a finished delivery is still older than this time.
	 *
	 * @param string $before UTC `Y-m-d H:i:s` cutoff.
	 */
	public function has_finished_before( string $before ): bool {
		global $wpdb;

		$table = Schema::deliveries_table();
		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE status IN (%s, %s, %s) AND updated_at < %s LIMIT 1",
				DeliveryStatus::HANDED_OFF,
				DeliveryStatus::FAILED,
				DeliveryStatus::CANCELLED,
				$before
			)
		);

		return is_numeric( $found );
	}

	/**
	 * Load one delivery.
	 *
	 * @param int $id Delivery id.
	 */
	public function find( int $id ): ?Delivery {
		global $wpdb;

		$table = Schema::deliveries_table();
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, submission_id, channel, status, attempts, next_attempt_at, locked_until, lock_token, last_error FROM {$table} WHERE id = %d",
				$id
			),
			ARRAY_A
		);

		return $this->hydrate( is_array( $row ) ? $row : null );
	}

	/**
	 * Mark a claimed delivery as handed to the mail system.
	 *
	 * @param int    $id    Delivery id.
	 * @param string $token Claim token.
	 */
	public function mark_handed_off( int $id, string $token ): bool {
		return $this->finish( $id, $token, DeliveryStatus::HANDED_OFF, '' );
	}

	/**
	 * Put a claimed delivery back in the queue.
	 *
	 * @param int    $id    Delivery id.
	 * @param string $token Claim token.
	 * @param string $error Error code, without addresses or body text.
	 * @param int    $delay Seconds until the next claim.
	 */
	public function schedule_retry( int $id, string $token, string $error, int $delay ): bool {
		global $wpdb;

		$saved = $wpdb->update(
			Schema::deliveries_table(),
			array(
				'status'          => DeliveryStatus::PENDING,
				'next_attempt_at' => gmdate( 'Y-m-d H:i:s', time() + max( 1, $delay ) ),
				'locked_until'    => null,
				'lock_token'      => null,
				'last_error'      => $error,
				'updated_at'      => $this->now(),
			),
			array(
				'id'         => $id,
				'lock_token' => $token,
				'status'     => DeliveryStatus::PROCESSING,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s' ),
			array( '%d', '%s', '%s' )
		);

		return 1 === $saved;
	}

	/**
	 * Stop retrying a claimed delivery.
	 *
	 * @param int    $id    Delivery id.
	 * @param string $token Claim token.
	 * @param string $error Error code, without addresses or body text.
	 */
	public function mark_failed( int $id, string $token, string $error ): bool {
		return $this->finish( $id, $token, DeliveryStatus::FAILED, $error );
	}

	/**
	 * Cancel a claimed or pending delivery.
	 *
	 * @param int    $id    Delivery id.
	 * @param string $token Claim token. Empty when the row is still pending.
	 * @param string $error Error code.
	 */
	public function cancel( int $id, string $token, string $error ): bool {
		global $wpdb;

		$where  = array(
			'id'     => $id,
			'status' => '' === $token ? DeliveryStatus::PENDING : DeliveryStatus::PROCESSING,
		);
		$format = array( '%d', '%s' );
		if ( '' !== $token ) {
			$where['lock_token'] = $token;
			$format[]            = '%s';
		}

		$saved = $wpdb->update(
			Schema::deliveries_table(),
			array(
				'status'       => DeliveryStatus::CANCELLED,
				'locked_until' => null,
				'lock_token'   => null,
				'last_error'   => $error,
				'updated_at'   => $this->now(),
			),
			$where,
			array( '%s', '%s', '%s', '%s', '%s' ),
			$format
		);

		return 1 === $saved;
	}

	/**
	 * Finish a claimed delivery.
	 *
	 * @param int    $id     Delivery id.
	 * @param string $token  Claim token.
	 * @param string $status Next status.
	 * @param string $error  Error code, or an empty string.
	 */
	private function finish( int $id, string $token, string $status, string $error ): bool {
		global $wpdb;

		$saved = $wpdb->update(
			Schema::deliveries_table(),
			array(
				'status'       => $status,
				'locked_until' => null,
				'lock_token'   => null,
				'last_error'   => '' === $error ? null : $error,
				'updated_at'   => $this->now(),
			),
			array(
				'id'         => $id,
				'lock_token' => $token,
				'status'     => DeliveryStatus::PROCESSING,
			),
			array( '%s', '%s', '%s', '%s', '%s' ),
			array( '%d', '%s', '%s' )
		);

		return 1 === $saved;
	}

	/**
	 * Load the row a claim just took.
	 *
	 * @param int    $id    Delivery id.
	 * @param string $token Claim token.
	 */
	private function find_token( int $id, string $token ): ?Delivery {
		global $wpdb;

		$table = Schema::deliveries_table();
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, submission_id, channel, status, attempts, next_attempt_at, locked_until, lock_token, last_error FROM {$table} WHERE id = %d AND lock_token = %s",
				$id,
				$token
			),
			ARRAY_A
		);

		return $this->hydrate( is_array( $row ) ? $row : null );
	}

	/**
	 * Build deliveries from wpdb rows.
	 *
	 * @param mixed $rows Rows from wpdb.
	 * @return Delivery[]
	 */
	private function hydrate_rows( mixed $rows ): array {
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$deliveries = array();
		foreach ( $rows as $row ) {
			$delivery = $this->hydrate( is_array( $row ) ? $row : null );
			if ( $delivery instanceof Delivery ) {
				$deliveries[] = $delivery;
			}
		}

		return $deliveries;
	}

	/**
	 * Build a delivery from a database row.
	 *
	 * @param array<string, mixed>|null $row Row from wpdb.
	 */
	private function hydrate( ?array $row ): ?Delivery {
		if ( ! is_array( $row ) ) {
			return null;
		}

		return new Delivery(
			(int) ( $row['id'] ?? 0 ),
			(int) ( $row['submission_id'] ?? 0 ),
			(string) ( $row['channel'] ?? '' ),
			(string) ( $row['status'] ?? '' ),
			(int) ( $row['attempts'] ?? 0 ),
			(string) ( $row['next_attempt_at'] ?? '' ),
			(string) ( $row['locked_until'] ?? '' ),
			(string) ( $row['lock_token'] ?? '' ),
			(string) ( $row['last_error'] ?? '' )
		);
	}

	/**
	 * Current UTC time in MySQL format.
	 */
	private function now(): string {
		return current_time( 'mysql', true );
	}

	/**
	 * Record a failed write without the submission text.
	 *
	 * @param int $delivery_id Delivery id, or zero when the row was not created.
	 */
	private function log_failure( int $delivery_id ): void {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- delivery id only.
		error_log( sprintf( 'Formstead could not store delivery %d.', $delivery_id ) );
	}
}
