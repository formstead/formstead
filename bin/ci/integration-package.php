<?php
/**
 * Seed, verify or export source on an isolated integration-package site.
 *
 * @package Formstead
 */

use Formstead\Database\Schema;
use Formstead\Delivery\DeliveryRepository;
use Formstead\Forms\Form;
use Formstead\Forms\FormInput;
use Formstead\Forms\FormRepository;
use Formstead\Integration\CodeGenerator;
use Formstead\Submissions\SubmissionRepository;

/**
 * Fingerprints of every plugin row and its configuration options.
 *
 * @return array<string, string>
 */
function formstead_package_snapshot(): array {
	global $wpdb;
	$snapshot = array();
	// phpcs:disable WordPress.DB.DirectDatabaseQuery -- complete snapshots on an explicitly isolated package site.
	foreach ( Schema::tables() as $table ) {
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i', $table ), ARRAY_A );
		if ( '' !== $wpdb->last_error ) {
			WP_CLI::error( 'Cannot read a package acceptance table.' );
		}
		$rows = array_map( 'wp_json_encode', $rows );
		sort( $rows );
		$snapshot[ $table ] = hash( 'sha256', (string) wp_json_encode( $rows ) );
	}
	$options = $wpdb->get_results(
		$wpdb->prepare( 'SELECT option_name, option_value FROM %i WHERE option_name LIKE %s ORDER BY option_name', $wpdb->options, $wpdb->esc_like( 'formstead_' ) . '%' ),
		ARRAY_A
	);
	// phpcs:enable WordPress.DB.DirectDatabaseQuery
	$snapshot['options'] = hash( 'sha256', (string) wp_json_encode( $options ) );
	return $snapshot;
}

/**
 * Use a source fixture outside the installed ZIP; runtime must need only the ZIP.
 *
 * @param string[] $args Mode and, for seed, local fixture path.
 */
function formstead_integration_package( array $args ): void {
	global $wpdb;
	if ( ! defined( 'FORMSTEAD_PACKAGE_ACCEPTANCE' ) || ! FORMSTEAD_PACKAGE_ACCEPTANCE || 'local' !== wp_get_environment_type() || ! str_starts_with( $wpdb->prefix, 'fse_' ) ) {
		WP_CLI::error( 'Only run on an explicitly isolated local package site.' );
	}
	$mode = $args[0] ?? '';
	if ( 'seed' === $mode ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- explicit local synthetic fixture in CLI acceptance.
		$fixture = json_decode( (string) file_get_contents( $args[1] ?? '' ), true, 512, JSON_THROW_ON_ERROR );
		$form    = FormInput::validate( $fixture['form_input'] )->form();
		if ( ! $form instanceof Form ) {
			WP_CLI::error( 'Invalid package fixture.' );
		}
		$form = ( new FormRepository() )->insert( $form );
		if ( ! $form instanceof Form ) {
			WP_CLI::error( 'Could not save the package fixture.' );
		}
		$submission = ( new SubmissionRepository() )->insert( $form, $fixture['normalized'] );
		if ( is_wp_error( $submission ) || is_wp_error( ( new DeliveryRepository() )->create( $submission->id() ) ) ) {
			WP_CLI::error( 'Could not seed a submission and pending delivery.' );
		}
		update_option( 'fse_package_snapshot', formstead_package_snapshot(), false );
		WP_CLI::success( 'Seeded all field types, a submission and a pending delivery.' );
		return;
	}
	if ( 'verify' === $mode ) {
		if ( 8 !== (int) get_option( 'formstead_db_version' ) || get_option( 'fse_package_snapshot' ) !== formstead_package_snapshot() ) {
			WP_CLI::error( 'Package replacement changed stored rows or configuration.' );
		}
		WP_CLI::success( 'Every plugin row and configuration option is unchanged.' );
		return;
	}
	if ( 'code' === $mode ) {
		$form = ( new FormRepository() )->all()[0] ?? null;
		if ( ! $form instanceof Form ) {
			WP_CLI::error( 'Seed the package site first.' );
		}
		$generator = new CodeGenerator();
		$endpoint  = rest_url( 'formstead/v1/forms/' . $form->public_id() . '/submissions' );
		WP_CLI::line(
			'FORMSTEAD_PACKAGE_JSON ' . wp_json_encode(
				array(
					'id'         => $form->id(),
					'public_id'  => $form->public_id(),
					'html'       => $generator->generate( $form, $endpoint ),
					'javascript' => $generator->generate( $form, $endpoint, 'javascript' ),
				)
			)
		);
		return;
	}
	WP_CLI::error( 'Expected seed, verify or code.' );
}

formstead_integration_package( isset( $args ) && is_array( $args ) ? $args : array() );
