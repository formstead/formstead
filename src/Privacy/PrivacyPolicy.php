<?php
/**
 * Suggested privacy-policy text for the site.
 *
 * @package Formstead
 */

namespace Formstead\Privacy;

/**
 * Adds Formstead's suggested policy text on the Privacy Policy Guide screen.
 *
 * The text describes what the plugin stores. It is not a legal conclusion.
 */
final class PrivacyPolicy {

	/**
	 * Register the guide text.
	 */
	public static function register(): void {
		add_action( 'admin_init', array( self::class, 'add' ) );
	}

	/**
	 * Hand the suggested text to WordPress.
	 */
	public static function add(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		wp_add_privacy_policy_content( 'Formstead', self::content() );
	}

	/**
	 * Suggested policy text.
	 *
	 * Tutorial paragraphs are left out of the copy box. The rest is wording a
	 * site can paste into its own policy.
	 */
	public static function content(): string {
		$tutorial = '<p class="privacy-policy-tutorial">' . __( 'Suggested text. Copy the parts that match this site. This is a description of the plugin, not legal advice.', 'formstead' ) . '</p>';

		$paragraphs = array(
			__( 'Formstead stores form submissions on this website. A submission keeps the field values, the time it was stored, a status, and a receipt id. A hash of the submission content is stored with it and is used to recognize the same content for a short time. The visitor IP address is not stored on the submission. A secret that rotates about once a day is used to hash that address into a short-lived rate-limit counter.', 'formstead' ),
			__( 'Email fields marked as identifying the person are also stored as an HMAC. The address itself is not written in that index. The secret for that HMAC is kept separately and is not rotated, because rotating it would make the index unusable. Fields of other types are not indexed, even when an email address is typed into them. Spam submissions are indexed with the rest.', 'formstead' ),
			__( 'When a form has notification recipients, the site emails them after a submission is stored. The message links to that submission. It includes the field values only when the form is set to include them. Mail is handed to the mail system of this site. A message already accepted by that system is outside this plugin.', 'formstead' ),
			__( 'People who can read submissions can search the inbox for an exact email address. The address is placed in the page address, so it can appear in the browser history and in server logs.', 'formstead' ),
			__( 'WordPress personal-data export and erase can copy or delete the submissions tied to an identifying email field. Erasure deletes the whole submission, including a waiting notification and the index row. Export and erase do not run while the index is still being built. Finish it with wp formstead identities rebuild.', 'formstead' ),
			__( 'An audit log records that a form was saved, settings were changed, submissions were exported or deleted, retention ran, or personal data was exported or erased. The log stores who did it and a short code. It does not store field values, email addresses, or IP addresses. Audit rows are deleted after 180 days.', 'formstead' ),
			__( 'Each form keeps submissions for the number of days set on that form. The default is 30 days. Zero keeps them until someone deletes them. Spam is deleted after 7 days. Finished notification rows are deleted after 30 days. Idempotency keys are kept for 24 hours. Someone who can delete submissions can delete one immediately. Uninstall removes Formstead tables and options only when the site has chosen that.', 'formstead' ),
			__( 'Deleting a row here does not delete a host backup, a server log, or a message that the mail system has already accepted.', 'formstead' ),
		);

		$html = $tutorial;
		foreach ( $paragraphs as $paragraph ) {
			$html .= '<p>' . $paragraph . '</p>';
		}

		return wp_kses_post( $html );
	}
}
