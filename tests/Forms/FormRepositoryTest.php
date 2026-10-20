<?php
/**
 * Tests for form persistence.
 *
 * @package Formstead
 */

namespace Formstead\Tests\Forms;

use Formstead\Forms\Field;
use Formstead\Forms\FieldCodec;
use Formstead\Forms\FieldType;
use Formstead\Forms\Form;
use Formstead\Forms\FormProtection;
use Formstead\Forms\FormRepository;
use Formstead\Forms\FormVersionRepository;
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
			$saved->schema_version(),
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

		$snapshot = ( new FormVersionRepository() )->find( $saved->id(), 1 );
		$this->assertNotNull( $snapshot );
		$this->assertSame( 'Email', $snapshot->fields()[0]->label() );

		$current = ( new FormVersionRepository() )->find( $saved->id(), 2 );
		$this->assertNotNull( $current );
		$this->assertSame( 'Work email', $current->fields()[0]->label() );
	}

	/**
	 * An editor that opened an older version cannot overwrite a newer save.
	 */
	public function test_update_rejects_a_stale_editor_version(): void {
		$saved = $this->forms->insert( $this->form( 'Contact' ) );
		$this->assertInstanceOf( Form::class, $saved );

		$changed = new Form(
			$saved->id(),
			$saved->public_id(),
			$saved->name(),
			true,
			array( new Field( 'email', 'Work email', FieldType::Email, true, 255 ) ),
			$saved->schema_version(),
			$saved->success_url(),
			$saved->created_at(),
			$saved->updated_at()
		);
		$updated = $this->forms->update( $changed );
		$this->assertInstanceOf( Form::class, $updated );

		$stale = new Form(
			$saved->id(),
			$saved->public_id(),
			'Renamed',
			true,
			array( new Field( 'email', 'Other', FieldType::Email, true, 255 ) ),
			$saved->schema_version(),
			$saved->success_url(),
			$saved->created_at(),
			$saved->updated_at()
		);

		$rejected = $this->forms->update( $stale );

		$this->assertInstanceOf( \WP_Error::class, $rejected );
		$this->assertSame( 'formstead_form_conflict', $rejected->get_error_code() );

		$loaded = $this->forms->find_by_id( $saved->id() );
		$this->assertInstanceOf( Form::class, $loaded );
		$this->assertSame( 'Contact', $loaded->name() );
		$this->assertSame( 2, $loaded->schema_version() );
		$this->assertSame( 'Work email', $loaded->fields()[0]->label() );
	}

	/**
	 * Renaming from a stale editor version does not change the stored name.
	 */
	public function test_update_rejects_a_stale_name_change(): void {
		$saved = $this->forms->insert( $this->form( 'Contact' ) );
		$this->assertInstanceOf( Form::class, $saved );

		$renamed = new Form(
			$saved->id(),
			$saved->public_id(),
			'Support',
			$saved->is_enabled(),
			$saved->fields(),
			$saved->schema_version() + 1,
			$saved->success_url(),
			$saved->created_at(),
			$saved->updated_at()
		);

		$rejected = $this->forms->update( $renamed );

		$this->assertInstanceOf( \WP_Error::class, $rejected );
		$this->assertSame( 'formstead_form_conflict', $rejected->get_error_code() );

		$loaded = $this->forms->find_by_id( $saved->id() );
		$this->assertInstanceOf( Form::class, $loaded );
		$this->assertSame( 'Contact', $loaded->name() );
		$this->assertSame( 1, $loaded->schema_version() );
	}

	/**
	 * A snapshot of the next version with different fields blocks the save.
	 */
	public function test_update_reports_a_conflict_when_the_next_version_differs(): void {
		$saved = $this->forms->insert( $this->form( 'Contact' ) );
		$this->assertInstanceOf( Form::class, $saved );

		$other = new Field( 'email', 'Other', FieldType::Email, true, 255 );
		$this->assertNotInstanceOf( \WP_Error::class, ( new FormVersionRepository() )->insert( $saved->id(), 2, array( $other ) ) );

		$changed = new Form(
			$saved->id(),
			$saved->public_id(),
			$saved->name(),
			true,
			array( new Field( 'email', 'Work email', FieldType::Email, true, 255 ) ),
			$saved->schema_version(),
			$saved->success_url(),
			$saved->created_at(),
			$saved->updated_at()
		);

		$updated = $this->forms->update( $changed );

		$this->assertInstanceOf( \WP_Error::class, $updated );
		$this->assertSame( 'formstead_form_conflict', $updated->get_error_code() );

		$loaded = $this->forms->find_by_id( $saved->id() );
		$this->assertInstanceOf( Form::class, $loaded );
		$this->assertSame( 1, $loaded->schema_version() );
		$this->assertSame( 'Email', $loaded->fields()[0]->label() );
		$this->assertNotSame( FieldCodec::encode( $changed->fields() ), FieldCodec::encode( $loaded->fields() ) );
	}

	/**
	 * Protection is stored without a new field-schema version, and a later field edit keeps it.
	 */
	public function test_protection_is_saved_without_bumping_the_schema(): void {
		$saved = $this->forms->insert( $this->form( 'Contact' ) );
		$this->assertInstanceOf( Form::class, $saved );

		$protected = new Form(
			$saved->id(),
			$saved->public_id(),
			$saved->name(),
			true,
			$saved->fields(),
			$saved->schema_version(),
			$saved->success_url(),
			$saved->created_at(),
			$saved->updated_at(),
			new FormProtection( array( 'https://forms.example' ), 2, null )
		);
		$updated   = $this->forms->update( $protected );
		$this->assertInstanceOf( Form::class, $updated );
		$this->assertSame( 1, $updated->schema_version() );
		$this->assertSame( array( 'https://forms.example' ), $updated->protection()->allowed_origins() );
		$this->assertSame( 2, $updated->protection()->rate_per_minute() );

		$this->forms->record_honeypot( $saved->id() );
		$renamed = new Form(
			$saved->id(),
			$saved->public_id(),
			'Renamed',
			true,
			array( new Field( 'email', 'Work email', FieldType::Email, true, 255 ) ),
			$updated->schema_version(),
			$saved->success_url(),
			$saved->created_at(),
			$saved->updated_at(),
			$updated->protection()
		);
		$changed = $this->forms->update( $renamed );
		$this->assertInstanceOf( Form::class, $changed );
		$this->assertSame( 2, $changed->schema_version() );

		$loaded = $this->forms->find_by_id( $saved->id() );
		$this->assertInstanceOf( Form::class, $loaded );
		$this->assertSame( array( 'https://forms.example' ), $loaded->protection()->allowed_origins() );
		$this->assertSame( 2, $loaded->protection()->rate_per_minute() );
		$this->assertSame( 1, $loaded->honeypot_rejections() );
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
