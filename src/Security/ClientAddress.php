<?php
/**
 * Client address used for rate limits.
 *
 * @package Formstead
 */

namespace Formstead\Security;

/**
 * Resolves the address that identifies a submitter.
 *
 * The address is `REMOTE_ADDR`. `X-Forwarded-For` is read from the right only
 * when that direct address is a trusted proxy. IPv6 addresses are reduced to
 * their /64 prefix. The raw address is never stored.
 */
final class ClientAddress {

	/**
	 * Address to hash into a rate-limit bucket, or null when none is available.
	 */
	public static function subject(): ?string {
		$remote = self::valid_ip( self::server( 'REMOTE_ADDR' ) );
		if ( null === $remote ) {
			return null;
		}

		if ( ! self::is_trusted( $remote ) ) {
			return self::network( $remote );
		}

		$client = $remote;
		foreach ( array_reverse( self::forwarded_parts() ) as $part ) {
			$ip = self::valid_ip( $part );
			if ( null === $ip ) {
				break;
			}

			$client = $ip;
			if ( ! self::is_trusted( $ip ) ) {
				break;
			}
		}

		return self::network( $client );
	}

	/**
	 * Trusted proxy rules from the constant and the `formstead_trusted_proxies` filter.
	 *
	 * Each entry is an IP address or a CIDR range.
	 *
	 * @return list<string>
	 */
	public static function trusted_proxies(): array {
		$configured = array();
		if ( defined( 'FORMSTEAD_TRUSTED_PROXIES' ) ) {
			$configured = self::rules( constant( 'FORMSTEAD_TRUSTED_PROXIES' ) );
		}

		/**
		 * Filters the trusted proxy addresses.
		 *
		 * @param list<string> $proxies IP addresses and CIDR ranges.
		 */
		$filtered = apply_filters( 'formstead_trusted_proxies', $configured );
		return self::rules( $filtered );
	}

	/**
	 * Whether an IP belongs to the trusted proxy list.
	 *
	 * @param string $ip Valid IP address.
	 */
	private static function is_trusted( string $ip ): bool {
		foreach ( self::trusted_proxies() as $rule ) {
			if ( self::matches( $ip, $rule ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * IPv4 as itself, IPv4-mapped IPv6 as IPv4, and other IPv6 as the /64 network.
	 *
	 * @param string $ip Valid IP address.
	 */
	private static function network( string $ip ): string {
		$packed = inet_pton( $ip );
		if ( false === $packed ) {
			return $ip;
		}

		if ( 4 === strlen( $packed ) ) {
			return $ip;
		}

		if ( str_starts_with( $packed, "\0\0\0\0\0\0\0\0\0\0\xff\xff" ) ) {
			$mapped = inet_ntop( substr( $packed, 12 ) );
			return is_string( $mapped ) ? $mapped : $ip;
		}

		$masked = substr( $packed, 0, 8 ) . str_repeat( "\0", 8 );
		$text   = inet_ntop( $masked );
		return is_string( $text ) ? $text : $ip;
	}

	/**
	 * Whether an IP matches an address or a CIDR rule.
	 *
	 * @param string $ip   Valid IP address.
	 * @param string $rule IP address or CIDR range.
	 */
	private static function matches( string $ip, string $rule ): bool {
		if ( ! str_contains( $rule, '/' ) ) {
			return $ip === $rule;
		}

		$bits = explode( '/', $rule, 2 );
		$net  = self::valid_ip( $bits[0] );
		$mask = isset( $bits[1] ) && 1 === preg_match( '/^\d+$/', $bits[1] ) ? (int) $bits[1] : -1;
		if ( null === $net ) {
			return false;
		}

		$ip_bytes  = inet_pton( $ip );
		$net_bytes = inet_pton( $net );
		if ( false === $ip_bytes || false === $net_bytes || strlen( $ip_bytes ) !== strlen( $net_bytes ) ) {
			return false;
		}

		$width = 8 * strlen( $ip_bytes );
		if ( $mask < 0 || $mask > $width ) {
			return false;
		}

		$whole = intdiv( $mask, 8 );
		if ( substr( $ip_bytes, 0, $whole ) !== substr( $net_bytes, 0, $whole ) ) {
			return false;
		}

		$rest = $mask % 8;
		if ( 0 === $rest ) {
			return true;
		}

		$filter = ( 0xff << ( 8 - $rest ) ) & 0xff;
		return ( ord( $ip_bytes[ $whole ] ) & $filter ) === ( ord( $net_bytes[ $whole ] ) & $filter );
	}

	/**
	 * A validated IP address, or null.
	 *
	 * @param string $value Candidate address.
	 */
	private static function valid_ip( string $value ): ?string {
		$ip = filter_var( trim( $value ), FILTER_VALIDATE_IP );
		return is_string( $ip ) ? $ip : null;
	}

	/**
	 * Proxy rules from a constant or a filter value.
	 *
	 * @param mixed $value Comma-separated string or a list.
	 * @return list<string>
	 */
	private static function rules( mixed $value ): array {
		if ( is_string( $value ) ) {
			$parts = array_map( 'trim', explode( ',', $value ) );
		} elseif ( is_array( $value ) ) {
			$parts = array();
			foreach ( $value as $item ) {
				if ( is_string( $item ) ) {
					$parts[] = trim( $item );
				}
			}
		} else {
			return array();
		}

		$rules = array();
		foreach ( $parts as $part ) {
			if ( '' !== $part ) {
				$rules[] = $part;
			}
		}

		return $rules;
	}

	/**
	 * Pieces of `X-Forwarded-For`, left to right.
	 *
	 * @return list<string>
	 */
	private static function forwarded_parts(): array {
		$header = self::server( 'HTTP_X_FORWARDED_FOR' );
		if ( '' === $header ) {
			return array();
		}

		return array_map( 'trim', explode( ',', $header ) );
	}

	/**
	 * One server value, unslashed. It is validated by the caller.
	 *
	 * @param string $key Server key.
	 */
	private static function server( string $key ): string {
		if ( ! isset( $_SERVER[ $key ] ) || ! is_string( $_SERVER[ $key ] ) ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated with FILTER_VALIDATE_IP or used only as a header list that is then validated.
		return wp_unslash( $_SERVER[ $key ] );
	}
}
