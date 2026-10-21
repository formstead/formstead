<?php
/**
 * Schedules retention on WP-Cron.
 *
 * @package Formstead
 */

namespace Formstead\Retention;

/**
 * Runs one bounded retention pass every hour.
 */
final class RetentionScheduler {

	/**
	 * Hook that deletes expired rows.
	 */
	public const HOOK = 'formstead_retention';

	/**
	 * Register the hook and keep the hourly event on the schedule.
	 */
	public static function register(): void {
		add_action( self::HOOK, array( self::class, 'run' ) );
		add_action( 'init', array( self::class, 'ensure' ) );
	}

	/**
	 * Schedule the hourly run when it is missing.
	 */
	public static function ensure(): void {
		if ( false === wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::HOOK );
		}
	}

	/**
	 * Delete one bounded batch of expired rows.
	 */
	public static function run(): void {
		( new RetentionRunner() )->run( RetentionRunner::CRON_BATCHES, time() + RetentionRunner::CRON_SECONDS );
	}

	/**
	 * Remove the hourly event. Called on deactivation.
	 */
	public static function clear(): void {
		wp_unschedule_hook( self::HOOK );
	}
}
