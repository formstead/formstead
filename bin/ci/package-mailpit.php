<?php
/**
 * Sends package-job mail to Mailpit on the runner.
 *
 * CI copies this file into mu-plugins. It is not part of the plugin ZIP.
 *
 * @package Formstead
 */

defined( 'ABSPATH' ) || exit;

/**
 * From address for the package job.
 *
 * WordPress would use wordpress@localhost, and PHPMailer rejects that before
 * phpmailer_init runs. This is the job's mail setup, not a form value.
 *
 * @param string $from_email Address WordPress would use.
 */
function formstead_package_mailpit_from_email( $from_email ): string {
	unset( $from_email );

	return 'wordpress@example.com';
}

/**
 * Point PHPMailer at Mailpit on the runner.
 *
 * @param PHPMailer\PHPMailer\PHPMailer $phpmailer Mailer instance.
 */
function formstead_package_mailpit_phpmailer( $phpmailer ): void {
	if ( ! $phpmailer instanceof PHPMailer\PHPMailer\PHPMailer ) {
		return;
	}

	$phpmailer->isSMTP();
	// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer public properties.
	$phpmailer->Host        = '127.0.0.1';
	$phpmailer->Port        = 1025;
	$phpmailer->SMTPAuth    = false;
	$phpmailer->SMTPSecure  = '';
	$phpmailer->SMTPAutoTLS = false;
	// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
}

add_filter( 'wp_mail_from', 'formstead_package_mailpit_from_email' );
add_action( 'phpmailer_init', 'formstead_package_mailpit_phpmailer' );
