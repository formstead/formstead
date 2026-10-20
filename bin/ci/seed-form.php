<?php
/**
 * Seed one contact form for the package smoke test.
 *
 * Prints the public id. Run with `wp eval-file` from a checkout, outside the ZIP.
 *
 * @package Formstead
 */

use Formstead\Forms\Field;
use Formstead\Forms\FieldType;
use Formstead\Forms\Form;
use Formstead\Forms\FormNotifications;
use Formstead\Forms\FormProtection;
use Formstead\Forms\FormRepository;

if ( ! defined( 'ABSPATH' ) ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI only, before WordPress loads.
	fwrite( STDERR, "Run this file with wp eval-file.\n" );
	exit( 1 );
}

/**
 * Create one enabled form and print its public id.
 */
function formstead_ci_seed_form() {
	$form = new Form(
		0,
		'',
		'Contact',
		true,
		array(
			new Field( 'email', 'Email', FieldType::Email, true, 255 ),
		),
		1,
		'https://example.com/thanks',
		'',
		'',
		new FormProtection( array( 'https://forms.example' ), null, null ),
		0,
		new FormNotifications( array( 'owner@example.com' ), 'email', false )
	);

	$saved = ( new FormRepository() )->insert( $form );
	if ( ! $saved instanceof Form ) {
		if ( class_exists( 'WP_CLI' ) ) {
			WP_CLI::error( 'Could not seed a form.' );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI only.
		fwrite( STDERR, "Could not seed a form.\n" );
		exit( 1 );
	}

	if ( class_exists( 'WP_CLI' ) ) {
		WP_CLI::line( $saved->public_id() );
		return;
	}

	echo esc_html( $saved->public_id() );
}

formstead_ci_seed_form();
