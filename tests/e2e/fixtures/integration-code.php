<?php
/**
 * Create or inspect the synthetic integration-code fixture on the tests site.
 *
 * @package Formstead
 */

use Formstead\Database\Schema;
use Formstead\Forms\Form;
use Formstead\Forms\FormInput;
use Formstead\Forms\FormRepository;

if ( ! defined( 'FORMSTEAD_E2E' ) || ! FORMSTEAD_E2E || 'local' !== wp_get_environment_type() ) {
	WP_CLI::error( 'Only run this fixture on the local E2E site.' );
}

/**
 * Create a synthetic form or inspect its latest payload.
 *
 * @param string[] $args Optional fixture form id.
 */
function formstead_e2e_integration_code( array $args ): void {
	if ( isset( $args[0] ) && ! in_array( $args[0], array( 'notify', 'react' ), true ) ) {
		$form = ( new FormRepository() )->find_by_id( (int) $args[0] );
		if ( ! $form instanceof Form || 'Integration contract — all fields' !== $form->name() ) {
			WP_CLI::error( 'Not an integration-code test form.' );
		}
		global $wpdb;
		$table = Schema::submissions_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table, synthetic test form only.
		$payload = $wpdb->get_var( $wpdb->prepare( "SELECT payload FROM {$table} WHERE form_id = %d ORDER BY id DESC LIMIT 1", $form->id() ) );
		WP_CLI::line( 'FORMSTEAD_E2E_JSON ' . wp_json_encode( null === $payload ? null : json_decode( $payload, true ) ) );
		return;
	}

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local synthetic test fixture.
	$fixture = json_decode( (string) file_get_contents( dirname( __DIR__, 2 ) . '/fixtures/integration-code/all-fields.json' ), true, 512, JSON_THROW_ON_ERROR );
	if ( isset( $args[0] ) && 'notify' === $args[0] ) {
		$fixture['form_input']['notify_recipients'] = 'integration@example.test';
	}
	if ( isset( $args[0] ) && 'react' === $args[0] ) {
		// One compiled component is reused across the local browser scenarios.
		$fixture['form_input']['rate_per_minute']   = '60';
		$fixture['form_input']['rate_per_hour']     = '1000';
		$fixture['form_input']['allowed_origins']  .= "\nhttp://localhost:4501";
		$fixture['form_input']['notify_recipients'] = 'integration@example.test';
	}
	$input = FormInput::validate( $fixture['form_input'] )->form();
	if ( ! $input instanceof Form ) {
		WP_CLI::error( 'Invalid integration-code fixture.' );
	}
	$form = ( new FormRepository() )->insert( $input );
	if ( ! $form instanceof Form ) {
		WP_CLI::error( 'Could not save the integration-code fixture.' );
	}
	WP_CLI::line(
		'FORMSTEAD_E2E_JSON ' . wp_json_encode(
			array(
				'id'        => $form->id(),
				'public_id' => $form->public_id(),
			)
		)
	);
}

// $args is supplied by wp eval-file.
formstead_e2e_integration_code( isset( $args ) && is_array( $args ) ? $args : array() );
