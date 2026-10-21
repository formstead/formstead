<?php
/**
 * Print submission state for a browser test.
 *
 * Usage: wp eval-file tests/e2e/fixtures/state.php form <public_id>
 *        wp eval-file tests/e2e/fixtures/state.php receipt <receipt_id>
 *
 * The JSON line has no payload and no submitted values.
 *
 * @package Formstead
 */

use Formstead\Database\Schema;
use Formstead\Forms\Form;
use Formstead\Forms\FormRepository;
use Formstead\Forms\PublicId;

if ( ! defined( 'ABSPATH' ) ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI only, before WordPress loads.
	fwrite( STDERR, "Run this file with wp eval-file.\n" );
	exit( 1 );
}

if ( ! defined( 'FORMSTEAD_E2E' ) || ! FORMSTEAD_E2E ) {
	formstead_e2e_state_fail( 'FORMSTEAD_E2E is not enabled.' );
}

/**
 * Stop and tell WP-CLI why.
 *
 * @param string $message Failure, without submission contents.
 */
function formstead_e2e_state_fail( string $message ): void {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI only.
	fwrite( STDERR, $message . "\n" );
	exit( 1 );
}

/**
 * Deliveries for one submission, without a copy of the payload.
 *
 * @param int $submission_id Submission id.
 * @return list<array{status: string, attempts: int, last_error: string}>
 */
function formstead_e2e_deliveries( int $submission_id ): array {
	global $wpdb;

	$table = Schema::deliveries_table();
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is an identifier.
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT status, attempts, last_error FROM {$table} WHERE submission_id = %d ORDER BY id ASC", $submission_id ), ARRAY_A );
	if ( ! is_array( $rows ) ) {
		return array();
	}

	$deliveries = array();
	foreach ( $rows as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}

		$deliveries[] = array(
			'status'     => (string) ( $row['status'] ?? '' ),
			'attempts'   => (int) ( $row['attempts'] ?? 0 ),
			'last_error' => (string) ( $row['last_error'] ?? '' ),
		);
	}

	return $deliveries;
}

/**
 * Newest submission on a form, or null.
 *
 * @param int $form_id Form id.
 * @return array{receipt_id: string, status: string, deliveries: list<array{status: string, attempts: int, last_error: string}>}|null
 */
function formstead_e2e_latest( int $form_id ): ?array {
	global $wpdb;

	$table = Schema::submissions_table();
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is an identifier.
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, receipt_id, status FROM {$table} WHERE form_id = %d ORDER BY created_at DESC, id DESC LIMIT 1", $form_id ), ARRAY_A );
	if ( ! is_array( $row ) ) {
		return null;
	}

	return array(
		'receipt_id' => (string) ( $row['receipt_id'] ?? '' ),
		'status'     => (string) ( $row['status'] ?? '' ),
		'deliveries' => formstead_e2e_deliveries( (int) ( $row['id'] ?? 0 ) ),
	);
}

/**
 * How many submissions a form has.
 *
 * @param int $form_id Form id.
 */
function formstead_e2e_submission_count( int $form_id ): int {
	global $wpdb;

	$table = Schema::submissions_table();
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is an identifier.
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE form_id = %d", $form_id ) );
}

/**
 * Print one JSON line for the setup parser.
 *
 * @param array<string, mixed> $payload State. Must not include a payload column.
 */
function formstead_e2e_emit( array $payload ): void {
	$encoded = wp_json_encode( $payload );
	if ( ! is_string( $encoded ) ) {
		formstead_e2e_state_fail( 'Could not encode the state.' );
	}

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI only.
	fwrite( STDOUT, 'FORMSTEAD_E2E_JSON ' . $encoded . "\n" );
}

/**
 * Print state for the mode WP-CLI passed in.
 *
 * @param string[] $args `form <public_id>` or `receipt <receipt_id>`.
 */
function formstead_e2e_state( array $args ): void {
	$mode  = isset( $args[0] ) ? (string) $args[0] : '';
	$value = isset( $args[1] ) ? (string) $args[1] : '';

	if ( 'form' === $mode ) {
		if ( ! PublicId::is_valid( $value ) ) {
			formstead_e2e_state_fail( 'The public id is not valid.' );
		}

		$form = ( new FormRepository() )->find_by_public_id( $value );
		if ( ! $form instanceof Form ) {
			formstead_e2e_emit( array( 'found' => false ) );
			return;
		}

		formstead_e2e_emit(
			array(
				'found'               => true,
				'public_id'           => $form->public_id(),
				'honeypot_rejections' => $form->honeypot_rejections(),
				'submissions'         => formstead_e2e_submission_count( $form->id() ),
				'latest'              => formstead_e2e_latest( $form->id() ),
			)
		);
		return;
	}

	if ( 'receipt' === $mode ) {
		if ( 1 !== preg_match( '/^fsr_[a-z2-7]{16}$/', $value ) ) {
			formstead_e2e_state_fail( 'The receipt id is not valid.' );
		}

		global $wpdb;
		$table = Schema::submissions_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is an identifier.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, receipt_id, status FROM {$table} WHERE receipt_id = %s", $value ), ARRAY_A );
		if ( ! is_array( $row ) ) {
			formstead_e2e_emit( array( 'found' => false ) );
			return;
		}

		formstead_e2e_emit(
			array(
				'found'      => true,
				'receipt_id' => (string) ( $row['receipt_id'] ?? '' ),
				'status'     => (string) ( $row['status'] ?? '' ),
				'deliveries' => formstead_e2e_deliveries( (int) ( $row['id'] ?? 0 ) ),
			)
		);
		return;
	}

	formstead_e2e_state_fail( 'Usage: state.php form <public_id> | receipt <receipt_id>.' );
}

// $args is set by `wp eval-file` in this file's scope.
formstead_e2e_state( isset( $args ) && is_array( $args ) ? $args : array() );
