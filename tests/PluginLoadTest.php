<?php
/**
 * Smoke test that the plugin boots inside WordPress.
 *
 * @package Formstead
 */

namespace Formstead\Tests;

use Formstead\Plugin;
use WP_UnitTestCase;

/**
 * Confirms the plugin file loaded and registered its bootstrap class.
 */
final class PluginLoadTest extends WP_UnitTestCase {

	/**
	 * Plugin constants and the bootstrap class must exist after load.
	 */
	public function test_plugin_is_loaded(): void {
		$this->assertTrue( defined( 'FORMSTEAD_VERSION' ) );
		$this->assertTrue( defined( 'FORMSTEAD_FILE' ) );
		$this->assertTrue( class_exists( Plugin::class ) );
		$this->assertSame( 'formstead/formstead.php', plugin_basename( FORMSTEAD_FILE ) );
	}
}
