<?php
/**
 * Tests for schema installation.
 *
 * @package Formstead
 */

namespace Formstead\Tests\Database;

use Formstead\Database\Migrator;
use Formstead\Database\Schema;
use Formstead\Lifecycle\Activator;
use Formstead\Security\Capabilities;
use Formstead\Tests\TestCase\MigrationTestCase;
use WP_Error;
use WP_Role;

/**
 * Covers installation, the migration lock, and capability grants.
 *
 * @group schema
 */
final class MigratorTest extends MigrationTestCase {

	/**
	 * A fresh install creates both tables and stores schema version 1.
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
	 * Activation creates the tables.
	 */
	public function test_activation_creates_tables(): void {
		Activator::activate( false );

		$this->assertTrue( Migrator::tables_exist() );
		$this->assertSame( Schema::VERSION, (int) get_option( Schema::VERSION_OPTION ) );
	}
}
