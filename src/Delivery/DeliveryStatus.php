<?php
/**
 * Delivery status names.
 *
 * @package Formstead
 */

namespace Formstead\Delivery;

/**
 * Status values stored on a delivery row.
 *
 * `handed_off` means the mail system accepted the message. It does not mean
 * the recipient's mailbox has it.
 */
final class DeliveryStatus {

	/**
	 * Waiting for a worker.
	 */
	public const PENDING = 'pending';

	/**
	 * Claimed by a worker.
	 */
	public const PROCESSING = 'processing';

	/**
	 * Accepted by the mail system. The mailbox may still not have it.
	 */
	public const HANDED_OFF = 'handed_off';

	/**
	 * Stopped after the attempt limit.
	 */
	public const FAILED = 'failed';

	/**
	 * Will not be sent.
	 */
	public const CANCELLED = 'cancelled';

	/**
	 * Status text that describes what happened, not that mail was delivered.
	 *
	 * @param string $status Stored status.
	 */
	public static function label( string $status ): string {
		return match ( $status ) {
			self::PENDING => __( 'Waiting to send', 'formstead' ),
			self::PROCESSING => __( 'Sending', 'formstead' ),
			self::HANDED_OFF => __( 'Handed to the mail system', 'formstead' ),
			self::FAILED => __( 'Failed', 'formstead' ),
			self::CANCELLED => __( 'Cancelled', 'formstead' ),
			default => $status,
		};
	}
}
