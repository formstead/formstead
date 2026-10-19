<?php
/**
 * Tests for version requirement checks.
 *
 * @package Formstead
 */

namespace Formstead\Tests\Lifecycle;

use Formstead\Lifecycle\Requirements;
use WP_UnitTestCase;

/**
 * Unit tests for Requirements. No database writes.
 */
final class RequirementsTest extends WP_UnitTestCase {

	/**
	 * PHP below 8.3 is rejected.
	 */
	public function test_rejects_old_php(): void {
		$this->assertSame(
			Requirements::REASON_PHP,
			Requirements::unmet_reason( '8.2.29', '7.1.3' )
		);
		$this->assertFalse( Requirements::are_met( '8.2.29', '7.1.3' ) );
	}

	/**
	 * WordPress below 7.0 is rejected.
	 */
	public function test_rejects_old_wordpress(): void {
		$this->assertSame(
			Requirements::REASON_WP,
			Requirements::unmet_reason( '8.3.0', '6.9' )
		);
	}

	/**
	 * The declared minimums are accepted.
	 */
	public function test_accepts_declared_minimums(): void {
		$this->assertNull( Requirements::unmet_reason( '8.3.0', '7.0' ) );
		$this->assertTrue( Requirements::are_met( '8.3.0', '7.1.3' ) );
	}
}
