<?php
/**
 * Schema installation and upgrades.
 *
 * @package Formstead
 */

namespace Formstead\Database;

use Formstead\Security\Capabilities;
use Formstead\Submissions\ReceiptId;
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
	 * Rows updated in one backfill query batch.
	 */
	private const BATCH = 100;

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
	 * stored only after the tables exist and any backfill has finished.
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

			$installed = (int) get_option( Schema::VERSION_OPTION, 0 );

			foreach ( Schema::statements() as $statement ) {
				dbDelta( $statement );
			}

			if ( ! self::tables_exist() ) {
				return new WP_Error(
					'formstead_migration_tables',
					__( 'Formstead could not create its database tables.', 'formstead' )
				);
			}

			$engine = self::storage_engine_error();
			if ( $engine instanceof WP_Error ) {
				return $engine;
			}

			if ( $installed < 2 ) {
				$upgraded = self::upgrade_to_2();
				if ( $upgraded instanceof WP_Error ) {
					return $upgraded;
				}
			}

			Capabilities::grant_to_administrator();
			update_option( Schema::VERSION_OPTION, Schema::VERSION, true );

			return null;
		} finally {
			self::release_lock();
		}
	}

	/**
	 * Whether every Formstead table is present.
	 */
	public static function tables_exist(): bool {
		foreach ( Schema::tables() as $table ) {
			if ( ! self::table_exists( $table ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * An error when any Formstead table is not InnoDB.
	 *
	 * Called after dbDelta(). A server that ignores `ENGINE=InnoDB` must not
	 * record the schema as ready, because deliveries need a real transaction.
	 */
	public static function storage_engine_error(): ?WP_Error {
		global $wpdb;

		foreach ( Schema::tables() as $table ) {
			$engine = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
					$table
				)
			);

			if ( ! is_string( $engine ) || 'innodb' !== strtolower( $engine ) ) {
				return new WP_Error(
					'formstead_migration_engine',
					__( 'Formstead requires the InnoDB storage engine.', 'formstead' )
				);
			}
		}

		return null;
	}

	/**
	 * Backfill schema snapshots and receipt ids added in schema version 2.
	 *
	 * The schema version option stays unchanged until this finishes, so a
	 * failed batch runs again on the next request.
	 */
	private static function upgrade_to_2(): ?WP_Error {
		$snapshots = self::backfill_snapshots();
		if ( $snapshots instanceof WP_Error ) {
			return $snapshots;
		}

		return self::backfill_receipts();
	}

	/**
	 * Store the current field schema of every form that has no snapshot yet.
	 *
	 * Older schema versions from 0.1.x were not kept, so they cannot be rebuilt.
	 */
	private static function backfill_snapshots(): ?WP_Error {
		global $wpdb;

		$forms    = Schema::forms_table();
		$versions = Schema::form_versions_table();
		$last_id  = 0;

		do {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names are identifiers.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, schema_version, fields, updated_at FROM {$forms} WHERE id > %d ORDER BY id ASC LIMIT %d",
					$last_id,
					self::BATCH
				),
				ARRAY_A
			);

			if ( ! is_array( $rows ) || array() === $rows ) {
				return null;
			}

			foreach ( $rows as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}

				$form_id = (int) ( $row['id'] ?? 0 );
				$version = (int) ( $row['schema_version'] ?? 0 );
				$last_id = $form_id;
				if ( $form_id < 1 || $version < 1 ) {
					continue;
				}

				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is an identifier.
				$exists = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT id FROM {$versions} WHERE form_id = %d AND version = %d",
						$form_id,
						$version
					)
				);
				if ( null !== $exists ) {
					continue;
				}

				$previous  = $wpdb->suppress_errors( true );
				$saved     = $wpdb->insert(
					$versions,
					array(
						'form_id'    => $form_id,
						'version'    => $version,
						'fields'     => (string) ( $row['fields'] ?? '[]' ),
						'created_at' => (string) ( $row['updated_at'] ?? current_time( 'mysql', true ) ),
					),
					array( '%d', '%d', '%s', '%s' )
				);
				$duplicate = false !== stripos( (string) $wpdb->last_error, 'Duplicate' );
				$wpdb->suppress_errors( $previous );

				if ( false === $saved && ! $duplicate ) {
					return new WP_Error(
						'formstead_migration_backfill',
						__( 'Formstead could not update its database.', 'formstead' )
					);
				}
			}

			$batch = count( $rows );
		} while ( self::BATCH === $batch );

		return null;
	}

	/**
	 * Assign a receipt id to every submission that does not have one.
	 */
	private static function backfill_receipts(): ?WP_Error {
		global $wpdb;

		$table = Schema::submissions_table();

		do {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is an identifier.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id FROM {$table} WHERE receipt_id IS NULL ORDER BY id ASC LIMIT %d",
					self::BATCH
				),
				ARRAY_A
			);

			if ( ! is_array( $rows ) || array() === $rows ) {
				return null;
			}

			foreach ( $rows as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}

				$assigned = self::assign_receipt( (int) ( $row['id'] ?? 0 ) );
				if ( $assigned instanceof WP_Error ) {
					return $assigned;
				}
			}

			$batch = count( $rows );
		} while ( self::BATCH === $batch );

		return null;
	}

	/**
	 * Set one receipt id, retrying when the generated value collides.
	 *
	 * @param int $submission_id Submission id.
	 */
	private static function assign_receipt( int $submission_id ): ?WP_Error {
		global $wpdb;

		if ( $submission_id < 1 ) {
			return null;
		}

		$table = Schema::submissions_table();

		for ( $attempt = 0; $attempt < 3; $attempt++ ) {
			$previous = $wpdb->suppress_errors( true );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is an identifier.
			$saved     = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET receipt_id = %s WHERE id = %d AND receipt_id IS NULL",
					ReceiptId::generate(),
					$submission_id
				)
			);
			$duplicate = false !== stripos( (string) $wpdb->last_error, 'Duplicate' );
			$wpdb->suppress_errors( $previous );

			if ( false === $saved ) {
				if ( $duplicate ) {
					continue;
				}

				return new WP_Error(
					'formstead_migration_backfill',
					__( 'Formstead could not update its database.', 'formstead' )
				);
			}

			return null;
		}

		return new WP_Error(
			'formstead_migration_backfill',
			__( 'Formstead could not update its database.', 'formstead' )
		);
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
