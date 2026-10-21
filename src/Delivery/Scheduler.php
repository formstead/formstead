<?php
/**
 * Schedules delivery work on WP-Cron.
 *
 * @package Formstead
 */

namespace Formstead\Delivery;

/**
 * Queues one immediate send and a sweep for anything that was missed.
 */
final class Scheduler {

	/**
	 * Hook that sends one delivery.
	 */
	public const SEND = 'formstead_send_delivery';

	/**
	 * Hook that sends a batch of due deliveries.
	 */
	public const SWEEP = 'formstead_sweep_deliveries';

	/**
	 * Cron schedule name for the sweep.
	 */
	public const INTERVAL = 'formstead_five_minutes';

	/**
	 * How many deliveries one sweep claims.
	 */
	private const SWEEP_LIMIT = 20;

	/**
	 * Register the hooks and keep the sweep on the schedule.
	 */
	public static function register(): void {
		// Retries begin after one minute, so the sweep cannot wait for the hourly default.
		// phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval
		add_filter( 'cron_schedules', array( self::class, 'schedules' ) );
		add_action( self::SEND, array( self::class, 'send' ) );
		add_action( self::SWEEP, array( self::class, 'sweep' ) );
		add_action( 'init', array( self::class, 'ensure_sweep' ) );
	}

	/**
	 * Add the five-minute interval.
	 *
	 * @param array<string, array<string, int|string>> $schedules Existing schedules.
	 * @return array<string, array<string, int|string>>
	 */
	public static function schedules( array $schedules ): array {
		$schedules[ self::INTERVAL ] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every five minutes', 'formstead' ),
		);

		return $schedules;
	}

	/**
	 * Schedule the sweep when it is missing.
	 */
	public static function ensure_sweep(): void {
		if ( false === wp_next_scheduled( self::SWEEP ) ) {
			wp_schedule_event( time() + ( 5 * MINUTE_IN_SECONDS ), self::INTERVAL, self::SWEEP );
		}
	}

	/**
	 * Queue one delivery to run as soon as cron runs.
	 *
	 * Called after the submission transaction commits.
	 *
	 * @param int $delivery_id Delivery id.
	 */
	public static function schedule( int $delivery_id ): void {
		if ( $delivery_id < 1 ) {
			return;
		}

		wp_schedule_single_event( time(), self::SEND, array( $delivery_id ) );
	}

	/**
	 * Send one delivery.
	 *
	 * @param int $delivery_id Delivery id.
	 */
	public static function send( int $delivery_id ): void {
		self::worker()->send( $delivery_id );
	}

	/**
	 * Send a batch of due deliveries.
	 */
	public static function sweep(): void {
		self::worker()->run_due( self::SWEEP_LIMIT );
	}

	/**
	 * Remove both hooks. Called on deactivation.
	 */
	public static function clear(): void {
		// Send events carry a delivery id, so the whole hook has to be removed.
		wp_unschedule_hook( self::SEND );
		wp_unschedule_hook( self::SWEEP );
	}

	/**
	 * Worker that picks a channel for each delivery.
	 *
	 * `formstead_delivery_channel` receives the channel name and may return a
	 * replacement. Tests use that to record a send.
	 */
	public static function worker(): DeliveryWorker {
		return new DeliveryWorker();
	}
}
