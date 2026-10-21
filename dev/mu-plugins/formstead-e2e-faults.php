<?php
/**
 * Fault injection for end-to-end tests.
 *
 * Mounted into the wp-env tests site only. It does nothing unless
 * FORMSTEAD_E2E_FAULTS is set and the formstead_e2e_fault option names a fault.
 *
 * @package Formstead
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'FORMSTEAD_E2E_FAULTS' ) || ! FORMSTEAD_E2E_FAULTS ) {
	return;
}

/**
 * Attach the fault selected for this request.
 *
 * The option is read once. A value other than storage or mail is ignored.
 */
function formstead_e2e_register_faults(): void {
	if ( ! class_exists( Formstead\Database\Schema::class ) ) {
		return;
	}

	$fault = get_option( 'formstead_e2e_fault', '' );
	if ( 'storage' === $fault ) {
		add_filter( 'query', 'formstead_e2e_break_submission_insert' );
	}

	if ( 'mail' === $fault ) {
		add_filter( 'pre_wp_mail', 'formstead_e2e_fail_mail' );
	}
}

/**
 * Point the submission INSERT at a missing table.
 *
 * Other queries, including deliveries and idempotency keys, are left alone.
 * The failed insert is what the public endpoint reports as storage_failed.
 *
 * @param mixed $query SQL about to run.
 * @return mixed
 */
function formstead_e2e_break_submission_insert( $query ) {
	if ( ! is_string( $query ) ) {
		return $query;
	}

	$table = Formstead\Database\Schema::submissions_table();
	if ( str_contains( $query, 'INSERT' ) && str_contains( $query, $table ) ) {
		return str_replace( $table, $table . '_missing', $query );
	}

	return $query;
}

/**
 * Make wp_mail() return false without sending.
 *
 * @param mixed $short_circuit Value passed by WordPress. Ignored.
 * @return bool
 */
function formstead_e2e_fail_mail( $short_circuit ) {
	unset( $short_circuit );

	return false;
}

add_action( 'init', 'formstead_e2e_register_faults' );
