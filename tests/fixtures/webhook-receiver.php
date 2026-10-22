<?php
/**
 * Local receiver for real HTTP transport tests. Never included in the ZIP.
 *
 * @package Formstead
 */

$formstead_capture = getenv( 'FORMSTEAD_TRANSPORT_CAPTURE' );
if ( is_string( $formstead_capture ) && '' !== $formstead_capture ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- isolated test process.
	file_put_contents( $formstead_capture, file_get_contents( 'php://input' ) );
}

http_response_code( 204 );
