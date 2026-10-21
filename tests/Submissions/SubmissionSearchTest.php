<?php
/**
 * Tests for exact email search in the inbox and the CSV export.
 *
 * @package Formstead
 */

namespace Formstead\Tests\Submissions;

use Formstead\Admin\SubmissionsExport;
use Formstead\Forms\Field;
use Formstead\Forms\FieldType;
use Formstead\Forms\Form;
use Formstead\Forms\FormRepository;
use Formstead\Submissions\Submission;
use Formstead\Submissions\SubmissionIntake;
use Formstead\Submissions\SubmissionQuery;
use Formstead\Submissions\SubmissionRepository;
use Formstead\Submissions\SubmissionStatus;
use WP_UnitTestCase;

/**
 * A different case matches. A different address does not. Spam stays searchable.
 */
final class SubmissionSearchTest extends WP_UnitTestCase {

	/**
	 * The inbox, its counts, and the CSV follow the hashed address.
	 */
	public function test_search_matches_the_address_exactly_and_ignores_case(): void {
		$form = $this->form();
		$repo = new SubmissionRepository();
		$this->store( $form, 'Ada@Example.com', 'from-ada', false );
		$this->store( $form, 'Ada@Example.com', 'from-ada-spam', true );
		$this->store( $form, 'bea@example.com', 'from-bea', false );

		$inbox = SubmissionQuery::from_values( $form->id(), SubmissionQuery::VIEW_INBOX, '', '', ' ADA@example.com ' );
		$page  = $repo->paginate( $inbox, 1, 20 );
		$this->assertSame( 1, $page->total() );
		$this->assertSame( 'from-ada', $page->submissions()[0]->payload()['note'] ?? null );

		$all    = SubmissionQuery::from_values( $form->id(), SubmissionQuery::VIEW_ALL, '', '', 'ada@example.com' );
		$counts = $repo->count_by_status( $all );
		$this->assertSame( 1, $counts[ SubmissionStatus::NEW ] );
		$this->assertSame( 1, $counts[ SubmissionStatus::SPAM ] );
		$this->assertSame( 2, $repo->paginate( $all, 1, 20 )->total() );
		$this->assertCount( 2, $repo->iterate_for_export( $all, null, 20 ) );

		$other = SubmissionQuery::from_values( $form->id(), SubmissionQuery::VIEW_ALL, '', '', 'other@example.com' );
		$this->assertSame( 0, $repo->paginate( $other, 1, 20 )->total() );
		$this->assertSame( array(), $repo->iterate_for_export( $other, null, 20 ) );

		$csv = $this->csv( $form, $all );
		$this->assertStringContainsString( 'from-ada', $csv );
		$this->assertStringContainsString( 'from-ada-spam', $csv );
		$this->assertStringNotContainsString( 'from-bea', $csv );
		$this->assertSame( 'ada@example.com', $all->search() );
		$this->assertNotSame( 'ada@example.com', $all->identity_hash() );
	}

	/**
	 * Persist a form whose email field identifies the person.
	 */
	private function form(): Form {
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
				array( 'email' )
			)
		);
		$this->assertInstanceOf( Form::class, $saved );
		return $saved;
	}

	/**
	 * Store one submission through the normal intake path.
	 *
	 * @param Form   $form  Form that receives it.
	 * @param string $email Email address.
	 * @param string $note  Note stored beside the address.
	 * @param bool   $spam  Whether intake should mark it as spam.
	 */
	private function store( Form $form, string $email, string $note, bool $spam ): void {
		$stored = ( new SubmissionIntake() )->accept(
			$form,
			array(
				'email' => $email,
				'note'  => $note,
			),
			null,
			$spam
		);
		$this->assertInstanceOf( Submission::class, $stored->submission() );
	}

	/**
	 * Render a CSV into a string.
	 *
	 * @param Form            $form  Form to export.
	 * @param SubmissionQuery $query Filters.
	 */
	private function csv( Form $form, SubmissionQuery $query ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- the CSV is captured in memory.
		$handle = fopen( 'php://temp', 'wb+' );
		$this->assertNotFalse( $handle );
		SubmissionsExport::write( $form, $query, $handle, 20 );
		rewind( $handle );
		$csv = stream_get_contents( $handle );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- the CSV is captured in memory.
		fclose( $handle );
		$this->assertIsString( $csv );
		return $csv;
	}
}
