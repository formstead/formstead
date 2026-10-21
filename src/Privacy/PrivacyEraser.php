<?php
/**
 * WordPress personal-data eraser.
 *
 * @package Formstead
 */

namespace Formstead\Privacy;

use Formstead\Audit\AuditEvent;
use Formstead\Audit\AuditLog;
use Formstead\Submissions\SubmissionDeleter;
use WP_Error;

/**
 * Deletes every submission that belongs to one email address.
 *
 * Each call removes the first page. The previous page is already gone, so
 * the offset stays at zero. Access is the core `erase_others_personal_data`
 * capability, checked by WordPress before this callback runs.
 */
final class PrivacyEraser {

	/**
	 * Register the eraser.
	 */
	public static function register(): void {
		add_filter( 'wp_privacy_personal_data_erasers', array( self::class, 'add_eraser' ) );
	}

	/**
	 * Add this eraser to the list WordPress already has.
	 *
	 * @param mixed $erasers Registered erasers.
	 * @return array<array-key, mixed>
	 */
	public static function add_eraser( mixed $erasers ): array {
		if ( ! is_array( $erasers ) ) {
			$erasers = array();
		}

		$erasers['formstead'] = array(
			'eraser_friendly_name' => __( 'Formstead submissions', 'formstead' ),
			'callback'             => array( self::class, 'erase' ),
		);

		return $erasers;
	}

	/**
	 * Delete one page of submissions for an address.
	 *
	 * The page argument is accepted because WordPress sends it. This eraser
	 * always reads the first remaining page.
	 *
	 * @param string $email_address Address WordPress is erasing.
	 * @param int    $page          Page number WordPress is requesting. Unused.
	 * @return array{items_removed: bool, items_retained: bool, messages: list<string>, done: bool}|WP_Error
	 */
	public static function erase( string $email_address, int $page = 1 ): array|WP_Error {
		unset( $page );

		$pending = self::pending_error();
		if ( $pending instanceof WP_Error ) {
			return $pending;
		}

		$ids = self::submission_ids( $email_address );
		if ( array() === $ids ) {
			return self::result( false, true );
		}

		$deleted = ( new SubmissionDeleter() )->delete( $ids );
		if ( $deleted instanceof WP_Error ) {
			return $deleted;
		}

		AuditLog::record(
			AuditEvent::PRIVACY_ERASED,
			'privacy',
			0,
			array( 'count' => $deleted )
		);

		return self::result( $deleted > 0, count( $ids ) < PrivacyExporter::PAGE_SIZE );
	}

	/**
	 * The shape WordPress expects from an eraser.
	 *
	 * @param bool $removed Whether this page deleted anything.
	 * @param bool $done    Whether no further page remains.
	 * @return array{items_removed: bool, items_retained: bool, messages: list<string>, done: bool}
	 */
	private static function result( bool $removed, bool $done ): array {
		return array(
			'items_removed'  => $removed,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => $done,
		);
	}

	/**
	 * Error when the index is not finished, or null when erasure can run.
	 */
	private static function pending_error(): ?WP_Error {
		if ( ! IdentityBackfill::is_pending() ) {
			return null;
		}

		return new WP_Error(
			'formstead_identity_index_pending',
			sprintf(
				/* translators: %s: WP-CLI command. */
				__( 'The identity index is still being built. Run %s to finish it before exporting or erasing personal data.', 'formstead' ),
				'wp formstead identities rebuild'
			)
		);
	}

	/**
	 * The first page of submission ids for this address.
	 *
	 * @param string $email Address to match.
	 * @return list<int>
	 */
	private static function submission_ids( string $email ): array {
		$hash = IdentityHasher::hash( $email );
		if ( ! is_string( $hash ) ) {
			return array();
		}

		return ( new IdentityRepository() )->submission_ids_for_hash( $hash, PrivacyExporter::PAGE_SIZE, 0 );
	}
}
