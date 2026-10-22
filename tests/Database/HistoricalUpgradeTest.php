<?php
/**
 * Upgrade every distributed historical schema, independently of today's DDL.
 *
 * @package Formstead
 */

namespace Formstead\Tests\Database;

use Formstead\Database\Migrator;
use Formstead\Database\Schema;
use Formstead\Forms\Field;
use Formstead\Forms\FieldCodec;
use Formstead\Forms\FieldType;
use Formstead\Forms\FormRepository;
use Formstead\Forms\FormVersionRepository;
use Formstead\Privacy\IdentityBackfill;
use Formstead\Security\Capabilities;
use Formstead\Submissions\SubmissionRepository;
use Formstead\Tests\TestCase\MigrationTestCase;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- isolated historical DDL fixtures.

/**
 * Covers historical data and repeated upgrades, including the current schema.
 *
 * @group schema
 */
final class HistoricalUpgradeTest extends MigrationTestCase {

	/**
	 * Every schema shipped before the release candidate.
	 *
	 * @return array<string, array{int}>
	 */
	public function schemas(): array {
		$result = array();
		foreach ( range( 1, 8 ) as $version ) {
			$result[ 'schema v' . $version ] = array( $version );
		}
		return $result;
	}

	/**
	 * Historical DDL with existing data upgrades without silently enabling delivery.
	 *
	 * @dataProvider schemas
	 * @param int $version Historical database version.
	 */
	public function test_upgrade_preserves_historical_data( int $version ): void {
		global $wpdb;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- checked-in local DDL, never a remote URL.
		$sql = file_get_contents( dirname( __DIR__ ) . '/fixtures/schemas/v' . $version . '.sql' );
		$this->assertIsString( $sql );
		$sql = str_replace( array( '{{prefix}}', '{{charset}}' ), array( $wpdb->prefix, $wpdb->get_charset_collate() ), $sql );
		foreach ( explode( ';', $sql ) as $statement ) {
			if ( '' !== trim( $statement ) ) {
				$this->assertNotFalse( $wpdb->query( $statement ) );
			}
		}
		$fields = FieldCodec::encode( array( new Field( 'email', 'Original email label', FieldType::Email, true, 255 ) ) );
		$now    = current_time( 'mysql', true );
		$form   = array(
			'public_id'      => 'fs_abcdefghijklmnop',
			'name'           => 'Historical form',
			'is_enabled'     => 1,
			'fields'         => $fields,
			'schema_version' => 1,
			'success_url'    => 'https://example.com/thanks',
			'created_at'     => $now,
			'updated_at'     => $now,
		);
		if ( $version >= 4 ) {
			$form['notify_recipients'] = '["operator@example.com"]';
		}
		$this->assertSame( 1, $wpdb->insert( Schema::forms_table(), $form ) );
		$form_id = (int) $wpdb->insert_id;
		$row     = array(
			'form_id'        => $form_id,
			'schema_version' => 1,
			'payload'        => '{"email":"historical@example.com"}',
			'status'         => 'new',
			'created_at'     => $now,
		);
		if ( $version >= 2 ) {
			$row['receipt_id'] = 'fsr_abcdefghijklmnop';
			$this->assertSame(
				1,
				$wpdb->insert(
					Schema::form_versions_table(),
					array(
						'form_id'    => $form_id,
						'version'    => 1,
						'fields'     => $fields,
						'created_at' => $now,
					)
				)
			);
		}
		$this->assertSame( 1, $wpdb->insert( Schema::submissions_table(), $row ) );
		$submission_id = (int) $wpdb->insert_id;
		$delivery_id   = 0;
		if ( $version >= 4 ) {
			$this->assertSame(
				1,
				$wpdb->insert(
					Schema::deliveries_table(),
					array(
						'submission_id'   => $submission_id,
						'channel'         => 'email',
						'status'          => 'pending',
						'next_attempt_at' => $now,
						'created_at'      => $now,
						'updated_at'      => $now,
					)
				)
			);
			$delivery_id = (int) $wpdb->insert_id;
		}
		update_option( Schema::VERSION_OPTION, $version );

		$this->assertNull( Migrator::maybe_migrate() );
		$this->assertSame( Schema::VERSION, (int) get_option( Schema::VERSION_OPTION ) );
		$this->assertNull( Migrator::run() );
		$stored = ( new SubmissionRepository() )->find( $submission_id );
		$this->assertNotNull( $stored );
		$this->assertSame( array( 'email' => 'historical@example.com' ), $stored->payload() );
		$this->assertNotSame( '', $stored->receipt_id() );
		if ( $version >= 2 ) {
			$this->assertSame( $row['receipt_id'], $stored->receipt_id() );
		}
		$snapshot = ( new FormVersionRepository() )->find( $form_id, 1 );
		$this->assertNotNull( $snapshot );
		$this->assertSame( 'Original email label', $snapshot->fields()[0]->label() );
		$updated = ( new FormRepository() )->find_by_id( $form_id );
		$this->assertNotNull( $updated );
		$this->assertSame( $form['public_id'], $updated->public_id() );
		$this->assertSame( $version >= 4 ? array( 'operator@example.com' ) : array(), $updated->notifications()->recipients() );
		$this->assertSame( '', $updated->webhook()->url() );
		if ( $version >= 4 ) {
			$this->assertSame( 'pending', $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM ' . Schema::deliveries_table() . ' WHERE id = %d', $delivery_id ) ) );
		}
		foreach ( Capabilities::all() as $capability ) {
			$this->assertTrue( get_role( 'administrator' )->has_cap( $capability ) );
		}
		IdentityBackfill::clear();
	}
}
