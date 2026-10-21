<?php
/**
 * Result of accepting a validated submission.
 *
 * @package Formstead
 */

namespace Formstead\Submissions;

/**
 * Accepted, replayed, conflicting, or failed intake.
 */
final class IntakeResult {

	/**
	 * `accepted`, `replayed`, `conflict`, `contended`, or `failed`.
	 *
	 * @var string
	 */
	private string $state;

	/**
	 * Stored or replayed submission. Null for a conflict or a failure.
	 *
	 * @var Submission|null
	 */
	private ?Submission $submission;

	/**
	 * Delivery created with an accepted submission, or null.
	 *
	 * @var int|null
	 */
	private ?int $delivery_id;

	/**
	 * Create an intake result.
	 *
	 * @param string          $state       Result state.
	 * @param Submission|null $submission  Related submission.
	 * @param int|null        $delivery_id Delivery created with the submission.
	 */
	private function __construct( string $state, ?Submission $submission, ?int $delivery_id = null ) {
		$this->state       = $state;
		$this->submission  = $submission;
		$this->delivery_id = $delivery_id;
	}

	/**
	 * A new submission was stored.
	 *
	 * @param Submission $submission  Stored submission.
	 * @param int|null   $delivery_id Delivery created with the submission.
	 */
	public static function accepted( Submission $submission, ?int $delivery_id = null ): self {
		return new self( 'accepted', $submission, $delivery_id );
	}

	/**
	 * An earlier submission with the same key and body was returned.
	 *
	 * @param Submission $submission Original submission.
	 */
	public static function replayed( Submission $submission ): self {
		return new self( 'replayed', $submission );
	}

	/**
	 * The key was already used for a different body.
	 */
	public static function conflict(): self {
		return new self( 'conflict', null );
	}

	/**
	 * A lock conflict aborted the attempt. The caller retries the transaction.
	 */
	public static function contended(): self {
		return new self( 'contended', null );
	}

	/**
	 * The submission could not be stored.
	 */
	public static function failed(): self {
		return new self( 'failed', null );
	}

	/**
	 * Whether this is a new submission.
	 */
	public function is_accepted(): bool {
		return 'accepted' === $this->state;
	}

	/**
	 * Whether the original response should be repeated.
	 */
	public function is_replayed(): bool {
		return 'replayed' === $this->state;
	}

	/**
	 * Whether the key does not match the body.
	 */
	public function is_conflict(): bool {
		return 'conflict' === $this->state;
	}

	/**
	 * Whether the attempt should be repeated after a lock conflict.
	 */
	public function is_contended(): bool {
		return 'contended' === $this->state;
	}

	/**
	 * Whether nothing was stored.
	 */
	public function is_failed(): bool {
		return 'failed' === $this->state;
	}

	/**
	 * Stored or replayed submission.
	 */
	public function submission(): ?Submission {
		return $this->submission;
	}

	/**
	 * Delivery created with an accepted submission, or null.
	 */
	public function delivery_id(): ?int {
		return $this->delivery_id;
	}
}
