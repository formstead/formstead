<?php
/**
 * Tests for notification email.
 *
 * @package Formstead
 */

namespace Formstead\Tests\Delivery;

use Formstead\Delivery\DeliveryRepository;
use Formstead\Delivery\DeliveryStatus;
use Formstead\Delivery\DeliveryWorker;
use Formstead\Delivery\EmailChannel;
use Formstead\Forms\Field;
use Formstead\Forms\FieldType;
use Formstead\Forms\Form;
use Formstead\Forms\FormNotifications;
use Formstead\Forms\FormRepository;
use Formstead\Submissions\Submission;
use Formstead\Submissions\SubmissionRepository;
use WP_UnitTestCase;

/**
 * Covers the message, a failed hand-off, and header injection.
 *
 * @phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
 * @phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
 */
final class EmailChannelTest extends WP_UnitTestCase {

	/**
	 * Remove mail filters between tests.
	 */
	public function tear_down(): void {
		remove_all_filters( 'pre_wp_mail' );
		remove_all_filters( 'formstead_mail_headers' );
		parent::tear_down();
	}

	/**
	 * A mailer failure keeps the submission and stops after five attempts.
	 */
	public function test_failed_mail_keeps_the_submission_and_stops_retrying(): void {
		global $wpdb;

		$form       = $this->save_form( array( 'owner@example.com' ), null, false );
		$repository = new SubmissionRepository();
		$saved      = $repository->insert( $form, array( 'email' => 'secret-submitter@example.com' ) );
		$this->assertInstanceOf( Submission::class, $saved );
		$delivery = ( new DeliveryRepository() )->create( $saved->id() );
		$this->assertIsInt( $delivery );

		$captured = null;
		add_filter(
			'pre_wp_mail',
			static function ( $short, $atts ) use ( &$captured ) {
				unset( $short );
				$captured = $atts;
				return false;
			},
			10,
			2
		);

		$worker = new DeliveryWorker( new EmailChannel() );
		$table  = \Formstead\Database\Schema::deliveries_table();
		for ( $attempt = 0; $attempt < 5; $attempt++ ) {
			$wpdb->update(
				$table,
				array( 'next_attempt_at' => '2000-01-01 00:00:00' ),
				array( 'id' => $delivery ),
				array( '%s' ),
				array( '%d' )
			);
			$worker->send( $delivery );
		}

		$loaded = ( new DeliveryRepository() )->find( $delivery );
		$this->assertNotNull( $loaded );
		$this->assertSame( DeliveryStatus::FAILED, $loaded->status() );
		$this->assertSame( 'mail_failed', $loaded->last_error() );
		$this->assertSame( 5, $loaded->attempts() );
		$this->assertInstanceOf( Submission::class, $repository->find( $saved->id() ) );

		$this->assertIsArray( $captured );
		$subject = (string) $captured['subject'];
		$message = (string) $captured['message'];
		$this->assertStringNotContainsString( 'secret-submitter@example.com', $subject );
		$this->assertStringNotContainsString( "\n", $subject );
		$this->assertStringContainsString( 'Contact', $subject );
		$this->assertStringNotContainsString( 'secret-submitter@example.com', $message );
		$this->assertStringContainsString( 'page=formstead', $message );
		$this->assertStringContainsString( 'submission=' . $saved->id(), $message );
		$this->assertStringNotContainsString( 'From:', $this->header_text( $captured ) );
	}

	/**
	 * A reply-to value with a line break or a bad address is left off the message.
	 */
	public function test_unsafe_reply_to_is_omitted(): void {
		foreach ( array( "ada@example.com\nBcc: evil@example.com", 'not-an-email' ) as $value ) {
			$captured = $this->send_with_reply_to( $value, false );
			$this->assertIsArray( $captured );
			$this->assertStringNotContainsString( 'Reply-To:', $this->header_text( $captured ) );
			$this->assertStringNotContainsString( 'Bcc:', $this->header_text( $captured ) );
			$this->assertStringNotContainsString( 'secret-submitter@example.com', (string) $captured['subject'] );
		}
	}

	/**
	 * A valid email field becomes Reply-To, and the subject stays one line.
	 */
	public function test_valid_reply_to_is_a_single_header(): void {
		update_option( 'blogname', "Site\nName" );
		$captured = $this->send_with_reply_to( 'ada@example.com', false );

		$this->assertIsArray( $captured );
		$this->assertStringContainsString( 'Reply-To: ada@example.com', $this->header_text( $captured ) );
		$this->assertStringNotContainsString( "\n", (string) $captured['subject'] );
		$this->assertStringNotContainsString( "\r", (string) $captured['subject'] );
		$this->assertStringContainsString( 'Site Name', (string) $captured['subject'] );
		$this->assertStringNotContainsString( 'ada@example.com', (string) $captured['subject'] );
		$this->assertStringNotContainsString( 'ada@example.com', (string) $captured['message'] );
	}

	/**
	 * A site name stored with HTML entities is decoded in the subject.
	 */
	public function test_subject_decodes_html_entities_in_the_site_name(): void {
		add_filter(
			'pre_option_blogname',
			static function () {
				return 'A &amp; B';
			}
		);

		$captured = $this->send_with_reply_to( 'ada@example.com', false );

		$this->assertIsArray( $captured );
		$this->assertStringContainsString( '[A & B] ', (string) $captured['subject'] );
		$this->assertStringNotContainsString( '&amp;', (string) $captured['subject'] );
	}

	/**
	 * Full content uses the label from the schema snapshot, not a later rename.
	 */
	public function test_full_content_uses_the_snapshot_label(): void {
		$forms = new FormRepository();
		$form  = $this->save_form( array( 'owner@example.com' ), 'email', true );
		$saved = ( new SubmissionRepository() )->insert( $form, array( 'email' => 'secret-submitter@example.com' ) );
		$this->assertInstanceOf( Submission::class, $saved );

		$updated = $forms->update(
			new Form(
				$form->id(),
				$form->public_id(),
				$form->name(),
				true,
				array( new Field( 'email', 'Address', FieldType::Email, true, 255 ) ),
				$form->schema_version(),
				$form->success_url(),
				$form->created_at(),
				$form->updated_at(),
				null,
				0,
				new FormNotifications( array( 'owner@example.com' ), 'email', true )
			)
		);
		$this->assertInstanceOf( Form::class, $updated );

		$captured = null;
		add_filter(
			'pre_wp_mail',
			static function ( $short, $atts ) use ( &$captured ) {
				unset( $short );
				$captured = $atts;
				return true;
			},
			10,
			2
		);
		$delivery = ( new DeliveryRepository() )->create( $saved->id() );
		$this->assertIsInt( $delivery );
		( new DeliveryWorker( new EmailChannel() ) )->send( $delivery );

		$this->assertIsArray( $captured );
		$message = (string) $captured['message'];
		$this->assertStringContainsString( 'Email: secret-submitter@example.com', $message );
		$this->assertStringNotContainsString( 'Address:', $message );
		$this->assertStringNotContainsString( 'secret-submitter@example.com', (string) $captured['subject'] );
	}

	/**
	 * A filtered header cannot smuggle a second line, and From is still unset.
	 */
	public function test_filtered_headers_lose_line_breaks(): void {
		add_filter(
			'formstead_mail_headers',
			static function ( $headers ) {
				$headers[] = "X-Formstead: 1\r\nBcc: evil@example.com";
				return $headers;
			}
		);

		$captured = $this->send_with_reply_to( 'ada@example.com', false );
		$this->assertIsArray( $captured );
		$headers = $captured['headers'] ?? array();
		$this->assertIsArray( $headers );
		foreach ( $headers as $header ) {
			$this->assertIsString( $header );
			$this->assertStringNotContainsString( "\n", $header );
			$this->assertStringNotContainsString( "\r", $header );
		}
		$text = implode( "\n", $headers );
		$this->assertStringContainsString( 'X-Formstead: 1Bcc: evil@example.com', $text );
		$this->assertDoesNotMatchRegularExpression( '/^From:/m', $text );
	}

	/**
	 * Send one submission and return the mail arguments.
	 *
	 * @param string $reply_to        Submitted email value.
	 * @param bool   $include_content Whether the body includes the submission.
	 * @return array<string, mixed>|null
	 */
	private function send_with_reply_to( string $reply_to, bool $include_content ): ?array {
		$form  = $this->save_form( array( 'owner@example.com' ), 'email', $include_content, "Contact\nBcc: evil@example.com" );
		$saved = ( new SubmissionRepository() )->insert(
			$form,
			array(
				'email' => $reply_to,
				'note'  => 'secret-submitter@example.com',
			)
		);
		$this->assertInstanceOf( Submission::class, $saved );

		$captured = null;
		add_filter(
			'pre_wp_mail',
			static function ( $short, $atts ) use ( &$captured ) {
				unset( $short );
				$captured = $atts;
				return true;
			},
			10,
			2
		);
		$delivery = ( new DeliveryRepository() )->create( $saved->id() );
		$this->assertIsInt( $delivery );
		( new DeliveryWorker( new EmailChannel() ) )->send( $delivery );
		remove_all_filters( 'pre_wp_mail' );

		return is_array( $captured ) ? $captured : null;
	}

	/**
	 * Persist a contact form.
	 *
	 * @param string[]    $recipients      Notification recipients.
	 * @param string|null $reply_to_field  Email field used as Reply-To.
	 * @param bool        $include_content Whether the body includes the submission.
	 * @param string      $name            Form name.
	 */
	private function save_form( array $recipients, ?string $reply_to_field, bool $include_content, string $name = 'Contact' ): Form {
		$fields = array( new Field( 'email', 'Email', FieldType::Email, true, 255 ) );
		if ( null === $reply_to_field ) {
			$fields[] = new Field( 'note', 'Note', FieldType::Text, false, 255 );
		}

		$saved = ( new FormRepository() )->insert(
			new Form(
				0,
				'',
				$name,
				true,
				$fields,
				1,
				'https://example.com/thanks',
				'',
				'',
				null,
				0,
				new FormNotifications( $recipients, $reply_to_field, $include_content )
			)
		);
		$this->assertInstanceOf( Form::class, $saved );
		return $saved;
	}

	/**
	 * Headers as one block of text.
	 *
	 * @param array<string, mixed> $atts Mail arguments.
	 */
	private function header_text( array $atts ): string {
		$headers = $atts['headers'] ?? '';
		if ( is_array( $headers ) ) {
			return implode( "\n", $headers );
		}

		return (string) $headers;
	}
}
