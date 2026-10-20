<?php
/**
 * Form persistence.
 *
 * @package Formstead
 */

namespace Formstead\Forms;

use Formstead\Database\Schema;
use WP_Error;

/**
 * Loads and stores forms in the forms table.
 */
final class FormRepository {

	/**
	 * Load a form by its database id.
	 *
	 * @param int $id Database id.
	 */
	public function find_by_id( int $id ): ?Form {
		return $this->find_one( 'id', $id );
	}

	/**
	 * Load a form by its public id.
	 *
	 * @param string $public_id Public id.
	 */
	public function find_by_public_id( string $public_id ): ?Form {
		if ( ! PublicId::is_valid( $public_id ) ) {
			return null;
		}

		return $this->find_one( 'public_id', $public_id );
	}

	/**
	 * Every form, most recently updated first.
	 *
	 * @return Form[]
	 */
	public function all(): array {
		global $wpdb;

		$table = Schema::forms_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table, identifier.
		$rows = $wpdb->get_results( "SELECT id, public_id, name, is_enabled, fields, schema_version, success_url, created_at, updated_at FROM {$table} ORDER BY updated_at DESC, id DESC", ARRAY_A );

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$forms = array();
		foreach ( $rows as $row ) {
			$form = $this->hydrate( is_array( $row ) ? $row : null );
			if ( $form instanceof Form ) {
				$forms[] = $form;
			}
		}

		return $forms;
	}

	/**
	 * Insert a new form and assign its public id.
	 *
	 * The public id is generated here and is not taken from `$form`.
	 *
	 * @param Form $form Form to store. Its id and public id are ignored.
	 */
	public function insert( Form $form ): Form|WP_Error {
		global $wpdb;

		$now    = $this->now();
		$fields = $this->encode_fields( $form->fields() );
		$table  = Schema::forms_table();

		for ( $attempt = 0; $attempt < 3; $attempt++ ) {
			$public_id = PublicId::generate();
			$previous  = $wpdb->suppress_errors( true );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
			$saved     = $wpdb->insert(
				$table,
				array(
					'public_id'      => $public_id,
					'name'           => $form->name(),
					'is_enabled'     => $form->is_enabled() ? 1 : 0,
					'fields'         => $fields,
					'schema_version' => 1,
					'success_url'    => $form->success_url(),
					'created_at'     => $now,
					'updated_at'     => $now,
				),
				array( '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%s' )
			);
			$duplicate = false !== stripos( (string) $wpdb->last_error, 'Duplicate' );
			$wpdb->suppress_errors( $previous );

			if ( false !== $saved ) {
				return new Form(
					(int) $wpdb->insert_id,
					$public_id,
					$form->name(),
					$form->is_enabled(),
					$form->fields(),
					1,
					$form->success_url(),
					$now,
					$now
				);
			}

			if ( ! $duplicate ) {
				return new WP_Error(
					'formstead_form_save_failed',
					__( 'The form could not be saved.', 'formstead' )
				);
			}
		}

		return new WP_Error(
			'formstead_form_save_failed',
			__( 'The form could not be saved.', 'formstead' )
		);
	}

	/**
	 * Update a form. The public id is never changed.
	 *
	 * The schema version increments only when the fields JSON changes.
	 *
	 * @param Form $form Form to store. `public_id` on this object is ignored.
	 */
	public function update( Form $form ): Form|WP_Error {
		global $wpdb;

		$current = $this->find_by_id( $form->id() );
		if ( ! $current instanceof Form ) {
			return new WP_Error(
				'formstead_form_missing',
				__( 'This form could not be found.', 'formstead' )
			);
		}

		$version = $current->schema_version();
		if ( $this->encode_fields( $current->fields() ) !== $this->encode_fields( $form->fields() ) ) {
			++$version;
		}

		$now      = $this->now();
		$previous = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
		$saved = $wpdb->update(
			Schema::forms_table(),
			array(
				'name'           => $form->name(),
				'is_enabled'     => $form->is_enabled() ? 1 : 0,
				'fields'         => $this->encode_fields( $form->fields() ),
				'schema_version' => $version,
				'success_url'    => $form->success_url(),
				'updated_at'     => $now,
			),
			array( 'id' => $form->id() ),
			array( '%s', '%d', '%s', '%d', '%s', '%s' ),
			array( '%d' )
		);
		$wpdb->suppress_errors( $previous );

		if ( false === $saved ) {
			return new WP_Error(
				'formstead_form_save_failed',
				__( 'The form could not be saved.', 'formstead' )
			);
		}

		return new Form(
			$current->id(),
			$current->public_id(),
			$form->name(),
			$form->is_enabled(),
			$form->fields(),
			$version,
			$form->success_url(),
			$current->created_at(),
			$now
		);
	}

	/**
	 * Load one form by a single column.
	 *
	 * @param string     $column `id` or `public_id`.
	 * @param int|string $value  Column value.
	 */
	private function find_one( string $column, int|string $value ): ?Form {
		global $wpdb;

		$table = Schema::forms_table();
		$row   = null;
		if ( 'id' === $column ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is an identifier.
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, public_id, name, is_enabled, fields, schema_version, success_url, created_at, updated_at FROM {$table} WHERE id = %d", $value ), ARRAY_A );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is an identifier.
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, public_id, name, is_enabled, fields, schema_version, success_url, created_at, updated_at FROM {$table} WHERE public_id = %s", $value ), ARRAY_A );
		}

		return $this->hydrate( is_array( $row ) ? $row : null );
	}

	/**
	 * Build a form from a database row.
	 *
	 * @param array<string, mixed>|null $row Row from wpdb.
	 */
	private function hydrate( ?array $row ): ?Form {
		if ( ! is_array( $row ) ) {
			return null;
		}

		$fields = $this->decode_fields( (string) ( $row['fields'] ?? '' ) );

		return new Form(
			(int) ( $row['id'] ?? 0 ),
			(string) ( $row['public_id'] ?? '' ),
			(string) ( $row['name'] ?? '' ),
			(bool) (int) ( $row['is_enabled'] ?? 0 ),
			$fields,
			(int) ( $row['schema_version'] ?? 1 ),
			(string) ( $row['success_url'] ?? '' ),
			(string) ( $row['created_at'] ?? '' ),
			(string) ( $row['updated_at'] ?? '' )
		);
	}

	/**
	 * JSON for a field list.
	 *
	 * @param Field[] $fields Fields to store.
	 */
	private function encode_fields( array $fields ): string {
		$rows = array();
		foreach ( $fields as $field ) {
			$rows[] = $field->to_array();
		}

		$json = wp_json_encode( $rows, JSON_UNESCAPED_UNICODE );

		return is_string( $json ) ? $json : '[]';
	}

	/**
	 * Fields from stored JSON. Invalid rows are skipped.
	 *
	 * JSON numbers become ints. `required` is normalized to a boolean before
	 * `Field::from_array()` sees the row.
	 *
	 * @param string $json Stored fields JSON.
	 * @return Field[]
	 */
	private function decode_fields( string $json ): array {
		$decoded = json_decode( $json, true );
		if ( ! is_array( $decoded ) ) {
			return array();
		}

		$fields = array();
		foreach ( $decoded as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			if ( isset( $row['max_length'] ) && is_numeric( $row['max_length'] ) ) {
				$row['max_length'] = (int) $row['max_length'];
			}
			$row['required'] = (bool) ( $row['required'] ?? false );

			$field = Field::from_array( $row );
			if ( $field instanceof Field ) {
				$fields[] = $field;
			}
		}

		return $fields;
	}

	/**
	 * Current UTC time in MySQL format.
	 */
	private function now(): string {
		return current_time( 'mysql', true );
	}
}
