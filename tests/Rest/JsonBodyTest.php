<?php
/**
 * Tests for JSON object parsing.
 *
 * @package Formstead
 */

namespace Formstead\Tests\Rest;

use Formstead\Rest\JsonBody;
use Formstead\Rest\JsonNumber;
use WP_UnitTestCase;

/**
 * Covers empty objects and number tokens.
 */
final class JsonBodyTest extends WP_UnitTestCase {

	/**
	 * An empty object stays an object, including surrounding whitespace.
	 */
	public function test_empty_object_is_an_object(): void {
		$this->assertSame( array(), JsonBody::object( '{}' ) );
		$this->assertSame( array(), JsonBody::object( " \n{\t} " ) );
	}

	/**
	 * A JSON array is not an object.
	 */
	public function test_array_is_rejected(): void {
		$this->assertNull( JsonBody::object( '[]' ) );
		$this->assertNull( JsonBody::object( ' [ ] ' ) );
	}

	/**
	 * Numbers keep their original tokens and strings stay strings.
	 */
	public function test_numbers_keep_their_tokens(): void {
		$object = JsonBody::object( '{"qty":0.10,"whole":12,"exp":1e2,"label":"1","flag":true}' );

		$this->assertIsArray( $object );
		$this->assertInstanceOf( JsonNumber::class, $object['qty'] );
		$this->assertSame( '0.10', $object['qty']->token() );
		$this->assertInstanceOf( JsonNumber::class, $object['whole'] );
		$this->assertSame( '12', $object['whole']->token() );
		$this->assertInstanceOf( JsonNumber::class, $object['exp'] );
		$this->assertSame( '1e2', $object['exp']->token() );
		$this->assertSame( '1', $object['label'] );
		$this->assertTrue( $object['flag'] );
	}
}
