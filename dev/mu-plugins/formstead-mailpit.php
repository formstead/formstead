<?php
/**
 * Sends development mail to Mailpit.
 *
 * This file is mounted into wp-env and is not part of the plugin ZIP.
 * It does nothing unless FORMSTEAD_MAILPIT_HOST is defined.
 *
 * @package Formstead
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'FORMSTEAD_MAILPIT_HOST' ) || ! is_string( FORMSTEAD_MAILPIT_HOST ) || '' === FORMSTEAD_MAILPIT_HOST ) {
	return;
}

/**
 * From address for local SMTP.
 *
 * WordPress would use wordpress@localhost, and PHPMailer rejects that before
 * phpmailer_init runs. This is the local mail setup, not a form value.
 *
 * @param string $from_email Address WordPress would use.
 */
function formstead_mailpit_from_email( $from_email ): string {
	unset( $from_email );

	return 'wordpress@example.com';
}

/**
 * Point PHPMailer at the Mailpit container.
 *
 * @param PHPMailer\PHPMailer\PHPMailer $phpmailer Mailer instance.
 */
function formstead_mailpit_phpmailer( $phpmailer ): void {
	if ( ! $phpmailer instanceof PHPMailer\PHPMailer\PHPMailer ) {
		return;
	}

	$phpmailer->isSMTP();
	// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer public properties.
	$phpmailer->Host        = FORMSTEAD_MAILPIT_HOST;
	$phpmailer->Port        = 1025;
	$phpmailer->SMTPAuth    = false;
	$phpmailer->SMTPSecure  = '';
	$phpmailer->SMTPAutoTLS = false;
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
}

add_filter( 'wp_mail_from', 'formstead_mailpit_from_email' );
add_action( 'phpmailer_init', 'formstead_mailpit_phpmailer' );
