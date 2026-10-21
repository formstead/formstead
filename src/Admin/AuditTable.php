<?php
/**
 * Audit log list table.
 *
 * @package Formstead
 */

namespace Formstead\Admin;

defined( 'ABSPATH' ) || exit;

use Formstead\Audit\AuditEvent;
use Formstead\Database\Schema;

if ( ! class_exists( '\WP_List_Table', false ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}
if ( ! function_exists( 'set_current_screen' ) ) {
	require_once ABSPATH . 'wp-admin/includes/screen.php';
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- table name is an identifier. The WHERE list is prepared.

/**
 * One page of audit events, newest first.
 */
final class AuditTable extends \WP_List_Table {

	private const PER_PAGE = 20;

	/**
	 * Event code, or an empty string for every event.
	 *
	 * @var string
	 */
	private string $event;

	/**
	 * First UTC day, or null.
	 *
	 * @var string|null
	 */
	private ?string $date_from;

	/**
	 * Last UTC day, or null.
	 *
	 * @var string|null
	 */
	private ?string $date_to;

	/**
	 * Page number, starting at 1.
	 *
	 * @var int
	 */
	private int $paged;

	/**
	 * Create the table for one filter.
	 *
	 * @param string      $event     Known event code, or an empty string.
	 * @param string|null $date_from First UTC day.
	 * @param string|null $date_to   Last UTC day.
	 * @param int         $paged     Page number, starting at 1.
	 */
	public function __construct( string $event, ?string $date_from, ?string $date_to, int $paged = 1 ) {
		$this->event     = $event;
		$this->date_from = $date_from;
		$this->date_to   = $date_to;
		$this->paged     = max( 1, $paged );

		if ( ! get_current_screen() ) {
			set_current_screen( Menu::MENU_SLUG . '_page_' . Menu::AUDIT_SLUG );
		}

		parent::__construct(
			array(
				'singular' => 'audit_event',
				'plural'   => 'audit_events',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Event code currently shown, or an empty string for every event.
	 */
	public function event(): string {
		return $this->event;
	}

	/**
	 * First UTC day, or an empty string.
	 */
	public function date_from(): string {
		return $this->date_from ?? '';
	}

	/**
	 * Last UTC day, or an empty string.
	 */
	public function date_to(): string {
		return $this->date_to ?? '';
	}

	/**
	 * Load one page.
	 */
	public function prepare_items(): void {
		global $wpdb;

		$where                 = $this->where();
		$table                 = Schema::audit_table();
		$count_sql             = "SELECT COUNT(*) FROM {$table} WHERE {$where['sql']}";
		$this->items           = array();
		$total                 = (int) $wpdb->get_var( $this->prepare( $count_sql, $where['args'] ) );
		$offset                = ( $this->paged - 1 ) * self::PER_PAGE;
		$list_args             = $where['args'];
		$list_args[]           = self::PER_PAGE;
		$list_args[]           = $offset;
		$sql                   = "SELECT event, actor_id, object_type, object_id, context, created_at FROM {$table} WHERE {$where['sql']} ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d";
		$rows                  = $wpdb->get_results( $this->prepare( $sql, $list_args ), ARRAY_A );
		$this->items           = $this->rows( is_array( $rows ) ? $rows : array() );
		$this->_column_headers = array( $this->get_columns(), array(), array(), 'created_at' );

		$this->set_pagination_args(
			array(
				'total_items' => $total,
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
			'created_at' => __( 'Date', 'formstead' ),
			'event'      => __( 'Event', 'formstead' ),
			'actor'      => __( 'User', 'formstead' ),
			'object'     => __( 'Object', 'formstead' ),
			'context'    => __( 'Details', 'formstead' ),
		);
	}

	/**
	 * Text when the filter matches nothing.
	 */
	public function no_items(): void {
		esc_html_e( 'No audit events found.', 'formstead' );
	}

	/**
	 * Date column.
	 *
	 * @param array<string, string>|mixed $item Row.
	 */
	public function column_created_at( $item ): string {
		if ( ! is_array( $item ) ) {
			return '';
		}

		$created = strtotime( (string) ( $item['created_at'] ?? '' ) . ' UTC' );
		if ( ! is_int( $created ) ) {
			return '';
		}

		$formatted = wp_date( 'Y-m-d H:i', $created );
		return is_string( $formatted ) ? esc_html( $formatted ) : '';
	}

	/**
	 * Event column.
	 *
	 * @param array<string, string>|mixed $item Row.
	 */
	public function column_event( $item ): string {
		if ( ! is_array( $item ) ) {
			return '';
		}

		$event = (string) ( $item['event'] ?? '' );
		$label = AuditEvent::label( $event );
		return esc_html( '' === $label ? $event : $label );
	}

	/**
	 * Person who did it. A missing account keeps its id. User 0 is automatic.
	 *
	 * @param array<string, string>|mixed $item Row.
	 */
	public function column_actor( $item ): string {
		if ( ! is_array( $item ) ) {
			return '';
		}

		$actor_id = (int) ( $item['actor_id'] ?? 0 );
		if ( $actor_id < 1 ) {
			return esc_html__( 'Automatic', 'formstead' );
		}

		$user = get_userdata( $actor_id );
		if ( ! $user instanceof \WP_User ) {
			return esc_html(
				sprintf(
					/* translators: %d: user id. */
					__( 'User #%d', 'formstead' ),
					$actor_id
				)
			);
		}

		return esc_html( $user->display_name );
	}

	/**
	 * Object column.
	 *
	 * @param array<string, string>|mixed $item Row.
	 */
	public function column_object( $item ): string {
		if ( ! is_array( $item ) ) {
			return '';
		}

		$object_id = (int) ( $item['object_id'] ?? 0 );
		$label     = $this->object_label( (string) ( $item['object_type'] ?? '' ) );
		if ( $object_id < 1 ) {
			return esc_html( $label );
		}

		return esc_html(
			sprintf(
				/* translators: 1: object type, 2: object id. */
				__( '%1$s #%2$d', 'formstead' ),
				$label,
				$object_id
			)
		);
	}

	/**
	 * Context column. Values are counts and short codes.
	 *
	 * @param array<string, string>|mixed $item Row.
	 */
	public function column_context( $item ): string {
		if ( ! is_array( $item ) ) {
			return '';
		}

		return esc_html( $this->context_text( (string) ( $item['context'] ?? '' ) ) );
	}

	/**
	 * WHERE clause and its placeholders.
	 *
	 * @return array{sql: string, args: list<int|string>}
	 */
	private function where(): array {
		$sql  = array( '1=1' );
		$args = array();

		if ( '' !== $this->event ) {
			$sql[]  = 'event = %s';
			$args[] = $this->event;
		}
		if ( is_string( $this->date_from ) ) {
			$sql[]  = 'created_at >= %s';
			$args[] = $this->date_from . ' 00:00:00';
		}
		if ( is_string( $this->date_to ) ) {
			$sql[]  = 'created_at <= %s';
			$args[] = $this->date_to . ' 23:59:59';
		}

		return array(
			'sql'  => implode( ' AND ', $sql ),
			'args' => $args,
		);
	}

	/**
	 * Prepare a statement, or return it when it has no placeholders.
	 *
	 * @param string           $sql  Statement with placeholders.
	 * @param list<int|string> $args Placeholder values.
	 */
	private function prepare( string $sql, array $args ): string {
		global $wpdb;

		if ( array() === $args ) {
			return $sql;
		}

		$prepared = $wpdb->prepare( $sql, $args );
		return is_string( $prepared ) ? $prepared : $sql;
	}

	/**
	 * Rows the table can render.
	 *
	 * @param array<int, mixed> $rows Database rows.
	 * @return list<array<string, string>>
	 */
	private function rows( array $rows ): array {
		$items = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$items[] = array(
				'created_at'  => (string) ( $row['created_at'] ?? '' ),
				'event'       => (string) ( $row['event'] ?? '' ),
				'actor_id'    => (string) ( $row['actor_id'] ?? '0' ),
				'object_type' => (string) ( $row['object_type'] ?? '' ),
				'object_id'   => (string) ( $row['object_id'] ?? '0' ),
				'context'     => (string) ( $row['context'] ?? '' ),
			);
		}

		return $items;
	}

	/**
	 * Translated object type, or the stored code when it is not a known one.
	 *
	 * @param string $type Stored object type.
	 */
	private function object_label( string $type ): string {
		switch ( $type ) {
			case 'form':
				return __( 'Form', 'formstead' );
			case 'settings':
				return __( 'Settings', 'formstead' );
			case 'submission':
				return __( 'Submission', 'formstead' );
			case 'retention':
				return __( 'Retention', 'formstead' );
			default:
				return 1 === preg_match( '/\A[a-z_]{1,20}\z/', $type ) ? $type : '';
		}
	}

	/**
	 * One line of context. Nested values that are not scalars are left out.
	 *
	 * @param string $json Stored context.
	 */
	private function context_text( string $json ): string {
		$decoded = json_decode( $json, true );
		if ( ! is_array( $decoded ) ) {
			return '';
		}

		$parts = array();
		foreach ( $decoded as $key => $value ) {
			if ( ! is_string( $key ) || 1 !== preg_match( '/\A[a-z0-9_]{1,40}\z/', $key ) ) {
				continue;
			}
			$rendered = $this->context_value( $value );
			if ( '' === $rendered ) {
				continue;
			}
			$parts[] = $key . ': ' . $rendered;
		}

		return implode( '; ', $parts );
	}

	/**
	 * One context value, or an empty string when it is not a scalar or a list of scalars.
	 *
	 * @param mixed $value Decoded context value.
	 */
	private function context_value( mixed $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}
		if ( is_int( $value ) ) {
			return (string) $value;
		}
		if ( is_string( $value ) && 1 === preg_match( '/\A[a-z0-9_]{1,40}\z/', $value ) ) {
			return $value;
		}
		if ( ! is_array( $value ) ) {
			return '';
		}

		$items = array();
		foreach ( $value as $item ) {
			$rendered = $this->context_value( $item );
			if ( '' !== $rendered ) {
				$items[] = $rendered;
			}
		}

		return implode( ', ', $items );
	}
}
