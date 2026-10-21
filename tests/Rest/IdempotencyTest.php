<?php
/**
 * Tests for idempotent public submissions.
 *
 * @package Formstead
 */

namespace Formstead\Tests\Rest;

use Formstead\Database\Schema;
use Formstead\Forms\Field;
use Formstead\Forms\FieldType;
use Formstead\Forms\Form;
use Formstead\Forms\FormRepository;
use Formstead\Security\OriginPolicy;
use Formstead\Submissions\IdempotencyKey;
use Formstead\Submissions\IdempotencyRepository;
use mysqli;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * Replay, conflict, and expiry of an idempotency key.
 *
 * @phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
 * @phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
 * @phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
 */
final class IdempotencyTest extends WP_UnitTestCase {

	/**
	 * Forms repository.
	 *
	 * @var FormRepository
	 */
	private FormRepository $forms;

	/**
	 * Connection that committed rows outside the suite transaction.
	 *
	 * @var mysqli|null
	 */
	private ?mysqli $cleanup_db = null;

	/**
	 * Form id whose committed rows must be removed after the suite lock is released.
	 *
	 * @var int
	 */
	private int $cleanup_form_id = 0;

	/**
	 * Holder process started with proc_open. Null when no race is running.
	 *
	 * @var resource|null
	 */
	private $holder_process = null;

	/**
	 * Pipes for the holder process, keyed by the proc_open descriptor.
	 *
	 * @var array<int, resource>
	 */
	private array $holder_pipes = array();

	/**
	 * Create the repository.
	 */
	public function set_up(): void {
		parent::set_up();
		OriginPolicy::reset();
		$this->forms = new FormRepository();
		rest_get_server();
	}

	/**
	 * Remove rows committed by the second connection.
	 *
	 * The replay locks those rows until the suite transaction rolls back, so
	 * the delete has to wait until after `parent::tear_down()`.
	 */
	public function tear_down(): void {
		$this->finish_holder();
		parent::tear_down();

		if ( ! $this->cleanup_db instanceof mysqli ) {
			return;
		}

		$submissions = Schema::submissions_table();
		$keys        = Schema::idempotency_keys_table();
		$form_id     = $this->cleanup_form_id;
		$this->cleanup_db->query( "DELETE FROM `{$keys}` WHERE form_id = {$form_id}" );
		$this->cleanup_db->query( "DELETE FROM `{$submissions}` WHERE form_id = {$form_id}" );
		$this->cleanup_db->close();
		$this->cleanup_db      = null;
		$this->cleanup_form_id = 0;
	}

	/**
	 * The same key and body return the original receipt and do not store a second row.
	 */
	public function test_repeat_replays_the_original_receipt_in_both_modes(): void {
		$form = $this->save_form();

		$html = $this->post( $form->public_id(), 'email=a@b.co', 'same-key' );
		$json = $this->post( $form->public_id(), 'email=a@b.co', 'same-key', true );

		$this->assertSame( 303, $html->get_status() );
		$this->assertArrayNotHasKey( 'Idempotent-Replayed', $html->get_headers() );
		$this->assertSame( 201, $json->get_status() );
		$this->assertSame( 'true', $json->get_headers()['Idempotent-Replayed'] );

		$rows = $this->rows();
		$this->assertCount( 1, $rows );
		$data = $json->get_data();
		$this->assertIsArray( $data );
		$this->assertSame( $rows[0]['receipt_id'], $data['receipt_id'] );
	}

	/**
	 * A JSON repeat of a form post matches the same body hash.
	 */
	public function test_json_repeat_of_a_form_post_is_a_replay(): void {
		$form = $this->save_form();

		$first = $this->post( $form->public_id(), 'email=a@b.co', 'cross-key', true );
		$again = $this->post_json( $form->public_id(), '{"email":"a@b.co"}', 'cross-key' );

		$this->assertSame( 201, $first->get_status() );
		$this->assertSame( 201, $again->get_status() );
		$this->assertSame( 'true', $again->get_headers()['Idempotent-Replayed'] );
		$this->assertSame( $first->get_data(), $again->get_data() );
		$this->assertCount( 1, $this->rows() );
	}

	/**
	 * The same key with a different body is a conflict and stores nothing new.
	 */
	public function test_different_body_is_a_conflict_in_both_modes(): void {
		$form = $this->save_form();
		$this->post( $form->public_id(), 'email=a@b.co', 'conflict-key' );

		$html = $this->post( $form->public_id(), 'email=b@b.co', 'conflict-key' );
		$json = $this->post_json( $form->public_id(), '{"email":"c@b.co"}', 'conflict-key' );

		$this->assertSame( 409, $html->get_status() );
		$this->assertSame( 409, $json->get_status() );
		$data = $json->get_data();
		$this->assertIsArray( $data );
		$this->assertSame( 'idempotency_conflict', $data['error']['code'] );
		$this->assertCount( 1, $this->rows() );
	}

	/**
	 * An expired key is reused for a new submission, even when the old body differed.
	 */
	public function test_expired_key_stores_a_new_submission(): void {
		global $wpdb;

		$form = $this->save_form();
		$key  = 'expired-key';
		$wpdb->insert(
			Schema::idempotency_keys_table(),
			array(
				'form_id'       => $form->id(),
				'key_hash'      => hash( 'sha256', $key ),
				'body_hash'     => hash( 'sha256', '{"email":"old@b.co"}' ),
				'submission_id' => 1,
				'expires_at'    => time() - 5,
			),
			array( '%d', '%s', '%s', '%d', '%d' )
		);

		$response = $this->post( $form->public_id(), 'email=a@b.co', $key, true );

		$this->assertSame( 201, $response->get_status() );
		$this->assertArrayNotHasKey( 'Idempotent-Replayed', $response->get_headers() );
		$this->assertCount( 1, $this->rows() );
		$this->assertSame( 1, $this->key_count( $form->id() ) );
	}

	/**
	 * A key with a space is rejected and nothing is stored.
	 */
	public function test_invalid_key_is_rejected_in_both_modes(): void {
		$form = $this->save_form();

		$html = $this->post( $form->public_id(), 'email=a@b.co', 'bad key' );
		$json = $this->post( $form->public_id(), 'email=a@b.co', 'bad key', true );

		$this->assertSame( 400, $html->get_status() );
		$this->assertSame( 400, $json->get_status() );
		$data = $json->get_data();
		$this->assertIsArray( $data );
		$this->assertSame( 'invalid_idempotency_key', $data['error']['code'] );
		$this->assertSame( array(), $this->rows() );
		$this->assertSame( 0, $this->key_count( $form->id() ) );
	}

	/**
	 * A trailing newline is outside the key format. `$` would have allowed it.
	 */
	public function test_key_with_a_trailing_newline_is_rejected(): void {
		$form = $this->save_form();

		$response = $this->post(
			$form->public_id(),
			'email=a@b.co&_fs_idempotency_key=' . rawurlencode( "abc\n" ),
			null,
			true
		);

		$this->assertSame( 400, $response->get_status() );
		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertSame( 'invalid_idempotency_key', $data['error']['code'] );
		$this->assertSame( array(), $this->rows() );
		$this->assertSame( 0, $this->key_count( $form->id() ) );
	}

	/**
	 * The header is stored. The field is ignored when the header is present.
	 */
	public function test_header_wins_over_the_reserved_field(): void {
		$form = $this->save_form();

		$first = $this->post( $form->public_id(), 'email=a@b.co&_fs_idempotency_key=field-key', 'header-key', true );
		$field = $this->post( $form->public_id(), 'email=a@b.co&_fs_idempotency_key=field-key' );
		$again = $this->post( $form->public_id(), 'email=a@b.co&_fs_idempotency_key=other-key', 'header-key', true );

		$this->assertSame( 201, $first->get_status() );
		$this->assertSame( 303, $field->get_status() );
		$this->assertArrayNotHasKey( 'Idempotent-Replayed', $field->get_headers() );
		$this->assertSame( 201, $again->get_status() );
		$this->assertSame( 'true', $again->get_headers()['Idempotent-Replayed'] );
		$this->assertSame( $first->get_data(), $again->get_data() );
		$this->assertCount( 2, $this->rows() );
	}

	/**
	 * A validation error does not consume the key, so a corrected repeat is stored.
	 */
	public function test_validation_error_does_not_consume_the_key(): void {
		$form = $this->save_form();

		$invalid = $this->post( $form->public_id(), 'note=Hi', 'retry-key', true );
		$valid   = $this->post( $form->public_id(), 'email=a@b.co', 'retry-key', true );

		$this->assertSame( 422, $invalid->get_status() );
		$this->assertSame( 201, $valid->get_status() );
		$this->assertArrayNotHasKey( 'Idempotent-Replayed', $valid->get_headers() );
		$this->assertCount( 1, $this->rows() );
		$this->assertSame( 1, $this->key_count( $form->id() ) );
	}

	/**
	 * The table stores hashes, not the key the client sent.
	 */
	public function test_only_the_key_hash_is_stored(): void {
		global $wpdb;

		$form = $this->save_form();
		$raw  = 'visible-key';
		$this->post( $form->public_id(), 'email=a@b.co', $raw, true );

		$table = Schema::idempotency_keys_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT key_hash, body_hash FROM {$table} WHERE form_id = %d", $form->id() ), ARRAY_A );
		$this->assertIsArray( $row );
		$this->assertSame( hash( 'sha256', $raw ), $row['key_hash'] );
		$body = IdempotencyKey::body_hash( array( 'email' => 'a@b.co' ) );
		$this->assertSame( $body, $row['body_hash'] );
		$this->assertStringNotContainsString( $raw, (string) wp_json_encode( $row ) );
	}

	/**
	 * Cleanup removes expired keys and leaves a live key in place.
	 */
	public function test_prune_removes_only_expired_keys(): void {
		global $wpdb;

		$form  = $this->save_form();
		$table = Schema::idempotency_keys_table();
		$wpdb->insert(
			$table,
			array(
				'form_id'       => $form->id(),
				'key_hash'      => str_repeat( 'a', 64 ),
				'body_hash'     => str_repeat( 'b', 64 ),
				'submission_id' => 1,
				'expires_at'    => time() - 5,
			)
		);
		$wpdb->insert(
			$table,
			array(
				'form_id'       => $form->id(),
				'key_hash'      => str_repeat( 'c', 64 ),
				'body_hash'     => str_repeat( 'd', 64 ),
				'submission_id' => 1,
				'expires_at'    => time() + 60,
			)
		);

		( new IdempotencyRepository() )->prune_expired( time() );

		$remaining = $wpdb->get_col( $wpdb->prepare( "SELECT key_hash FROM {$table} WHERE form_id = %d", $form->id() ) );
		$this->assertSame( array( str_repeat( 'c', 64 ) ), $remaining );
	}

	/**
	 * A key committed by another connection is replayed instead of stored again.
	 */
	public function test_key_committed_on_another_connection_is_replayed(): void {
		$form      = $this->save_form();
		$key       = 'race-key';
		$receipt   = 'fsr_abcdefghijklmnop';
		$body_hash = IdempotencyKey::body_hash( array( 'email' => 'a@b.co' ) );
		$this->assertIsString( $body_hash );

		$db                    = $this->other_connection();
		$this->cleanup_db      = $db;
		$this->cleanup_form_id = $form->id();
		$submissions           = Schema::submissions_table();
		$keys                  = Schema::idempotency_keys_table();
		$created_at            = current_time( 'mysql', true );
		$payload               = '{"email":"a@b.co"}';

		$saved = $db->query(
			sprintf(
				"INSERT INTO `%s` (form_id, schema_version, receipt_id, payload, status, created_at) VALUES (%d, %d, '%s', '%s', 'new', '%s')",
				$submissions,
				$form->id(),
				$form->schema_version(),
				$db->real_escape_string( $receipt ),
				$db->real_escape_string( $payload ),
				$db->real_escape_string( $created_at )
			)
		);
		$this->assertNotFalse( $saved, $db->error );
		$submission_id = (int) $db->insert_id;

		$reserved = $db->query(
			sprintf(
				"INSERT INTO `%s` (form_id, key_hash, body_hash, submission_id, expires_at) VALUES (%d, '%s', '%s', %d, %d)",
				$keys,
				$form->id(),
				$db->real_escape_string( hash( 'sha256', $key ) ),
				$db->real_escape_string( $body_hash ),
				$submission_id,
				time() + IdempotencyKey::WINDOW
			)
		);
		$this->assertNotFalse( $reserved );

		$response = $this->post( $form->public_id(), 'email=a@b.co', $key, true );

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 'true', $response->get_headers()['Idempotent-Replayed'] );
		$this->assertSame( array( 'receipt_id' => $receipt ), $response->get_data() );

		$count = $db->query( "SELECT COUNT(*) AS total FROM `{$submissions}` WHERE form_id = " . (int) $form->id() );
		$this->assertInstanceOf( \mysqli_result::class, $count );
		$total = $count->fetch_assoc();
		$this->assertIsArray( $total );
		$this->assertSame( '1', (string) $total['total'] );
	}

	/**
	 * An expired row is overwritten, so its id stays and a new submission is stored.
	 */
	public function test_expired_key_row_is_reused_in_place(): void {
		global $wpdb;

		$form  = $this->save_form();
		$key   = 'reuse-key';
		$table = Schema::idempotency_keys_table();
		$wpdb->insert(
			$table,
			array(
				'form_id'       => $form->id(),
				'key_hash'      => hash( 'sha256', $key ),
				'body_hash'     => hash( 'sha256', '{"email":"old@b.co"}' ),
				'submission_id' => 1,
				'expires_at'    => time() - 5,
			),
			array( '%d', '%s', '%s', '%d', '%d' )
		);
		$row_id = (int) $wpdb->insert_id;

		$response = $this->post( $form->public_id(), 'email=a@b.co', $key, true );

		$this->assertSame( 201, $response->get_status() );
		$this->assertArrayNotHasKey( 'Idempotent-Replayed', $response->get_headers() );
		$this->assertCount( 1, $this->rows() );
		$this->assertSame( 1, $this->key_count( $form->id() ) );
		$kept = $wpdb->get_row( $wpdb->prepare( "SELECT id, submission_id, expires_at FROM {$table} WHERE form_id = %d", $form->id() ), ARRAY_A );
		$this->assertIsArray( $kept );
		$this->assertSame( $row_id, (int) $kept['id'] );
		$this->assertGreaterThan( 0, (int) $kept['submission_id'] );
		$this->assertGreaterThan( time(), (int) $kept['expires_at'] );
	}

	/**
	 * A key inserted by another connection, and not yet committed, is replayed.
	 */
	public function test_waits_for_uncommitted_key_and_replays(): void {
		$form      = $this->save_form();
		$key       = 'held-key';
		$receipt   = 'fsr_heldreplay000001';
		$body_hash = IdempotencyKey::body_hash( array( 'email' => 'a@b.co' ) );
		$this->assertIsString( $body_hash );
		$this->prepare_cleanup( $form->id() );

		$this->start_holder( 'hold', $form, $key, $body_hash, $receipt );
		$response = $this->post( $form->public_id(), 'email=a@b.co', $key, true );
		$detail   = $this->holder_stderr();
		$this->assertSame( 0, $this->finish_holder(), $detail );

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 'true', $response->get_headers()['Idempotent-Replayed'] );
		$this->assertSame( array( 'receipt_id' => $receipt ), $response->get_data() );
		$this->assertSame( '1', $this->committed_count( Schema::submissions_table() ) );
		$this->assertSame( '1', $this->committed_count( Schema::idempotency_keys_table() ) );
	}

	/**
	 * The old delete-then-insert sequence on another connection must not fail this request.
	 *
	 * On MySQL 8.0 that sequence deadlocks a claim that also deletes before inserting.
	 */
	public function test_parallel_claim_does_not_deadlock(): void {
		$form      = $this->save_form();
		$key       = 'gap-key';
		$body_hash = IdempotencyKey::body_hash( array( 'email' => 'a@b.co' ) );
		$this->assertIsString( $body_hash );
		$this->prepare_cleanup( $form->id() );

		$this->start_holder( 'gap', $form, $key, $body_hash, 'fsr_gaplockreplay001' );
		$response = $this->post( $form->public_id(), 'email=a@b.co', $key, true );
		$detail   = $this->holder_stderr();
		$this->assertSame( 0, $this->finish_holder(), $detail );

		$this->assertSame( 201, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
	}

	/**
	 * Remember a form so rows committed outside the suite transaction can be removed.
	 *
	 * @param int $form_id Form id.
	 */
	private function prepare_cleanup( int $form_id ): void {
		$this->cleanup_db      = $this->other_connection();
		$this->cleanup_form_id = $form_id;
	}

	/**
	 * Whether proc_open can start the holder process.
	 */
	private function proc_open_available(): bool {
		if ( ! function_exists( 'proc_open' ) || '' === PHP_BINARY ) {
			return false;
		}

		$disabled = ini_get( 'disable_functions' );
		if ( ! is_string( $disabled ) || '' === $disabled ) {
			return true;
		}

		return ! in_array( 'proc_open', array_map( 'trim', explode( ',', $disabled ) ), true );
	}

	/**
	 * Start the holder and wait until its transaction is open.
	 *
	 * @param string $mode      `hold` or `gap`.
	 * @param Form   $form      Form the request will use.
	 * @param string $key       Raw idempotency key.
	 * @param string $body_hash SHA-256 of the canonical body.
	 * @param string $receipt   Receipt id the holder stores.
	 */
	private function start_holder( string $mode, Form $form, string $key, string $body_hash, string $receipt ): void {
		if ( ! $this->proc_open_available() ) {
			$this->markTestSkipped( 'proc_open is not available.' );
		}

		$script = dirname( __DIR__ ) . '/fixtures/hold-idempotency-key.php';
		$this->assertFileExists( $script );

		$host   = DB_HOST;
		$port   = '';
		$socket = '';
		if ( is_string( $host ) && str_contains( $host, ':' ) ) {
			$parts = explode( ':', $host, 2 );
			$host  = $parts[0];
			if ( isset( $parts[1] ) && is_numeric( $parts[1] ) ) {
				$port = $parts[1];
			} elseif ( isset( $parts[1] ) ) {
				$socket = $parts[1];
			}
		}

		$env   = array(
			'FORMSTEAD_DB_HOST'           => is_string( $host ) && '' !== $host ? $host : 'localhost',
			'FORMSTEAD_DB_PORT'           => $port,
			'FORMSTEAD_DB_SOCKET'         => $socket,
			'FORMSTEAD_DB_USER'           => (string) DB_USER,
			'FORMSTEAD_DB_PASSWORD'       => (string) DB_PASSWORD,
			'FORMSTEAD_DB_NAME'           => (string) DB_NAME,
			'FORMSTEAD_SUBMISSIONS_TABLE' => Schema::submissions_table(),
			'FORMSTEAD_KEYS_TABLE'        => Schema::idempotency_keys_table(),
			'FORMSTEAD_FORM_ID'           => (string) $form->id(),
			'FORMSTEAD_SCHEMA_VERSION'    => (string) $form->schema_version(),
			'FORMSTEAD_KEY_HASH'          => hash( 'sha256', $key ),
			'FORMSTEAD_BODY_HASH'         => $body_hash,
			'FORMSTEAD_RECEIPT'           => $receipt,
			'FORMSTEAD_PAYLOAD'           => '{"email":"a@b.co"}',
			'FORMSTEAD_CREATED_AT'        => current_time( 'mysql', true ),
			'FORMSTEAD_MODE'              => $mode,
			'FORMSTEAD_HOLD_SECONDS'      => '1',
		);
		$pipes = array();
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- the race needs a second PHP process.
		$process = proc_open(
			array( PHP_BINARY, $script ),
			array(
				0 => array( 'pipe', 'r' ),
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes,
			null,
			$env
		);
		$this->assertTrue( is_resource( $process ), 'The holder process did not start.' );
		$this->holder_process = $process;
		foreach ( $pipes as $index => $pipe ) {
			if ( is_resource( $pipe ) ) {
				$this->holder_pipes[ (int) $index ] = $pipe;
			}
		}

		$stdout = $this->holder_pipes[1] ?? null;
		$this->assertTrue( is_resource( $stdout ) );
		stream_set_blocking( $stdout, true );
		stream_set_timeout( $stdout, 10 );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fgets -- pipe from the holder process.
		$line = fgets( $stdout );
		$this->assertSame( "ready\n", $line, $this->holder_stderr() );
	}

	/**
	 * Stderr from the holder, without waiting for the process to exit.
	 */
	private function holder_stderr(): string {
		$stderr = $this->holder_pipes[2] ?? null;
		if ( ! is_resource( $stderr ) ) {
			return '';
		}

		stream_set_blocking( $stderr, false );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_stream_get_contents -- pipe from the holder process.
		$text = stream_get_contents( $stderr );
		return is_string( $text ) ? $text : '';
	}

	/**
	 * Wait for the holder to exit, or stop it.
	 */
	private function finish_holder(): int {
		if ( ! is_resource( $this->holder_process ) ) {
			return 0;
		}

		$deadline = time() + 8;
		$status   = proc_get_status( $this->holder_process );
		while ( is_array( $status ) && $status['running'] && time() < $deadline ) {
			usleep( 100000 );
			$status = proc_get_status( $this->holder_process );
		}
		if ( is_array( $status ) && $status['running'] ) {
			proc_terminate( $this->holder_process );
		}

		foreach ( $this->holder_pipes as $pipe ) {
			if ( is_resource( $pipe ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- pipe from the holder process.
				fclose( $pipe );
			}
		}
		$this->holder_pipes = array();

		$code                 = proc_close( $this->holder_process );
		$this->holder_process = null;

		return $code;
	}

	/**
	 * Count committed rows for the cleanup form on the side connection.
	 *
	 * The suite transaction cannot see those rows with a plain SELECT.
	 *
	 * @param string $table Full table name.
	 */
	private function committed_count( string $table ): string {
		$this->assertInstanceOf( mysqli::class, $this->cleanup_db );
		$this->assertSame( 1, preg_match( '/\A[A-Za-z0-9_]+\z/', $table ) );

		$form_id = (int) $this->cleanup_form_id;
		$result  = $this->cleanup_db->query( "SELECT COUNT(*) AS total FROM `{$table}` WHERE form_id = {$form_id}" );
		$this->assertInstanceOf( \mysqli_result::class, $result );
		$total = $result->fetch_assoc();
		$this->assertIsArray( $total );

		return (string) $total['total'];
	}

	/**
	 * Open a second database connection that can commit outside the suite transaction.
	 */
	private function other_connection(): mysqli {
		// A second connection must commit outside the suite transaction.
		// phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_init
		$mysqli = mysqli_init();
		$this->assertInstanceOf( mysqli::class, $mysqli );

		$host   = DB_HOST;
		$port   = 0;
		$socket = null;
		if ( is_string( $host ) && str_contains( $host, ':' ) ) {
			$parts = explode( ':', $host, 2 );
			$host  = $parts[0];
			if ( isset( $parts[1] ) && is_numeric( $parts[1] ) ) {
				$port = (int) $parts[1];
			} elseif ( isset( $parts[1] ) ) {
				$socket = $parts[1];
			}
		}

		$connected = $mysqli->real_connect(
			is_string( $host ) ? $host : 'localhost',
			DB_USER,
			DB_PASSWORD,
			DB_NAME,
			$port > 0 ? $port : null,
			$socket
		);
		$this->assertTrue( $connected, (string) $mysqli->connect_error );
		$mysqli->set_charset( 'utf8mb4' );
		$mysqli->query( 'SET SESSION innodb_lock_wait_timeout = 3' );

		return $mysqli;
	}

	/**
	 * Post a URL-encoded submission.
	 *
	 * @param string      $public_id Public form id.
	 * @param string      $body      URL-encoded body.
	 * @param string|null $key       Idempotency key, or null to omit the header.
	 * @param bool        $json      Whether to ask for JSON.
	 */
	private function post( string $public_id, string $body, ?string $key = null, bool $json = false ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/formstead/v1/forms/' . $public_id . '/submissions' );
		$request->set_header( 'Content-Type', 'application/x-www-form-urlencoded' );
		if ( is_string( $key ) ) {
			$request->set_header( 'Idempotency-Key', $key );
		}
		if ( $json ) {
			$request->set_header( 'Accept', 'application/json' );
		}
		$request->set_body( $body );
		parse_str( $body, $params );
		$request->set_body_params( $params );

		$response = rest_do_request( $request );
		$this->assertInstanceOf( WP_REST_Response::class, $response );
		return $response;
	}

	/**
	 * Post a JSON submission.
	 *
	 * @param string $public_id Public form id.
	 * @param string $body      JSON body.
	 * @param string $key       Idempotency key.
	 */
	private function post_json( string $public_id, string $body, string $key ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/formstead/v1/forms/' . $public_id . '/submissions' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'Accept', 'application/json' );
		$request->set_header( 'Idempotency-Key', $key );
		$request->set_body( $body );

		$response = rest_do_request( $request );
		$this->assertInstanceOf( WP_REST_Response::class, $response );
		return $response;
	}

	/**
	 * Persist a contact form.
	 */
	private function save_form(): Form {
		$saved = $this->forms->insert(
			new Form(
				0,
				'',
				'Contact',
				true,
				array(
					new Field( 'email', 'Email', FieldType::Email, true, 255 ),
					new Field( 'note', 'Note', FieldType::Textarea, false, 1000 ),
				),
				1,
				'https://example.com/thanks',
				'',
				''
			)
		);
		$this->assertInstanceOf( Form::class, $saved );
		return $saved;
	}

	/**
	 * Stored submissions for assertions.
	 *
	 * @return array<int, array<string, string>>
	 */
	private function rows(): array {
		global $wpdb;

		$table = Schema::submissions_table();
		$rows  = $wpdb->get_results( "SELECT receipt_id, payload FROM {$table} ORDER BY id ASC", ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$clean = array();
		foreach ( $rows as $row ) {
			if ( is_array( $row ) ) {
				$clean[] = array(
					'receipt_id' => (string) ( $row['receipt_id'] ?? '' ),
					'payload'    => (string) ( $row['payload'] ?? '' ),
				);
			}
		}

		return $clean;
	}

	/**
	 * How many keys are stored for a form.
	 *
	 * @param int $form_id Form id.
	 */
	private function key_count( int $form_id ): int {
		global $wpdb;

		$table = Schema::idempotency_keys_table();
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE form_id = %d", $form_id ) );
	}
}
