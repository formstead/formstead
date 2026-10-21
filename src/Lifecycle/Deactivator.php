<?php
/**
 * Plugin deactivation.
 *
 * @package Formstead
 */

namespace Formstead\Lifecycle;

use Formstead\Delivery\Scheduler;
use Formstead\Retention\RetentionScheduler;

/**
 * Runs on deactivation. Data and capabilities are kept.
 */
final class Deactivator {

	/**
	 * Run on plugin deactivation.
	 *
	 * Scheduled sends and retention runs are removed. Stored submissions stay.
	 */
	public static function deactivate(): void {
		Scheduler::clear();
		RetentionScheduler::clear();
	}
}
