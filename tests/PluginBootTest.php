<?php
/**
 * Tests for plugin boot when migration does not finish.
 *
 * @package Formstead
 */

namespace Formstead\Tests;

use Formstead\Admin\Menu;
use Formstead\Database\Migrator;
use Formstead\Database\Schema;
use Formstead\Plugin;
use Formstead\Rest\HtmlResponder;
use Formstead\Rest\SubmissionsController;
use Formstead\Tests\TestCase\MigrationTestCase;
use WP_Hook;

/**
 * A held migration lock must not drop the public route when the tables exist.
 *
 * @group schema
 */
final class PluginBootTest extends MigrationTestCase {

	private const ROUTE = '/formstead/v1/forms/(?P<public_form_id>[^/]+)/submissions';

	/**
	 * Put the route and the menu back for tests that follow this class.
	 */
	public function tear_down(): void {
		parent::tear_down();
		$this->detach_runtime();
		Plugin::boot();
	}

	/**
	 * Tables from an unfinished migration still get the public route.
	 */
	public function test_boot_keeps_the_route_when_tables_exist_and_migration_is_locked(): void {
		$this->assertNull( Migrator::run() );
		delete_option( Schema::VERSION_OPTION );
		$this->hold_lock();
		$this->detach_runtime();

		Plugin::boot();

		$this->assertArrayHasKey( self::ROUTE, rest_get_server()->get_routes() );
		$this->assertNotFalse( has_action( 'admin_menu', array( Menu::class, 'add_pages' ) ) );
		$this->assertNotFalse( has_filter( 'rest_pre_serve_request', array( HtmlResponder::class, 'serve' ) ) );
		$this->assertNotFalse( has_action( 'admin_notices', array( Plugin::class, 'migration_notice' ) ) );
		$this->assertSame( 0, (int) get_option( Schema::VERSION_OPTION, 0 ) );
	}

	/**
	 * Without tables there is nothing for the route to write to.
	 */
	public function test_boot_skips_the_route_when_tables_are_missing(): void {
		$this->hold_lock();
		$this->detach_runtime();

		Plugin::boot();

		$this->assertArrayNotHasKey( self::ROUTE, rest_get_server()->get_routes() );
		$this->assertFalse( has_action( 'admin_menu', array( Menu::class, 'add_pages' ) ) );
		$this->assertNotFalse( has_action( 'admin_notices', array( Plugin::class, 'migration_notice' ) ) );
	}

	/**
	 * A second boot() call does not add the runtime hooks again.
	 */
	public function test_boot_twice_registers_each_hook_once(): void {
		$this->assertNull( Migrator::run() );
		$this->detach_runtime();

		Plugin::boot();
		Plugin::boot();

		$this->assertSame( 1, $this->callback_count( 'rest_api_init', array( SubmissionsController::class, 'register_routes' ) ) );
		$this->assertSame( 1, $this->callback_count( 'admin_menu', array( Menu::class, 'add_pages' ) ) );
		$this->assertSame( 1, $this->callback_count( 'rest_pre_serve_request', array( HtmlResponder::class, 'serve' ) ) );
	}

	/**
	 * Hold the migration lock so `run()` refuses to start.
	 */
	private function hold_lock(): void {
		delete_option( Schema::LOCK_OPTION );
		add_option( Schema::LOCK_OPTION, time(), '', false );
	}

	/**
	 * Remove hooks and the REST server left by the real bootstrap.
	 */
	private function detach_runtime(): void {
		remove_action( 'rest_api_init', array( SubmissionsController::class, 'register_routes' ) );
		remove_action( 'admin_menu', array( Menu::class, 'add_pages' ) );
		remove_filter( 'rest_pre_serve_request', array( HtmlResponder::class, 'serve' ), 12 );
		remove_action( 'admin_notices', array( Plugin::class, 'migration_notice' ) );
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- the next rest_get_server() must rebuild routes from the current hooks.
		$GLOBALS['wp_rest_server'] = null;
	}

	/**
	 * How many times one static callback is stored on a hook.
	 *
	 * @param string                            $hook     Hook name.
	 * @param array{0: class-string, 1: string} $callback Static callback.
	 */
	private function callback_count( string $hook, array $callback ): int {
		global $wp_filter;

		if ( ! isset( $wp_filter[ $hook ] ) || ! $wp_filter[ $hook ] instanceof WP_Hook ) {
			return 0;
		}

		$count = 0;
		foreach ( $wp_filter[ $hook ]->callbacks as $group ) {
			foreach ( $group as $registered ) {
				if ( isset( $registered['function'] ) && $registered['function'] === $callback ) {
					++$count;
				}
			}
		}

		return $count;
	}
}
