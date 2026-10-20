<?php
/**
 * HMAC secret for client addresses.
 *
 * @package Formstead
 */

namespace Formstead\Security;

/**
 * Stores the secret that hashes client addresses into rate-limit keys.
 *
 * The secret is rotated lazily, at most once a day, on the next request.
 * It is not autoloaded and it is never written to a log.
 */
final class IpHasher {

	public const OPTION = 'formstead_ip_secret';

	/**
	 * Current HMAC secret, rotating it when it is older than a day.
	 */
	public static function secret(): string {
		$stored = get_option( self::OPTION, null );
		$now    = time();

		if ( is_array( $stored ) && isset( $stored['secret'], $stored['rotated_at'] ) && is_string( $stored['secret'] ) && '' !== $stored['secret'] && is_numeric( $stored['rotated_at'] ) && ( $now - (int) $stored['rotated_at'] ) < DAY_IN_SECONDS ) {
			return $stored['secret'];
		}

		$secret = bin2hex( random_bytes( 32 ) );
		update_option(
			self::OPTION,
			array(
				'secret'     => $secret,
				'rotated_at' => $now,
			),
			false
		);

		return $secret;
	}
}
