<?php
/**
 * Lets end-to-end tests call a webhook on this machine.
 *
 * Mounted into the wp-env tests site. It does nothing unless FORMSTEAD_E2E
 * is set, so a normal install never treats localhost as a public address.
 *
 * @package Formstead
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'FORMSTEAD_E2E' ) || ! FORMSTEAD_E2E || ! in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) ) {
	return;
}

/**
 * Allow the Next.js example to receive a webhook during tests.
 *
 * @param mixed $hosts Hosts already allowed.
 * @return list<mixed>
 */
function formstead_e2e_allow_webhook_hosts( $hosts ) {
	$list   = is_array( $hosts ) ? $hosts : array();
	$list[] = 'localhost:4500';
	$list[] = '127.0.0.1:4500';
	$list[] = 'wordpress:80';

	return $list;
}

add_filter( 'formstead_webhook_allowed_hosts', 'formstead_e2e_allow_webhook_hosts' );

/**
 * The isolated Docker tests service is the only additional HTTP destination.
 *
 * @param bool   $external Existing decision.
 * @param string $host     Requested host.
 */
function formstead_e2e_allow_receiver( bool $external, string $host ): bool {
	return 'wordpress' === $host || $external;
}
add_filter( 'http_request_host_is_external', 'formstead_e2e_allow_receiver', 10, 2 );

/**
 * Receive an actual signed webhook without contacting a third-party service.
 */
function formstead_e2e_register_receiver(): void {
	register_rest_route(
		'formstead-e2e/v1',
		'/webhook',
		array(
			'methods'             => 'POST',
			'permission_callback' => '__return_true',
			'callback'            => 'formstead_e2e_receive_webhook',
		)
	);
}
add_action( 'rest_api_init', 'formstead_e2e_register_receiver' );

/**
 * Check the public test vector key independently of the production signing helper.
 *
 * @param WP_REST_Request $request Received request.
 */
function formstead_e2e_receive_webhook( WP_REST_Request $request ): WP_REST_Response {
	$id        = (string) $request->get_header( 'webhook-id' );
	$timestamp = (string) $request->get_header( 'webhook-timestamp' );
	$signature = (string) $request->get_header( 'webhook-signature' );
	// The shared fixture key is 32 zero bytes, never a production secret.
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Standard Webhooks signature.
	$expected = 'v1,' . base64_encode( hash_hmac( 'sha256', $id . '.' . $timestamp . '.' . $request->get_body(), str_repeat( "\0", 32 ), true ) );
	if ( abs( time() - (int) $timestamp ) > 300 || ! hash_equals( $expected, $signature ) ) {
		return new WP_REST_Response( array( 'error' => 'invalid_signature' ), 401 );
	}
	$body = json_decode( $request->get_body(), true );
	update_option(
		'formstead_e2e_webhook_received',
		array(
			'receipt_id' => is_array( $body ) ? (string) ( $body['receipt_id'] ?? '' ) : '',
			'event_id'   => $id,
			'has_fields' => is_array( $body ) && array_key_exists( 'fields', $body ),
		),
		false
	);
	return new WP_REST_Response( null, 204 );
}
