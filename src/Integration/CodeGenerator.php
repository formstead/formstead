<?php
/**
 * Integration code for a persisted form.
 *
 * @package Formstead
 */

namespace Formstead\Integration;

use Formstead\Forms\Form;
use Formstead\Forms\PublicId;
use InvalidArgumentException;

/**
 * Renders standalone source without reading requests or changing the form.
 */
final class CodeGenerator {

	/**
	 * Generate a supported integration variant.
	 *
	 * @param Form   $form     Persisted form.
	 * @param string $endpoint Public intake URL resolved by WordPress.
	 * @param string $variant Integration variant.
	 * @throws InvalidArgumentException When the form is unsaved or the variant is unsupported.
	 */
	public function generate( Form $form, string $endpoint, string $variant = 'html' ): string {
		if ( ! in_array( $variant, array( 'html', 'javascript', 'astro', 'react' ), true ) || $form->id() < 1 || ! PublicId::is_valid( $form->public_id() ) ) {
			throw new InvalidArgumentException( 'A saved form and a supported integration variant are required.' );
		}

		$prefix   = 'formstead-' . $form->public_id();
		$enhanced = 'javascript' === $variant;
		ob_start();
		include dirname( FORMSTEAD_FILE ) . '/templates/integration/' . $variant . '.php';
		return trim( (string) ob_get_clean() ) . "\n";
	}
}
