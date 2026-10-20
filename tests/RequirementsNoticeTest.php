<?php
/**
 * Tests for requirement admin notices.
 *
 * @package Formstead
 */

namespace Formstead\Tests;

use WP_UnitTestCase;

/**
 * Subscribers must not see install-time notices.
 */
final class RequirementsNoticeTest extends WP_UnitTestCase {

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
	 * Only a user who can activate plugins sees the version notice.
	 */
	public function test_version_notice_is_limited_to_plugin_activators(): void {
		global $wp_version;

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restored in tear_down.
		$wp_version = '6.9';

		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		ob_start();
		formstead_requirements_notice();
		$subscriber_output = ob_get_clean();

		$this->assertSame( '', $subscriber_output );

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		ob_start();
		formstead_requirements_notice();
		$admin_output = ob_get_clean();

		$this->assertStringContainsString( 'WordPress 7.0', $admin_output );
	}

	/**
	 * Only a user who can activate plugins sees the missing autoload notice.
	 */
	public function test_missing_autoload_notice_is_limited_to_plugin_activators(): void {
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		ob_start();
		formstead_missing_autoload_notice();
		$subscriber_output = ob_get_clean();

		$this->assertSame( '', $subscriber_output );

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		ob_start();
		formstead_missing_autoload_notice();
		$admin_output = ob_get_clean();

		$this->assertStringContainsString( 'vendor/autoload.php', $admin_output );
	}
}
