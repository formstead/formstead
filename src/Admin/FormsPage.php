<?php
/**
 * Forms admin screen.
 *
 * @package Formstead
 */

namespace Formstead\Admin;

use Formstead\Forms\Field;
use Formstead\Forms\FieldType;
use Formstead\Forms\Form;
use Formstead\Forms\FormInput;
use Formstead\Forms\FormRepository;
use Formstead\Security\Capabilities;
use WP_Error;

/**
 * Lists forms and saves the add/edit screen.
 */
final class FormsPage {

	/**
	 * Admin hook suffix for the forms screen.
	 *
	 * @var string
	 */
	private static string $hook = '';

	/**
	 * Whether the current request failed validation.
	 *
	 * @var bool
	 */
	private static bool $invalid = false;

	/**
	 * Field errors from the current request.
	 *
	 * @var array<string, string>
	 */
	private static array $errors = array();

	/**
	 * Posted values to show again after a validation error.
	 *
	 * @var array<string, mixed>
	 */
	private static array $posted = array();

	/**
	 * Remember the forms screen hook so scripts load only there.
	 *
	 * @param string $hook Hook suffix returned by `add_submenu_page()`.
	 */
	public static function set_hook( string $hook ): void {
		self::$hook = $hook;
	}

	/**
	 * Load the field-row script on the forms screen.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public static function enqueue( string $hook ): void {
		if ( '' === self::$hook || $hook !== self::$hook ) {
			return;
		}

		wp_enqueue_script(
			'formstead-form-fields',
			plugins_url( 'assets/admin/form-fields.js', FORMSTEAD_FILE ),
			array(),
			FORMSTEAD_VERSION,
			true
		);
	}

	/**
	 * Forget validation state from the current request.
	 */
	public static function reset_request(): void {
		self::$invalid = false;
		self::$errors  = array();
		self::$posted  = array();
	}

	/**
	 * Save a form on the load hook, before the screen renders.
	 */
	public static function handle_load(): void {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		if ( 'POST' !== $method ) {
			return;
		}

		self::reset_request();
		check_admin_referer( 'formstead_save_form' );

		if ( ! current_user_can( Capabilities::MANAGE_FORMS ) ) {
			wp_die(
				esc_html__( 'Sorry, you are not allowed to manage forms.', 'formstead' ),
				'',
				array( 'response' => 403 )
			);
		}

		$input = self::input_from_post();
		$form  = null;
		if ( $input['form_id'] > 0 ) {
			$form = ( new FormRepository() )->find_by_id( $input['form_id'] );
			if ( ! $form instanceof Form ) {
				wp_die(
					esc_html__( 'This form could not be found.', 'formstead' ),
					'',
					array( 'response' => 404 )
				);
			}
		}

		$result = FormInput::validate( $input, $form );
		$valid  = $result->form();
		if ( ! $result->is_valid() || ! $valid instanceof Form ) {
			self::$invalid = true;
			self::$errors  = $result->errors();
			self::$posted  = $input;
			return;
		}

		$repository = new FormRepository();
		$saved      = $form instanceof Form ? $repository->update( $valid ) : $repository->insert( $valid );
		if ( $saved instanceof WP_Error ) {
			self::$invalid = true;
			self::$errors  = array( 'form' => $saved->get_error_message() );
			self::$posted  = $input;
			return;
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => Menu::FORMS_SLUG,
					'form'    => $saved->id(),
					'updated' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Render the list or the edit screen.
	 */
	public static function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE_FORMS ) ) {
			wp_die(
				esc_html__( 'Sorry, you are not allowed to manage forms.', 'formstead' ),
				'',
				array( 'response' => 403 )
			);
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- reading the screen query, not a submission.
		$form_id = isset( $_GET['form'] ) ? absint( wp_unslash( $_GET['form'] ) ) : 0;
		$action  = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( self::$invalid || 'new' === $action || $form_id > 0 ) {
			self::render_edit( $form_id );
			return;
		}

		self::render_list();
	}

	/**
	 * Render the forms table.
	 */
	private static function render_list(): void {
		$forms = ( new FormRepository() )->all();
		include dirname( FORMSTEAD_FILE ) . '/templates/admin/forms-list.php';
	}

	/**
	 * Render the add or edit form.
	 *
	 * @param int $form_id Form id from the query string. Zero for a new form.
	 */
	private static function render_edit( int $form_id ): void {
		$repository = new FormRepository();
		$form       = null;

		if ( self::$invalid ) {
			$posted_id = isset( self::$posted['form_id'] ) ? (int) self::$posted['form_id'] : 0;
			if ( $posted_id > 0 ) {
				$form = $repository->find_by_id( $posted_id );
			}
			$values = self::$posted;
		} else {
			if ( $form_id > 0 ) {
				$form = $repository->find_by_id( $form_id );
				if ( ! $form instanceof Form ) {
					wp_die(
						esc_html__( 'This form could not be found.', 'formstead' ),
						'',
						array( 'response' => 404 )
					);
				}
			}
			$values = self::values_from_form( $form );
		}

		$values['fields'] = self::with_blank_row( $values['fields'] ?? array() );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading the screen query, not a submission.
		$updated_flag = isset( $_GET['updated'] ) ? sanitize_text_field( wp_unslash( $_GET['updated'] ) ) : '';
		$errors       = self::$errors;
		$updated      = '1' === $updated_flag;

		include dirname( FORMSTEAD_FILE ) . '/templates/admin/form-edit.php';
	}

	/**
	 * Posted form values for validation and redisplay.
	 *
	 * The nonce was verified in `handle_load()` before this runs.
	 * The name loses HTML tags. The success URL is left unchanged so a
	 * percent-encoded address survives; FormInput rejects a URL that
	 * `esc_url_raw()` would change.
	 *
	 * @return array{form_id: int, name: string, is_enabled: string, success_url: string, fields: array<int, array<string, string>>}
	 */
	private static function input_from_post(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in handle_load() before this runs.
		$form_id = isset( $_POST['form_id'] ) ? absint( wp_unslash( $_POST['form_id'] ) ) : 0;
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- posted_name() strips tags and keeps percent-encoded text.
		$name = self::posted_name( isset( $_POST['name'] ) ? wp_unslash( $_POST['name'] ) : '' );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- FormInput rejects URLs that esc_url_raw() would change.
		$url     = self::text_value( isset( $_POST['success_url'] ) ? wp_unslash( $_POST['success_url'] ) : '' );
		$enabled = isset( $_POST['is_enabled'] ) ? '1' : '';

		return array(
			'form_id'     => $form_id,
			'name'        => $name,
			'is_enabled'  => $enabled,
			'success_url' => $url,
			'fields'      => self::posted_fields(),
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Field rows from the request.
	 *
	 * Names and types are trimmed only, so a value outside the field-name
	 * pattern is rejected instead of rewritten. Anything that is not text
	 * becomes an empty string.
	 *
	 * @return array<int, array<string, string>>
	 */
	private static function posted_fields(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in handle_load() before this runs.
		if ( ! isset( $_POST['fields'] ) || ! is_array( $_POST['fields'] ) ) {
			return array();
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each row is normalized in posted_row().
		$posted = wp_unslash( $_POST['fields'] );
		if ( ! is_array( $posted ) ) {
			return array();
		}

		$fields = array();
		foreach ( $posted as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$fields[] = self::posted_row( $row );
		}

		return $fields;
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Form name with tags removed. Percent-encoded text is kept.
	 *
	 * @param mixed $value Posted name.
	 */
	private static function posted_name( mixed $value ): string {
		return trim( wp_strip_all_tags( self::text_value( $value ) ) );
	}

	/**
	 * One posted field row.
	 *
	 * @param array<mixed> $row Posted row.
	 * @return array<string, string>
	 */
	private static function posted_row( array $row ): array {
		return array(
			'name'       => self::text_value( $row['name'] ?? null ),
			'label'      => self::label_value( $row['label'] ?? null ),
			'type'       => self::text_value( $row['type'] ?? null ),
			'required'   => isset( $row['required'] ) ? '1' : '',
			'max_length' => self::text_value( $row['max_length'] ?? null ),
			'remove'     => isset( $row['remove'] ) ? '1' : '',
		);
	}

	/**
	 * Posted text, or an empty string when the value is not text.
	 *
	 * @param mixed $value Posted value.
	 */
	private static function text_value( mixed $value ): string {
		return is_string( $value ) ? trim( $value ) : '';
	}

	/**
	 * Field label, with tags and control characters removed.
	 *
	 * @param mixed $value Posted label.
	 */
	private static function label_value( mixed $value ): string {
		return is_string( $value ) ? sanitize_text_field( $value ) : '';
	}

	/**
	 * Values for the edit form.
	 *
	 * @param Form|null $form Saved form, or null for a new form.
	 * @return array{form_id: int, name: string, is_enabled: string, success_url: string, fields: array<int, array<string, string>>}
	 */
	private static function values_from_form( ?Form $form ): array {
		if ( ! $form instanceof Form ) {
			return array(
				'form_id'     => 0,
				'name'        => '',
				'is_enabled'  => '1',
				'success_url' => '',
				'fields'      => array(),
			);
		}

		$rows = array();
		foreach ( $form->fields() as $field ) {
			$rows[] = self::row_from_field( $field );
		}

		return array(
			'form_id'     => $form->id(),
			'name'        => $form->name(),
			'is_enabled'  => $form->is_enabled() ? '1' : '',
			'success_url' => $form->success_url(),
			'fields'      => $rows,
		);
	}

	/**
	 * One edit-form row for a saved field.
	 *
	 * @param Field $field Saved field.
	 * @return array<string, string>
	 */
	private static function row_from_field( Field $field ): array {
		return array(
			'name'       => $field->name(),
			'label'      => $field->label(),
			'type'       => $field->type()->value,
			'required'   => $field->required() ? '1' : '',
			'max_length' => (string) $field->max_length(),
			'remove'     => '',
		);
	}

	/**
	 * Append one empty row when the last row is already filled in.
	 *
	 * Without JavaScript, that empty row is how a person adds the next field.
	 *
	 * @param mixed $rows Posted or stored rows.
	 * @return array<int, array<string, string>>
	 */
	private static function with_blank_row( mixed $rows ): array {
		$normalized = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$normalized[] = array(
					'name'       => isset( $row['name'] ) ? (string) $row['name'] : '',
					'label'      => isset( $row['label'] ) ? (string) $row['label'] : '',
					'type'       => isset( $row['type'] ) ? (string) $row['type'] : '',
					'required'   => isset( $row['required'] ) && '1' === (string) $row['required'] ? '1' : '',
					'max_length' => isset( $row['max_length'] ) ? (string) $row['max_length'] : '',
					'remove'     => isset( $row['remove'] ) && '1' === (string) $row['remove'] ? '1' : '',
				);
			}
		}

		$blank = array(
			'name'       => '',
			'label'      => '',
			'type'       => '',
			'required'   => '',
			'max_length' => '',
			'remove'     => '',
		);
		$last  = $normalized[ count( $normalized ) - 1 ] ?? null;
		if ( ! is_array( $last ) || '' !== $last['name'] || '' !== $last['label'] || '' !== $last['type'] || '' !== $last['max_length'] ) {
			$normalized[] = $blank;
		}

		return $normalized;
	}

	/**
	 * Human label for a field type.
	 *
	 * @param string $type Stored type value.
	 */
	public static function type_label( string $type ): string {
		return match ( $type ) {
			FieldType::Email->value => __( 'Email', 'formstead' ),
			FieldType::Textarea->value => __( 'Textarea', 'formstead' ),
			default => __( 'Text', 'formstead' ),
		};
	}
}
