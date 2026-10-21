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

if ( ! defined( 'FORMSTEAD_E2E' ) || ! FORMSTEAD_E2E ) {
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

	return $list;
}

add_filter( 'formstead_webhook_allowed_hosts', 'formstead_e2e_allow_webhook_hosts' );
