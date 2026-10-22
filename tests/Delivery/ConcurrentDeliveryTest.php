<?php
/**
 * Two real worker processes must not send the same live delivery.
 *
 * @package Formstead
 */

namespace Formstead\Tests\Delivery;

use Formstead\Database\Migrator;
use Formstead\Delivery\DeliveryRepository;
use Formstead\Forms\Field;
use Formstead\Forms\FieldType;
use Formstead\Forms\Form;
use Formstead\Forms\FormRepository;
use Formstead\Submissions\SubmissionRepository;
use Formstead\Tests\TestCase\MigrationTestCase;

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.DiscouragedPHPFunctions, WordPress.DB.DirectDatabaseQuery -- disposable schema and two local worker processes, no shell.

/**
 * A committed fixture is visible to both independent database connections.
 *
 * @group schema
 */
final class ConcurrentDeliveryTest extends MigrationTestCase {

	/**
	 * A live claim allows only one worker to call its channel.
	 */
	public function test_parallel_workers_send_once_while_the_claim_is_live(): void {
		global $wpdb;
		$this->assertNull( Migrator::run() );
		$form = ( new FormRepository() )->insert( new Form( 0, '', 'Concurrent delivery', true, array( new Field( 'note', 'Note', FieldType::Text, false, 255 ) ), 1, 'https://example.com/thanks', '', '' ) );
		$this->assertInstanceOf( Form::class, $form );
		$submission = ( new SubmissionRepository() )->insert( $form, array( 'note' => 'Synthetic concurrency fixture' ) );
		$this->assertNotInstanceOf( \WP_Error::class, $submission );
		$delivery = ( new DeliveryRepository() )->create( $submission->id() );
		$this->assertIsInt( $delivery );
		$wpdb->query( 'COMMIT' );

		$capture = tempnam( sys_get_temp_dir(), 'formstead-worker-' );
		$barrier = $capture . '-ready';
		$env     = array_merge(
			getenv(),
			array(
				'FORMSTEAD_WP_PATH'        => ABSPATH,
				'FORMSTEAD_DB_NAME'        => DB_NAME,
				'FORMSTEAD_DB_USER'        => DB_USER,
				'FORMSTEAD_DB_PASSWORD'    => DB_PASSWORD,
				'FORMSTEAD_DB_HOST'        => DB_HOST,
				'FORMSTEAD_DB_PREFIX'      => $wpdb->prefix,
				'FORMSTEAD_WORKER_BARRIER' => $barrier,
				'FORMSTEAD_WORKER_CAPTURE' => $capture,
				'FORMSTEAD_DELIVERY_ID'    => (string) $delivery,
			)
		);
		$workers = array();
		$log     = tmpfile();
		try {
			for ( $i = 0; $i < 2; ++$i ) {
				$process = proc_open(
					array( PHP_BINARY, dirname( __DIR__ ) . '/fixtures/send-delivery.php' ),
					array(
						0 => array( 'pipe', 'r' ),
						1 => $log,
						2 => $log,
					),
					$pipes,
					null,
					$env
				);
				$this->assertIsResource( $process );
				fclose( $pipes[0] );
				$workers[] = $process;
			}
			touch( $barrier );
			foreach ( $workers as $index => $worker ) {
				$code = proc_close( $worker );
				unset( $workers[ $index ] );
				rewind( $log );
				$this->assertSame( 0, $code, (string) stream_get_contents( $log ) );
			}
			$this->assertSame( "sent\n", file_get_contents( $capture ) );
			$stored = ( new DeliveryRepository() )->find( $delivery );
			$this->assertNotNull( $stored );
			$this->assertSame( 'handed_off', $stored->status() );
			$this->assertSame( 1, $stored->attempts() );
		} finally {
			foreach ( $workers as $worker ) {
				proc_terminate( $worker );
				proc_close( $worker );
			}
			fclose( $log );
			unlink( $capture );
			if ( is_file( $barrier ) ) {
				unlink( $barrier );
			}
		}
	}
}
