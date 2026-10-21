<?php
/**
 * Tests for inbox actions.
 *
 * @package Formstead
 */

namespace Formstead\Tests\Admin;

// phpcs:disable WordPress.Security.NonceVerification -- the test posts the inbox action, including its nonce.

use Formstead\Admin\SubmissionsExport;
use Formstead\Admin\SubmissionsPage;
use Formstead\Forms\Field;
use Formstead\Forms\FieldType;
use Formstead\Forms\Form;
use Formstead\Forms\FormRepository;
use Formstead\Security\Capabilities;
use Formstead\Submissions\SubmissionRepository;
use WP_User;

/**
 * Bulk status changes and deletion permission.
 */
final class SubmissionActionsTest extends \WP_UnitTestCase {

	/**
	 * Clear the request between tests.
	 */
	public function tear_down(): void {
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		unset( $_SERVER['REQUEST_METHOD'] );
		parent::tear_down();
	}

	/**
	 * A bulk action changes only the checked rows.
	 */
	public function test_bulk_mark_as_spam_changes_the_checked_rows(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$repository = new SubmissionRepository();
		$first      = $this->store( 'first' );
		$second     = $this->store( 'second' );
		$this->post_bulk( 'mark_spam', array( $first ) );

		$this->assertSame( 'spam', $repository->find( $first )->status() );
		$this->assertSame( 'new', $repository->find( $second )->status() );
	}

	/**
	 * A posted action without a nonce does not change the submission.
	 */
	public function test_bulk_action_without_a_nonce_changes_nothing(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$id                        = $this->store( 'kept' );
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST['action']           = 'mark_spam';
		$_POST['submission']       = array( (string) $id );
		$_POST['_wpnonce']         = 'not-a-nonce';
		$_REQUEST['_wpnonce']      = 'not-a-nonce';

		try {
			SubmissionsPage::handle_load();
			$this->fail( 'Expected a missing nonce to stop the action.' );
		} catch ( \WPDieException $exception ) {
			$this->assertNotEmpty( $exception->getMessage() );
		}

		$this->assertSame( 'new', ( new SubmissionRepository() )->find( $id )->status() );
	}

	/**
	 * Reading submissions does not allow deleting them.
	 */
	public function test_reader_without_delete_capability_cannot_delete(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$user    = new WP_User( $user_id );
		$user->add_cap( Capabilities::READ_SUBMISSIONS );
		wp_set_current_user( $user_id );
		$id = $this->store( 'kept' );

		$nonce                     = wp_create_nonce( 'formstead_submission_' . $id );
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST['formstead_single'] = '1';
		$_POST['submission_id']    = (string) $id;
		$_POST['formstead_action'] = 'delete';
		$_POST['_wpnonce']         = $nonce;
		$_REQUEST['_wpnonce']      = $nonce;

		try {
			SubmissionsPage::handle_load();
			$this->fail( 'Expected deletion to be rejected.' );
		} catch ( \WPDieException $exception ) {
			$this->assertStringContainsString( 'not allowed to delete', $exception->getMessage() );
		}

		$this->assertNotNull( ( new SubmissionRepository() )->find( $id ) );
	}

	/**
	 * A person who can read but not export is refused.
	 */
	public function test_reader_without_export_capability_cannot_export(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$user    = new WP_User( $user_id );
		$user->add_cap( Capabilities::READ_SUBMISSIONS );
		wp_set_current_user( $user_id );

		try {
			SubmissionsExport::handle();
			$this->fail( 'Expected the export to be rejected.' );
		} catch ( \WPDieException $exception ) {
			$this->assertStringContainsString( 'not allowed to export', $exception->getMessage() );
		}
	}

	/**
	 * Exporting does not grant deletion.
	 */
	public function test_exporter_without_delete_capability_cannot_delete(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$user    = new WP_User( $user_id );
		$user->add_cap( Capabilities::READ_SUBMISSIONS );
		$user->add_cap( Capabilities::EXPORT_SUBMISSIONS );
		wp_set_current_user( $user_id );
		$id = $this->store( 'kept-by-exporter' );
		$this->post_bulk( 'delete', array( $id ), false );

		$this->assertNotNull( ( new SubmissionRepository() )->find( $id ) );
	}

	/**
	 * Post a bulk action and stop the redirect.
	 *
	 * @param string $action Bulk action.
	 * @param int[]  $ids    Submission ids.
	 * @param bool   $expect_redirect Whether the action should redirect instead of dying.
	 * @throws \WPDieException When the action is rejected and a redirect was expected.
	 */
	private function post_bulk( string $action, array $ids, bool $expect_redirect = true ): void {
		$nonce                     = wp_create_nonce( 'bulk-submissions' );
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST['action']           = $action;
		$_POST['submission']       = array_map( 'strval', $ids );
		$_POST['_wpnonce']         = $nonce;
		$_REQUEST['_wpnonce']      = $nonce;

		add_filter( 'wp_redirect', array( $this, 'stop_redirect' ) );

		try {
			SubmissionsPage::handle_load();
			if ( $expect_redirect ) {
				$this->fail( 'Expected a redirect after the bulk action.' );
			}
		} catch ( \WPDieException $exception ) {
			if ( $expect_redirect ) {
				throw $exception;
			}
			$this->assertStringContainsString( 'not allowed to delete', $exception->getMessage() );
		} catch ( \RuntimeException $exception ) {
			$this->assertSame( 'redirect', $exception->getMessage() );
			if ( ! $expect_redirect ) {
				$this->fail( 'Expected the action to be rejected.' );
			}
		} finally {
			remove_filter( 'wp_redirect', array( $this, 'stop_redirect' ) );
		}
	}

	/**
	 * Stop a redirect so the test can continue.
	 *
	 * @param mixed $location Redirect target. Unused.
	 * @throws \RuntimeException Always.
	 */
	public function stop_redirect( mixed $location ): void {
		unset( $location );
		throw new \RuntimeException( 'redirect' );
	}

	/**
	 * Store one note and return its id.
	 *
	 * @param string $note Note value.
	 */
	private function store( string $note ): int {
		$saved_form = ( new FormRepository() )->insert(
			new Form(
				0,
				'',
				'Contact',
				true,
				array( new Field( 'note', 'Note', FieldType::Textarea, false, 1000 ) ),
				1,
				'https://example.com/thanks',
				'',
				''
			)
		);
		$this->assertInstanceOf( Form::class, $saved_form );
		$saved = ( new SubmissionRepository() )->insert( $saved_form, array( 'note' => $note ) );
		$this->assertInstanceOf( \Formstead\Submissions\Submission::class, $saved );
		return $saved->id();
	}
}
