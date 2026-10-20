<?php
/**
 * Tests for public form ids.
 *
 * @package Formstead
 */

namespace Formstead\Tests\Forms;

use Formstead\Forms\PublicId;
use WP_UnitTestCase;

/**
 * Covers generation and the public id pattern.
 */
final class PublicIdTest extends WP_UnitTestCase {

	/**
	 * Generated ids match the pattern and are 19 characters.
	 */
	public function test_generate_matches_the_pattern(): void {
		$seen = array();

		for ( $index = 0; $index < 20; $index++ ) {
			$id = PublicId::generate();
			$this->assertTrue( PublicId::is_valid( $id ) );
			$this->assertSame( 19, strlen( $id ) );
			$seen[] = $id;
		}

		$this->assertCount( 20, array_unique( $seen ) );
	}

	/**
	 * Values that are not public ids are rejected.
	 *
	 * @dataProvider invalid_provider
	 * @param string $value Candidate id.
	 */
	public function test_invalid_values_are_rejected( string $value ): void {
		$this->assertFalse( PublicId::is_valid( $value ) );
	}

	/**
	 * Invalid public ids.
	 *
	 * @return array<string, array{string}>
	 */
	public function invalid_provider(): array {
		return array(
			'short'     => array( 'fs_abc' ),
			'uppercase' => array( 'fs_ABCDEFGHIJKLMNOP' ),
			'prefix'    => array( 'xx_abcdefghijklmnop' ),
			'digits'    => array( 'fs_abcdefghijklmn01' ),
			'extra'     => array( 'fs_abcdefghijklmnopqrst' ),
		);
	}
}
