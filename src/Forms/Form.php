<?php
/**
 * A configured form.
 *
 * @package Formstead
 */

namespace Formstead\Forms;

/**
 * Immutable form record.
 */
final class Form {

	/**
	 * Database id. Zero before the form is saved.
	 *
	 * @var int
	 */
	private int $id;

	/**
	 * Public identifier. Empty before the form is saved.
	 *
	 * @var string
	 */
	private string $public_id;

	/**
	 * Form name shown in the admin.
	 *
	 * @var string
	 */
	private string $name;

	/**
	 * Whether the public endpoint accepts submissions.
	 *
	 * @var bool
	 */
	private bool $is_enabled;

	/**
	 * Configured fields.
	 *
	 * @var Field[]
	 */
	private array $fields;

	/**
	 * Schema version used by submissions stored after the last field change.
	 *
	 * @var int
	 */
	private int $schema_version;

	/**
	 * Absolute URL for the success redirect.
	 *
	 * @var string
	 */
	private string $success_url;

	/**
	 * Creation time in UTC `Y-m-d H:i:s`. Empty before the form is saved.
	 *
	 * @var string
	 */
	private string $created_at;

	/**
	 * Update time in UTC `Y-m-d H:i:s`. Empty before the form is saved.
	 *
	 * @var string
	 */
	private string $updated_at;

	/**
	 * Create a form.
	 *
	 * @param int     $id             Database id, or zero for a new form.
	 * @param string  $public_id      Public id, or an empty string for a new form.
	 * @param string  $name           Form name.
	 * @param bool    $is_enabled     Whether submissions are accepted.
	 * @param Field[] $fields         Configured fields.
	 * @param int     $schema_version Current field-schema version.
	 * @param string  $success_url    Absolute success URL.
	 * @param string  $created_at     UTC creation time.
	 * @param string  $updated_at     UTC update time.
	 */
	public function __construct(
		int $id,
		string $public_id,
		string $name,
		bool $is_enabled,
		array $fields,
		int $schema_version,
		string $success_url,
		string $created_at,
		string $updated_at
	) {
		$this->id             = $id;
		$this->public_id      = $public_id;
		$this->name           = $name;
		$this->is_enabled     = $is_enabled;
		$this->fields         = $fields;
		$this->schema_version = $schema_version;
		$this->success_url    = $success_url;
		$this->created_at     = $created_at;
		$this->updated_at     = $updated_at;
	}

	/**
	 * Database id. Zero before the form is saved.
	 */
	public function id(): int {
		return $this->id;
	}

	/**
	 * Public identifier. Empty before the form is saved.
	 */
	public function public_id(): string {
		return $this->public_id;
	}

	/**
	 * Form name.
	 */
	public function name(): string {
		return $this->name;
	}

	/**
	 * Whether the public endpoint accepts submissions.
	 */
	public function is_enabled(): bool {
		return $this->is_enabled;
	}

	/**
	 * Configured fields.
	 *
	 * @return Field[]
	 */
	public function fields(): array {
		return $this->fields;
	}

	/**
	 * Current field-schema version.
	 */
	public function schema_version(): int {
		return $this->schema_version;
	}

	/**
	 * Absolute success URL.
	 */
	public function success_url(): string {
		return $this->success_url;
	}

	/**
	 * UTC creation time.
	 */
	public function created_at(): string {
		return $this->created_at;
	}

	/**
	 * UTC update time.
	 */
	public function updated_at(): string {
		return $this->updated_at;
	}

	/**
	 * The field with this name, if the current schema still has it.
	 *
	 * @param string $name Field name.
	 */
	public function field( string $name ): ?Field {
		foreach ( $this->fields as $field ) {
			if ( $field->name() === $name ) {
				return $field;
			}
		}

		return null;
	}
}
