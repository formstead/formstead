<?php
/**
 * Decides whether a webhook URL may be called.
 *
 * @package Formstead
 */

namespace Formstead\Webhook;

use WP_Error;

/**
 * Accepts HTTPS URLs whose addresses are public.
 *
 * A name is resolved to A and AAAA records. One blocked address rejects the
 * URL. The checked addresses are returned so the request can pin them and a
 * later lookup cannot point the same name somewhere else. An allowed host
 * from `formstead_webhook_allowed_hosts` may use another port and a private
 * address. That filter is for a test environment.
 */
final class WebhookUrlPolicy {

	/**
	 * Filter that lists hosts a test environment may call.
	 */
	public const ALLOWED_HOSTS = 'formstead_webhook_allowed_hosts';

	/**
	 * How a name becomes addresses. Null uses DNS.
	 *
	 * @var callable(string): list<string>|null
	 */
	private $resolver;

	/**
	 * Environment for the test-host exception.
	 *
	 * @var string
	 */
	private string $environment;

	/**
	 * Create a policy.
	 *
	 * @param callable(string): list<string>|null $resolver    Address lookup. Null uses DNS.
	 * @param string|null                         $environment Environment override for tests.
	 */
	public function __construct( ?callable $resolver = null, ?string $environment = null ) {
		$this->resolver    = $resolver;
		$this->environment = $environment ?? wp_get_environment_type();
	}

	/**
	 * Check a URL and return the addresses to pin.
	 *
	 * The URL in the result is the caller's URL without surrounding space.
	 * It is not rewritten to an IP, so the Host header stays the name.
	 *
	 * @param string $url Webhook URL.
	 * @return array{url: string, host: string, port: int, resolve: list<string>}|WP_Error
	 */
	public function inspect( string $url ): array|WP_Error {
		$url    = trim( $url );
		$parsed = wp_parse_url( $url );
		if ( ! is_array( $parsed ) ) {
			return $this->blocked();
		}

		if ( isset( $parsed['user'] ) || isset( $parsed['pass'] ) ) {
			return $this->blocked();
		}

		$host = $parsed['host'] ?? '';
		if ( ! is_string( $host ) || '' === $host ) {
			return $this->blocked();
		}

		$host = strtolower( trim( $host, '[]' ) );
		$port = isset( $parsed['port'] ) ? (int) $parsed['port'] : 443;
		if ( $port < 1 || $port > 65535 ) {
			return $this->blocked();
		}

		$scheme  = strtolower( (string) ( $parsed['scheme'] ?? '' ) );
		$allowed = $this->is_allowed( $host, $port );
		if ( 'https' !== $scheme && ! ( $allowed && 'http' === $scheme ) ) {
			return $this->blocked();
		}
		if ( 443 !== $port && ! $allowed ) {
			return $this->blocked();
		}

		$literal = $this->literal_address( $host );
		if ( false === $literal ) {
			return $this->blocked();
		}

		if ( is_string( $literal ) ) {
			$addresses = array( $literal );
		} else {
			if ( $this->blocked_name( $host ) ) {
				return $this->blocked();
			}
			$addresses = $this->resolve( $host );
		}

		if ( array() === $addresses ) {
			return $this->blocked();
		}

		$resolve = array();
		foreach ( $addresses as $address ) {
			if ( ! is_string( $address ) || ! $this->is_ip( $address ) ) {
				return $this->blocked();
			}
			if ( ! $allowed && $this->is_blocked_ip( $address ) ) {
				return $this->blocked();
			}
			$resolve[] = $host . ':' . $port . ':' . $this->resolve_address( $address );
		}

		return array(
			'url'     => $url,
			'host'    => $host,
			'port'    => $port,
			'resolve' => $resolve,
		);
	}

	/**
	 * Whether this host and port were explicitly allowed.
	 *
	 * An entry is a host, or a host and a port separated by a colon. An IPv6
	 * entry keeps its brackets when it includes a port: `[::1]:8443`.
	 *
	 * @param string $host Lower-case host without brackets.
	 * @param int    $port Port, including the HTTPS default.
	 */
	private function is_allowed( string $host, int $port ): bool {
		if ( ! defined( 'FORMSTEAD_E2E' ) || ! constant( 'FORMSTEAD_E2E' ) || ! in_array( $this->environment, array( 'local', 'development' ), true ) ) {
			return false;
		}

		$allowed = apply_filters( 'formstead_webhook_allowed_hosts', array() );
		if ( ! is_array( $allowed ) ) {
			return false;
		}

		foreach ( $allowed as $entry ) {
			if ( ! is_string( $entry ) ) {
				continue;
			}
			$entry = strtolower( trim( $entry ) );
			if ( $host === $entry ) {
				return true;
			}
			if ( $host . ':' . $port === $entry || '[' . $host . ']:' . $port === $entry ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Look up a name.
	 *
	 * @param string $host Host that is not an IP literal.
	 * @return list<string>
	 */
	private function resolve( string $host ): array {
		if ( is_callable( $this->resolver ) ) {
			return array_values( ( $this->resolver )( $host ) );
		}

		if ( ! function_exists( 'dns_get_record' ) ) {
			return array();
		}

		$records = dns_get_record( $host, DNS_A | DNS_AAAA );
		if ( ! is_array( $records ) ) {
			return array();
		}

		$addresses = array();
		foreach ( $records as $record ) {
			if ( isset( $record['ip'] ) && is_string( $record['ip'] ) ) {
				$addresses[] = $record['ip'];
			}
			if ( isset( $record['ipv6'] ) && is_string( $record['ipv6'] ) ) {
				$addresses[] = $record['ipv6'];
			}
		}

		return $addresses;
	}

	/**
	 * An IP literal, false when the host looks like a broken literal, or null for a name.
	 *
	 * Decimal (`2130706433`), hex, and octal spellings are literals. They are
	 * not looked up in DNS.
	 *
	 * @param string $host Lower-case host.
	 * @return string|false|null Canonical IP, false when it must be rejected, null when it is a name.
	 */
	private function literal_address( string $host ): string|false|null {
		if ( str_contains( $host, ':' ) ) {
			return $this->is_ip( $host ) ? $host : false;
		}

		if ( 1 !== preg_match( '/\A(0x[0-9a-f]+|\d+)(\.(0x[0-9a-f]+|\d+)){0,3}\z/i', $host ) ) {
			return null;
		}

		$parts  = explode( '.', $host );
		$values = array();
		foreach ( $parts as $part ) {
			$value = $this->component( $part );
			if ( ! is_int( $value ) ) {
				return false;
			}
			$values[] = $value;
		}

		$ip = $this->assemble_v4( $values );
		if ( ! is_int( $ip ) ) {
			return false;
		}

		$canonical = long2ip( $ip );
		return is_string( $canonical ) ? $canonical : false;
	}

	/**
	 * One component of an IPv4 literal.
	 *
	 * A leading zero is octal. `0x` is hex. Anything else is decimal.
	 *
	 * @param string $part One dot-separated component.
	 */
	private function component( string $part ): int|false {
		if ( 1 === preg_match( '/\A0x([0-9a-f]+)\z/i', $part, $matches ) ) {
			if ( strlen( $matches[1] ) > 8 ) {
				return false;
			}
			$value = hexdec( $matches[1] );
		} elseif ( 1 === preg_match( '/\A0[0-7]+\z/', $part ) ) {
			$value = octdec( $part );
		} elseif ( 1 === preg_match( '/\A[0-9]+\z/', $part ) ) {
			if ( strlen( $part ) > 10 ) {
				return false;
			}
			$value = (int) $part;
		} else {
			return false;
		}

		if ( $value < 0 || $value > 4294967295 ) {
			return false;
		}

		return (int) $value;
	}

	/**
	 * Pack one to four IPv4 components the way inet_addr does.
	 *
	 * @param array<int, int> $parts Component values.
	 */
	private function assemble_v4( array $parts ): int|false {
		$count = count( $parts );
		if ( 4 === $count ) {
			foreach ( $parts as $part ) {
				if ( $part > 255 ) {
					return false;
				}
			}
			return ( $parts[0] << 24 ) | ( $parts[1] << 16 ) | ( $parts[2] << 8 ) | $parts[3];
		}

		if ( 3 === $count && $parts[0] <= 255 && $parts[1] <= 255 && $parts[2] <= 65535 ) {
			return ( $parts[0] << 24 ) | ( $parts[1] << 16 ) | $parts[2];
		}

		if ( 2 === $count && $parts[0] <= 255 && $parts[1] <= 16777215 ) {
			return ( $parts[0] << 24 ) | $parts[1];
		}

		if ( 1 === $count && $parts[0] <= 4294967295 ) {
			return $parts[0];
		}

		return false;
	}

	/**
	 * Whether a name is a loopback name.
	 *
	 * @param string $host Lower-case host.
	 */
	private function blocked_name( string $host ): bool {
		return 'localhost' === $host
			|| 'localhost.localdomain' === $host
			|| str_ends_with( $host, '.localhost' );
	}

	/**
	 * Whether the string is an IP address.
	 *
	 * @param string $address Candidate address.
	 */
	private function is_ip( string $address ): bool {
		return false !== filter_var( $address, FILTER_VALIDATE_IP );
	}

	/**
	 * Whether an IP is loopback, private, link-local, metadata, or otherwise not global.
	 *
	 * IPv4-mapped IPv6 is blocked even when the embedded address would be public.
	 *
	 * @param string $address IP address.
	 */
	private function is_blocked_ip( string $address ): bool {
		$packed = inet_pton( $address );
		if ( ! is_string( $packed ) ) {
			return true;
		}

		if ( 16 === strlen( $packed ) && $this->is_ipv4_mapped( $packed ) ) {
			return true;
		}

		if ( 16 === strlen( $packed ) && $this->is_6to4( $packed ) ) {
			return true;
		}

		$ranges = 4 === strlen( $packed ) ? self::v4_ranges() : self::v6_ranges();
		foreach ( $ranges as $range ) {
			if ( $this->in_range( $packed, $range['network'], $range['bits'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Address form curl expects inside `CURLOPT_RESOLVE`. IPv6 is bracketed.
	 *
	 * @param string $address IP address.
	 */
	private function resolve_address( string $address ): string {
		if ( str_contains( $address, ':' ) ) {
			return '[' . $address . ']';
		}

		return $address;
	}

	/**
	 * Whether the address is in this network.
	 *
	 * @param string $packed  Address from `inet_pton()`.
	 * @param string $network Network address.
	 * @param int    $bits    Prefix length.
	 */
	private function in_range( string $packed, string $network, int $bits ): bool {
		$base = inet_pton( $network );
		if ( ! is_string( $base ) || strlen( $base ) !== strlen( $packed ) ) {
			return false;
		}

		return $this->masked( $packed, $bits ) === $this->masked( $base, $bits );
	}

	/**
	 * Keep the prefix bits and zero the rest.
	 *
	 * @param string $packed Address bytes.
	 * @param int    $bits   Prefix length.
	 */
	private function masked( string $packed, int $bits ): string {
		$length = strlen( $packed );
		$bytes  = intdiv( $bits, 8 );
		$rest   = $bits % 8;
		$mask   = str_repeat( "\xff", $bytes );
		if ( $rest > 0 ) {
			$mask .= chr( ( 0xff << ( 8 - $rest ) ) & 0xff );
		}
		$mask = str_pad( $mask, $length, "\x00" );

		return $packed & $mask;
	}

	/**
	 * Whether the IPv6 address embeds an IPv4 address after `::ffff`.
	 *
	 * @param string $packed 16 address bytes.
	 */
	private function is_ipv4_mapped( string $packed ): bool {
		return str_starts_with( $packed, "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff" );
	}

	/**
	 * Whether the address is 6to4 (`2002::/16`).
	 *
	 * @param string $packed 16 address bytes.
	 */
	private function is_6to4( string $packed ): bool {
		return str_starts_with( $packed, "\x20\x02" );
	}

	/**
	 * IPv4 networks that are not a public webhook destination.
	 *
	 * @return list<array{network: string, bits: int}>
	 */
	private static function v4_ranges(): array {
		return array(
			array(
				'network' => '0.0.0.0',
				'bits'    => 8,
			),
			array(
				'network' => '10.0.0.0',
				'bits'    => 8,
			),
			array(
				'network' => '100.64.0.0',
				'bits'    => 10,
			),
			array(
				'network' => '127.0.0.0',
				'bits'    => 8,
			),
			array(
				'network' => '169.254.0.0',
				'bits'    => 16,
			),
			array(
				'network' => '172.16.0.0',
				'bits'    => 12,
			),
			array(
				'network' => '192.0.0.0',
				'bits'    => 24,
			),
			array(
				'network' => '192.0.2.0',
				'bits'    => 24,
			),
			array(
				'network' => '192.168.0.0',
				'bits'    => 16,
			),
			array(
				'network' => '198.18.0.0',
				'bits'    => 15,
			),
			array(
				'network' => '198.51.100.0',
				'bits'    => 24,
			),
			array(
				'network' => '203.0.113.0',
				'bits'    => 24,
			),
			array(
				'network' => '224.0.0.0',
				'bits'    => 4,
			),
			array(
				'network' => '240.0.0.0',
				'bits'    => 4,
			),
		);
	}

	/**
	 * IPv6 networks that are not a public webhook destination.
	 *
	 * @return list<array{network: string, bits: int}>
	 */
	private static function v6_ranges(): array {
		return array(
			array(
				'network' => '::',
				'bits'    => 128,
			),
			array(
				'network' => '::1',
				'bits'    => 128,
			),
			array(
				'network' => 'fc00::',
				'bits'    => 7,
			),
			array(
				'network' => 'fe80::',
				'bits'    => 10,
			),
			array(
				'network' => 'ff00::',
				'bits'    => 8,
			),
			array(
				'network' => '2001:db8::',
				'bits'    => 32,
			),
		);
	}

	/**
	 * Rejection without the URL, so userinfo cannot reach the message.
	 */
	private function blocked(): WP_Error {
		return new WP_Error(
			WebhookFailure::BLOCKED_URL,
			__( 'The webhook address is not allowed.', 'formstead' )
		);
	}
}
