<?php
/**
 * Opt-in removal of Formstead data.
 *
 * @package Formstead
 */

namespace Formstead\Lifecycle;

use Formstead\Database\Schema;
use Formstead\Delivery\Scheduler;
use Formstead\Privacy\IdentityBackfill;
use Formstead\Privacy\PrivacyExportPages;
use Formstead\Retention\RetentionScheduler;
use Formstead\Security\Capabilities;
use WP_Role;
use WP_Roles;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- uninstall drops plugin tables. Option names are filtered in PHP.

/**
 * Deletes Formstead tables, options, and capabilities when a site opted in.
 *
 * Dropping the tables does not go through the submission deleter. The cron
 * hooks are cleared first, and the delivery rows disappear with their table,
 * so there is nothing left to send. Each site in a network is checked on its
 * own. Switching sites does not reload roles, so the wipe loads that site's
 * roles before removing capabilities. A site that did not opt in keeps its data.
 */
final class Uninstaller {

	/**
	 * Option that opts a site into deleting its data. Absent by default.
	 */
	public const OPTION = 'formstead_delete_data_on_uninstall';

	/**
	 * Delete data on every site that opted in.
	 */
	public static function wipe(): void {
		if ( ! is_multisite() ) {
			self::wipe_site();
			return;
		}

		$offset = 0;
		do {
			$ids = get_sites(
				array(
					'fields'  => 'ids',
					'number'  => 100,
					'offset'  => $offset,
					'orderby' => 'id',
					'order'   => 'ASC',
				)
			);
			if ( ! is_array( $ids ) || array() === $ids ) {
				break;
			}

			foreach ( $ids as $site_id ) {
				$site_id = (int) $site_id;
				switch_to_blog( $site_id );
				wp_roles()->for_site( $site_id );
				self::wipe_site();
				restore_current_blog();
			}

			$count   = count( $ids );
			$offset += 100;
		} while ( 100 === $count );

		wp_roles()->for_site( get_current_blog_id() );
	}

	/**
	 * Whether this site asked to delete its data on uninstall.
	 */
	public static function enabled(): bool {
		$value = get_option( self::OPTION, false );
		return true === $value || 1 === $value || '1' === $value;
	}

	/**
	 * Delete this site's Formstead data when it opted in.
	 */
	private static function wipe_site(): void {
		if ( ! self::enabled() ) {
			return;
		}

		RetentionScheduler::clear();
		Scheduler::clear();
		IdentityBackfill::clear();
		delete_post_meta_by_key( PrivacyExportPages::META_KEY );
		self::drop_tables();
		self::delete_options();
		self::delete_capabilities();
	}

	/**
	 * Drop every Formstead table.
	 */
	private static function drop_tables(): void {
		global $wpdb;

		foreach ( Schema::tables() as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		}
	}

	/**
	 * Delete Formstead options and transients.
	 */
	private static function delete_options(): void {
		global $wpdb;

		$names = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '%formstead%'" );
		if ( ! is_array( $names ) ) {
			return;
		}

		foreach ( $names as $name ) {
			if ( ! is_string( $name ) ) {
				continue;
			}

			if ( str_starts_with( $name, '_transient_timeout_' ) ) {
				continue;
			}

			if ( str_starts_with( $name, '_transient_formstead_' ) ) {
				delete_transient( substr( $name, strlen( '_transient_' ) ) );
				continue;
			}

			if ( str_starts_with( $name, 'formstead_' ) ) {
				delete_option( $name );
			}
		}
	}

	/**
	 * Remove Formstead capabilities from every role.
	 */
	private static function delete_capabilities(): void {
		$roles = wp_roles();
		if ( ! $roles instanceof WP_Roles ) {
			return;
		}

		foreach ( array_keys( $roles->roles ) as $name ) {
			$role = get_role( (string) $name );
			if ( ! $role instanceof WP_Role ) {
				continue;
			}

			foreach ( Capabilities::all() as $capability ) {
				$role->remove_cap( $capability );
			}
		}
	}
}
