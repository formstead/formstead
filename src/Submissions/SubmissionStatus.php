<?php
/**
 * Inbox status names.
 *
 * @package Formstead
 */

namespace Formstead\Submissions;

/**
 * Status values stored on a submission row.
 */
final class SubmissionStatus {

	/**
	 * Not opened yet.
	 */
	public const NEW = 'new';

	/**
	 * Opened, or moved back into the inbox.
	 */
	public const READ = 'read';

	/**
	 * Held out of the inbox by a person or by a spam signal.
	 */
	public const SPAM = 'spam';

	/**
	 * Kept, but hidden from the inbox.
	 */
	public const ARCHIVED = 'archived';

	/**
	 * Whether this value can be stored.
	 *
	 * @param string $status Candidate status.
	 */
	public static function is_known( string $status ): bool {
		return in_array( $status, self::all(), true );
	}

	/**
	 * Every stored status.
	 *
	 * @return list<string>
	 */
	public static function all(): array {
		return array(
			self::NEW,
			self::READ,
			self::SPAM,
			self::ARCHIVED,
		);
	}

	/**
	 * Status text for the inbox.
	 *
	 * @param string $status Stored status.
	 */
	public static function label( string $status ): string {
		return match ( $status ) {
			self::NEW => __( 'Unread', 'formstead' ),
			self::READ => __( 'Read', 'formstead' ),
			self::SPAM => __( 'Spam', 'formstead' ),
			self::ARCHIVED => __( 'Archived', 'formstead' ),
			default => $status,
		};
	}
}
