<?php
/**
 * Email notifications for a stored submission.
 *
 * @package Formstead
 */

namespace Formstead\Delivery;

use Formstead\Admin\Menu;
use Formstead\Forms\Field;
use Formstead\Forms\FieldType;
use Formstead\Forms\Form;
use Formstead\Forms\FormVersion;
use Formstead\Forms\FormVersionRepository;
use Formstead\Submissions\Submission;

/**
 * Hands a submission to wp_mail().
 *
 * The subject never includes submitted values. The body is an inbox link
 * unless the form asks for the values. From is left to the site mail setup.
 */
final class EmailChannel implements Channel {

	/**
	 * Send the notification, or return an error code.
	 *
	 * A mailer exception is left for the worker, which stores `mail_exception`
	 * and does not keep the exception message.
	 *
	 * @param Submission $submission Submission to send.
	 * @param Form       $form       Form that received it.
	 */
	public function send( Submission $submission, Form $form ): ?string {
		$recipients = $this->recipients( $form );
		if ( array() === $recipients ) {
			return 'no_recipients';
		}

		$failed   = false;
		$listener = static function ( $error ) use ( &$failed ): void {
			unset( $error );
			$failed = true;
		};

		add_action( 'wp_mail_failed', $listener );
		try {
			$sent = wp_mail(
				$recipients,
				$this->subject( $form ),
				$this->body( $submission, $form ),
				$this->headers( $submission, $form )
			);
		} finally {
			remove_action( 'wp_mail_failed', $listener );
		}

		if ( ! $sent || $failed ) {
			return 'mail_failed';
		}

		return null;
	}

	/**
	 * Addresses configured on the form that are still valid.
	 *
	 * @param Form $form Form that received the submission.
	 * @return string[]
	 */
	private function recipients( Form $form ): array {
		$recipients = array();
		foreach ( $form->notifications()->recipients() as $recipient ) {
			if ( is_string( $recipient ) && is_email( $recipient ) && ! $this->has_line_break( $recipient ) ) {
				$recipients[] = $recipient;
			}
		}

		return $recipients;
	}

	/**
	 * Subject. Newlines in the site or form name are flattened.
	 *
	 * @param Form $form Form that received the submission.
	 */
	private function subject( Form $form ): string {
		return sprintf(
			'[%s] New submission: %s',
			$this->single_line( wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ) ),
			$this->single_line( $form->name() )
		);
	}

	/**
	 * Plain-text body. Submitted values are included only when the form asks.
	 *
	 * @param Submission $submission Submission to send.
	 * @param Form       $form       Form that received it.
	 */
	private function body( Submission $submission, Form $form ): string {
		$lines = array(
			__( 'A new submission was stored.', 'formstead' ),
			'',
			admin_url( 'admin.php?page=' . Menu::MENU_SLUG . '&submission=' . $submission->id() ),
		);

		if ( $form->notifications()->includes_content() ) {
			$lines[] = '';
			foreach ( $this->content_lines( $submission, $form ) as $line ) {
				$lines[] = $line;
			}
		}

		return implode( "\n", $lines );
	}

	/**
	 * One line per submitted field, labeled from the schema snapshot.
	 *
	 * @param Submission $submission Submission to send.
	 * @param Form       $form       Form that received it.
	 * @return string[]
	 */
	private function content_lines( Submission $submission, Form $form ): array {
		$labels = $this->labels( $submission, $form );
		$lines  = array();

		foreach ( $submission->payload() as $name => $value ) {
			$label   = $labels[ $name ] ?? $name;
			$lines[] = $label . ': ' . $this->plain_value( $value );
		}

		return $lines;
	}

	/**
	 * Field labels from the schema snapshot, then the current form.
	 *
	 * @param Submission $submission Submission to send.
	 * @param Form       $form       Form that received it.
	 * @return array<string, string>
	 */
	private function labels( Submission $submission, Form $form ): array {
		$snapshot = ( new FormVersionRepository() )->find( $submission->form_id(), $submission->schema_version() );
		$fields   = $snapshot instanceof FormVersion ? $snapshot->fields() : array();
		if ( array() === $fields ) {
			$fields = $form->fields();
		}

		$labels = array();
		foreach ( $fields as $field ) {
			if ( $field instanceof Field ) {
				$labels[ $field->name() ] = $field->label();
			}
		}

		return $labels;
	}

	/**
	 * A submitted value as a single block of text.
	 *
	 * @param string|bool|list<string> $value Stored value.
	 */
	private function plain_value( string|bool|array $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? __( 'Yes', 'formstead' ) : __( 'No', 'formstead' );
		}

		if ( is_array( $value ) ) {
			return implode( ', ', $value );
		}

		return $value;
	}

	/**
	 * Headers for this message. From is not set here.
	 *
	 * @param Submission $submission Submission to send.
	 * @param Form       $form       Form that received it.
	 * @return string[]
	 */
	private function headers( Submission $submission, Form $form ): array {
		$headers = array();
		$reply   = $this->reply_to( $submission, $form );
		if ( is_string( $reply ) ) {
			$headers[] = 'Reply-To: ' . $reply;
		}

		/**
		 * Filters the headers on a notification email.
		 *
		 * Formstead does not set From. A site can add it here. Newlines are
		 * removed from every header after this filter runs.
		 *
		 * @param string[]   $headers    Headers, including Reply-To when it is valid.
		 * @param Submission $submission Submission being sent.
		 * @param Form       $form       Form that received it.
		 */
		$filtered = apply_filters( 'formstead_mail_headers', $headers, $submission, $form );
		if ( ! is_array( $filtered ) ) {
			$filtered = $headers;
		}

		$clean = array();
		foreach ( $filtered as $header ) {
			if ( ! is_string( $header ) ) {
				continue;
			}

			$header = str_replace( array( "\r", "\n" ), '', $header );
			if ( '' !== trim( $header ) ) {
				$clean[] = $header;
			}
		}

		return $clean;
	}

	/**
	 * Reply-To from the named email field, when the value is a single address.
	 *
	 * @param Submission $submission Submission to send.
	 * @param Form       $form       Form that received it.
	 */
	private function reply_to( Submission $submission, Form $form ): ?string {
		$name = $form->notifications()->reply_to_field();
		if ( ! is_string( $name ) || '' === $name ) {
			return null;
		}

		$field = $form->field( $name );
		if ( ! $field instanceof Field || FieldType::Email !== $field->type() ) {
			return null;
		}

		$value = $submission->payload()[ $name ] ?? null;
		if ( ! is_string( $value ) || $this->has_line_break( $value ) || ! is_email( $value ) ) {
			return null;
		}

		return $value;
	}

	/**
	 * Collapse line breaks so a name cannot become a second header.
	 *
	 * @param string $value Site name or form name.
	 */
	private function single_line( string $value ): string {
		$flat = preg_replace( '/\s+/', ' ', $value );
		return trim( is_string( $flat ) ? $flat : $value );
	}

	/**
	 * Whether a value contains a CR or LF.
	 *
	 * @param string $value Candidate header value.
	 */
	private function has_line_break( string $value ): bool {
		return str_contains( $value, "\r" ) || str_contains( $value, "\n" );
	}
}
