<?php
/**
 * Tests for signed webhook delivery.
 *
 * @package Formstead
 */

namespace Formstead\Tests\Webhook;

use Formstead\Database\Schema;
use Formstead\Delivery\DeliveryRepository;
use Formstead\Delivery\DeliveryStatus;
use Formstead\Delivery\DeliveryWorker;
use Formstead\Delivery\Scheduler;
use Formstead\Forms\Field;
use Formstead\Forms\FieldType;
use Formstead\Forms\Form;
use Formstead\Forms\FormNotifications;
use Formstead\Forms\FormRepository;
use Formstead\Forms\FormWebhook;
use Formstead\Submissions\Submission;
use Formstead\Submissions\SubmissionDeleter;
use Formstead\Submissions\SubmissionIntake;
use Formstead\Submissions\SubmissionStatus;
use Formstead\Webhook\WebhookFailure;
use Formstead\Webhook\WebhookSignature;
use WP_UnitTestCase;

/**
 * Signature, retries, cancellation, and independence from email.
 *
 * @phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
 * @phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
 * @phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
 */
final class WebhookChannelTest extends WP_UnitTestCase {

	/**
	 * HTTP status the stub returns.
	 *
	 * @var int
	 */
	private static int $http_status = 200;

	/**
	 * Captured webhook requests.
	 *
	 * @var list<array{headers: array<string, string>, body: string}>
	 */
	private static array $requests = array();

	/**
	 * Whether mail is accepted.
	 *
	 * @var bool
	 */
	private static bool $mail_sent = true;

	/**
	 * Drop stubs between tests.
	 */
	public function tear_down(): void {
		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'pre_wp_mail' );
		self::$http_status = 200;
		self::$requests    = array();
		self::$mail_sent   = true;
		parent::tear_down();
	}

	/**
	 * The shared vector matches Standard Webhooks.
	 */
	public function test_signature_matches_the_shared_vector(): void {
		$vector = $this->vector();

		$signature = WebhookSignature::header( $vector['id'], $vector['timestamp'], $vector['body'], $vector['secret'] );

		$this->assertSame( $vector['signature'], $signature );
	}

	/**
	 * A form with a URL stores a webhook beside email, and spam stores neither.
	 */
	public function test_webhook_and_email_are_stored_together_and_spam_stores_neither(): void {
		$form    = $this->save_form( true, false );
		$intake  = new SubmissionIntake();
		$stored  = $intake->accept( $form, array( 'email' => 'a@b.co' ), null );
		$spam    = $intake->accept( $form, array( 'email' => 'other@b.co' ), null, true );
		$saved   = $stored->submission();
		$flagged = $spam->submission();

		$this->assertInstanceOf( Submission::class, $saved );
		$this->assertInstanceOf( Submission::class, $flagged );
		$this->assertSame( SubmissionStatus::SPAM, $flagged->status() );
		$this->assertSame( array(), ( new DeliveryRepository() )->for_submission( $flagged->id() ) );

		$deliveries = ( new DeliveryRepository() )->for_submission( $saved->id() );
		$this->assertCount( 2, $deliveries );
		$channels = array( $deliveries[0]->channel(), $deliveries[1]->channel() );
		sort( $channels );
		$this->assertSame( array( 'email', 'webhook' ), $channels );
		$webhook = 'webhook' === $deliveries[0]->channel() ? $deliveries[0] : $deliveries[1];
		$email   = 'email' === $deliveries[0]->channel() ? $deliveries[0] : $deliveries[1];
		$this->assertSame( 1, preg_match( '/\Afse_[a-z2-7]{16}\z/', $webhook->event_id() ) );
		$this->assertSame( '', $email->event_id() );
		$this->assertNotFalse( wp_next_scheduled( Scheduler::SEND, array( $webhook->id() ) ) );
		$this->assertNotFalse( wp_next_scheduled( Scheduler::SEND, array( $email->id() ) ) );
	}

	/**
	 * The body omits fields until the form asks for them, and the id stays the same.
	 */
	public function test_body_fields_follow_the_option_and_the_id_is_stable(): void {
		self::$http_status = 500;
		$this->stub_http();
		$form   = $this->save_form( false, false );
		$secret = $form->webhook()->secret();
		$first  = $this->only_webhook( $this->accept( $form ) );

		( new DeliveryWorker() )->send( $first->id() );

		$this->assertCount( 1, self::$requests );
		$this->assertStringNotContainsString( '"fields"', self::$requests[0]['body'] );
		$this->assertStringContainsString( '"type":"submission.created"', self::$requests[0]['body'] );
		$id        = self::$requests[0]['headers']['webhook-id'];
		$timestamp = (int) self::$requests[0]['headers']['webhook-timestamp'];
		$this->assertSame( $first->event_id(), $id );
		$this->assertSame(
			WebhookSignature::header( $id, $timestamp, self::$requests[0]['body'], $secret ),
			self::$requests[0]['headers']['webhook-signature']
		);

		$this->retry( $first->id() );
		( new DeliveryWorker() )->send( $first->id() );
		$this->assertCount( 2, self::$requests );
		$this->assertSame( $id, self::$requests[1]['headers']['webhook-id'] );

		$with_fields    = $this->save_form( false, true );
		self::$requests = array();
		$second         = $this->only_webhook( $this->accept( $with_fields ) );
		( new DeliveryWorker() )->send( $second->id() );
		$this->assertStringContainsString( '"fields"', self::$requests[0]['body'] );
		$this->assertStringContainsString( 'a@b.co', self::$requests[0]['body'] );
		$this->assertStringContainsString( 'Email', self::$requests[0]['body'] );
	}

	/**
	 * A blocked URL fails once and is not requested.
	 */
	public function test_blocked_url_fails_without_a_request_or_a_retry(): void {
		global $wpdb;

		$this->stub_http();
		$form    = $this->save_form( false, false );
		$webhook = $this->only_webhook( $this->accept( $form ) );
		$wpdb->update(
			Schema::forms_table(),
			array( 'webhook_url' => 'https://127.0.0.1/hook' ),
			array( 'id' => $form->id() ),
			array( '%s' ),
			array( '%d' )
		);

		$worker = new DeliveryWorker();
		$worker->send( $webhook->id() );
		$loaded = ( new DeliveryRepository() )->find( $webhook->id() );
		$this->assertNotNull( $loaded );
		$this->assertSame( DeliveryStatus::FAILED, $loaded->status() );
		$this->assertSame( WebhookFailure::BLOCKED_URL, $loaded->last_error() );
		$this->assertSame( 1, $loaded->attempts() );
		$this->assertSame( array(), self::$requests );

		$worker->send( $webhook->id() );
		$again = ( new DeliveryRepository() )->find( $webhook->id() );
		$this->assertNotNull( $again );
		$this->assertSame( 1, $again->attempts() );
		$this->assertSame( array(), self::$requests );
	}

	/**
	 * A redirect is not followed and is not retried.
	 */
	public function test_redirect_fails_without_a_retry(): void {
		self::$http_status = 302;
		$this->stub_http();
		$form    = $this->save_form( false, false );
		$webhook = $this->only_webhook( $this->accept( $form ) );

		$worker = new DeliveryWorker();
		$worker->send( $webhook->id() );
		$loaded = ( new DeliveryRepository() )->find( $webhook->id() );
		$this->assertNotNull( $loaded );
		$this->assertSame( DeliveryStatus::FAILED, $loaded->status() );
		$this->assertSame( WebhookFailure::REDIRECT, $loaded->last_error() );
		$this->assertCount( 1, self::$requests );

		$worker->send( $webhook->id() );
		$this->assertCount( 1, self::$requests );
	}

	/**
	 * The fifth HTTP failure is final, and the receiver body is not stored or logged.
	 */
	public function test_retries_stop_after_five_failures_without_storing_the_response(): void {
		global $wpdb;

		self::$http_status = 500;
		$this->stub_http();
		$form    = $this->save_form( false, false );
		$webhook = $this->only_webhook( $this->accept( $form ) );
		$worker  = new DeliveryWorker();
		$log     = tempnam( sys_get_temp_dir(), 'formstead-webhook' );
		$this->assertIsString( $log );
		$previous = ini_get( 'error_log' );
		// phpcs:ignore WordPress.PHP.IniSet.Risky -- capture this test's error_log line only.
		ini_set( 'error_log', $log );

		try {
			for ( $attempt = 0; $attempt < 5; $attempt++ ) {
				$this->retry( $webhook->id() );
				$worker->send( $webhook->id() );
			}
		} finally {
			// phpcs:ignore WordPress.PHP.IniSet.Risky -- restore the previous error_log destination.
			ini_set( 'error_log', is_string( $previous ) ? $previous : '' );
		}

		$loaded = ( new DeliveryRepository() )->find( $webhook->id() );
		$this->assertNotNull( $loaded );
		$this->assertSame( DeliveryStatus::FAILED, $loaded->status() );
		$this->assertSame( WebhookFailure::HTTP_5XX, $loaded->last_error() );
		$this->assertSame( 5, $loaded->attempts() );
		$this->assertCount( 5, self::$requests );
		$ids = array_map(
			static function ( array $request ): string {
				return $request['headers']['webhook-id'];
			},
			self::$requests
		);
		$this->assertSame( array( $webhook->event_id() ), array_values( array_unique( $ids ) ) );

		$worker->send( $webhook->id() );
		$this->assertCount( 5, self::$requests );

		$table = Schema::deliveries_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $webhook->id() ), ARRAY_A );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- the test reads the log file it just wrote.
		$logged = file_get_contents( $log );
		$dump   = strtolower( (string) wp_json_encode( $row ) . (string) $logged );
		$this->assertStringNotContainsString( 'receiver-secret-body', $dump );
	}

	/**
	 * Deleting the submission cancels the webhook, and the worker does not send it.
	 */
	public function test_delete_cancels_the_pending_webhook(): void {
		$this->stub_http();
		$form    = $this->save_form( false, false );
		$saved   = $this->accept( $form );
		$webhook = $this->only_webhook( $saved );

		$deleted = ( new SubmissionDeleter() )->delete( array( $saved->id() ) );
		$this->assertIsInt( $deleted );

		$loaded = ( new DeliveryRepository() )->find( $webhook->id() );
		$this->assertNotNull( $loaded );
		$this->assertSame( DeliveryStatus::CANCELLED, $loaded->status() );

		( new DeliveryWorker() )->send( $webhook->id() );
		$this->assertSame( array(), self::$requests );
	}

	/**
	 * Email and webhook succeed or fail on their own.
	 */
	public function test_email_and_webhook_are_independent(): void {
		self::$mail_sent   = true;
		self::$http_status = 500;
		$this->stub_http();
		$this->stub_mail();
		$form  = $this->save_form( true, false );
		$saved = $this->accept( $form );
		$this->send_both( $saved );

		$repository = new DeliveryRepository();
		$email      = $this->channel_row( $saved, 'email' );
		$webhook    = $this->channel_row( $saved, 'webhook' );
		$this->assertSame( DeliveryStatus::HANDED_OFF, $repository->find( $email->id() )->status() );
		$this->assertSame( DeliveryStatus::PENDING, $repository->find( $webhook->id() )->status() );
		$this->assertSame( WebhookFailure::HTTP_5XX, $repository->find( $webhook->id() )->last_error() );

		self::$mail_sent   = false;
		self::$http_status = 200;
		self::$requests    = array();
		$second            = $this->accept( $form, 'b@b.co' );
		$this->send_both( $second );
		$this->assertSame( DeliveryStatus::PENDING, $repository->find( $this->channel_row( $second, 'email' )->id() )->status() );
		$this->assertSame( DeliveryStatus::HANDED_OFF, $repository->find( $this->channel_row( $second, 'webhook' )->id() )->status() );
		$row = $repository->find( $this->channel_row( $second, 'webhook' )->id() );
		$this->assertNotNull( $row );
		$this->assertStringNotContainsString( 'receiver-secret-body', $row->last_error() );
	}

	/**
	 * Shared signature vector.
	 *
	 * @return array{id: string, timestamp: int, secret: string, body: string, signature: string}
	 */
	private function vector(): array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- the test reads a local fixture, not a remote URL.
		$contents = file_get_contents( dirname( __DIR__ ) . '/fixtures/webhook-vector.json' );
		$this->assertIsString( $contents );
		$decoded = json_decode( $contents, true );
		$this->assertIsArray( $decoded );

		return array(
			'id'        => (string) $decoded['id'],
			'timestamp' => (int) $decoded['timestamp'],
			'secret'    => (string) $decoded['secret'],
			'body'      => (string) $decoded['body'],
			'signature' => (string) $decoded['signature'],
		);
	}

	/**
	 * Persist a form with a public webhook and, optionally, a recipient.
	 *
	 * @param bool $email   Whether the form also sends email.
	 * @param bool $content Whether the webhook includes submitted fields.
	 */
	private function save_form( bool $email, bool $content ): Form {
		$saved = ( new FormRepository() )->insert(
			new Form(
				0,
				'',
				'Contact',
				true,
				array( new Field( 'email', 'Email', FieldType::Email, true, 255 ) ),
				1,
				'https://example.com/thanks',
				'',
				'',
				null,
				0,
				$email ? new FormNotifications( array( 'owner@example.com' ), null, false ) : null,
				30,
				array(),
				new FormWebhook( 'https://93.184.216.34/hook', FormWebhook::generate_secret(), $content )
			)
		);
		$this->assertInstanceOf( Form::class, $saved );

		return $saved;
	}

	/**
	 * Store one submission.
	 *
	 * @param Form   $form  Form that receives it.
	 * @param string $email Submitted email. A repeat of the same body is stored as spam.
	 */
	private function accept( Form $form, string $email = 'a@b.co' ): Submission {
		$result = ( new SubmissionIntake() )->accept( $form, array( 'email' => $email ), null );
		$saved  = $result->submission();
		$this->assertInstanceOf( Submission::class, $saved );

		return $saved;
	}

	/**
	 * The webhook delivery for a submission.
	 *
	 * @param Submission $submission Stored submission.
	 */
	private function only_webhook( Submission $submission ): \Formstead\Delivery\Delivery {
		return $this->channel_row( $submission, 'webhook' );
	}

	/**
	 * One channel's delivery.
	 *
	 * @param Submission $submission Stored submission.
	 * @param string     $channel    Channel name.
	 */
	private function channel_row( Submission $submission, string $channel ): \Formstead\Delivery\Delivery {
		foreach ( ( new DeliveryRepository() )->for_submission( $submission->id() ) as $delivery ) {
			if ( $channel === $delivery->channel() ) {
				return $delivery;
			}
		}

		$this->fail( 'Missing ' . $channel . ' delivery.' );
	}

	/**
	 * Send the email and webhook deliveries for one submission.
	 *
	 * @param Submission $submission Stored submission.
	 */
	private function send_both( Submission $submission ): void {
		$worker = new DeliveryWorker();
		foreach ( ( new DeliveryRepository() )->for_submission( $submission->id() ) as $delivery ) {
			$worker->send( $delivery->id() );
		}
	}

	/**
	 * Make a delivery due again.
	 *
	 * @param int $delivery_id Delivery id.
	 */
	private function retry( int $delivery_id ): void {
		global $wpdb;

		$wpdb->update(
			Schema::deliveries_table(),
			array( 'next_attempt_at' => '2000-01-01 00:00:00' ),
			array( 'id' => $delivery_id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Answer webhook requests without leaving the test.
	 */
	private function stub_http(): void {
		add_filter(
			'pre_http_request',
			static function ( $pre, $args ) {
				unset( $pre );
				$headers = array();
				if ( isset( $args['headers'] ) && is_array( $args['headers'] ) ) {
					foreach ( $args['headers'] as $name => $value ) {
						$headers[ strtolower( (string) $name ) ] = (string) $value;
					}
				}
				self::$requests[] = array(
					'headers' => $headers,
					'body'    => isset( $args['body'] ) && is_string( $args['body'] ) ? $args['body'] : '',
				);

				return array(
					'headers'  => array(),
					'body'     => 'receiver-secret-body',
					'response' => array(
						'code'    => self::$http_status,
						'message' => 'stub',
					),
					'cookies'  => array(),
					'filename' => '',
				);
			},
			10,
			2
		);
	}

	/**
	 * Accept or refuse mail without sending it.
	 */
	private function stub_mail(): void {
		add_filter(
			'pre_wp_mail',
			static function () {
				return self::$mail_sent;
			}
		);
	}
}
