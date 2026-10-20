<?php
/**
 * Admin menu.
 *
 * @package Formstead
 */

namespace Formstead\Admin;

use Formstead\Security\Capabilities;

/**
 * Registers the Formstead menu.
 *
 * The top-level item lists submissions. Forms are a submenu. WordPress sends
 * a person who has only one of the capabilities to the screen they can open.
 */
final class Menu {

	public const MENU_SLUG = 'formstead';

	public const FORMS_SLUG = 'formstead-forms';

	/**
	 * Hook the menu on `admin_menu`.
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'add_pages' ) );
	}

	/**
	 * Add the inbox and forms screens.
	 */
	public static function add_pages(): void {
		add_menu_page(
			__( 'Formstead', 'formstead' ),
			__( 'Formstead', 'formstead' ),
			Capabilities::READ_SUBMISSIONS,
			self::MENU_SLUG,
			array( SubmissionsPage::class, 'render' ),
			'dashicons-feedback',
			26
		);

		$forms_hook = add_submenu_page(
			self::MENU_SLUG,
			__( 'Forms', 'formstead' ),
			__( 'Forms', 'formstead' ),
			Capabilities::MANAGE_FORMS,
			self::FORMS_SLUG,
			array( FormsPage::class, 'render' )
		);

		if ( is_string( $forms_hook ) ) {
			FormsPage::set_hook( $forms_hook );
			add_action( 'load-' . $forms_hook, array( FormsPage::class, 'handle_load' ) );
		}

		add_action( 'admin_enqueue_scripts', array( FormsPage::class, 'enqueue' ) );
	}
}
