<?php
/**
 * Tests for activation guards.
 *
 * @package Formstead
 */

namespace Formstead\Tests\Lifecycle;

use Formstead\Lifecycle\Activator;
use WP_Error;
use WP_UnitTestCase;

/**
 * Covers the Multisite refusal without requiring a network.
 */
final class ActivatorTest extends WP_UnitTestCase {

	/**
	 * WordPress version before this test changed it.
	 *
	 * @var string
	 */
	private string $original_wp_version;

	/**
	 * Remember the suite's WordPress version.
	 */
	public function set_up(): void {
		parent::set_up();

		global $wp_version;

		$this->original_wp_version = (string) $wp_version;
	}

	/**
	 * Restore the WordPress version after a test lowers it.
	 */
	public function tear_down(): void {
		global $wp_version;

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- undo the version this test set.
		$wp_version = $this->original_wp_version;

		parent::tear_down();
	}

	/**
	 * Network-wide activation on Multisite is refused.
	 */
	public function test_refuses_network_activation(): void {
		$error = Activator::activation_error( true, true );

		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( 'formstead_network_activation', $error->get_error_code() );
	}

	/**
	 * Single-site activation is allowed.
	 */
	public function test_allows_single_site_activation(): void {
		$this->assertNull( Activator::activation_error( false, false ) );
	}

	/**
	 * Per-site activation on a network is allowed.
	 */
	public function test_allows_per_site_activation_on_multisite(): void {
		$this->assertNull( Activator::activation_error( false, true ) );
	}

	/**
	 * Activation reports an old WordPress version.
	 */
	public function test_refuses_old_wordpress(): void {
		global $wp_version;

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restored in tear_down.
		$wp_version = '6.9';
		$error      = Activator::activation_error( false, false );

		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( 'formstead_wp_version', $error->get_error_code() );
	}

	/**
	 * Activation stops when WordPress is too old.
	 */
	public function test_activate_dies_when_wordpress_is_too_old(): void {
		global $wp_version;

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restored in tear_down.
		$wp_version = '6.9';

		$this->expectException( \WPDieException::class );
		Activator::activate( false );
	}
}
