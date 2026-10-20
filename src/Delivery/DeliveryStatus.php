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

	public const PENDING    = 'pending';
	public const PROCESSING = 'processing';
	public const HANDED_OFF = 'handed_off';
	public const FAILED     = 'failed';
	public const CANCELLED  = 'cancelled';
}
