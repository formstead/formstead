<?php
/**
 * Stable codes for the audit log.
 *
 * @package Formstead
 */

namespace Formstead\Audit;

/**
 * Event names stored in `formstead_audit`. The values do not change.
 */
final class AuditEvent {

	public const FORM_CREATED = 'form_created';

	public const FORM_UPDATED = 'form_updated';

	public const SETTINGS_UPDATED = 'settings_updated';

	public const SUBMISSIONS_EXPORTED = 'submissions_exported';

	public const SUBMISSIONS_DELETED = 'submissions_deleted';

	public const RETENTION_RUN = 'retention_run';

	/**
	 * Codes the log accepts, in a stable order.
	 *
	 * @return list<string>
	 */
	public static function codes(): array {
		return array(
			self::FORM_CREATED,
			self::FORM_UPDATED,
			self::SETTINGS_UPDATED,
			self::SUBMISSIONS_EXPORTED,
			self::SUBMISSIONS_DELETED,
			self::RETENTION_RUN,
		);
	}

	/**
	 * Whether this code is one the log accepts.
	 *
	 * @param string $event Event code.
	 */
	public static function is_known( string $event ): bool {
		return in_array( $event, self::codes(), true );
	}

	/**
	 * Translated name of a known code, or an empty string.
	 *
	 * @param string $event Event code.
	 */
	public static function label( string $event ): string {
		switch ( $event ) {
			case self::FORM_CREATED:
				return __( 'Form created', 'formstead' );
			case self::FORM_UPDATED:
				return __( 'Form updated', 'formstead' );
			case self::SETTINGS_UPDATED:
				return __( 'Settings updated', 'formstead' );
			case self::SUBMISSIONS_EXPORTED:
				return __( 'Submissions exported', 'formstead' );
			case self::SUBMISSIONS_DELETED:
				return __( 'Submissions deleted', 'formstead' );
			case self::RETENTION_RUN:
				return __( 'Retention run', 'formstead' );
			default:
				return '';
		}
	}
}
