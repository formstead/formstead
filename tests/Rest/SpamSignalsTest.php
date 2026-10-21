<?php
/**
 * Tests for spam signals on the public endpoint.
 *
 * @package Formstead
 */

namespace Formstead\Tests\Rest;

use Formstead\Database\Schema;
use Formstead\Delivery\DeliveryRepository;
use Formstead\Forms\Field;
use Formstead\Forms\FieldType;
use Formstead\Forms\Form;
use Formstead\Forms\FormNotifications;
use Formstead\Forms\FormRepository;
use Formstead\Submissions\SubmissionRepository;
use Formstead\Submissions\SubmissionStatus;
use Formstead\Submissions\UnreadCounter;
use WP_REST_Request;
use WP_REST_Response;

/**
 * A spam signal is stored and looks like success, without an email.
 *
 * @phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
 * @phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
 * @phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
 */
final class SpamSignalsTest extends \WP_UnitTestCase {

	/**
	 * Prepare the REST server.
	 */
	public function set_up(): void {
		parent::set_up();
		rest_get_server();
	}

	/**
	 * A short fill time is accepted as spam and does not queue mail.
	 *
	 * @dataProvider modes
	 * @param string $mode `html` or `json`.
	 */
	public function test_fast_submission_is_spam_without_mail( string $mode ): void {
		$form     = $this->form();
		$response = $this->post( $form, $mode, 'note=hello&_fs_elapsed=1000' );

		$this->assertSame( 'json' === $mode ? 201 : 303, $response->get_status() );
		if ( 'json' === $mode ) {
			$data = $response->get_data();
			$this->assertIsArray( $data );
			$this->assertNotEmpty( $data['receipt_id'] );
		}

		$submission = $this->only( $form->id() );
		$this->assertSame( SubmissionStatus::SPAM, $submission->status() );
		$this->assertSame( array(), ( new DeliveryRepository() )->for_submission( $submission->id() ) );
		$this->assertSame( 0, UnreadCounter::count() );
	}

	/**
	 * Omitting the fill time leaves a normal submission, including its email.
	 */
	public function test_missing_elapsed_field_is_not_spam(): void {
		$form     = $this->form();
		$response = $this->post( $form, 'json', 'note=hello' );

		$this->assertSame( 201, $response->get_status() );
		$submission = $this->only( $form->id() );
		$this->assertSame( SubmissionStatus::NEW, $submission->status() );
		$this->assertCount( 1, ( new DeliveryRepository() )->for_submission( $submission->id() ) );
		$this->assertSame( 1, UnreadCounter::count() );
	}

	/**
	 * The same body inside the window is spam. After the window it is a new submission.
	 */
	public function test_duplicate_body_is_spam_only_inside_the_window(): void {
		global $wpdb;

		$form = $this->form();
		$this->assertSame( 201, $this->post( $form, 'json', 'note=again' )->get_status() );
		$first = $this->only( $form->id() );
		$this->assertSame( SubmissionStatus::NEW, $first->status() );

		$second = $this->post( $form, 'json', 'note=again' );
		$this->assertSame( 201, $second->get_status() );
		$rows = $this->rows( $form->id() );
		$this->assertCount( 2, $rows );
		$this->assertSame( SubmissionStatus::SPAM, $rows[1]->status() );
		$this->assertSame( array(), ( new DeliveryRepository() )->for_submission( $rows[1]->id() ) );

		$table = Schema::submissions_table();
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET created_at = %s WHERE form_id = %d", '2020-01-01 00:00:00', $form->id() ) );

		$third = $this->post( $form, 'json', 'note=again' );
		$this->assertSame( 201, $third->get_status() );
		$rows = $this->rows( $form->id() );
		$this->assertSame( SubmissionStatus::NEW, $rows[2]->status() );
	}

	/**
	 * HTML and JSON.
	 *
	 * @return array<string, array{string}>
	 */
	public function modes(): array {
		return array(
			'html' => array( 'html' ),
			'json' => array( 'json' ),
		);
	}

	/**
	 * Persist a form that would otherwise send mail.
	 */
	private function form(): Form {
		$saved = ( new FormRepository() )->insert(
			new Form(
				0,
				'',
				'Contact',
				true,
				array( new Field( 'note', 'Note', FieldType::Textarea, false, 1000 ) ),
				1,
				'https://example.com/thanks',
				'',
				'',
				null,
				0,
				new FormNotifications( array( 'owner@example.com' ) )
			)
		);
		$this->assertInstanceOf( Form::class, $saved );
		return $saved;
	}

	/**
	 * Post one body.
	 *
	 * @param Form   $form Form to submit.
	 * @param string $mode `html` or `json`.
	 * @param string $body URL-encoded body.
	 */
	private function post( Form $form, string $mode, string $body ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/formstead/v1/forms/' . $form->public_id() . '/submissions' );
		$request->set_header( 'Content-Type', 'application/x-www-form-urlencoded' );
		if ( 'json' === $mode ) {
			$request->set_header( 'Accept', 'application/json' );
		}
		$request->set_body( $body );
		parse_str( $body, $params );
		$request->set_body_params( $params );

		$response = rest_do_request( $request );
		$this->assertInstanceOf( WP_REST_Response::class, $response );
		return $response;
	}

	/**
	 * The only submission for a form.
	 *
	 * @param int $form_id Form id.
	 */
	private function only( int $form_id ): \Formstead\Submissions\Submission {
		$rows = $this->rows( $form_id );
		$this->assertCount( 1, $rows );
		return $rows[0];
	}

	/**
	 * Submissions for a form, oldest first.
	 *
	 * @param int $form_id Form id.
	 * @return \Formstead\Submissions\Submission[]
	 */
	private function rows( int $form_id ): array {
		global $wpdb;

		$table = Schema::submissions_table();
		$ids   = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE form_id = %d ORDER BY id ASC", $form_id ) );
		$rows  = array();
		foreach ( is_array( $ids ) ? $ids : array() as $id ) {
			$submission = ( new SubmissionRepository() )->find( (int) $id );
			if ( $submission instanceof \Formstead\Submissions\Submission ) {
				$rows[] = $submission;
			}
		}
		return $rows;
	}
}
