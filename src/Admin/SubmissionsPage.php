<?php
/**
 * Submissions inbox.
 *
 * @package Formstead
 */

namespace Formstead\Admin;

use Formstead\Delivery\DeliveryRepository;
use Formstead\Delivery\DeliveryStatus;
use Formstead\Forms\Field;
use Formstead\Forms\FieldType;
use Formstead\Forms\Form;
use Formstead\Forms\FormRepository;
use Formstead\Forms\FormVersion;
use Formstead\Forms\FormVersionRepository;
use Formstead\Security\Capabilities;
use Formstead\Submissions\Submission;
use Formstead\Submissions\SubmissionList;
use Formstead\Submissions\SubmissionRepository;

/**
 * Lists submissions and shows one submission.
 *
 * The capability is checked here, not only on the menu item.
 * Opening a submission does not change its status.
 */
final class SubmissionsPage {

	private const PER_PAGE = 20;

	/**
	 * Render the list or one submission.
	 */
	public static function render(): void {
		if ( ! current_user_can( Capabilities::READ_SUBMISSIONS ) ) {
			wp_die(
				esc_html__( 'Sorry, you are not allowed to read submissions.', 'formstead' ),
				'',
				array( 'response' => 403 )
			);
		}

		$submission_id = self::query_int( 'submission' );
		if ( $submission_id > 0 ) {
			self::render_detail( $submission_id );
			return;
		}

		self::render_list();
	}

	/**
	 * Render the inbox list.
	 */
	private static function render_list(): void {
		$form_id     = self::query_int( 'form_id' );
		$paged       = max( 1, self::query_int( 'paged' ) );
		$forms       = ( new FormRepository() )->all();
		$page        = ( new SubmissionRepository() )->paginate( $form_id > 0 ? $form_id : null, $paged, self::PER_PAGE );
		$rows        = self::list_rows( $page, $forms );
		$total_pages = (int) ceil( $page->total() / self::PER_PAGE );
		$pagination  = '';

		if ( $total_pages > 1 ) {
			$link_args = array(
				'base'      => add_query_arg( 'paged', '%#%' ),
				'format'    => '',
				'current'   => $paged,
				'total'     => $total_pages,
				'add_args'  => array(),
				'prev_text' => __( 'Previous', 'formstead' ),
				'next_text' => __( 'Next', 'formstead' ),
			);
			if ( $form_id > 0 ) {
				$link_args['add_args'] = array( 'form_id' => $form_id );
			}
			$links      = paginate_links( $link_args );
			$pagination = is_string( $links ) ? $links : '';
		}

		include dirname( FORMSTEAD_FILE ) . '/templates/admin/submissions-list.php';
	}

	/**
	 * Render one submission.
	 *
	 * @param int $submission_id Submission id from the query string.
	 */
	private static function render_detail( int $submission_id ): void {
		$submission = ( new SubmissionRepository() )->find( $submission_id );
		$form       = $submission instanceof Submission ? ( new FormRepository() )->find_by_id( $submission->form_id() ) : null;
		$rows       = $submission instanceof Submission ? self::detail_rows( $submission, $form ) : array();
		$deliveries = $submission instanceof Submission ? self::delivery_rows( $submission->id() ) : array();

		include dirname( FORMSTEAD_FILE ) . '/templates/admin/submission-detail.php';
	}

	/**
	 * List rows for the template.
	 *
	 * @param SubmissionList $page  Page of submissions.
	 * @param Form[]         $forms Known forms.
	 * @return array<int, array{url: string, created_at: string, form_name: string, excerpt: string}>
	 */
	private static function list_rows( SubmissionList $page, array $forms ): array {
		$forms_by_id = self::forms_by_id( $forms );
		$rows        = array();

		foreach ( $page->submissions() as $submission ) {
			$form   = $forms_by_id[ $submission->form_id() ] ?? null;
			$rows[] = array(
				'url'        => admin_url( 'admin.php?page=' . Menu::MENU_SLUG . '&submission=' . $submission->id() ),
				'created_at' => $submission->created_at(),
				'form_name'  => $form instanceof Form ? $form->name() : sprintf(
					/* translators: %d: form id. */
					__( 'Form %d', 'formstead' ),
					$submission->form_id()
				),
				'excerpt'    => self::excerpt( $submission ),
			);
		}

		return $rows;
	}

	/**
	 * Detail rows for the template.
	 *
	 * Labels and types come from the schema snapshot for the submission's
	 * version. When that snapshot does not exist, the current form is used.
	 * A field in neither place keeps its stored name.
	 *
	 * @param Submission $submission Stored submission.
	 * @param Form|null  $form       Current form, when it still exists.
	 * @return array<int, array{label: string, value: string, multiline: bool, items: list<string>}>
	 */
	private static function detail_rows( Submission $submission, ?Form $form ): array {
		$snapshot = ( new FormVersionRepository() )->find( $submission->form_id(), $submission->schema_version() );
		$fields   = $snapshot instanceof FormVersion ? $snapshot->fields() : array();
		if ( array() === $fields && $form instanceof Form ) {
			$fields = $form->fields();
		}

		$by_name = array();
		foreach ( $fields as $field ) {
			$by_name[ $field->name() ] = $field;
		}

		$rows = array();
		foreach ( $submission->payload() as $name => $value ) {
			$field   = $by_name[ $name ] ?? null;
			$items   = array();
			$display = self::display_value( $field, $value );
			if ( is_array( $display ) ) {
				$items   = $display;
				$display = '';
			}
			$rows[] = array(
				'label'     => $field instanceof Field ? $field->label() : $name,
				'value'     => $display,
				'multiline' => $field instanceof Field && FieldType::Textarea === $field->type(),
				'items'     => $items,
			);
		}

		return $rows;
	}

	/**
	 * Delivery rows for the submission screen.
	 *
	 * Status text describes the hand-off. It does not claim the mailbox received the message.
	 *
	 * @param int $submission_id Submission id.
	 * @return array<int, array{channel: string, status: string, attempts: string, last_error: string, next_attempt_at: string}>
	 */
	private static function delivery_rows( int $submission_id ): array {
		$rows = array();
		foreach ( ( new DeliveryRepository() )->for_submission( $submission_id ) as $delivery ) {
			$pending = DeliveryStatus::PENDING === $delivery->status();
			$rows[]  = array(
				'channel'         => 'email' === $delivery->channel() ? __( 'Email', 'formstead' ) : $delivery->channel(),
				'status'          => DeliveryStatus::label( $delivery->status() ),
				'attempts'        => (string) $delivery->attempts(),
				'last_error'      => $delivery->last_error(),
				'next_attempt_at' => $pending ? $delivery->next_attempt_at() : '',
			);
		}

		return $rows;
	}

	/**
	 * Text, a yes/no answer, or a list of choice labels.
	 *
	 * @param Field|null               $field Field from the snapshot, when it is known.
	 * @param string|bool|list<string> $value Stored value.
	 * @return string|list<string>
	 */
	private static function display_value( ?Field $field, string|bool|array $value ): string|array {
		if ( is_bool( $value ) ) {
			return $value ? __( 'Yes', 'formstead' ) : __( 'No', 'formstead' );
		}

		if ( is_array( $value ) ) {
			return self::choice_labels( $field, $value );
		}

		if ( $field instanceof Field && FieldType::Choice === $field->type() ) {
			$labels = self::choice_labels( $field, array( $value ) );
			return $labels[0] ?? $value;
		}

		return $value;
	}

	/**
	 * Choice labels in the order configured on the field.
	 *
	 * A stored value that is no longer an option keeps the raw value.
	 *
	 * @param Field|null $field    Choice field, when it is known.
	 * @param string[]   $selected Stored values.
	 * @return list<string>
	 */
	private static function choice_labels( ?Field $field, array $selected ): array {
		if ( ! $field instanceof Field ) {
			return array_values( $selected );
		}

		$labels  = array();
		$pending = array_fill_keys( $selected, true );
		foreach ( $field->settings()->options() as $option ) {
			if ( isset( $pending[ $option['value'] ] ) ) {
				$labels[] = $option['label'];
				unset( $pending[ $option['value'] ] );
			}
		}
		foreach ( $selected as $value ) {
			if ( isset( $pending[ $value ] ) ) {
				$labels[] = $value;
			}
		}

		return $labels;
	}

	/**
	 * Short text from the first stored field.
	 *
	 * @param Submission $submission Stored submission.
	 */
	private static function excerpt( Submission $submission ): string {
		$payload = $submission->payload();
		if ( array() === $payload ) {
			return '';
		}

		$value = self::plain_text( reset( $payload ) );
		$flat  = preg_replace( '/\s+/', ' ', $value );
		$flat  = trim( is_string( $flat ) ? $flat : $value );
		if ( mb_strlen( $flat ) <= 80 ) {
			return $flat;
		}

		return mb_substr( $flat, 0, 80 ) . '…';
	}

	/**
	 * One line of text for a stored value.
	 *
	 * @param mixed $value Stored value, or false from an empty reset.
	 */
	private static function plain_text( mixed $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? __( 'Yes', 'formstead' ) : __( 'No', 'formstead' );
		}
		if ( is_array( $value ) ) {
			return implode( ', ', $value );
		}

		return is_string( $value ) ? $value : '';
	}

	/**
	 * Forms keyed by id.
	 *
	 * @param Form[] $forms Forms to index.
	 * @return array<int, Form>
	 */
	private static function forms_by_id( array $forms ): array {
		$indexed = array();
		foreach ( $forms as $form ) {
			$indexed[ $form->id() ] = $form;
		}
		return $indexed;
	}

	/**
	 * Integer from the inbox query string.
	 *
	 * `page` is the menu slug, so pagination uses `paged`.
	 *
	 * @param string $key Query key.
	 */
	private static function query_int( string $key ): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading the screen query, not a submission.
		if ( ! isset( $_GET[ $key ] ) ) {
			return 0;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- absint sanitizes the screen query.
		return absint( wp_unslash( $_GET[ $key ] ) );
	}
}
