<?php
/**
 * Result of reserving an idempotency key.
 *
 * @package Formstead
 */

namespace Formstead\Submissions;

/**
 * Whether a key was inserted, replayed, or rejected.
 */
final class IdempotencyClaim {

	/**
	 * `inserted`, `replay`, `conflict`, or `failed`.
	 *
	 * @var string
	 */
	private string $state;

	/**
	 * New key row id. Zero unless the key was inserted.
	 *
	 * @var int
	 */
	private int $id;

	/**
	 * Existing submission id. Zero unless this is a replay.
	 *
	 * @var int
	 */
	private int $submission_id;

	/**
	 * Create a claim result.
	 *
	 * @param string $state         Claim state.
	 * @param int    $id            Key row id.
	 * @param int    $submission_id Existing submission id.
	 */
	private function __construct( string $state, int $id, int $submission_id ) {
		$this->state         = $state;
		$this->id            = $id;
		$this->submission_id = $submission_id;
	}

	/**
	 * The key row was inserted and still needs a submission id.
	 *
	 * @param int $id Key row id.
	 */
	public static function inserted( int $id ): self {
		return new self( 'inserted', $id, 0 );
	}

	/**
	 * The same key and body were stored before.
	 *
	 * @param int $submission_id Existing submission id.
	 */
	public static function replay( int $submission_id ): self {
		return new self( 'replay', 0, $submission_id );
	}

	/**
	 * The same key was stored with a different body.
	 */
	public static function conflict(): self {
		return new self( 'conflict', 0, 0 );
	}

	/**
	 * The key could not be saved.
	 */
	public static function failed(): self {
		return new self( 'failed', 0, 0 );
	}

	/**
	 * Whether the caller should insert a submission and attach it.
	 */
	public function is_inserted(): bool {
		return 'inserted' === $this->state;
	}

	/**
	 * Whether the caller should return the original receipt.
	 */
	public function is_replay(): bool {
		return 'replay' === $this->state;
	}

	/**
	 * Whether the caller should return 409.
	 */
	public function is_conflict(): bool {
		return 'conflict' === $this->state;
	}

	/**
	 * Whether the database rejected the key.
	 */
	public function is_failed(): bool {
		return 'failed' === $this->state;
	}

	/**
	 * New key row id.
	 */
	public function id(): int {
		return $this->id;
	}

	/**
	 * Existing submission id for a replay.
	 */
	public function submission_id(): int {
		return $this->submission_id;
	}
}
