<?php
/**
 * Inbox list table.
 *
 * @package Formstead
 */

namespace Formstead\Admin;

use Formstead\Forms\Form;
use Formstead\Submissions\Submission;
use Formstead\Submissions\SubmissionQuery;
use Formstead\Submissions\SubmissionRepository;
use Formstead\Submissions\SubmissionStatus;

if ( ! class_exists( '\WP_List_Table', false ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}
if ( ! function_exists( 'set_current_screen' ) ) {
	require_once ABSPATH . 'wp-admin/includes/screen.php';
}

/**
 * Submissions for the current view, with bulk actions.
 */
final class SubmissionsTable extends \WP_List_Table {

	private const PER_PAGE = 20;

	/**
	 * Filters for this screen.
	 *
	 * @var SubmissionQuery
	 */
	private SubmissionQuery $query;

	/**
	 * Forms keyed by id.
	 *
	 * @var array<int, Form>
	 */
	private array $forms;

	/**
	 * Counts per status for the form and date filters.
	 *
	 * @var array<string, int>
	 */
	private array $counts;

	/**
	 * Page number from the inbox query string.
	 *
	 * @var int
	 */
	private int $paged;

	/**
	 * Create the table for one inbox query.
	 *
	 * @param SubmissionQuery $query Inbox filters.
	 * @param Form[]          $forms Known forms.
	 * @param int             $paged Page number, starting at 1.
	 */
	public function __construct( SubmissionQuery $query, array $forms, int $paged = 1 ) {
		$this->query  = $query;
		$this->paged  = max( 1, $paged );
		$this->forms  = array();
		$this->counts = array();
		foreach ( $forms as $form ) {
			if ( $form instanceof Form ) {
				$this->forms[ $form->id() ] = $form;
			}
		}

		if ( ! get_current_screen() ) {
			set_current_screen( 'toplevel_page_' . Menu::MENU_SLUG );
		}

		parent::__construct(
			array(
				'singular' => 'submission',
				'plural'   => 'submissions',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Load one page and the view counts.
	 */
	public function prepare_items(): void {
		$repository   = new SubmissionRepository();
		$this->counts = $repository->count_by_status( $this->query );
		$page         = $repository->paginate( $this->query, $this->paged, self::PER_PAGE );

		$items = array();
		foreach ( $page->submissions() as $submission ) {
			$items[] = $this->item( $submission );
		}
		$this->items           = $items;
		$this->_column_headers = array( $this->get_columns(), array(), array(), 'created_at' );

		$this->set_pagination_args(
			array(
				'total_items' => $page->total(),
				'per_page'    => self::PER_PAGE,
			)
		);
	}

	/**
	 * Columns.
	 *
	 * @return array<string, string>
	 */
	public function get_columns(): array {
		return array(
			'cb'         => '<input type="checkbox" />',
			'created_at' => __( 'Date', 'formstead' ),
			'form'       => __( 'Form', 'formstead' ),
			'status'     => __( 'Status', 'formstead' ),
			'excerpt'    => __( 'Submission', 'formstead' ),
		);
	}

	/**
	 * View links. Counts follow the form and date filters.
	 *
	 * @return array<string, string>
	 */
	protected function get_views(): array {
		$links = array(
			SubmissionQuery::VIEW_INBOX    => array( __( 'Inbox', 'formstead' ), ( $this->counts[ SubmissionStatus::NEW ] ?? 0 ) + ( $this->counts[ SubmissionStatus::READ ] ?? 0 ) ),
			SubmissionQuery::VIEW_UNREAD   => array( __( 'Unread', 'formstead' ), $this->counts[ SubmissionStatus::NEW ] ?? 0 ),
			SubmissionQuery::VIEW_ARCHIVED => array( __( 'Archived', 'formstead' ), $this->counts[ SubmissionStatus::ARCHIVED ] ?? 0 ),
			SubmissionQuery::VIEW_SPAM     => array( __( 'Spam', 'formstead' ), $this->counts[ SubmissionStatus::SPAM ] ?? 0 ),
			SubmissionQuery::VIEW_ALL      => array( __( 'All', 'formstead' ), array_sum( $this->counts ) ),
		);

		$views = array();
		foreach ( $links as $view => $link ) {
			$class          = $this->query->view() === $view ? ' class="current"' : '';
			$views[ $view ] = '<a href="' . esc_url( $this->view_url( $view ) ) . '"' . $class . '>' . esc_html( $link[0] ) . ' <span class="count">(' . esc_html( number_format_i18n( $link[1] ) ) . ')</span></a>';
		}

		return $views;
	}

	/**
	 * Bulk status actions.
	 *
	 * @return array<string, string>
	 */
	protected function get_bulk_actions(): array {
		return array(
			'mark_read'   => __( 'Mark as read', 'formstead' ),
			'mark_unread' => __( 'Mark as unread', 'formstead' ),
			'archive'     => __( 'Archive', 'formstead' ),
			'restore'     => __( 'Move to inbox', 'formstead' ),
			'mark_spam'   => __( 'Mark as spam', 'formstead' ),
			'not_spam'    => __( 'Not spam', 'formstead' ),
		);
	}

	/**
	 * Checkbox column.
	 *
	 * @param array<string, string>|mixed $item Row.
	 */
	public function column_cb( $item ): string {
		if ( ! is_array( $item ) ) {
			return '';
		}

		$id = (int) ( $item['id'] ?? 0 );
		return sprintf(
			'<label class="screen-reader-text" for="submission-%1$d">%2$s</label><input type="checkbox" id="submission-%1$d" name="submission[]" value="%1$d" />',
			$id,
			esc_html__( 'Select submission', 'formstead' )
		);
	}

	/**
	 * Date column, linking to the submission.
	 *
	 * @param array<string, string>|mixed $item Row.
	 */
	public function column_created_at( $item ): string {
		if ( ! is_array( $item ) ) {
			return '';
		}

		return '<a href="' . esc_url( (string) ( $item['url'] ?? '' ) ) . '">' . esc_html( (string) ( $item['created_at'] ?? '' ) ) . '</a>';
	}

	/**
	 * Text column.
	 *
	 * @param array<string, string>|mixed $item        Row.
	 * @param string                      $column_name Column.
	 */
	public function column_default( $item, $column_name ): string {
		if ( ! is_array( $item ) ) {
			return '';
		}

		return esc_html( (string) ( $item[ $column_name ] ?? '' ) );
	}

	/**
	 * Empty inbox.
	 */
	public function no_items(): void {
		esc_html_e( 'No submissions yet.', 'formstead' );
	}

	/**
	 * One list row.
	 *
	 * @param Submission $submission Stored submission.
	 * @return array{id: string, url: string, created_at: string, form: string, status: string, excerpt: string}
	 */
	private function item( Submission $submission ): array {
		$form = $this->forms[ $submission->form_id() ] ?? null;

		return array(
			'id'         => (string) $submission->id(),
			'url'        => admin_url( 'admin.php?page=' . Menu::MENU_SLUG . '&submission=' . $submission->id() ),
			'created_at' => $submission->created_at(),
			'form'       => $form instanceof Form ? $form->name() : sprintf(
				/* translators: %d: form id. */
				__( 'Form %d', 'formstead' ),
				$submission->form_id()
			),
			'status'     => SubmissionStatus::label( $submission->status() ),
			'excerpt'    => self::excerpt( $submission ),
		);
	}

	/**
	 * View URL that keeps the form and date filters.
	 *
	 * @param string $view View slug.
	 */
	private function view_url( string $view ): string {
		$args = array(
			'page'       => Menu::MENU_SLUG,
			'inbox_view' => $view,
		);
		if ( null !== $this->query->form_id() ) {
			$args['form_id'] = $this->query->form_id();
		}
		if ( null !== $this->query->date_from() ) {
			$args['date_from'] = $this->query->date_from();
		}
		if ( null !== $this->query->date_to() ) {
			$args['date_to'] = $this->query->date_to();
		}

		return add_query_arg( $args, admin_url( 'admin.php' ) );
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

		$value = reset( $payload );
		if ( is_bool( $value ) ) {
			$text = $value ? __( 'Yes', 'formstead' ) : __( 'No', 'formstead' );
		} elseif ( is_array( $value ) ) {
			$text = implode( ', ', $value );
		} else {
			$text = $value;
		}

		$flat = preg_replace( '/\s+/', ' ', $text );
		$flat = trim( is_string( $flat ) ? $flat : $text );
		if ( mb_strlen( $flat ) <= 80 ) {
			return $flat;
		}

		return mb_substr( $flat, 0, 80 ) . '…';
	}
}
