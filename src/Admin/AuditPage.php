<?php
/**
 * Audit log screen.
 *
 * @package Formstead
 */

namespace Formstead\Admin;

use Formstead\Audit\AuditEvent;
use Formstead\Security\Capabilities;
use Formstead\Submissions\SubmissionQuery;

/**
 * Lists audit events for someone who can manage forms.
 *
 * The capability is checked here, not only on the menu item. The screen
 * only reads. Filters travel in the query string, the same way the inbox does.
 */
final class AuditPage {

	/**
	 * Render the log, or refuse the request.
	 */
	public static function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE_FORMS ) ) {
			wp_die(
				esc_html__( 'Sorry, you are not allowed to view the audit log.', 'formstead' ),
				'',
				array( 'response' => 403 )
			);
		}

		$event     = self::event();
		$date_from = SubmissionQuery::date( self::query_text( 'date_from' ) );
		$date_to   = SubmissionQuery::date( self::query_text( 'date_to' ) );
		$paged     = max( 1, self::query_int( 'paged' ) );
		$table     = new AuditTable( $event, $date_from, $date_to, $paged );
		$table->prepare_items();

		include dirname( FORMSTEAD_FILE ) . '/templates/admin/audit-list.php';
	}

	/**
	 * Known event code from the query, or an empty string for every event.
	 */
	private static function event(): string {
		$event = self::query_text( 'event' );
		return AuditEvent::is_known( $event ) ? $event : '';
	}

	/**
	 * One query value, already unslashed.
	 *
	 * @param string $key Query key.
	 */
	private static function query_text( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading the screen query, not a submission.
		if ( ! isset( $_GET[ $key ] ) ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading the screen query, not a submission.
		return sanitize_text_field( wp_unslash( $_GET[ $key ] ) );
	}

	/**
	 * One integer query value.
	 *
	 * @param string $key Query key.
	 */
	private static function query_int( string $key ): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading the screen query, not a submission.
		if ( ! isset( $_GET[ $key ] ) ) {
			return 0;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading the screen query, not a submission.
		return absint( wp_unslash( $_GET[ $key ] ) );
	}
}
