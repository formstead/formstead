import { chromium, expect, type FullConfig } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { readForms } from './helpers/wp';

/**
 * Sign in as the tests-site admin and keep the session for admin screens.
 */
export default async function globalSetup( config: FullConfig ): Promise<void> {
	void config;

	const forms = readForms();
	const base = forms.base_url.replace( /\/$/, '' );
	mkdirSync( 'tests/e2e/.auth', { recursive: true } );

	const browser = await chromium.launch();
	const page = await browser.newPage();
	await page.goto( `${ base }/wp-login.php` );
	// WordPress focuses the username on a timer; wait so it cannot interrupt fill().
	await expect( page.locator( '#user_login' ) ).toBeFocused();
	await page.locator( '#user_login' ).fill( 'admin' );
	await page.locator( '#user_pass' ).fill( 'password' );
	await page.locator( '#wp-submit' ).click();
	await page.waitForURL( /\/wp-admin\// );
	await page.context().storageState( { path: 'tests/e2e/.auth/admin.json' } );
	await browser.close();
}
