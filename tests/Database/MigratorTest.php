<?php
/**
 * Tests for schema installation.
 *
 * @package Formstead
 */

namespace Formstead\Tests\Database;

use Formstead\Database\Migrator;
use Formstead\Database\Schema;
use Formstead\Database\Transaction;
use Formstead\Lifecycle\Activator;
use Formstead\Security\Capabilities;
use Formstead\Tests\TestCase\MigrationTestCase;
use WP_Error;
use WP_Role;

/**
 * Covers installation, the migration lock, and capability grants.
 *
 * @group schema
 *
 * @phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
 * @phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
 * @phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange
 * @phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
 */
final class MigratorTest extends MigrationTestCase {

	/**
	 * A fresh install creates the tables and stores the current schema version.
	 */
	public function test_fresh_install_creates_tables_and_version(): void {
		$this->assertNull( Migrator::run() );
		$this->assertSame( Schema::VERSION, (int) get_option( Schema::VERSION_OPTION ) );
		$this->assertTrue( Migrator::tables_exist() );
	}

	/**
	 * The second dbDelta() call changes nothing.
	 */
	public function test_second_dbdelta_is_a_noop(): void {
		$this->assertNull( Migrator::run() );

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$changes = array();
		foreach ( Schema::statements() as $statement ) {
			$changes = array_merge( $changes, dbDelta( $statement ) );
		}

		$this->assertSame( array(), $changes );
	}

	/**
	 * An up-to-date schema does not issue DDL.
	 */
	public function test_maybe_migrate_skips_ddl_when_current(): void {
		$this->assertNull( Migrator::run() );

		$seen = array();
		$spy  = static function ( $sql ) use ( &$seen ) {
			$seen[] = $sql;
			return $sql;
		};

		add_filter( 'query', $spy );
		$this->assertNull( Migrator::maybe_migrate() );
		remove_filter( 'query', $spy );

		foreach ( $seen as $sql ) {
			$this->assertStringNotContainsString( 'CREATE TABLE', $sql );
			$this->assertStringNotContainsString( 'ALTER TABLE', $sql );
		}
	}

	/**
	 * A lock younger than the TTL blocks migration and leaves the version unset.
	 */
	public function test_held_lock_blocks_migration(): void {
		add_option( Schema::LOCK_OPTION, time(), '', false );

		$error = Migrator::maybe_migrate();

		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( 'formstead_migration_locked', $error->get_error_code() );
		$this->assertSame( 0, (int) get_option( Schema::VERSION_OPTION, 0 ) );
		$this->assertFalse( Migrator::tables_exist() );
	}

	/**
	 * A lock older than the TTL is replaced and migration finishes.
	 */
	public function test_stale_lock_is_taken_over(): void {
		add_option( Schema::LOCK_OPTION, time() - ( Migrator::LOCK_TTL + 1 ), '', false );

		$this->assertNull( Migrator::maybe_migrate() );
		$this->assertSame( Schema::VERSION, (int) get_option( Schema::VERSION_OPTION ) );
		$this->assertTrue( Migrator::tables_exist() );
		$this->assertFalse( get_option( Schema::LOCK_OPTION ) );
	}

	/**
	 * Migration grants the four Formstead capabilities to administrators.
	 */
	public function test_migration_grants_administrator_capabilities(): void {
		$role = get_role( 'administrator' );
		$this->assertInstanceOf( WP_Role::class, $role );

		foreach ( Capabilities::all() as $capability ) {
			$role->remove_cap( $capability );
		}

		$this->assertNull( Migrator::run() );

		foreach ( Capabilities::all() as $capability ) {
			$this->assertTrue( $role->has_cap( $capability ) );
		}
	}

	/**
	 * An install that is still on schema version 1 gains snapshots and receipt ids.
	 */
	public function test_upgrade_from_version_1_backfills_snapshots_and_receipts(): void {
		global $wpdb;

		$forms       = Schema::forms_table();
		$submissions = Schema::submissions_table();
		$versions    = Schema::form_versions_table();
		$limits      = Schema::rate_limits_table();
		$charset     = $wpdb->get_charset_collate();

		$wpdb->query( "DROP TABLE IF EXISTS {$limits}" );
		$wpdb->query( "DROP TABLE IF EXISTS {$versions}" );
		$wpdb->query( "DROP TABLE IF EXISTS {$submissions}" );
		$wpdb->query( "DROP TABLE IF EXISTS {$forms}" );

		$wpdb->query(
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
			) {$charset} ENGINE=InnoDB"
		);
		$wpdb->query(
			"CREATE TABLE {$submissions} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				form_id bigint(20) unsigned NOT NULL,
				schema_version int(10) unsigned NOT NULL,
				payload longtext NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'new',
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY form_status_created (form_id,status,created_at)
			) {$charset} ENGINE=InnoDB"
		);

		$wpdb->insert(
			$forms,
			array(
				'public_id'      => 'fs_abcdefghijklmnop',
				'name'           => 'Contact',
				'is_enabled'     => 1,
				'fields'         => '[{"name":"email","label":"Email","type":"email","required":true,"max_length":255}]',
				'schema_version' => 2,
				'success_url'    => 'https://example.com/thanks',
				'created_at'     => '2026-10-20 10:00:00',
				'updated_at'     => '2026-10-20 11:00:00',
			)
		);
		$form_id = (int) $wpdb->insert_id;
		$wpdb->insert(
			$submissions,
			array(
				'form_id'        => $form_id,
				'schema_version' => 1,
				'payload'        => '{"email":"a@b.co"}',
				'status'         => 'new',
				'created_at'     => '2026-10-20 10:30:00',
			)
		);

		update_option( Schema::VERSION_OPTION, 1, true );

		$this->assertNull( Migrator::maybe_migrate() );
		$this->assertSame( Schema::VERSION, (int) get_option( Schema::VERSION_OPTION ) );
		$this->assertTrue( Migrator::tables_exist() );

		$snapshot = $wpdb->get_row(
			$wpdb->prepare( "SELECT version, fields FROM {$versions} WHERE form_id = %d", $form_id ),
			ARRAY_A
		);
		$this->assertIsArray( $snapshot );
		$this->assertSame( '2', (string) $snapshot['version'] );
		$this->assertStringContainsString( 'Email', (string) $snapshot['fields'] );

		$receipt = $wpdb->get_var( $wpdb->prepare( "SELECT receipt_id FROM {$submissions} WHERE form_id = %d", $form_id ) );
		$this->assertIsString( $receipt );
		$this->assertMatchesRegularExpression( '/^fsr_[a-z2-7]{16}$/', $receipt );

		$older = $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$versions} WHERE form_id = %d AND version = %d", $form_id, 1 )
		);
		$this->assertNull( $older );
	}

	/**
	 * Schema version 2 gains protection columns and the rate-limit table.
	 *
	 * Existing forms keep their rows. There is no backfill.
	 */
	public function test_upgrade_from_version_2_adds_protection_columns(): void {
		global $wpdb;

		$forms       = Schema::forms_table();
		$versions    = Schema::form_versions_table();
		$submissions = Schema::submissions_table();
		$limits      = Schema::rate_limits_table();
		$charset     = $wpdb->get_charset_collate();

		$wpdb->query( "DROP TABLE IF EXISTS {$limits}" );
		$wpdb->query( "DROP TABLE IF EXISTS {$versions}" );
		$wpdb->query( "DROP TABLE IF EXISTS {$submissions}" );
		$wpdb->query( "DROP TABLE IF EXISTS {$forms}" );

		$wpdb->query(
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
			) {$charset} ENGINE=InnoDB"
		);
		$wpdb->query(
			"CREATE TABLE {$versions} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				form_id bigint(20) unsigned NOT NULL,
				version int(10) unsigned NOT NULL,
				fields longtext NOT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY form_version (form_id,version)
			) {$charset} ENGINE=InnoDB"
		);
		$wpdb->query(
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
			) {$charset} ENGINE=InnoDB"
		);

		$wpdb->insert(
			$forms,
			array(
				'public_id'      => 'fs_abcdefghijklmnop',
				'name'           => 'Contact',
				'is_enabled'     => 1,
				'fields'         => '[]',
				'schema_version' => 1,
				'success_url'    => 'https://example.com/thanks',
				'created_at'     => '2026-10-20 10:00:00',
				'updated_at'     => '2026-10-20 11:00:00',
			)
		);

		update_option( Schema::VERSION_OPTION, 2, true );

		$this->assertNull( Migrator::maybe_migrate() );
		$this->assertSame( Schema::VERSION, (int) get_option( Schema::VERSION_OPTION ) );
		$this->assertTrue( Migrator::tables_exist() );

		$row = $wpdb->get_row( "SELECT allowed_origins, rate_per_minute, rate_per_hour, honeypot_rejections FROM {$forms}", ARRAY_A );
		$this->assertIsArray( $row );
		$this->assertNull( $row['allowed_origins'] );
		$this->assertNull( $row['rate_per_minute'] );
		$this->assertNull( $row['rate_per_hour'] );
		$this->assertSame( '0', (string) $row['honeypot_rejections'] );
	}

	/**
	 * Schema version 3 gains notification columns and the delivery tables.
	 *
	 * Existing forms keep an empty recipient list, so an upgrade does not start sending mail.
	 */
	public function test_upgrade_from_version_3_adds_notification_columns(): void {
		global $wpdb;

		$forms       = Schema::forms_table();
		$versions    = Schema::form_versions_table();
		$submissions = Schema::submissions_table();
		$limits      = Schema::rate_limits_table();
		$charset     = $wpdb->get_charset_collate();

		foreach ( Schema::tables() as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		}

		$wpdb->query(
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
				allowed_origins text,
				rate_per_minute smallint(5) unsigned DEFAULT NULL,
				rate_per_hour int(10) unsigned DEFAULT NULL,
				honeypot_rejections bigint(20) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				UNIQUE KEY public_id (public_id)
			) {$charset} ENGINE=InnoDB"
		);
		$wpdb->query(
			"CREATE TABLE {$versions} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				form_id bigint(20) unsigned NOT NULL,
				version int(10) unsigned NOT NULL,
				fields longtext NOT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY form_version (form_id,version)
			) {$charset} ENGINE=InnoDB"
		);
		$wpdb->query(
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
			) {$charset} ENGINE=InnoDB"
		);
		$wpdb->query(
			"CREATE TABLE {$limits} (
				bucket char(64) NOT NULL,
				hits int(10) unsigned NOT NULL DEFAULT 0,
				expires_at bigint(20) unsigned NOT NULL,
				PRIMARY KEY  (bucket),
				KEY expires_at (expires_at)
			) {$charset} ENGINE=InnoDB"
		);

		$wpdb->insert(
			$forms,
			array(
				'public_id'      => 'fs_abcdefghijklmnop',
				'name'           => 'Contact',
				'is_enabled'     => 1,
				'fields'         => '[]',
				'schema_version' => 1,
				'success_url'    => 'https://example.com/thanks',
				'created_at'     => '2026-10-20 10:00:00',
				'updated_at'     => '2026-10-20 11:00:00',
			)
		);

		update_option( Schema::VERSION_OPTION, 3, true );

		$this->assertNull( Migrator::maybe_migrate() );
		$this->assertSame( Schema::VERSION, (int) get_option( Schema::VERSION_OPTION ) );
		$this->assertTrue( Migrator::tables_exist() );

		$row = $wpdb->get_row( "SELECT notify_recipients, reply_to_field, mail_include_content FROM {$forms}", ARRAY_A );
		$this->assertIsArray( $row );
		$this->assertNull( $row['notify_recipients'] );
		$this->assertNull( $row['reply_to_field'] );
		$this->assertSame( '0', (string) $row['mail_include_content'] );
	}

	/**
	 * A table that is not InnoDB fails the migration check.
	 */
	public function test_myisam_table_is_rejected(): void {
		global $wpdb;

		$this->assertNull( Migrator::run() );

		$forms = Schema::forms_table();
		$wpdb->query( "ALTER TABLE {$forms} ENGINE=MyISAM" );

		$error = Migrator::storage_engine_error();
		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( 'formstead_migration_engine', $error->get_error_code() );
	}

	/**
	 * A failed transaction keeps the row written before it and drops the row written inside it.
	 */
	public function test_transaction_rollback_discards_the_inner_row(): void {
		global $wpdb;

		$this->assertNull( Migrator::run() );

		$table = Schema::rate_limits_table();
		$wpdb->query( 'START TRANSACTION' );
		$wpdb->insert(
			$table,
			array(
				'bucket'     => str_repeat( 'a', 64 ),
				'hits'       => 1,
				'expires_at' => 10,
			),
			array( '%s', '%d', '%d' )
		);

		$result = Transaction::run(
			static function () use ( $wpdb, $table ) {
				$wpdb->insert(
					$table,
					array(
						'bucket'     => str_repeat( 'b', 64 ),
						'hits'       => 1,
						'expires_at' => 10,
					),
					array( '%s', '%d', '%d' )
				);

				return new WP_Error( 'formstead_test_rollback', 'rollback' );
			}
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'formstead_test_rollback', $result->get_error_code() );
		$this->assertSame( 1, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) );
		$wpdb->query( 'ROLLBACK' );
	}

	/**
	 * Schema version 4 gains the inbox column and indexes.
	 */
	public function test_upgrade_from_version_4_adds_inbox_indexes(): void {
		global $wpdb;

		$this->assertNull( Migrator::run() );

		$submissions = Schema::submissions_table();
		$keys        = Schema::idempotency_keys_table();
		$wpdb->query( "ALTER TABLE {$submissions} DROP INDEX form_created" );
		$wpdb->query( "ALTER TABLE {$submissions} DROP INDEX status_created" );
		$wpdb->query( "ALTER TABLE {$submissions} DROP INDEX form_content" );
		$wpdb->query( "ALTER TABLE {$submissions} DROP COLUMN content_hash" );
		$wpdb->query( "ALTER TABLE {$keys} DROP INDEX submission_id" );
		update_option( Schema::VERSION_OPTION, 4 );

		$this->assertNull( Migrator::maybe_migrate() );
		$this->assertSame( Schema::VERSION, (int) get_option( Schema::VERSION_OPTION ) );
		$this->assertSame( 'content_hash', $wpdb->get_var( "SHOW COLUMNS FROM {$submissions} LIKE 'content_hash'" ) );

		$submission_indexes = $wpdb->get_col( "SHOW INDEX FROM {$submissions}", 2 );
		foreach ( array( 'form_status_created', 'form_created', 'status_created', 'form_content' ) as $index ) {
			$this->assertContains( $index, $submission_indexes );
		}

		$key_indexes = $wpdb->get_col( "SHOW INDEX FROM {$keys}", 2 );
		$this->assertContains( 'submission_id', $key_indexes );
	}

	/**
	 * Schema version 5 gains a per-form retention period and a delivery index.
	 *
	 * Existing forms keep submissions for 30 days. The column default applies
	 * them without a backfill.
	 */
	public function test_upgrade_from_version_5_adds_retention(): void {
		global $wpdb;

		$this->assertNull( Migrator::run() );

		$forms      = Schema::forms_table();
		$deliveries = Schema::deliveries_table();
		$this->assertSame( 'retention_days', $wpdb->get_var( "SHOW COLUMNS FROM {$forms} LIKE 'retention_days'" ) );

		$wpdb->query( "ALTER TABLE {$forms} DROP COLUMN retention_days" );
		$wpdb->query( "ALTER TABLE {$deliveries} DROP INDEX status_updated" );
		$wpdb->insert(
			$forms,
			array(
				'public_id'      => 'fs_abcdefghijklmnop',
				'name'           => 'Contact',
				'is_enabled'     => 1,
				'fields'         => '[]',
				'schema_version' => 1,
				'success_url'    => 'https://example.com/thanks',
				'created_at'     => '2026-10-20 10:00:00',
				'updated_at'     => '2026-10-20 11:00:00',
			)
		);
		update_option( Schema::VERSION_OPTION, 5 );

		$this->assertNull( Migrator::maybe_migrate() );
		$this->assertSame( Schema::VERSION, (int) get_option( Schema::VERSION_OPTION ) );
		$this->assertSame( '30', (string) $wpdb->get_var( "SELECT retention_days FROM {$forms}" ) );

		$indexes = $wpdb->get_col( "SHOW INDEX FROM {$deliveries}", 2 );
		$this->assertContains( 'status_updated', $indexes );

		$again = array();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		foreach ( Schema::statements() as $statement ) {
			$again = array_merge( $again, dbDelta( $statement ) );
		}
		$this->assertSame( array(), $again );
	}

	/**
	 * Activation creates the tables.
	 */
	public function test_activation_creates_tables(): void {
		Activator::activate( false );

		$this->assertTrue( Migrator::tables_exist() );
		$this->assertSame( Schema::VERSION, (int) get_option( Schema::VERSION_OPTION ) );
	}
}
