<?php
/**
 * Index of hashed identifying values.
 *
 * @package Formstead
 */

namespace Formstead\Privacy;

use Formstead\Database\Schema;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- table name is an identifier. Dynamic IN lists are unpacked.

/**
 * Stores HMAC values for identifying fields. The address itself is not stored.
 */
final class IdentityRepository {

	/**
	 * Store one row per identifying field.
	 *
	 * Called inside the submission transaction. A failed insert leaves the
	 * error suppressed so the address cannot reach the log through the query.
	 *
	 * @param int                   $submission_id Submission id.
	 * @param int                   $form_id       Form id.
	 * @param array<string, string> $hashes       Field name to HMAC.
	 * @param string                $created_at    UTC `Y-m-d H:i:s`.
	 */
	public function insert_for_submission( int $submission_id, int $form_id, array $hashes, string $created_at ): bool {
		global $wpdb;

		if ( array() === $hashes ) {
			return true;
		}

		if ( $submission_id < 1 || $form_id < 1 ) {
			return false;
		}

		$table = Schema::identities_table();
		foreach ( $hashes as $field => $hash ) {
			if ( 1 !== preg_match( '/\A[a-z][a-z0-9_]{0,63}\z/', $field ) || 1 !== preg_match( '/\A[a-f0-9]{64}\z/', $hash ) ) {
				return false;
			}

			$previous = $wpdb->suppress_errors( true );
			$saved    = $wpdb->insert(
				$table,
				array(
					'submission_id' => $submission_id,
					'form_id'       => $form_id,
					'field'         => $field,
					'value_hash'    => $hash,
					'created_at'    => $created_at,
				),
				array( '%d', '%d', '%s', '%s', '%s' )
			);
			$wpdb->suppress_errors( $previous );

			if ( false === $saved ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- form id only, never the address or the query.
				error_log( sprintf( 'Formstead could not store an identity index for form %d.', $form_id ) );
				return false;
			}
		}

		return true;
	}

	/**
	 * Store identifying hashes that are not indexed yet.
	 *
	 * A repeated backfill can see the same submission. Existing fields stay,
	 * so a second pass does not create a second row.
	 *
	 * @param int                   $submission_id Submission id.
	 * @param int                   $form_id       Form id.
	 * @param array<string, string> $hashes       Field name to HMAC.
	 * @param string                $created_at    UTC `Y-m-d H:i:s`.
	 */
	public function sync_submission( int $submission_id, int $form_id, array $hashes, string $created_at ): bool {
		if ( array() === $hashes ) {
			return true;
		}

		$present = $this->fields_for_submission( $submission_id );
		$missing = array();
		foreach ( $hashes as $field => $hash ) {
			if ( ! isset( $present[ $field ] ) ) {
				$missing[ $field ] = $hash;
			}
		}

		return $this->insert_for_submission( $submission_id, $form_id, $missing, $created_at );
	}

	/**
	 * Field names already indexed for one submission.
	 *
	 * @param int $submission_id Submission id.
	 * @return array<string, true>
	 */
	private function fields_for_submission( int $submission_id ): array {
		global $wpdb;

		if ( $submission_id < 1 ) {
			return array();
		}

		$table = Schema::identities_table();
		$rows  = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT field FROM {$table} WHERE submission_id = %d",
				$submission_id
			)
		);
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$fields = array();
		foreach ( $rows as $field ) {
			if ( is_string( $field ) && '' !== $field ) {
				$fields[ $field ] = true;
			}
		}

		return $fields;
	}

	/**
	 * Delete index rows for these submissions.
	 *
	 * @param int[] $submission_ids Submission ids.
	 * @return bool False when the delete query fails.
	 */
	public function delete_for_submissions( array $submission_ids ): bool {
		global $wpdb;

		$ids = array();
		foreach ( $submission_ids as $id ) {
			if ( is_int( $id ) && $id > 0 ) {
				$ids[ $id ] = $id;
			}
		}
		if ( array() === $ids ) {
			return true;
		}

		$ids          = array_values( $ids );
		$table        = Schema::identities_table();
		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		$deleted      = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE submission_id IN ( {$placeholders} )",
				...$ids
			)
		);

		return false !== $deleted;
	}

	/**
	 * Delete every index row for one field on one form.
	 *
	 * @param int    $form_id Form id.
	 * @param string $field   Field name.
	 */
	public function delete_for_field( int $form_id, string $field ): bool {
		global $wpdb;

		if ( $form_id < 1 || 1 !== preg_match( '/\A[a-z][a-z0-9_]{0,63}\z/', $field ) ) {
			return false;
		}

		$deleted = $wpdb->delete(
			Schema::identities_table(),
			array(
				'form_id' => $form_id,
				'field'   => $field,
			),
			array( '%d', '%s' )
		);

		return false !== $deleted;
	}

	/**
	 * Delete index rows for fields that are no longer identifying.
	 *
	 * An empty keep list deletes every row for the form. Repeating the call
	 * after a saved form is safe: rows that should stay are not matched.
	 *
	 * @param int      $form_id Form id.
	 * @param string[] $keep    Field names that are still identifying.
	 */
	public function delete_except( int $form_id, array $keep ): bool {
		global $wpdb;

		if ( $form_id < 1 ) {
			return false;
		}

		$names = array();
		foreach ( $keep as $name ) {
			if ( is_string( $name ) && 1 === preg_match( '/\A[a-z][a-z0-9_]{0,63}\z/', $name ) ) {
				$names[ $name ] = $name;
			}
		}

		$table = Schema::identities_table();
		if ( array() === $names ) {
			$deleted = $wpdb->delete( $table, array( 'form_id' => $form_id ), array( '%d' ) );
			return false !== $deleted;
		}

		$names        = array_values( $names );
		$placeholders = implode( ', ', array_fill( 0, count( $names ), '%s' ) );
		$deleted      = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE form_id = %d AND field NOT IN ( {$placeholders} )",
				$form_id,
				...$names
			)
		);

		return false !== $deleted;
	}

	/**
	 * Submission ids whose identifying value matches this HMAC.
	 *
	 * @param string $hash   HMAC-SHA256 hex.
	 * @param int    $limit  Maximum rows.
	 * @param int    $offset Rows to skip.
	 * @return list<int>
	 */
	public function submission_ids_for_hash( string $hash, int $limit, int $offset = 0 ): array {
		global $wpdb;

		if ( 1 !== preg_match( '/\A[a-f0-9]{64}\z/', $hash ) ) {
			return array();
		}

		$table = Schema::identities_table();
		$rows  = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT submission_id FROM {$table} WHERE value_hash = %s ORDER BY submission_id ASC LIMIT %d OFFSET %d",
				$hash,
				max( 1, $limit ),
				max( 0, $offset )
			)
		);
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$ids = array();
		foreach ( $rows as $row ) {
			$id = (int) $row;
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}

		return $ids;
	}
}
