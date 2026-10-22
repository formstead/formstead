<?php
/**
 * Keep pending work and data through a complete activation cycle.
 *
 * @package Formstead
 */

namespace Formstead\Tests\Lifecycle;

use Formstead\Database\Migrator;
use Formstead\Delivery\DeliveryRepository;
use Formstead\Delivery\Scheduler;
use Formstead\Forms\Field;
use Formstead\Forms\FieldType;
use Formstead\Forms\Form;
use Formstead\Forms\FormRepository;
use Formstead\Lifecycle\Activator;
use Formstead\Lifecycle\Deactivator;
use Formstead\Privacy\IdentityBackfill;
use Formstead\Retention\RetentionScheduler;
use Formstead\Security\Capabilities;
use Formstead\Submissions\SubmissionRepository;
use Formstead\Tests\TestCase\MigrationTestCase;

/**
 * Activation performs real DDL, so this test owns a disposable schema.
 *
 * @group schema
 */
final class ReactivationTest extends MigrationTestCase {

	/**
	 * Schedules are restored once and pending rows survive unchanged.
	 */
	public function test_reactivation_preserves_data_and_restores_schedules_once(): void {
		Activator::activate();
		$form = ( new FormRepository() )->insert( new Form( 0, '', 'Cycle', true, array( new Field( 'email', 'Email', FieldType::Email, true, 255 ) ), 1, 'https://example.com/thanks', '', '' ) );
		$this->assertInstanceOf( Form::class, $form );
		$submission = ( new SubmissionRepository() )->insert( $form, array( 'email' => 'cycle@example.com' ) );
		$this->assertNotInstanceOf( \WP_Error::class, $submission );
		$delivery_id = ( new DeliveryRepository() )->create( $submission->id() );
		$this->assertIsInt( $delivery_id );
		update_option(
			Migrator::IDENTITY_BACKFILL_OPTION,
			array(
				'status'   => 'pending',
				'after_id' => 0,
				'until_id' => $submission->id(),
			),
			false
		);
		Scheduler::ensure_sweep();
		RetentionScheduler::ensure();
		IdentityBackfill::ensure();
		Deactivator::deactivate();
		foreach ( array( Scheduler::SWEEP, RetentionScheduler::HOOK, IdentityBackfill::HOOK ) as $hook ) {
			$this->assertFalse( wp_next_scheduled( $hook ) );
		}
		Activator::activate();
		for ( $i = 0; $i < 2; ++$i ) {
			Scheduler::ensure_sweep();
			RetentionScheduler::ensure();
			IdentityBackfill::ensure();
		}
		foreach ( array( Scheduler::SWEEP, RetentionScheduler::HOOK, IdentityBackfill::HOOK ) as $hook ) {
			$count = 0;
			foreach ( _get_cron_array() as $events ) {
				$count += isset( $events[ $hook ] ) ? count( $events[ $hook ] ) : 0;
			}
			$this->assertSame( 1, $count, $hook );
		}
		$this->assertSame( array( 'email' => 'cycle@example.com' ), ( new SubmissionRepository() )->find( $submission->id() )->payload() );
		$this->assertSame( 'pending', ( new DeliveryRepository() )->find( $delivery_id )->status() );
		$this->assertTrue( get_role( 'administrator' )->has_cap( Capabilities::READ_SUBMISSIONS ) );
		Deactivator::deactivate();
	}
}
