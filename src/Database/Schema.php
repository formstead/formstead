<?php
/**
 * Database schema version 2.
 *
 * @package Formstead
 */

namespace Formstead\Database;

/**
 * Table names and CREATE TABLE statements for dbDelta().
 *
 * Column types keep the display widths WordPress stores (`bigint(20) unsigned`,
 * `int(10) unsigned`, `tinyint(1)`). A second dbDelta() call must be a no-op.
 */
final class Schema {

	public const VERSION = 2;

	public const VERSION_OPTION = 'formstead_db_version';

	public const LOCK_OPTION = 'formstead_migration_lock';

	/**
	 * Forms table, including the site prefix.
	 */
	public static function forms_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'formstead_forms';
	}

	/**
	 * Submissions table, including the site prefix.
	 */
	public static function submissions_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'formstead_submissions';
	}

	/**
	 * Immutable form-schema snapshots, including the site prefix.
	 */
	public static function form_versions_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'formstead_form_versions';
	}

	/**
	 * CREATE TABLE statements, one per table.
	 *
	 * Table names are identifiers, so they cannot be placeholders.
	 *
	 * @return list<string>
	 */
	public static function statements(): array {
		global $wpdb;

		$charset     = $wpdb->get_charset_collate();
		$forms       = self::forms_table();
		$versions    = self::form_versions_table();
		$submissions = self::submissions_table();

		// Table names are identifiers, so they cannot be placeholders.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array(
			"CREATE TABLE {$forms} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				public_id varchar(19) NOT NULL,
				name varchar(190) NOT NULL,
				is_enabled tinyint(1) NOT NULL DEFAULT 1,
				fields longtext NOT NULL,
				schema_version int(10) unsigned NOT NULL DEFAULT 1,
				success_url varchar(2048) NOT NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY public_id (public_id)
			) {$charset} ENGINE=InnoDB;",
			"CREATE TABLE {$versions} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				form_id bigint(20) unsigned NOT NULL,
				version int(10) unsigned NOT NULL,
				fields longtext NOT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY form_version (form_id,version)
			) {$charset} ENGINE=InnoDB;",
			"CREATE TABLE {$submissions} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				form_id bigint(20) unsigned NOT NULL,
				schema_version int(10) unsigned NOT NULL,
				receipt_id varchar(20) DEFAULT NULL,
				payload longtext NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'new',
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY receipt_id (receipt_id),
				KEY form_status_created (form_id,status,created_at)
			) {$charset} ENGINE=InnoDB;",
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
