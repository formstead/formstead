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
	 * @param Form                                    $form   Form that received the submission.
	 * @param array<string, string|bool|list<string>> $values Normalized field values.
	 */
	public function insert( Form $form, array $values ): Submission|WP_Error {
		global $wpdb;

		$payload = wp_json_encode( $values, JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $payload ) ) {
			$this->log_failure( $form->id() );
			return new WP_Error(
				'formstead_submission_save_failed',
				__( 'The submission could not be saved.', 'formstead' )
			);
		}

		$created_at = current_time( 'mysql', true );

		for ( $attempt = 0; $attempt < 3; $attempt++ ) {
			$receipt  = ReceiptId::generate();
			$previous = $wpdb->suppress_errors( true );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
			$saved     = $wpdb->insert(
				Schema::submissions_table(),
				array(
					'form_id'        => $form->id(),
					'schema_version' => $form->schema_version(),
					'receipt_id'     => $receipt,
					'payload'        => $payload,
					'status'         => 'new',
					'created_at'     => $created_at,
				),
				array( '%d', '%d', '%s', '%s', '%s', '%s' )
			);
			$duplicate = false !== stripos( (string) $wpdb->last_error, 'Duplicate' );
			$wpdb->suppress_errors( $previous );

			if ( false !== $saved ) {
				return new Submission(
					(int) $wpdb->insert_id,
					$form->id(),
					$form->schema_version(),
					$receipt,
					$values,
					'new',
					$created_at
				);
			}

			if ( ! $duplicate ) {
				$this->log_failure( $form->id() );
				return new WP_Error(
					'formstead_submission_save_failed',
					__( 'The submission could not be saved.', 'formstead' )
				);
			}
		}

		$this->log_failure( $form->id() );
		return new WP_Error(
			'formstead_submission_save_failed',
			__( 'The submission could not be saved.', 'formstead' )
		);
	}

	/**
	 * One page of submissions, newest first.
	 *
	 * A form id limits the query to that form. The `form_status_created` index
	 * starts with `form_id`, which is the filter used by the inbox.
	 *
	 * @param int|null $form_id  Form id, or null for every form.
	 * @param int      $page     Page number, starting at 1.
	 * @param int      $per_page Page size.
	 */
	public function paginate( ?int $form_id, int $page, int $per_page ): SubmissionList {
		global $wpdb;

		$page     = max( 1, $page );
		$per_page = max( 1, $per_page );
		$offset   = ( $page - 1 ) * $per_page;
		$table    = Schema::submissions_table();

		if ( null !== $form_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is an identifier.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, form_id, schema_version, receipt_id, payload, status, created_at FROM {$table} WHERE form_id = %d ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d", $form_id, $per_page, $offset ), ARRAY_A );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is an identifier.
			$total = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE form_id = %d", $form_id ) );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is an identifier.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, form_id, schema_version, receipt_id, payload, status, created_at FROM {$table} ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d", $per_page, $offset ), ARRAY_A );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is an identifier.
			$total = $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		}

		return new SubmissionList( $this->hydrate_rows( $rows ), (int) $total );
	}

	/**
	 * Load one submission. Reading it does not change its status.
	 *
	 * @param int $id Submission id.
	 */
	public function find( int $id ): ?Submission {
		global $wpdb;

		$table = Schema::submissions_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is an identifier.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, form_id, schema_version, receipt_id, payload, status, created_at FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		return $this->hydrate( is_array( $row ) ? $row : null );
	}

	/**
	 * Build submissions from wpdb rows.
	 *
	 * @param mixed $rows Rows from wpdb.
	 * @return Submission[]
	 */
	private function hydrate_rows( mixed $rows ): array {
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$submissions = array();
		foreach ( $rows as $row ) {
			$submission = $this->hydrate( is_array( $row ) ? $row : null );
			if ( $submission instanceof Submission ) {
				$submissions[] = $submission;
			}
		}

		return $submissions;
	}

	/**
	 * Build a submission from a database row.
	 *
	 * @param array<string, mixed>|null $row Row from wpdb.
	 */
	private function hydrate( ?array $row ): ?Submission {
		if ( ! is_array( $row ) ) {
			return null;
		}

		return new Submission(
			(int) ( $row['id'] ?? 0 ),
			(int) ( $row['form_id'] ?? 0 ),
			(int) ( $row['schema_version'] ?? 0 ),
			(string) ( $row['receipt_id'] ?? '' ),
			$this->decode_payload( (string) ( $row['payload'] ?? '' ) ),
			(string) ( $row['status'] ?? '' ),
			(string) ( $row['created_at'] ?? '' )
		);
	}

	/**
	 * Field values from stored JSON.
	 *
	 * Strings, booleans, and lists of strings are kept. Anything else is skipped.
	 *
	 * @param string $json Stored payload.
	 * @return array<string, string|bool|list<string>>
	 */
	private function decode_payload( string $json ): array {
		$decoded = json_decode( $json, true );
		if ( ! is_array( $decoded ) ) {
			return array();
		}

		$payload = array();
		foreach ( $decoded as $name => $value ) {
			if ( ! is_string( $name ) ) {
				continue;
			}
			if ( is_string( $value ) || is_bool( $value ) ) {
				$payload[ $name ] = $value;
				continue;
			}
			if ( ! is_array( $value ) || ! array_is_list( $value ) ) {
				continue;
			}

			$items = array();
			$valid = true;
			foreach ( $value as $item ) {
				if ( ! is_string( $item ) ) {
					$valid = false;
					break;
				}
				$items[] = $item;
			}
			if ( $valid ) {
				$payload[ $name ] = $items;
			}
		}

		return $payload;
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
