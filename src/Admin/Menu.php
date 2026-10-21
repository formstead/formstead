<?php
/**
 * Admin menu.
 *
 * @package Formstead
 */

namespace Formstead\Admin;

use Formstead\Security\Capabilities;
use Formstead\Submissions\UnreadCounter;

/**
 * Registers the Formstead menu.
 *
 * The top-level item lists submissions. Forms are a submenu. WordPress sends
 * a person who has only one of the capabilities to the screen they can open.
 */
final class Menu {

	public const MENU_SLUG = 'formstead';

	public const FORMS_SLUG = 'formstead-forms';

	public const SETTINGS_SLUG = 'formstead-settings';

	/**
	 * Hook the menu on `admin_menu`.
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'add_pages' ) );
		add_action( 'admin_notices', array( SettingsPage::class, 'notice' ) );
	}

	/**
	 * Add the inbox, forms, and settings screens.
	 */
	public static function add_pages(): void {
		$unread = UnreadCounter::count();
		$title  = __( 'Formstead', 'formstead' );
		if ( $unread > 0 ) {
			$title .= sprintf(
				' <span class="awaiting-mod count-%1$d"><span class="pending-count">%2$s</span></span>',
				$unread,
				number_format_i18n( $unread )
			);
		}

		$inbox_hook = add_menu_page(
			__( 'Formstead', 'formstead' ),
			$title,
			Capabilities::READ_SUBMISSIONS,
			self::MENU_SLUG,
			array( SubmissionsPage::class, 'render' ),
			'dashicons-feedback',
			26
		);

		if ( is_string( $inbox_hook ) ) {
			add_action( 'load-' . $inbox_hook, array( SubmissionsPage::class, 'handle_load' ) );
		}

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

		$settings_hook = add_submenu_page(
			self::MENU_SLUG,
			__( 'Settings', 'formstead' ),
			__( 'Settings', 'formstead' ),
			Capabilities::MANAGE_FORMS,
			self::SETTINGS_SLUG,
			array( SettingsPage::class, 'render' )
		);

		if ( is_string( $settings_hook ) ) {
			add_action( 'load-' . $settings_hook, array( SettingsPage::class, 'handle_load' ) );
		}

		add_action( 'admin_enqueue_scripts', array( FormsPage::class, 'enqueue' ) );
	}
}
