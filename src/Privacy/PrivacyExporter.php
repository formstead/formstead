<?php
/**
 * WordPress personal-data exporter.
 *
 * @package Formstead
 */

namespace Formstead\Privacy;

use Formstead\Audit\AuditEvent;
use Formstead\Audit\AuditLog;
use Formstead\Forms\FormRepository;
use Formstead\Submissions\SubmissionRepository;
use Formstead\Submissions\SubmissionRows;
use Formstead\Submissions\SubmissionStatus;
use WP_Error;

/**
 * Exports submissions that belong to one email address.
 *
 * WordPress calls this once per page. The page size is fixed so a person
 * with more submissions than one page is still exported in full. Access is
 * the core `export_others_personal_data` capability, checked by WordPress
 * before this callback runs.
 */
final class PrivacyExporter {

	public const PAGE_SIZE = 100;

	public const GROUP_ID = 'formstead-submissions';

	/**
	 * Register the exporter.
	 */
	public static function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( self::class, 'add_exporter' ) );
		add_action( 'wp_privacy_personal_data_export_file_created', array( self::class, 'clear_pages' ), 10, 4 );
	}

	/**
	 * Add this exporter to the list WordPress already has.
	 *
	 * @param mixed $exporters Registered exporters.
	 * @return array<array-key, mixed>
	 */
	public static function add_exporter( mixed $exporters ): array {
		if ( ! is_array( $exporters ) ) {
			$exporters = array();
		}

		$exporters['formstead'] = array(
			'exporter_friendly_name' => __( 'Formstead submissions', 'formstead' ),
			'callback'               => array( self::class, 'export' ),
		);

		return $exporters;
	}

	/**
	 * One page of submissions for an address.
	 *
	 * @param string $email_address Address WordPress is exporting.
	 * @param int    $page          Page number, starting at 1.
	 * @param int    $request_id    Request id for PHP callers; AJAX resolves the validated core request.
	 * @return array{data: list<array{group_id: string, group_label: string, item_id: string, data: list<array{name: string, value: string}>}>, done: bool}|WP_Error
	 */
	public static function export( string $email_address, int $page = 1, int $request_id = 0 ): array|WP_Error {
		$pending = self::pending_error();
		if ( $pending instanceof WP_Error ) {
			return $pending;
		}

		$pages = PrivacyExportPages::read( $request_id > 0 ? $request_id : self::ajax_request_id(), $email_address, max( 1, $page ) );
		if ( $pages instanceof WP_Error ) {
			return $pages;
		}

		$data = array();
		foreach ( $pages['ids'] as $id ) {
			$item = self::item( $id );
			if ( null !== $item ) {
				$data[] = $item;
			}
		}

		if ( array() !== $data ) {
			AuditLog::record(
				AuditEvent::PRIVACY_EXPORTED,
				'privacy',
				0,
				array( 'count' => count( $data ) )
			);
		}

		return array(
			'data' => $data,
			'done' => $pages['done'],
		);
	}

	/**
	 * Error when the index is not finished, or null when export can run.
	 */
	private static function pending_error(): ?WP_Error {
		if ( ! IdentityBackfill::is_pending() ) {
			return null;
		}

		return new WP_Error(
			'formstead_identity_index_pending',
			sprintf(
				/* translators: %s: WP-CLI command. */
				__( 'The identity index is still being built. Run %s to finish it before exporting or erasing personal data.', 'formstead' ),
				'wp formstead identities rebuild'
			)
		);
	}

	/**
	 * Resolve only a core privacy request with its own capability and nonce.
	 */
	private static function ajax_request_id(): int {
		$id    = isset( $_POST['id'] ) && is_scalar( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$nonce = isset( $_POST['security'] ) && is_string( $_POST['security'] ) ? sanitize_text_field( wp_unslash( $_POST['security'] ) ) : '';
		if ( $id < 1 || ! current_user_can( 'export_others_personal_data' ) || ! wp_verify_nonce( $nonce, 'wp-privacy-export-personal-data-' . $id ) ) {
			return 0;
		}
		return $id;
	}

	/**
	 * Remove temporary page boundaries once core has built the export file.
	 *
	 * @param mixed $archive_path Archive path. Unused.
	 * @param mixed $archive_url  Archive URL. Unused.
	 * @param mixed $html_path    HTML path. Unused.
	 * @param int   $request_id   WordPress privacy request id.
	 */
	public static function clear_pages( mixed $archive_path, mixed $archive_url, mixed $html_path, int $request_id ): void {
		unset( $archive_path, $archive_url, $html_path );
		delete_post_meta( $request_id, PrivacyExportPages::META_KEY );
	}

	/**
	 * One export item, or null when the submission is already gone.
	 *
	 * @param int $id Submission id.
	 * @return array{group_id: string, group_label: string, item_id: string, data: list<array{name: string, value: string}>}|null
	 */
	private static function item( int $id ): ?array {
		$submission = ( new SubmissionRepository() )->find( $id );
		if ( null === $submission ) {
			return null;
		}

		$form = ( new FormRepository() )->find_by_id( $submission->form_id() );
		$data = array(
			array(
				'name'  => __( 'Receipt', 'formstead' ),
				'value' => $submission->receipt_id(),
			),
			array(
				'name'  => __( 'Form', 'formstead' ),
				'value' => null === $form ? sprintf(
					/* translators: %d: form id. */
					__( 'Form #%d', 'formstead' ),
					$submission->form_id()
				) : $form->name(),
			),
			array(
				'name'  => __( 'Date', 'formstead' ),
				'value' => $submission->created_at(),
			),
			array(
				'name'  => __( 'Status', 'formstead' ),
				'value' => SubmissionStatus::label( $submission->status() ),
			),
		);

		foreach ( SubmissionRows::for_submission( $submission, $form ) as $row ) {
			$value = $row['value'];
			if ( array() !== $row['items'] ) {
				$value = implode( ', ', $row['items'] );
			}
			$data[] = array(
				'name'  => $row['label'],
				'value' => $value,
			);
		}

		return array(
			'group_id'    => self::GROUP_ID,
			'group_label' => __( 'Formstead submissions', 'formstead' ),
			'item_id'     => 'submission-' . $submission->id(),
			'data'        => $data,
		);
	}
}
