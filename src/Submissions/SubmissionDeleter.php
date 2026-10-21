<?php
/**
 * The only path that deletes submissions.
 *
 * @package Formstead
 */

namespace Formstead\Submissions;

use Formstead\Database\Transaction;
use Formstead\Delivery\DeliveryCanceller;
use WP_Error;

/**
 * Deletes submissions together with their open deliveries and idempotency keys.
 *
 * Retention and the privacy eraser use this service. A failed query rolls the
 * transaction back, so a delivery is not cancelled for a submission that stays.
 */
final class SubmissionDeleter {

	/**
	 * Permanently delete these submissions.
	 *
	 * @param int[] $ids Submission ids.
	 * @return int|WP_Error Rows deleted, or an error when the delete fails.
	 */
	public function delete( array $ids ): int|WP_Error {
		$clean = array();
		foreach ( $ids as $id ) {
			if ( is_int( $id ) && $id > 0 ) {
				$clean[ $id ] = $id;
			}
		}
		$clean = array_values( $clean );
		if ( array() === $clean ) {
			return 0;
		}

		$result = Transaction::run(
			static function () use ( $clean ) {
				( new DeliveryCanceller() )->cancel_for_submissions( $clean );
				( new IdempotencyRepository() )->delete_for_submissions( $clean );

				$deleted = ( new SubmissionRepository() )->delete_ids( $clean );
				if ( false === $deleted ) {
					return new WP_Error(
						'formstead_submission_delete_failed',
						__( 'The submissions could not be deleted.', 'formstead' )
					);
				}

				return $deleted;
			}
		);

		if ( $result instanceof WP_Error ) {
			return $result;
		}

		UnreadCounter::forget();
		return is_int( $result ) ? $result : 0;
	}
}
