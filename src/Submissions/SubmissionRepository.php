<?php
/**
 * Submission persistence.
 *
 * @package Formstead
 */

namespace Formstead\Submissions;

use Formstead\Database\Schema;
use Formstead\Forms\Form;
use WP_Error;

/**
 * Stores submissions in the plugin table.
 */
final class SubmissionRepository {

	/**
	 * Store one validated submission.
	 *
	 * Database errors are suppressed for this query so a failed insert cannot
	 * write the submission text into `debug.log`. The failure line names the
	 * form id only.
	 *
	 * @param Form                  $form   Form that received the submission.
	 * @param array<string, string> $values Trimmed field values.
	 */
	public function insert( Form $form, array $values ): int|WP_Error {
		global $wpdb;

		$payload = wp_json_encode( $values, JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $payload ) ) {
			$this->log_failure( $form->id() );
			return new WP_Error(
				'formstead_submission_save_failed',
				__( 'The submission could not be saved.', 'formstead' )
			);
		}

		$previous = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
		$saved = $wpdb->insert(
			Schema::submissions_table(),
			array(
				'form_id'        => $form->id(),
				'schema_version' => $form->schema_version(),
				'payload'        => $payload,
				'status'         => 'new',
				'created_at'     => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%s', '%s', '%s' )
		);
		$wpdb->suppress_errors( $previous );

		if ( false === $saved ) {
			$this->log_failure( $form->id() );
			return new WP_Error(
				'formstead_submission_save_failed',
				__( 'The submission could not be saved.', 'formstead' )
			);
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Record a failed insert without the query or the submission text.
	 *
	 * @param int $form_id Form id.
	 */
	private function log_failure( int $form_id ): void {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- form id only, never the query.
		error_log( sprintf( 'Formstead could not store a submission for form %d.', $form_id ) );
	}
}
