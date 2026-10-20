<?php
/**
 * One stored submission.
 *
 * @package Formstead
 */

namespace Formstead\Submissions;

/**
 * Immutable submission record.
 */
final class Submission {

	/**
	 * Database id.
	 *
	 * @var int
	 */
	private int $id;

	/**
	 * Form that received the submission.
	 *
	 * @var int
	 */
	private int $form_id;

	/**
	 * Form schema version at the time of the submission.
	 *
	 * @var int
	 */
	private int $schema_version;

	/**
	 * Field values keyed by field name.
	 *
	 * @var array<string, string>
	 */
	private array $payload;

	/**
	 * Inbox status. Opening the submission does not change it.
	 *
	 * @var string
	 */
	private string $status;

	/**
	 * Creation time in UTC `Y-m-d H:i:s`.
	 *
	 * @var string
	 */
	private string $created_at;

	/**
	 * Create a submission.
	 *
	 * @param int                   $id             Database id.
	 * @param int                   $form_id        Form id.
	 * @param int                   $schema_version Schema version copied from the form.
	 * @param array<string, string> $payload       Stored field values.
	 * @param string                $status         Inbox status.
	 * @param string                $created_at     UTC creation time.
	 */
	public function __construct( int $id, int $form_id, int $schema_version, array $payload, string $status, string $created_at ) {
		$this->id             = $id;
		$this->form_id        = $form_id;
		$this->schema_version = $schema_version;
		$this->payload        = $payload;
		$this->status         = $status;
		$this->created_at     = $created_at;
	}

	/**
	 * Database id.
	 */
	public function id(): int {
		return $this->id;
	}

	/**
	 * Form id.
	 */
	public function form_id(): int {
		return $this->form_id;
	}

	/**
	 * Schema version copied from the form.
	 */
	public function schema_version(): int {
		return $this->schema_version;
	}

	/**
	 * Stored field values.
	 *
	 * @return array<string, string>
	 */
	public function payload(): array {
		return $this->payload;
	}

	/**
	 * Inbox status.
	 */
	public function status(): string {
		return $this->status;
	}

	/**
	 * UTC creation time.
	 */
	public function created_at(): string {
		return $this->created_at;
	}
}
