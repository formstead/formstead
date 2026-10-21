<?php
/**
 * Per-form webhook settings.
 *
 * @package Formstead
 */

namespace Formstead\Forms;

/**
 * URL, signing secret, and whether the body includes the submission.
 *
 * Changing these values does not change the field schema. An empty URL means
 * the form does not send a webhook. The secret is created when a URL is first
 * saved and is replaced only by rotation or by clearing the URL.
 */
final class FormWebhook {

	/**
	 * Destination URL, or an empty string when no webhook is configured.
	 *
	 * @var string
	 */
	private string $url;

	/**
	 * Signing secret, or an empty string when no webhook is configured.
	 *
	 * @var string
	 */
	private string $secret;

	/**
	 * Whether the body includes the submitted fields.
	 *
	 * @var bool
	 */
	private bool $include_content;

	/**
	 * Create webhook settings.
	 *
	 * @param string $url             Destination URL.
	 * @param string $secret          Signing secret.
	 * @param bool   $include_content Whether the body includes submitted fields.
	 */
	public function __construct( string $url, string $secret, bool $include_content ) {
		$this->url             = $url;
		$this->secret          = $secret;
		$this->include_content = $include_content;
	}

	/**
	 * No webhook.
	 */
	public static function none(): self {
		return new self( '', '', false );
	}

	/**
	 * Settings read from the forms table.
	 *
	 * @param mixed $url             Stored URL.
	 * @param mixed $secret          Stored secret.
	 * @param mixed $include_content Stored flag.
	 */
	public static function from_stored( mixed $url, mixed $secret, mixed $include_content ): self {
		return new self(
			is_string( $url ) ? $url : '',
			is_string( $secret ) ? $secret : '',
			1 === (int) $include_content
		);
	}

	/**
	 * A new `whsec_` secret.
	 *
	 * The prefix and the base64 body match Standard Webhooks. The secret is
	 * stored so later deliveries can sign with it.
	 */
	public static function generate_secret(): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Standard Webhooks stores the key as base64, not as hidden code.
		return 'whsec_' . base64_encode( random_bytes( 32 ) );
	}

	/**
	 * Destination URL.
	 */
	public function url(): string {
		return $this->url;
	}

	/**
	 * Signing secret.
	 */
	public function secret(): string {
		return $this->secret;
	}

	/**
	 * Whether the body includes the submitted fields.
	 */
	public function includes_content(): bool {
		return $this->include_content;
	}

	/**
	 * Whether a destination is configured.
	 */
	public function is_configured(): bool {
		return '' !== $this->url;
	}
}
