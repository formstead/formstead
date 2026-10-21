<?php
/**
 * Settings screen.
 *
 * @package Formstead
 */

namespace Formstead\Admin;

use Formstead\Audit\AuditEvent;
use Formstead\Audit\AuditLog;
use Formstead\Lifecycle\Uninstaller;
use Formstead\Privacy\IdentityBackfill;
use Formstead\Retention\RetentionPeriods;
use Formstead\Retention\RetentionRunner;
use Formstead\Retention\RetentionScheduler;
use Formstead\Security\Capabilities;
use WP_Screen;

/**
 * Shows retention periods, the last run, and a warning when it falls behind.
 */
final class SettingsPage {

	/**
	 * A full run older than this means retention is not keeping up.
	 */
	public const RUN_STALE_AFTER = 48 * HOUR_IN_SECONDS;

	/**
	 * Leftover rows older than this are a backlog.
	 */
	public const BACKLOG_STALE_AFTER = 24 * HOUR_IN_SECONDS;

	/**
	 * Save the uninstall choice before the screen renders.
	 */
	public static function handle_load(): void {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		if ( 'POST' !== $method ) {
			return;
		}

		check_admin_referer( 'formstead_save_settings' );

		if ( ! current_user_can( Capabilities::MANAGE_FORMS ) || ! current_user_can( 'activate_plugins' ) ) {
			wp_die(
				esc_html__( 'Sorry, you are not allowed to change this setting.', 'formstead' ),
				'',
				array( 'response' => 403 )
			);
		}

		if ( isset( $_POST['delete_data_on_uninstall'] ) ) {
			update_option( Uninstaller::OPTION, '1', false );
		} else {
			delete_option( Uninstaller::OPTION );
		}

		AuditLog::record(
			AuditEvent::SETTINGS_UPDATED,
			'settings',
			0,
			array( 'settings' => array( 'delete_data_on_uninstall' ) )
		);

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => Menu::SETTINGS_SLUG,
					'updated' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Render the settings screen.
	 */
	public static function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE_FORMS ) ) {
			wp_die(
				esc_html__( 'Sorry, you are not allowed to manage forms.', 'formstead' ),
				'',
				array( 'response' => 403 )
			);
		}

		$report           = self::report();
		$overdue          = self::is_overdue();
		$spam_days        = RetentionPeriods::spam_days();
		$delivery_days    = RetentionPeriods::delivery_days();
		$audit_days       = RetentionPeriods::audit_days();
		$warning          = self::warning();
		$next_run         = self::next_run();
		$delete_data      = Uninstaller::enabled();
		$identity_message = IdentityBackfill::message();
		$can_delete_data  = current_user_can( 'activate_plugins' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading the screen query, not a submission.
		$updated_flag = isset( $_GET['updated'] ) ? sanitize_text_field( wp_unslash( $_GET['updated'] ) ) : '';
		$updated      = '1' === $updated_flag;

		include dirname( FORMSTEAD_FILE ) . '/templates/admin/settings.php';
	}

	/**
	 * Warn on Formstead screens when retention is behind.
	 */
	public static function notice(): void {
		if ( ! current_user_can( Capabilities::MANAGE_FORMS ) || ! self::on_formstead_screen() || ! self::is_overdue() ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html( self::warning() )
		);
	}

	/**
	 * Whether the last full run is old, leftovers have waited a day, or cron never started the first run.
	 *
	 * No report is not a warning while the next event is still ahead, or less than 48 hours late.
	 *
	 * @param int|null $now Unix time. Tests can pin the clock.
	 */
	public static function is_overdue( ?int $now = null ): bool {
		$now    = $now ?? time();
		$stored = get_option( RetentionRunner::REPORT_OPTION );
		if ( ! is_array( $stored ) ) {
			return self::schedule_is_overdue( $now );
		}

		$finished = self::timestamp( $stored['finished_at'] ?? null );
		if ( $finished < 1 || $finished < $now - self::RUN_STALE_AFTER ) {
			return true;
		}

		if ( empty( $stored['backlog'] ) ) {
			return false;
		}

		$since = self::timestamp( $stored['backlog_since'] ?? null );
		return $since > 0 && $since < $now - self::BACKLOG_STALE_AFTER;
	}

	/**
	 * Text of the overdue warning. It names the command and no submission.
	 *
	 * Before the first report, the text says retention has not run yet.
	 */
	public static function warning(): string {
		if ( ! is_array( get_option( RetentionRunner::REPORT_OPTION ) ) ) {
			return sprintf(
				/* translators: %s: WP-CLI command. */
				__( 'Retention has not run yet. Run %s from the system cron.', 'formstead' ),
				'wp formstead retention run'
			);
		}

		return sprintf(
			/* translators: %s: WP-CLI command. */
			__( 'Retention has not finished in the last 48 hours, or expired rows have been waiting for more than 24 hours. Run %s from the system cron.', 'formstead' ),
			'wp formstead retention run'
		);
	}

	/**
	 * Unix time of the next retention event, or zero when none is scheduled.
	 */
	private static function next_run(): int {
		$next = wp_next_scheduled( RetentionScheduler::HOOK );
		if ( ! is_numeric( $next ) ) {
			return 0;
		}

		return max( 0, (int) $next );
	}

	/**
	 * Whether the hourly event is missing or more than 48 hours late.
	 *
	 * @param int $now Unix time.
	 */
	private static function schedule_is_overdue( int $now ): bool {
		$next = self::next_run();
		if ( $next < 1 ) {
			return true;
		}

		return $next < $now - self::RUN_STALE_AFTER;
	}

	/**
	 * Last run, with counts and known error codes only.
	 *
	 * @return array{finished_at: int, submissions: int, spam: int, deliveries: int, keys: int, rate_limits: int, audit: int, backlog: bool, errors: list<string>}|null
	 */
	public static function report(): ?array {
		$stored = get_option( RetentionRunner::REPORT_OPTION );
		if ( ! is_array( $stored ) ) {
			return null;
		}

		return array(
			'finished_at' => self::timestamp( $stored['finished_at'] ?? null ),
			'submissions' => self::count( $stored['submissions'] ?? null ),
			'spam'        => self::count( $stored['spam'] ?? null ),
			'deliveries'  => self::count( $stored['deliveries'] ?? null ),
			'keys'        => self::count( $stored['keys'] ?? null ),
			'rate_limits' => self::count( $stored['rate_limits'] ?? null ),
			'audit'       => self::count( $stored['audit'] ?? null ),
			'backlog'     => ! empty( $stored['backlog'] ),
			'errors'      => self::errors( $stored['errors'] ?? null ),
		);
	}

	/**
	 * Translated text for a known error code, or an empty string.
	 *
	 * @param string $code Stored error code.
	 */
	public static function error_text( string $code ): string {
		if ( 'submission_delete_failed' === $code ) {
			return __( 'Submissions could not be deleted.', 'formstead' );
		}

		return '';
	}

	/**
	 * Whether the current admin screen belongs to Formstead.
	 */
	private static function on_formstead_screen(): bool {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return false;
		}

		$screen = get_current_screen();
		if ( ! $screen instanceof WP_Screen ) {
			return false;
		}

		return 'toplevel_page_' . Menu::MENU_SLUG === $screen->id
			|| str_starts_with( $screen->id, Menu::MENU_SLUG . '_page_' );
	}

	/**
	 * Known error codes from a stored list.
	 *
	 * @param mixed $errors Stored value.
	 * @return list<string>
	 */
	private static function errors( mixed $errors ): array {
		if ( ! is_array( $errors ) ) {
			return array();
		}

		$codes = array();
		foreach ( $errors as $code ) {
			if ( ! is_string( $code ) || '' === self::error_text( $code ) || in_array( $code, $codes, true ) ) {
				continue;
			}
			$codes[] = $code;
		}

		return $codes;
	}

	/**
	 * A non-negative integer from a stored count.
	 *
	 * @param mixed $value Stored value.
	 */
	private static function count( mixed $value ): int {
		if ( ! is_numeric( $value ) ) {
			return 0;
		}

		return max( 0, (int) $value );
	}

	/**
	 * A unix time from a stored value, or zero.
	 *
	 * @param mixed $value Stored value.
	 */
	private static function timestamp( mixed $value ): int {
		if ( ! is_numeric( $value ) ) {
			return 0;
		}

		return max( 0, (int) $value );
	}
}
