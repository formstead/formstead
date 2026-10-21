<?php
/**
 * Plugin bootstrap.
 *
 * @package Formstead
 */

namespace Formstead;

use Formstead\Admin\Menu;
use Formstead\Cli\DeliveriesCommand;
use Formstead\Database\Migrator;
use Formstead\Delivery\Scheduler;
use Formstead\Lifecycle\Requirements;
use Formstead\Rest\Cors;
use Formstead\Rest\HtmlResponder;
use Formstead\Rest\SubmissionsController;
use WP_Error;

/**
 * Registers plugin runtime hooks after WordPress has loaded plugins.
 */
final class Plugin {

	/**
	 * Migration failure for the current request, if the schema is not ready.
	 *
	 * @var WP_Error|null
	 */
	private static ?WP_Error $migration_error = null;

	/**
	 * Boot the plugin after WordPress has loaded plugins.
	 *
	 * WordPress 4.6+ loads translations for the `formstead` text domain
	 * automatically when they are present. A failed migration does not bump
	 * the schema version. The public route and the admin menu still register
	 * when the tables are already there, so a held lock does not turn
	 * submissions into `rest_no_route`.
	 */
	public static function boot(): void {
		global $wp_version;

		if ( ! Requirements::are_met( PHP_VERSION, (string) $wp_version ) ) {
			return;
		}

		$error = Migrator::maybe_migrate();

		if ( $error instanceof WP_Error ) {
			self::$migration_error = $error;
			add_action( 'admin_notices', array( self::class, 'migration_notice' ) );
			if ( ! Migrator::tables_exist() ) {
				return;
			}
		}

		Menu::register();
		SubmissionsController::register();
		Cors::register();
		HtmlResponder::register();
		Scheduler::register();
		DeliveriesCommand::register();
	}

	/**
	 * Tell users who can activate plugins that the schema update failed.
	 */
	public static function migration_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) || ! self::$migration_error instanceof WP_Error ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html( self::$migration_error->get_error_message() )
		);
	}
}
