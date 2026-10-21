<?php
/**
 * Tests for the audit log screen.
 *
 * @package Formstead
 */

namespace Formstead\Tests\Admin;

use Formstead\Admin\AuditPage;
use Formstead\Audit\AuditEvent;
use Formstead\Database\Schema;
use Formstead\Security\Capabilities;
use WP_UnitTestCase;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the test plants the rows the screen reads.
// phpcs:disable WordPress.Security.NonceVerification -- the test supplies the screen query.

/**
 * The log is limited to people who manage forms, and filters do not invent rows.
 */
final class AuditPageTest extends WP_UnitTestCase {

	/**
	 * Forget the screen request.
	 */
	public function tear_down(): void {
		$_GET                      = array();
		$_REQUEST                  = array();
		$_SERVER['REQUEST_METHOD'] = 'GET';
		parent::tear_down();
	}

	/**
	 * Reading submissions is not enough to open the log.
	 */
	public function test_reading_submissions_cannot_open_the_audit_log(): void {
		$user = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user );
		$account = get_userdata( $user );
		$this->assertNotFalse( $account );
		$account->add_cap( Capabilities::READ_SUBMISSIONS );

		$this->expectException( \WPDieException::class );
		$this->expectExceptionMessage( 'not allowed to view the audit log' );
		AuditPage::render();
	}

	/**
	 * A deleted account keeps its id, and the event filter hides the other row.
	 */
	public function test_a_missing_user_keeps_an_id_and_the_event_filter_applies(): void {
		$admin   = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$deleted = self::factory()->user->create();
		wp_set_current_user( $admin );
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $deleted );

		$this->insert_event( AuditEvent::FORM_CREATED, $deleted, 'form', 910004, gmdate( 'Y-m-d H:i:s' ) );
		$this->insert_event( AuditEvent::SETTINGS_UPDATED, $admin, 'settings', 0, gmdate( 'Y-m-d H:i:s' ) );
		$_GET['event'] = AuditEvent::FORM_CREATED;

		$html = $this->render();

		$this->assertStringContainsString( 'User #' . $deleted, $html );
		$this->assertStringContainsString( 'Form created', $html );
		$this->assertStringContainsString( 'Form #910004', $html );
		$this->assertStringContainsString( '1 item', $html );
	}

	/**
	 * A date filter keeps the day that was asked for.
	 */
	public function test_the_date_filter_keeps_one_day(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$today     = gmdate( 'Y-m-d' );
		$yesterday = gmdate( 'Y-m-d', time() - DAY_IN_SECONDS );
		$this->insert_event( AuditEvent::FORM_UPDATED, 0, 'form', 910011, $today . ' 12:00:00' );
		$this->insert_event( AuditEvent::FORM_UPDATED, 0, 'form', 910012, $yesterday . ' 12:00:00' );
		$_GET['date_from'] = $today;
		$_GET['date_to']   = $today;

		$html = $this->render();

		$this->assertStringContainsString( 'Form #910011', $html );
		$this->assertStringNotContainsString( 'Form #910012', $html );
		$this->assertStringContainsString( 'Automatic', $html );
	}

	/**
	 * Page two does not repeat the first page.
	 */
	public function test_the_second_page_starts_after_twenty_rows(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$now = time();
		for ( $index = 1; $index <= 21; $index++ ) {
			$this->insert_event( AuditEvent::SUBMISSIONS_DELETED, 0, 'submission', 800000 + $index, gmdate( 'Y-m-d H:i:s', $now - $index ) );
		}
		$_GET['event'] = AuditEvent::SUBMISSIONS_DELETED;
		$_GET['paged'] = '2';

		$html = $this->render();

		$this->assertStringContainsString( 'Submission #800021', $html );
		$this->assertStringNotContainsString( 'Submission #800001', $html );
		$this->assertStringNotContainsString( 'Submission #800020', $html );
	}

	/**
	 * Render the screen.
	 */
	private function render(): string {
		ob_start();
		AuditPage::render();
		return (string) ob_get_clean();
	}

	/**
	 * Store one audit row.
	 *
	 * @param string $event       Event code.
	 * @param int    $actor_id    User id, or zero.
	 * @param string $object_type Object code.
	 * @param int    $object_id   Object id.
	 * @param string $created_at  UTC `Y-m-d H:i:s`.
	 */
	private function insert_event( string $event, int $actor_id, string $object_type, int $object_id, string $created_at ): void {
		global $wpdb;

		$wpdb->insert(
			Schema::audit_table(),
			array(
				'event'       => $event,
				'actor_id'    => $actor_id,
				'object_type' => $object_type,
				'object_id'   => $object_id,
				'context'     => '{"fields":true}',
				'created_at'  => $created_at,
			),
			array( '%s', '%d', '%s', '%d', '%s', '%s' )
		);
	}
}
