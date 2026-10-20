<?php
/**
 * Schema installation and upgrades.
 *
 * @package Formstead
 */

namespace Formstead\Database;

use Formstead\Security\Capabilities;
use WP_Error;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- dbDelta and SHOW TABLES cannot use placeholders for identifiers.

/**
 * Creates and upgrades Formstead tables.
 *
 * Activation calls `run()` directly. Updates call `maybe_migrate()`, because
 * the activation hook does not fire when the plugin files are replaced.
 */
final class Migrator {

	/**
	 * How long a migration lock is honored before another request may take it.
	 */
	public const LOCK_TTL = 600;

	/**
	 * Migrate when the stored schema version is behind the code.
	 */
	public static function maybe_migrate(): ?WP_Error {
		$installed = (int) get_option( Schema::VERSION_OPTION, 0 );

		if ( $installed >= Schema::VERSION ) {
			return null;
		}

		return self::run();
	}

	/**
	 * Create or update tables, grant capabilities, and store the schema version.
	 *
	 * Returns an error without the database message. The schema version is
	 * stored only after both tables exist.
	 */
	public static function run(): ?WP_Error {
		if ( ! self::acquire_lock() ) {
			return new WP_Error(
				'formstead_migration_locked',
				__( 'Formstead is already updating its database. Reload this screen in a moment.', 'formstead' )
			);
		}

		try {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';

			foreach ( Schema::statements() as $statement ) {
				dbDelta( $statement );
			}

			if ( ! self::tables_exist() ) {
				return new WP_Error(
					'formstead_migration_tables',
					__( 'Formstead could not create its database tables.', 'formstead' )
				);
			}

			Capabilities::grant_to_administrator();
			update_option( Schema::VERSION_OPTION, Schema::VERSION, true );

			return null;
		} finally {
			self::release_lock();
		}
	}

	/**
	 * Whether both Formstead tables are present.
	 */
	public static function tables_exist(): bool {
		return self::table_exists( Schema::forms_table() ) && self::table_exists( Schema::submissions_table() );
	}

	/**
	 * Take the migration lock, or take over a lock older than LOCK_TTL.
	 *
	 * `add_option()` is atomic because `option_name` is unique.
	 */
	private static function acquire_lock(): bool {
		$now = time();

		// The deprecated third argument must be passed to set autoload.
		if ( add_option( Schema::LOCK_OPTION, $now, '', false ) ) {
			return true;
		}

		$existing = get_option( Schema::LOCK_OPTION, 0 );
		if ( is_numeric( $existing ) && ( $now - (int) $existing ) < self::LOCK_TTL ) {
			return false;
		}

		delete_option( Schema::LOCK_OPTION );

		return add_option( Schema::LOCK_OPTION, $now, '', false );
	}

	/**
	 * Release the migration lock.
	 */
	private static function release_lock(): void {
		delete_option( Schema::LOCK_OPTION );
	}

	/**
	 * Whether one table is listed by SHOW TABLES.
	 *
	 * Underscores in the prefix are escaped because LIKE treats them as wildcards.
	 *
	 * @param string $table Full table name, including the site prefix.
	 */
	private static function table_exists( string $table ): bool {
		global $wpdb;

		$found = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) )
		);

		return $found === $table;
	}
}
