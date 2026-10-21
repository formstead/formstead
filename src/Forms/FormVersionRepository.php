<?php
/**
 * Form schema snapshot persistence.
 *
 * @package Formstead
 */

namespace Formstead\Forms;

use Formstead\Database\Schema;
use WP_Error;

/**
 * Loads and stores immutable field schemas.
 */
final class FormVersionRepository {

	/**
	 * Load one snapshot.
	 *
	 * @param int $form_id Form id.
	 * @param int $version Schema version.
	 */
	public function find( int $form_id, int $version ): ?FormVersion {
		global $wpdb;

		$table = Schema::form_versions_table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is an identifier.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, form_id, version, fields, created_at FROM {$table} WHERE form_id = %d AND version = %d",
				$form_id,
				$version
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $this->hydrate( is_array( $row ) ? $row : null );
	}

	/**
	 * Store a snapshot. The caller chooses the version number.
	 *
	 * A duplicate `(form_id, version)` returns `formstead_form_version_duplicate`.
	 *
	 * @param int     $form_id Form id.
	 * @param int     $version Schema version.
	 * @param Field[] $fields  Fields to freeze.
	 */
	public function insert( int $form_id, int $version, array $fields ): FormVersion|WP_Error {
		global $wpdb;

		$now      = current_time( 'mysql', true );
		$previous = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
		$saved     = $wpdb->insert(
			Schema::form_versions_table(),
			array(
				'form_id'    => $form_id,
				'version'    => $version,
				'fields'     => FieldCodec::encode( $fields ),
				'created_at' => $now,
			),
			array( '%d', '%d', '%s', '%s' )
		);
		$duplicate = false !== stripos( (string) $wpdb->last_error, 'Duplicate' );
		$wpdb->suppress_errors( $previous );

		if ( false === $saved ) {
			if ( $duplicate ) {
				return new WP_Error(
					'formstead_form_version_duplicate',
					__( 'This form was saved somewhere else. Reload the screen and try again.', 'formstead' )
				);
			}

			return new WP_Error(
				'formstead_form_save_failed',
				__( 'The form could not be saved.', 'formstead' )
			);
		}

		return new FormVersion( (int) $wpdb->insert_id, $form_id, $version, $fields, $now );
	}

	/**
	 * Snapshots for one form, newest version first.
	 *
	 * @param int $form_id Form id.
	 * @return FormVersion[]
	 */
	public function for_form( int $form_id ): array {
		global $wpdb;

		if ( $form_id < 1 ) {
			return array();
		}

		$table = Schema::form_versions_table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is an identifier.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, form_id, version, fields, created_at FROM {$table} WHERE form_id = %d ORDER BY version DESC",
				$form_id
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$versions = array();
		foreach ( $rows as $row ) {
			$version = $this->hydrate( is_array( $row ) ? $row : null );
			if ( $version instanceof FormVersion ) {
				$versions[] = $version;
			}
		}

		return $versions;
	}

	/**
	 * Remove a snapshot that was inserted but never published.
	 *
	 * @param int $form_id Form id.
	 * @param int $version Schema version.
	 */
	public function delete( int $form_id, int $version ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
		$wpdb->delete(
			Schema::form_versions_table(),
			array(
				'form_id' => $form_id,
				'version' => $version,
			),
			array( '%d', '%d' )
		);
	}

	/**
	 * Build a snapshot from a database row.
	 *
	 * @param array<string, mixed>|null $row Row from wpdb.
	 */
	private function hydrate( ?array $row ): ?FormVersion {
		if ( ! is_array( $row ) ) {
			return null;
		}

		return new FormVersion(
			(int) ( $row['id'] ?? 0 ),
			(int) ( $row['form_id'] ?? 0 ),
			(int) ( $row['version'] ?? 0 ),
			FieldCodec::decode( (string) ( $row['fields'] ?? '' ) ),
			(string) ( $row['created_at'] ?? '' )
		);
	}
}
