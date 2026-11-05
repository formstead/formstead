<?php
/**
 * Shared translated messages for generated fetch clients.
 *
 * @package Formstead
 */

defined( 'ABSPATH' ) || exit;

$messages = array(
	'already'        => __( 'These details were already accepted. They have not been sent again.', 'formstead' ),
	'accepted'       => __( 'Thanks. Your submission was accepted.', 'formstead' ),
	'check'          => __( 'Check the form and try again.', 'formstead' ),
	'unconfirmed'    => __( 'We could not confirm whether your submission was accepted. Try again with the same details.', 'formstead' ),
	'failed'         => __( 'The form could not be sent. Check your details and try again.', 'formstead' ),
	'unavailable'    => __( 'This form is unavailable. Please contact the site owner.', 'formstead' ),
	'conflict'       => __( 'This submission may already have been sent with different details. Check before sending again.', 'formstead' ),
	'rate'           => __( 'Too many submissions. Try again later.', 'formstead' ),
	/* translators: %s: seconds until another attempt is allowed. */
	'retry'          => __( 'Too many submissions. Try again in %s seconds.', 'formstead' ),
	'required'       => __( 'This field is required.', 'formstead' ),
	'too_long'       => __( 'This value is too long.', 'formstead' ),
	'invalid_email'  => __( 'Enter a valid email address.', 'formstead' ),
	'invalid_number' => __( 'Enter a valid number.', 'formstead' ),
	'too_small'      => __( 'This value is too small.', 'formstead' ),
	'too_large'      => __( 'This value is too large.', 'formstead' ),
	'too_precise'    => __( 'This value has too many decimal places.', 'formstead' ),
	'invalid_choice' => __( 'Choose a valid option.', 'formstead' ),
	'invalid'        => __( 'This value is not valid.', 'formstead' ),
);
