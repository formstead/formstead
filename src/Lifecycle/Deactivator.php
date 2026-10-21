<?php
/**
 * Plugin deactivation.
 *
 * @package Formstead
 */

namespace Formstead\Lifecycle;

use Formstead\Delivery\Scheduler;
use Formstead\Privacy\IdentityBackfill;
use Formstead\Retention\RetentionScheduler;

/**
 * Runs on deactivation. Data and capabilities are kept.
 */
final class Deactivator {

	/**
	 * Run on plugin deactivation.
	 *
	 * Scheduled sends, retention, and identity indexing are removed.
	 * Stored submissions stay.
	 */
	public static function deactivate(): void {
		Scheduler::clear();
		RetentionScheduler::clear();
		IdentityBackfill::clear();
	}
}
