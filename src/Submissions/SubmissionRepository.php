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

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- table names are identifiers. Dynamic WHERE and IN lists are prepared.

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
	 * @param Form                                    $form         Form that received the submission.
	 * @param array<string, string|bool|list<string>> $values       Normalized field values.
	 * @param string                                  $status       Inbox status.
	 * @param string|null                             $content_hash SHA-256 of the canonical body, or null.
	 */
	public function insert( Form $form, array $values, string $status = SubmissionStatus::NEW, ?string $content_hash = null ): Submission|WP_Error {
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
		$status     = SubmissionStatus::is_known( $status ) ? $status : SubmissionStatus::NEW;
		$hashed     = is_string( $content_hash ) && 1 === preg_match( '/\A[a-f0-9]{64}\z/', $content_hash );

		for ( $attempt = 0; $attempt < 3; $attempt++ ) {
			$receipt = ReceiptId::generate();
			$data    = array(
				'form_id'        => $form->id(),
				'schema_version' => $form->schema_version(),
				'receipt_id'     => $receipt,
				'payload'        => $payload,
				'status'         => $status,
				'created_at'     => $created_at,
			);
			$format  = array( '%d', '%d', '%s', '%s', '%s', '%s' );
			if ( $hashed ) {
				$data['content_hash'] = $content_hash;
				$format[]             = '%s';
			}

			$previous  = $wpdb->suppress_errors( true );
			$saved     = $wpdb->insert( Schema::submissions_table(), $data, $format );
			$duplicate = false !== stripos( (string) $wpdb->last_error, 'Duplicate' );
			$wpdb->suppress_errors( $previous );

			if ( false !== $saved ) {
				UnreadCounter::forget();
				return new Submission(
					(int) $wpdb->insert_id,
					$form->id(),
					$form->schema_version(),
					$receipt,
					$values,
					$status,
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
	 * @param SubmissionQuery $query    Inbox filters.
	 * @param int             $page     Page number, starting at 1.
	 * @param int             $per_page Page size.
	 */
	public function paginate( SubmissionQuery $query, int $page, int $per_page ): SubmissionList {
		global $wpdb;

		$page     = max( 1, $page );
		$per_page = max( 1, $per_page );
		$offset   = ( $page - 1 ) * $per_page;
		$table    = Schema::submissions_table();
		$where    = $this->where( $query );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table name is an identifier. The WHERE clause is prepared.
		$rows = $wpdb->get_results( $this->prepare( "SELECT id, form_id, schema_version, receipt_id, payload, status, created_at FROM {$table} WHERE {$where['sql']} ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d", array_merge( $where['args'], array( $per_page, $offset ) ) ), ARRAY_A );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table name is an identifier. The WHERE clause is prepared.
		$total = $wpdb->get_var( $this->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where['sql']}", $where['args'] ) );

		return new SubmissionList( $this->hydrate_rows( $rows ), (int) $total );
	}

	/**
	 * Submissions for one export batch, oldest first.
	 *
	 * The caller passes the last row it wrote. The first call passes null.
	 * `created_at` then `id` matches the form index, so rows that share a
	 * timestamp are not skipped or repeated.
	 *
	 * @param SubmissionQuery                         $query Inbox filters.
	 * @param array{created_at: string, id: int}|null $after Cursor from the previous batch.
	 * @param int                                     $limit Batch size.
	 * @return Submission[]
	 */
	public function iterate_for_export( SubmissionQuery $query, ?array $after, int $limit ): array {
		global $wpdb;

		$limit = max( 1, $limit );
		$table = Schema::submissions_table();
		$where = $this->where( $query );
		$sql   = "SELECT id, form_id, schema_version, receipt_id, payload, status, created_at FROM {$table} WHERE {$where['sql']}";
		$args  = $where['args'];
		if ( is_array( $after ) ) {
			$sql   .= ' AND ( created_at > %s OR ( created_at = %s AND id > %d ) )';
			$args[] = $after['created_at'];
			$args[] = $after['created_at'];
			$args[] = $after['id'];
		}
		$sql   .= ' ORDER BY created_at ASC, id ASC LIMIT %d';
		$args[] = $limit;

		$rows = $wpdb->get_results( $this->prepare( $sql, $args ), ARRAY_A );

		return $this->hydrate_rows( $rows );
	}

	/**
	 * Whether this form already stored the same body inside the window.
	 *
	 * @param int    $form_id      Form id.
	 * @param string $content_hash SHA-256 of the canonical body.
	 * @param int    $since        Unix time. Rows older than this do not count.
	 */
	public function has_recent_duplicate( int $form_id, string $content_hash, int $since ): bool {
		global $wpdb;

		if ( $form_id < 1 || 1 !== preg_match( '/\A[a-f0-9]{64}\z/', $content_hash ) || $since < 1 ) {
			return false;
		}

		$table = Schema::submissions_table();
		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE form_id = %d AND content_hash = %s AND created_at >= %s LIMIT 1",
				$form_id,
				$content_hash,
				gmdate( 'Y-m-d H:i:s', $since )
			)
		);

		return is_string( $found ) || is_int( $found );
	}

	/**
	 * Count submissions in one status, across every form.
	 *
	 * @param string $status Inbox status.
	 */
	public function count_with_status( string $status ): int {
		global $wpdb;

		if ( ! SubmissionStatus::is_known( $status ) ) {
			return 0;
		}

		$table = Schema::submissions_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is an identifier.
		$count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", $status ) );

		return (int) $count;
	}

	/**
	 * Count submissions per status for the current filters, ignoring the view.
	 *
	 * The form and the date range still apply, so view counts match the filters.
	 *
	 * @param SubmissionQuery $query Inbox filters. The view's status list is ignored.
	 * @return array<string, int>
	 */
	public function count_by_status( SubmissionQuery $query ): array {
		global $wpdb;

		$counts = array();
		foreach ( SubmissionStatus::all() as $status ) {
			$counts[ $status ] = 0;
		}

		$where = $this->where(
			SubmissionQuery::from_values(
				(int) $query->form_id(),
				SubmissionQuery::VIEW_ALL,
				$query->date_from() ?? '',
				$query->date_to() ?? '',
				$query->search()
			)
		);
		$table = Schema::submissions_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table name is an identifier. The WHERE clause is prepared.
		$rows = $wpdb->get_results( $this->prepare( "SELECT status, COUNT(*) AS total FROM {$table} WHERE {$where['sql']} GROUP BY status", $where['args'] ), ARRAY_A );

		if ( ! is_array( $rows ) ) {
			return $counts;
		}

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$status = (string) ( $row['status'] ?? '' );
			if ( isset( $counts[ $status ] ) ) {
				$counts[ $status ] = (int) ( $row['total'] ?? 0 );
			}
		}

		return $counts;
	}

	/**
	 * Set the status of these submissions.
	 *
	 * @param int[]  $ids    Submission ids.
	 * @param string $status Status to store.
	 * @return int Rows changed.
	 */
	public function set_status( array $ids, string $status ): int {
		global $wpdb;

		if ( ! SubmissionStatus::is_known( $status ) ) {
			return 0;
		}

		$ids = self::positive_ids( $ids );
		if ( array() === $ids ) {
			return 0;
		}

		$table        = Schema::submissions_table();
		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		$args         = array_merge( array( $status, $status ), $ids );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- table name is an identifier. The IN list is unpacked.
		$changed = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = %s WHERE status <> %s AND id IN ( {$placeholders} )", ...$args ) );
		if ( ! is_int( $changed ) || $changed < 1 ) {
			return 0;
		}

		UnreadCounter::forget();
		return $changed;
	}

	/**
	 * Mark one new submission as read.
	 *
	 * A submission that is already read, spam, or archived is left alone.
	 *
	 * @param int $id Submission id.
	 */
	public function mark_read_if_new( int $id ): bool {
		global $wpdb;

		if ( $id < 1 ) {
			return false;
		}

		$table = Schema::submissions_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is an identifier.
		$changed = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = %s WHERE id = %d AND status = %s",
				SubmissionStatus::READ,
				$id,
				SubmissionStatus::NEW
			)
		);
		if ( 1 !== $changed ) {
			return false;
		}

		UnreadCounter::forget();
		return true;
	}

	/**
	 * Delete these submissions.
	 *
	 * The caller cancels deliveries and idempotency keys in the same transaction.
	 *
	 * @param int[] $ids Submission ids.
	 * @return int|false Rows deleted, or false when the query fails.
	 */
	public function delete_ids( array $ids ): int|false {
		global $wpdb;

		$ids = self::positive_ids( $ids );
		if ( array() === $ids ) {
			return 0;
		}

		$table        = Schema::submissions_table();
		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		$deleted      = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id IN ( {$placeholders} )", ...$ids ) );

		return is_int( $deleted ) ? $deleted : false;
	}

	/**
	 * Ids stored before this time for one form, oldest first.
	 *
	 * A row stored at exactly `$before` is kept.
	 *
	 * @param int    $form_id Form id.
	 * @param string $before  UTC `Y-m-d H:i:s` cutoff.
	 * @param int    $limit   Maximum ids.
	 * @return int[]
	 */
	public function ids_created_before( int $form_id, string $before, int $limit ): array {
		global $wpdb;

		if ( $form_id < 1 || $limit < 1 ) {
			return array();
		}

		$table = Schema::submissions_table();
		$ids   = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE form_id = %d AND created_at < %s ORDER BY created_at ASC, id ASC LIMIT %d",
				$form_id,
				$before,
				$limit
			)
		);

		return $this->int_list( $ids );
	}

	/**
	 * Spam ids stored before this time, oldest first, from every form.
	 *
	 * @param string $before UTC `Y-m-d H:i:s` cutoff.
	 * @param int    $limit  Maximum ids.
	 * @return int[]
	 */
	public function spam_ids_created_before( string $before, int $limit ): array {
		global $wpdb;

		if ( $limit < 1 ) {
			return array();
		}

		$table = Schema::submissions_table();
		$ids   = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE status = %s AND created_at < %s ORDER BY created_at ASC, id ASC LIMIT %d",
				SubmissionStatus::SPAM,
				$before,
				$limit
			)
		);

		return $this->int_list( $ids );
	}

	/**
	 * Load one submission. The caller marks a new row as read.
	 *
	 * A locking read sees a row committed by another connection after this
	 * transaction's snapshot was taken. Replay uses that so it can return the
	 * original receipt.
	 *
	 * @param int  $id         Submission id.
	 * @param bool $for_update Whether to lock the row.
	 */
	public function find( int $id, bool $for_update = false ): ?Submission {
		global $wpdb;

		$table = Schema::submissions_table();
		if ( $for_update ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is an identifier.
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, form_id, schema_version, receipt_id, payload, status, created_at FROM {$table} WHERE id = %d FOR UPDATE", $id ), ARRAY_A );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is an identifier.
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, form_id, schema_version, receipt_id, payload, status, created_at FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		}

		return $this->hydrate( is_array( $row ) ? $row : null );
	}

	/**
	 * Prepared WHERE body and its values.
	 *
	 * The SQL contains placeholders only. `1=1` is used when nothing is filtered
	 * so the caller can always append `AND`.
	 *
	 * @param SubmissionQuery $query Inbox filters.
	 * @return array{sql: string, args: list<int|string>}
	 */
	private function where( SubmissionQuery $query ): array {
		$sql  = array( '1=1' );
		$args = array();

		if ( null !== $query->form_id() ) {
			$sql[]  = 'form_id = %d';
			$args[] = $query->form_id();
		}

		$statuses = $query->statuses();
		if ( array() !== $statuses ) {
			$placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
			$sql[]        = "status IN ( {$placeholders} )";
			foreach ( $statuses as $status ) {
				$args[] = $status;
			}
		}

		if ( null !== $query->date_from() ) {
			$sql[]  = 'created_at >= %s';
			$args[] = $query->date_from() . ' 00:00:00';
		}
		if ( null !== $query->date_to() ) {
			$sql[]  = 'created_at <= %s';
			$args[] = $query->date_to() . ' 23:59:59';
		}

		$hash = $query->identity_hash();
		if ( is_string( $hash ) ) {
			$identities  = Schema::identities_table();
			$submissions = Schema::submissions_table();
			$sql[]       = "EXISTS ( SELECT 1 FROM {$identities} WHERE value_hash = %s AND submission_id = {$submissions}.id )";
			$args[]      = $hash;
		}

		return array(
			'sql'  => implode( ' AND ', $sql ),
			'args' => $args,
		);
	}

	/**
	 * Prepare a statement, or return it when it has no placeholders.
	 *
	 * @param string                 $sql  SQL with placeholders.
	 * @param array<int, int|string> $args Placeholder values.
	 */
	private function prepare( string $sql, array $args ): string {
		global $wpdb;

		if ( array() === $args ) {
			return $sql;
		}

		$prepared = $wpdb->prepare( $sql, ...$args );
		return is_string( $prepared ) ? $prepared : $sql;
	}

	/**
	 * Integer ids from a wpdb column.
	 *
	 * @param mixed $ids Values from get_col().
	 * @return int[]
	 */
	private function int_list( mixed $ids ): array {
		if ( ! is_array( $ids ) ) {
			return array();
		}

		$clean = array();
		foreach ( $ids as $id ) {
			$number = (int) $id;
			if ( $number > 0 ) {
				$clean[] = $number;
			}
		}

		return $clean;
	}

	/**
	 * Unique positive ids.
	 *
	 * @param int[] $ids Candidate ids.
	 * @return list<int>
	 */
	private static function positive_ids( array $ids ): array {
		$clean = array();
		foreach ( $ids as $id ) {
			if ( is_int( $id ) && $id > 0 ) {
				$clean[ $id ] = $id;
			}
		}

		return array_values( $clean );
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
