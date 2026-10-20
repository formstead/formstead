<?php
/**
 * Per-form endpoint protection.
 *
 * @package Formstead
 */

namespace Formstead\Forms;

/**
 * Origin allowlist and optional rate-limit overrides.
 *
 * An empty allowlist means only this site's origin is accepted. A null rate
 * uses the global limit. Changing these values does not change the field schema.
 */
final class FormProtection {

	/**
	 * Normalized origins allowed to submit this form.
	 *
	 * @var string[]
	 */
	private array $allowed_origins;

	/**
	 * Submissions allowed per IP hash each minute, or null for the global limit.
	 *
	 * @var int|null
	 */
	private ?int $rate_per_minute;

	/**
	 * Submissions allowed for the form each hour, or null for the global limit.
	 *
	 * @var int|null
	 */
	private ?int $rate_per_hour;

	/**
	 * Create protection settings.
	 *
	 * @param string[] $allowed_origins Normalized origins.
	 * @param int|null $rate_per_minute Per-minute override, or null.
	 * @param int|null $rate_per_hour   Per-hour override, or null.
	 */
	public function __construct( array $allowed_origins = array(), ?int $rate_per_minute = null, ?int $rate_per_hour = null ) {
		$this->allowed_origins = $allowed_origins;
		$this->rate_per_minute = $rate_per_minute;
		$this->rate_per_hour   = $rate_per_hour;
	}

	/**
	 * No allowlist and no rate overrides.
	 */
	public static function none(): self {
		return new self();
	}

	/**
	 * Protection stored on a form row.
	 *
	 * Invalid JSON is treated as an empty allowlist, which allows only this site.
	 *
	 * @param string|null $origins_json JSON list of origins, or null.
	 * @param mixed       $per_minute   Stored per-minute override.
	 * @param mixed       $per_hour     Stored per-hour override.
	 */
	public static function from_stored( ?string $origins_json, mixed $per_minute, mixed $per_hour ): self {
		$origins = array();
		if ( is_string( $origins_json ) && '' !== $origins_json ) {
			$decoded = json_decode( $origins_json, true );
			if ( is_array( $decoded ) ) {
				foreach ( $decoded as $origin ) {
					if ( is_string( $origin ) && '' !== $origin ) {
						$origins[] = $origin;
					}
				}
			}
		}

		return new self( $origins, self::optional_int( $per_minute ), self::optional_int( $per_hour ) );
	}

	/**
	 * Normalized origins. Empty means the site origin only.
	 *
	 * @return string[]
	 */
	public function allowed_origins(): array {
		return $this->allowed_origins;
	}

	/**
	 * JSON list for storage, or null when the allowlist is empty.
	 */
	public function origins_json(): ?string {
		if ( array() === $this->allowed_origins ) {
			return null;
		}

		$encoded = wp_json_encode( $this->allowed_origins );
		return is_string( $encoded ) ? $encoded : null;
	}

	/**
	 * Per-minute override, or null when the global limit applies.
	 */
	public function rate_per_minute(): ?int {
		return $this->rate_per_minute;
	}

	/**
	 * Per-hour override, or null when the global limit applies.
	 */
	public function rate_per_hour(): ?int {
		return $this->rate_per_hour;
	}

	/**
	 * A positive integer stored in a nullable column, or null.
	 *
	 * @param mixed $value Column value.
	 */
	private static function optional_int( mixed $value ): ?int {
		if ( null === $value || '' === $value || ! is_numeric( $value ) ) {
			return null;
		}

		$number = (int) $value;
		return $number > 0 ? $number : null;
	}
}
