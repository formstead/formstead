<?php
/**
 * Cancels deliveries that must not be sent.
 *
 * @package Formstead
 */

namespace Formstead\Delivery;

use Formstead\Database\Schema;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is an identifier.

/**
 * Cancels pending and in-progress deliveries for submissions that are going away.
 *
 * Deletion in a later stage calls this inside the same transaction as the delete.
 */
final class DeliveryCanceller {

	/**
	 * Cancel every open delivery for these submissions.
	 *
	 * Handed-off and already finished rows are left as they are.
	 *
	 * @param int[] $submission_ids Submission ids.
	 */
	public function cancel_for_submissions( array $submission_ids ): void {
		global $wpdb;

		$ids = array();
		foreach ( $submission_ids as $id ) {
			if ( is_int( $id ) && $id > 0 ) {
				$ids[] = $id;
			}
		}
		if ( array() === $ids ) {
			return;
		}

		$table        = Schema::deliveries_table();
		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		$values       = array_merge(
			array( DeliveryStatus::CANCELLED, 'cancelled', current_time( 'mysql', true ), DeliveryStatus::PENDING, DeliveryStatus::PROCESSING ),
			$ids
		);

		$wpdb->query(
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- the IN list is unpacked.
			$wpdb->prepare(
				"UPDATE {$table} SET status = %s, locked_until = NULL, lock_token = NULL, last_error = %s, updated_at = %s WHERE status IN ( %s, %s ) AND submission_id IN ( {$placeholders} )",
				...$values
			)
		);
	}
}
