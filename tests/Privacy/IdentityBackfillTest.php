<?php
/**
 * Tests for indexing submissions that were stored before a field was marked.
 *
 * @package Formstead
 */

namespace Formstead\Tests\Privacy;

use Formstead\Cli\IdentitiesCommand;
use Formstead\Database\Migrator;
use Formstead\Database\Schema;
use Formstead\Forms\Field;
use Formstead\Forms\FieldType;
use Formstead\Forms\Form;
use Formstead\Forms\FormRepository;
use Formstead\Delivery\Scheduler;
use Formstead\Lifecycle\Deactivator;
use Formstead\Privacy\IdentityBackfill;
use Formstead\Retention\RetentionScheduler;
use Formstead\Privacy\IdentityHasher;
use Formstead\Submissions\SubmissionIntake;
use Formstead\Tests\TestCase\MigrationTestCase;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- these tests install version 6 and read the index they just built.

/**
 * Covers the upgrade backfill, a resumed batch, and a mark added later.
 */
final class IdentityBackfillTest extends MigrationTestCase {

	/**
	 * Drop the cron event and the lock the tests schedule.
	 */
	public function tear_down(): void {
		IdentityBackfill::clear();
		delete_option( IdentityBackfill::LOCK_OPTION );
		parent::tear_down();
		RetentionScheduler::ensure();
		Scheduler::ensure_sweep();
	}

	/**
	 * An upgrade from version 6 indexes the marked emails and then finishes.
	 *
	 * Spam is indexed too. A text field named `email` is not. The address
	 * itself is not written into the index.
	 */
	public function test_upgrade_from_version_6_indexes_existing_emails(): void {
		global $wpdb;

		$this->install_version_6();

		$this->assertNull( Migrator::maybe_migrate() );
		$this->assertTrue( IdentityBackfill::is_pending() );
		$this->assertStringContainsString( 'wp formstead identities rebuild', IdentityBackfill::message() );

		$this->assertSame( 'done', ( new IdentityBackfill() )->run( null, null ) );
		$this->assertFalse( IdentityBackfill::is_pending() );
		$this->assertSame( 'The identity index is up to date.', IdentityBackfill::message() );

		$table = Schema::identities_table();
		$rows  = $wpdb->get_results( "SELECT field, value_hash FROM {$table} ORDER BY id ASC", ARRAY_A );
		$this->assertIsArray( $rows );
		$this->assertCount( 2, $rows );
		$hash = IdentityHasher::hash( 'Ada@Example.com' );
		$this->assertIsString( $hash );
		foreach ( $rows as $row ) {
			$this->assertIsArray( $row );
			$this->assertSame( 'address', $row['field'] );
			$this->assertSame( $hash, $row['value_hash'] );
		}
		$this->assertStringNotContainsString( 'ada@example.com', (string) wp_json_encode( $rows ) );
		$this->assertStringNotContainsString( 'not-indexed@example.com', (string) wp_json_encode( $rows ) );

		$this->assertSame( 'done', ( new IdentityBackfill() )->run( null, null ) );
		$this->assertCount( 2, $wpdb->get_results( "SELECT id FROM {$table}" ) );
	}

	/**
	 * A stopped batch continues from the last indexed submission.
	 */
	public function test_a_stopped_batch_resumes_without_duplicating_rows(): void {
		global $wpdb;

		$this->assertNull( Migrator::run() );
		$form  = $this->form( array( 'email' ) );
		$first = $this->insert_submission( $form->id(), '{"email":"ada@example.com"}', 'new' );
		$this->insert_submission( $form->id(), '{"email":"ada@example.com"}', 'new' );
		$last = $this->insert_submission( $form->id(), '{"email":"Ada@Example.com "}', 'spam' );
		update_option(
			Migrator::IDENTITY_BACKFILL_OPTION,
			array(
				'status'   => 'pending',
				'after_id' => 0,
				'until_id' => $last,
			),
			false
		);

		$this->assertSame( 'pending', ( new IdentityBackfill() )->run( null, 1, 1 ) );
		$cursor = get_option( Migrator::IDENTITY_BACKFILL_OPTION );
		$this->assertIsArray( $cursor );
		$this->assertSame( $first, $cursor['after_id'] );
		$this->assertSame( 'pending', $cursor['status'] );

		$table = Schema::identities_table();
		$this->assertSame( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE form_id = %d", $form->id() ) ) );

		$this->assertSame( 'done', ( new IdentityBackfill() )->run( null, null, 1 ) );
		$this->assertSame( 3, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE form_id = %d", $form->id() ) ) );
		$this->assertSame( 'done', ( new IdentityBackfill() )->run( null, null, 1 ) );
		$this->assertSame( 3, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE form_id = %d", $form->id() ) ) );
	}

	/**
	 * Marking one form indexes that form and leaves the other form alone.
	 */
	public function test_adding_a_mark_backfills_only_that_form(): void {
		global $wpdb;

		$this->assertNull( Migrator::run() );
		$marked = $this->form( array() );
		$other  = $this->form( array() );
		( new SubmissionIntake() )->accept( $marked, array( 'email' => 'ada@example.com' ), null );
		( new SubmissionIntake() )->accept( $other, array( 'email' => 'bea@example.com' ), null );

		$updated = ( new FormRepository() )->update( $this->copy( $marked, array( 'email' ) ) );
		$this->assertInstanceOf( Form::class, $updated );

		$cursor = get_option( Migrator::IDENTITY_BACKFILL_OPTION );
		$this->assertIsArray( $cursor );
		$this->assertSame( $marked->id(), $cursor['form_id'] );
		$this->assertSame( 'pending', $cursor['status'] );
		$this->assertNotFalse( wp_next_scheduled( IdentityBackfill::HOOK ) );

		$this->assertSame( 'done', ( new IdentityBackfill() )->run( null, null ) );

		$table = Schema::identities_table();
		$this->assertSame( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE form_id = %d", $marked->id() ) ) );
		$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE form_id = %d", $other->id() ) ) );
		$this->assertFalse( wp_next_scheduled( IdentityBackfill::HOOK ) );
	}

	/**
	 * The command indexes one form and reports an unknown public id.
	 */
	public function test_the_command_rebuilds_one_form(): void {
		global $wpdb;

		$this->assertNull( Migrator::run() );
		$form  = $this->form( array( 'email' ) );
		$other = $this->form( array( 'email' ) );
		$this->insert_submission( $form->id(), '{"email":"ada@example.com"}', 'new' );
		$this->insert_submission( $other->id(), '{"email":"bea@example.com"}', 'new' );

		$this->assertSame( 'done', IdentitiesCommand::execute( array( 'form' => $form->public_id() ) ) );

		$table = Schema::identities_table();
		$this->assertSame( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE form_id = %d", $form->id() ) ) );
		$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE form_id = %d", $other->id() ) ) );
		$this->assertSame( 'missing', IdentitiesCommand::execute( array( 'form' => 'fs_not_a_form' ) ) );
		$this->assertSame( 'Form not found.', IdentitiesCommand::messages( 'missing' )[0]['text'] );
	}

	/**
	 * Deactivation removes the cron event and keeps the cursor.
	 */
	public function test_deactivation_clears_the_backfill_event(): void {
		update_option(
			Migrator::IDENTITY_BACKFILL_OPTION,
			array(
				'status'   => 'pending',
				'after_id' => 0,
				'until_id' => 4,
			),
			false
		);
		IdentityBackfill::ensure();
		$this->assertNotFalse( wp_next_scheduled( IdentityBackfill::HOOK ) );

		Deactivator::deactivate();

		$this->assertFalse( wp_next_scheduled( IdentityBackfill::HOOK ) );
		$this->assertIsArray( get_option( Migrator::IDENTITY_BACKFILL_OPTION ) );
	}

	/**
	 * A lock held by another run is left alone.
	 */
	public function test_a_current_lock_is_not_stolen(): void {
		$this->assertNull( Migrator::run() );
		update_option(
			Migrator::IDENTITY_BACKFILL_OPTION,
			array(
				'status'   => 'pending',
				'after_id' => 0,
				'until_id' => 4,
			),
			false
		);
		add_option( IdentityBackfill::LOCK_OPTION, wp_generate_uuid4() . '|' . time(), '', false );

		$this->assertSame( 'locked', ( new IdentityBackfill() )->run( null, null ) );
		$this->assertSame( 'The identity index is already being built.', IdentitiesCommand::messages( 'locked' )[0]['text'] );
	}

	/**
	 * Install schema version 6 with one email form and one form that has none.
	 */
	private function install_version_6(): void {
		global $wpdb;

		$this->assertNull( Migrator::run() );

		$forms       = Schema::forms_table();
		$submissions = Schema::submissions_table();
		$identities  = Schema::identities_table();
		$audit       = Schema::audit_table();
		$wpdb->query( "DROP TABLE IF EXISTS {$identities}" );
		$wpdb->query( "DROP TABLE IF EXISTS {$audit}" );
		$wpdb->query( "ALTER TABLE {$forms} DROP COLUMN identity_fields" );

		$wpdb->insert(
			$forms,
			array(
				'public_id'      => 'fs_abcdefghijklmnop',
				'name'           => 'Contact',
				'is_enabled'     => 1,
				'fields'         => '[{"name":"email","label":"Not an address","type":"text","required":false,"max_length":255},{"name":"address","label":"Email","type":"email","required":true,"max_length":255}]',
				'schema_version' => 1,
				'success_url'    => 'https://example.com/thanks',
				'created_at'     => '2026-10-20 10:00:00',
				'updated_at'     => '2026-10-20 11:00:00',
			)
		);
		$with_email = (int) $wpdb->insert_id;
		$wpdb->insert(
			$forms,
			array(
				'public_id'      => 'fs_bbcdefghijklmnop',
				'name'           => 'Note',
				'is_enabled'     => 1,
				'fields'         => '[{"name":"note","label":"Note","type":"textarea","required":false,"max_length":1000}]',
				'schema_version' => 1,
				'success_url'    => 'https://example.com/thanks',
				'created_at'     => '2026-10-20 10:00:00',
				'updated_at'     => '2026-10-20 11:00:00',
			)
		);
		$without_email = (int) $wpdb->insert_id;

		$this->insert_submission( $with_email, '{"email":"not-indexed@example.com","address":" Ada@Example.com "}', 'new' );
		$this->insert_submission( $with_email, '{"address":"Ada@Example.com"}', 'spam' );
		$this->insert_submission( $with_email, '{"address":"   "}', 'new' );
		$this->insert_submission( $without_email, '{"note":"ada@example.com"}', 'new' );
		update_option( Schema::VERSION_OPTION, 6 );
	}

	/**
	 * Persist a form with an email field.
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

	/**
	 * Copy a form with a different identifying list.
	 *
	 * @param Form     $form     Form to copy.
	 * @param string[] $identity Identifying field names.
	 */
	private function copy( Form $form, array $identity ): Form {
		return new Form(
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
			$identity
		);
	}

	/**
	 * Store one submission without touching the identity index.
	 *
	 * @param int    $form_id Form id.
	 * @param string $payload JSON payload.
	 * @param string $status  Submission status.
	 */
	private function insert_submission( int $form_id, string $payload, string $status ): int {
		global $wpdb;

		$wpdb->insert(
			Schema::submissions_table(),
			array(
				'form_id'        => $form_id,
				'schema_version' => 1,
				'receipt_id'     => 'fsr_' . wp_generate_password( 16, false, false ),
				'payload'        => $payload,
				'status'         => $status,
				'created_at'     => '2026-10-20 12:00:00',
			)
		);

		return (int) $wpdb->insert_id;
	}
}
