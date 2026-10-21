<?php
/**
 * Tests for the settings screen and the retention warning.
 *
 * @package Formstead
 */

namespace Formstead\Tests\Admin;

// phpcs:disable WordPress.Security.NonceVerification -- the test supplies the nonce that the screen checks.

use Formstead\Admin\Menu;
use Formstead\Admin\SettingsPage;
use Formstead\Lifecycle\Uninstaller;
use Formstead\Retention\RetentionRunner;
use Formstead\Security\Capabilities;
use WP_UnitTestCase;

/**
 * Covers the capability, the report, and when the warning appears.
 */
final class SettingsPageTest extends WP_UnitTestCase {

	/**
	 * Forget the report and leave the admin screen.
	 */
	public function tear_down(): void {
		$_POST                     = array();
		$_GET                      = array();
		$_REQUEST                  = array();
		$_SERVER['REQUEST_METHOD'] = 'GET';
		delete_option( RetentionRunner::REPORT_OPTION );
		delete_option( Uninstaller::OPTION );
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
	 * An administrator can opt in to deleting data, and can turn it off again.
	 */
	public function test_administrator_can_opt_in_to_deleting_data(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->post_settings( true );
		$this->save_expecting_redirect();
		$this->assertTrue( Uninstaller::enabled() );

		$this->post_settings( false );
		$this->save_expecting_redirect();
		$this->assertFalse( Uninstaller::enabled() );
	}

	/**
	 * Managing forms is not enough to change the uninstall choice.
	 */
	public function test_uninstall_choice_requires_activate_plugins(): void {
		$user = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user );
		$account = get_userdata( $user );
		$this->assertNotFalse( $account );
		$account->add_cap( Capabilities::MANAGE_FORMS );
		update_option( Uninstaller::OPTION, '1', false );

		$this->post_settings( false );
		try {
			SettingsPage::handle_load();
			$this->fail( 'Expected the uninstall choice to be refused.' );
		} catch ( \WPDieException $exception ) {
			$this->assertNotSame( '', $exception->getMessage() );
		}
		$this->assertTrue( Uninstaller::enabled() );
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
	 * Post the settings form.
	 *
	 * @param bool $delete Whether to opt in to deleting data.
	 */
	private function post_settings( bool $delete ): void {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array();
		if ( $delete ) {
			$_POST['delete_data_on_uninstall'] = '1';
		}
		$nonce             = wp_create_nonce( 'formstead_save_settings' );
		$_POST['_wpnonce'] = $nonce;
		$_REQUEST          = $_POST;
	}

	/**
	 * Save settings and stop the redirect.
	 */
	private function save_expecting_redirect(): void {
		$filter = static function ( $location ) {
			throw new \RuntimeException( esc_html( (string) $location ) );
		};
		add_filter( 'wp_redirect', $filter );

		try {
			SettingsPage::handle_load();
			$this->fail( 'Expected a redirect after saving.' );
		} catch ( \RuntimeException $exception ) {
			$this->assertStringContainsString( 'updated=1', $exception->getMessage() );
		} finally {
			remove_filter( 'wp_redirect', $filter );
		}
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
