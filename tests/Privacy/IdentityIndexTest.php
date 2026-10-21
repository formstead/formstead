<?php
/**
 * Tests for the identifying-email index.
 *
 * @package Formstead
 */

namespace Formstead\Tests\Privacy;

use Formstead\Database\Schema;
use Formstead\Forms\Field;
use Formstead\Forms\FieldType;
use Formstead\Forms\Form;
use Formstead\Forms\FormRepository;
use Formstead\Privacy\IdentityHasher;
use Formstead\Privacy\IdentityRepository;
use Formstead\Submissions\Submission;
use Formstead\Submissions\SubmissionDeleter;
use Formstead\Submissions\SubmissionIntake;
use Formstead\Submissions\SubmissionRepository;
use Formstead\Submissions\SubmissionStatus;
use WP_Error;
use WP_UnitTestCase;

/**
 * The index stores a hash, and deletion removes it with the submission.
 *
 * @phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
 * @phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
 * @phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
 */
final class IdentityIndexTest extends WP_UnitTestCase {

	/**
	 * Normalization ignores case and surrounding spaces, and keeps a plus-tag.
	 */
	public function test_hash_ignores_case_and_space_and_keeps_a_plus_tag(): void {
		update_option( IdentityHasher::OPTION, str_repeat( 'ab', 32 ), false );

		$plain = IdentityHasher::hash( ' Ada@Example.com ' );
		$this->assertSame( $plain, IdentityHasher::hash( 'ada@example.com' ) );
		$this->assertNotSame( $plain, IdentityHasher::hash( 'ada+tag@example.com' ) );
		$this->assertNull( IdentityHasher::hash( '   ' ) );
	}

	/**
	 * A stored submission indexes the hash and not the address. Spam is indexed too.
	 */
	public function test_submission_stores_the_hash_without_the_address(): void {
		global $wpdb;

		update_option( IdentityHasher::OPTION, str_repeat( 'ab', 32 ), false );
		$form    = $this->form( array( 'email' ) );
		$intake  = new SubmissionIntake();
		$stored  = $intake->accept(
			$form,
			array(
				'email' => 'Ada@Example.com',
				'note'  => 'hello',
			),
			null
		);
		$spam    = $intake->accept(
			$form,
			array(
				'email' => 'Ada@Example.com',
				'note'  => 'again',
			),
			null,
			true
		);
		$saved   = $stored->submission();
		$flagged = $spam->submission();

		$this->assertInstanceOf( Submission::class, $saved );
		$this->assertInstanceOf( Submission::class, $flagged );
		$this->assertSame( SubmissionStatus::SPAM, $flagged->status() );

		$table = Schema::identities_table();
		$rows  = $wpdb->get_results( "SELECT field, value_hash FROM {$table} ORDER BY id ASC", ARRAY_A );
		$this->assertCount( 2, $rows );
		$dump = strtolower( (string) wp_json_encode( $rows ) );
		$this->assertStringNotContainsString( 'ada@example.com', $dump );
		$this->assertSame( 'email', $rows[0]['field'] );
		$this->assertSame( IdentityHasher::hash( ' ada@example.com ' ), $rows[0]['value_hash'] );
		$this->assertSame( array( $saved->id(), $flagged->id() ), ( new IdentityRepository() )->submission_ids_for_hash( (string) $rows[0]['value_hash'], 10 ) );
	}

	/**
	 * A failed index insert leaves no submission.
	 */
	public function test_failed_index_insert_rolls_the_submission_back(): void {
		global $wpdb;

		$form       = $this->form( array( 'email' ) );
		$table      = Schema::identities_table();
		$submission = Schema::submissions_table();
		$previous   = $wpdb->suppress_errors( true );
		$filter     = static function ( $query ) use ( $table ) {
			if ( is_string( $query ) && str_contains( $query, 'INSERT' ) && str_contains( $query, $table ) ) {
				return 'INSERT INTO ' . $table . '_missing (id) VALUES (1)';
			}

			return $query;
		};
		add_filter( 'query', $filter );
		$stored = ( new SubmissionIntake() )->accept( $form, array( 'email' => 'ada@example.com' ), null );
		remove_filter( 'query', $filter );
		$wpdb->suppress_errors( $previous );

		$this->assertTrue( $stored->is_failed() );
		$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE form_id = %d", $form->id() ) ) );
		$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$submission} WHERE form_id = %d", $form->id() ) ) );
	}

	/**
	 * Deleting the submission deletes its index rows.
	 */
	public function test_delete_removes_identity_rows(): void {
		global $wpdb;

		$form   = $this->form( array( 'email' ) );
		$stored = ( new SubmissionIntake() )->accept( $form, array( 'email' => 'ada@example.com' ), null );
		$saved  = $stored->submission();
		$this->assertInstanceOf( Submission::class, $saved );

		$deleted = ( new SubmissionDeleter() )->delete( array( $saved->id() ) );
		$this->assertSame( 1, $deleted );
		$this->assertNull( ( new SubmissionRepository() )->find( $saved->id() ) );
		$table = Schema::identities_table();
		$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE form_id = %d", $form->id() ) ) );
	}

	/**
	 * Turning the mark off deletes that field's rows and does not create a new schema version.
	 */
	public function test_removing_the_mark_deletes_the_field_rows(): void {
		global $wpdb;

		$form   = $this->form( array( 'email' ) );
		$stored = ( new SubmissionIntake() )->accept( $form, array( 'email' => 'ada@example.com' ), null );
		$this->assertInstanceOf( Submission::class, $stored->submission() );

		$updated = ( new FormRepository() )->update(
			new Form(
				$form->id(),
				$form->public_id(),
				$form->name(),
				true,
				$form->fields(),
				$form->schema_version(),
				$form->success_url(),
				$form->created_at(),
				$form->updated_at(),
				null,
				0,
				null,
				$form->retention_days(),
				array()
			)
		);

		$this->assertInstanceOf( Form::class, $updated );
		$this->assertNotInstanceOf( WP_Error::class, $updated );
		$this->assertSame( $form->schema_version(), $updated->schema_version() );
		$this->assertSame( array(), $updated->identity_fields() );
		$table = Schema::identities_table();
		$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE form_id = %d", $form->id() ) ) );
		$this->assertNotNull( ( new SubmissionRepository() )->find( $stored->submission()->id() ) );
	}

	/**
	 * Persist a form with an email field and a text field.
	 *
	 * @param string[] $identity Identifying field names.
	 */
	private function form( array $identity ): Form {
		$saved = ( new FormRepository() )->insert(
			new Form(
				0,
				'',
				'Contact',
				true,
				array(
					new Field( 'email', 'Email', FieldType::Email, true, 255 ),
					new Field( 'note', 'Note', FieldType::Text, false, 255 ),
				),
				1,
				'https://example.com/thanks',
				'',
				'',
				null,
				0,
				null,
				30,
				$identity
			)
		);
		$this->assertInstanceOf( Form::class, $saved );
		return $saved;
	}
}
