<?php
/**
 * Plugin capabilities.
 *
 * @package Formstead
 */

namespace Formstead\Security;

use WP_Role;

/**
 * Capabilities granted to administrators on activation and migration.
 *
 * Deactivation does not remove them.
 */
final class Capabilities {

	public const MANAGE_FORMS = 'formstead_manage_forms';

	public const READ_SUBMISSIONS = 'formstead_read_submissions';

	public const EXPORT_SUBMISSIONS = 'formstead_export_submissions';

	public const DELETE_SUBMISSIONS = 'formstead_delete_submissions';

	/**
	 * Every capability this plugin registers.
	 *
	 * @return list<string>
	 */
	public static function all(): array {
		return array(
			self::MANAGE_FORMS,
			self::READ_SUBMISSIONS,
			self::EXPORT_SUBMISSIONS,
			self::DELETE_SUBMISSIONS,
		);
	}

	/**
	 * Grant every Formstead capability to the administrator role.
	 */
	public static function grant_to_administrator(): void {
		$role = get_role( 'administrator' );

		if ( ! $role instanceof WP_Role ) {
			return;
		}

		foreach ( self::all() as $capability ) {
			$role->add_cap( $capability );
		}
	}
}
