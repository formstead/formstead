<?php
/**
 * Submissions inbox.
 *
 * @package Formstead
 */

namespace Formstead\Admin;

use Formstead\Forms\FieldType;
use Formstead\Forms\Form;
use Formstead\Forms\FormRepository;
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
	 * A field that is still on the form uses its current label. A removed field
	 * keeps the stored name. Textarea values keep their line breaks.
	 *
	 * @param Submission $submission Stored submission.
	 * @param Form|null  $form       Current form, when it still exists.
	 * @return array<int, array{label: string, value: string, multiline: bool}>
	 */
	private static function detail_rows( Submission $submission, ?Form $form ): array {
		$rows = array();

		foreach ( $submission->payload() as $name => $value ) {
			$field  = $form instanceof Form ? $form->field( $name ) : null;
			$rows[] = array(
				'label'     => null !== $field ? $field->label() : $name,
				'value'     => self::as_text( $value ),
				'multiline' => null !== $field && FieldType::Textarea === $field->type(),
			);
		}

		return $rows;
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

		$first = reset( $payload );
		$value = self::as_text( is_string( $first ) || is_bool( $first ) || is_array( $first ) ? $first : '' );
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
	 * Checkbox and choice values are readable here. The snapshot view replaces
	 * this with labels.
	 *
	 * @param string|bool|list<string> $value Stored value.
	 */
	private static function as_text( string|bool|array $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? '1' : '0';
		}
		if ( is_array( $value ) ) {
			return implode( ', ', $value );
		}

		return $value;
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
