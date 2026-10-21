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
use Formstead\Submissions\SubmissionQuery;
use Formstead\Submissions\SubmissionRepository;
use Formstead\Submissions\SubmissionStatus;

/**
 * Lists submissions and shows one submission.
 *
 * The capability is checked here, not only on the menu item.
 * Opening a new submission marks it read.
 */
final class SubmissionsPage {

	private const PER_PAGE = 20;

	/**
	 * Status stored for each detail action.
	 *
	 * @var array<string, string>
	 */
	private const STATUS_ACTIONS = array(
		'mark_read'   => SubmissionStatus::READ,
		'mark_unread' => SubmissionStatus::NEW,
		'archive'     => SubmissionStatus::ARCHIVED,
		'restore'     => SubmissionStatus::READ,
		'mark_spam'   => SubmissionStatus::SPAM,
		'not_spam'    => SubmissionStatus::READ,
	);

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
	 * Apply a posted status action, then redirect.
	 *
	 * Runs on `load-toplevel_page_formstead`, before the screen renders.
	 */
	public static function handle_load(): void {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- handle_single() verifies the nonce.
		if ( 'POST' !== $method || ! isset( $_POST['formstead_single'] ) ) {
			return;
		}

		if ( ! current_user_can( Capabilities::READ_SUBMISSIONS ) ) {
			wp_die(
				esc_html__( 'Sorry, you are not allowed to read submissions.', 'formstead' ),
				'',
				array( 'response' => 403 )
			);
		}

		self::handle_single();
	}

	/**
	 * Render the inbox list.
	 */
	private static function render_list(): void {
		$form_id     = self::query_int( 'form_id' );
		$paged       = max( 1, self::query_int( 'paged' ) );
		$forms       = ( new FormRepository() )->all();
		$page        = ( new SubmissionRepository() )->paginate( SubmissionQuery::from_values( $form_id, SubmissionQuery::VIEW_ALL, '', '' ), $paged, self::PER_PAGE );
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
		$repository = new SubmissionRepository();
		$repository->mark_read_if_new( $submission_id );
		$submission = $repository->find( $submission_id );
		$form       = $submission instanceof Submission ? ( new FormRepository() )->find_by_id( $submission->form_id() ) : null;
		$rows       = $submission instanceof Submission ? self::detail_rows( $submission, $form ) : array();
		$deliveries = $submission instanceof Submission ? self::delivery_rows( $submission->id() ) : array();
		$actions    = $submission instanceof Submission ? self::detail_actions( $submission ) : array();
		$notice     = self::notice();

		include dirname( FORMSTEAD_FILE ) . '/templates/admin/submission-detail.php';
	}

	/**
	 * Status actions for one submission.
	 *
	 * @param Submission $submission Stored submission.
	 * @return list<array{action: string, label: string}>
	 */
	private static function detail_actions( Submission $submission ): array {
		$actions = array();
		if ( SubmissionStatus::NEW !== $submission->status() ) {
			$actions[] = array(
				'action' => 'mark_unread',
				'label'  => __( 'Mark as unread', 'formstead' ),
			);
		}
		if ( SubmissionStatus::READ !== $submission->status() && SubmissionStatus::NEW !== $submission->status() ) {
			$actions[] = array(
				'action' => 'restore',
				'label'  => __( 'Move to inbox', 'formstead' ),
			);
		}
		if ( SubmissionStatus::ARCHIVED !== $submission->status() ) {
			$actions[] = array(
				'action' => 'archive',
				'label'  => __( 'Archive', 'formstead' ),
			);
		}
		if ( SubmissionStatus::SPAM !== $submission->status() ) {
			$actions[] = array(
				'action' => 'mark_spam',
				'label'  => __( 'Mark as spam', 'formstead' ),
			);
		} else {
			$actions[] = array(
				'action' => 'not_spam',
				'label'  => __( 'Not spam', 'formstead' ),
			);
		}

		return $actions;
	}

	/**
	 * Change the submission opened on this screen.
	 */
	private static function handle_single(): void {
		$id = self::posted_int( 'submission_id' );
		check_admin_referer( 'formstead_submission_' . $id );

		$action = self::posted_text( 'formstead_action' );
		if ( ! isset( self::STATUS_ACTIONS[ $action ] ) ) {
			return;
		}

		$changed = ( new SubmissionRepository() )->set_status( array( $id ), self::STATUS_ACTIONS[ $action ] );
		self::redirect(
			array(
				'page'              => Menu::MENU_SLUG,
				'submission'        => $id,
				'formstead_updated' => $changed,
			)
		);
	}

	/**
	 * Redirect back to the submission and stop the load hook.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 */
	private static function redirect( array $args ): void {
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Notice produced by the previous action, or an empty string.
	 */
	private static function notice(): string {
		$updated = self::query_int( 'formstead_updated' );
		if ( $updated > 0 ) {
			return sprintf(
				/* translators: %s: number of submissions. */
				_n( '%s submission updated.', '%s submissions updated.', $updated, 'formstead' ),
				number_format_i18n( $updated )
			);
		}

		return '';
	}

	/**
	 * Integer from the action POST.
	 *
	 * @param string $key Field name.
	 */
	private static function posted_int( string $key ): int {
		return absint( self::posted_text( $key ) );
	}

	/**
	 * Text from the action POST.
	 *
	 * @param string $key Field name.
	 */
	private static function posted_text( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- the caller checks the action nonce before this is used.
		if ( ! isset( $_POST[ $key ] ) ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_text_field cleans the action field.
		return sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
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
