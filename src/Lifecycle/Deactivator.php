<?php
/**
 * Plugin deactivation.
 *
 * @package Formstead
 */

namespace Formstead\Lifecycle;

use Formstead\Delivery\Scheduler;

/**
 * Runs on deactivation. Data and capabilities are kept.
 */
final class Deactivator {

	/**
	 * Run on plugin deactivation.
	 *
	 * Scheduled sends are removed. Stored submissions and deliveries stay.
	 */
	public static function deactivate(): void {
		Scheduler::clear();
	}
}
