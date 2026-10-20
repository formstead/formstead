<?php
/**
 * One page of submissions.
 *
 * @package Formstead
 */

namespace Formstead\Submissions;

/**
 * Submissions for a list page, plus the unpaged total.
 */
final class SubmissionList {

	/**
	 * Submissions on this page, newest first.
	 *
	 * @var Submission[]
	 */
	private array $submissions;

	/**
	 * Matching submissions across every page.
	 *
	 * @var int
	 */
	private int $total;

	/**
	 * Create a page of results.
	 *
	 * @param Submission[] $submissions Rows on this page.
	 * @param int          $total       Unpaged total.
	 */
	public function __construct( array $submissions, int $total ) {
		$this->submissions = $submissions;
		$this->total       = $total;
	}

	/**
	 * Rows on this page.
	 *
	 * @return Submission[]
	 */
	public function submissions(): array {
		return $this->submissions;
	}

	/**
	 * Unpaged total.
	 */
	public function total(): int {
		return $this->total;
	}
}
