<?php
/**
 * Per-form email notification settings.
 *
 * @package Formstead
 */

namespace Formstead\Forms;

/**
 * Recipients, the reply-to field, and whether mail includes the submission.
 *
 * Changing these values does not change the field schema. An empty recipient
 * list means the form stores submissions and does not send mail.
 */
final class FormNotifications {

	/**
	 * Addresses that receive a new submission.
	 *
	 * @var string[]
	 */
	private array $recipients;

	/**
	 * Name of the email field used as Reply-To, or null.
	 *
	 * @var string|null
	 */
	private ?string $reply_to_field;

	/**
	 * Whether the message includes the submitted values.
	 *
	 * @var bool
	 */
	private bool $include_content;

	/**
	 * Create notification settings.
	 *
	 * @param string[]    $recipients     Recipient addresses.
	 * @param string|null $reply_to_field Email field name, or null.
	 * @param bool        $include_content Whether the body includes the submission.
	 */
	public function __construct( array $recipients = array(), ?string $reply_to_field = null, bool $include_content = false ) {
		$this->recipients      = $recipients;
		$this->reply_to_field  = $reply_to_field;
		$this->include_content = $include_content;
	}

	/**
	 * No recipients and no reply-to field.
	 */
	public static function none(): self {
		return new self();
	}

	/**
	 * Settings stored on a form row.
	 *
	 * Invalid JSON is an empty recipient list.
	 *
	 * @param string|null $recipients_json JSON list of addresses, or null.
	 * @param mixed       $reply_to_field  Stored field name.
	 * @param mixed       $include_content Stored include flag.
	 */
	public static function from_stored( ?string $recipients_json, mixed $reply_to_field, mixed $include_content ): self {
		$recipients = array();
		if ( is_string( $recipients_json ) && '' !== $recipients_json ) {
			$decoded = json_decode( $recipients_json, true );
			if ( is_array( $decoded ) ) {
				foreach ( $decoded as $recipient ) {
					if ( is_string( $recipient ) && '' !== $recipient ) {
						$recipients[] = $recipient;
					}
				}
			}
		}

		$field = is_string( $reply_to_field ) && '' !== $reply_to_field ? $reply_to_field : null;

		return new self( $recipients, $field, 1 === (int) $include_content );
	}

	/**
	 * Recipient addresses.
	 *
	 * @return string[]
	 */
	public function recipients(): array {
		return $this->recipients;
	}

	/**
	 * Whether at least one recipient is configured.
	 */
	public function has_recipients(): bool {
		return array() !== $this->recipients;
	}

	/**
	 * JSON list for storage, or null when nobody is notified.
	 */
	public function recipients_json(): ?string {
		if ( array() === $this->recipients ) {
			return null;
		}

		$encoded = wp_json_encode( array_values( $this->recipients ) );
		return is_string( $encoded ) ? $encoded : null;
	}

	/**
	 * Email field used as Reply-To, or null.
	 */
	public function reply_to_field(): ?string {
		return $this->reply_to_field;
	}

	/**
	 * Whether the message includes the submitted values.
	 */
	public function includes_content(): bool {
		return $this->include_content;
	}
}
