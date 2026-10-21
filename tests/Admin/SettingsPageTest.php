<?php
/**
 * Tests for the settings screen and the retention warning.
 *
 * @package Formstead
 */

namespace Formstead\Tests\Admin;

use Formstead\Admin\Menu;
use Formstead\Admin\SettingsPage;
use Formstead\Retention\RetentionRunner;
use WP_UnitTestCase;

/**
 * Covers the capability, the report, and when the warning appears.
 */
final class SettingsPageTest extends WP_UnitTestCase {

	/**
	 * Forget the report and leave the admin screen.
	 */
	public function tear_down(): void {
		delete_option( RetentionRunner::REPORT_OPTION );
		if ( function_exists( 'set_current_screen' ) ) {
			set_current_screen( 'front' );
		}
		parent::tear_down();
	}

	/**
	 * A subscriber cannot open settings.
	 */
	public function test_settings_require_manage_forms(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->expectException( \WPDieException::class );
		SettingsPage::render();
	}

	/**
	 * The screen shows periods and counts, and drops anything that is not a count.
	 */
	public function test_report_shows_counts_without_submission_text(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		update_option(
			RetentionRunner::REPORT_OPTION,
			array(
				'finished_at' => time(),
				'submissions' => 4,
				'spam'        => 1,
				'deliveries'  => 2,
				'keys'        => 3,
				'rate_limits' => 5,
				'backlog'     => false,
				'errors'      => array( 'submission_delete_failed', 'secret-body' ),
				'note'        => 'secret-body',
			),
			false
		);

		$html = $this->render();

		$this->assertStringContainsString( 'FORMSTEAD_SPAM_RETENTION_DAYS', $html );
		$this->assertStringContainsString( 'FORMSTEAD_DELIVERY_RETENTION_DAYS', $html );
		$this->assertStringContainsString( '7', $html );
		$this->assertStringContainsString( '30', $html );
		$this->assertStringContainsString( '4', $html );
		$this->assertStringContainsString( 'Submissions could not be deleted.', $html );
		$this->assertStringNotContainsString( 'secret-body', $html );
		$this->assertStringNotContainsString( 'system cron', $html );
	}

	/**
	 * No report, a stale run, and a day-old backlog each warn. A recent run does not.
	 */
	public function test_warning_conditions(): void {
		$now = time();
		$this->assertTrue( SettingsPage::is_overdue( $now ) );

		$this->store( $now - 47 * HOUR_IN_SECONDS, false, 0 );
		$this->assertFalse( SettingsPage::is_overdue( $now ) );

		$this->store( $now - 49 * HOUR_IN_SECONDS, false, 0 );
		$this->assertTrue( SettingsPage::is_overdue( $now ) );

		$this->store( $now - HOUR_IN_SECONDS, true, $now - 23 * HOUR_IN_SECONDS );
		$this->assertFalse( SettingsPage::is_overdue( $now ) );

		$this->store( $now - HOUR_IN_SECONDS, true, $now - 25 * HOUR_IN_SECONDS );
		$this->assertTrue( SettingsPage::is_overdue( $now ) );
	}

	/**
	 * The warning is shown on a Formstead screen and not on the dashboard.
	 */
	public function test_notice_is_limited_to_formstead_screens(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		delete_option( RetentionRunner::REPORT_OPTION );

		set_current_screen( 'dashboard' );
		$this->assertSame( '', $this->notice() );

		set_current_screen( 'toplevel_page_' . Menu::MENU_SLUG );
		$html = $this->notice();
		$this->assertStringContainsString( 'wp formstead retention run', $html );
		$this->assertStringNotContainsString( 'secret-body', $html );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( '', $this->notice() );
	}

	/**
	 * Render the settings screen.
	 */
	private function render(): string {
		ob_start();
		SettingsPage::render();
		return (string) ob_get_clean();
	}

	/**
	 * Render the admin notice.
	 */
	private function notice(): string {
		ob_start();
		SettingsPage::notice();
		return (string) ob_get_clean();
	}

	/**
	 * Store a minimal retention report.
	 *
	 * @param int  $finished When the run finished.
	 * @param bool $backlog  Whether rows remained.
	 * @param int  $since    When the backlog started.
	 */
	private function store( int $finished, bool $backlog, int $since ): void {
		update_option(
			RetentionRunner::REPORT_OPTION,
			array(
				'finished_at'   => $finished,
				'backlog'       => $backlog,
				'backlog_since' => $since,
			),
			false
		);
	}
}
