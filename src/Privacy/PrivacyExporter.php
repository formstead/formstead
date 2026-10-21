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
	 * @return array{data: list<array{group_id: string, group_label: string, item_id: string, data: list<array{name: string, value: string}>}>, done: bool}|WP_Error
	 */
	public static function export( string $email_address, int $page = 1 ): array|WP_Error {
		$pending = self::pending_error();
		if ( $pending instanceof WP_Error ) {
			return $pending;
		}

		$page = max( 1, $page );
		$ids  = self::submission_ids( $email_address, ( $page - 1 ) * self::PAGE_SIZE );
		$data = array();
		foreach ( $ids as $id ) {
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
			'done' => count( $ids ) < self::PAGE_SIZE,
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
	 * Submission ids for one page of this address.
	 *
	 * @param string $email  Address to match.
	 * @param int    $offset Rows to skip.
	 * @return list<int>
	 */
	private static function submission_ids( string $email, int $offset ): array {
		$hash = IdentityHasher::hash( $email );
		if ( ! is_string( $hash ) ) {
			return array();
		}

		return ( new IdentityRepository() )->submission_ids_for_hash( $hash, self::PAGE_SIZE, $offset );
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
