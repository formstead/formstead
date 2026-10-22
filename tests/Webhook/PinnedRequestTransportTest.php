<?php
/**
 * Real HTTP tests for address pinning. No external service is contacted.
 *
 * @package Formstead
 */

namespace Formstead\Tests\Webhook;

use Formstead\Webhook\PinnedRequest;
use Formstead\Webhook\WebhookFailure;
use Formstead\Webhook\WebhookUrlPolicy;
use WP_UnitTestCase;
use WpOrg\Requests\Transport\Fsockopen;

// phpcs:disable WordPress.WP.AlternativeFunctions -- the fixture needs an isolated local process and socket.

/**
 * A name with no DNS record must reach only the address chosen by the policy.
 */
final class PinnedRequestTransportTest extends WP_UnitTestCase {

	/**
	 * The cURL transport pins a name even if another transport was selected.
	 */
	public function test_the_real_request_uses_the_checked_address(): void {
		$this->with_receiver(
			function ( string $url, string $capture ): void {
				$other = static function ( &$request_url, &$headers, &$data, &$type, &$options ): void {
					unset( $request_url, $headers, $data, $type );
					$options['transport'] = new Fsockopen();
				};
				add_action( 'requests-requests.before_request', $other, 10, 5 );
				try {
					$result = $this->sender()->post( str_replace( 'pinning.invalid', 'PINNING.INVALID', $url ), 'pinned-test-body' );
				} finally {
					remove_action( 'requests-requests.before_request', $other, 10 );
				}
				$this->assertNull( $result );
				$this->assertSame( 'pinned-test-body', file_get_contents( $capture ) );
			}
		);
	}

	/**
	 * Losing the pinning arguments stops before any connection is made.
	 */
	public function test_missing_pinning_arguments_refuse_the_real_request(): void {
		$this->with_receiver(
			function ( string $url, string $capture ): void {
				$remove_pin = static function ( array $args ): array {
					unset( $args['formstead_resolve'] );
					return $args;
				};
				add_filter( 'http_request_args', $remove_pin );
				try {
					$result = $this->sender()->post( $url, 'must-not-arrive' );
				} finally {
					remove_filter( 'http_request_args', $remove_pin );
				}
				$this->assertSame( WebhookFailure::UNSAFE_TRANSPORT, $result );
				$this->assertSame( '', file_get_contents( $capture ) );
			}
		);
	}

	/**
	 * Use a private address only under the explicit local test exception.
	 */
	private function sender(): PinnedRequest {
		return new PinnedRequest( new WebhookUrlPolicy( static fn(): array => array( '127.0.0.1' ), 'local' ) );
	}

	/**
	 * Start one disposable receiver and restore all process and WordPress state.
	 *
	 * @param callable(string, string): void $test Test using the URL and captured-body file.
	 */
	private function with_receiver( callable $test ): void {
		$socket = stream_socket_server( 'tcp://127.0.0.1:0' );
		$this->assertIsResource( $socket );
		$address = stream_socket_get_name( $socket, false );
		$this->assertIsString( $address );
		fclose( $socket );
		$port    = (int) substr( $address, strrpos( $address, ':' ) + 1 );
		$capture = tempnam( sys_get_temp_dir(), 'formstead-capture-' );
		$this->assertIsString( $capture );
		$log = tmpfile();
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- isolated receiver, no shell or external address.
		$process = proc_open(
			array( PHP_BINARY, '-S', $address, dirname( __DIR__ ) . '/fixtures/webhook-receiver.php' ),
			array(
				0 => array( 'pipe', 'r' ),
				1 => $log,
				2 => $log,
			),
			$pipes,
			null,
			array_merge( getenv(), array( 'FORMSTEAD_TRANSPORT_CAPTURE' => $capture ) )
		);
		$this->assertIsResource( $process );
		$home  = get_option( 'home' );
		$url   = 'http://pinning.invalid:' . $port . '/hook';
		$allow = static fn(): array => array( 'pinning.invalid:' . $port );
		add_filter( WebhookUrlPolicy::ALLOWED_HOSTS, $allow );
		update_option( 'home', 'http://pinning.invalid:' . $port );
		$local_home = static fn(): string => 'http://pinning.invalid:' . $port;
		add_filter( 'pre_option_home', $local_home );
		try {
			$ready = false;
			for ( $attempt = 0; $attempt < 50; ++$attempt ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- startup can refuse connections briefly.
				$probe = @fsockopen( '127.0.0.1', $port, $error_code, $error_message, 0.1 );
				if ( is_resource( $probe ) ) {
					fclose( $probe );
					$ready = true;
					break;
				}
				usleep( 20000 );
			}
			$this->assertTrue( $ready, 'Local receiver did not start.' );
			$test( $url, $capture );
		} finally {
			update_option( 'home', $home );
			remove_filter( WebhookUrlPolicy::ALLOWED_HOSTS, $allow );
			remove_filter( 'pre_option_home', $local_home );
			fclose( $pipes[0] );
			proc_terminate( $process );
			proc_close( $process );
			fclose( $log );
			unlink( $capture );
		}
	}
}
