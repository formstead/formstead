<?php
/**
 * Tests for the audit log.
 *
 * @package Formstead
 */

namespace Formstead\Tests\Audit;

use Formstead\Admin\FormsPage;
use Formstead\Admin\SettingsPage;
use Formstead\Admin\SubmissionsExport;
use Formstead\Admin\SubmissionsPage;
use Formstead\Audit\AuditEvent;
use Formstead\Database\Schema;
use Formstead\Forms\Form;
use Formstead\Forms\FormRepository;
use Formstead\Retention\RetentionRunner;
use Formstead\Submissions\Submission;
use Formstead\Submissions\SubmissionIntake;
use Formstead\Submissions\SubmissionQuery;
use Formstead\Submissions\SubmissionRepository;
use WP_UnitTestCase;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the test reads the audit rows it caused.
// phpcs:disable WordPress.Security.NonceVerification -- the test supplies the nonce that the screen checks.

/**
 * Operations are recorded without the submission, and a failed insert does not undo a delete.
 */
final class AuditLogTest extends WP_UnitTestCase {

	private const EMAIL = 'marker-audit-9f3c@example.com';

	private const TEXT = 'marker-audit-text-9f3c';

	private const AREA = 'marker-audit-area-9f3c';

	private const NAME = 'marker-audit-name-9f3c';

	private const LABEL = 'marker-audit-label-9f3c';

	/**
	 * Forget the screen request.
	 */
	public function tear_down(): void {
		$_POST                     = array();
		$_GET                      = array();
		$_REQUEST                  = array();
		$_SERVER['REQUEST_METHOD'] = 'GET';
		parent::tear_down();
	}

	/**
	 * Every audited operation leaves the markers and the address out of the log.
	 */
	public function test_audit_rows_do_not_contain_the_submission(): void {
		global $wpdb;

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		$table  = Schema::audit_table();
		$before = (int) $wpdb->get_var( "SELECT COALESCE(MAX(id), 0) FROM {$table}" );
		$form   = $this->create_form();
		$this->update_retention( $form );

		$first  = $this->store( $form );
		$second = $this->store( $form );
		$this->export( $form );
		$this->delete_single( $first );
		$this->delete_bulk( $second );
		$this->save_settings();

		wp_set_current_user( 0 );
		$report = ( new RetentionRunner() )->run( 1, null );
		$this->assertIsArray( $report );

		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT event, actor_id, context FROM {$table} WHERE id > %d", $before ), ARRAY_A );
		$this->assertIsArray( $rows );
		$events = array();
		foreach ( $rows as $row ) {
			$this->assertIsArray( $row );
			$encoded = (string) wp_json_encode( $row );
			$this->assertStringNotContainsString( 'marker-audit', $encoded );
			$this->assertStringNotContainsString( self::EMAIL, $encoded );
			$events[] = (string) $row['event'];
			if ( AuditEvent::RETENTION_RUN === $row['event'] ) {
				$this->assertSame( '0', (string) $row['actor_id'] );
			}
			if ( AuditEvent::FORM_CREATED === $row['event'] ) {
				$this->assertSame( (string) $user_id, (string) $row['actor_id'] );
			}
		}

		foreach (
			array(
				AuditEvent::FORM_CREATED,
				AuditEvent::FORM_UPDATED,
				AuditEvent::SETTINGS_UPDATED,
				AuditEvent::SUBMISSIONS_EXPORTED,
				AuditEvent::SUBMISSIONS_DELETED,
				AuditEvent::RETENTION_RUN,
			) as $event
		) {
			$this->assertContains( $event, $events, $event );
		}
	}

	/**
	 * A failed audit insert leaves the deletion in place and writes nothing to the log.
	 */
	public function test_a_failed_audit_insert_does_not_undo_the_delete_or_log_it(): void {
		global $wpdb;

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$form   = $this->create_form();
		$stored = $this->store( $form );
		$table  = Schema::audit_table();
		$before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		$filter = static function ( $query ) use ( $table ) {
			if ( is_string( $query ) && str_contains( $query, 'INSERT' ) && str_contains( $query, $table ) ) {
				return 'INSERT INTO ' . $table . '_missing (id) VALUES (1)';
			}

			return $query;
		};

		$previous_log = ini_get( 'error_log' );
		$log_file     = tempnam( sys_get_temp_dir(), 'formstead' );
		$this->assertIsString( $log_file );
		// phpcs:ignore WordPress.PHP.IniSet.Risky -- capture this test's error_log line only.
		ini_set( 'error_log', $log_file );
		add_filter( 'query', $filter );

		try {
			$this->delete_single( $stored );
		} finally {
			remove_filter( 'query', $filter );
			// phpcs:ignore WordPress.PHP.IniSet.Risky -- restore the previous error_log destination.
			ini_set( 'error_log', is_string( $previous_log ) ? $previous_log : '' );
		}

		$this->assertNull( ( new SubmissionRepository() )->find( $stored ) );
		$this->assertSame( $before, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local log file.
		$log = is_readable( $log_file ) ? (string) file_get_contents( $log_file ) : '';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- local log file.
		unlink( $log_file );
		$this->assertStringNotContainsString( self::EMAIL, $log );
		$this->assertStringNotContainsString( self::TEXT, $log );
		$this->assertStringNotContainsString( $table, $log );
	}

	/**
	 * Create a form through the screen, with a marker in the name and the label.
	 */
	private function create_form(): Form {
		$this->post_form( 0, 0, '30' );
		$this->redirect( array( FormsPage::class, 'handle_load' ) );

		foreach ( ( new FormRepository() )->all() as $form ) {
			if ( self::NAME === $form->name() ) {
				return $form;
			}
		}

		$this->fail( 'Expected the form to be saved.' );
	}

	/**
	 * Change retention through the screen.
	 *
	 * @param Form $form Form to update.
	 */
	private function update_retention( Form $form ): void {
		$this->post_form( $form->id(), $form->schema_version(), '7' );
		$this->redirect( array( FormsPage::class, 'handle_load' ) );
	}

	/**
	 * Store one submission whose every field carries a marker.
	 *
	 * @param Form $form Form that receives it.
	 */
	private function store( Form $form ): int {
		$stored = ( new SubmissionIntake() )->accept(
			$form,
			array(
				'email' => self::EMAIL,
				'note'  => self::TEXT,
				'body'  => self::AREA,
			),
			null
		);
		$saved  = $stored->submission();
		$this->assertInstanceOf( Submission::class, $saved );
		return $saved->id();
	}

	/**
	 * Export the form.
	 *
	 * @param Form $form Form to export.
	 */
	private function export( Form $form ): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- the CSV is captured in memory.
		$handle = fopen( 'php://temp', 'wb+' );
		$this->assertNotFalse( $handle );
		SubmissionsExport::write( $form, SubmissionQuery::from_values( $form->id(), SubmissionQuery::VIEW_ALL, '', '' ), $handle );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- the CSV is captured in memory.
		fclose( $handle );
	}

	/**
	 * Delete one submission from its detail screen.
	 *
	 * @param int $id Submission id.
	 */
	private function delete_single( int $id ): void {
		$nonce                     = wp_create_nonce( 'formstead_submission_' . $id );
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array(
			'formstead_single' => '1',
			'submission_id'    => (string) $id,
			'formstead_action' => 'delete',
			'_wpnonce'         => $nonce,
		);
		$_REQUEST                  = $_POST;
		$this->redirect( array( SubmissionsPage::class, 'handle_load' ) );
	}

	/**
	 * Delete one submission with the bulk action.
	 *
	 * @param int $id Submission id.
	 */
	private function delete_bulk( int $id ): void {
		$nonce                     = wp_create_nonce( 'bulk-submissions' );
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array(
			'action'     => 'delete',
			'submission' => array( (string) $id ),
			'_wpnonce'   => $nonce,
		);
		$_REQUEST                  = $_POST;
		$this->redirect( array( SubmissionsPage::class, 'handle_load' ) );
	}

	/**
	 * Save the uninstall choice.
	 */
	private function save_settings(): void {
		$nonce                     = wp_create_nonce( 'formstead_save_settings' );
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array(
			'delete_data_on_uninstall' => '1',
			'_wpnonce'                 => $nonce,
		);
		$_REQUEST                  = $_POST;
		$this->redirect( array( SettingsPage::class, 'handle_load' ) );
	}

	/**
	 * Post the form screen.
	 *
	 * @param int    $form_id Form id, or zero for a new form.
	 * @param int    $version Schema version the screen last loaded.
	 * @param string $days    Retention days.
	 */
	private function post_form( int $form_id, int $version, string $days ): void {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array(
			'form_id'        => (string) $form_id,
			'schema_version' => (string) $version,
			'name'           => self::NAME,
			'is_enabled'     => '1',
			'success_url'    => 'https://example.com/thanks',
			'retention_days' => $days,
			'fields'         => array(
				array(
					'name'       => 'email',
					'label'      => self::LABEL,
					'type'       => 'email',
					'required'   => '1',
					'max_length' => '255',
				),
				array(
					'name'       => 'note',
					'label'      => 'Note',
					'type'       => 'text',
					'max_length' => '255',
				),
				array(
					'name'       => 'body',
					'label'      => 'Body',
					'type'       => 'textarea',
					'max_length' => '1000',
				),
				array(
					'name'       => '',
					'label'      => '',
					'type'       => '',
					'max_length' => '',
				),
			),
		);
		$nonce                     = wp_create_nonce( 'formstead_save_form' );
		$_POST['_wpnonce']         = $nonce;
		$_REQUEST                  = $_POST;
	}

	/**
	 * Run a handler that redirects, then continue.
	 *
	 * @param callable $handler Screen handler.
	 */
	private function redirect( callable $handler ): void {
		$filter = static function ( $location ) {
			throw new \RuntimeException( esc_html( (string) $location ) );
		};
		add_filter( 'wp_redirect', $filter );

		try {
			$handler();
			$this->fail( 'Expected a redirect.' );
		} catch ( \RuntimeException $exception ) {
			$this->assertNotSame( '', $exception->getMessage() );
		} finally {
			remove_filter( 'wp_redirect', $filter );
		}
	}
}
