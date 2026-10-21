<?php
/**
 * Column names for a form's CSV export.
 *
 * @package Formstead
 */

namespace Formstead\Submissions;

use Formstead\Forms\Form;
use Formstead\Forms\FormVersionRepository;

/**
 * Field names from the current schema, then names that only older snapshots have.
 *
 * Removed fields are appended from the newest snapshot that still contains them.
 * Headers use the field name, which stays stable when a label changes.
 */
final class CsvColumns {

	/**
	 * Fixed columns that come before the form fields.
	 *
	 * @return list<string>
	 */
	public static function leading(): array {
		return array( 'receipt_id', 'created_at', 'status' );
	}

	/**
	 * Field names for one form.
	 *
	 * @param Form $form Form being exported.
	 * @return list<string>
	 */
	public static function fields( Form $form ): array {
		$names = array();
		$seen  = array();
		foreach ( $form->fields() as $field ) {
			$names[]                = $field->name();
			$seen[ $field->name() ] = true;
		}

		foreach ( ( new FormVersionRepository() )->for_form( $form->id() ) as $version ) {
			foreach ( $version->fields() as $field ) {
				if ( isset( $seen[ $field->name() ] ) ) {
					continue;
				}
				$names[]                = $field->name();
				$seen[ $field->name() ] = true;
			}
		}

		return $names;
	}
}
