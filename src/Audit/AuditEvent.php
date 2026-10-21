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
	 * Whether this code is one the log accepts.
	 *
	 * @param string $event Event code.
	 */
	public static function is_known( string $event ): bool {
		return in_array(
			$event,
			array(
				self::FORM_CREATED,
				self::FORM_UPDATED,
				self::SETTINGS_UPDATED,
				self::SUBMISSIONS_EXPORTED,
				self::SUBMISSIONS_DELETED,
				self::RETENTION_RUN,
			),
			true
		);
	}
}
