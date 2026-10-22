<?php
/**
 * Schema installation and upgrades.
 *
 * @package Formstead
 */

namespace Formstead\Database;

use Formstead\Forms\Field;
use Formstead\Forms\FieldType;
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
	 * Option that records an identity-index backfill still to run.
	 *
	 * Absent when there is nothing to index. Not autoloaded. The value is
	 * `status`, `after_id`, and `until_id`. The migration stores `pending`
	 * and does not index submissions itself.
	 */
	public const IDENTITY_BACKFILL_OPTION = 'formstead_identity_backfill';

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

			// dbDelta can continue after a failed ALTER, clearing last_error later.
			// A read-only second pass must have no remaining schema changes.
			foreach ( Schema::statements() as $statement ) {
				if ( array() !== dbDelta( $statement, false ) ) {
					return new WP_Error(
						'formstead_migration_tables',
						__( 'Formstead could not create its database tables.', 'formstead' )
					);
				}
			}

			if ( $installed < 2 ) {
				$upgraded = self::upgrade_to_2();
				if ( $upgraded instanceof WP_Error ) {
					return $upgraded;
				}
			}

			if ( $installed > 0 && $installed < 7 ) {
				$upgraded = self::upgrade_to_7();
				if ( $upgraded instanceof WP_Error ) {
					return $upgraded;
				}
			}

			if ( $installed > 0 && $installed < 8 ) {
				self::upgrade_to_8();
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

			if ( ! is_array( $rows ) || '' !== (string) $wpdb->last_error ) {
				return new WP_Error(
					'formstead_migration_backfill',
					__( 'Formstead could not update its database.', 'formstead' )
				);
			}
			if ( array() === $rows ) {
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
				// get_var() can change last_error despite its inferred earlier value.
				/**
				 * Current database error after the lookup.
				 *
				 * @var string $read_error
				 */
				$read_error = $wpdb->last_error;
				if ( '' !== $read_error ) {
					return new WP_Error(
						'formstead_migration_backfill',
						__( 'Formstead could not update its database.', 'formstead' )
					);
				}
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

			if ( ! is_array( $rows ) || '' !== (string) $wpdb->last_error ) {
				return new WP_Error(
					'formstead_migration_backfill',
					__( 'Formstead could not update its database.', 'formstead' )
				);
			}
			if ( array() === $rows ) {
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
	 * Schema version 8 adds webhook columns.
	 *
	 * The columns come from dbDelta(). Existing forms are left without a URL
	 * or a secret, so this step does not generate either and does not backfill
	 * event ids. Email deliveries keep a null event id.
	 */
	private static function upgrade_to_8(): void {
		// Columns are already present. Nothing else is migrated.
	}

	/**
	 * Mark existing email fields as identifying and request an index backfill.
	 *
	 * Submissions are not indexed here. A large inbox would hold the request
	 * that ran the migration. The backfill option is stored only when at
	 * least one email field was marked and at least one submission exists.
	 */
	private static function upgrade_to_7(): ?WP_Error {
		global $wpdb;

		$forms   = Schema::forms_table();
		$last_id = 0;
		$marked  = false;

		do {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is an identifier.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, fields FROM {$forms} WHERE id > %d ORDER BY id ASC LIMIT %d",
					$last_id,
					self::BATCH
				),
				ARRAY_A
			);

			if ( ! is_array( $rows ) || '' !== (string) $wpdb->last_error ) {
				return new WP_Error(
					'formstead_migration_backfill',
					__( 'Formstead could not update its database.', 'formstead' )
				);
			}

			if ( array() === $rows ) {
				break;
			}

			foreach ( $rows as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}

				$form_id = (int) ( $row['id'] ?? 0 );
				$last_id = $form_id;
				if ( $form_id < 1 ) {
					continue;
				}

				$names = self::email_field_names( (string) ( $row['fields'] ?? '' ) );
				$json  = wp_json_encode( $names );
				if ( ! is_string( $json ) ) {
					return new WP_Error(
						'formstead_migration_backfill',
						__( 'Formstead could not update its database.', 'formstead' )
					);
				}

				$saved = $wpdb->update(
					$forms,
					array( 'identity_fields' => $json ),
					array( 'id' => $form_id ),
					array( '%s' ),
					array( '%d' )
				);
				if ( false === $saved ) {
					return new WP_Error(
						'formstead_migration_backfill',
						__( 'Formstead could not update its database.', 'formstead' )
					);
				}

				if ( array() !== $names ) {
					$marked = true;
				}
			}

			$batch = count( $rows );
		} while ( self::BATCH === $batch );

		if ( ! $marked ) {
			return null;
		}

		return self::request_identity_backfill();
	}

	/**
	 * Email field names stored in a form's fields JSON.
	 *
	 * A text field is not identifying, even when its name looks like an address.
	 * Unreadable JSON marks nothing.
	 *
	 * @param string $fields_json Stored fields.
	 * @return list<string>
	 */
	private static function email_field_names( string $fields_json ): array {
		$decoded = json_decode( $fields_json, true );
		if ( ! is_array( $decoded ) ) {
			return array();
		}

		$names = array();
		foreach ( $decoded as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$field = Field::from_array( $row );
			if ( ! $field instanceof Field || FieldType::Email !== $field->type() ) {
				continue;
			}

			$names[ $field->name() ] = $field->name();
		}

		return array_values( $names );
	}

	/**
	 * Store a pending identity backfill when submissions already exist.
	 */
	private static function request_identity_backfill(): ?WP_Error {
		global $wpdb;

		$submissions = Schema::submissions_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is an identifier.
		$until = $wpdb->get_var( "SELECT MAX(id) FROM {$submissions}" );
		if ( '' !== (string) $wpdb->last_error ) {
			return new WP_Error(
				'formstead_migration_backfill',
				__( 'Formstead could not update its database.', 'formstead' )
			);
		}

		$until_id = is_numeric( $until ) ? (int) $until : 0;
		if ( $until_id < 1 ) {
			return null;
		}

		$cursor = array(
			'status'   => 'pending',
			'after_id' => 0,
			'until_id' => $until_id,
		);
		update_option( self::IDENTITY_BACKFILL_OPTION, $cursor, false );

		$stored = get_option( self::IDENTITY_BACKFILL_OPTION );
		if ( ! is_array( $stored ) || ( $stored['until_id'] ?? null ) !== $until_id || 'pending' !== ( $stored['status'] ?? '' ) ) {
			return new WP_Error(
				'formstead_migration_backfill',
				__( 'Formstead could not update its database.', 'formstead' )
			);
		}

		return null;
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
