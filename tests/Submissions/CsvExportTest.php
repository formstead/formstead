<?php
/**
 * Tests for CSV cells and the export stream.
 *
 * @package Formstead
 */

namespace Formstead\Tests\Submissions;

use Formstead\Admin\SubmissionsExport;
use Formstead\Database\Schema;
use Formstead\Forms\Field;
use Formstead\Forms\FieldType;
use Formstead\Forms\Form;
use Formstead\Forms\FormRepository;
use Formstead\Submissions\CsvColumns;
use Formstead\Submissions\CsvRow;
use Formstead\Submissions\SubmissionQuery;
use Formstead\Submissions\SubmissionRepository;
use Formstead\Submissions\SubmissionStatus;

/**
 * Formula protection, column union, and export filters.
 *
 * @phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
 * @phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
 */
final class CsvExportTest extends \WP_UnitTestCase {

	/**
	 * Every formula prefix is quoted. Ordinary text is not.
	 *
	 * @dataProvider formula_prefixes
	 * @param string $prefix Character that starts a formula.
	 */
	public function test_formula_prefixes_are_quoted( string $prefix ): void {
		$this->assertSame( "'" . $prefix . '1', CsvRow::guard( $prefix . '1' ) );
	}

	/**
	 * Characters a spreadsheet treats as a formula.
	 *
	 * @return array<string, array{string}>
	 */
	public function formula_prefixes(): array {
		return array(
			'equals' => array( '=' ),
			'plus'   => array( '+' ),
			'minus'  => array( '-' ),
			'at'     => array( '@' ),
			'tab'    => array( "\t" ),
			'cr'     => array( "\r" ),
		);
	}

	/**
	 * Booleans and multiple choices have a stable text form.
	 */
	public function test_booleans_and_choices_are_plain_text(): void {
		$this->assertSame( '1', CsvRow::field( true ) );
		$this->assertSame( '0', CsvRow::field( false ) );
		$this->assertSame( 'a; b', CsvRow::field( array( 'a', 'b' ) ) );
		$this->assertSame( "'=a; b", CsvRow::field( array( '=a', 'b' ) ) );
		$this->assertSame( 'hello', CsvRow::guard( 'hello' ) );
	}

	/**
	 * The file uses the current fields first, then fields that were removed.
	 */
	public function test_export_unions_fields_from_every_schema_version(): void {
		$forms = new FormRepository();
		$form  = $forms->insert(
			new Form(
				0,
				'',
				'Contact',
				true,
				array( new Field( 'name', 'Name', FieldType::Text, false, 100 ) ),
				1,
				'https://example.com/thanks',
				'',
				''
			)
		);
		$this->assertInstanceOf( Form::class, $form );
		$old = ( new SubmissionRepository() )->insert( $form, array( 'name' => 'Ada' ) );
		$this->assertNotInstanceOf( \WP_Error::class, $old );

		$updated = $forms->update(
			new Form(
				$form->id(),
				$form->public_id(),
				$form->name(),
				true,
				array(
					new Field( 'email', 'Email', FieldType::Email, false, 255 ),
					new Field( 'agree', 'Agree', FieldType::Boolean, false, 1 ),
				),
				$form->schema_version(),
				$form->success_url(),
				$form->created_at(),
				$form->updated_at()
			)
		);
		$this->assertInstanceOf( Form::class, $updated );
		$new = ( new SubmissionRepository() )->insert(
			$updated,
			array(
				'email' => '=hidden',
				'agree' => true,
			)
		);
		$this->assertNotInstanceOf( \WP_Error::class, $new );

		$other = $forms->insert(
			new Form(
				0,
				'',
				'Other',
				true,
				array( new Field( 'secret', 'Secret', FieldType::Text, false, 100 ) ),
				1,
				'https://example.com/thanks',
				'',
				''
			)
		);
		$this->assertInstanceOf( Form::class, $other );
		$hidden = ( new SubmissionRepository() )->insert( $other, array( 'secret' => 'not-in-this-file' ) );
		$this->assertNotInstanceOf( \WP_Error::class, $hidden );

		$this->assertSame( array( 'email', 'agree', 'name' ), CsvColumns::fields( $updated ) );

		$csv = $this->csv( $updated, SubmissionQuery::from_values( $updated->id(), SubmissionQuery::VIEW_ALL, '', '' ) );
		$this->assertStringStartsWith( "\xEF\xBB\xBF", $csv );
		$this->assertStringContainsString( 'receipt_id,created_at,status,email,agree,name', $csv );
		$this->assertStringContainsString( "'=hidden", $csv );
		$this->assertStringContainsString( ',1,', $csv );
		$this->assertStringContainsString( 'Ada', $csv );
		$this->assertStringNotContainsString( 'not-in-this-file', $csv );
	}

	/**
	 * The inbox view and the date range limit which rows are written.
	 */
	public function test_export_respects_status_and_date_filters(): void {
		global $wpdb;

		$form = ( new FormRepository() )->insert(
			new Form(
				0,
				'',
				'Contact',
				true,
				array( new Field( 'note', 'Note', FieldType::Text, false, 100 ) ),
				1,
				'https://example.com/thanks',
				'',
				''
			)
		);
		$this->assertInstanceOf( Form::class, $form );
		$repository = new SubmissionRepository();
		$kept = $repository->insert( $form, array( 'note' => 'kept-row' ) );
		$spam = $repository->insert( $form, array( 'note' => 'spam-row' ) );
		$old  = $repository->insert( $form, array( 'note' => 'old-row' ) );
		$this->assertInstanceOf( \Formstead\Submissions\Submission::class, $kept );
		$this->assertInstanceOf( \Formstead\Submissions\Submission::class, $spam );
		$this->assertInstanceOf( \Formstead\Submissions\Submission::class, $old );
		$this->assertSame( 1, $repository->set_status( array( $spam->id() ), SubmissionStatus::SPAM ) );

		$wpdb->update(
			Schema::submissions_table(),
			array( 'created_at' => '2020-01-01 00:00:00' ),
			array( 'id' => $old->id() ),
			array( '%s' ),
			array( '%d' )
		);

		$csv = $this->csv( $form, SubmissionQuery::from_values( $form->id(), SubmissionQuery::VIEW_INBOX, '2026-01-01', '2026-12-31' ) );
		$this->assertStringContainsString( 'kept-row', $csv );
		$this->assertStringNotContainsString( 'spam-row', $csv );
		$this->assertStringNotContainsString( 'old-row', $csv );
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
		SubmissionsExport::write( $form, $query, $handle );
		rewind( $handle );
		$csv = stream_get_contents( $handle );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- the CSV is captured in memory.
		fclose( $handle );
		$this->assertIsString( $csv );
		return $csv;
	}
}
