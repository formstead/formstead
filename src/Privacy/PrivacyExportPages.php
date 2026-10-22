<?php
/**
 * Stable page boundaries for one WordPress privacy request.
 *
 * @package Formstead
 */

namespace Formstead\Privacy;

use Formstead\Database\Schema;
use WP_Error;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- private indexed reads; table names come from Schema.

/**
 * Keeps only ids and page boundaries, never a copy of personal data.
 */
final class PrivacyExportPages {

	public const META_KEY = '_formstead_export_pages';

	/**
	 * Read a page without OFFSET, bounded by the first page's highest id.
	 *
	 * @param int    $request_id WordPress privacy request.
	 * @param string $email      Requested address.
	 * @param int    $page       One-based page.
	 * @return array{ids: list<int>, done: bool}|WP_Error
	 */
	public static function read( int $request_id, string $email, int $page ): array|WP_Error {
		global $wpdb;

		$request = wp_get_user_request( $request_id );
		$hash    = IdentityHasher::hash( $email );
		if ( ! $request || 'export_personal_data' !== $request->action_name || strtolower( trim( $request->email ) ) !== strtolower( trim( $email ) ) || ! is_string( $hash ) ) {
			return self::error();
		}

		$table = Schema::identities_table();
		$state = get_post_meta( $request_id, self::META_KEY, true );
		if ( ! is_array( $state ) || ! isset( $state['until'], $state['starts'], $state['done_page'] ) ) {
			if ( 1 !== $page ) {
				return self::error();
			}
			$until = $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(submission_id) FROM %i WHERE value_hash = %s', $table, $hash ) );
			if ( '' !== (string) $wpdb->last_error ) {
				return new WP_Error( 'formstead_identity_index_unreadable', __( 'Formstead could not read the identity index. Try the export or erase again.', 'formstead' ) );
			}
			$state = array(
				'until'     => (int) $until,
				'starts'    => array( 1 => 0 ),
				'done_page' => 0,
			);
		}
		if ( ! is_array( $state['starts'] ) || ! isset( $state['starts'][ $page ] ) ) {
			return self::error();
		}

		$after    = (int) $state['starts'][ $page ];
		$replayed = isset( $state['starts'][ $page + 1 ] );
		$until    = $replayed ? (int) $state['starts'][ $page + 1 ] : (int) $state['until'];
		$rows     = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT DISTINCT submission_id FROM %i WHERE value_hash = %s AND submission_id > %d AND submission_id <= %d ORDER BY submission_id ASC LIMIT %d',
				$table,
				$hash,
				$after,
				$until,
				PrivacyExporter::PAGE_SIZE + 1
			)
		);
		if ( ! is_array( $rows ) || '' !== (string) $wpdb->last_error ) {
			return new WP_Error( 'formstead_identity_index_unreadable', __( 'Formstead could not read the identity index. Try the export or erase again.', 'formstead' ) );
		}

		$ids  = array_values( array_map( 'intval', array_slice( $rows, 0, PrivacyExporter::PAGE_SIZE ) ) );
		$done = $replayed ? (int) $state['done_page'] === $page : count( $rows ) <= PrivacyExporter::PAGE_SIZE;
		if ( ! $replayed ) {
			$state['starts'][ $page + 1 ] = $done ? (int) $state['until'] : $ids[ count( $ids ) - 1 ];
			if ( $done ) {
				$state['done_page'] = $page;
			}
		}
		if ( get_post_meta( $request_id, self::META_KEY, true ) !== $state ) {
			update_post_meta( $request_id, self::META_KEY, $state );
			if ( get_post_meta( $request_id, self::META_KEY, true ) !== $state ) {
				return self::error();
			}
		}

		return array(
			'ids'  => $ids,
			'done' => $done,
		);
	}

	/**
	 * Generic error with no address or database detail.
	 */
	private static function error(): WP_Error {
		return new WP_Error( 'formstead_export_request_unavailable', __( 'The export page could not be prepared. Restart the personal-data export.', 'formstead' ) );
	}
}
