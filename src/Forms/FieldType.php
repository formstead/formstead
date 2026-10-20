<?php
/**
 * Field types stored on a form.
 *
 * @package Formstead
 */

namespace Formstead\Forms;

/**
 * Field types accepted by the public contract.
 */
enum FieldType: string {

	case Text     = 'text';
	case Email    = 'email';
	case Textarea = 'textarea';
	case Number   = 'number';
	case Boolean  = 'boolean';
	case Choice   = 'choice';

	/**
	 * Default maximum length for this type.
	 */
	public function default_max_length(): int {
		// Enum methods run in object context. This sniff does not treat enums as classes.
		// phpcs:ignore PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext
		return match ( $this ) {
			self::Textarea => 10000,
			self::Choice => 190,
			self::Boolean => 5,
			self::Text, self::Email, self::Number => 255,
		};
	}
}
