<?php
/**
 * Tests for form persistence.
 *
 * @package Formstead
 */

namespace Formstead\Tests\Forms;

use Formstead\Forms\Field;
use Formstead\Forms\FieldType;
use Formstead\Forms\Form;
use Formstead\Forms\FormRepository;
use Formstead\Forms\PublicId;
use WP_UnitTestCase;

/**
 * Covers the public id and schema version rules.
 */
final class FormRepositoryTest extends WP_UnitTestCase {

	/**
	 * Repository under test.
	 *
	 * @var FormRepository
	 */
	private FormRepository $forms;

	/**
	 * Create the repository.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->forms = new FormRepository();
	}

	/**
	 * Inserted forms can be loaded, and the public id is not taken from the input.
	 */
	public function test_insert_assigns_a_public_id(): void {
		$saved = $this->forms->insert( $this->form( 'Contact' ) );

		$this->assertNotInstanceOf( \WP_Error::class, $saved );
		$this->assertTrue( PublicId::is_valid( $saved->public_id() ) );
		$this->assertSame( 1, $saved->schema_version() );

		$loaded = $this->forms->find_by_public_id( $saved->public_id() );
		$this->assertInstanceOf( Form::class, $loaded );
		$this->assertSame( $saved->id(), $loaded->id() );
		$this->assertSame( 'Contact', $loaded->name() );
		$this->assertSame( 'email', $loaded->fields()[0]->name() );
	}

	/**
	 * Updating the name keeps the public id and the schema version.
	 */
	public function test_update_keeps_the_public_id_and_version_when_fields_are_unchanged(): void {
		$saved = $this->forms->insert( $this->form( 'Contact' ) );
		$this->assertNotInstanceOf( \WP_Error::class, $saved );

		$renamed = new Form(
			$saved->id(),
			'fs_shouldnotchange',
			'Support',
			false,
			$saved->fields(),
			99,
			'https://example.com/other',
			$saved->created_at(),
			$saved->updated_at()
		);

		$updated = $this->forms->update( $renamed );

		$this->assertNotInstanceOf( \WP_Error::class, $updated );
		$this->assertSame( $saved->public_id(), $updated->public_id() );
		$this->assertSame( 1, $updated->schema_version() );
		$this->assertSame( 'Support', $updated->name() );
		$this->assertFalse( $updated->is_enabled() );
		$this->assertSame( 'https://example.com/other', $updated->success_url() );

		$loaded = $this->forms->find_by_id( $saved->id() );
		$this->assertInstanceOf( Form::class, $loaded );
		$this->assertSame( $saved->public_id(), $loaded->public_id() );
		$this->assertSame( 1, $loaded->schema_version() );
	}

	/**
	 * Changing fields bumps the schema version once.
	 */
	public function test_update_bumps_schema_version_when_fields_change(): void {
		$saved = $this->forms->insert( $this->form( 'Contact' ) );
		$this->assertNotInstanceOf( \WP_Error::class, $saved );

		$changed = new Form(
			$saved->id(),
			$saved->public_id(),
			$saved->name(),
			true,
			array(
				new Field( 'email', 'Work email', FieldType::Email, true, 255 ),
			),
			$saved->schema_version(),
			$saved->success_url(),
			$saved->created_at(),
			$saved->updated_at()
		);

		$updated = $this->forms->update( $changed );

		$this->assertNotInstanceOf( \WP_Error::class, $updated );
		$this->assertSame( 2, $updated->schema_version() );
		$this->assertSame( 'Work email', $updated->fields()[0]->label() );
	}

	/**
	 * A form that has not been saved yet.
	 *
	 * @param string $name Form name.
	 */
	private function form( string $name ): Form {
		return new Form(
			0,
			'',
			$name,
			true,
			array(
				new Field( 'email', 'Email', FieldType::Email, true, 255 ),
			),
			1,
			'https://example.com/thanks',
			'',
			''
		);
	}
}
