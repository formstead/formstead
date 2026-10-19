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
}
