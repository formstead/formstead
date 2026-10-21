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
		$rows = $wpdb->get_results( "SELECT id, public_id, name, is_enabled, fields, schema_version, success_url, created_at, updated_at, allowed_origins, rate_per_minute, rate_per_hour, honeypot_rejections, notify_recipients, reply_to_field, mail_include_content, retention_days FROM {$table} ORDER BY updated_at DESC, id DESC", ARRAY_A );

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
		$fields = FieldCodec::encode( $form->fields() );
		$table  = Schema::forms_table();

		for ( $attempt = 0; $attempt < 3; $attempt++ ) {
			$public_id = PublicId::generate();
			$previous  = $wpdb->suppress_errors( true );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
			$saved     = $wpdb->insert(
				$table,
				array(
					'public_id'            => $public_id,
					'name'                 => $form->name(),
					'is_enabled'           => $form->is_enabled() ? 1 : 0,
					'fields'               => $fields,
					'schema_version'       => 1,
					'success_url'          => $form->success_url(),
					'created_at'           => $now,
					'updated_at'           => $now,
					'allowed_origins'      => $form->protection()->origins_json(),
					'rate_per_minute'      => $form->protection()->rate_per_minute(),
					'rate_per_hour'        => $form->protection()->rate_per_hour(),
					'honeypot_rejections'  => 0,
					'notify_recipients'    => $form->notifications()->recipients_json(),
					'reply_to_field'       => $form->notifications()->reply_to_field(),
					'mail_include_content' => $form->notifications()->includes_content() ? 1 : 0,
					'retention_days'       => $form->retention_days(),
				),
				array( '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%d', '%d' )
			);
			$duplicate = false !== stripos( (string) $wpdb->last_error, 'Duplicate' );
			$wpdb->suppress_errors( $previous );

			if ( false !== $saved ) {
				$form_id = (int) $wpdb->insert_id;
				$version = ( new FormVersionRepository() )->insert( $form_id, 1, $form->fields() );
				if ( $version instanceof WP_Error ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- remove the form whose snapshot failed.
					$wpdb->delete( $table, array( 'id' => $form_id ), array( '%d' ) );
					return $version;
				}

				return new Form(
					$form_id,
					$public_id,
					$form->name(),
					$form->is_enabled(),
					$form->fields(),
					1,
					$form->success_url(),
					$now,
					$now,
					$form->protection(),
					0,
					$form->notifications(),
					$form->retention_days()
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
	 * `$form->schema_version()` is the version the editor opened. It must
	 * still be the stored version. The schema version increments only when
	 * the fields JSON changes.
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

		if ( $form->schema_version() !== $current->schema_version() ) {
			return new WP_Error(
				'formstead_form_conflict',
				__( 'This form was saved somewhere else. Reload the screen and try again.', 'formstead' )
			);
		}

		$now             = $this->now();
		$fields_changed  = FieldCodec::encode( $current->fields() ) !== FieldCodec::encode( $form->fields() );
		$version         = $current->schema_version();
		$versions        = new FormVersionRepository();
		$created_version = false;

		if ( $fields_changed ) {
			$version         = $current->schema_version() + 1;
			$created_version = $this->prepare_snapshot( $versions, $form, $version );
			if ( $created_version instanceof WP_Error ) {
				return $created_version;
			}
		}

		$previous = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
		$saved = $wpdb->update(
			Schema::forms_table(),
			array(
				'name'                 => $form->name(),
				'is_enabled'           => $form->is_enabled() ? 1 : 0,
				'fields'               => FieldCodec::encode( $form->fields() ),
				'schema_version'       => $version,
				'success_url'          => $form->success_url(),
				'updated_at'           => $now,
				'allowed_origins'      => $form->protection()->origins_json(),
				'rate_per_minute'      => $form->protection()->rate_per_minute(),
				'rate_per_hour'        => $form->protection()->rate_per_hour(),
				'notify_recipients'    => $form->notifications()->recipients_json(),
				'reply_to_field'       => $form->notifications()->reply_to_field(),
				'mail_include_content' => $form->notifications()->includes_content() ? 1 : 0,
				'retention_days'       => $form->retention_days(),
			),
			array(
				'id'             => $form->id(),
				'schema_version' => $form->schema_version(),
			),
			array( '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%d', '%d' ),
			array( '%d', '%d' )
		);
		$wpdb->suppress_errors( $previous );

		if ( false === $saved || 0 === $saved ) {
			if ( true === $created_version ) {
				$versions->delete( $form->id(), $version );
			}

			if ( false === $saved ) {
				return new WP_Error(
					'formstead_form_save_failed',
					__( 'The form could not be saved.', 'formstead' )
				);
			}

			$unchanged = $this->find_by_id( $form->id() );
			if ( ! $fields_changed && $unchanged instanceof Form && $unchanged->schema_version() === $form->schema_version() ) {
				return $unchanged;
			}

			return new WP_Error(
				'formstead_form_conflict',
				__( 'This form was saved somewhere else. Reload the screen and try again.', 'formstead' )
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
			$now,
			$form->protection(),
			$current->honeypot_rejections(),
			$form->notifications(),
			$form->retention_days()
		);
	}

	/**
	 * Count one honeypot rejection. The submission itself is not stored.
	 *
	 * A failed update still looks like success to the sender. The counter is
	 * not required for that response, and the failure is not logged.
	 *
	 * @param int $form_id Form id.
	 */
	public function record_honeypot( int $form_id ): void {
		global $wpdb;

		if ( $form_id < 1 ) {
			return;
		}

		$table = Schema::forms_table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is an identifier.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET honeypot_rejections = honeypot_rejections + 1 WHERE id = %d",
				$form_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Insert the next snapshot, or reuse one left by an interrupted save of the same fields.
	 *
	 * @param FormVersionRepository $versions Snapshot store.
	 * @param Form                  $form     Form being saved.
	 * @param int                   $version  Version number the snapshot should use.
	 * @return bool|WP_Error True when this call inserted the snapshot. False when an identical snapshot already existed. `WP_Error` when the version is taken by different fields.
	 */
	private function prepare_snapshot( FormVersionRepository $versions, Form $form, int $version ): bool|WP_Error {
		$inserted = $versions->insert( $form->id(), $version, $form->fields() );
		if ( ! $inserted instanceof WP_Error ) {
			return true;
		}

		if ( 'formstead_form_version_duplicate' !== $inserted->get_error_code() ) {
			return $inserted;
		}

		$existing = $versions->find( $form->id(), $version );
		if ( $existing instanceof FormVersion && FieldCodec::encode( $existing->fields() ) === FieldCodec::encode( $form->fields() ) ) {
			return false;
		}

		return new WP_Error(
			'formstead_form_conflict',
			__( 'This form was saved somewhere else. Reload the screen and try again.', 'formstead' )
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
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, public_id, name, is_enabled, fields, schema_version, success_url, created_at, updated_at, allowed_origins, rate_per_minute, rate_per_hour, honeypot_rejections, notify_recipients, reply_to_field, mail_include_content, retention_days FROM {$table} WHERE id = %d", $value ), ARRAY_A );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is an identifier.
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, public_id, name, is_enabled, fields, schema_version, success_url, created_at, updated_at, allowed_origins, rate_per_minute, rate_per_hour, honeypot_rejections, notify_recipients, reply_to_field, mail_include_content, retention_days FROM {$table} WHERE public_id = %s", $value ), ARRAY_A );
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

		$fields        = FieldCodec::decode( (string) ( $row['fields'] ?? '' ) );
		$origins       = $row['allowed_origins'] ?? null;
		$protection    = FormProtection::from_stored(
			is_string( $origins ) ? $origins : null,
			$row['rate_per_minute'] ?? null,
			$row['rate_per_hour'] ?? null
		);
		$recipients    = $row['notify_recipients'] ?? null;
		$notifications = FormNotifications::from_stored(
			is_string( $recipients ) ? $recipients : null,
			$row['reply_to_field'] ?? null,
			$row['mail_include_content'] ?? 0
		);

		return new Form(
			(int) ( $row['id'] ?? 0 ),
			(string) ( $row['public_id'] ?? '' ),
			(string) ( $row['name'] ?? '' ),
			(bool) (int) ( $row['is_enabled'] ?? 0 ),
			$fields,
			(int) ( $row['schema_version'] ?? 1 ),
			(string) ( $row['success_url'] ?? '' ),
			(string) ( $row['created_at'] ?? '' ),
			(string) ( $row['updated_at'] ?? '' ),
			$protection,
			(int) ( $row['honeypot_rejections'] ?? 0 ),
			$notifications,
			(int) ( $row['retention_days'] ?? 30 )
		);
	}

	/**
	 * Current UTC time in MySQL format.
	 */
	private function now(): string {
		return current_time( 'mysql', true );
	}
}
