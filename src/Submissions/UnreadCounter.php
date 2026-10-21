<?php
/**
 * Count of unread submissions for the admin menu.
 *
 * @package Formstead
 */

namespace Formstead\Submissions;

/**
 * Caches the number of submissions still in the `new` status.
 *
 * The cache is dropped when a submission is stored, its status changes, or it
 * is deleted. A short lifetime covers a missed drop.
 */
final class UnreadCounter {

	/**
	 * Transient key.
	 */
	private const KEY = 'formstead_unread_count';

	/**
	 * How long a cached count is kept, in seconds.
	 */
	private const TTL = 60;

	/**
	 * Unread submissions across every form.
	 */
	public static function count(): int {
		$cached = get_transient( self::KEY );
		if ( is_int( $cached ) ) {
			return $cached;
		}
		if ( is_string( $cached ) && is_numeric( $cached ) ) {
			return (int) $cached;
		}

		$count = ( new SubmissionRepository() )->count_with_status( SubmissionStatus::NEW );
		set_transient( self::KEY, $count, self::TTL );

		return $count;
	}

	/**
	 * Drop the cached count.
	 */
	public static function forget(): void {
		delete_transient( self::KEY );
	}
}
