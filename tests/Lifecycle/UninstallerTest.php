<?php
/**
 * Tests for the opt-in uninstall wipe.
 *
 * @package Formstead
 */

namespace Formstead\Tests\Lifecycle;

use Formstead\Database\Migrator;
use Formstead\Database\Schema;
use Formstead\Delivery\Scheduler;
use Formstead\Lifecycle\Uninstaller;
use Formstead\Privacy\IdentityBackfill;
use Formstead\Retention\RetentionRunner;
use Formstead\Retention\RetentionScheduler;
use Formstead\Security\Capabilities;
use Formstead\Security\IpHasher;
use Formstead\Tests\TestCase\MigrationTestCase;

/**
 * Without the option, uninstall keeps data. With it, nothing Formstead remains.
 *
 * @group schema
 *
 * @phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
 * @phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
 * @phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
 */
final class UninstallerTest extends MigrationTestCase {

	/**
	 * Put capabilities and cron back after a wipe commits.
	 */
	public function tear_down(): void {
		delete_option( Uninstaller::OPTION );
		parent::tear_down();
		Capabilities::grant_to_administrator();
		RetentionScheduler::ensure();
		Scheduler::ensure_sweep();
	}

	/**
	 * A site that did not opt in keeps its tables and settings.
	 */
	public function test_without_the_option_data_stays(): void {
		$this->assertNull( Migrator::run() );
		update_option(
			IpHasher::OPTION,
			array(
				'secret'     => 'kept',
				'rotated_at' => time(),
			),
			false
		);
		Capabilities::grant_to_administrator();

		Uninstaller::wipe();

		$tables = $this->table_names();
		$this->assertContains( Schema::forms_table(), $tables );
		$this->assertContains( Schema::submissions_table(), $tables );
		$this->assertContains( Schema::identities_table(), $tables );
		$this->assertContains( Schema::audit_table(), $tables );
		$this->assertSame( (string) Schema::VERSION, (string) get_option( Schema::VERSION_OPTION ) );
		$this->assertIsArray( get_option( IpHasher::OPTION ) );
		$role = get_role( 'administrator' );
		$this->assertNotNull( $role );
		$this->assertTrue( $role->has_cap( Capabilities::MANAGE_FORMS ) );
	}

	/**
	 * Opting in removes tables, options, transients, capabilities, and cron events.
	 */
	public function test_with_the_option_nothing_formstead_remains(): void {
		$this->assertNull( Migrator::run() );
		update_option( Uninstaller::OPTION, '1', false );
		update_option( RetentionRunner::REPORT_OPTION, array( 'finished_at' => time() ), false );
		update_option(
			IpHasher::OPTION,
			array(
				'secret'     => 'gone',
				'rotated_at' => time(),
			),
			false
		);
		set_transient( 'formstead_unread_count', 4, HOUR_IN_SECONDS );
		Capabilities::grant_to_administrator();
		RetentionScheduler::ensure();
		Scheduler::ensure_sweep();
		update_option(
			Migrator::IDENTITY_BACKFILL_OPTION,
			array(
				'status'   => 'pending',
				'after_id' => 0,
				'until_id' => 1,
			),
			false
		);
		IdentityBackfill::ensure();
		$this->assertNotFalse( wp_next_scheduled( RetentionScheduler::HOOK ) );
		$this->assertNotFalse( wp_next_scheduled( Scheduler::SWEEP ) );
		$this->assertNotFalse( wp_next_scheduled( IdentityBackfill::HOOK ) );

		Uninstaller::wipe();

		$tables = $this->table_names();
		foreach ( Schema::tables() as $table ) {
			$this->assertNotContains( $table, $tables, $table );
		}
		$this->assertNotContains( Schema::identities_table(), $tables );
		$this->assertNotContains( Schema::audit_table(), $tables );
		$this->assertFalse( get_option( Schema::VERSION_OPTION ) );
		$this->assertFalse( get_option( Uninstaller::OPTION ) );
		$this->assertFalse( get_option( RetentionRunner::REPORT_OPTION ) );
		$this->assertFalse( get_option( IpHasher::OPTION ) );
		$this->assertFalse( get_transient( 'formstead_unread_count' ) );

		$role = get_role( 'administrator' );
		$this->assertNotNull( $role );
		foreach ( Capabilities::all() as $capability ) {
			$this->assertFalse( $role->has_cap( $capability ), $capability );
		}
		$this->assertFalse( wp_next_scheduled( RetentionScheduler::HOOK ) );
		$this->assertFalse( wp_next_scheduled( Scheduler::SWEEP ) );
		$this->assertFalse( wp_next_scheduled( IdentityBackfill::HOOK ) );
		$this->assertFalse( get_option( Migrator::IDENTITY_BACKFILL_OPTION ) );
	}

	/**
	 * Table names in this database.
	 *
	 * @return string[]
	 */
	private function table_names(): array {
		global $wpdb;

		$names = $wpdb->get_col( 'SHOW TABLES' );
		if ( ! is_array( $names ) ) {
			return array();
		}

		$clean = array();
		foreach ( $names as $name ) {
			if ( is_string( $name ) ) {
				$clean[] = $name;
			}
		}

		return $clean;
	}
}
