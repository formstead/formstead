<?php
/**
 * One immutable form schema snapshot.
 *
 * @package Formstead
 */

namespace Formstead\Forms;

/**
 * Field schema captured when a form's fields change.
 */
final class FormVersion {

	/**
	 * Database id.
	 *
	 * @var int
	 */
	private int $id;

	/**
	 * Form that owns this snapshot.
	 *
	 * @var int
	 */
	private int $form_id;

	/**
	 * Schema version number.
	 *
	 * @var int
	 */
	private int $version;

	/**
	 * Fields as they were in this version.
	 *
	 * @var Field[]
	 */
	private array $fields;

	/**
	 * Creation time in UTC `Y-m-d H:i:s`.
	 *
	 * @var string
	 */
	private string $created_at;

	/**
	 * Create a snapshot.
	 *
	 * @param int     $id         Database id.
	 * @param int     $form_id    Form id.
	 * @param int     $version    Schema version.
	 * @param Field[] $fields     Fields captured for this version.
	 * @param string  $created_at UTC creation time.
	 */
	public function __construct( int $id, int $form_id, int $version, array $fields, string $created_at ) {
		$this->id         = $id;
		$this->form_id    = $form_id;
		$this->version    = $version;
		$this->fields     = $fields;
		$this->created_at = $created_at;
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
	 * Schema version.
	 */
	public function version(): int {
		return $this->version;
	}

	/**
	 * Fields captured for this version.
	 *
	 * @return Field[]
	 */
	public function fields(): array {
		return $this->fields;
	}

	/**
	 * The field with this name, if this version has it.
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

	/**
	 * UTC creation time.
	 */
	public function created_at(): string {
		return $this->created_at;
	}
}
