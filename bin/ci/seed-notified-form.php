<?php
/**
 * Seed one form that notifies a fixed recipient.
 *
 * Prints the public id. Used by the Mailpit check, outside the plugin ZIP.
 *
 * @package Formstead
 */

use Formstead\Forms\Field;
use Formstead\Forms\FieldType;
use Formstead\Forms\Form;
use Formstead\Forms\FormNotifications;
use Formstead\Forms\FormRepository;

if ( ! defined( 'ABSPATH' ) ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI only, before WordPress loads.
	fwrite( STDERR, "Run this file with wp eval-file.\n" );
	exit( 1 );
}

/**
 * Create one enabled form with a recipient and print its public id.
 */
function formstead_ci_seed_notified_form() {
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
		null,
		0,
		new FormNotifications( array( 'owner@example.com' ), 'email', false )
	);

	$saved = ( new FormRepository() )->insert( $form );
	if ( ! $saved instanceof Form ) {
		if ( class_exists( 'WP_CLI' ) ) {
			WP_CLI::error( 'Could not seed a notified form.' );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI only.
		fwrite( STDERR, "Could not seed a notified form.\n" );
		exit( 1 );
	}

	if ( class_exists( 'WP_CLI' ) ) {
		WP_CLI::line( $saved->public_id() );
		return;
	}

	echo esc_html( $saved->public_id() );
}

formstead_ci_seed_notified_form();
