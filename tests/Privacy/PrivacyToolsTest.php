<?php
/**
 * Tests for the personal-data exporter and eraser.
 *
 * @package Formstead
 */

namespace Formstead\Tests\Privacy;

use Formstead\Database\Migrator;
use Formstead\Database\Schema;
use Formstead\Delivery\DeliveryRepository;
use Formstead\Delivery\DeliveryStatus;
use Formstead\Forms\Field;
use Formstead\Forms\FieldType;
use Formstead\Forms\Form;
use Formstead\Forms\FormRepository;
use Formstead\Privacy\IdentityBackfill;
use Formstead\Privacy\PrivacyEraser;
use Formstead\Privacy\PrivacyExporter;
use Formstead\Submissions\Submission;
use Formstead\Submissions\SubmissionIntake;
use Formstead\Submissions\SubmissionRepository;
use WP_Error;
use WP_UnitTestCase;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the test reads the audit rows it caused.

/**
 * Export and erasure page through an address, and they stop when the index is unfinished.
 */
final class PrivacyToolsTest extends WP_UnitTestCase {

	private const EMAIL = 'person-privacy-250@example.com';

	private const OTHER = 'other-privacy-250@example.com';

	/**
	 * Drop a cursor left by an earlier test that committed.
	 */
	public function set_up(): void {
		parent::set_up();
		delete_option( Migrator::IDENTITY_BACKFILL_OPTION );
		IdentityBackfill::clear();
	}

	/**
	 * Forget a backfill cursor this class may set.
	 */
	public function tear_down(): void {
		delete_option( Migrator::IDENTITY_BACKFILL_OPTION );
		IdentityBackfill::clear();
		parent::tear_down();
	}

	/**
	 * Two hundred and fifty submissions export without repeats and erase in three pages.
	 */
	public function test_export_and_erase_page_past_one_hundred_rows(): void {
		global $wpdb;

		$first  = $this->form( 'First' );
		$second = $this->form( 'Second' );
		$kept   = $this->store( $first, self::OTHER );
		$owned  = array();
		for ( $index = 0; $index < 150; $index++ ) {
			$owned[] = $this->store( $first, self::EMAIL );
		}
		for ( $index = 0; $index < 100; $index++ ) {
			$owned[] = $this->store( $second, self::EMAIL );
		}

		$delivery_id = ( new DeliveryRepository() )->create( $owned[0]->id() );
		$this->assertIsInt( $delivery_id );

		$ids   = array();
		$page  = 1;
		$sizes = array();
		do {
			$result = PrivacyExporter::export( self::EMAIL, $page );
			$this->assertIsArray( $result );
			$sizes[] = count( $result['data'] );
			foreach ( $result['data'] as $item ) {
				$this->assertIsArray( $item );
				$ids[] = (string) $item['item_id'];
			}
			++$page;
		} while ( empty( $result['done'] ) && $page < 6 );

		$this->assertSame( array( 100, 100, 50 ), $sizes );
		$this->assertCount( 250, $ids );
		$this->assertSame( $ids, array_unique( $ids ) );
		$this->assertStringContainsString( self::EMAIL, (string) wp_json_encode( $result['data'] ) );

		$removed = 0;
		$calls   = 0;
		do {
			$erased = PrivacyEraser::erase( self::EMAIL, $calls + 1 );
			$this->assertIsArray( $erased );
			++$calls;
			if ( ! empty( $erased['items_removed'] ) ) {
				++$removed;
			}
			$this->assertFalse( $erased['items_retained'] );
		} while ( empty( $erased['done'] ) && $calls < 6 );

		$this->assertSame( 3, $calls );
		$this->assertSame( 3, $removed );
		$this->assertNull( ( new SubmissionRepository() )->find( $owned[0]->id() ) );
		$this->assertInstanceOf( Submission::class, ( new SubmissionRepository() )->find( $kept->id() ) );

		$deliveries = ( new DeliveryRepository() )->for_submission( $owned[0]->id() );
		$this->assertCount( 1, $deliveries );
		$this->assertSame( DeliveryStatus::CANCELLED, $deliveries[0]->status() );

		$table = Schema::audit_table();
		$rows  = $wpdb->get_results( "SELECT event, context FROM {$table}", ARRAY_A );
		$this->assertIsArray( $rows );
		$encoded = strtolower( (string) wp_json_encode( $rows ) );
		$this->assertStringNotContainsString( 'person-privacy-250', $encoded );
		$this->assertStringNotContainsString( 'kept-out-of-audit', $encoded );
		$this->assertStringContainsString( 'privacy_exported', $encoded );
		$this->assertStringContainsString( 'privacy_erased', $encoded );
	}

	/**
	 * An unfinished index refuses both tools instead of returning a partial result.
	 */
	public function test_a_pending_index_refuses_export_and_erasure(): void {
		update_option(
			Migrator::IDENTITY_BACKFILL_OPTION,
			array(
				'status'   => 'pending',
				'after_id' => 0,
				'until_id' => 10,
			),
			false
		);

		$exported = PrivacyExporter::export( self::EMAIL, 1 );
		$erased   = PrivacyEraser::erase( self::EMAIL, 1 );

		$this->assertInstanceOf( WP_Error::class, $exported );
		$this->assertInstanceOf( WP_Error::class, $erased );
		$this->assertStringContainsString( 'wp formstead identities rebuild', $exported->get_error_message() );
		$this->assertStringContainsString( 'wp formstead identities rebuild', $erased->get_error_message() );
	}

	/**
	 * A failed index read is an error, not an empty export or a finished erasure.
	 */
	public function test_a_failed_index_read_refuses_export_and_erasure(): void {
		global $wpdb;

		$form  = $this->form( 'Readable' );
		$saved = $this->store( $form, self::EMAIL );
		$table = Schema::identities_table();

		$previous = $wpdb->suppress_errors( true );
		$filter   = static function ( $query ) use ( $table ) {
			if ( is_string( $query ) && str_contains( $query, $table ) && str_contains( $query, 'value_hash' ) ) {
				return 'SELECT submission_id FROM ' . $table . '_missing';
			}

			return $query;
		};

		add_filter( 'query', $filter );
		try {
			$exported = PrivacyExporter::export( self::EMAIL, 1 );
			$erased   = PrivacyEraser::erase( self::EMAIL, 1 );
		} finally {
			remove_filter( 'query', $filter );
			$wpdb->suppress_errors( $previous );
		}

		$this->assertInstanceOf( WP_Error::class, $exported );
		$this->assertSame( 'formstead_identity_index_unreadable', $exported->get_error_code() );
		$this->assertStringNotContainsString( 'wp formstead identities rebuild', $exported->get_error_message() );
		$this->assertInstanceOf( WP_Error::class, $erased );
		$this->assertSame( 'formstead_identity_index_unreadable', $erased->get_error_code() );
		$this->assertNotNull( ( new SubmissionRepository() )->find( $saved->id() ) );
	}

	/**
	 * Persist a form whose email field identifies the person.
	 *
	 * @param string $name Form name.
	 */
	private function form( string $name ): Form {
		$saved = ( new FormRepository() )->insert(
			new Form(
				0,
				'',
				$name,
				true,
				array(
					new Field( 'email', 'Email', FieldType::Email, true, 255 ),
					new Field( 'note', 'Note', FieldType::Text, false, 255 ),
				),
				1,
				'https://example.com/thanks',
				'',
				'',
				null,
				0,
				null,
				30,
				array( 'email' )
			)
		);
		$this->assertInstanceOf( Form::class, $saved );
		return $saved;
	}

	/**
	 * Store one submission for an address.
	 *
	 * @param Form   $form  Receiving form.
	 * @param string $email Address stored in the identifying field.
	 */
	private function store( Form $form, string $email ): Submission {
		$stored = ( new SubmissionIntake() )->accept(
			$form,
			array(
				'email' => $email,
				'note'  => 'kept-out-of-audit',
			),
			null
		);
		$saved  = $stored->submission();
		$this->assertInstanceOf( Submission::class, $saved );
		return $saved;
	}
}
