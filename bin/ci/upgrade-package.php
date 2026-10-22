<?php
/**
 * Preserve a populated v0.9 installation across the actual ZIP replacement.
 *
 * Run only on the disposable package site with wp eval-file and seed/verify.
 *
 * @package Formstead
 */

/**
 * Compare synthetic package-site data before and after replacing plugin files.
 *
 * @param string $mode Seed or verify.
 */
function formstead_ci_upgrade_package( string $mode ): void {
	global $wpdb;

	// phpcs:disable WordPress.DB.DirectDatabaseQuery -- isolated package acceptance needs complete table snapshots.
	if ( 'seed' === $mode ) {
		require __DIR__ . '/seed-form.php';
		$form       = ( new Formstead\Forms\FormRepository() )->all()[0];
		$submission = ( new Formstead\Submissions\SubmissionRepository() )->insert( $form, array( 'email' => 'upgrade@example.com' ) );
		if ( $submission instanceof WP_Error ) {
			WP_CLI::error( 'Could not seed the upgrade submission.' );
		}
		$delivery = ( new Formstead\Delivery\DeliveryRepository() )->create( $submission->id() );
		if ( $delivery instanceof WP_Error ) {
			WP_CLI::error( 'Could not seed the pending delivery.' );
		}
	}

	$tables   = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'formstead_' ) . '%' ) );
	$snapshot = array();
	foreach ( $tables as $table ) {
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i', $table ), ARRAY_A );
		if ( '' !== $wpdb->last_error ) {
			WP_CLI::error( 'Cannot read an upgrade table.' );
		}
		$rows = array_map( 'wp_json_encode', $rows );
		sort( $rows );
		$snapshot[ $table ] = hash( 'sha256', (string) wp_json_encode( $rows ) );
	}
	if ( 'seed' === $mode ) {
		update_option( 'formstead_package_upgrade_snapshot', $snapshot, false );
	} elseif ( 'verify' === $mode ) {
		if ( '1.0.0-rc.1' !== FORMSTEAD_VERSION || 8 !== (int) get_option( 'formstead_db_version' ) || get_option( 'formstead_package_upgrade_snapshot' ) !== $snapshot ) {
			WP_CLI::error( 'ZIP upgrade changed stored rows or did not load the candidate.' );
		}
		WP_CLI::success( 'The v0.9.0 ZIP upgrade preserves every populated table.' );
	} else {
		WP_CLI::error( 'Expected seed or verify.' );
	}
}

formstead_ci_upgrade_package( (string) ( $args[0] ?? '' ) );
