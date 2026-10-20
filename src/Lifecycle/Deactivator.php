<?php
/**
 * Plugin deactivation.
 *
 * @package Formstead
 */

namespace Formstead\Lifecycle;

/**
 * Runs on deactivation. Scheduled events are unscheduled in a later stage.
 */
final class Deactivator {

	/**
	 * Run on plugin deactivation.
	 *
	 * Intentionally empty: data and capabilities are kept, and there are no cron events yet.
	 */
	public static function deactivate(): void {
	}
}
