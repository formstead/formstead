<?php
/**
 * One delivery task.
 *
 * @package Formstead
 */

namespace Formstead\Delivery;

/**
 * Immutable delivery record.
 */
final class Delivery {

	/**
	 * Database id.
	 *
	 * @var int
	 */
	private int $id;

	/**
	 * Submission this delivery belongs to.
	 *
	 * @var int
	 */
	private int $submission_id;

	/**
	 * Delivery channel. `email` or `webhook`.
	 *
	 * @var string
	 */
	private string $channel;

	/**
	 * Stable webhook id, or an empty string for email.
	 *
	 * @var string
	 */
	private string $event_id;

	/**
	 * Current status.
	 *
	 * @var string
	 */
	private string $status;

	/**
	 * How many times a worker has claimed this delivery.
	 *
	 * @var int
	 */
	private int $attempts;

	/**
	 * UTC time when the delivery may be claimed.
	 *
	 * @var string
	 */
	private string $next_attempt_at;

	/**
	 * UTC time when an abandoned claim may be taken, or an empty string.
	 *
	 * @var string
	 */
	private string $locked_until;

	/**
	 * Claim token, or an empty string when the row is not locked.
	 *
	 * @var string
	 */
	private string $lock_token;

	/**
	 * Last error code, or an empty string.
	 *
	 * @var string
	 */
	private string $last_error;

	/**
	 * Create a delivery.
	 *
	 * @param int    $id              Database id.
	 * @param int    $submission_id   Submission id.
	 * @param string $channel         Channel name.
	 * @param string $status          Status.
	 * @param int    $attempts        Claim count.
	 * @param string $next_attempt_at UTC next attempt.
	 * @param string $locked_until    UTC lock expiry, or an empty string.
	 * @param string $lock_token      Claim token, or an empty string.
	 * @param string $last_error      Error code, or an empty string.
	 * @param string $event_id       Webhook id, or an empty string for email.
	 */
	public function __construct(
		int $id,
		int $submission_id,
		string $channel,
		string $status,
		int $attempts,
		string $next_attempt_at,
		string $locked_until,
		string $lock_token,
		string $last_error,
		string $event_id = ''
	) {
		$this->id              = $id;
		$this->submission_id   = $submission_id;
		$this->channel         = $channel;
		$this->status          = $status;
		$this->attempts        = $attempts;
		$this->next_attempt_at = $next_attempt_at;
		$this->locked_until    = $locked_until;
		$this->lock_token      = $lock_token;
		$this->last_error      = $last_error;
		$this->event_id        = $event_id;
	}

	/**
	 * Database id.
	 */
	public function id(): int {
		return $this->id;
	}

	/**
	 * Submission id.
	 */
	public function submission_id(): int {
		return $this->submission_id;
	}

	/**
	 * Channel name.
	 */
	public function channel(): string {
		return $this->channel;
	}

	/**
	 * Stable webhook id, or an empty string for email.
	 */
	public function event_id(): string {
		return $this->event_id;
	}

	/**
	 * Status.
	 */
	public function status(): string {
		return $this->status;
	}

	/**
	 * How many times a worker has claimed this delivery.
	 */
	public function attempts(): int {
		return $this->attempts;
	}

	/**
	 * UTC time when the delivery may be claimed.
	 */
	public function next_attempt_at(): string {
		return $this->next_attempt_at;
	}

	/**
	 * UTC lock expiry, or an empty string.
	 */
	public function locked_until(): string {
		return $this->locked_until;
	}

	/**
	 * Claim token, or an empty string.
	 */
	public function lock_token(): string {
		return $this->lock_token;
	}

	/**
	 * Last error code, or an empty string.
	 */
	public function last_error(): string {
		return $this->last_error;
	}
}
