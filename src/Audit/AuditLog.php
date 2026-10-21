<?php
/**
 * Append-only record of admin and retention actions.
 *
 * @package Formstead
 */

namespace Formstead\Audit;

use Formstead\Database\Schema;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table. A failed insert is not logged.

/**
 * Writes one row after an operation has succeeded.
 *
 * The context keeps numbers, booleans, and short codes. A field value, an
 * address, or an IP never belongs here. A failed insert does not undo the
 * operation and does not write to the log.
 */
final class AuditLog {

	/**
	 * Record one event for the current user, or for user 0 when nobody is logged in.
	 *
	 * @param string               $event       Event code from AuditEvent.
	 * @param string               $object_type Short object code, such as `form`.
	 * @param int                  $object_id   Object id, or zero when there is none.
	 * @param array<string, mixed> $context     Numbers, booleans, and short codes.
	 */
	public static function record( string $event, string $object_type, int $object_id, array $context ): void {
		global $wpdb;

		if ( ! AuditEvent::is_known( $event ) || $object_id < 0 || 1 !== preg_match( '/\A[a-z_]{1,20}\z/', $object_type ) ) {
			return;
		}

		$encoded = self::encode( $context );
		if ( ! is_string( $encoded ) ) {
			return;
		}

		$previous = $wpdb->suppress_errors( true );
		$wpdb->insert(
			Schema::audit_table(),
			array(
				'event'       => $event,
				'actor_id'    => get_current_user_id(),
				'object_type' => $object_type,
				'object_id'   => $object_id,
				'context'     => $encoded,
				'created_at'  => current_time( 'mysql', true ),
			),
			array( '%s', '%d', '%s', '%d', '%s', '%s' )
		);
		$wpdb->suppress_errors( $previous );
	}

	/**
	 * JSON context, or null when it cannot be encoded.
	 *
	 * Strings that are not short codes are left out, so a payload cannot land
	 * in the row by being passed through.
	 *
	 * @param array<string, mixed> $context Context supplied by the caller.
	 */
	private static function encode( array $context ): ?string {
		$clean = self::clean( $context );
		if ( ! is_array( $clean ) ) {
			return null;
		}

		$encoded = wp_json_encode( $clean );
		return is_string( $encoded ) ? $encoded : null;
	}

	/**
	 * Keep numbers, booleans, and short codes. Drop everything else.
	 *
	 * @param mixed $value One context value.
	 */
	private static function clean( mixed $value ): mixed {
		if ( is_int( $value ) || is_bool( $value ) ) {
			return $value;
		}

		if ( is_string( $value ) ) {
			return 1 === preg_match( '/\A[a-z0-9_]{1,40}\z/', $value ) ? $value : null;
		}

		if ( ! is_array( $value ) ) {
			return null;
		}

		$list  = array_is_list( $value );
		$clean = array();
		foreach ( $value as $key => $item ) {
			$kept = self::clean( $item );
			if ( null === $kept ) {
				continue;
			}

			if ( $list ) {
				$clean[] = $kept;
				continue;
			}

			if ( ! is_string( $key ) || 1 !== preg_match( '/\A[a-z0-9_]{1,40}\z/', $key ) ) {
				continue;
			}

			$clean[ $key ] = $kept;
		}

		return $clean;
	}
}
