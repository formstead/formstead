<?php
/**
 * Tests for the opt-in uninstall wipe on a network.
 *
 * @package Formstead
 */

namespace Formstead\Tests\Lifecycle;

use Formstead\Database\Migrator;
use Formstead\Database\Schema;
use Formstead\Delivery\Scheduler;
use Formstead\Lifecycle\Uninstaller;
use Formstead\Retention\RetentionScheduler;
use Formstead\Security\Capabilities;
use Formstead\Security\IpHasher;
use Formstead\Tests\TestCase\MigrationTestCase;

/**
 * One site can opt in without deleting another site's data.
 *
 * Run with `WP_MULTISITE=1 vendor/bin/phpunit --group multisite`.
 *
 * @group multisite
 *
 * @phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
 * @phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
 * @phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange
 * @phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
 * @phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
 */
final class UninstallerMultisiteTest extends MigrationTestCase {

	/**
	 * Second site created for this test. Zero until it exists.
	 *
	 * @var int
	 */
	private int $second = 0;

	/**
	 * Skip on a single site before the schema reset runs.
	 */
	public function set_up(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite is off.' );
		}

		parent::set_up();
	}

	/**
	 * Drop the second site's tables and put the main site back.
	 */
	public function tear_down(): void {
		while ( ms_is_switched() ) {
			restore_current_blog();
		}

		if ( $this->second > 0 ) {
			switch_to_blog( $this->second );
			$this->drop_formstead_tables();
			restore_current_blog();
		}

		wp_roles()->for_site( get_current_blog_id() );
		parent::tear_down();
		Capabilities::grant_to_administrator();
		RetentionScheduler::ensure();
		Scheduler::ensure_sweep();
	}

	/**
	 * A site that opted in loses its data. A site that did not keeps it.
	 */
	public function test_wipe_follows_each_sites_choice(): void {
		$main = get_current_blog_id();
		$this->prepare_site( false );
		$second = self::factory()->blog->create();
		$this->assertIsInt( $second );
		$this->second = $second;

		switch_to_blog( $second );
		wp_roles()->for_site( $second );
		$this->prepare_site( true );
		$second_tables = Schema::tables();
		restore_current_blog();
		wp_roles()->for_site( $main );

		Uninstaller::wipe();

		$this->assertSame( $main, get_current_blog_id() );
		$this->assertContains( Schema::forms_table(), $this->table_names() );
		$this->assertSame( (string) Schema::VERSION, (string) get_option( Schema::VERSION_OPTION ) );
		$this->assertIsArray( get_option( IpHasher::OPTION ) );
		$this->assertTrue( $this->administrator_has( Capabilities::MANAGE_FORMS ) );
		$this->assertNotFalse( wp_next_scheduled( RetentionScheduler::HOOK ) );
		$this->assertNotFalse( wp_next_scheduled( Scheduler::SWEEP ) );

		switch_to_blog( $second );
		wp_roles()->for_site( $second );
		$tables = $this->table_names();
		foreach ( $second_tables as $table ) {
			$this->assertNotContains( $table, $tables, $table );
		}
		$this->assertFalse( get_option( Schema::VERSION_OPTION ) );
		$this->assertFalse( get_option( Uninstaller::OPTION ) );
		$this->assertFalse( get_option( IpHasher::OPTION ) );
		$role = get_role( 'administrator' );
		$this->assertNotNull( $role );
		foreach ( Capabilities::all() as $capability ) {
			$this->assertFalse( $role->has_cap( $capability ), $capability );
		}
		$this->assertFalse( wp_next_scheduled( RetentionScheduler::HOOK ) );
		$this->assertFalse( wp_next_scheduled( Scheduler::SWEEP ) );
		restore_current_blog();
		wp_roles()->for_site( get_current_blog_id() );
	}

	/**
	 * Install this site and record whether it opts in.
	 *
	 * @param bool $delete Whether this site deletes its data on uninstall.
	 */
	private function prepare_site( bool $delete ): void {
		$this->assertNull( Migrator::run() );
		if ( $delete ) {
			update_option( Uninstaller::OPTION, '1', false );
		} else {
			delete_option( Uninstaller::OPTION );
		}
		update_option(
			IpHasher::OPTION,
			array(
				'secret'     => $delete ? 'gone' : 'kept',
				'rotated_at' => time(),
			),
			false
		);
		Capabilities::grant_to_administrator();
		RetentionScheduler::ensure();
		Scheduler::ensure_sweep();
		$this->assertNotFalse( wp_next_scheduled( RetentionScheduler::HOOK ) );
	}

	/**
	 * Whether the administrator role on the current site has a capability.
	 *
	 * @param string $capability Capability name.
	 */
	private function administrator_has( string $capability ): bool {
		$role = get_role( 'administrator' );
		if ( null === $role ) {
			return false;
		}

		return $role->has_cap( $capability );
	}

	/**
	 * Remove Formstead tables on the current site.
	 */
	private function drop_formstead_tables(): void {
		global $wpdb;

		foreach ( Schema::tables() as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		}
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
