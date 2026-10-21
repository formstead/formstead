<?php
/**
 * Filters for an inbox query.
 *
 * @package Formstead
 */

namespace Formstead\Submissions;

use Formstead\Privacy\IdentityHasher;

/**
 * Form, view, and date range used by the inbox and the CSV export.
 *
 * An empty status list means every status. Dates are UTC calendar days.
 * An email search is stored as an HMAC, plus the address the screen shows.
 */
final class SubmissionQuery {

	public const VIEW_INBOX = 'inbox';

	public const VIEW_UNREAD = 'unread';

	public const VIEW_ARCHIVED = 'archived';

	public const VIEW_SPAM = 'spam';

	public const VIEW_ALL = 'all';

	/**
	 * Form id, or null for every form.
	 *
	 * @var int|null
	 */
	private ?int $form_id;

	/**
	 * Inbox view slug.
	 *
	 * @var string
	 */
	private string $view;

	/**
	 * Statuses included by the view. Empty means every status.
	 *
	 * @var string[]
	 */
	private array $statuses;

	/**
	 * First UTC day, `Y-m-d`, or null.
	 *
	 * @var string|null
	 */
	private ?string $date_from;

	/**
	 * Last UTC day, `Y-m-d`, or null.
	 *
	 * @var string|null
	 */
	private ?string $date_to;

	/**
	 * HMAC of the searched address, or null when the inbox is not searching.
	 *
	 * @var string|null
	 */
	private ?string $identity_hash;

	/**
	 * Address typed into the search box, or an empty string.
	 *
	 * @var string
	 */
	private string $search;

	/**
	 * Create a query.
	 *
	 * @param int|null    $form_id       Form id, or null for every form.
	 * @param string      $view          Inbox view slug.
	 * @param string[]    $statuses      Statuses to include. Empty means every status.
	 * @param string|null $date_from     First UTC day.
	 * @param string|null $date_to       Last UTC day.
	 * @param string|null $identity_hash HMAC of the searched address.
	 * @param string      $search        Address shown in the search box.
	 */
	private function __construct( ?int $form_id, string $view, array $statuses, ?string $date_from, ?string $date_to, ?string $identity_hash, string $search ) {
		$this->form_id       = $form_id;
		$this->view          = $view;
		$this->statuses      = $statuses;
		$this->date_from     = $date_from;
		$this->date_to       = $date_to;
		$this->identity_hash = $identity_hash;
		$this->search        = $search;
	}

	/**
	 * Build a query from already read screen values.
	 *
	 * Unknown views become the inbox. A date that is not a real `Y-m-d` is dropped.
	 *
	 * @param int    $form_id   Form id, or zero for every form.
	 * @param string $view      Requested view.
	 * @param string $date_from Requested first day.
	 * @param string $date_to   Requested last day.
	 * @param string $email     Address to match exactly, or an empty string.
	 */
	public static function from_values( int $form_id, string $view, string $date_from, string $date_to, string $email = '' ): self {
		$view  = self::normalize_view( $view );
		$email = trim( $email );
		$hash  = '' === $email ? null : IdentityHasher::hash( $email );
		if ( ! is_string( $hash ) ) {
			$email = '';
			$hash  = null;
		}

		return new self(
			$form_id > 0 ? $form_id : null,
			$view,
			self::statuses_for_view( $view ),
			self::date( $date_from ),
			self::date( $date_to ),
			$hash,
			$email
		);
	}

	/**
	 * Known view slug, or the inbox.
	 *
	 * @param string $view Requested view.
	 */
	public static function normalize_view( string $view ): string {
		if ( in_array( $view, array( self::VIEW_INBOX, self::VIEW_UNREAD, self::VIEW_ARCHIVED, self::VIEW_SPAM, self::VIEW_ALL ), true ) ) {
			return $view;
		}

		return self::VIEW_INBOX;
	}

	/**
	 * A real UTC day, or null.
	 *
	 * @param string $value Candidate `Y-m-d`.
	 */
	public static function date( string $value ): ?string {
		if ( 1 !== preg_match( '/\A(\d{4})-(\d{2})-(\d{2})\z/', $value, $matches ) ) {
			return null;
		}

		$year  = (int) $matches[1];
		$month = (int) $matches[2];
		$day   = (int) $matches[3];
		if ( ! checkdate( $month, $day, $year ) ) {
			return null;
		}

		return sprintf( '%04d-%02d-%02d', $year, $month, $day );
	}

	/**
	 * Statuses included in a view.
	 *
	 * @param string $view Known view slug.
	 * @return list<string>
	 */
	public static function statuses_for_view( string $view ): array {
		return match ( $view ) {
			self::VIEW_UNREAD => array( SubmissionStatus::NEW ),
			self::VIEW_ARCHIVED => array( SubmissionStatus::ARCHIVED ),
			self::VIEW_SPAM => array( SubmissionStatus::SPAM ),
			self::VIEW_ALL => array(),
			default => array( SubmissionStatus::NEW, SubmissionStatus::READ ),
		};
	}

	/**
	 * Form id, or null for every form.
	 */
	public function form_id(): ?int {
		return $this->form_id;
	}

	/**
	 * Inbox view slug.
	 */
	public function view(): string {
		return $this->view;
	}

	/**
	 * Statuses to include. Empty means every status.
	 *
	 * @return string[]
	 */
	public function statuses(): array {
		return $this->statuses;
	}

	/**
	 * First UTC day, or null.
	 */
	public function date_from(): ?string {
		return $this->date_from;
	}

	/**
	 * Last UTC day, or null.
	 */
	public function date_to(): ?string {
		return $this->date_to;
	}

	/**
	 * HMAC of the searched address, or null when the inbox is not searching.
	 */
	public function identity_hash(): ?string {
		return $this->identity_hash;
	}

	/**
	 * Address typed into the search box, or an empty string.
	 */
	public function search(): string {
		return $this->search;
	}
}
