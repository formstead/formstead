<?php
/**
 * Forms admin screen.
 *
 * @package Formstead
 */

namespace Formstead\Admin;

use Formstead\Audit\AuditEvent;
use Formstead\Audit\AuditLog;
use Formstead\Forms\Field;
use Formstead\Forms\FieldCodec;
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
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- rotation checks its own nonce before writing.
		if ( isset( $_POST['formstead_rotate_webhook'] ) ) {
			self::rotate_webhook();
		}

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

		self::audit_form( $form, $saved );

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
	 * Record that a form was created or which settings changed.
	 *
	 * The context names the settings. It does not include their values.
	 *
	 * @param Form|null $previous Form before this save, or null when it is new.
	 * @param Form      $saved    Form that was stored.
	 */
	private static function audit_form( ?Form $previous, Form $saved ): void {
		if ( ! $previous instanceof Form ) {
			AuditLog::record(
				AuditEvent::FORM_CREATED,
				'form',
				$saved->id(),
				array( 'fields' => array() !== $saved->fields() )
			);
			return;
		}

		AuditLog::record(
			AuditEvent::FORM_UPDATED,
			'form',
			$saved->id(),
			array(
				'settings' => self::changed_settings( $previous, $saved ),
				'fields'   => FieldCodec::encode( $previous->fields() ) !== FieldCodec::encode( $saved->fields() ),
			)
		);
	}

	/**
	 * Setting codes whose stored value changed.
	 *
	 * @param Form $previous Form before this save.
	 * @param Form $saved    Form that was stored.
	 * @return list<string>
	 */
	private static function changed_settings( Form $previous, Form $saved ): array {
		$settings = array();
		if ( $previous->name() !== $saved->name() ) {
			$settings[] = 'name';
		}
		if ( $previous->is_enabled() !== $saved->is_enabled() ) {
			$settings[] = 'enabled';
		}
		if ( $previous->success_url() !== $saved->success_url() ) {
			$settings[] = 'success_url';
		}
		if ( $previous->protection()->origins_json() !== $saved->protection()->origins_json() ) {
			$settings[] = 'origins';
		}
		if ( $previous->protection()->rate_per_minute() !== $saved->protection()->rate_per_minute() ) {
			$settings[] = 'rate_per_minute';
		}
		if ( $previous->protection()->rate_per_hour() !== $saved->protection()->rate_per_hour() ) {
			$settings[] = 'rate_per_hour';
		}
		if ( $previous->notifications()->recipients_json() !== $saved->notifications()->recipients_json() ) {
			$settings[] = 'recipients';
		}
		if ( $previous->notifications()->reply_to_field() !== $saved->notifications()->reply_to_field() ) {
			$settings[] = 'reply_to';
		}
		if ( $previous->notifications()->includes_content() !== $saved->notifications()->includes_content() ) {
			$settings[] = 'include_content';
		}
		if ( $previous->retention_days() !== $saved->retention_days() ) {
			$settings[] = 'retention_days';
		}
		if ( $previous->identity_fields() !== $saved->identity_fields() ) {
			$settings[] = 'identity';
		}
		if ( $previous->webhook()->url() !== $saved->webhook()->url() ) {
			$settings[] = 'webhook_url';
		}
		if ( $previous->webhook()->includes_content() !== $saved->webhook()->includes_content() ) {
			$settings[] = 'webhook_include_content';
		}

		return $settings;
	}

	/**
	 * Replace the signing secret and leave the rest of the form unchanged.
	 *
	 * The nonce and capability are checked here, before any write. The audit
	 * row names the form and does not include the secret or the URL.
	 */
	private static function rotate_webhook(): never {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified below before the secret changes.
		$form_id = isset( $_POST['form_id'] ) ? absint( wp_unslash( $_POST['form_id'] ) ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		check_admin_referer( 'formstead_rotate_webhook_' . $form_id );

		if ( ! current_user_can( Capabilities::MANAGE_FORMS ) ) {
			wp_die(
				esc_html__( 'Sorry, you are not allowed to manage forms.', 'formstead' ),
				'',
				array( 'response' => 403 )
			);
		}

		$saved = ( new FormRepository() )->rotate_webhook_secret( $form_id );
		if ( $saved instanceof WP_Error ) {
			wp_die(
				esc_html( $saved->get_error_message() ),
				'',
				array( 'response' => 404 )
			);
		}

		AuditLog::record(
			AuditEvent::WEBHOOK_SECRET_ROTATED,
			'form',
			$saved->id(),
			array()
		);

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
	 * @return array{form_id: int, schema_version: int, name: string, is_enabled: string, success_url: string, retention_days: string, allowed_origins: string, rate_per_minute: string, rate_per_hour: string, notify_recipients: string, reply_to_field: string, mail_include_content: string, webhook_url: string, webhook_include_content: string, fields: array<int, array<string, string>>}
	 */
	private static function input_from_post(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in handle_load() before this runs.
		$form_id = isset( $_POST['form_id'] ) ? absint( wp_unslash( $_POST['form_id'] ) ) : 0;
		$version = isset( $_POST['schema_version'] ) ? absint( wp_unslash( $_POST['schema_version'] ) ) : 0;
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- posted_name() strips tags and keeps percent-encoded text.
		$name = self::posted_name( isset( $_POST['name'] ) ? wp_unslash( $_POST['name'] ) : '' );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- FormInput rejects URLs that esc_url_raw() would change.
		$url     = self::text_value( isset( $_POST['success_url'] ) ? wp_unslash( $_POST['success_url'] ) : '' );
		$enabled = isset( $_POST['is_enabled'] ) ? '1' : '';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- FormInput accepts only a whole number from 0 to 3650.
		$retention = self::text_value( isset( $_POST['retention_days'] ) ? wp_unslash( $_POST['retention_days'] ) : '' );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- FormInput rejects an origin that is not scheme, host, and port.
		$origins = self::text_value( isset( $_POST['allowed_origins'] ) ? wp_unslash( $_POST['allowed_origins'] ) : '' );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- FormInput accepts only a whole number inside the ceiling.
		$minute = self::text_value( isset( $_POST['rate_per_minute'] ) ? wp_unslash( $_POST['rate_per_minute'] ) : '' );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- FormInput accepts only a whole number inside the ceiling.
		$hour = self::text_value( isset( $_POST['rate_per_hour'] ) ? wp_unslash( $_POST['rate_per_hour'] ) : '' );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- FormInput accepts only valid email addresses.
		$recipients = self::options_value( isset( $_POST['notify_recipients'] ) ? wp_unslash( $_POST['notify_recipients'] ) : '' );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- FormInput accepts only the name of an email field.
		$reply_to = self::text_value( isset( $_POST['reply_to_field'] ) ? wp_unslash( $_POST['reply_to_field'] ) : '' );
		$include  = isset( $_POST['mail_include_content'] ) ? '1' : '';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- FormInput rejects a URL that is not public https.
		$webhook_url     = self::text_value( isset( $_POST['webhook_url'] ) ? wp_unslash( $_POST['webhook_url'] ) : '' );
		$webhook_content = isset( $_POST['webhook_include_content'] ) ? '1' : '';

		return array(
			'form_id'                 => $form_id,
			'schema_version'          => $version,
			'name'                    => $name,
			'is_enabled'              => $enabled,
			'success_url'             => $url,
			'retention_days'          => $retention,
			'allowed_origins'         => $origins,
			'rate_per_minute'         => $minute,
			'rate_per_hour'           => $hour,
			'notify_recipients'       => $recipients,
			'reply_to_field'          => $reply_to,
			'mail_include_content'    => $include,
			'webhook_url'             => $webhook_url,
			'webhook_include_content' => $webhook_content,
			'fields'                  => self::posted_fields(),
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
		$posted = array(
			'name'       => self::text_value( $row['name'] ?? null ),
			'label'      => self::label_value( $row['label'] ?? null ),
			'type'       => self::text_value( $row['type'] ?? null ),
			'required'   => isset( $row['required'] ) ? '1' : '',
			'max_length' => self::text_value( $row['max_length'] ?? null ),
			'min'        => self::text_value( $row['min'] ?? null ),
			'max'        => self::text_value( $row['max'] ?? null ),
			'scale'      => self::text_value( $row['scale'] ?? null ),
			'options'    => self::options_value( $row['options'] ?? null ),
			'multiple'   => isset( $row['multiple'] ) ? '1' : '',
			'remove'     => isset( $row['remove'] ) ? '1' : '',
		);
		if ( array_key_exists( 'identifies', $row ) ) {
			$posted['identifies'] = '1' === (string) $row['identifies'] ? '1' : '0';
		}

		return $posted;
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
	 * Option lines. Tags are removed and the line breaks are kept.
	 *
	 * @param mixed $value Posted options.
	 */
	private static function options_value( mixed $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}

		$lines = preg_split( '/\R/', wp_strip_all_tags( $value ) );
		if ( ! is_array( $lines ) ) {
			return '';
		}

		return implode( "\n", array_map( 'trim', $lines ) );
	}

	/**
	 * Values for the edit form.
	 *
	 * @param Form|null $form Saved form, or null for a new form.
	 * @return array{form_id: int, schema_version: int, name: string, is_enabled: string, success_url: string, retention_days: string, allowed_origins: string, rate_per_minute: string, rate_per_hour: string, notify_recipients: string, reply_to_field: string, mail_include_content: string, webhook_url: string, webhook_include_content: string, fields: array<int, array<string, string>>}
	 */
	private static function values_from_form( ?Form $form ): array {
		if ( ! $form instanceof Form ) {
			return array(
				'form_id'                 => 0,
				'schema_version'          => 1,
				'name'                    => '',
				'is_enabled'              => '1',
				'success_url'             => '',
				'retention_days'          => '30',
				'allowed_origins'         => '',
				'rate_per_minute'         => '',
				'rate_per_hour'           => '',
				'notify_recipients'       => self::default_recipient(),
				'reply_to_field'          => '',
				'mail_include_content'    => '',
				'webhook_url'             => '',
				'webhook_include_content' => '',
				'fields'                  => array(),
			);
		}

		$rows     = array();
		$identity = $form->identity_fields();
		foreach ( $form->fields() as $field ) {
			$rows[] = self::row_from_field( $field, $identity );
		}

		$minute = $form->protection()->rate_per_minute();
		$hour   = $form->protection()->rate_per_hour();

		$reply_to = $form->notifications()->reply_to_field();

		return array(
			'form_id'                 => $form->id(),
			'schema_version'          => $form->schema_version(),
			'name'                    => $form->name(),
			'is_enabled'              => $form->is_enabled() ? '1' : '',
			'success_url'             => $form->success_url(),
			'retention_days'          => (string) $form->retention_days(),
			'allowed_origins'         => implode( "\n", $form->protection()->allowed_origins() ),
			'rate_per_minute'         => null === $minute ? '' : (string) $minute,
			'rate_per_hour'           => null === $hour ? '' : (string) $hour,
			'notify_recipients'       => implode( "\n", $form->notifications()->recipients() ),
			'reply_to_field'          => is_string( $reply_to ) ? $reply_to : '',
			'mail_include_content'    => $form->notifications()->includes_content() ? '1' : '',
			'webhook_url'             => $form->webhook()->url(),
			'webhook_include_content' => $form->webhook()->includes_content() ? '1' : '',
			'fields'                  => $rows,
		);
	}

	/**
	 * Address shown in the recipient box of a new form.
	 *
	 * An existing form keeps its stored list, which is empty until someone sets it.
	 */
	private static function default_recipient(): string {
		$email = get_option( 'admin_email' );
		if ( ! is_string( $email ) || ! is_email( $email ) ) {
			return '';
		}

		return $email;
	}

	/**
	 * One edit-form row for a saved field.
	 *
	 * @param Field    $field    Saved field.
	 * @param string[] $identity Identifying field names.
	 * @return array<string, string>
	 */
	private static function row_from_field( Field $field, array $identity ): array {
		$lines = array();
		foreach ( $field->settings()->options() as $option ) {
			$lines[] = $option['value'] . '|' . $option['label'];
		}

		return array(
			'name'       => $field->name(),
			'label'      => $field->label(),
			'type'       => $field->type()->value,
			'required'   => $field->required() ? '1' : '',
			'max_length' => (string) $field->max_length(),
			'min'        => (string) $field->settings()->min(),
			'max'        => (string) $field->settings()->max(),
			'scale'      => FieldType::Number === $field->type() ? (string) $field->settings()->scale() : '',
			'options'    => implode( "\n", $lines ),
			'multiple'   => $field->settings()->multiple() ? '1' : '',
			'remove'     => '',
			'identifies' => in_array( $field->name(), $identity, true ) ? '1' : '0',
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
					'min'        => isset( $row['min'] ) ? (string) $row['min'] : '',
					'max'        => isset( $row['max'] ) ? (string) $row['max'] : '',
					'scale'      => isset( $row['scale'] ) ? (string) $row['scale'] : '',
					'options'    => isset( $row['options'] ) ? (string) $row['options'] : '',
					'multiple'   => isset( $row['multiple'] ) && '1' === (string) $row['multiple'] ? '1' : '',
					'remove'     => isset( $row['remove'] ) && '1' === (string) $row['remove'] ? '1' : '',
					'identifies' => isset( $row['identifies'] ) && '1' === (string) $row['identifies'] ? '1' : '0',
				);
			}
		}

		$blank = array(
			'name'       => '',
			'label'      => '',
			'type'       => '',
			'required'   => '',
			'max_length' => '',
			'min'        => '',
			'max'        => '',
			'scale'      => '',
			'options'    => '',
			'multiple'   => '',
			'remove'     => '',
			'identifies' => '1',
		);
		$last  = $normalized[ count( $normalized ) - 1 ] ?? null;
		if ( ! is_array( $last ) || '' !== $last['name'] || '' !== $last['label'] || '' !== $last['type'] || '' !== $last['max_length'] || '' !== $last['min'] || '' !== $last['max'] || '' !== $last['scale'] || '' !== $last['options'] ) {
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
			FieldType::Number->value => __( 'Number', 'formstead' ),
			FieldType::Boolean->value => __( 'Checkbox', 'formstead' ),
			FieldType::Choice->value => __( 'Choice', 'formstead' ),
			default => __( 'Text', 'formstead' ),
		};
	}
}
